<?php
declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TwoChain\PimcoreMessengerDashboardBundle\DependencyInjection\Compiler\ResolveFailedTransportNamePass;

final class ResolveFailedTransportNamePassTest extends TestCase
{
    public function testExtractsBareTransportNameFromAlias(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias(
            'messenger.failure_transports.default',
            new Alias('messenger.transport.pim_import_failed'),
        );

        (new ResolveFailedTransportNamePass())->process($container);

        $this->assertSame(
            'pim_import_failed',
            $container->getParameter(ResolveFailedTransportNamePass::PARAMETER),
        );
    }

    public function testParameterIsNullWhenAliasMissing(): void
    {
        $container = new ContainerBuilder();

        (new ResolveFailedTransportNamePass())->process($container);

        $this->assertNull($container->getParameter(ResolveFailedTransportNamePass::PARAMETER));
    }

    public function testParameterIsNullWhenAliasDoesNotPointAtMessengerTransport(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias(
            'messenger.failure_transports.default',
            new Alias('some.other.service'),
        );

        (new ResolveFailedTransportNamePass())->process($container);

        $this->assertNull($container->getParameter(ResolveFailedTransportNamePass::PARAMETER));
    }
}
