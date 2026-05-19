<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\DefaultTransportAdapter;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\InMemoryTransportAdapter;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\ListableReceiverAdapter;
use TwoChain\PimcoreMessengerDashboardBundle\Service\TransportAdapterFactory;

/**
 * The Doctrine/Redis/Amqp/Beanstalkd/Sqs branches require bridge-specific
 * receiver instances that are awkward to instantiate in pure unit tests.
 * Those are covered in integration tests; here we cover the framework-only
 * branches plus the generic fallbacks.
 */
final class TransportAdapterFactoryTest extends TestCase
{
    public function testInMemoryTransportMapsToDedicatedAdapter(): void
    {
        $locator = new ServiceLocator([
            'in_mem' => fn() => new InMemoryTransport(),
        ]);
        $factory = new TransportAdapterFactory($locator);

        $adapter = $factory->for('in_mem');

        $this->assertInstanceOf(InMemoryTransportAdapter::class, $adapter);
        $this->assertSame('in_memory', $adapter->type());
    }

    public function testSyncTransportMapsToDefaultAdapterWithSyncType(): void
    {
        $locator = new ServiceLocator([
            'sync_t' => fn() => new SyncTransport(new class implements \Symfony\Component\Messenger\MessageBusInterface {
                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    return new Envelope($message);
                }
            }),
        ]);
        $factory = new TransportAdapterFactory($locator);

        $adapter = $factory->for('sync_t');

        $this->assertInstanceOf(DefaultTransportAdapter::class, $adapter);
        $this->assertSame('sync', $adapter->type());
        $this->assertFalse($adapter->capabilities()->canList);
    }

    public function testListableReceiverFallsBackToGenericListableAdapter(): void
    {
        $locator = new ServiceLocator([
            'generic' => fn() => new GenericListableReceiver(),
        ]);
        $factory = new TransportAdapterFactory($locator);

        $adapter = $factory->for('generic');

        $this->assertInstanceOf(ListableReceiverAdapter::class, $adapter);
        $this->assertSame('unknown', $adapter->type());
    }

    public function testUnknownReceiverFallsBackToDefaultAdapter(): void
    {
        $locator = new ServiceLocator([
            'mystery' => fn() => new GenericPlainReceiver(),
        ]);
        $factory = new TransportAdapterFactory($locator);

        $adapter = $factory->for('mystery');

        $this->assertInstanceOf(DefaultTransportAdapter::class, $adapter);
        $this->assertSame('unknown', $adapter->type());
    }

    public function testAdaptersAreCachedPerName(): void
    {
        $locator = new ServiceLocator([
            't' => fn() => new GenericListableReceiver(),
        ]);
        $factory = new TransportAdapterFactory($locator);

        $a = $factory->for('t');
        $b = $factory->for('t');

        $this->assertSame($a, $b);
    }
}

final class GenericListableReceiver implements ListableReceiverInterface
{
    public function all(?int $limit = null): iterable
    {
        return [];
    }

    public function find(mixed $id): ?Envelope
    {
        return null;
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}
}

final class GenericPlainReceiver implements ReceiverInterface
{
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}
}
