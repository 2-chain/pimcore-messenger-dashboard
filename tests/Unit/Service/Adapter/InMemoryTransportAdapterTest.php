<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\InMemoryTransportAdapter;

final class InMemoryTransportAdapterTest extends TestCase
{
    public function testTypeAndName(): void
    {
        $adapter = new InMemoryTransportAdapter('in_mem', new InMemoryTransport());

        $this->assertSame('in_mem', $adapter->name());
        $this->assertSame('in_memory', $adapter->type());
    }

    public function testCapabilitiesAdvertiseFullSupportExceptRequeue(): void
    {
        $adapter = new InMemoryTransportAdapter('t', new InMemoryTransport());

        $caps = $adapter->capabilities();
        $this->assertTrue($caps->canCount);
        $this->assertTrue($caps->canList);
        $this->assertTrue($caps->canInspectIndividual);
        $this->assertTrue($caps->canDeleteIndividual);
        $this->assertTrue($caps->canBulkDelete);
        $this->assertTrue($caps->canPurge);
        $this->assertFalse(
            $caps->canRequeue,
            'in-memory transports are per-request; requeue across requests is meaningless',
        );
    }

    public function testCountReflectsPendingQueue(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new InMemoryMessage('order-1')));
        $transport->send(new Envelope(new InMemoryMessage('order-2')));
        $adapter = new InMemoryTransportAdapter('t', $transport);

        $this->assertSame(2, $adapter->count());
    }

    public function testListReturnsAllSentMessages(): void
    {
        $transport = new InMemoryTransport();
        $a = $transport->send(new Envelope(new InMemoryMessage('order-1')));
        $b = $transport->send(new Envelope(new InMemoryMessage('order-2')));
        $adapter = new InMemoryTransportAdapter('t', $transport);

        $listed = $adapter->list(0, 50);

        $this->assertCount(2, $listed);
        $this->assertSame(InMemoryMessage::class, $listed[0]->messageClass);
        $idA = (string) $a->last(TransportMessageIdStamp::class)?->getId();
        $idB = (string) $b->last(TransportMessageIdStamp::class)?->getId();
        $this->assertSame([$idA, $idB], array_map(fn($d) => $d->id, $listed));
    }

    public function testListWithQueryFiltersByBody(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new InMemoryMessage('order-100')));
        $transport->send(new Envelope(new InMemoryMessage('order-200')));
        $adapter = new InMemoryTransportAdapter('t', $transport);

        $result = $adapter->list(0, 50, 'order-100');

        $this->assertCount(1, $result);
    }

    public function testCountListableRespectsQuery(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new InMemoryMessage('a')));
        $transport->send(new Envelope(new InMemoryMessage('b')));
        $transport->send(new Envelope(new InMemoryMessage('a')));
        $adapter = new InMemoryTransportAdapter('t', $transport);

        $this->assertSame(3, $adapter->countListable());
        $this->assertSame(2, $adapter->countListable('"a"'));
    }

    public function testFindByIdReturnsDescriptor(): void
    {
        $transport = new InMemoryTransport();
        $sent = $transport->send(new Envelope(new InMemoryMessage('order-1')));
        $id = (string) $sent->last(TransportMessageIdStamp::class)?->getId();
        $adapter = new InMemoryTransportAdapter('t', $transport);

        $found = $adapter->find($id);

        $this->assertNotNull($found);
        $this->assertSame($id, $found->id);
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new InMemoryMessage('x')));
        $adapter = new InMemoryTransportAdapter('t', $transport);

        $this->assertNull($adapter->find('does-not-exist'));
    }

    public function testFindEnvelopeReturnsRawEnvelope(): void
    {
        $transport = new InMemoryTransport();
        $sent = $transport->send(new Envelope(new InMemoryMessage('order-1')));
        $id = (string) $sent->last(TransportMessageIdStamp::class)?->getId();
        $adapter = new InMemoryTransportAdapter('t', $transport);

        $envelope = $adapter->findEnvelope($id);

        $this->assertNotNull($envelope);
        $this->assertInstanceOf(InMemoryMessage::class, $envelope->getMessage());
    }

    public function testDeleteOneRemovesFromPendingQueue(): void
    {
        $transport = new InMemoryTransport();
        $sent = $transport->send(new Envelope(new InMemoryMessage('order-1')));
        $transport->send(new Envelope(new InMemoryMessage('order-2')));
        $id = (string) $sent->last(TransportMessageIdStamp::class)?->getId();
        $adapter = new InMemoryTransportAdapter('t', $transport);

        $this->assertTrue($adapter->deleteOne($id));
        $this->assertSame(1, $adapter->count());
        $this->assertNull($adapter->find($id));
    }

    public function testDeleteOneReturnsFalseForUnknownId(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new InMemoryMessage('x')));
        $adapter = new InMemoryTransportAdapter('t', $transport);

        $this->assertFalse($adapter->deleteOne('does-not-exist'));
    }

    public function testPurgeEmptiesPendingQueueAndReportsCount(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new InMemoryMessage('a')));
        $transport->send(new Envelope(new InMemoryMessage('b')));
        $transport->send(new Envelope(new InMemoryMessage('c')));
        $adapter = new InMemoryTransportAdapter('t', $transport);

        $this->assertSame(3, $adapter->purge());
        $this->assertSame(0, $adapter->count());
    }

    public function testAlreadyAckedMessagesDoNotShowUp(): void
    {
        $transport = new InMemoryTransport();
        $sent = $transport->send(new Envelope(new InMemoryMessage('order-1')));
        $transport->ack($sent);
        $adapter = new InMemoryTransportAdapter('t', $transport);

        $this->assertSame(0, $adapter->count());
        $this->assertSame([], $adapter->list());
    }
}

final class InMemoryMessage
{
    public function __construct(public readonly string $payload) {}
}
