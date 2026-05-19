<?php
declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\ListableReceiverAdapter;

final class ListableReceiverAdapterTest extends TestCase
{
    public function testListWithoutQueryReturnsAll(): void
    {
        $adapter = new ListableReceiverAdapter('test', $this->stubReceiver([
            new OrderMessageStub('order-100'),
            new OrderMessageStub('order-200'),
            new ShipmentMessageStub('ship-1'),
        ]));

        $result = $adapter->list(0, 50);

        $this->assertCount(3, $result);
    }

    public function testListWithQueryFiltersByBody(): void
    {
        $adapter = new ListableReceiverAdapter('test', $this->stubReceiver([
            new OrderMessageStub('order-100'),
            new OrderMessageStub('order-200'),
            new ShipmentMessageStub('ship-1'),
        ]));

        $result = $adapter->list(0, 50, 'order-100');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('order-100', $result[0]->bodyPreview ?? '');
    }

    public function testListWithQueryFiltersByMessageClass(): void
    {
        $adapter = new ListableReceiverAdapter('test', $this->stubReceiver([
            new OrderMessageStub('order-100'),
            new ShipmentMessageStub('ship-1'),
        ]));

        $result = $adapter->list(0, 50, 'Shipment');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('Shipment', $result[0]->messageClass);
    }

    public function testListWithWildcardQuery(): void
    {
        $adapter = new ListableReceiverAdapter('test', $this->stubReceiver([
            new OrderMessageStub('order-100'),
            new OrderMessageStub('order-200'),
            new ShipmentMessageStub('ship-1'),
        ]));

        // 'order-%' matches both order envelopes via body content.
        $result = $adapter->list(0, 50, 'order-%');

        $this->assertCount(2, $result);
    }

    public function testCountListableMatchesListLength(): void
    {
        $adapter = new ListableReceiverAdapter('test', $this->stubReceiver([
            new OrderMessageStub('order-100'),
            new OrderMessageStub('order-200'),
            new ShipmentMessageStub('ship-1'),
        ]));

        $this->assertSame(3, $adapter->countListable(null));
        $this->assertSame(2, $adapter->countListable('order'));
        $this->assertSame(0, $adapter->countListable('nothing-matches'));
    }

    public function testQueryAppliedBeforePagination(): void
    {
        $envelopes = [];
        for ($i = 1; $i <= 20; ++$i) {
            $envelopes[] = new OrderMessageStub(sprintf('order-%03d', $i));
        }
        $adapter = new ListableReceiverAdapter('test', $this->stubReceiver($envelopes));

        // limit=5, offset=0 returns first 5 matches; total = 20.
        $page = $adapter->list(0, 5);
        $this->assertCount(5, $page);

        // With filter, only matches survive paging.
        $filtered = $adapter->list(0, 50, 'order-005');
        $this->assertCount(1, $filtered);
    }

    public function testNameAndType(): void
    {
        $adapter = new ListableReceiverAdapter('queue_x', $this->stubReceiver([]), 'in_memory');

        $this->assertSame('queue_x', $adapter->name());
        $this->assertSame('in_memory', $adapter->type());
    }

    public function testCapabilitiesIncludeListAndInspect(): void
    {
        $adapter = new ListableReceiverAdapter('q', $this->stubReceiver([]));

        $caps = $adapter->capabilities();
        $this->assertTrue($caps->canList);
        $this->assertTrue($caps->canInspectIndividual);
        $this->assertTrue($caps->canDeleteIndividual);
        $this->assertFalse($caps->canCount, 'plain stub is not MessageCountAware');
        $this->assertFalse($caps->canBulkDelete);
        $this->assertFalse($caps->canPurge);
    }

    public function testCapabilitiesAdvertiseCountWhenReceiverIsCountAware(): void
    {
        $receiver = new CountingListableReceiver(7);
        $adapter = new ListableReceiverAdapter('q', $receiver);

        $this->assertTrue($adapter->capabilities()->canCount);
        $this->assertSame(7, $adapter->count());
    }

    public function testCountReturnsZeroWhenReceiverIsNotCountAware(): void
    {
        $adapter = new ListableReceiverAdapter('q', $this->stubReceiver([new OrderMessageStub('x')]));

        $this->assertSame(0, $adapter->count());
    }

    public function testFindByExistingIdReturnsDescriptor(): void
    {
        $adapter = new ListableReceiverAdapter('q', $this->stubReceiver([
            new OrderMessageStub('order-100'),
        ]));

        $descriptor = $adapter->find('1');

        $this->assertNotNull($descriptor);
        $this->assertSame('1', $descriptor->id);
        $this->assertSame(OrderMessageStub::class, $descriptor->messageClass);
    }

    public function testFindReturnsNullForMissingId(): void
    {
        $adapter = new ListableReceiverAdapter('q', $this->stubReceiver([
            new OrderMessageStub('order-100'),
        ]));

        $this->assertNull($adapter->find('does-not-exist'));
    }

    public function testFindEnvelopeReturnsEnvelopeOrNull(): void
    {
        $adapter = new ListableReceiverAdapter('q', $this->stubReceiver([
            new OrderMessageStub('order-100'),
        ]));

        $this->assertNotNull($adapter->findEnvelope('1'));
        $this->assertNull($adapter->findEnvelope('missing'));
    }

    public function testDeleteOneRejectsEnvelopeAndReturnsTrue(): void
    {
        $receiver = new RecordingListableReceiver([
            (new Envelope(new OrderMessageStub('x')))->with(new TransportMessageIdStamp('1')),
        ]);
        $adapter = new ListableReceiverAdapter('q', $receiver);

        $this->assertTrue($adapter->deleteOne('1'));
        $this->assertCount(1, $receiver->rejected);
    }

    public function testDeleteOneReturnsFalseForUnknownId(): void
    {
        $receiver = new RecordingListableReceiver([
            (new Envelope(new OrderMessageStub('x')))->with(new TransportMessageIdStamp('1')),
        ]);
        $adapter = new ListableReceiverAdapter('q', $receiver);

        $this->assertFalse($adapter->deleteOne('99'));
        $this->assertSame([], $receiver->rejected);
    }

    public function testPurgeOnGenericAdapterThrows(): void
    {
        $adapter = new ListableReceiverAdapter('q', $this->stubReceiver([]));

        $this->expectException(\LogicException::class);
        $adapter->purge();
    }

    /**
     * Wraps the given messages in TransportMessageIdStamp'd envelopes and
     * returns a minimal ListableReceiverInterface that yields them.
     */
    private function stubReceiver(array $messages): ListableReceiverInterface
    {
        $envelopes = [];
        $i = 1;
        foreach ($messages as $message) {
            $envelopes[] = (new Envelope($message))->with(new TransportMessageIdStamp((string) $i++));
        }

        return new class($envelopes) implements ListableReceiverInterface {
            public function __construct(private array $envelopes)
            {
            }

            public function all(?int $limit = null): iterable
            {
                return $limit === null ? $this->envelopes : array_slice($this->envelopes, 0, $limit);
            }

            public function find(mixed $id): ?Envelope
            {
                foreach ($this->envelopes as $e) {
                    $stamp = $e->last(TransportMessageIdStamp::class);
                    if ($stamp !== null && (string) $stamp->getId() === (string) $id) {
                        return $e;
                    }
                }

                return null;
            }

            public function get(): iterable
            {
                return [];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }
        };
    }
}

final class OrderMessageStub
{
    public function __construct(public readonly string $orderId)
    {
    }
}

final class ShipmentMessageStub
{
    public function __construct(public readonly string $shipmentId)
    {
    }
}

final class CountingListableReceiver implements ListableReceiverInterface, MessageCountAwareInterface
{
    public function __construct(private readonly int $count)
    {
    }

    public function all(?int $limit = null): iterable
    {
        return [];
    }

    public function find(mixed $id): ?Envelope
    {
        return null;
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function getMessageCount(): int
    {
        return $this->count;
    }
}

final class RecordingListableReceiver implements ListableReceiverInterface
{
    /** @var list<Envelope> */
    public array $rejected = [];

    /** @param list<Envelope> $envelopes */
    public function __construct(private readonly array $envelopes)
    {
    }

    public function all(?int $limit = null): iterable
    {
        return $limit === null ? $this->envelopes : array_slice($this->envelopes, 0, $limit);
    }

    public function find(mixed $id): ?Envelope
    {
        foreach ($this->envelopes as $e) {
            $stamp = $e->last(TransportMessageIdStamp::class);
            if ($stamp !== null && (string) $stamp->getId() === (string) $id) {
                return $e;
            }
        }

        return null;
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
        $this->rejected[] = $envelope;
    }
}
