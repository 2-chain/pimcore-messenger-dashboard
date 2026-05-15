<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Adapter for Symfony's Amazon SQS transport
 * (symfony/amazon-sqs-messenger). SQS queues don't allow message
 * enumeration without consuming, so list/inspect/delete-individual are
 * unavailable. Count comes from MessageCountAwareInterface.
 *
 * SQS exposes a native PurgeQueue API. We reach the underlying Connection
 * via reflection (the receiver doesn't expose it publicly) and call
 * `reset()`, which is the bridge's wrapper around PurgeQueue.
 */
final readonly class AmazonSqsTransportAdapter implements TransportAdapterInterface
{
    public function __construct(
        private string $name,
        private ReceiverInterface $receiver,
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
        return 'sqs';
    }

    #[\Override]
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            canCount: $this->receiver instanceof MessageCountAwareInterface,
            canPurge: $this->hasConnection(),
        );
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
        throw new \LogicException('Amazon SQS transport does not support listing messages.');
    }

    #[\Override]
    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        throw new \LogicException('Amazon SQS transport does not support listing messages.');
    }

    #[\Override]
    public function find(string $id): ?MessageDescriptor
    {
        throw new \LogicException('Amazon SQS transport does not support per-message inspection.');
    }

    #[\Override]
    public function findEnvelope(string $id): ?\Symfony\Component\Messenger\Envelope
    {
        throw new \LogicException('Amazon SQS transport does not support per-message inspection.');
    }

    #[\Override]
    public function deleteOne(string $id): bool
    {
        throw new \LogicException('Amazon SQS transport does not support deleting individual messages.');
    }

    #[\Override]
    public function purge(): int
    {
        $connection = $this->reflectConnection();
        if ($connection === null || !method_exists($connection, 'reset')) {
            throw new \LogicException('Amazon SQS adapter cannot reach the connection to purge.');
        }
        $connection->reset();

        return 0;
    }

    private function hasConnection(): bool
    {
        return $this->reflectConnection() !== null;
    }

    private function reflectConnection(): ?object
    {
        $reflection = new \ReflectionObject($this->receiver);
        if (!$reflection->hasProperty('connection')) {
            return null;
        }

        $prop = $reflection->getProperty('connection');
        $value = $prop->getValue($this->receiver);

        return is_object($value) ? $value : null;
    }
}
