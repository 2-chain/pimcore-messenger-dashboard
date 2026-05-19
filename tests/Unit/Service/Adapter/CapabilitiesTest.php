<?php
declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\Capabilities;

final class CapabilitiesTest extends TestCase
{
    public function testDefaultsAreAllFalse(): void
    {
        $caps = new Capabilities();

        $this->assertSame([
            'canCount' => false,
            'canList' => false,
            'canInspectIndividual' => false,
            'canDeleteIndividual' => false,
            'canBulkDelete' => false,
            'canPurge' => false,
            'canRequeue' => false,
        ], $caps->toArray());
    }

    public function testCountOnlyEnablesOnlyCount(): void
    {
        $caps = Capabilities::countOnly();

        $this->assertTrue($caps->canCount);
        $this->assertFalse($caps->canList);
        $this->assertFalse($caps->canInspectIndividual);
        $this->assertFalse($caps->canDeleteIndividual);
        $this->assertFalse($caps->canBulkDelete);
        $this->assertFalse($caps->canPurge);
        $this->assertFalse($caps->canRequeue);
    }

    public function testFullEnablesEverything(): void
    {
        $caps = Capabilities::full();

        foreach ($caps->toArray() as $name => $value) {
            $this->assertTrue($value, sprintf('expected %s to be true', $name));
        }
    }

    public function testToArrayMatchesConstructorArgs(): void
    {
        $caps = new Capabilities(
            canCount: true,
            canList: false,
            canInspectIndividual: true,
            canDeleteIndividual: false,
            canBulkDelete: true,
            canPurge: false,
            canRequeue: true,
        );

        $this->assertSame([
            'canCount' => true,
            'canList' => false,
            'canInspectIndividual' => true,
            'canDeleteIndividual' => false,
            'canBulkDelete' => true,
            'canPurge' => false,
            'canRequeue' => true,
        ], $caps->toArray());
    }
}
