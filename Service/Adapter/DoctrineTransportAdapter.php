<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection as DbalConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * Doctrine-backed transport adapter. The Doctrine transport in Symfony
 * Messenger is implemented as a `DoctrineTransport` class (not just the
 * inner `DoctrineReceiver`) that itself implements ListableReceiverInterface
 * — so we accept the broader interface here and let the factory's FQCN
 * check decide it's the Doctrine variant.
 *
 * Inherits everything listable from ListableReceiverAdapter and:
 *  - Adds full capability flags.
 *  - Implements purge() via the receiver API.
 *  - Side-channel-queries the `created_at` column so descriptors reflect
 *    the actual storage timestamp instead of "now" on every render
 *    (Symfony's receiver doesn't stamp creation time back onto envelopes).
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
    private array|null|false $tableAccess = null;

    public function __construct(string $name, ListableReceiverInterface $receiver)
    {
        parent::__construct($name, $receiver, 'doctrine');
    }

    #[\Override]
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

    #[\Override]
    public function countListable(?string $query = null): int
    {
        $access = $this->resolveTableAccess();
        if ($access === null) {
            // Side-channel unavailable — fall back to parent's PHP filter
            // which uses the receiver API directly.
            return parent::countListable($query);
        }

        $params = [$this->queueNameFromAccess(), new \DateTimeImmutable('now', new \DateTimeZone('UTC'))];
        $types = ['string', 'datetime_immutable'];
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE queue_name = ? AND delivered_at IS NULL AND available_at <= ?',
            $access['table'],
        );
        if ($query !== null) {
            $sql .= " AND (body LIKE ? OR headers LIKE ?) ESCAPE '\\\\'";
            $pattern = '%' . $this->normalizeQueryForLike($query) . '%';
            $params[] = $pattern;
            $params[] = $pattern;
            $types[] = 'string';
            $types[] = 'string';
        }

        try {
            return (int) $access['conn']->fetchOne($sql, $params, $types);
        } catch (\Throwable) {
            return parent::countListable($query);
        }
    }

    #[\Override]
    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        if ($query !== null) {
            return $this->listFiltered($offset, $limit, $query);
        }

        $envelopes = [];
        foreach ($this->receiver->all($offset + $limit) as $envelope) {
            $envelopes[] = $envelope;
        }
        $sliced = array_slice($envelopes, $offset, $limit);

        $createdAtMap = $this->fetchCreatedAtForEnvelopes($sliced);

        $descriptors = [];
        foreach ($sliced as $envelope) {
            $id = (string) ($envelope->last(TransportMessageIdStamp::class)?->getId() ?? '');
            $descriptors[] = $this->envelopeToDescriptor($envelope, $createdAtMap[$id] ?? null);
        }

        return $descriptors;
    }

    #[\Override]
    public function find(string $id): ?MessageDescriptor
    {
        $envelope = $this->findEnvelope($id);
        if (!$envelope instanceof \Symfony\Component\Messenger\Envelope) {
            return null;
        }
        $createdAt = $this->fetchCreatedAtForEnvelopes([$envelope])[$id] ?? null;

        return $this->envelopeToDescriptor($envelope, $createdAt);
    }

    #[\Override]
    public function purge(): int
    {
        $count = 0;
        // Pull the current backlog and reject each. Cap at a sane upper
        // bound to avoid runaway memory on huge tables — controllers should
        // call repeatedly if needed.
        foreach ($this->receiver->all(10000) as $envelope) {
            $this->receiver->reject($envelope);
            ++$count;
        }

        return $count;
    }

    /**
     * Look up the storage `created_at` for a batch of envelopes via a
     * single SELECT against the transport's own table.
     *
     * @param list<Envelope> $envelopes
     * @return array<string, \DateTimeImmutable> keyed by transport message id
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
                $ids[] = (string) $stamp->getId();
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
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $value = $row['created_at'] ?? null;
            $id = (string) ($row['id'] ?? '');
            if (!is_string($value) || $value === '' || $id === '') {
                continue;
            }
            try {
                $map[$id] = new \DateTimeImmutable($value);
            } catch (\Throwable) {
                // Skip unparseable timestamps.
                continue;
            }
        }

        return $map;
    }

    /**
     * SQL-LIKE filtered listing — pulls matching ids from the underlying
     * table, then routes each id back through the receiver so the existing
     * envelope-to-descriptor pipeline reconstructs MessageDescriptors.
     *
     * @return list<MessageDescriptor>
     */
    private function listFiltered(int $offset, int $limit, string $query): array
    {
        $access = $this->resolveTableAccess();
        if ($access === null) {
            // Fall back to the parent's PHP filter when reflection access
            // is unavailable. Slower but correct.
            return parent::list($offset, $limit, $query);
        }

        $pattern = '%' . $this->normalizeQueryForLike($query) . '%';
        $sql = sprintf(
            'SELECT id, created_at FROM %s
             WHERE queue_name = ?
               AND delivered_at IS NULL
               AND available_at <= ?
               AND (body LIKE ? OR headers LIKE ?) ESCAPE \'\\\\\'
             ORDER BY available_at ASC
             LIMIT %d OFFSET %d',
            $access['table'],
            $limit,
            $offset,
        );

        try {
            $rows = $access['conn']->fetchAllAssociative(
                $sql,
                [$this->queueNameFromAccess(), new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $pattern, $pattern],
                ['string', 'datetime_immutable', 'string', 'string'],
            );
        } catch (\Throwable) {
            return parent::list($offset, $limit, $query);
        }

        $descriptors = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $envelope = $this->receiver->find($id);
            if (!$envelope instanceof \Symfony\Component\Messenger\Envelope) {
                continue; // raced with a worker — message vanished between our COUNT and lookup
            }
            $createdAt = null;
            $rawCreated = $row['created_at'] ?? null;
            if (is_string($rawCreated) && $rawCreated !== '') {
                try {
                    $createdAt = new \DateTimeImmutable($rawCreated);
                } catch (\Throwable) {
                    // skip unparseable timestamp
                }
            }
            $descriptors[] = $this->envelopeToDescriptor($envelope, $createdAt);
        }

        return $descriptors;
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
        $reflection = new \ReflectionObject($obj);
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
