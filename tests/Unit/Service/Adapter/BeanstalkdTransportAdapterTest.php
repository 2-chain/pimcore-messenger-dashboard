<?php
declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\BeanstalkdTransportAdapter;

final class BeanstalkdTransportAdapterTest extends TestCase
{
    public function testTypeAndName(): void
    {
        $adapter = new BeanstalkdTransportAdapter('jobs', new BsCountAware(0));

        $this->assertSame('beanstalkd', $adapter->type());
        $this->assertSame('jobs', $adapter->name());
    }

    public function testOnlyCountIsAdvertised(): void
    {
        $adapter = new BeanstalkdTransportAdapter('jobs', new BsCountAware(5));

        $caps = $adapter->capabilities();
        $this->assertTrue($caps->canCount);
        $this->assertFalse($caps->canList);
        $this->assertFalse($caps->canPurge);
        $this->assertFalse($caps->canDeleteIndividual);
    }

    public function testCountDelegatesToReceiver(): void
    {
        $adapter = new BeanstalkdTransportAdapter('jobs', new BsCountAware(99));

        $this->assertSame(99, $adapter->count());
    }

    public function testCountReturnsZeroWhenReceiverIsNotCountAware(): void
    {
        $adapter = new BeanstalkdTransportAdapter('jobs', new BsNoop());

        $this->assertSame(0, $adapter->count());
    }

    public function testAllMutatingOperationsThrow(): void
    {
        $adapter = new BeanstalkdTransportAdapter('jobs', new BsNoop());

        foreach (['countListable', 'list', 'purge'] as $method) {
            try {
                $adapter->$method();
                $this->fail($method);
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['find', 'findEnvelope', 'deleteOne'] as $method) {
            try {
                $adapter->$method('x');
                $this->fail($method);
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

final class BsNoop implements ReceiverInterface
{
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }
}

final class BsCountAware implements ReceiverInterface, MessageCountAwareInterface
{
    public function __construct(private readonly int $count)
    {
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function getMessageCount(): int
    {
        return $this->count;
    }
}
