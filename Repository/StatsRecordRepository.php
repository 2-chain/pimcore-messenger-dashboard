<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use TwoChain\PimcoreMessengerDashboardBundle\Entity\StatsRecord;
use TwoChain\PimcoreMessengerDashboardBundle\Service\StatsRecorderInterface;

/**
 * @extends ServiceEntityRepository<StatsRecord>
 */
class StatsRecordRepository extends ServiceEntityRepository implements StatsRecorderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StatsRecord::class);
    }

    #[\Override]
    public function record(StatsRecord $rec): void
    {
        $em = $this->getEntityManager();
        $em->persist($rec);
        $em->flush();
    }

    /**
     * Bulk delete rows with handled_at < $before, in batches.
     * Returns total rows deleted.
     */
    public function prune(\DateTimeImmutable $before, int $batchSize = 10000): int
    {
        $conn = $this->getEntityManager()->getConnection();
        $total = 0;
        // MariaDB/MySQL do not accept a placeholder for LIMIT in DELETE, so
        // we inline the batch size (caller-controlled int, no SQL-injection
        // surface).
        $sql = sprintf('DELETE FROM messenger_dashboard_stats WHERE handled_at < ? LIMIT %d', $batchSize);

        do {
            $deleted = (int) $conn->executeStatement($sql, [$before->format('Y-m-d H:i:s')]);
            $total += $deleted;
        } while ($deleted === $batchSize);

        return $total;
    }

    /**
     * Most recent handled_at for the given transport, regardless of status.
     * Returns null if no records exist for the transport.
     */
    public function lastHandledAt(string $transport): ?\DateTimeImmutable
    {
        $row = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT MAX(handled_at) FROM messenger_dashboard_stats WHERE transport = ?',
            [$transport],
        );
        if ($row === false || $row === null) {
            return null;
        }

        return new \DateTimeImmutable((string) $row);
    }

    /**
     * Count handled vs failed for a transport since $since.
     *
     * @return array{handled: int, failed: int}
     */
    public function countSplit(string $transport, \DateTimeImmutable $since): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT status, COUNT(*) AS n FROM messenger_dashboard_stats
             WHERE transport = ? AND handled_at >= ?
             GROUP BY status',
            [$transport, $since->format('Y-m-d H:i:s')],
        );

        $out = ['handled' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $status = (string) $r['status'];
            if ($status === 'handled' || $status === 'failed') {
                $out[$status] = (int) $r['n'];
            }
        }

        return $out;
    }
}
