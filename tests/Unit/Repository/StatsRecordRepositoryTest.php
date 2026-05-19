<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreMessengerDashboardBundle\Entity\StatsRecord;
use TwoChain\PimcoreMessengerDashboardBundle\Repository\StatsRecordRepository;
use TwoChain\PimcoreMessengerDashboardBundle\Service\StatsRecorderInterface;
use DateTimeImmutable;

/**
 * Repository methods that hit raw SQL are exercised here against PHPUnit
 * doubles of Connection/EntityManager. Tests that need real Doctrine
 * round-trips (mapping metadata, indexes, transactions) belong in
 * tests/Integration/.
 */
final class StatsRecordRepositoryTest extends TestCase
{
    public function testImplementsStatsRecorderInterface(): void
    {
        $this->assertInstanceOf(StatsRecorderInterface::class, new TestableRepository());
    }

    public function testRecordPersistsAndFlushes(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $rec = StatsRecord::handled('pim_core', 'App\\Message\\X', 100, 0);

        $em->expects($this->once())->method('persist')->with($rec);
        $em->expects($this->once())->method('flush');

        $repo = new TestableRepository();
        $repo->setEntityManager($em);
        $repo->record($rec);
    }

    public function testPruneRunsUntilLastBatchIsBelowBatchSize(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->exactly(3))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(10000, 10000, 1234);
        $em = $this->emWithConnection($conn);

        $repo = new TestableRepository();
        $repo->setEntityManager($em);

        $total = $repo->prune(new DateTimeImmutable('2026-01-01 00:00:00'), batchSize: 10000);

        $this->assertSame(21234, $total);
    }

    public function testPruneStopsImmediatelyOnZeroRows(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())->method('executeStatement')->willReturn(0);
        $em = $this->emWithConnection($conn);

        $repo = new TestableRepository();
        $repo->setEntityManager($em);

        $this->assertSame(0, $repo->prune(new DateTimeImmutable()));
    }

    public function testPruneInlinesBatchSizeInSql(): void
    {
        $conn = $this->createStub(Connection::class);
        $captured = '';
        $conn->method('executeStatement')->willReturnCallback(function (string $sql) use (&$captured): int {
            $captured = $sql;

            return 0;
        });
        $em = $this->emWithConnection($conn);

        $repo = new TestableRepository();
        $repo->setEntityManager($em);
        $repo->prune(new DateTimeImmutable(), batchSize: 2500);

        $this->assertStringContainsString('LIMIT 2500', $captured);
    }

    public function testCountOlderThanCastsToInt(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturn('42');  // DBAL often returns strings
        $em = $this->emWithConnection($conn);

        $repo = new TestableRepository();
        $repo->setEntityManager($em);

        $this->assertSame(42, $repo->countOlderThan(new DateTimeImmutable()));
    }

    public function testLastHandledAtReturnsImmutableDate(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturn('2026-05-19 12:00:00');
        $em = $this->emWithConnection($conn);

        $repo = new TestableRepository();
        $repo->setEntityManager($em);

        $date = $repo->lastHandledAt('pim_core');

        $this->assertInstanceOf(DateTimeImmutable::class, $date);
        $this->assertSame('2026-05-19 12:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function testLastHandledAtReturnsNullForUnknownTransport(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturn(false);
        $em = $this->emWithConnection($conn);

        $repo = new TestableRepository();
        $repo->setEntityManager($em);

        $this->assertNull($repo->lastHandledAt('ghost'));
    }

    public function testLastHandledAtReturnsNullWhenMaxIsNull(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturn(null);  // empty table case
        $em = $this->emWithConnection($conn);

        $repo = new TestableRepository();
        $repo->setEntityManager($em);

        $this->assertNull($repo->lastHandledAt('pim_core'));
    }

    public function testCountSplitMapsStatusRows(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([
            ['status' => 'handled', 'n' => '125'],
            ['status' => 'failed', 'n' => '3'],
        ]);
        $em = $this->emWithConnection($conn);

        $repo = new TestableRepository();
        $repo->setEntityManager($em);

        $result = $repo->countSplit('pim_core', new DateTimeImmutable('-1 hour'));

        $this->assertSame(['handled' => 125, 'failed' => 3], $result);
    }

    public function testCountSplitIgnoresUnknownStatuses(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([
            ['status' => 'handled', 'n' => '5'],
            ['status' => 'weird', 'n' => '99'],  // ignored
        ]);
        $em = $this->emWithConnection($conn);

        $repo = new TestableRepository();
        $repo->setEntityManager($em);

        $this->assertSame(['handled' => 5, 'failed' => 0], $repo->countSplit('t', new DateTimeImmutable()));
    }

    public function testCountSplitDefaultsZeroWhenNoRows(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $em = $this->emWithConnection($conn);

        $repo = new TestableRepository();
        $repo->setEntityManager($em);

        $this->assertSame(['handled' => 0, 'failed' => 0], $repo->countSplit('t', new DateTimeImmutable()));
    }

    private function emWithConnection(Connection $conn): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        return $em;
    }
}

/**
 * Subclass that bypasses the ServiceEntityRepository constructor (which
 * needs ManagerRegistry + Doctrine metadata) and lets tests inject the
 * EntityManager directly.
 */
final class TestableRepository extends StatsRecordRepository
{
    private EntityManagerInterface $em;

    public function __construct()
    {
        // Intentionally don't call parent::__construct.
    }

    public function setEntityManager(EntityManagerInterface $em): void
    {
        $this->em = $em;
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }
}
