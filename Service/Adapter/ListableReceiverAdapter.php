<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use LogicException;
use Override;

/**
 * Generic listable-transport adapter. Works with anything that implements
 * Symfony's ListableReceiverInterface (Doctrine transport, in-memory transport,
 * any third-party transport that follows the contract).
 *
 * Capabilities: count + list + inspect + delete-individual.
 * Bulk delete and purge are left to specialized subclasses
 * (DoctrineTransportAdapter overrides with raw-DBAL implementations).
 */
class ListableReceiverAdapter implements TransportAdapterInterface
{
    use EnvelopeDescribing;

    public function __construct(
        protected readonly string $name,
        protected readonly ListableReceiverInterface $receiver,
        protected readonly string $type = 'unknown',
    ) {}

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    #[Override]
    public function type(): string
    {
        return $this->type;
    }

    #[Override]
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            canCount: $this->receiver instanceof MessageCountAwareInterface,
            canList: true,
            canInspectIndividual: true,
            canDeleteIndividual: true,
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
        $count = 0;
        $regex = $query !== null ? LikePatternToRegex::convert($query) : null;

        foreach ($this->receiver->all($this->fetchCap()) as $envelope) {
            if ($regex === null || $this->envelopeMatches($envelope, $regex)) {
                ++$count;
            }
        }

        return $count;
    }

    #[Override]
    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        $regex = $query !== null ? LikePatternToRegex::convert($query) : null;

        $matches = [];
        foreach ($this->receiver->all($this->fetchCap()) as $envelope) {
            if ($regex === null || $this->envelopeMatches($envelope, $regex)) {
                $matches[] = $envelope;
                if ($regex === null && count($matches) >= $offset + $limit) {
                    // Fast-exit when no filter is active — stop fetching as
                    // soon as we have enough envelopes to satisfy the page.
                    break;
                }
            }
        }

        $sliced = array_slice($matches, $offset, $limit);

        return array_map([$this, 'envelopeToDescriptor'], $sliced);
    }

    #[Override]
    public function find(string $id): ?MessageDescriptor
    {
        $envelope = $this->findEnvelope($id);
        if (!$envelope instanceof \Symfony\Component\Messenger\Envelope) {
            return null;
        }

        return $this->envelopeToDescriptor($envelope);
    }

    #[Override]
    public function findEnvelope(string $id): ?Envelope
    {
        return $this->receiver->find($id);
    }

    #[Override]
    public function deleteOne(string $id): bool
    {
        $envelope = $this->findEnvelope($id);
        if (!$envelope instanceof \Symfony\Component\Messenger\Envelope) {
            return false;
        }

        $this->receiver->reject($envelope);

        return true;
    }

    #[Override]
    public function purge(): int
    {
        throw new LogicException(sprintf('Transport "%s" does not support purge in the generic adapter.', $this->name));
    }

    /**
     * Hard cap on how many envelopes we materialize per request when a
     * filter is active. Avoids unbounded memory on huge queues; documented
     * as "best effort above N" in the spec.
     */
    protected function fetchCap(): int
    {
        return 5000;
    }
}
