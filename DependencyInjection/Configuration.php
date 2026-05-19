<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Override;

final class Configuration implements ConfigurationInterface
{
    #[Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('twochain_messenger_dashboard');

        $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('stats')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('retention_days')
                            ->defaultValue(30)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('failed_transport')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('auto_configure')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('polling_interval_ms')
                            ->defaultValue(10000)
                            ->min(1000)
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
