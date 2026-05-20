<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Override;

final class TwoChainMessengerDashboardExtension extends Extension implements PrependExtensionInterface
{
    #[Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('twochain_messenger_dashboard.stats.enabled', $config['stats']['enabled']);
        $container->setParameter('twochain_messenger_dashboard.stats.retention_days', $config['stats']['retention_days']);
        $container->setParameter('twochain_messenger_dashboard.failed_transport.auto_configure', $config['failed_transport']['auto_configure']);
        $container->setParameter('twochain_messenger_dashboard.ui.polling_interval_ms', $config['ui']['polling_interval_ms']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    /**
     * Registers the bundle's Doctrine migrations path with the host project's
     * doctrine_migrations config. Mirrors the pattern used by pimcore/data-hub.
     */
    #[Override]
    public function prepend(ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        if ($container->hasExtension('doctrine_migrations')) {
            $loader->load('doctrine_migrations.yml');
        }

        if ($container->hasExtension('doctrine')) {
            // Pimcore 12 ships DBAL but doesn't preconfigure the ORM
            // EntityManager for application bundles. We enable it here for our
            // own entities. The mapping points at src/MessengerDashboardBundle/Entity
            // via is_bundle: true.
            $loader->load('pimcore/doctrine.yaml');
        }

        $this->prependFailedTransport($container);
    }

    /**
     * Auto-configure a default failed transport (pimcore_failed → Doctrine
     * queue) unless either:
     *  - the bundle's `failed_transport.auto_configure` is set to false, or
     *  - the host project already sets `framework.messenger.failure_transport`.
     *
     * Symfony merges prepended config UNDER user-supplied config, so users
     * who set their own failure_transport in their own framework.yaml win.
     * The check below is just a polite no-op when the user has already
     * configured one — keeps the diagnostics cleaner.
     */
    private function prependFailedTransport(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('framework')) {
            return;
        }

        // Read our own config from prepended sources (load() hasn't run yet).
        $ourConfigs = $container->getExtensionConfig($this->getAlias());
        $processed = $this->processConfiguration(new Configuration(), $ourConfigs);
        if (!$processed['failed_transport']['auto_configure']) {
            return;
        }

        // Skip if user already set framework.messenger.failure_transport.
        foreach ($container->getExtensionConfig('framework') as $frameworkConfig) {
            if (!\is_array($frameworkConfig)) {
                continue;
            }
            if (isset($frameworkConfig['messenger']) && \is_array($frameworkConfig['messenger'])
                && isset($frameworkConfig['messenger']['failure_transport'])) {
                return;
            }
        }

        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'failure_transport' => 'pimcore_failed',
                'transports' => [
                    'pimcore_failed' => 'doctrine://default?queue_name=pimcore_failed',
                ],
            ],
        ]);
    }

    #[Override]
    public function getAlias(): string
    {
        return 'twochain_messenger_dashboard';
    }
}
