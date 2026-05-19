<?php
declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\MessageDescriptor;

final class MessageDescriptorTest extends TestCase
{
    public function testToArraySerializesAllFields(): void
    {
        $createdAt = new \DateTimeImmutable('2026-05-19T12:34:56+00:00');
        $descriptor = new MessageDescriptor(
            id: 'msg-42',
            messageClass: 'App\\Message\\ImportProduct',
            createdAt: $createdAt,
            retryCount: 2,
            headers: ['sentFromTransport' => 'pim_import', 'manualRequeues' => 1],
            bodyPreview: '{"sku":"ABC"}',
            failureClass: \RuntimeException::class,
            failureMessage: 'boom',
        );

        $this->assertSame([
            'id' => 'msg-42',
            'messageClass' => 'App\\Message\\ImportProduct',
            'createdAt' => '2026-05-19T12:34:56+00:00',
            'retryCount' => 2,
            'headers' => ['sentFromTransport' => 'pim_import', 'manualRequeues' => 1],
            'bodyPreview' => '{"sku":"ABC"}',
            'failureClass' => \RuntimeException::class,
            'failureMessage' => 'boom',
        ], $descriptor->toArray());
    }

    public function testToArrayNullsDefaultToNull(): void
    {
        $descriptor = new MessageDescriptor(
            id: '1',
            messageClass: 'Foo',
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $array = $descriptor->toArray();

        $this->assertNull($array['retryCount']);
        $this->assertNull($array['bodyPreview']);
        $this->assertNull($array['failureClass']);
        $this->assertNull($array['failureMessage']);
        $this->assertSame([], $array['headers']);
    }
}
