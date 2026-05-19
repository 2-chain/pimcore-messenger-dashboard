<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreMessengerDashboardBundle\Command\PruneStatsCommand;
use TwoChain\PimcoreMessengerDashboardBundle\Entity\StatsRecord;
use TwoChain\PimcoreMessengerDashboardBundle\Repository\StatsRecordRepository;
use DateTimeImmutable;

final class PruneStatsCommandTest extends TestCase
{
    public function testPruneDelegatesToRepositoryWithCutoffFromConfiguredDefault(): void
    {
        $repo = new StubStatsRecordRepository();
        $repo->pruneResult = 17;
        $tester = new CommandTester(new PruneStatsCommand($repo, defaultRetentionDays: 30));

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertNotNull($repo->lastPruneCutoff);
        $this->assertGreaterThanOrEqual(
            (new DateTimeImmutable('-30 days -1 minute'))->getTimestamp(),
            $repo->lastPruneCutoff->getTimestamp(),
        );
        $this->assertLessThanOrEqual(
            (new DateTimeImmutable('-30 days +1 minute'))->getTimestamp(),
            $repo->lastPruneCutoff->getTimestamp(),
        );
        $this->assertStringContainsString('Deleted 17 row', $tester->getDisplay());
    }

    public function testRetentionDaysOptionOverridesDefault(): void
    {
        $repo = new StubStatsRecordRepository();
        $tester = new CommandTester(new PruneStatsCommand($repo, defaultRetentionDays: 30));

        $tester->execute(['--retention-days' => '7']);

        $expected = (new DateTimeImmutable('-7 days'))->getTimestamp();
        $this->assertNotNull($repo->lastPruneCutoff);
        $this->assertEqualsWithDelta(
            $expected,
            $repo->lastPruneCutoff->getTimestamp(),
            60,
            'cutoff should reflect --retention-days override',
        );
    }

    public function testRetentionDaysBelowOneFailsWithError(): void
    {
        $repo = new StubStatsRecordRepository();
        $tester = new CommandTester(new PruneStatsCommand($repo, defaultRetentionDays: 30));

        $exit = $tester->execute(['--retention-days' => '0']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('retention-days must be >= 1', $tester->getDisplay());
        $this->assertNull($repo->lastPruneCutoff, 'repo should not have been touched');
    }

    public function testDryRunCountsButDoesNotDelete(): void
    {
        $repo = new StubStatsRecordRepository();
        $repo->countOlderThanResult = 42;
        $tester = new CommandTester(new PruneStatsCommand($repo, defaultRetentionDays: 30));

        $exit = $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertNull($repo->lastPruneCutoff, 'prune must NOT be called in dry-run');
        $this->assertStringContainsString('[dry-run] 42 row', $tester->getDisplay());
    }
}

/**
 * Test double that bypasses Doctrine's ServiceEntityRepository plumbing.
 * Overrides only the methods PruneStatsCommand actually calls.
 */
final class StubStatsRecordRepository extends StatsRecordRepository
{
    public ?DateTimeImmutable $lastPruneCutoff = null;
    public int $pruneResult = 0;
    public int $countOlderThanResult = 0;

    public function __construct()
    {
        // Skip parent::__construct (which needs ManagerRegistry + Doctrine
        // metadata); we only need a Doctrine-shaped duck.
    }

    public function record(StatsRecord $rec): void {}

    public function prune(DateTimeImmutable $before, int $batchSize = 10000): int
    {
        $this->lastPruneCutoff = $before;

        return $this->pruneResult;
    }

    public function lastHandledAt(string $transport): ?DateTimeImmutable
    {
        return null;
    }

    public function countSplit(string $transport, DateTimeImmutable $since): array
    {
        return ['handled' => 0, 'failed' => 0];
    }

    public function countOlderThan(DateTimeImmutable $before): int
    {
        return $this->countOlderThanResult;
    }
}
