<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service;

use TwoChain\PimcoreMessengerDashboardBundle\Stamp\DashboardRequeueCountStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Throwable;

/**
 * High-level bulk and requeue operations layered on top of the transport
 * adapters. Controllers call into this; never call adapters directly for
 * mutations.
 *
 * Per-id loops collect failures rather than aborting on the first error,
 * which matches the spec section 8.4 — partial failure is the norm for
 * bulk operations.
 */
class MessageOperations
{
    public function __construct(
        private readonly TransportRegistry $registry,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * @param list<string> $ids
     * @return array{processed: int, failed: list<array{id: string, reason: string}>}
     */
    public function deleteMany(string $transportName, array $ids): array
    {
        $adapter = $this->registry->adapter($transportName);
        if (!$adapter->capabilities()->canDeleteIndividual) {
            return ['processed' => 0, 'failed' => array_map(
                fn(string $id): array => ['id' => $id, 'reason' => 'transport_does_not_support_delete'],
                $ids,
            )];
        }

        $processed = 0;
        $failed = [];
        foreach ($ids as $id) {
            try {
                if ($adapter->deleteOne($id)) {
                    ++$processed;
                } else {
                    $failed[] = ['id' => $id, 'reason' => 'message_not_found'];
                }
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    /** @return array{processed: int, failed: list<array{id: string, reason: string}>} */
    public function purge(string $transportName): array
    {
        $adapter = $this->registry->adapter($transportName);
        if (!$adapter->capabilities()->canPurge) {
            return ['processed' => 0, 'failed' => [['id' => '*', 'reason' => 'transport_does_not_support_purge']]];
        }
        try {
            $count = $adapter->purge();

            return ['processed' => $count, 'failed' => []];
        } catch (Throwable $e) {
            return ['processed' => 0, 'failed' => [['id' => '*', 'reason' => $e->getMessage()]]];
        }
    }

    /**
     * @param list<string> $ids
     * @return array{processed: int, failed: list<array{id: string, reason: string}>}
     */
    public function requeueMany(string $failedTransportName, array $ids): array
    {
        $adapter = $this->registry->adapter($failedTransportName);
        if (!$adapter->capabilities()->canList) {
            return [
                'processed' => 0,
                'failed' => array_map(
                    static fn(string $id): array => ['id' => $id, 'reason' => 'failed_transport_not_listable'],
                    $ids,
                ),
            ];
        }

        $processed = 0;
        $failed = [];
        foreach ($ids as $id) {
            try {
                $envelope = $adapter->findEnvelope($id);
                if (!$envelope instanceof \Symfony\Component\Messenger\Envelope) {
                    $failed[] = ['id' => $id, 'reason' => 'message_not_found'];
                    continue;
                }
                // Dispatch a copy back to the original transport BEFORE
                // removing the failed entry — if dispatch throws, the
                // user can retry without losing the message.
                $this->dispatchToOriginalTransport($envelope);
                if (!$adapter->deleteOne($id)) {
                    $failed[] = ['id' => $id, 'reason' => 'requeued_but_not_removed_from_failed'];
                    continue;
                }
                ++$processed;
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    /** @return array{processed: int, failed: list<array{id: string, reason: string}>} */
    public function requeueAll(string $failedTransportName): array
    {
        $adapter = $this->registry->adapter($failedTransportName);
        if (!$adapter->capabilities()->canList) {
            return ['processed' => 0, 'failed' => [['id' => '*', 'reason' => 'failed_transport_not_listable']]];
        }

        $ids = [];
        foreach ($adapter->list(0, 10000) as $desc) {
            $ids[] = $desc->id;
        }

        return $this->requeueMany($failedTransportName, $ids);
    }

    private function dispatchToOriginalTransport(Envelope $envelope): void
    {
        $sentToFailure = $envelope->last(SentToFailureTransportStamp::class);
        $original = $sentToFailure?->getOriginalReceiverName();

        // Strip the failure stamp + force re-routing back to the original
        // transport. Without this, Messenger would re-route via configured
        // routing rules (potentially back to the same failed transport
        // again, depending on bundle config).
        $envelope = $envelope->withoutAll(SentToFailureTransportStamp::class);

        // Bump the dashboard's manual-requeue counter so the user sees an
        // attempt count that actually grows. RedeliveryStamp only counts
        // automatic retries from the configured RetryStrategy — manual
        // requeues need their own tracker. Replace any existing stamp
        // with an incremented one.
        $current = $envelope->last(DashboardRequeueCountStamp::class)?->count ?? 0;
        $envelope = $envelope
            ->withoutAll(DashboardRequeueCountStamp::class)
            ->with(new DashboardRequeueCountStamp($current + 1));

        if ($original !== null) {
            $envelope = $envelope->with(new TransportNamesStamp([$original]));
        }
        // BusNameStamp ensures we use the right bus when multiple buses
        // are configured. Default Pimcore setup has one bus
        // ('messenger.bus.pimcore-core'); we leave dispatch up to the
        // default bus if no BusNameStamp is present.
        if (!$envelope->last(BusNameStamp::class) instanceof \Symfony\Component\Messenger\Stamp\StampInterface) {
            // Default Pimcore bus name (matches section 3.2 of the spec).
            $envelope = $envelope->with(new BusNameStamp('messenger.bus.pimcore-core'));
        }

        $this->bus->dispatch($envelope);
    }
}
