<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\DefaultTransportAdapter;
use LogicException;

final class DefaultTransportAdapterTest extends TestCase
{
    public function testNameAndTypeArePassedThrough(): void
    {
        $adapter = new DefaultTransportAdapter('my_transport', new NoopReceiver(), 'custom');

        $this->assertSame('my_transport', $adapter->name());
        $this->assertSame('custom', $adapter->type());
    }

    public function testTypeDefaultsToUnknown(): void
    {
        $adapter = new DefaultTransportAdapter('t', new NoopReceiver());

        $this->assertSame('unknown', $adapter->type());
    }

    public function testCapabilitiesAreCountOnlyForCountAwareReceiver(): void
    {
        $adapter = new DefaultTransportAdapter('t', new CountAwareReceiver(7));

        $caps = $adapter->capabilities();
        $this->assertTrue($caps->canCount);
        $this->assertFalse($caps->canList);
        $this->assertFalse($caps->canDeleteIndividual);
        $this->assertFalse($caps->canPurge);
    }

    public function testCapabilitiesAreEmptyWhenReceiverIsNotCountAware(): void
    {
        $adapter = new DefaultTransportAdapter('t', new NoopReceiver());

        $this->assertFalse($adapter->capabilities()->canCount);
    }

    public function testCountReturnsZeroWhenNotCountAware(): void
    {
        $adapter = new DefaultTransportAdapter('t', new NoopReceiver());

        $this->assertSame(0, $adapter->count());
    }

    public function testCountDelegatesToCountAwareReceiver(): void
    {
        $adapter = new DefaultTransportAdapter('t', new CountAwareReceiver(42));

        $this->assertSame(42, $adapter->count());
    }

    public function testListingOperationsThrowLogicException(): void
    {
        $adapter = new DefaultTransportAdapter('queue_x', new NoopReceiver());

        foreach (['countListable', 'list', 'find', 'findEnvelope', 'deleteOne', 'purge'] as $method) {
            try {
                $method === 'find' || $method === 'findEnvelope' || $method === 'deleteOne'
                    ? $adapter->$method('id')
                    : $adapter->$method();
                $this->fail(sprintf('Expected LogicException from %s()', $method));
            } catch (LogicException $e) {
                $this->assertStringContainsString('queue_x', $e->getMessage(), $method);
            }
        }
    }
}

final class NoopReceiver implements ReceiverInterface
{
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}
}

final class CountAwareReceiver implements ReceiverInterface, MessageCountAwareInterface
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
