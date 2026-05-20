<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection as DbalConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use DateTimeImmutable;
use DateTimeZone;
use Override;
use ReflectionObject;
use Throwable;

/**
 * Doctrine-backed transport adapter. The Doctrine transport in Symfony
 * Messenger is implemented as a `DoctrineTransport` class (not just the
 * inner `DoctrineReceiver`) that itself implements ListableReceiverInterface
 * — so we accept the broader interface here and let the factory's FQCN
 * check decide it's the Doctrine variant.
 *
 * Inherits everything listable from ListableReceiverAdapter and:
 *  - Adds full capability flags.
 *  - Reads the `body` column directly via DBAL and deserializes through
 *    {@see BodyDeserializer}, which tolerates both the standard PhpSerializer
 *    output AND addslashes-style escaped bodies. Symfony's PhpSerializer
 *    throws on the escaped variant, so going through `$receiver->find()` /
 *    `$receiver->all()` would silently lose rows whose bodies happen to
 *    have been escaped at write time.
 *  - Implements purge() via a direct DELETE so it doesn't depend on the
 *    receiver being able to deserialize every row.
 *  - Surfaces the storage `created_at` so descriptors reflect the actual
 *    insert timestamp instead of "now" on every render.
 */
final class DoctrineTransportAdapter extends ListableReceiverAdapter
{
    /**
     * Cached (DBAL connection, table name) tuple resolved via reflection
     * on the messenger Connection. `false` after a failed lookup so we
     * stop retrying and fall back to the parent's timestamp behavior.
     *
     * @var array{conn: DbalConnection, table: string}|null|false
     */
    private array|false|null $tableAccess = null;

    public function __construct(string $name, ListableReceiverInterface $receiver)
    {
        parent::__construct($name, $receiver, 'doctrine');
    }

    #[Override]
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            canCount: true,
            canList: true,
            canInspectIndividual: true,
            canDeleteIndividual: true,
            canBulkDelete: true,
            canPurge: true,
            canRequeue: true,
        );
    }

    #[Override]
    public function countListable(?string $query = null): int
    {
        $access = $this->resolveTableAccess();
        if ($access === null) {
            // Side-channel unavailable — fall back to parent's PHP filter
            // which uses the receiver API directly.
            return parent::countListable($query);
        }

        $params = [$this->queueNameFromAccess(), new DateTimeImmutable('now', new DateTimeZone('UTC'))];
        $types = ['string', 'datetime_immutable'];
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE queue_name = ? AND delivered_at IS NULL AND available_at <= ?',
            $access['table'],
        );
        if ($query !== null) {
            // No ESCAPE clause: SQLite requires a single-char escape (would
            // need `'\'`, unterminated in MariaDB) while MariaDB needs
            // `'\\'` (rejected by SQLite as 2 chars). Without ESCAPE, both
            // drivers fall back to their default `\` escape behavior for
            // `\%` and `\_`, which is what the bundle's PHP-side normalizer
            // produces. Trade-off: a literal `%` or `_` in user input
            // matches as a wildcard — acceptable for a free-text search box.
            $sql .= ' AND (body LIKE ? OR headers LIKE ?)';
            $pattern = '%' . $this->normalizeQueryForLike($query) . '%';
            $params[] = $pattern;
            $params[] = $pattern;
            $types[] = 'string';
            $types[] = 'string';
        }

        try {
            $raw = $access['conn']->fetchOne($sql, $params, $types);

            return \is_numeric($raw) ? (int) $raw : parent::countListable($query);
        } catch (Throwable) {
            return parent::countListable($query);
        }
    }

    #[Override]
    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        $access = $this->resolveTableAccess();
        if ($access === null) {
            // Reflection access unavailable — fall back to the receiver-based
            // parent path. Loses tolerance for escaped bodies but at least
            // works on standard PhpSerializer output.
            return parent::list($offset, $limit, $query);
        }

        try {
            $rows = $this->fetchRowsForList($access, $offset, $limit, $query);
        } catch (Throwable) {
            return parent::list($offset, $limit, $query);
        }

        $descriptors = [];
        foreach ($rows as $row) {
            $body = isset($row['body']) && \is_scalar($row['body']) ? (string) $row['body'] : '';
            if ($body === '') {
                continue;
            }
            $envelope = BodyDeserializer::tryDeserialize($body);
            if (!$envelope instanceof Envelope) {
                // Row exists but neither standard nor stripslashes-style
                // deserialization yielded an Envelope. Skip rather than
                // crashing the whole page.
                continue;
            }
            // Symfony's DoctrineReceiver attaches a TransportMessageIdStamp
            // carrying the DB row id when it returns envelopes; raw-body
            // deserialization bypasses that, so the id has to be re-attached
            // here or descriptors come back with id = "".
            $rowId = isset($row['id']) && \is_scalar($row['id']) ? (string) $row['id'] : '';
            $envelope = $envelope->with(new TransportMessageIdStamp($rowId));
            $descriptors[] = $this->envelopeToDescriptor($envelope, $this->parseCreatedAt($row['created_at'] ?? null));
        }

        return $descriptors;
    }

    #[Override]
    public function find(string $id): ?MessageDescriptor
    {
        $envelope = $this->findEnvelope($id);
        if (!$envelope instanceof Envelope) {
            return null;
        }
        $createdAt = $this->fetchCreatedAtForEnvelopes([$envelope])[$id] ?? null;

        return $this->envelopeToDescriptor($envelope, $createdAt);
    }

    #[Override]
    public function findEnvelope(string $id): ?Envelope
    {
        // Try Symfony's receiver first — for standard PhpSerializer bodies
        // it adds any bridge-internal stamps (e.g. DoctrineReceivedStamp)
        // that downstream operations like reject() rely on.
        try {
            $envelope = $this->receiver->find($id);
            if ($envelope instanceof Envelope) {
                return $envelope;
            }
        } catch (Throwable) {
            // PhpSerializer throws MessageDecodingFailedException on bodies
            // it can't parse (e.g. addslashes-style escaped storage). Fall
            // through to the tolerant SQL path.
        }

        $access = $this->resolveTableAccess();
        if ($access === null) {
            return null;
        }
        try {
            $body = $access['conn']->fetchOne(
                sprintf('SELECT body FROM %s WHERE id = ?', $access['table']),
                [$id],
            );
        } catch (Throwable) {
            return null;
        }
        if (!is_string($body) || $body === '') {
            return null;
        }

        $envelope = BodyDeserializer::tryDeserialize($body);
        if (!$envelope instanceof Envelope) {
            return null;
        }

        // See list() — raw-body deserialization doesn't carry the row id,
        // so attach it explicitly to match the receiver's behavior.
        return $envelope->with(new TransportMessageIdStamp($id));
    }

    #[Override]
    public function deleteOne(string $id): bool
    {
        $access = $this->resolveTableAccess();
        if ($access === null) {
            // Reflection access unavailable — fall back to the receiver
            // path. Works for standard bodies (where find() returns an
            // envelope carrying DoctrineReceivedStamp, which reject()
            // needs).
            return parent::deleteOne($id);
        }

        try {
            $deleted = $access['conn']->executeStatement(
                sprintf('DELETE FROM %s WHERE id = ? AND queue_name = ?', $access['table']),
                [$id, $this->queueNameFromAccess()],
                ['string', 'string'],
            );

            return $deleted > 0;
        } catch (Throwable) {
            return false;
        }
    }

    #[Override]
    public function purge(): int
    {
        $access = $this->resolveTableAccess();
        if ($access === null) {
            // Reflection-based access failed — fall back to the receiver
            // iteration. Works on standard bodies but will short-circuit on
            // the first un-deserializable row.
            $count = 0;
            foreach ($this->receiver->all(10000) as $envelope) {
                $this->receiver->reject($envelope);
                ++$count;
            }

            return $count;
        }

        try {
            return (int) $access['conn']->executeStatement(
                sprintf('DELETE FROM %s WHERE queue_name = ? AND delivered_at IS NULL', $access['table']),
                [$this->queueNameFromAccess()],
                ['string'],
            );
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Build the SELECT for {@see list()} — with optional LIKE filter.
     *
     * @param array{conn: DbalConnection, table: string} $access
     * @return list<array<string, mixed>> rows with id, body, created_at
     */
    private function fetchRowsForList(array $access, int $offset, int $limit, ?string $query): array
    {
        $params = [$this->queueNameFromAccess(), new DateTimeImmutable('now', new DateTimeZone('UTC'))];
        $types = ['string', 'datetime_immutable'];
        $sql = sprintf(
            'SELECT id, body, created_at FROM %s
             WHERE queue_name = ?
               AND delivered_at IS NULL
               AND available_at <= ?',
            $access['table'],
        );
        if ($query !== null) {
            // See countListable() for why ESCAPE is omitted.
            $sql .= ' AND (body LIKE ? OR headers LIKE ?)';
            $pattern = '%' . $this->normalizeQueryForLike($query) . '%';
            $params[] = $pattern;
            $params[] = $pattern;
            $types[] = 'string';
            $types[] = 'string';
        }
        $sql .= sprintf(' ORDER BY available_at ASC LIMIT %d OFFSET %d', $limit, $offset);

        return $access['conn']->fetchAllAssociative($sql, $params, $types);
    }

    private function parseCreatedAt(mixed $raw): ?DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Look up the storage `created_at` for a batch of envelopes via a
     * single SELECT against the transport's own table.
     *
     * @param list<Envelope> $envelopes
     * @return array<string, DateTimeImmutable> keyed by transport message id
     */
    private function fetchCreatedAtForEnvelopes(array $envelopes): array
    {
        $access = $this->resolveTableAccess();
        if ($access === null) {
            return [];
        }
        $ids = [];
        foreach ($envelopes as $envelope) {
            $stamp = $envelope->last(TransportMessageIdStamp::class);
            if ($stamp !== null) {
                $rawId = $stamp->getId();
                if (\is_scalar($rawId)) {
                    $ids[] = (string) $rawId;
                }
            }
        }
        if ($ids === []) {
            return [];
        }
        try {
            $rows = $access['conn']->fetchAllAssociative(
                sprintf('SELECT id, created_at FROM %s WHERE id IN (?)', $access['table']),
                [$ids],
                [ArrayParameterType::STRING],
            );
        } catch (Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $value = $row['created_at'] ?? null;
            $rawRowId = $row['id'] ?? null;
            $id = \is_scalar($rawRowId) ? (string) $rawRowId : '';
            if (!is_string($value) || $value === '' || $id === '') {
                continue;
            }
            try {
                $map[$id] = new DateTimeImmutable($value);
            } catch (Throwable) {
                // Skip unparseable timestamps.
                continue;
            }
        }

        return $map;
    }

    /**
     * The Doctrine messenger Connection stores the queue name in
     * configuration['queue_name']. Re-read it via reflection on the
     * messenger Connection.
     */
    private function queueNameFromAccess(): string
    {
        // Pull from the same messenger Connection we found in
        // resolveTableAccess(). Falls back to the transport's own name if
        // configuration isn't accessible — most projects keep
        // queue_name === transport name.
        $messengerConnection = $this->extractProperty($this->receiver, 'connection');
        if (is_object($messengerConnection)) {
            $config = $this->extractProperty($messengerConnection, 'configuration');
            if (is_array($config) && isset($config['queue_name']) && is_string($config['queue_name'])) {
                return $config['queue_name'];
            }
        }

        return $this->name;
    }

    /**
     * Normalize the user query for use inside `%…%` with `ESCAPE '\\'`.
     *
     * The controller wraps the query in leading/trailing `%`. If the query
     * itself ends with an odd number of backslashes, the trailing `\` would
     * pair with the appended `%` and escape it — turning the user's free
     * input into an unintended literal `%` match. Pair every trailing
     * backslash so MySQL parses it as a literal `\` (`\\` under `ESCAPE '\\'`)
     * instead, matching what LikePatternToRegex does in the PHP fallback path.
     */
    private function normalizeQueryForLike(string $query): string
    {
        $trailing = 0;
        for ($i = strlen($query) - 1; $i >= 0; --$i) {
            if ($query[$i] !== '\\') {
                break;
            }
            ++$trailing;
        }
        if ($trailing % 2 === 1) {
            return $query . '\\';
        }

        return $query;
    }

    /**
     * Walks the reflection chain receiver → messenger Connection →
     * DBAL connection + table name. Cached after first use; resilient
     * to property-name changes across Symfony versions (any failure
     * disables the override and we fall back to the parent's behavior).
     *
     * @return array{conn: DbalConnection, table: string}|null
     */
    private function resolveTableAccess(): ?array
    {
        if ($this->tableAccess === false) {
            return null;
        }
        if ($this->tableAccess !== null) {
            return $this->tableAccess;
        }

        $messengerConnection = $this->extractProperty($this->receiver, 'connection');
        if (!is_object($messengerConnection)) {
            $this->tableAccess = false;

            return null;
        }
        $dbal = $this->extractProperty($messengerConnection, 'driverConnection');
        $configuration = $this->extractProperty($messengerConnection, 'configuration');
        if (!$dbal instanceof DbalConnection || !is_array($configuration)) {
            $this->tableAccess = false;

            return null;
        }
        $table = (string) ($configuration['table_name'] ?? 'messenger_messages');
        if ($table === '') {
            $this->tableAccess = false;

            return null;
        }

        return $this->tableAccess = ['conn' => $dbal, 'table' => $table];
    }

    private function extractProperty(object $obj, string $property): mixed
    {
        $reflection = new ReflectionObject($obj);
        if (!$reflection->hasProperty($property)) {
            return null;
        }
        $prop = $reflection->getProperty($property);
        if (!$prop->isInitialized($obj)) {
            return null;
        }

        return $prop->getValue($obj);
    }
}
