<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Adapter for Symfony's in-memory transport.
 *
 * The in-memory transport doesn't implement `ListableReceiverInterface`,
 * so we can't reuse `ListableReceiverAdapter` directly. Instead we read the
 * pending queue via `get()` (which returns the still-pending envelopes,
 * decoded and stamped with `TransportMessageIdStamp`).
 *
 * Lifecycle caveat: an in-memory transport's storage is scoped to a single
 * PHP request. Messages dispatched in one HTTP request do not survive into
 * the next one, so the dashboard's polling only sees what the current
 * request's bootstrap put into the queue. In practice in-memory transports
 * are useful for testing/local dev, where the dashboard's value is
 * "what's in the queue right now" — which still works.
 */
final class InMemoryTransportAdapter implements TransportAdapterInterface
{
    use EnvelopeDescribing;

    private const int FETCH_CAP = 5000;

    public function __construct(
        private readonly string $name,
        private readonly InMemoryTransport $transport,
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function type(): string
    {
        return 'in_memory';
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
            canRequeue: false,
        );
    }

    #[\Override]
    public function count(): int
    {
        return count($this->pending());
    }

    #[\Override]
    public function countListable(?string $query = null): int
    {
        if ($query === null) {
            return $this->count();
        }
        $regex = LikePatternToRegex::convert($query);
        $count = 0;
        foreach ($this->pending() as $envelope) {
            if ($this->envelopeMatches($envelope, $regex)) {
                ++$count;
            }
        }

        return $count;
    }

    #[\Override]
    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        $regex = $query !== null ? LikePatternToRegex::convert($query) : null;
        $matches = [];
        foreach ($this->pending() as $envelope) {
            if ($regex === null || $this->envelopeMatches($envelope, $regex)) {
                $matches[] = $envelope;
                if ($regex === null && count($matches) >= $offset + $limit) {
                    break;
                }
            }
        }
        $sliced = array_slice($matches, $offset, $limit);

        return array_map(fn (Envelope $e): MessageDescriptor => $this->envelopeToDescriptor($e), $sliced);
    }

    #[\Override]
    public function find(string $id): ?MessageDescriptor
    {
        $envelope = $this->findEnvelope($id);

        return $envelope !== null ? $this->envelopeToDescriptor($envelope) : null;
    }

    #[\Override]
    public function findEnvelope(string $id): ?Envelope
    {
        foreach ($this->pending() as $envelope) {
            if ($this->envelopeId($envelope) === $id) {
                return $envelope;
            }
        }

        return null;
    }

    #[\Override]
    public function deleteOne(string $id): bool
    {
        $envelope = $this->findEnvelope($id);
        if ($envelope === null) {
            return false;
        }
        // InMemoryTransport requires a TransportMessageIdStamp on reject().
        // send() always attaches one, so any envelope returned by pending()
        // is guaranteed to carry it — but we double-check defensively.
        if ($envelope->last(TransportMessageIdStamp::class) === null) {
            return false;
        }
        $this->transport->reject($envelope);

        return true;
    }

    #[\Override]
    public function purge(): int
    {
        $count = 0;
        // Reject each pending envelope so it leaves the queue. We don't
        // call reset() because that would also wipe the acknowledged/rejected
        // history that the transport tracks for testing introspection.
        foreach ($this->pending() as $envelope) {
            if ($envelope->last(TransportMessageIdStamp::class) === null) {
                continue;
            }
            $this->transport->reject($envelope);
            ++$count;
        }

        return $count;
    }

    /** @return list<Envelope> */
    private function pending(): array
    {
        $queued = $this->transport->get();
        $envelopes = is_array($queued) ? $queued : iterator_to_array($queued, false);

        // Cap to bound memory in pathological cases.
        return array_slice($envelopes, 0, self::FETCH_CAP);
    }

    private function envelopeId(Envelope $envelope): string
    {
        $stamp = $envelope->last(TransportMessageIdStamp::class);

        return $stamp !== null ? (string) $stamp->getId() : '';
    }
}
