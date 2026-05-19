<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Pimcore\Model\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use TwoChain\PimcoreMessengerDashboardBundle\Controller\DashboardController;
use TwoChain\PimcoreMessengerDashboardBundle\Entity\StatsRecord;
use TwoChain\PimcoreMessengerDashboardBundle\Repository\StatsRecordRepository;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\Capabilities;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\MessageDescriptor;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\TransportAdapterInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\MessageOperations;
use TwoChain\PimcoreMessengerDashboardBundle\Service\PermissionChecker;
use TwoChain\PimcoreMessengerDashboardBundle\Service\TransportAdapterFactory;
use TwoChain\PimcoreMessengerDashboardBundle\Service\TransportRegistry;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * The controller's only non-unit dependency is Pimcore's session-auth
 * static call. We subclass it to skip that and inject a permissive
 * PermissionChecker so we can exercise route bodies as pure units.
 */
final class DashboardControllerTest extends TestCase
{
    private function controller(
        array $adapters,
        ?string $failedTransportName = null,
        ?ControllerStatsRepo $stats = null,
        ?ControllerMessageOps $operations = null,
        ?NoopPermissionChecker $checker = null,
    ): TestableDashboardController {
        $registry = new ControllerStubRegistry($adapters);
        $stats ??= new ControllerStatsRepo();
        $operations ??= new ControllerMessageOps();
        $checker ??= new NoopPermissionChecker();

        return new TestableDashboardController(
            $registry,
            $stats,
            $operations,
            $checker,
            $failedTransportName,
        );
    }

    private function decode(JsonResponse $response): mixed
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    // ---------- /transports ----------

    public function testListTransportsSerializesEveryAdapter(): void
    {
        $controller = $this->controller([
            new ControllerFakeAdapter('queue_a', 'doctrine', 12, Capabilities::full()),
            new ControllerFakeAdapter('queue_b', 'amqp', 3, Capabilities::countOnly()),
        ], failedTransportName: 'queue_b');

        $response = $controller->listTransports(new Request());

        $body = $this->decode($response);
        $this->assertCount(2, $body);
        $this->assertSame('queue_a', $body[0]['name']);
        $this->assertSame(12, $body[0]['count']);
        $this->assertFalse($body[0]['isFailedTransport']);
        $this->assertTrue($body[1]['isFailedTransport']);
    }

    public function testListTransportsReturnsCountUnavailableWhenAdapterCountThrows(): void
    {
        $broken = new ControllerFakeAdapter('redis_down', 'redis', 0, Capabilities::countOnly());
        $broken->countException = new RuntimeException('connection refused');
        $controller = $this->controller([$broken]);

        $body = $this->decode($controller->listTransports(new Request()));

        $this->assertSame('unavailable', $body[0]['count']);
    }

    public function testListTransportsRequiresViewPermission(): void
    {
        $checker = new NoopPermissionChecker();
        $checker->denyView = true;
        $controller = $this->controller([], checker: $checker);

        $this->expectException(AccessDeniedHttpException::class);
        $controller->listTransports(new Request());
    }

    // ---------- /transports/{name}/messages ----------

    public function testListMessagesReturnsPaginatedResults(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 3, Capabilities::full());
        $adapter->listResults = [
            new MessageDescriptor(id: '1', messageClass: 'A', createdAt: new DateTimeImmutable()),
            new MessageDescriptor(id: '2', messageClass: 'A', createdAt: new DateTimeImmutable()),
            new MessageDescriptor(id: '3', messageClass: 'A', createdAt: new DateTimeImmutable()),
        ];
        $adapter->countListableResult = 3;
        $controller = $this->controller([$adapter]);

        $response = $controller->listMessages(new Request(query: ['limit' => '50']), 'q');

        $body = $this->decode($response);
        $this->assertSame(3, $body['total']);
        $this->assertSame(50, $body['limit']);
        $this->assertCount(3, $body['items']);
    }

    public function testListMessagesClampsLimitAndOffset(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 0, Capabilities::full());
        $controller = $this->controller([$adapter]);

        $body = $this->decode(
            $controller->listMessages(new Request(query: ['offset' => '-5', 'limit' => '99999']), 'q'),
        );

        $this->assertSame(0, $body['offset'], 'negative offset clamped to 0');
        $this->assertSame(500, $body['limit'], 'limit capped to 500');
    }

    public function testListMessagesReturns405WhenAdapterCannotList(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'amqp', 0, Capabilities::countOnly());
        $controller = $this->controller([$adapter]);

        $response = $controller->listMessages(new Request(), 'q');

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('not_supported', $this->decode($response)['error']['code']);
    }

    public function testListMessagesReturns400ForOverlongSearchQuery(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 0, Capabilities::full());
        $controller = $this->controller([$adapter]);
        $tooLong = str_repeat('x', 1025);

        $response = $controller->listMessages(new Request(query: ['q' => $tooLong]), 'q');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('query_too_long', $this->decode($response)['error']['code']);
    }

    public function testListMessagesPropagatesSearchQueryToAdapter(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 0, Capabilities::full());
        $controller = $this->controller([$adapter]);

        $controller->listMessages(new Request(query: ['q' => '  hello  ']), 'q');

        $this->assertSame('hello', $adapter->lastListQuery, 'query is trimmed before reaching the adapter');
    }

    public function testListMessages404sForUnknownTransport(): void
    {
        $controller = $this->controller([]);

        $this->expectException(NotFoundHttpException::class);
        $controller->listMessages(new Request(), 'ghost');
    }

    public function testListMessagesFallsBackToFullCountWhenCountListableThrowsLogicException(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'redis', 7, Capabilities::full());
        $adapter->countListableException = new LogicException('not supported');
        $controller = $this->controller([$adapter]);

        $body = $this->decode($controller->listMessages(new Request(), 'q'));

        $this->assertSame(7, $body['total'], 'falls back to unfiltered count when countListable LogicExceptions');
    }

    // ---------- /transports/{name}/messages/{id} ----------

    public function testShowMessageReturnsDescriptor(): void
    {
        $desc = new MessageDescriptor(id: 'abc', messageClass: 'X', createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 1, Capabilities::full());
        $adapter->findResults = ['abc' => $desc];
        $controller = $this->controller([$adapter]);

        $body = $this->decode($controller->showMessage(new Request(), 'q', 'abc'));

        $this->assertSame('abc', $body['id']);
    }

    public function testShowMessageReturns404WhenNotFound(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 0, Capabilities::full());
        $controller = $this->controller([$adapter]);

        $response = $controller->showMessage(new Request(), 'q', 'ghost');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowMessage405WhenInspectionUnsupported(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'amqp', 0, Capabilities::countOnly());
        $controller = $this->controller([$adapter]);

        $response = $controller->showMessage(new Request(), 'q', 'x');

        $this->assertSame(405, $response->getStatusCode());
    }

    public function testDeleteMessageReturns204OnSuccess(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 1, Capabilities::full());
        $adapter->deleteOneResults = ['abc' => true];
        $controller = $this->controller([$adapter]);

        $response = $controller->deleteMessage(new Request(), 'q', 'abc');

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testDeleteMessageReturns404WhenAdapterReportsMissing(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 0, Capabilities::full());
        $adapter->deleteOneResults = ['abc' => false];
        $controller = $this->controller([$adapter]);

        $response = $controller->deleteMessage(new Request(), 'q', 'abc');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeleteMessage405WhenDeleteUnsupported(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'amqp', 0, Capabilities::countOnly());
        $controller = $this->controller([$adapter]);

        $response = $controller->deleteMessage(new Request(), 'q', 'abc');

        $this->assertSame(405, $response->getStatusCode());
    }

    public function testBulkDeleteIdsReturnsOperationsResult(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 0, Capabilities::full());
        $ops = new ControllerMessageOps();
        $ops->deleteManyResult = ['processed' => 2, 'failed' => []];
        $controller = $this->controller([$adapter], operations: $ops);

        $req = new Request(content: json_encode(['ids' => ['a', 'b']]));
        $body = $this->decode($controller->bulkDeleteMessages($req, 'q'));

        $this->assertSame(2, $body['processed']);
        $this->assertSame('q', $ops->lastDeleteManyTransport);
        $this->assertSame(['a', 'b'], $ops->lastDeleteManyIds);
    }

    public function testBulkDeleteAllDelegatesToPurge(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 0, Capabilities::full());
        $ops = new ControllerMessageOps();
        $ops->purgeResult = ['processed' => 5, 'failed' => []];
        $controller = $this->controller([$adapter], operations: $ops);

        $body = $this->decode(
            $controller->bulkDeleteMessages(new Request(content: json_encode(['all' => true])), 'q'),
        );

        $this->assertSame(5, $body['processed']);
        $this->assertSame('q', $ops->lastPurgeTransport);
    }

    public function testBulkDeleteReturns400ForInvalidPayload(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 0, Capabilities::full());
        $controller = $this->controller([$adapter]);

        $response = $controller->bulkDeleteMessages(new Request(content: '{}'), 'q');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_payload', $this->decode($response)['error']['code']);
    }

    // ---------- /failed/* ----------

    public function testFailedListReturns404WhenNoFailedTransportConfigured(): void
    {
        $controller = $this->controller([], failedTransportName: null);

        $this->expectException(NotFoundHttpException::class);
        $controller->listFailed(new Request());
    }

    public function testFailedList404WhenConfiguredFailedTransportNotRegistered(): void
    {
        $controller = $this->controller([], failedTransportName: 'missing_one');

        $this->expectException(NotFoundHttpException::class);
        $controller->listFailed(new Request());
    }

    public function testFailedListFiltersByClassInPhp(): void
    {
        $failed = new ControllerFakeAdapter('pim_failed', 'doctrine', 0, Capabilities::full());
        $failed->listResults = [
            new MessageDescriptor(id: '1', messageClass: 'A', createdAt: new DateTimeImmutable()),
            new MessageDescriptor(id: '2', messageClass: 'B', createdAt: new DateTimeImmutable()),
            new MessageDescriptor(id: '3', messageClass: 'A', createdAt: new DateTimeImmutable()),
        ];
        $controller = $this->controller([$failed], failedTransportName: 'pim_failed');

        $body = $this->decode(
            $controller->listFailed(new Request(query: ['messageClass' => 'A'])),
        );

        $this->assertSame(2, $body['total']);
        $this->assertCount(2, $body['items']);
        foreach ($body['items'] as $item) {
            $this->assertSame('A', $item['messageClass']);
        }
    }

    public function testFailedMessageClassesReturnsSortedDistinctList(): void
    {
        $failed = new ControllerFakeAdapter('pim_failed', 'doctrine', 0, Capabilities::full());
        $failed->listResults = [
            new MessageDescriptor(id: '1', messageClass: 'Zeta', createdAt: new DateTimeImmutable()),
            new MessageDescriptor(id: '2', messageClass: 'Alpha', createdAt: new DateTimeImmutable()),
            new MessageDescriptor(id: '3', messageClass: 'Alpha', createdAt: new DateTimeImmutable()),
        ];
        $controller = $this->controller([$failed], failedTransportName: 'pim_failed');

        $body = $this->decode($controller->failedMessageClasses(new Request()));

        $this->assertSame(['Alpha', 'Zeta'], $body['classes']);
    }

    public function testFailedRequeueReturns202OnSuccess(): void
    {
        $failed = new ControllerFakeAdapter('pim_failed', 'doctrine', 0, Capabilities::full());
        $ops = new ControllerMessageOps();
        $ops->requeueManyResult = ['processed' => 1, 'failed' => []];
        $controller = $this->controller([$failed], failedTransportName: 'pim_failed', operations: $ops);

        $response = $controller->requeueFailed(new Request(), 'abc');

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('pim_failed', $ops->lastRequeueManyTransport);
        $this->assertSame(['abc'], $ops->lastRequeueManyIds);
    }

    public function testFailedRequeueReturns400WithReasonOnFailure(): void
    {
        $failed = new ControllerFakeAdapter('pim_failed', 'doctrine', 0, Capabilities::full());
        $ops = new ControllerMessageOps();
        $ops->requeueManyResult = ['processed' => 0, 'failed' => [['id' => 'abc', 'reason' => 'message_not_found']]];
        $controller = $this->controller([$failed], failedTransportName: 'pim_failed', operations: $ops);

        $response = $controller->requeueFailed(new Request(), 'abc');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('message_not_found', $this->decode($response)['error']['message']);
    }

    public function testFailedBulkRequeueAllDelegatesToRequeueAll(): void
    {
        $failed = new ControllerFakeAdapter('pim_failed', 'doctrine', 0, Capabilities::full());
        $ops = new ControllerMessageOps();
        $ops->requeueAllResult = ['processed' => 5, 'failed' => []];
        $controller = $this->controller([$failed], failedTransportName: 'pim_failed', operations: $ops);

        $body = $this->decode(
            $controller->bulkRequeueFailed(new Request(content: json_encode(['all' => true]))),
        );

        $this->assertSame(5, $body['processed']);
        $this->assertSame('pim_failed', $ops->lastRequeueAllTransport);
    }

    // ---------- /stats ----------

    public function testStatsReturnsLastHandledAtAndCountSplits(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 0, Capabilities::full());
        $stats = new ControllerStatsRepo();
        $stats->lastHandledAtResults = ['q' => new DateTimeImmutable('2026-05-19T10:00:00+00:00')];
        $stats->countSplitResults = ['q' => ['handled' => 10, 'failed' => 1]];
        $controller = $this->controller([$adapter], stats: $stats);

        $body = $this->decode($controller->stats(new Request(query: ['windows' => '1h'])));

        $this->assertArrayHasKey('q', $body);
        $this->assertSame('2026-05-19T10:00:00+00:00', $body['q']['lastHandledAt']);
        $this->assertSame(['handled' => 10, 'failed' => 1], $body['q']['1h']);
    }

    public function testStatsIgnoresMalformedWindowSpecs(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 0, Capabilities::full());
        $controller = $this->controller([$adapter]);

        $body = $this->decode($controller->stats(new Request(query: ['windows' => 'garbage,1h'])));

        $this->assertArrayNotHasKey('garbage', $body['q']);
        $this->assertArrayHasKey('1h', $body['q']);
    }

    public function testEditOperationsRequireEditPermission(): void
    {
        $adapter = new ControllerFakeAdapter('q', 'doctrine', 0, Capabilities::full());
        $checker = new NoopPermissionChecker();
        $checker->denyEdit = true;
        $controller = $this->controller([$adapter], checker: $checker);

        $this->expectException(AccessDeniedHttpException::class);
        $controller->deleteMessage(new Request(), 'q', 'x');
    }
}

/**
 * DashboardController subclass that bypasses Pimcore's session auth.
 * We never call `Authentication::authenticateSession()` in tests because
 * it requires a booted Pimcore registry.
 */
final class TestableDashboardController extends DashboardController
{
    protected function currentUser(Request $request): ?User
    {
        return null;
    }
}

final class NoopPermissionChecker extends PermissionChecker
{
    public bool $denyView = false;
    public bool $denyEdit = false;

    public function canView(?User $user): bool
    {
        return !$this->denyView;
    }

    public function canEdit(?User $user): bool
    {
        return !$this->denyEdit;
    }

    public function assertView(?User $user): void
    {
        if ($this->denyView) {
            throw new AccessDeniedHttpException('view denied');
        }
    }

    public function assertEdit(?User $user): void
    {
        if ($this->denyEdit) {
            throw new AccessDeniedHttpException('edit denied');
        }
    }
}

final class ControllerStubRegistry extends TransportRegistry
{
    /** @param list<ControllerFakeAdapter> $adapters */
    public function __construct(private readonly array $adapters) {}

    public function names(): array
    {
        return array_map(fn(TransportAdapterInterface $a): string => $a->name(), $this->adapters);
    }

    public function adapter(string $name): TransportAdapterInterface
    {
        foreach ($this->adapters as $a) {
            if ($a->name() === $name) {
                return $a;
            }
        }
        throw new InvalidArgumentException(sprintf('No adapter named %s', $name));
    }

    public function adapters(): iterable
    {
        yield from $this->adapters;
    }
}

final class ControllerFakeAdapter implements TransportAdapterInterface
{
    public ?Throwable $countException = null;
    public ?Throwable $countListableException = null;
    public int $countListableResult = 0;
    /** @var list<MessageDescriptor> */
    public array $listResults = [];
    /** @var array<string, MessageDescriptor> */
    public array $findResults = [];
    /** @var array<string, bool> */
    public array $deleteOneResults = [];
    public ?string $lastListQuery = null;

    public function __construct(
        private readonly string $name,
        private readonly string $type,
        private readonly int $count,
        private readonly Capabilities $capabilities,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function count(): int
    {
        if ($this->countException !== null) {
            throw $this->countException;
        }

        return $this->count;
    }

    public function countListable(?string $query = null): int
    {
        if ($this->countListableException !== null) {
            throw $this->countListableException;
        }

        return $this->countListableResult !== 0 ? $this->countListableResult : count($this->listResults);
    }

    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        $this->lastListQuery = $query;

        return array_slice($this->listResults, $offset, $limit);
    }

    public function find(string $id): ?MessageDescriptor
    {
        return $this->findResults[$id] ?? null;
    }

    public function findEnvelope(string $id): ?Envelope
    {
        return null;
    }

    public function deleteOne(string $id): bool
    {
        return $this->deleteOneResults[$id] ?? false;
    }

    public function purge(): int
    {
        return 0;
    }
}

final class ControllerMessageOps extends MessageOperations
{
    public array $deleteManyResult = ['processed' => 0, 'failed' => []];
    public array $purgeResult = ['processed' => 0, 'failed' => []];
    public array $requeueManyResult = ['processed' => 0, 'failed' => []];
    public array $requeueAllResult = ['processed' => 0, 'failed' => []];

    public ?string $lastDeleteManyTransport = null;
    public ?array $lastDeleteManyIds = null;
    public ?string $lastPurgeTransport = null;
    public ?string $lastRequeueManyTransport = null;
    public ?array $lastRequeueManyIds = null;
    public ?string $lastRequeueAllTransport = null;

    public function __construct()
    {
        // Skip parent constructor — we don't need registry or bus.
    }

    public function deleteMany(string $transportName, array $ids): array
    {
        $this->lastDeleteManyTransport = $transportName;
        $this->lastDeleteManyIds = $ids;

        return $this->deleteManyResult;
    }

    public function purge(string $transportName): array
    {
        $this->lastPurgeTransport = $transportName;

        return $this->purgeResult;
    }

    public function requeueMany(string $failedTransportName, array $ids): array
    {
        $this->lastRequeueManyTransport = $failedTransportName;
        $this->lastRequeueManyIds = $ids;

        return $this->requeueManyResult;
    }

    public function requeueAll(string $failedTransportName): array
    {
        $this->lastRequeueAllTransport = $failedTransportName;

        return $this->requeueAllResult;
    }
}

final class ControllerStatsRepo extends StatsRecordRepository
{
    /** @var array<string, DateTimeImmutable> */
    public array $lastHandledAtResults = [];
    /** @var array<string, array{handled: int, failed: int}> */
    public array $countSplitResults = [];

    public function __construct()
    {
        // Skip parent.
    }

    public function record(StatsRecord $rec): void {}

    public function prune(DateTimeImmutable $before, int $batchSize = 10000): int
    {
        return 0;
    }

    public function countOlderThan(DateTimeImmutable $before): int
    {
        return 0;
    }

    public function lastHandledAt(string $transport): ?DateTimeImmutable
    {
        return $this->lastHandledAtResults[$transport] ?? null;
    }

    public function countSplit(string $transport, DateTimeImmutable $since): array
    {
        return $this->countSplitResults[$transport] ?? ['handled' => 0, 'failed' => 0];
    }
}
