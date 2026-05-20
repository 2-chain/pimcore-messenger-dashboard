<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Override;
use ReflectionObject;

/**
 * Adapter for Symfony's Redis transport. Redis Streams DO support read-only
 * enumeration via XRANGE (Symfony just doesn't expose it on
 * ListableReceiverInterface), so we reach into the receiver's connection
 * via reflection to provide list/inspect/delete-individual.
 *
 * Caveats:
 *  - XRANGE returns every entry currently in the stream. Symfony's Redis
 *    transport XACKs handled messages but doesn't always XDEL them right
 *    away (the consumer-group ack list grows; the stream itself is pruned
 *    lazily). The list may therefore include entries that have already been
 *    processed but not yet XDEL'd. The Redis "count" comes from Symfony's
 *    own `getMessageCount()` and is authoritative.
 *  - Reflection access can break across Symfony upgrades. If the property
 *    layout changes, the adapter falls back to count-only without throwing.
 */
final class RedisTransportAdapter implements TransportAdapterInterface
{
    /**
     * Cached snapshot of the (Redis client, stream name) tuple. Resolved
     * lazily on first use; null if the receiver's internals don't match
     * the expected shape.
     *
     * @var array{client: object, stream: string}|null|false
     */
    private array|false|null $access = null;

    public function __construct(
        private readonly string $name,
        private readonly ReceiverInterface $receiver,
    ) {}

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    #[Override]
    public function type(): string
    {
        return 'redis';
    }

    #[Override]
    public function capabilities(): Capabilities
    {
        $hasAccess = $this->resolveAccess() !== null;

        return new Capabilities(
            canCount: $this->receiver instanceof MessageCountAwareInterface,
            canList: $hasAccess,
            canInspectIndividual: $hasAccess,
            canDeleteIndividual: $hasAccess,
            canPurge: $hasAccess || method_exists($this->receiver, 'purge'),
            canRequeue: true,
        );
    }

    #[Override]
    public function count(): int
    {
        if (!$this->receiver instanceof MessageCountAwareInterface) {
            return 0;
        }

        return $this->receiver->getMessageCount();
    }

    #[Override]
    public function countListable(?string $query = null): int
    {
        throw new LogicException('Redis transport does not support listing messages.');
    }

    #[Override]
    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        // Redis adapter ignores $query — XRANGE doesn't support a substring
        // filter and pulling every entry into PHP just to filter would
        // defeat the point of the per-transport scope. The UI still
        // displays the search box for consistency; results are just
        // unfiltered for Redis transports.
        $access = $this->resolveAccess();
        if ($access === null) {
            return [];
        }

        $entries = $this->xrange($access, $offset + $limit);
        $sliced = array_slice($entries, $offset, $limit, true);

        $descriptors = [];
        foreach ($sliced as $entryId => $fields) {
            // `xrange()` declares `array<string, array<string, mixed>>`, so
            // each $fields is already an array — no defensive coercion needed.
            $descriptors[] = $this->buildDescriptor((string) $entryId, $fields);
        }

        return $descriptors;
    }

    #[Override]
    public function find(string $id): ?MessageDescriptor
    {
        $access = $this->resolveAccess();
        if ($access === null) {
            return null;
        }

        $entries = $this->callRedis($access['client'], 'xRange', [$access['stream'], $id, $id]);
        if (!is_array($entries) || $entries === []) {
            return null;
        }
        $fields = reset($entries);
        $entryId = (string) key($entries);

        return $this->buildDescriptor($entryId, is_array($fields) ? $fields : []);
    }

    /**
     * Redis adapter never returns Symfony Envelopes because we bypass the
     * bridge's serializer entirely (we read raw stream entries via XRANGE).
     * Requeue paths shouldn't call this on Redis — the failed transport is
     * Doctrine by default. If a user wired Redis as the failed transport,
     * they can still use the bridge's own `messenger:failed:retry` command.
     */
    #[Override]
    public function findEnvelope(string $id): ?\Symfony\Component\Messenger\Envelope
    {
        throw new LogicException('Redis transport adapter does not expose envelopes; use messenger:failed:retry for Redis-backed failed transports.');
    }

    #[Override]
    public function deleteOne(string $id): bool
    {
        $access = $this->resolveAccess();
        if ($access === null) {
            return false;
        }
        $rawDeleted = $this->callRedis($access['client'], 'xDel', [$access['stream'], [$id]]);

        return \is_numeric($rawDeleted) && ((int) $rawDeleted) > 0;
    }

    #[Override]
    public function purge(): int
    {
        // Prefer the bridge's own purge if present (handles consumer groups
        // properly); otherwise fall back to XTRIM via reflection access.
        if (method_exists($this->receiver, 'purge')) {
            return (int) $this->receiver->purge();
        }
        $access = $this->resolveAccess();
        if ($access === null) {
            throw new LogicException('Redis transport adapter cannot purge — connection access unavailable.');
        }
        // XTRIM MAXLEN 0 empties the stream.
        $this->callRedis($access['client'], 'xTrim', [$access['stream'], 0]);

        return 0;
    }

    /**
     * Locate the Redis client and stream name via reflection.
     *
     * @return array{client: object, stream: string}|null
     */
    private function resolveAccess(): ?array
    {
        if ($this->access === false) {
            return null;
        }
        if ($this->access !== null) {
            return $this->access;
        }

        $connection = $this->extractPrivateProperty($this->receiver, 'connection');
        if (!is_object($connection)) {
            $this->access = false;

            return null;
        }
        $client = $this->extractPrivateProperty($connection, 'connection')
            ?? $this->extractPrivateProperty($connection, 'redis')
            ?? $this->extractPrivateProperty($connection, 'client');
        $stream = $this->extractPrivateProperty($connection, 'stream');

        if (!is_object($client) || !is_string($stream) || $stream === '') {
            $this->access = false;

            return null;
        }

        return $this->access = ['client' => $client, 'stream' => $stream];
    }

    /**
     * @param array{client: object, stream: string} $access
     * @return array<string, array<string, mixed>>
     */
    private function xrange(array $access, int $limit): array
    {
        $result = $this->callRedis($access['client'], 'xRange', [$access['stream'], '-', '+', $limit]);

        return is_array($result) ? $result : [];
    }

    /** @param array<int, mixed> $args */
    private function callRedis(object $client, string $method, array $args): mixed
    {
        // phpredis exposes XRANGE as `xRange`; Predis as `xrange`. Try both.
        if (method_exists($client, $method)) {
            return $client->$method(...$args);
        }
        $lower = strtolower($method);
        if (method_exists($client, $lower)) {
            return $client->$lower(...$args);
        }
        throw new LogicException(sprintf('Redis client (%s) does not expose %s().', $client::class, $method));
    }

    /**
     * @param array<string, mixed> $fields raw stream entry fields (typically [message=>JSON, headers=>JSON])
     */
    private function buildDescriptor(string $entryId, array $fields): MessageDescriptor
    {
        $messageClass = '';
        if (isset($fields['message']) && is_string($fields['message'])) {
            $decoded = json_decode($fields['message'], true);
            if (is_array($decoded) && isset($decoded['class']) && is_string($decoded['class'])) {
                $messageClass = $decoded['class'];
            }
        }

        $bodyParts = [];
        foreach ($fields as $field => $value) {
            $bodyParts[$field] = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        $bodyPreview = json_encode($bodyParts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return new MessageDescriptor(
            id: $entryId,
            messageClass: $messageClass !== '' ? $messageClass : 'redis.stream.entry',
            createdAt: $this->entryIdToDate($entryId),
            retryCount: null,
            headers: ['streamEntryId' => $entryId],
            bodyPreview: $bodyPreview === false ? null : mb_strcut($bodyPreview, 0, MessageDescriptor::MAX_BODY_PREVIEW_BYTES, 'UTF-8'),
        );
    }

    /**
     * Redis stream IDs are "<ms-since-epoch>-<seq>", so the timestamp is
     * embedded in the id itself.
     */
    private function entryIdToDate(string $entryId): DateTimeImmutable
    {
        if (preg_match('/^(\d+)-/', $entryId, $m) === 1) {
            $seconds = (int) floor(((int) $m[1]) / 1000);

            return (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }

        return new DateTimeImmutable();
    }

    private function extractPrivateProperty(object $obj, string $property): mixed
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
