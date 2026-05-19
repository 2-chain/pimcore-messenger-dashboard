<?php
declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\TransportAdapterInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\TransportAdapterFactory;
use TwoChain\PimcoreMessengerDashboardBundle\Service\TransportRegistry;

final class TransportRegistryTest extends TestCase
{
    public function testNamesFiltersOutDottedServiceIdsAndSorts(): void
    {
        $locator = new ServiceLocator([
            'pimcore_core' => fn () => new RegistryStubReceiver(),
            'messenger.transport.pimcore_core' => fn () => new RegistryStubReceiver(),
            'aaa_first' => fn () => new RegistryStubReceiver(),
            'messenger.bus.default' => fn () => new RegistryStubReceiver(),
        ]);
        $factory = new TransportAdapterFactory($locator);

        $registry = new TransportRegistry($locator, $factory);

        $this->assertSame(['aaa_first', 'pimcore_core'], $registry->names());
    }

    public function testNamesAreCachedAcrossCalls(): void
    {
        $locator = new ServiceLocator([
            'a' => fn () => new RegistryStubReceiver(),
        ]);
        $registry = new TransportRegistry($locator, new TransportAdapterFactory($locator));

        $first = $registry->names();
        $second = $registry->names();

        $this->assertSame($first, $second);
    }

    public function testAdapterDelegatesToFactory(): void
    {
        $locator = new ServiceLocator([
            'queue_a' => fn () => new RegistryStubReceiver(),
        ]);
        $factory = new TransportAdapterFactory($locator);
        $registry = new TransportRegistry($locator, $factory);

        $adapter = $registry->adapter('queue_a');

        $this->assertInstanceOf(TransportAdapterInterface::class, $adapter);
        $this->assertSame('queue_a', $adapter->name());
    }

    public function testAdaptersIteratesAllNames(): void
    {
        $locator = new ServiceLocator([
            'queue_a' => fn () => new RegistryStubReceiver(),
            'queue_b' => fn () => new RegistryStubReceiver(),
        ]);
        $registry = new TransportRegistry($locator, new TransportAdapterFactory($locator));

        $names = [];
        foreach ($registry->adapters() as $adapter) {
            $names[] = $adapter->name();
        }

        $this->assertSame(['queue_a', 'queue_b'], $names);
    }
}

final class RegistryStubReceiver implements ListableReceiverInterface
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

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }
}
