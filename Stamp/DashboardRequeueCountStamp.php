<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Counts how many times an envelope was manually re-queued from the
 * dashboard. Symfony's RedeliveryStamp only tracks automatic retries done
 * by the configured RetryStrategy — it doesn't increment when a human
 * manually re-dispatches a failed message, so the dashboard would
 * otherwise always show retry count = 0 for transports with
 * `max_retries: 0`.
 *
 * Persisted on the envelope across requeue → fail → requeue cycles so the
 * counter is monotonic for the message's lifetime in the failed transport.
 */
final readonly class DashboardRequeueCountStamp implements StampInterface
{
    public function __construct(public int $count)
    {
    }
}
