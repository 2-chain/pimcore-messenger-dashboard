<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\EventSubscriber;

use TwoChain\PimcoreMessengerDashboardBundle\Entity\StatsRecord;
use Psr\Log\LoggerInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\StatsRecorderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Writes one row to messenger_dashboard_stats per handled message and per
 * finally-failed message (skipping intermediate retry failures).
 *
 * Duration is measured by stashing microtime on WorkerMessageReceivedEvent
 * and subtracting on Handled/Failed. The envelope reference is the same
 * object throughout the receive→handle cycle in Symfony's Worker, so
 * spl_object_id is a stable key within a single worker process.
 *
 * The audit subscriber must never break the worker. All recorder calls are
 * try/catch'd and degrade to a warning log on failure.
 */
final class MessengerAuditSubscriber implements EventSubscriberInterface
{
    /** @var array<int, float> envelope-spl-object-id → microtime(true) */
    private array $startTimes = [];

    public function __construct(
        private readonly StatsRecorderInterface $recorder,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'onReceived',
            WorkerMessageHandledEvent::class => 'onHandled',
            WorkerMessageFailedEvent::class => 'onFailed',
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->startTimes[spl_object_id($event->getEnvelope())] = microtime(true);
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $envelope = $event->getEnvelope();
        $key = spl_object_id($envelope);
        $duration = $this->popDuration($key);

        try {
            $this->recorder->record(StatsRecord::handled(
                $event->getReceiverName(),
                $envelope->getMessage()::class,
                $duration,
                $envelope->last(RedeliveryStamp::class)?->getRetryCount(),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to record handled message stats', [
                'transport' => $event->getReceiverName(),
                'exception' => $e,
            ]);
        }
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($event->willRetry()) {
            // Intermediate failure — don't double-count. The final failure
            // (when retries are exhausted) will fire another Failed event
            // with willRetry() === false.
            return;
        }

        $envelope = $event->getEnvelope();
        $key = spl_object_id($envelope);
        $duration = $this->popDuration($key);
        $throwable = $event->getThrowable();

        try {
            $this->recorder->record(StatsRecord::failed(
                $event->getReceiverName(),
                $envelope->getMessage()::class,
                $duration,
                $envelope->last(RedeliveryStamp::class)?->getRetryCount(),
                $throwable::class,
                $throwable->getMessage(),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to record failed message stats', [
                'transport' => $event->getReceiverName(),
                'exception' => $e,
            ]);
        }
    }

    private function popDuration(int $key): ?int
    {
        if (!isset($this->startTimes[$key])) {
            return null;
        }
        $start = $this->startTimes[$key];
        unset($this->startTimes[$key]);

        return (int) round((microtime(true) - $start) * 1000);
    }
}
