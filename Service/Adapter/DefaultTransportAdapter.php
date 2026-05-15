<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Count-only fallback adapter. Used for any receiver that doesn't implement
 * a richer interface (or for transports where listing/inspection isn't
 * supported by the broker — e.g. plain Redis pub/sub, custom transports).
 *
 * If the receiver doesn't even implement MessageCountAwareInterface, count()
 * returns 0 (no way to ask) — the UI just shows a `—` for that transport.
 */
class DefaultTransportAdapter implements TransportAdapterInterface
{
    public function __construct(
        protected readonly string $name,
        protected readonly ReceiverInterface $receiver,
        protected readonly string $type = 'unknown',
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
        return $this->type;
    }

    #[\Override]
    public function capabilities(): Capabilities
    {
        return new Capabilities(canCount: $this->receiver instanceof MessageCountAwareInterface);
    }

    #[\Override]
    public function count(): int
    {
        if (!$this->receiver instanceof MessageCountAwareInterface) {
            return 0;
        }

        return $this->receiver->getMessageCount();
    }

    #[\Override]
    public function countListable(?string $query = null): int
    {
        throw new \LogicException(sprintf('Transport "%s" does not support listing messages.', $this->name));
    }

    #[\Override]
    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        throw new \LogicException(sprintf('Transport "%s" does not support listing messages.', $this->name));
    }

    #[\Override]
    public function find(string $id): ?MessageDescriptor
    {
        throw new \LogicException(sprintf('Transport "%s" does not support per-message inspection.', $this->name));
    }

    #[\Override]
    public function findEnvelope(string $id): ?\Symfony\Component\Messenger\Envelope
    {
        throw new \LogicException(sprintf('Transport "%s" does not support per-message inspection.', $this->name));
    }

    #[\Override]
    public function deleteOne(string $id): bool
    {
        throw new \LogicException(sprintf('Transport "%s" does not support deleting individual messages.', $this->name));
    }

    #[\Override]
    public function purge(): int
    {
        throw new \LogicException(sprintf('Transport "%s" does not support purge.', $this->name));
    }
}
