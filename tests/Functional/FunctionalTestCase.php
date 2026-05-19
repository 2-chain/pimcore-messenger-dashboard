<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Override;
use RuntimeException;

abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    #[Override]
    protected static function createKernel(array $options = []): TestKernel
    {
        return new TestKernel();
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        // KernelBrowser reboots the kernel between requests by default,
        // which wipes the in-memory messenger transports and Doctrine
        // SQLite connection. Test scenarios that span multiple HTTP
        // requests (e.g. POST delete → GET listing) need a stable kernel.
        $this->client->disableReboot();
        $this->createStatsSchema();
    }

    protected function permissionChecker(): TestablePermissionChecker
    {
        return static::getContainer()->get(\TwoChain\PimcoreMessengerDashboardBundle\Service\PermissionChecker::class);
    }

    protected function messageBus(): MessageBusInterface
    {
        return static::getContainer()->get('messenger.bus.pimcore-core');
    }

    /**
     * Dispatch a message to the named transport via TransportNamesStamp.
     * Returns the envelope after dispatch (so the test can read back the
     * TransportMessageIdStamp id the in-memory transport assigned).
     */
    protected function send(object $message, string $transport): Envelope
    {
        return $this->messageBus()->dispatch(
            new Envelope($message, [new TransportNamesStamp([$transport])]),
        );
    }

    /**
     * Dispatch a message to the failure transport with the stamps that
     * Symfony's `SendFailedMessageToFailureTransportListener` would
     * normally attach in production. Tests that exercise requeue need
     * a `SentToFailureTransportStamp` so the dashboard can identify the
     * original transport to re-dispatch back to.
     */
    protected function sendToFailureTransport(
        object $message,
        string $failureTransport,
        string $originalTransport,
        string $exceptionClass = RuntimeException::class,
        string $exceptionMessage = 'simulated failure',
    ): Envelope {
        return $this->messageBus()->dispatch(
            new Envelope($message, [
                new TransportNamesStamp([$failureTransport]),
                new SentToFailureTransportStamp($originalTransport),
                new ErrorDetailsStamp($exceptionClass, 0, $exceptionMessage),
            ]),
        );
    }

    /**
     * Read the current list of message ids from the dashboard's listing
     * endpoint. Closer to how real clients read ids than poking at
     * stamps on dispatched envelopes (Symfony's middleware doesn't always
     * propagate TransportMessageIdStamp back through bus->dispatch).
     *
     * @return list<string>
     */
    protected function listMessageIds(string $transport): array
    {
        $this->client->request('GET', '/admin/messenger-dashboard/transports/' . $transport . '/messages?limit=500');
        $body = $this->decodeJson($this->client->getResponse());

        return array_map(static fn(array $item): string => (string) $item['id'], $body['items']);
    }

    /** @return array<string, mixed> */
    protected function decodeJson(Response $response): array
    {
        $body = (string) $response->getContent();
        $this->assertJson($body, sprintf('Expected JSON response, got: %s', $body));

        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    protected function createStatsSchema(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($em);
        $metas = $em->getMetadataFactory()->getAllMetadata();
        if ($metas !== []) {
            $tool->createSchema($metas);
        }
    }
}
