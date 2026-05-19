<?php
declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use TwoChain\PimcoreMessengerDashboardBundle\Entity\StatsRecord;
use TwoChain\PimcoreMessengerDashboardBundle\EventSubscriber\MessengerAuditSubscriber;
use TwoChain\PimcoreMessengerDashboardBundle\Service\StatsRecorderInterface;

final class MessengerAuditSubscriberTest extends TestCase
{
    public function testHandledMessageProducesHandledRecord(): void
    {
        $recorder = new InMemoryRecorder();
        $subscriber = new MessengerAuditSubscriber($recorder, new NullLogger(), enabled: true);

        $envelope = new Envelope(new FakeMessage());
        $subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'pimcore_core'));
        usleep(1000); // ensure measurable duration
        $subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'pimcore_core'));

        $this->assertCount(1, $recorder->records);
        $rec = $recorder->records[0];
        $this->assertSame(StatsRecord::STATUS_HANDLED, $rec->getStatus());
        $this->assertSame('pimcore_core', $rec->getTransport());
        $this->assertSame(FakeMessage::class, $rec->getMessageClass());
        $this->assertNotNull($rec->getDurationMs());
        $this->assertGreaterThanOrEqual(1, $rec->getDurationMs());
    }

    public function testFailedMessageWithRetryProducesNoRecord(): void
    {
        $recorder = new InMemoryRecorder();
        $subscriber = new MessengerAuditSubscriber($recorder, new NullLogger(), enabled: true);

        $envelope = new Envelope(new FakeMessage());
        $event = new WorkerMessageFailedEvent($envelope, 'pimcore_core', new \RuntimeException('transient'));
        $event->setForRetry();

        $subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'pimcore_core'));
        $subscriber->onFailed($event);

        $this->assertSame([], $recorder->records);
    }

    public function testFinalFailureProducesFailedRecord(): void
    {
        $recorder = new InMemoryRecorder();
        $subscriber = new MessengerAuditSubscriber($recorder, new NullLogger(), enabled: true);

        $envelope = (new Envelope(new FakeMessage()))
            ->with(new RedeliveryStamp(3));
        $exception = new \RuntimeException('permanent failure');
        $event = new WorkerMessageFailedEvent($envelope, 'pimcore_core', $exception);
        // willRetry stays false (default)

        $subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'pimcore_core'));
        $subscriber->onFailed($event);

        $this->assertCount(1, $recorder->records);
        $rec = $recorder->records[0];
        $this->assertSame(StatsRecord::STATUS_FAILED, $rec->getStatus());
        $this->assertSame(\RuntimeException::class, $rec->getFailureClass());
        $this->assertSame('permanent failure', $rec->getFailureMessage());
        $this->assertSame(3, $rec->getRetryCount());
    }

    public function testRecorderExceptionIsSwallowedAndLogged(): void
    {
        $recorder = new ThrowingRecorder();
        $logger = new CollectingLogger();
        $subscriber = new MessengerAuditSubscriber($recorder, $logger, enabled: true);

        $envelope = new Envelope(new FakeMessage());
        $subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'pimcore_core'));
        $subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'pimcore_core')); // must not throw

        $warnings = array_filter($logger->records, fn ($r) => $r['level'] === 'warning');
        $this->assertNotEmpty($warnings, 'subscriber should log a warning when recorder throws');
    }

    public function testDisabledSubscriberSubscribesToNothing(): void
    {
        $events = (new MessengerAuditSubscriber(
            new InMemoryRecorder(),
            new NullLogger(),
            enabled: false,
        ))::getSubscribedEvents();

        // Static call yields all events; we need the instance behavior.
        // The subscriber must short-circuit when disabled — implementation
        // detail tested via instance call below.

        $this->assertNotEmpty($events, 'getSubscribedEvents is static and returns all events.');

        // Verify that when disabled, dispatching has no effect:
        $recorder = new InMemoryRecorder();
        $disabled = new MessengerAuditSubscriber($recorder, new NullLogger(), enabled: false);
        $envelope = new Envelope(new FakeMessage());
        $disabled->onReceived(new WorkerMessageReceivedEvent($envelope, 'pimcore_core'));
        $disabled->onHandled(new WorkerMessageHandledEvent($envelope, 'pimcore_core'));
        $this->assertSame([], $recorder->records);
    }
}

final class FakeMessage
{
    public function __construct(public readonly string $payload = 'hi')
    {
    }
}

final class InMemoryRecorder implements StatsRecorderInterface
{
    /** @var list<StatsRecord> */
    public array $records = [];

    public function record(StatsRecord $rec): void
    {
        $this->records[] = $rec;
    }
}

final class ThrowingRecorder implements StatsRecorderInterface
{
    public function record(StatsRecord $rec): void
    {
        throw new \RuntimeException('db unreachable');
    }
}

final class CollectingLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
