<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Functional\Controller;

use TwoChain\PimcoreMessengerDashboardBundle\Tests\Functional\FunctionalTestCase;

/**
 * Verify each endpoint's permission gating end-to-end. View-denied users
 * must get 403 on read endpoints; edit-denied users can read but must get
 * 403 on mutating endpoints. The TestablePermissionChecker exposes
 * `denyView` / `denyEdit` flags the tests poke at.
 */
final class PermissionGatingTest extends FunctionalTestCase
{
    // ---------- view-denied: every endpoint must 403 ----------

    public function testViewDeniedBlocksTransportsList(): void
    {
        $this->permissionChecker()->denyView = true;

        $this->client->request('GET', '/admin/messenger-dashboard/transports');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testViewDeniedBlocksMessagesList(): void
    {
        $this->permissionChecker()->denyView = true;

        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testViewDeniedBlocksShowMessage(): void
    {
        $this->permissionChecker()->denyView = true;

        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages/1');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testViewDeniedBlocksFailedList(): void
    {
        $this->permissionChecker()->denyView = true;

        $this->client->request('GET', '/admin/messenger-dashboard/failed/messages');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testViewDeniedBlocksFailedMessageClasses(): void
    {
        $this->permissionChecker()->denyView = true;

        $this->client->request('GET', '/admin/messenger-dashboard/failed/message-classes');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testViewDeniedBlocksStats(): void
    {
        $this->permissionChecker()->denyView = true;

        $this->client->request('GET', '/admin/messenger-dashboard/stats');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    // ---------- edit-denied: reads OK, mutations 403 ----------

    public function testEditDeniedAllowsReadingButBlocksDelete(): void
    {
        $this->permissionChecker()->denyEdit = true;
        $this->send(new GatedMessage('payload'), 'test_q');

        $this->client->request('GET', '/admin/messenger-dashboard/transports/test_q/messages');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('DELETE', '/admin/messenger-dashboard/transports/test_q/messages/1');
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testEditDeniedBlocksBulkDelete(): void
    {
        $this->permissionChecker()->denyEdit = true;

        $this->client->request(
            'POST',
            '/admin/messenger-dashboard/transports/test_q/messages/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['all' => true]),
        );

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testEditDeniedBlocksFailedDelete(): void
    {
        $this->permissionChecker()->denyEdit = true;

        $this->client->request('DELETE', '/admin/messenger-dashboard/failed/messages/1');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testEditDeniedBlocksFailedRequeue(): void
    {
        $this->permissionChecker()->denyEdit = true;

        $this->client->request('POST', '/admin/messenger-dashboard/failed/messages/1/requeue');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testEditDeniedBlocksFailedBulkDelete(): void
    {
        $this->permissionChecker()->denyEdit = true;

        $this->client->request(
            'POST',
            '/admin/messenger-dashboard/failed/messages/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['all' => true]),
        );

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testEditDeniedBlocksFailedBulkRequeue(): void
    {
        $this->permissionChecker()->denyEdit = true;

        $this->client->request(
            'POST',
            '/admin/messenger-dashboard/failed/messages/bulk-requeue',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['all' => true]),
        );

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testStatsEndpointIsViewOnlyAndPassesEditDenied(): void
    {
        $this->permissionChecker()->denyEdit = true;

        $this->client->request('GET', '/admin/messenger-dashboard/stats?windows=1h');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}

final class GatedMessage
{
    public function __construct(public readonly string $payload) {}
}
