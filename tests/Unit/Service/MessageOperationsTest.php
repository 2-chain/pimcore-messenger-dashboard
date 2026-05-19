<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\Capabilities;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\MessageDescriptor;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\TransportAdapterInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\MessageOperations;
use TwoChain\PimcoreMessengerDashboardBundle\Service\TransportAdapterFactory;
use TwoChain\PimcoreMessengerDashboardBundle\Service\TransportRegistry;
use TwoChain\PimcoreMessengerDashboardBundle\Stamp\DashboardRequeueCountStamp;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use stdClass;

final class MessageOperationsTest extends TestCase
{
    public function testDeleteManyReportsProcessedAndFailedIds(): void
    {
        $adapter = new StubAdapter('q', Capabilities::full());
        $adapter->deleteResults = ['a' => true, 'b' => false]; // 'b' missing
        $ops = $this->ops($adapter);

        $result = $ops->deleteMany('q', ['a', 'b', 'c']);

        $this->assertSame(1, $result['processed']);
        $this->assertSame([
            ['id' => 'b', 'reason' => 'message_not_found'],
            ['id' => 'c', 'reason' => 'message_not_found'],
        ], $result['failed']);
    }

    public function testDeleteManyShortCircuitsIfCapabilityIsMissing(): void
    {
        $adapter = new StubAdapter('q', new Capabilities(canCount: true)); // canDeleteIndividual = false
        $ops = $this->ops($adapter);

        $result = $ops->deleteMany('q', ['a', 'b']);

        $this->assertSame(0, $result['processed']);
        $this->assertCount(2, $result['failed']);
        $this->assertSame('transport_does_not_support_delete', $result['failed'][0]['reason']);
    }

    public function testDeleteManyCapturesPerIdExceptions(): void
    {
        $adapter = new StubAdapter('q', Capabilities::full());
        $adapter->deleteCallback = function (string $id): bool {
            if ($id === 'boom') {
                throw new RuntimeException('db connection lost');
            }

            return true;
        };
        $ops = $this->ops($adapter);

        $result = $ops->deleteMany('q', ['ok', 'boom', 'ok2']);

        $this->assertSame(2, $result['processed']);
        $this->assertSame([['id' => 'boom', 'reason' => 'db connection lost']], $result['failed']);
    }

    public function testPurgeReportsRowCount(): void
    {
        $adapter = new StubAdapter('q', Capabilities::full());
        $adapter->purgeResult = 42;
        $ops = $this->ops($adapter);

        $result = $ops->purge('q');

        $this->assertSame(42, $result['processed']);
        $this->assertSame([], $result['failed']);
    }

    public function testPurgeReportsErrorWhenAdapterThrows(): void
    {
        $adapter = new StubAdapter('q', Capabilities::full());
        $adapter->purgeException = new RuntimeException('connection down');
        $ops = $this->ops($adapter);

        $result = $ops->purge('q');

        $this->assertSame(0, $result['processed']);
        $this->assertSame([['id' => '*', 'reason' => 'connection down']], $result['failed']);
    }

    public function testPurgeShortCircuitsWhenCapabilityIsMissing(): void
    {
        $adapter = new StubAdapter('q', new Capabilities());
        $ops = $this->ops($adapter);

        $result = $ops->purge('q');

        $this->assertSame(0, $result['processed']);
        $this->assertSame([['id' => '*', 'reason' => 'transport_does_not_support_purge']], $result['failed']);
    }

    public function testRequeueDispatchesEnvelopeToOriginalTransport(): void
    {
        $bus = new RecordingBus();
        $envelope = (new Envelope(new stdClass()))
            ->with(new SentToFailureTransportStamp('pim_import'));

        $failed = new StubAdapter('pim_failed', Capabilities::full());
        $failed->findEnvelopeResults = ['msg-1' => $envelope];
        $failed->deleteResults = ['msg-1' => true];

        $ops = $this->ops($failed, $bus);

        $result = $ops->requeueMany('pim_failed', ['msg-1']);

        $this->assertSame(1, $result['processed']);
        $this->assertCount(1, $bus->dispatched);
        $dispatched = $bus->dispatched[0];
        $names = $dispatched->last(TransportNamesStamp::class)?->getTransportNames();
        $this->assertSame(['pim_import'], $names);
        $this->assertNull(
            $dispatched->last(SentToFailureTransportStamp::class),
            'failure stamp must be stripped before re-dispatch',
        );
    }

    public function testRequeueIncrementsDashboardCountStamp(): void
    {
        $bus = new RecordingBus();
        $envelope = (new Envelope(new stdClass()))
            ->with(new SentToFailureTransportStamp('pim_import'))
            ->with(new DashboardRequeueCountStamp(2));

        $failed = new StubAdapter('pim_failed', Capabilities::full());
        $failed->findEnvelopeResults = ['msg-1' => $envelope];
        $failed->deleteResults = ['msg-1' => true];

        $ops = $this->ops($failed, $bus);
        $ops->requeueMany('pim_failed', ['msg-1']);

        $count = $bus->dispatched[0]->last(DashboardRequeueCountStamp::class);
        $this->assertNotNull($count);
        $this->assertSame(3, $count->count);
    }

    public function testRequeueAddsDefaultBusNameWhenMissing(): void
    {
        $bus = new RecordingBus();
        $envelope = (new Envelope(new stdClass()))
            ->with(new SentToFailureTransportStamp('pim_import'));

        $failed = new StubAdapter('pim_failed', Capabilities::full());
        $failed->findEnvelopeResults = ['msg-1' => $envelope];
        $failed->deleteResults = ['msg-1' => true];

        $ops = $this->ops($failed, $bus);
        $ops->requeueMany('pim_failed', ['msg-1']);

        $stamp = $bus->dispatched[0]->last(BusNameStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame('messenger.bus.pimcore-core', $stamp->getBusName());
    }

    public function testRequeuePreservesExistingBusNameStamp(): void
    {
        $bus = new RecordingBus();
        $envelope = (new Envelope(new stdClass()))
            ->with(new SentToFailureTransportStamp('pim_import'))
            ->with(new BusNameStamp('custom.bus'));

        $failed = new StubAdapter('pim_failed', Capabilities::full());
        $failed->findEnvelopeResults = ['msg-1' => $envelope];
        $failed->deleteResults = ['msg-1' => true];

        $ops = $this->ops($failed, $bus);
        $ops->requeueMany('pim_failed', ['msg-1']);

        $stamp = $bus->dispatched[0]->last(BusNameStamp::class);
        $this->assertSame('custom.bus', $stamp?->getBusName());
    }

    public function testRequeueRecordsFailureWhenEnvelopeNotFound(): void
    {
        $bus = new RecordingBus();
        $failed = new StubAdapter('pim_failed', Capabilities::full());
        // no findEnvelopeResults configured → returns null
        $ops = $this->ops($failed, $bus);

        $result = $ops->requeueMany('pim_failed', ['ghost']);

        $this->assertSame(0, $result['processed']);
        $this->assertSame([['id' => 'ghost', 'reason' => 'message_not_found']], $result['failed']);
        $this->assertSame([], $bus->dispatched);
    }

    public function testRequeueShortCircuitsWhenTransportNotListable(): void
    {
        $bus = new RecordingBus();
        $failed = new StubAdapter('pim_failed', new Capabilities()); // canList = false
        $ops = $this->ops($failed, $bus);

        $result = $ops->requeueMany('pim_failed', ['a', 'b']);

        $this->assertSame(0, $result['processed']);
        $this->assertCount(2, $result['failed']);
        $this->assertSame('failed_transport_not_listable', $result['failed'][0]['reason']);
        $this->assertSame([], $bus->dispatched);
    }

    public function testRequeueAllCollectsIdsFromListAndDelegates(): void
    {
        $bus = new RecordingBus();
        $failed = new StubAdapter('pim_failed', Capabilities::full());
        $env1 = (new Envelope(new stdClass()))->with(new SentToFailureTransportStamp('original'));
        $env2 = (new Envelope(new stdClass()))->with(new SentToFailureTransportStamp('original'));
        $failed->listResults = [
            new MessageDescriptor(id: 'id-1', messageClass: 'A', createdAt: new DateTimeImmutable()),
            new MessageDescriptor(id: 'id-2', messageClass: 'B', createdAt: new DateTimeImmutable()),
        ];
        $failed->findEnvelopeResults = ['id-1' => $env1, 'id-2' => $env2];
        $failed->deleteResults = ['id-1' => true, 'id-2' => true];

        $ops = $this->ops($failed, $bus);
        $result = $ops->requeueAll('pim_failed');

        $this->assertSame(2, $result['processed']);
        $this->assertCount(2, $bus->dispatched);
    }

    public function testRequeueAllShortCircuitsForNonListable(): void
    {
        $failed = new StubAdapter('pim_failed', new Capabilities());

        $result = $this->ops($failed)->requeueAll('pim_failed');

        $this->assertSame(0, $result['processed']);
        $this->assertSame([['id' => '*', 'reason' => 'failed_transport_not_listable']], $result['failed']);
    }

    private function ops(StubAdapter $adapter, ?RecordingBus $bus = null): MessageOperations
    {
        $registry = new StaticRegistry($adapter);

        return new MessageOperations($registry, $bus ?? new RecordingBus());
    }
}

final class StubAdapter implements TransportAdapterInterface
{
    /** @var array<string, bool> */
    public array $deleteResults = [];
    /** @var ?callable */
    public $deleteCallback = null;
    public int $purgeResult = 0;
    public ?Throwable $purgeException = null;
    /** @var array<string, Envelope> */
    public array $findEnvelopeResults = [];
    /** @var list<MessageDescriptor> */
    public array $listResults = [];

    public function __construct(
        private readonly string $name,
        private readonly Capabilities $capabilities,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return 'stub';
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function count(): int
    {
        return 0;
    }

    public function countListable(?string $query = null): int
    {
        return count($this->listResults);
    }

    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        return array_slice($this->listResults, $offset, $limit);
    }

    public function find(string $id): ?MessageDescriptor
    {
        foreach ($this->listResults as $d) {
            if ($d->id === $id) {
                return $d;
            }
        }

        return null;
    }

    public function findEnvelope(string $id): ?Envelope
    {
        return $this->findEnvelopeResults[$id] ?? null;
    }

    public function deleteOne(string $id): bool
    {
        if ($this->deleteCallback !== null) {
            return ($this->deleteCallback)($id);
        }

        return $this->deleteResults[$id] ?? false;
    }

    public function purge(): int
    {
        if ($this->purgeException !== null) {
            throw $this->purgeException;
        }

        return $this->purgeResult;
    }
}

final class RecordingBus implements MessageBusInterface
{
    /** @var list<Envelope> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = $message instanceof Envelope ? $message : new Envelope($message, $stamps);
        $this->dispatched[] = $envelope;

        return $envelope;
    }
}

/**
 * TransportRegistry hands back the same StubAdapter for any requested name.
 * We extend the real class so the type-check in MessageOperations is happy.
 */
final class StaticRegistry extends TransportRegistry
{
    public function __construct(private readonly StubAdapter $adapter)
    {
        // Intentionally don't call parent::__construct — we override the two
        // methods MessageOperations needs.
    }

    public function names(): array
    {
        return [$this->adapter->name()];
    }

    public function adapter(string $name): TransportAdapterInterface
    {
        return $this->adapter;
    }

    public function adapters(): iterable
    {
        yield $this->adapter;
    }
}
