<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use LogicException;
use Override;

/**
 * Adapter for Symfony's Beanstalkd transport
 * (symfony/beanstalkd-messenger). Beanstalkd tubes don't allow message
 * enumeration without consuming, so list/inspect/delete-individual are
 * unavailable. Count comes from MessageCountAwareInterface.
 *
 * Beanstalkd has no native bulk-purge primitive — clearing a tube requires
 * iterating jobs and deleting each, which our generic adapter doesn't model.
 * Purge is therefore advertised as unsupported.
 */
final readonly class BeanstalkdTransportAdapter implements TransportAdapterInterface
{
    public function __construct(
        private string $name,
        private ReceiverInterface $receiver,
    ) {}

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    #[Override]
    public function type(): string
    {
        return 'beanstalkd';
    }

    #[Override]
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            canCount: $this->receiver instanceof MessageCountAwareInterface,
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
        throw new LogicException('Beanstalkd transport does not support listing messages.');
    }

    #[Override]
    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        throw new LogicException('Beanstalkd transport does not support listing messages.');
    }

    #[Override]
    public function find(string $id): ?MessageDescriptor
    {
        throw new LogicException('Beanstalkd transport does not support per-message inspection.');
    }

    #[Override]
    public function findEnvelope(string $id): ?\Symfony\Component\Messenger\Envelope
    {
        throw new LogicException('Beanstalkd transport does not support per-message inspection.');
    }

    #[Override]
    public function deleteOne(string $id): bool
    {
        throw new LogicException('Beanstalkd transport does not support deleting individual messages.');
    }

    #[Override]
    public function purge(): int
    {
        throw new LogicException('Beanstalkd transport does not support bulk purge.');
    }
}
