<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Functional\Controller;

use TwoChain\PimcoreMessengerDashboardBundle\Tests\Functional\FunctionalTestCase;

/**
 * End-to-end HTTP tests for the /failed/* routes. The TestKernel
 * configures `pim_failed` as the failure_transport (in-memory).
 */
final class FailedFlowsTest extends FunctionalTestCase
{
    public function testListFailedReturnsAllMessagesInTheFailureTransport(): void
    {
        $this->send(new FailureSampleA('a'), 'pim_failed');
        $this->send(new FailureSampleB('b'), 'pim_failed');

        $this->client->request('GET', '/admin/messenger-dashboard/failed/messages');

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame('pim_failed', $body['transport']);
        $this->assertSame(2, $body['total']);
    }

    public function testFailedMessageClassesEndpointReturnsDistinctSortedList(): void
    {
        $this->send(new FailureSampleB('bee'), 'pim_failed');
        $this->send(new FailureSampleA('alpha'), 'pim_failed');
        $this->send(new FailureSampleA('apple'), 'pim_failed');

        $this->client->request('GET', '/admin/messenger-dashboard/failed/message-classes');

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame([FailureSampleA::class, FailureSampleB::class], $body['classes']);
    }

    public function testListFailedFiltersByMessageClass(): void
    {
        $this->send(new FailureSampleA('one'), 'pim_failed');
        $this->send(new FailureSampleB('two'), 'pim_failed');
        $this->send(new FailureSampleA('three'), 'pim_failed');

        $this->client->request(
            'GET',
            '/admin/messenger-dashboard/failed/messages?messageClass=' . urlencode(FailureSampleA::class),
        );

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame(2, $body['total']);
        foreach ($body['items'] as $item) {
            $this->assertSame(FailureSampleA::class, $item['messageClass']);
        }
    }

    public function testListFailedSearchByText(): void
    {
        $this->send(new FailureSampleA('NEEDLE-here'), 'pim_failed');
        $this->send(new FailureSampleA('haystack'), 'pim_failed');

        $this->client->request('GET', '/admin/messenger-dashboard/failed/messages?q=NEEDLE-here');

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame(1, $body['total']);
    }

    public function testShowFailedReturnsMessageDescriptor(): void
    {
        $this->send(new FailureSampleA('inspectable'), 'pim_failed');
        $id = $this->listFailedIds()[0];

        $this->client->request('GET', '/admin/messenger-dashboard/failed/messages/' . $id);

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame($id, $body['id']);
        $this->assertSame(FailureSampleA::class, $body['messageClass']);
    }

    public function testDeleteFailedRemovesIt(): void
    {
        $this->send(new FailureSampleA('to-delete'), 'pim_failed');
        $this->send(new FailureSampleB('survivor'), 'pim_failed');
        $ids = $this->listFailedIds();

        $this->client->request('DELETE', '/admin/messenger-dashboard/failed/messages/' . $ids[0]);

        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $this->listFailedIds());
    }

    public function testFailedRequeueOnSuccessReturns202AndClearsFromFailed(): void
    {
        $this->sendToFailureTransport(new FailureSampleA('requeue-me'), 'pim_failed', 'test_q');
        $id = $this->listFailedIds()[0];

        $this->client->request('POST', '/admin/messenger-dashboard/failed/messages/' . $id . '/requeue');

        $this->assertSame(202, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->listFailedIds());
    }

    public function testFailedRequeueOnUnknownIdReturns400WithReason(): void
    {
        $this->client->request('POST', '/admin/messenger-dashboard/failed/messages/9999/requeue');

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());
        $body = $this->decodeJson($response);
        $this->assertSame('requeue_failed', $body['error']['code']);
        $this->assertSame('message_not_found', $body['error']['message']);
    }

    public function testBulkDeleteFailedByIds(): void
    {
        $this->send(new FailureSampleA('a'), 'pim_failed');
        $this->send(new FailureSampleA('b'), 'pim_failed');
        $this->send(new FailureSampleA('c'), 'pim_failed');
        $ids = $this->listFailedIds();

        $this->client->request(
            'POST',
            '/admin/messenger-dashboard/failed/messages/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$ids[0], $ids[2]]]),
        );

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame(2, $body['processed']);
        $this->assertCount(1, $this->listFailedIds());
    }

    public function testBulkDeleteFailedWithAllPurges(): void
    {
        $this->send(new FailureSampleA('a'), 'pim_failed');
        $this->send(new FailureSampleA('b'), 'pim_failed');

        $this->client->request(
            'POST',
            '/admin/messenger-dashboard/failed/messages/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['all' => true]),
        );

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame(2, $body['processed']);
        $this->assertSame([], $this->listFailedIds());
    }

    public function testBulkRequeueAllReturnsCountAndDrainsFailed(): void
    {
        $this->sendToFailureTransport(new FailureSampleA('a'), 'pim_failed', 'test_q');
        $this->sendToFailureTransport(new FailureSampleA('b'), 'pim_failed', 'test_q');

        $this->client->request(
            'POST',
            '/admin/messenger-dashboard/failed/messages/bulk-requeue',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['all' => true]),
        );

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame(2, $body['processed']);
        $this->assertSame([], $this->listFailedIds());
    }

    public function testBulkRequeueByIdsLeavesOthersInPlace(): void
    {
        $this->sendToFailureTransport(new FailureSampleA('a'), 'pim_failed', 'test_q');
        $this->sendToFailureTransport(new FailureSampleA('b'), 'pim_failed', 'test_q');
        $this->sendToFailureTransport(new FailureSampleA('c'), 'pim_failed', 'test_q');
        $ids = $this->listFailedIds();

        $this->client->request(
            'POST',
            '/admin/messenger-dashboard/failed/messages/bulk-requeue',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$ids[1]]]),
        );

        $body = $this->decodeJson($this->client->getResponse());
        $this->assertSame(1, $body['processed']);
        $this->assertCount(2, $this->listFailedIds());
    }

    /** @return list<string> */
    private function listFailedIds(): array
    {
        $this->client->request('GET', '/admin/messenger-dashboard/failed/messages?limit=500');
        $body = $this->decodeJson($this->client->getResponse());

        return array_map(static fn(array $item): string => (string) $item['id'], $body['items']);
    }
}

final class FailureSampleA
{
    public function __construct(public readonly string $label) {}
}

final class FailureSampleB
{
    public function __construct(public readonly string $label) {}
}
