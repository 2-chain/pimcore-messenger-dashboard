<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use TwoChain\PimcoreMessengerDashboardBundle\DependencyInjection\TwoChainMessengerDashboardExtension;

final class TwoChainMessengerDashboardExtensionTest extends TestCase
{
    public function testLoadExposesConfigAsContainerParameters(): void
    {
        $container = $this->container();

        (new TwoChainMessengerDashboardExtension())->load([], $container);

        $this->assertTrue($container->getParameter('twochain_messenger_dashboard.stats.enabled'));
        $this->assertSame(30, $container->getParameter('twochain_messenger_dashboard.stats.retention_days'));
        $this->assertTrue($container->getParameter('twochain_messenger_dashboard.failed_transport.auto_configure'));
        $this->assertSame(10000, $container->getParameter('twochain_messenger_dashboard.ui.polling_interval_ms'));
    }

    public function testLoadOverridesDefaultsWithUserConfig(): void
    {
        $container = $this->container();

        (new TwoChainMessengerDashboardExtension())->load(
            [['stats' => ['enabled' => false, 'retention_days' => 7], 'ui' => ['polling_interval_ms' => 2000]]],
            $container,
        );

        $this->assertFalse($container->getParameter('twochain_messenger_dashboard.stats.enabled'));
        $this->assertSame(7, $container->getParameter('twochain_messenger_dashboard.stats.retention_days'));
        $this->assertSame(2000, $container->getParameter('twochain_messenger_dashboard.ui.polling_interval_ms'));
    }

    public function testLoadRegistersBundleServices(): void
    {
        $container = $this->container();

        (new TwoChainMessengerDashboardExtension())->load([], $container);

        $this->assertTrue(
            $container->hasDefinition('TwoChain\\PimcoreMessengerDashboardBundle\\Service\\TransportRegistry')
            || $container->hasAlias('TwoChain\\PimcoreMessengerDashboardBundle\\Service\\TransportRegistry'),
            'TransportRegistry should be registered as a service after extension load',
        );
        $this->assertTrue($container->hasDefinition('TwoChain\\PimcoreMessengerDashboardBundle\\Service\\PermissionChecker'));
    }

    public function testAliasIsBundleAlias(): void
    {
        $this->assertSame('twochain_messenger_dashboard', (new TwoChainMessengerDashboardExtension())->getAlias());
    }

    public function testPrependDoesNothingWhenFrameworkExtensionMissing(): void
    {
        $container = new ContainerBuilder();
        // No framework extension registered.

        (new TwoChainMessengerDashboardExtension())->prepend($container);

        $this->assertSame([], $container->getExtensionConfig('framework'));
    }

    public function testPrependAddsFailedTransportConfigByDefault(): void
    {
        $container = $this->container();

        (new TwoChainMessengerDashboardExtension())->prepend($container);

        $frameworkConfigs = $container->getExtensionConfig('framework');
        $this->assertNotEmpty($frameworkConfigs);
        $found = false;
        foreach ($frameworkConfigs as $config) {
            if (($config['messenger']['failure_transport'] ?? null) === 'pimcore_failed') {
                $this->assertSame(
                    'doctrine://default?queue_name=pimcore_failed',
                    $config['messenger']['transports']['pimcore_failed'],
                );
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'expected prepended framework.messenger.failure_transport = pimcore_failed');
    }

    public function testPrependDoesNotOverrideExistingFailureTransport(): void
    {
        $container = $this->container();
        $container->prependExtensionConfig('framework', [
            'messenger' => ['failure_transport' => 'my_custom_failed'],
        ]);

        (new TwoChainMessengerDashboardExtension())->prepend($container);

        // Walk the prepended configs and ensure the bundle did NOT push its
        // own pimcore_failed default in on top of the user's choice.
        $hasPimcoreFailedAuto = false;
        foreach ($container->getExtensionConfig('framework') as $config) {
            if (($config['messenger']['failure_transport'] ?? null) === 'pimcore_failed') {
                $hasPimcoreFailedAuto = true;
            }
        }
        $this->assertFalse(
            $hasPimcoreFailedAuto,
            'bundle must respect user-configured failure_transport and not prepend its own',
        );
    }

    public function testPrependRespectsAutoConfigureFalse(): void
    {
        $container = $this->container();
        $container->prependExtensionConfig('twochain_messenger_dashboard', [
            'failed_transport' => ['auto_configure' => false],
        ]);

        (new TwoChainMessengerDashboardExtension())->prepend($container);

        $hasPimcoreFailedAuto = false;
        foreach ($container->getExtensionConfig('framework') as $config) {
            if (($config['messenger']['failure_transport'] ?? null) === 'pimcore_failed') {
                $hasPimcoreFailedAuto = true;
            }
        }
        $this->assertFalse($hasPimcoreFailedAuto);
    }

    /**
     * Provides a ContainerBuilder with the same set of extensions registered
     * that Symfony's FrameworkBundle would. We only need them by name —
     * the prepend() logic checks `hasExtension('framework')` and so on.
     */
    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FakeExtension('framework'));
        $container->registerExtension(new FakeExtension('twochain_messenger_dashboard'));

        return $container;
    }
}

final class FakeExtension implements ExtensionInterface
{
    public function __construct(private readonly string $alias) {}

    public function load(array $configs, ContainerBuilder $container): void {}

    public function getNamespace(): string
    {
        return '';
    }

    public function getXsdValidationBasePath(): string|false
    {
        return false;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }
}
