<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Integration\Repository;

use Doctrine\ORM\EntityManagerInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Entity\StatsRecord;
use TwoChain\PimcoreMessengerDashboardBundle\Repository\StatsRecordRepository;
use TwoChain\PimcoreMessengerDashboardBundle\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use RuntimeException;

/**
 * Integration tests for {@see StatsRecordRepository} against a real
 * Doctrine ORM + DBAL connection. Covers persistence + the raw-SQL paths
 * (prune batching, last-handled-at, count-by-status).
 */
final class StatsRecordRepositoryIntegrationTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private StatsRecordRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStatsTable();
        $this->em = $this->createEntityManager();
        $this->repo = new StatsRecordRepository($this->createManagerRegistry($this->em));
    }

    public function testRecordPersistsHandledAndFailedRows(): void
    {
        $handled = StatsRecord::handled('transport_a', 'App\\Message\\X', durationMs: 120, retryCount: 0);
        $failed = StatsRecord::failed(
            'transport_a',
            'App\\Message\\X',
            durationMs: 50,
            retryCount: 3,
            failureClass: RuntimeException::class,
            failureMessage: 'boom',
        );

        $this->repo->record($handled);
        $this->repo->record($failed);
        $this->em->clear();

        $rows = $this->conn->fetchAllAssociative(
            'SELECT status, message_class, duration_ms, retry_count, failure_class, failure_message
             FROM messenger_dashboard_stats ORDER BY id ASC',
        );

        $this->assertCount(2, $rows);
        $this->assertSame(StatsRecord::STATUS_HANDLED, $rows[0]['status']);
        $this->assertSame(120, (int) $rows[0]['duration_ms']);
        $this->assertNull($rows[0]['failure_class']);
        $this->assertSame(StatsRecord::STATUS_FAILED, $rows[1]['status']);
        $this->assertSame(RuntimeException::class, $rows[1]['failure_class']);
        $this->assertSame('boom', $rows[1]['failure_message']);
    }

    public function testCountOlderThanReturnsAnInt(): void
    {
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-10 days'));
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-5 days'));
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-1 hour'));

        $cutoff = new DateTimeImmutable('-3 days');
        $this->assertSame(2, $this->repo->countOlderThan($cutoff));
    }

    public function testPruneDeletesRowsOlderThanCutoff(): void
    {
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-10 days'));
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-5 days'));
        $this->insertRow('transport_a', 'failed', new DateTimeImmutable('-1 hour'));

        $deleted = $this->repo->prune(new DateTimeImmutable('-3 days'));

        $this->assertSame(2, $deleted);
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM messenger_dashboard_stats'));
    }

    public function testPruneRespectsBatchSizeButProcessesAllMatchingRows(): void
    {
        if ($this->isSqlite()) {
            // SQLite's standard build doesn't support `DELETE … LIMIT N`
            // — that's a MariaDB/MySQL feature only. The batching loop in
            // prune() depends on it; tested against MariaDB.
            $this->markTestSkipped('DELETE … LIMIT not available on SQLite');
        }
        for ($i = 0; $i < 25; ++$i) {
            $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-10 days'));
        }
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-1 hour'));

        $deleted = $this->repo->prune(new DateTimeImmutable('-3 days'), batchSize: 10);

        $this->assertSame(25, $deleted, 'all 25 old rows deleted across multiple batches');
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM messenger_dashboard_stats'));
    }

    public function testLastHandledAtReturnsMostRecent(): void
    {
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-3 days'));
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-1 hour'));
        $this->insertRow('transport_b', 'handled', new DateTimeImmutable('-5 minutes'));

        $latest = $this->repo->lastHandledAt('transport_a');

        $this->assertInstanceOf(DateTimeImmutable::class, $latest);
        $this->assertEqualsWithDelta(
            (new DateTimeImmutable('-1 hour'))->getTimestamp(),
            $latest->getTimestamp(),
            5,
        );
    }

    public function testLastHandledAtReturnsNullForUnknownTransport(): void
    {
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable());

        $this->assertNull($this->repo->lastHandledAt('ghost'));
    }

    public function testCountSplitGroupsByStatus(): void
    {
        $since = new DateTimeImmutable('-1 hour');
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-30 minutes'));
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-10 minutes'));
        $this->insertRow('transport_a', 'failed', new DateTimeImmutable('-5 minutes'));

        $this->assertSame(['handled' => 2, 'failed' => 1], $this->repo->countSplit('transport_a', $since));
    }

    public function testCountSplitIgnoresRowsBeforeSince(): void
    {
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-3 days'));
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable('-5 minutes'));

        $this->assertSame(['handled' => 1, 'failed' => 0], $this->repo->countSplit(
            'transport_a',
            new DateTimeImmutable('-1 hour'),
        ));
    }

    public function testCountSplitIgnoresOtherTransports(): void
    {
        $this->insertRow('transport_a', 'handled', new DateTimeImmutable());
        $this->insertRow('transport_b', 'handled', new DateTimeImmutable());

        $this->assertSame(['handled' => 1, 'failed' => 0], $this->repo->countSplit(
            'transport_a',
            new DateTimeImmutable('-1 day'),
        ));
    }

    /**
     * Helper to insert a row directly without round-tripping through the
     * entity (avoids ORM identity map cruft between assertions).
     */
    private function insertRow(string $transport, string $status, DateTimeImmutable $handledAt): void
    {
        $this->conn->insert('messenger_dashboard_stats', [
            'transport' => $transport,
            'message_class' => 'App\\Message\\Stub',
            'status' => $status,
            'handled_at' => $handledAt->format('Y-m-d H:i:s'),
            'duration_ms' => 100,
            'retry_count' => 0,
        ]);
    }
}
