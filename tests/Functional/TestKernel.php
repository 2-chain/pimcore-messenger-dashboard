<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Functional;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use TwoChain\PimcoreMessengerDashboardBundle\Controller\DashboardController;
use TwoChain\PimcoreMessengerDashboardBundle\Installer;
use TwoChain\PimcoreMessengerDashboardBundle\PimcoreMessengerDashboardBundle;
use TwoChain\PimcoreMessengerDashboardBundle\Service\PermissionChecker;
use Override;

/**
 * Minimal Symfony kernel for functional tests.
 *
 * Uses `MicroKernelTrait` so the kernel itself is auto-registered as a
 * service (needed for `kernel::loadRoutes` and friends). Boots
 * FrameworkBundle + DoctrineBundle + the dashboard bundle — no Pimcore
 * runtime. The Pimcore-specific surface area is handled by:
 *  - {@see TestablePermissionChecker} replacing the production checker so
 *    the test client doesn't need to forge a Pimcore session.
 *  - {@see TestableDashboardController} extending the production controller
 *    to override the protected `currentUser()` method.
 *
 * Each test gets a clean kernel via WebTestCase::createClient.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct()
    {
        // debug=false: Symfony's debug exception handlers aren't cleaned up
        // between WebTestCase runs and surface as "risky test" warnings.
        // We don't need debug output in functional tests anyway.
        parent::__construct('test', false);
    }

    #[Override]
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new PimcoreMessengerDashboardBundle(),
        ];
    }

    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(
            new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    // The autoload glob in the bundle's services.yaml
                    // re-registers the Installer service after the kernel's
                    // closure runs. Installer pulls in Pimcore-runtime
                    // services we don't have in this minimal kernel, and
                    // it's only ever called by `pimcore:bundle:install`
                    // (not at HTTP request time).
                    if ($container->hasDefinition(Installer::class)) {
                        $container->removeDefinition(Installer::class);
                    }

                    // Symfony tags messenger transports with `kernel.reset`
                    // so the ServicesResetter calls $transport->reset()
                    // after each request. InMemoryTransport::reset() wipes
                    // every queued envelope — which makes any test that
                    // spans multiple HTTP requests (e.g. seed via POST,
                    // verify via GET) silently lose its data. Strip the
                    // reset tag in the test kernel so InMemoryTransport
                    // state survives the request boundary the way a real
                    // Doctrine transport would.
                    foreach (array_keys($container->findTaggedServiceIds('kernel.reset')) as $id) {
                        if (str_starts_with($id, 'messenger.transport.')) {
                            $container->getDefinition($id)->clearTag('kernel.reset');
                        }
                    }
                }
            },
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
        );
    }

    #[Override]
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/messenger-dashboard-tests/cache/' . $this->environment;
    }

    #[Override]
    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/messenger-dashboard-tests/log';
    }

    protected function configureContainer(ContainerConfigurator $c, LoaderInterface $loader): void
    {
        $c->extension('framework', [
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'secret' => 'test',
            'test' => true,
            'php_errors' => ['log' => true],
            'messenger' => [
                'transports' => [
                    'test_q' => 'in-memory://',
                    'test_q2' => 'in-memory://',
                    'pim_failed' => 'in-memory://',
                ],
                'failure_transport' => 'pim_failed',
                'buses' => [
                    'messenger.bus.pimcore-core' => [],
                ],
                'default_bus' => 'messenger.bus.pimcore-core',
            ],
        ]);

        $c->extension('doctrine', [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
                'charset' => 'utf8mb4',
            ],
            'orm' => [
                'auto_generate_proxy_classes' => true,
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                'auto_mapping' => false,
                'mappings' => [
                    'PimcoreMessengerDashboardBundle' => [
                        'is_bundle' => true,
                        'type' => 'attribute',
                        'dir' => 'Entity',
                        'prefix' => 'TwoChain\\PimcoreMessengerDashboardBundle\\Entity',
                    ],
                ],
            ],
        ]);

        // Replace the production PermissionChecker with a permissive
        // subclass that tests can poke at. Public so test code can
        // fetch it from the container.
        $c->services()
            ->set(PermissionChecker::class, TestablePermissionChecker::class)
            ->public();

        // Replace the production DashboardController with a subclass that
        // bypasses Pimcore's session-auth static call.
        $c->services()
            ->set(DashboardController::class, TestableDashboardController::class)
            ->public()
            ->autowire()
            ->autoconfigure()
            ->tag('controller.service_arguments')
            ->arg('$failedTransportName', '%twochain_messenger_dashboard.failed_transport.name%');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__ . '/../../Controller/', type: 'attribute');
    }
}
