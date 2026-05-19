<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use TwoChain\PimcoreMessengerDashboardBundle\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $processed = (new Processor())->processConfiguration(
            new Configuration(),
            [[]]
        );

        $this->assertSame([
            'stats' => [
                'enabled' => true,
                'retention_days' => 30,
            ],
            'failed_transport' => [
                'auto_configure' => true,
            ],
            'ui' => [
                'polling_interval_ms' => 10000,
            ],
        ], $processed);
    }

    public function testOverrides(): void
    {
        $processed = (new Processor())->processConfiguration(
            new Configuration(),
            [[
                'stats' => ['enabled' => false, 'retention_days' => 7],
                'ui' => ['polling_interval_ms' => 30000],
            ]]
        );

        $this->assertFalse($processed['stats']['enabled']);
        $this->assertSame(7, $processed['stats']['retention_days']);
        $this->assertSame(30000, $processed['ui']['polling_interval_ms']);
        $this->assertTrue($processed['failed_transport']['auto_configure']);
    }

    public function testRejectsNegativeRetentionDays(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        (new Processor())->processConfiguration(
            new Configuration(),
            [['stats' => ['retention_days' => -1]]]
        );
    }
}
