<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Functional\Controller;

use TwoChain\PimcoreMessengerDashboardBundle\Tests\Functional\FunctionalTestCase;

/**
 * End-to-end HTTP tests for the transports + per-transport message routes.
 *
 * Uses the in-memory messenger transports configured in TestKernel
 * (test_q, test_q2, pim_failed) plus the bundle's InMemoryTransportAdapter
 * to exercise the full list/find/delete/purge pipeline.
 */
final class TransportsAndMessagesTest extends FunctionalTestCase
{
    public function testTransportsEndpointReturnsAllConfiguredTransports(): void
    {
        $this->client->request('GET', '/admin/messenger-dashboard/transports');

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decodeJson($response);

        $names = array_column($body, 'name');
        sort($names);
        $this->assertSame(['pim_failed', 'test_q', 'test_q2'], $names);
    }

    public function testTransportSummaryFlagsTheFailureTransport(): void
    {
        $this->client->request('GET', '/admin/messenger-dashboard/transports');
        $body = $this->decodeJson($this->client->getResponse());

        $byName = [];
        foreach ($body as $entry) {
            $byName[$entry['name']] = $entry;
        }

        $this->assertTrue($byName['pim_failed']['isFailedTransport']);
        $this->assertFalse($byName['test_q']['isFailedTransport']);
    }

    public function testListMessagesEmptyTransportReturnsZeroTotal(): void
    {
        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages');

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame('test_q', $body['transport']);
        $this->assertSame(0, $body['total']);
        $this->assertSame([], $body['items']);
    }

    public function testListMessagesReturnsDispatchedEnvelopes(): void
    {
        $this->send(new FunctionalMessage('first'), 'test_q');
        $this->send(new FunctionalMessage('second'), 'test_q');

        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages');

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame(2, $body['total']);
        $this->assertCount(2, $body['items']);
        $this->assertSame(FunctionalMessage::class, $body['items'][0]['messageClass']);
    }

    public function testListMessagesClampsLimitAndOffset(): void
    {
        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages?offset=-5&limit=99999');

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame(0, $body['offset']);
        $this->assertSame(500, $body['limit']);
    }

    public function testListMessages404sForUnknownTransport(): void
    {
        $this->client->request('GET', '/admin/messenger-dashboard/transports/ghost_transport/messages');

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testSearchQueryFiltersMessages(): void
    {
        $this->send(new FunctionalMessage('NEEDLE-payload'), 'test_q');
        $this->send(new FunctionalMessage('plain-haystack'), 'test_q');

        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages?q=NEEDLE-payload');

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame(1, $body['total']);
    }

    public function testOverlongQueryRejectedWith400(): void
    {
        $tooLong = str_repeat('x', 1025);

        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages?q=' . $tooLong);

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());
        $body = $this->decodeJson($response);
        $this->assertSame('query_too_long', $body['error']['code']);
    }

    public function testShowMessageReturnsDescriptorForExistingId(): void
    {
        $this->send(new FunctionalMessage('inspect-me'), 'test_q');
        $id = $this->listMessageIds('test_q')[0];

        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages/' . $id);

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame($id, $body['id']);
        $this->assertSame(FunctionalMessage::class, $body['messageClass']);
    }

    public function testShowMessage404sForUnknownId(): void
    {
        $this->send(new FunctionalMessage('x'), 'test_q');

        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages/9999');

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteMessageReturns204AndRemovesIt(): void
    {
        $this->send(new FunctionalMessage('to-be-deleted'), 'test_q');
        $id = $this->listMessageIds('test_q')[0];

        $this->client->request('DELETE', '/admin/messenger-dashboard/transports/test_q/messages/' . $id);

        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // The message should now be gone from the listing.
        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages');
        $this->assertSame(0, $this->decodeJson($this->client->getResponse())['total']);
    }

    public function testDeleteMessage404sForUnknownId(): void
    {
        $this->send(new FunctionalMessage('x'), 'test_q');

        $this->client->request('DELETE', '/admin/messenger-dashboard/transports/test_q/messages/9999');

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testBulkDeleteByIdsRemovesSelected(): void
    {
        $this->send(new FunctionalMessage('a'), 'test_q');
        $this->send(new FunctionalMessage('b'), 'test_q');
        $this->send(new FunctionalMessage('c'), 'test_q');
        $ids = $this->listMessageIds('test_q');
        $this->assertCount(3, $ids, 'sanity: 3 messages should be listed');

        $this->client->request(
            'POST',
            '/admin/messenger-dashboard/transports/test_q/messages/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$ids[0], $ids[2]]]),
        );

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame(2, $body['processed']);
        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages');
        $this->assertSame(1, $this->decodeJson($this->client->getResponse())['total']);
    }

    public function testBulkDeleteAllPurgesTheTransport(): void
    {
        $this->send(new FunctionalMessage('a'), 'test_q');
        $this->send(new FunctionalMessage('b'), 'test_q');
        $this->send(new FunctionalMessage('c'), 'test_q');

        $this->client->request(
            'POST',
            '/admin/messenger-dashboard/transports/test_q/messages/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['all' => true]),
        );

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame(3, $body['processed']);
        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages');
        $this->assertSame(0, $this->decodeJson($this->client->getResponse())['total']);
    }

    public function testBulkDeleteRejectsInvalidPayloadWith400(): void
    {
        $this->client->request(
            'POST',
            '/admin/messenger-dashboard/transports/test_q/messages/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_payload', $this->decodeJson($response)['error']['code']);
    }
}

final class FunctionalMessage
{
    public function __construct(public readonly string $label) {}
}
