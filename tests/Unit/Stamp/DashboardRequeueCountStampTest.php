<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Stamp;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\StampInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Stamp\DashboardRequeueCountStamp;

final class DashboardRequeueCountStampTest extends TestCase
{
    public function testStoresCount(): void
    {
        $stamp = new DashboardRequeueCountStamp(5);

        $this->assertSame(5, $stamp->count);
    }

    public function testImplementsStampInterface(): void
    {
        $this->assertInstanceOf(StampInterface::class, new DashboardRequeueCountStamp(0));
    }
}
