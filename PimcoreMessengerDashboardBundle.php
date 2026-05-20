<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle;

use TwoChain\PimcoreMessengerDashboardBundle\DependencyInjection\Compiler\ResolveFailedTransportNamePass;
use TwoChain\PimcoreMessengerDashboardBundle\DependencyInjection\TwoChainMessengerDashboardExtension;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\Installer\InstallerInterface;
use Pimcore\Extension\Bundle\PimcoreBundleAdminClassicInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Override;

final class PimcoreMessengerDashboardBundle extends AbstractPimcoreBundle implements PimcoreBundleAdminClassicInterface
{
    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ResolveFailedTransportNamePass());
    }

    #[Override]
    public function getJsPaths(): array
    {
        return [
            '/bundles/pimcoremessengerdashboard/js/dashboard.js',
            '/bundles/pimcoremessengerdashboard/js/startup.js',
        ];
    }

    #[Override]
    public function getCssPaths(): array
    {
        return [
            '/bundles/pimcoremessengerdashboard/css/dashboard.css',
        ];
    }

    #[Override]
    public function getEditmodeJsPaths(): array
    {
        return [];
    }

    #[Override]
    public function getEditmodeCssPaths(): array
    {
        return [];
    }

    /** @return list<string> */
    public function getInstallableUserPermissions(): array
    {
        return Installer::PERMISSION_KEYS;
    }

    #[Override]
    public function getInstaller(): ?InstallerInterface
    {
        if ($this->container === null) {
            return null;
        }

        $installer = $this->container->get(Installer::class);
        \assert($installer instanceof InstallerInterface);

        return $installer;
    }

    #[Override]
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new TwoChainMessengerDashboardExtension();
    }
}
