<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Integration\Service\Adapter;

use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as MessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\DoctrineTransportAdapter;
use TwoChain\PimcoreMessengerDashboardBundle\Tests\Integration\IntegrationTestCase;
use RuntimeException;

/**
 * Integration tests for {@see DoctrineTransportAdapter} against a real
 * DBAL connection. Exercises:
 *  - The full INSERT → SQL filter → deserialize cycle for standard bodies.
 *  - The tolerant-deserialize fallback path on addslashes-escaped bodies
 *    (the production failure mode this bundle was bitten by).
 *  - The fixed LIKE … ESCAPE placement that MariaDB rejects when applied
 *    to a parenthesized OR (only catches the regression when run against
 *    MariaDB; SQLite is more permissive).
 */
final class DoctrineTransportAdapterIntegrationTest extends IntegrationTestCase
{
    private const string TRANSPORT = 'test_transport';

    private DoctrineTransport $transport;
    private DoctrineTransportAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMessengerMessagesTable();

        $messengerConn = new MessengerConnection(
            // auto_setup=false: we created the schema ourselves; don't let
            // the bridge try to create it again per query.
            ['table_name' => 'messenger_messages', 'queue_name' => self::TRANSPORT, 'auto_setup' => false],
            $this->conn,
        );
        $this->transport = new DoctrineTransport($messengerConn, new PhpSerializer());
        $this->adapter = new DoctrineTransportAdapter(self::TRANSPORT, $this->transport);
    }

    public function testListReturnsRowsInsertedViaTheTransport(): void
    {
        $this->transport->send(new Envelope(new SampleMessage('alpha')));
        $this->transport->send(new Envelope(new SampleMessage('beta')));

        $descriptors = $this->adapter->list(0, 50);

        $this->assertCount(2, $descriptors);
        $classes = array_map(fn($d): string => $d->messageClass, $descriptors);
        $this->assertSame([SampleMessage::class, SampleMessage::class], $classes);
    }

    public function testListPopulatesDescriptorIdFromDatabaseRow(): void
    {
        $this->transport->send(new Envelope(new SampleMessage('alpha')));
        $this->transport->send(new Envelope(new SampleMessage('beta')));

        $rowIds = $this->conn->fetchFirstColumn('SELECT id FROM messenger_messages ORDER BY id ASC');
        $rowIds = array_map('strval', $rowIds);

        $descriptors = $this->adapter->list(0, 50);
        $descriptorIds = array_map(fn($d): string => $d->id, $descriptors);

        $this->assertSame($rowIds, $descriptorIds);
    }

    public function testListPopulatesDescriptorIdEvenForEscapedBodies(): void
    {
        $this->conn->insert('messenger_messages', [
            'body' => addslashes(serialize(new Envelope(new SampleMessage('escaped-id')))),
            'headers' => '[]',
            'queue_name' => self::TRANSPORT,
            'created_at' => '2026-01-01 00:00:00',
            'available_at' => '2026-01-01 00:00:00',
        ]);
        $rowId = (string) $this->conn->fetchOne('SELECT id FROM messenger_messages LIMIT 1');

        $descriptors = $this->adapter->list(0, 50);

        $this->assertCount(1, $descriptors);
        $this->assertSame($rowId, $descriptors[0]->id);
    }

    public function testCountReflectsPendingRows(): void
    {
        $this->transport->send(new Envelope(new SampleMessage('one')));
        $this->transport->send(new Envelope(new SampleMessage('two')));
        $this->transport->send(new Envelope(new SampleMessage('three')));

        $this->assertSame(3, $this->adapter->count());
    }

    public function testListIgnoresRowsFromOtherQueues(): void
    {
        $this->transport->send(new Envelope(new SampleMessage('mine')));
        // Manually insert a row in a different queue_name — must not show up.
        $this->conn->insert('messenger_messages', [
            'body' => serialize(new Envelope(new SampleMessage('not mine'))),
            'headers' => '[]',
            'queue_name' => 'other_queue',
            'created_at' => '2026-01-01 00:00:00',
            'available_at' => '2026-01-01 00:00:00',
        ]);

        $descriptors = $this->adapter->list(0, 50);

        $this->assertCount(1, $descriptors);
        $this->assertSame(1, $this->adapter->count());
    }

    public function testListIgnoresAlreadyDeliveredRows(): void
    {
        $this->transport->send(new Envelope(new SampleMessage('pending')));
        $this->conn->insert('messenger_messages', [
            'body' => serialize(new Envelope(new SampleMessage('delivered'))),
            'headers' => '[]',
            'queue_name' => self::TRANSPORT,
            'created_at' => '2026-01-01 00:00:00',
            'available_at' => '2026-01-01 00:00:00',
            'delivered_at' => '2026-01-01 01:00:00',
        ]);

        // list() applies `delivered_at IS NULL` filter. We don't assert on
        // count() here: the underlying receiver's getMessageCount applies
        // Symfony's visibility-timeout semantics, which is intentionally
        // different from list() — count() drives the sidebar badge while
        // list() drives the grid.
        $this->assertCount(1, $this->adapter->list(0, 50));
    }

    public function testSearchByBodyText(): void
    {
        $this->transport->send(new Envelope(new SampleMessage('alpha-needle-zeta')));
        $this->transport->send(new Envelope(new SampleMessage('plain-haystack')));
        $this->transport->send(new Envelope(new SampleMessage('alpha-other')));

        $hits = $this->adapter->list(0, 50, 'needle');

        $this->assertCount(1, $hits);
    }

    public function testSearchCountListableMatchesListLength(): void
    {
        // Use distinctive substrings that don't accidentally match the
        // namespace of the serialized class (which contains "Adapter",
        // "Service" etc. and would clash with naive search terms).
        $this->transport->send(new Envelope(new SampleMessage('NEEDLE-apple')));
        $this->transport->send(new Envelope(new SampleMessage('NEEDLE-apricot')));
        $this->transport->send(new Envelope(new SampleMessage('HAYSTACK-banana')));

        $this->assertSame(2, $this->adapter->countListable('NEEDLE'));
        $this->assertSame(1, $this->adapter->countListable('HAYSTACK'));
        $this->assertSame(0, $this->adapter->countListable('NOWHERE'));
    }

    public function testSearchMatchesAgainstFailureErrorDetailsStampInHeaders(): void
    {
        // Failed messages carry their ErrorDetailsStamp serialized into the
        // body (via PhpSerializer). Search across body covers it.
        $envelope = (new Envelope(new SampleMessage('payload')))
            ->with(new ErrorDetailsStamp(RuntimeException::class, 0, 'Database connection refused'))
            ->with(new SentToFailureTransportStamp('original_q'));
        $this->transport->send($envelope);
        $this->transport->send(new Envelope(new SampleMessage('quiet')));

        $hits = $this->adapter->list(0, 50, 'Database connection refused');

        $this->assertCount(1, $hits);
        $this->assertSame('Database connection refused', $hits[0]->failureMessage);
        $this->assertSame(RuntimeException::class, $hits[0]->failureClass);
    }

    public function testToleratesAddslashesEscapedBody(): void
    {
        // Simulate the broken-writer scenario where a project's pipeline
        // ran addslashes() (or json_encode without the outer quotes) on the
        // PhpSerializer output before INSERT. PhpSerializer can't decode
        // that — it returns false from unserialize(). The adapter must
        // recover via BodyDeserializer's stripslashes fallback.
        $envelope = new Envelope(new SampleMessage('escaped-target'));
        $standard = serialize($envelope);
        $escaped = addslashes($standard);
        $this->assertNotSame($standard, $escaped, 'sanity: escaping changes the bytes');

        $this->conn->insert('messenger_messages', [
            'body' => $escaped,
            'headers' => '[]',
            'queue_name' => self::TRANSPORT,
            'created_at' => '2026-01-01 00:00:00',
            'available_at' => '2026-01-01 00:00:00',
        ]);

        $hits = $this->adapter->list(0, 50);

        $this->assertCount(1, $hits, 'adapter must recover the escaped row via stripslashes fallback');
        $this->assertSame(SampleMessage::class, $hits[0]->messageClass);
    }

    public function testSearchFindsEscapedBodiesToo(): void
    {
        // The fix from before: `body LIKE '%text%'` matches plain-ASCII
        // substrings even when surrounding bytes are escape-mangled. This
        // is what unblocked the user's "Cannot save object" search.
        $envelope = new Envelope(new SampleMessage('Cannot save object 10033044'));
        $this->conn->insert('messenger_messages', [
            'body' => addslashes(serialize($envelope)),
            'headers' => '[]',
            'queue_name' => self::TRANSPORT,
            'created_at' => '2026-01-01 00:00:00',
            'available_at' => '2026-01-01 00:00:00',
        ]);

        $hits = $this->adapter->list(0, 50, 'Cannot save object');

        $this->assertCount(1, $hits);
    }

    public function testFindReturnsDescriptorForExistingId(): void
    {
        $this->transport->send(new Envelope(new SampleMessage('first')));
        $id = (string) $this->conn->fetchOne('SELECT id FROM messenger_messages LIMIT 1');

        $descriptor = $this->adapter->find($id);

        $this->assertNotNull($descriptor);
        $this->assertSame($id, $descriptor->id);
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        $this->transport->send(new Envelope(new SampleMessage('x')));

        $this->assertNull($this->adapter->find('99999'));
    }

    public function testFindEnvelopeWorksForEscapedBodyViaTolerantFallback(): void
    {
        // Bridge's serializer would throw on this body; the adapter's
        // tolerant fallback path must take over.
        $envelope = new Envelope(new SampleMessage('tolerant-find'));
        $this->conn->insert('messenger_messages', [
            'body' => addslashes(serialize($envelope)),
            'headers' => '[]',
            'queue_name' => self::TRANSPORT,
            'created_at' => '2026-01-01 00:00:00',
            'available_at' => '2026-01-01 00:00:00',
        ]);
        $id = (string) $this->conn->fetchOne('SELECT id FROM messenger_messages LIMIT 1');

        $found = $this->adapter->findEnvelope($id);

        $this->assertInstanceOf(Envelope::class, $found);
        $this->assertSame('tolerant-find', $found->getMessage()->label);
    }

    public function testDeleteOneRemovesTheRowFromTheTable(): void
    {
        $this->transport->send(new Envelope(new SampleMessage('a')));
        $this->transport->send(new Envelope(new SampleMessage('b')));
        $idA = (string) $this->conn->fetchOne(
            'SELECT id FROM messenger_messages WHERE body LIKE ? LIMIT 1',
            ['%' . 'SampleMessage' . '%'],
        );

        $this->assertTrue($this->adapter->deleteOne($idA));
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    public function testDeleteOneReturnsFalseForUnknownId(): void
    {
        $this->transport->send(new Envelope(new SampleMessage('a')));

        $this->assertFalse($this->adapter->deleteOne('99999'));
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    public function testDeleteOneWorksOnEscapedBodyRows(): void
    {
        // Direct SQL DELETE in our override means escaped bodies — which
        // can't deserialize fully — can still be removed by id.
        $this->conn->insert('messenger_messages', [
            'body' => addslashes(serialize(new Envelope(new SampleMessage('escaped-delete')))),
            'headers' => '[]',
            'queue_name' => self::TRANSPORT,
            'created_at' => '2026-01-01 00:00:00',
            'available_at' => '2026-01-01 00:00:00',
        ]);
        $id = (string) $this->conn->fetchOne('SELECT id FROM messenger_messages LIMIT 1');

        $this->assertTrue($this->adapter->deleteOne($id));
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    public function testPurgeRemovesAllPendingRowsForTheQueue(): void
    {
        $this->transport->send(new Envelope(new SampleMessage('a')));
        $this->transport->send(new Envelope(new SampleMessage('b')));
        $this->transport->send(new Envelope(new SampleMessage('c')));

        $this->assertSame(3, $this->adapter->purge());
        $this->assertSame(0, $this->adapter->count());
    }

    public function testSearchWorksEvenWhenAnotherRowsBodyIsUnreadable(): void
    {
        // Insert a body that NO serializer can decode — not standard PHP
        // serialize, not addslashes-escaped, just junk. The receiver-based
        // fallback path (parent::list) would throw mid-iteration on this
        // row. Only the direct-SQL path can return useful results, because
        // it filters at SQL level and lets BodyDeserializer silently skip
        // rows that don't deserialize.
        //
        // If the SQL `LIKE … ESCAPE` placement regresses (MariaDB rejects
        // `(… OR …) ESCAPE`), the adapter falls back to parent::list,
        // hits the junk body, and this test surfaces the failure as a
        // thrown exception — exactly the regression that was hiding in
        // production.
        $this->conn->insert('messenger_messages', [
            'body' => 'this is not a serialized envelope at all }',
            'headers' => '[]',
            'queue_name' => self::TRANSPORT,
            'created_at' => '2026-01-01 00:00:00',
            'available_at' => '2026-01-01 00:00:00',
        ]);
        $this->transport->send(new Envelope(new SampleMessage('SQL-PATH-NEEDLE')));

        $hits = $this->adapter->list(0, 50, 'SQL-PATH-NEEDLE');

        $this->assertCount(1, $hits);
    }

    public function testPurgeDoesNotTouchOtherQueues(): void
    {
        $this->transport->send(new Envelope(new SampleMessage('mine')));
        $this->conn->insert('messenger_messages', [
            'body' => serialize(new Envelope(new SampleMessage('not mine'))),
            'headers' => '[]',
            'queue_name' => 'untouched_queue',
            'created_at' => '2026-01-01 00:00:00',
            'available_at' => '2026-01-01 00:00:00',
        ]);

        $this->adapter->purge();

        $this->assertSame(1, (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'untouched_queue'",
        ));
    }
}

final class SampleMessage
{
    public function __construct(public readonly string $label) {}

    public function __toString(): string
    {
        return $this->label;
    }
}
