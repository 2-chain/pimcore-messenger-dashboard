<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use LogicException;
use Override;
use ReflectionObject;

/**
 * AMQP transport adapter. AMQP queues don't allow message enumeration
 * without consuming, so list/inspect/delete-individual are unavailable.
 * Purge uses queue.purge via the connection.
 *
 * Receiver is typed broadly (`ReceiverInterface`) so we work with both the
 * wrapping `AmqpTransport` and the inner `AmqpReceiver`; the factory's
 * FQCN check is what gates us to AMQP-shaped receivers.
 */
final readonly class AmqpTransportAdapter implements TransportAdapterInterface
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
        return 'amqp';
    }

    #[Override]
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            canCount: $this->receiver instanceof MessageCountAwareInterface,
            canPurge: true,
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
        throw new LogicException('AMQP transport does not support listing messages.');
    }

    #[Override]
    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        throw new LogicException('AMQP transport does not support listing messages.');
    }

    #[Override]
    public function find(string $id): ?MessageDescriptor
    {
        throw new LogicException('AMQP transport does not support per-message inspection.');
    }

    #[Override]
    public function findEnvelope(string $id): ?\Symfony\Component\Messenger\Envelope
    {
        throw new LogicException('AMQP transport does not support per-message inspection.');
    }

    #[Override]
    public function deleteOne(string $id): bool
    {
        throw new LogicException('AMQP transport does not support deleting individual messages.');
    }

    #[Override]
    public function purge(): int
    {
        // AmqpReceiver doesn't expose connection access publicly, so we rely
        // on a reflection access pattern. If the underlying API shifts
        // between Symfony versions, this falls back to a clear error.
        $reflection = new ReflectionObject($this->receiver);
        if (!$reflection->hasProperty('connection')) {
            throw new LogicException('AMQP adapter cannot reach the connection to purge.');
        }
        $prop = $reflection->getProperty('connection');
        $connection = $prop->getValue($this->receiver);

        if (!method_exists($connection, 'queue')) {
            throw new LogicException('AMQP connection does not expose queue() — purge unavailable.');
        }
        $queue = $connection->queue($this->name);
        if (!method_exists($queue, 'purge')) {
            throw new LogicException('AMQP queue does not expose purge().');
        }

        return (int) $queue->purge();
    }
}
