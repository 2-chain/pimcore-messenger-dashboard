<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Integration\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use TwoChain\PimcoreMessengerDashboardBundle\Entity\StatsRecord;
use TwoChain\PimcoreMessengerDashboardBundle\EventSubscriber\MessengerAuditSubscriber;
use TwoChain\PimcoreMessengerDashboardBundle\Repository\StatsRecordRepository;
use TwoChain\PimcoreMessengerDashboardBundle\Tests\Integration\IntegrationTestCase;
use LogicException;
use RuntimeException;

/**
 * Integration tests for {@see MessengerAuditSubscriber} with a real
 * repository persisting through a real ORM EntityManager to a real DB.
 *
 * Exercises the full audit pipeline: worker fires events → subscriber
 * stashes/pops the start time → recorder writes a row → assertions read
 * back from the table.
 */
final class MessengerAuditSubscriberIntegrationTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private MessengerAuditSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStatsTable();
        $this->em = $this->createEntityManager();
        $repo = new StatsRecordRepository($this->createManagerRegistry($this->em));
        $this->subscriber = new MessengerAuditSubscriber($repo, new NullLogger(), enabled: true);
    }

    public function testHandledMessageProducesPersistedRow(): void
    {
        $envelope = new Envelope(new AuditedMessage('payload'));

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'queue_a'));
        usleep(2_000); // make duration measurable on any platform
        $this->subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'queue_a'));
        $this->em->clear();

        $rows = $this->conn->fetchAllAssociative('SELECT * FROM messenger_dashboard_stats');
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('queue_a', $row['transport']);
        $this->assertSame(AuditedMessage::class, $row['message_class']);
        $this->assertSame(StatsRecord::STATUS_HANDLED, $row['status']);
        $this->assertGreaterThanOrEqual(1, (int) $row['duration_ms']);
        $this->assertNull($row['failure_class']);
    }

    public function testFinalFailureProducesFailedRowWithErrorDetails(): void
    {
        $envelope = (new Envelope(new AuditedMessage('payload')))
            ->with(new RedeliveryStamp(3));
        $event = new WorkerMessageFailedEvent($envelope, 'queue_a', new RuntimeException('upstream gone'));
        // willRetry() is false by default → this counts as a terminal failure.

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'queue_a'));
        $this->subscriber->onFailed($event);
        $this->em->clear();

        $rows = $this->conn->fetchAllAssociative('SELECT * FROM messenger_dashboard_stats');
        $this->assertCount(1, $rows);
        $this->assertSame(StatsRecord::STATUS_FAILED, $rows[0]['status']);
        $this->assertSame(RuntimeException::class, $rows[0]['failure_class']);
        $this->assertSame('upstream gone', $rows[0]['failure_message']);
        $this->assertSame(3, (int) $rows[0]['retry_count']);
    }

    public function testIntermediateRetryFailureDoesNotRecordARow(): void
    {
        $envelope = new Envelope(new AuditedMessage('payload'));
        $event = new WorkerMessageFailedEvent($envelope, 'queue_a', new RuntimeException('transient'));
        $event->setForRetry();

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'queue_a'));
        $this->subscriber->onFailed($event);
        $this->em->clear();

        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM messenger_dashboard_stats'));
    }

    public function testDisabledSubscriberSkipsWriting(): void
    {
        $repo = new StatsRecordRepository($this->createManagerRegistry($this->em));
        $disabled = new MessengerAuditSubscriber($repo, new NullLogger(), enabled: false);
        $envelope = new Envelope(new AuditedMessage('payload'));

        $disabled->onReceived(new WorkerMessageReceivedEvent($envelope, 'queue_a'));
        $disabled->onHandled(new WorkerMessageHandledEvent($envelope, 'queue_a'));
        $this->em->clear();

        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM messenger_dashboard_stats'));
    }

    public function testMultipleEnvelopesAccumulateRows(): void
    {
        $e1 = new Envelope(new AuditedMessage('first'));
        $e2 = new Envelope(new AuditedMessage('second'));

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($e1, 'queue_a'));
        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($e2, 'queue_b'));
        $this->subscriber->onHandled(new WorkerMessageHandledEvent($e1, 'queue_a'));
        $this->subscriber->onFailed(new WorkerMessageFailedEvent($e2, 'queue_b', new LogicException('bad input')));
        $this->em->clear();

        $rows = $this->conn->fetchAllAssociative(
            'SELECT transport, status, failure_class FROM messenger_dashboard_stats ORDER BY id ASC',
        );
        $this->assertSame([
            ['transport' => 'queue_a', 'status' => 'handled', 'failure_class' => null],
            ['transport' => 'queue_b', 'status' => 'failed', 'failure_class' => LogicException::class],
        ], $rows);
    }

    public function testRetryCountIsTrackedFromRedeliveryStamp(): void
    {
        $envelope = (new Envelope(new AuditedMessage('payload')))
            ->with(new RedeliveryStamp(5));

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'queue_a'));
        $this->subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'queue_a'));
        $this->em->clear();

        $this->assertSame(5, (int) $this->conn->fetchOne('SELECT retry_count FROM messenger_dashboard_stats'));
    }
}

final class AuditedMessage
{
    public function __construct(public readonly string $payload) {}
}
