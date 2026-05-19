<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\RedisTransportAdapter;
use LogicException;

/**
 * Real Redis access goes through reflection on the bridge's internal
 * Connection. These unit tests cover the "no connection access" fallback
 * and the receiver-level passthroughs; the reflection-based hot path is
 * exercised in integration tests against a real Redis instance.
 */
final class RedisTransportAdapterTest extends TestCase
{
    public function testTypeIsRedis(): void
    {
        $adapter = new RedisTransportAdapter('stream', new RedisBareReceiver());
        $this->assertSame('redis', $adapter->type());
    }

    public function testCapabilitiesFallBackWhenReflectionFails(): void
    {
        $adapter = new RedisTransportAdapter('stream', new RedisBareReceiver());

        $caps = $adapter->capabilities();
        $this->assertFalse($caps->canList);
        $this->assertFalse($caps->canInspectIndividual);
        $this->assertFalse($caps->canDeleteIndividual);
        $this->assertTrue($caps->canRequeue, 'requeue is always advertised true');
        $this->assertFalse($caps->canCount);
    }

    public function testCanPurgeIfReceiverHasNativePurgeMethod(): void
    {
        $adapter = new RedisTransportAdapter('stream', new RedisReceiverWithPurge());

        $this->assertTrue($adapter->capabilities()->canPurge);
    }

    public function testCountFromCountAwareReceiver(): void
    {
        $adapter = new RedisTransportAdapter('stream', new RedisCountAwareReceiver(8));

        $this->assertTrue($adapter->capabilities()->canCount);
        $this->assertSame(8, $adapter->count());
    }

    public function testListReturnsEmptyWhenConnectionUnreachable(): void
    {
        $adapter = new RedisTransportAdapter('stream', new RedisBareReceiver());

        $this->assertSame([], $adapter->list());
    }

    public function testFindReturnsNullWhenConnectionUnreachable(): void
    {
        $adapter = new RedisTransportAdapter('stream', new RedisBareReceiver());

        $this->assertNull($adapter->find('1-0'));
    }

    public function testDeleteOneReturnsFalseWhenConnectionUnreachable(): void
    {
        $adapter = new RedisTransportAdapter('stream', new RedisBareReceiver());

        $this->assertFalse($adapter->deleteOne('1-0'));
    }

    public function testCountListableAlwaysThrows(): void
    {
        $adapter = new RedisTransportAdapter('stream', new RedisBareReceiver());

        $this->expectException(LogicException::class);
        $adapter->countListable();
    }

    public function testFindEnvelopeAlwaysThrows(): void
    {
        $adapter = new RedisTransportAdapter('stream', new RedisBareReceiver());

        $this->expectException(LogicException::class);
        $adapter->findEnvelope('1-0');
    }

    public function testPurgeDelegatesToReceiverNativePurge(): void
    {
        $receiver = new RedisReceiverWithPurge();
        $adapter = new RedisTransportAdapter('stream', $receiver);

        $adapter->purge();

        $this->assertTrue($receiver->purged);
    }

    public function testPurgeThrowsWhenReceiverHasNoPurgeAndNoAccess(): void
    {
        $adapter = new RedisTransportAdapter('stream', new RedisBareReceiver());

        $this->expectException(LogicException::class);
        $adapter->purge();
    }
}

final class RedisBareReceiver implements ReceiverInterface
{
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}
}

final class RedisCountAwareReceiver implements ReceiverInterface, MessageCountAwareInterface
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

final class RedisReceiverWithPurge implements ReceiverInterface
{
    public bool $purged = false;

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}

    public function purge(): int
    {
        $this->purged = true;

        return 3;
    }
}
