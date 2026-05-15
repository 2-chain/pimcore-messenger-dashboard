<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service;

use TwoChain\PimcoreMessengerDashboardBundle\Entity\StatsRecord;

/**
 * Single-method seam between the audit event subscriber and the storage
 * layer. Exists so MessengerAuditSubscriber can be unit-tested against an
 * in-memory fake without spinning up the full Doctrine EntityManager.
 */
interface StatsRecorderInterface
{
    public function record(StatsRecord $rec): void;
}
