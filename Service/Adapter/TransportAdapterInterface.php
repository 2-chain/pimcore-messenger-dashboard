<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Symfony\Component\Messenger\Envelope;
use RuntimeException;

/**
 * One adapter per configured Symfony Messenger transport. Implementations
 * advertise their feature set via capabilities(); the UI and controllers
 * read capabilities first and only call the relevant methods.
 *
 * Methods that the adapter doesn't support (canX === false) throw
 * \LogicException — that's a programming error, not an expected user error.
 */
interface TransportAdapterInterface
{
    public function name(): string;

    /** "doctrine" | "redis" | "amqp" | "in_memory" | "sync" | "unknown" */
    public function type(): string;

    public function capabilities(): Capabilities;

    /**
     * @throws RuntimeException if the transport's underlying backend is
     *                           unreachable (Redis down, AMQP broker offline, etc.)
     */
    public function count(): int;

    /**
     * Filtered-count for the listable surface — what `list($_, $_, $query)`
     * would return as `total` if iterated. Separate from count() which
     * always returns the unfiltered live queue depth (used by the sidebar
     * badge + the "In queue" stats column).
     *
     * Adapters where canList === false MUST throw \LogicException.
     */
    public function countListable(?string $query = null): int;

    /**
     * @param int     $offset Zero-based row offset for pagination.
     * @param int     $limit  Maximum rows to return.
     * @param ?string $query  Substring search with `%` and `_` wildcards
     *                        (backslash escapes them for literal matches).
     *                        null = no filter. Implementations may apply
     *                        this via SQL LIKE or a regex post-filter
     *                        depending on the underlying transport.
     * @return list<MessageDescriptor>
     */
    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array;

    public function find(string $id): ?MessageDescriptor;

    /**
     * Lower-level lookup that returns the raw Symfony Envelope (with all
     * stamps) rather than a flattened MessageDescriptor. Used by requeue
     * paths where we need to re-dispatch the envelope through the message
     * bus.
     */
    public function findEnvelope(string $id): ?Envelope;

    public function deleteOne(string $id): bool;

    /** @return int rows removed */
    public function purge(): int;
}
