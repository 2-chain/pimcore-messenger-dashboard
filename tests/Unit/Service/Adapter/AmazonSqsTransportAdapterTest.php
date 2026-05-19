<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\AmazonSqsTransportAdapter;
use LogicException;
use stdClass;

final class AmazonSqsTransportAdapterTest extends TestCase
{
    public function testTypeIsSqs(): void
    {
        $adapter = new AmazonSqsTransportAdapter('sqs_queue', new SqsBareReceiver());
        $this->assertSame('sqs', $adapter->type());
    }

    public function testCanPurgeReflectsConnectionAvailability(): void
    {
        $withConn = new AmazonSqsTransportAdapter('q', new SqsReceiverWithConnection(new stdClass()));
        $this->assertTrue($withConn->capabilities()->canPurge);

        $withoutConn = new AmazonSqsTransportAdapter('q', new SqsBareReceiver());
        $this->assertFalse($withoutConn->capabilities()->canPurge);
    }

    public function testCountAwareDrivesCanCount(): void
    {
        $adapter = new AmazonSqsTransportAdapter('q', new SqsCountAwareReceiver(12));

        $this->assertTrue($adapter->capabilities()->canCount);
        $this->assertSame(12, $adapter->count());
    }

    public function testCountIsZeroWhenReceiverNotCountAware(): void
    {
        $adapter = new AmazonSqsTransportAdapter('q', new SqsBareReceiver());

        $this->assertSame(0, $adapter->count());
    }

    public function testPurgeCallsConnectionResetWhenAvailable(): void
    {
        $connection = new SqsFakeConnection();
        $adapter = new AmazonSqsTransportAdapter('q', new SqsReceiverWithConnection($connection));

        $adapter->purge();

        $this->assertTrue($connection->reset);
    }

    public function testPurgeThrowsWhenConnectionLacksReset(): void
    {
        $adapter = new AmazonSqsTransportAdapter('q', new SqsReceiverWithConnection(new stdClass()));

        $this->expectException(LogicException::class);
        $adapter->purge();
    }

    public function testPurgeThrowsWhenNoConnectionProperty(): void
    {
        $adapter = new AmazonSqsTransportAdapter('q', new SqsBareReceiver());

        $this->expectException(LogicException::class);
        $adapter->purge();
    }

    public function testListingThrows(): void
    {
        $adapter = new AmazonSqsTransportAdapter('q', new SqsBareReceiver());

        $this->expectException(LogicException::class);
        $adapter->list();
    }
}

final class SqsBareReceiver implements ReceiverInterface
{
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}
}

final class SqsReceiverWithConnection implements ReceiverInterface
{
    public function __construct(public readonly object $connection) {}

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}
}

final class SqsCountAwareReceiver implements ReceiverInterface, MessageCountAwareInterface
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

final class SqsFakeConnection
{
    public bool $reset = false;

    public function reset(): void
    {
        $this->reset = true;
    }
}
