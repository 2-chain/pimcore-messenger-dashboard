<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\AmqpTransportAdapter;
use LogicException;

final class AmqpTransportAdapterTest extends TestCase
{
    public function testTypeIsAmqp(): void
    {
        $adapter = new AmqpTransportAdapter('rabbitmq', new AmqpNoopReceiver());

        $this->assertSame('amqp', $adapter->type());
        $this->assertSame('rabbitmq', $adapter->name());
    }

    public function testCapabilitiesAreCountAndPurgeOnly(): void
    {
        $adapter = new AmqpTransportAdapter('q', new AmqpCountAwareReceiver(0));

        $caps = $adapter->capabilities();
        $this->assertTrue($caps->canCount);
        $this->assertTrue($caps->canPurge);
        $this->assertFalse($caps->canList);
        $this->assertFalse($caps->canDeleteIndividual);
        $this->assertFalse($caps->canRequeue);
    }

    public function testCountIsZeroWhenReceiverIsNotCountAware(): void
    {
        $adapter = new AmqpTransportAdapter('q', new AmqpNoopReceiver());

        $this->assertSame(0, $adapter->count());
        $this->assertFalse($adapter->capabilities()->canCount);
    }

    public function testCountDelegatesToCountAwareReceiver(): void
    {
        $adapter = new AmqpTransportAdapter('q', new AmqpCountAwareReceiver(13));

        $this->assertSame(13, $adapter->count());
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = new AmqpTransportAdapter('q', new AmqpNoopReceiver());

        foreach (['countListable', 'list'] as $method) {
            try {
                $adapter->$method();
                $this->fail($method);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['find', 'findEnvelope', 'deleteOne'] as $method) {
            try {
                $adapter->$method('id');
                $this->fail($method);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPurgeFailsCleanlyWhenReceiverHasNoConnectionProperty(): void
    {
        $adapter = new AmqpTransportAdapter('q', new AmqpNoopReceiver());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/cannot reach the connection/i');
        $adapter->purge();
    }
}

final class AmqpNoopReceiver implements ReceiverInterface
{
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}
}

final class AmqpCountAwareReceiver implements ReceiverInterface, MessageCountAwareInterface
{
    public function __construct(private readonly int $count) {}

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}

    public function getMessageCount(): int
    {
        return $this->count;
    }
}
