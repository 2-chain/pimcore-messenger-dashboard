<?php
declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TwoChain\PimcoreMessengerDashboardBundle\DependencyInjection\Compiler\ResolveFailedTransportNamePass;
use TwoChain\PimcoreMessengerDashboardBundle\DependencyInjection\TwoChainMessengerDashboardExtension;
use TwoChain\PimcoreMessengerDashboardBundle\Installer;
use TwoChain\PimcoreMessengerDashboardBundle\PimcoreMessengerDashboardBundle;

final class PimcoreMessengerDashboardBundleTest extends TestCase
{
    public function testJsAndCssPathsArePublishedUnderBundleSlug(): void
    {
        $bundle = new PimcoreMessengerDashboardBundle();

        $this->assertSame([
            '/bundles/pimcoremessengerdashboard/js/dashboard.js',
            '/bundles/pimcoremessengerdashboard/js/startup.js',
        ], $bundle->getJsPaths());

        $this->assertSame([
            '/bundles/pimcoremessengerdashboard/css/dashboard.css',
        ], $bundle->getCssPaths());
    }

    public function testEditmodePathsAreEmpty(): void
    {
        $bundle = new PimcoreMessengerDashboardBundle();

        $this->assertSame([], $bundle->getEditmodeJsPaths());
        $this->assertSame([], $bundle->getEditmodeCssPaths());
    }

    public function testInstallableUserPermissionsMatchInstallerKeys(): void
    {
        $bundle = new PimcoreMessengerDashboardBundle();

        $this->assertSame(Installer::PERMISSION_KEYS, $bundle->getInstallableUserPermissions());
    }

    public function testGetContainerExtensionReturnsBundleExtension(): void
    {
        $bundle = new PimcoreMessengerDashboardBundle();

        $this->assertInstanceOf(TwoChainMessengerDashboardExtension::class, $bundle->getContainerExtension());
    }

    public function testBuildRegistersFailedTransportCompilerPass(): void
    {
        $bundle = new PimcoreMessengerDashboardBundle();
        $container = new ContainerBuilder();

        $bundle->build($container);

        $passes = $container->getCompiler()->getPassConfig()->getBeforeOptimizationPasses();
        $hasOurPass = false;
        foreach ($passes as $pass) {
            if ($pass instanceof ResolveFailedTransportNamePass) {
                $hasOurPass = true;
                break;
            }
        }
        $this->assertTrue($hasOurPass);
    }
}
