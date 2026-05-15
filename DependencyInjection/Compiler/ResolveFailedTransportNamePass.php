<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Resolves which transport the framework treats as the default failure
 * transport, and exposes its name as a container parameter so the
 * dashboard controller can target it without hardcoding "pimcore_failed".
 *
 * Symfony's FrameworkExtension sets the alias
 * `messenger.failure_transports.default → messenger.transport.<name>`
 * during its load(). This pass runs after all extensions have loaded,
 * reads the alias target, strips the `messenger.transport.` prefix, and
 * sets `twochain_messenger_dashboard.failed_transport.name`.
 *
 * If neither the bundle's auto-configure nor the host project sets a
 * failure_transport, the parameter is null and the dashboard's failed
 * endpoints respond with a clear 404.
 */
final class ResolveFailedTransportNamePass implements CompilerPassInterface
{
    public const string PARAMETER = 'twochain_messenger_dashboard.failed_transport.name';

    private const string ALIAS = 'messenger.failure_transports.default';
    private const string TRANSPORT_PREFIX = 'messenger.transport.';

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $name = null;
        if ($container->hasAlias(self::ALIAS)) {
            $target = (string) $container->getAlias(self::ALIAS);
            if (str_starts_with($target, self::TRANSPORT_PREFIX)) {
                $name = substr($target, strlen(self::TRANSPORT_PREFIX));
            }
        }
        $container->setParameter(self::PARAMETER, $name);
    }
}
