<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\BodyDeserializer;

final class BodyDeserializerTest extends TestCase
{
    public function testStandardSerializedEnvelopeRoundTrips(): void
    {
        $envelope = (new Envelope(new BodyDeserializerStubMessage('hello')))
            ->with(new TransportMessageIdStamp('42'))
            ->with(new BusNameStamp('messenger.bus.pimcore-core'));

        $body = serialize($envelope);

        $decoded = BodyDeserializer::tryDeserialize($body);

        $this->assertInstanceOf(Envelope::class, $decoded);
        $this->assertInstanceOf(BodyDeserializerStubMessage::class, $decoded->getMessage());
        $this->assertSame('hello', $decoded->getMessage()->value);
        $this->assertSame('42', (string) $decoded->last(TransportMessageIdStamp::class)?->getId());
    }

    public function testAddslashesEscapedBodyIsRecovered(): void
    {
        $envelope = (new Envelope(new BodyDeserializerStubMessage('escaped')))
            ->with(new TransportMessageIdStamp('99'));
        $raw = serialize($envelope);
        // Simulate the broken-writer path: addslashes() escapes `"`, `\`,
        // and the NUL bytes that serialize() uses for private-property
        // markers. That's the format observed in some production tables.
        $escaped = addslashes($raw);

        $this->assertNotSame($raw, $escaped, 'sanity: escaping must change the string');

        $decoded = BodyDeserializer::tryDeserialize($escaped);

        $this->assertInstanceOf(Envelope::class, $decoded);
        $this->assertSame('escaped', $decoded->getMessage()->value);
        $this->assertSame('99', (string) $decoded->last(TransportMessageIdStamp::class)?->getId());
    }

    public function testReturnsNullForGarbageInput(): void
    {
        $this->assertNull(BodyDeserializer::tryDeserialize('not-a-serialized-string'));
        $this->assertNull(BodyDeserializer::tryDeserialize(''));
    }

    public function testReturnsNullForSerializedNonEnvelopeValue(): void
    {
        // unserialize succeeds but returns something that isn't an Envelope.
        $this->assertNull(BodyDeserializer::tryDeserialize(serialize(['just' => 'an array'])));
        $this->assertNull(BodyDeserializer::tryDeserialize(serialize('plain string')));
        $this->assertNull(BodyDeserializer::tryDeserialize(serialize(new BodyDeserializerStubMessage('not an envelope'))));
    }

    public function testStandardBodyIsNotRunThroughStripslashes(): void
    {
        // A standard body whose payload happens to contain `\"` as a literal
        // byte sequence (e.g. in a message property that stores escaped
        // JSON) must NOT be mangled by stripslashes — the first
        // unserialize() succeeds and stripslashes is never reached.
        $message = new BodyDeserializerStubMessage('value with \\"quoted\\" parts');
        $envelope = new Envelope($message);
        $body = serialize($envelope);

        $decoded = BodyDeserializer::tryDeserialize($body);

        $this->assertNotNull($decoded);
        $this->assertSame('value with \\"quoted\\" parts', $decoded->getMessage()->value);
    }
}

final class BodyDeserializerStubMessage
{
    public function __construct(public readonly string $value) {}
}
