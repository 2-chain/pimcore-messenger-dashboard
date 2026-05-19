<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Functional\Controller;

use TwoChain\PimcoreMessengerDashboardBundle\Entity\StatsRecord;
use TwoChain\PimcoreMessengerDashboardBundle\Repository\StatsRecordRepository;
use TwoChain\PimcoreMessengerDashboardBundle\Tests\Functional\FunctionalTestCase;
use RuntimeException;

/**
 * Functional tests for GET /admin/messenger-dashboard/stats — seeds rows
 * via the real StatsRecordRepository and reads back through HTTP.
 */
final class StatsTest extends FunctionalTestCase
{
    public function testStatsResponseShapeIncludesEveryTransport(): void
    {
        $this->client->request('GET', '/admin/messenger-dashboard/stats?windows=1h');

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertArrayHasKey('test_q', $body);
        $this->assertArrayHasKey('pim_failed', $body);
        $this->assertArrayHasKey('1h', $body['test_q']);
        $this->assertSame(['handled' => 0, 'failed' => 0], $body['test_q']['1h']);
    }

    public function testStatsAggregatesHandledAndFailedFromTheRepository(): void
    {
        $repo = static::getContainer()->get(StatsRecordRepository::class);
        $repo->record(StatsRecord::handled('test_q', 'App\\Message\\X', 100, 0));
        $repo->record(StatsRecord::handled('test_q', 'App\\Message\\X', 120, 0));
        $repo->record(StatsRecord::failed('test_q', 'App\\Message\\X', 30, 3, RuntimeException::class, 'boom'));

        $this->client->request('GET', '/admin/messenger-dashboard/stats?windows=1h');

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame(['handled' => 2, 'failed' => 1], $body['test_q']['1h']);
    }

    public function testStatsRespectsMultipleWindows(): void
    {
        $repo = static::getContainer()->get(StatsRecordRepository::class);
        $repo->record(StatsRecord::handled('test_q', 'App\\Message\\X', 100, 0));

        $this->client->request('GET', '/admin/messenger-dashboard/stats?windows=1h,12h,24h');

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertArrayHasKey('1h', $body['test_q']);
        $this->assertArrayHasKey('12h', $body['test_q']);
        $this->assertArrayHasKey('24h', $body['test_q']);
    }

    public function testStatsIgnoresMalformedWindowSpec(): void
    {
        $this->client->request('GET', '/admin/messenger-dashboard/stats?windows=1h,garbage,bad-spec');

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertArrayHasKey('1h', $body['test_q']);
        $this->assertArrayNotHasKey('garbage', $body['test_q']);
        $this->assertArrayNotHasKey('bad-spec', $body['test_q']);
    }

    public function testStatsIncludesLastHandledAtFromRepository(): void
    {
        $repo = static::getContainer()->get(StatsRecordRepository::class);
        $repo->record(StatsRecord::handled('test_q', 'App\\Message\\X', 100, 0));

        $this->client->request('GET', '/admin/messenger-dashboard/stats?windows=1h');

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertNotNull($body['test_q']['lastHandledAt']);
        $this->assertNull($body['test_q2']['lastHandledAt'], 'transport with no records should be null');
    }
}
