<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Entity;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreMessengerDashboardBundle\Entity\StatsRecord;
use DateTimeImmutable;
use RuntimeException;

final class StatsRecordTest extends TestCase
{
    public function testHandledFactorySetsStatusAndCarriesMetadata(): void
    {
        $rec = StatsRecord::handled('pim_import', 'App\\Message\\X', durationMs: 120, retryCount: 0);

        $this->assertSame(StatsRecord::STATUS_HANDLED, $rec->getStatus());
        $this->assertSame('pim_import', $rec->getTransport());
        $this->assertSame('App\\Message\\X', $rec->getMessageClass());
        $this->assertSame(120, $rec->getDurationMs());
        $this->assertSame(0, $rec->getRetryCount());
        $this->assertNull($rec->getFailureClass());
        $this->assertNull($rec->getFailureMessage());
        $this->assertNull($rec->getId(), 'id is set by Doctrine on persist');
        $this->assertEqualsWithDelta(
            (new DateTimeImmutable())->getTimestamp(),
            $rec->getHandledAt()->getTimestamp(),
            2,
        );
    }

    public function testFailedFactoryStoresFailureDetails(): void
    {
        $rec = StatsRecord::failed(
            'pim_import',
            'App\\Message\\X',
            durationMs: 50,
            retryCount: 3,
            failureClass: RuntimeException::class,
            failureMessage: 'boom',
        );

        $this->assertSame(StatsRecord::STATUS_FAILED, $rec->getStatus());
        $this->assertSame(RuntimeException::class, $rec->getFailureClass());
        $this->assertSame('boom', $rec->getFailureMessage());
        $this->assertSame(3, $rec->getRetryCount());
        $this->assertSame(50, $rec->getDurationMs());
    }

    public function testFailedFactoryTruncatesLongFailureMessages(): void
    {
        $long = str_repeat('x', StatsRecord::MAX_FAILURE_MESSAGE_BYTES + 100);

        $rec = StatsRecord::failed('t', 'C', null, null, 'E', $long);

        $this->assertNotNull($rec->getFailureMessage());
        $this->assertSame(
            StatsRecord::MAX_FAILURE_MESSAGE_BYTES,
            strlen($rec->getFailureMessage()),
        );
    }

    public function testFailedFactoryKeepsNullFailureMessage(): void
    {
        $rec = StatsRecord::failed('t', 'C', null, null, null, null);

        $this->assertNull($rec->getFailureMessage());
        $this->assertNull($rec->getFailureClass());
        $this->assertNull($rec->getDurationMs());
        $this->assertNull($rec->getRetryCount());
    }
}
