<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Controller;

use Pimcore\Tool\Authentication;
use TwoChain\PimcoreMessengerDashboardBundle\Repository\StatsRecordRepository;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\MessageDescriptor;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\TransportAdapterInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\MessageOperations;
use TwoChain\PimcoreMessengerDashboardBundle\Service\PermissionChecker;
use TwoChain\PimcoreMessengerDashboardBundle\Service\TransportRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * JSON REST API for the admin dashboard. All routes are under
 * `/admin/messenger-dashboard/*` and behind Pimcore's admin firewall.
 *
 * Every action calls PermissionChecker::assertView() or assertEdit() as its
 * first line. Permission failures translate to AccessDeniedHttpException →
 * Symfony 403 → standard JSON error envelope on the way out.
 */
#[Route('/admin/messenger-dashboard', name: 'twochain_messenger_dashboard_')]
class DashboardController extends AbstractController
{
    /**
     * Server-side cap on how many failed envelopes we materialize when the
     * user is filtering by class. Above this threshold the filter becomes
     * "best effort" — older messages may not appear in the filtered list.
     * Dashboards in practice have far fewer failed messages than this; the
     * cap exists to bound memory in pathological cases.
     */
    private const int FILTER_FETCH_CAP = 5000;

    /**
     * Hard cap on the length of the free-text `q` search filter. Bounds URL
     * size and prevents pathological LIKE patterns from reaching the DB.
     */
    private const int MAX_QUERY_LENGTH = 1024;

    public function __construct(
        private readonly TransportRegistry $registry,
        private readonly StatsRecordRepository $stats,
        private readonly MessageOperations $operations,
        private readonly PermissionChecker $permissionChecker,
        private readonly ?string $failedTransportName = null,
    ) {
    }

    #[Route('/transports', name: 'transports', methods: ['GET'])]
    public function listTransports(Request $request): JsonResponse
    {
        $this->permissionChecker->assertView($this->currentUser($request));

        $result = [];
        foreach ($this->registry->adapters() as $adapter) {
            $result[] = $this->transportSummary($adapter);
        }

        return new JsonResponse($result);
    }

    #[Route('/transports/{name}/messages', name: 'transport_messages', methods: ['GET'])]
    public function listMessages(Request $request, string $name): JsonResponse
    {
        $this->permissionChecker->assertView($this->currentUser($request));
        $adapter = $this->resolveAdapter($name);
        if (!$adapter->capabilities()->canList) {
            return $this->error('not_supported', 'Transport does not support listing.', 405);
        }
        $offset = max(0, (int) $request->query->get('offset', '0'));
        $limit = min(500, max(1, (int) $request->query->get('limit', '50')));
        try {
            $query = $this->extractSearchQuery($request);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'Search query exceeds the maximum allowed length.', 400);
        }

        $items = array_map(static fn ($d): array => $d->toArray(), $adapter->list($offset, $limit, $query));
        $total = $this->totalListableForPaging($adapter, $query);

        return new JsonResponse([
            'transport' => $name,
            'offset' => $offset,
            'limit' => $limit,
            'total' => $total,
            'query' => $query,
            'items' => $items,
        ]);
    }

    #[Route('/transports/{name}/messages/{id}', name: 'transport_message_show', methods: ['GET'])]
    public function showMessage(Request $request, string $name, string $id): JsonResponse
    {
        $this->permissionChecker->assertView($this->currentUser($request));
        $adapter = $this->resolveAdapter($name);
        if (!$adapter->capabilities()->canInspectIndividual) {
            return $this->error('not_supported', 'Transport does not support per-message inspection.', 405);
        }
        $desc = $adapter->find($id);
        if (!$desc instanceof MessageDescriptor) {
            return $this->error('not_found', sprintf('Message %s not found in transport %s.', $id, $name), 404);
        }

        return new JsonResponse($desc->toArray());
    }

    #[Route('/transports/{name}/messages/{id}', name: 'transport_message_delete', methods: ['DELETE'])]
    public function deleteMessage(Request $request, string $name, string $id): JsonResponse
    {
        $this->permissionChecker->assertEdit($this->currentUser($request));
        $adapter = $this->resolveAdapter($name);
        if (!$adapter->capabilities()->canDeleteIndividual) {
            return $this->error('not_supported', 'Transport does not support per-message delete.', 405);
        }
        if (!$adapter->deleteOne($id)) {
            return $this->error('not_found', sprintf('Message %s not found in transport %s.', $id, $name), 404);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/transports/{name}/messages/bulk-delete', name: 'transport_messages_bulk_delete', methods: ['POST'])]
    public function bulkDeleteMessages(Request $request, string $name): JsonResponse
    {
        $this->permissionChecker->assertEdit($this->currentUser($request));
        $this->resolveAdapter($name); // 404 if not configured
        $payload = $this->decodePayload($request);

        if (($payload['all'] ?? false) === true) {
            return new JsonResponse($this->operations->purge($name));
        }
        $ids = $payload['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->error('invalid_payload', 'Expected non-empty {ids: [...]} or {all: true}.', 400);
        }

        return new JsonResponse($this->operations->deleteMany($name, array_values(array_map('strval', $ids))));
    }

    #[Route('/failed/messages', name: 'failed_list', methods: ['GET'])]
    public function listFailed(Request $request): JsonResponse
    {
        $this->permissionChecker->assertView($this->currentUser($request));
        $failed = $this->resolveFailedAdapter();
        if (!$failed->capabilities()->canList) {
            return $this->error('not_supported', 'Failed transport does not support listing.', 405);
        }
        $offset = max(0, (int) $request->query->get('offset', '0'));
        $limit = min(500, max(1, (int) $request->query->get('limit', '50')));
        $classFilter = (string) $request->query->get('messageClass', '');
        $classFilter = $classFilter !== '' ? $classFilter : null;
        try {
            $query = $this->extractSearchQuery($request);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'Search query exceeds the maximum allowed length.', 400);
        }

        if ($classFilter !== null) {
            // Fetch up to 5000 envelopes, filter in PHP, then slice. Pagination
            // stays correct because total reflects the filtered count. The
            // free-text `q` filter is applied at the adapter layer so the
            // server-side cap is spent on rows that already match the search.
            $allDescriptors = $failed->list(0, self::FILTER_FETCH_CAP, $query);
            $matches = array_values(array_filter(
                $allDescriptors,
                static fn ($d): bool => $d->messageClass === $classFilter,
            ));
            $items = array_map(
                static fn ($d): array => $d->toArray(),
                array_slice($matches, $offset, $limit),
            );
            $total = count($matches);
        } else {
            $items = array_map(static fn ($d): array => $d->toArray(), $failed->list($offset, $limit, $query));
            $total = $this->totalListableForPaging($failed, $query);
        }

        return new JsonResponse([
            'transport' => $failed->name(),
            'offset' => $offset,
            'limit' => $limit,
            'total' => $total,
            'query' => $query,
            'items' => $items,
        ]);
    }

    /**
     * Distinct message classes currently sitting in the failed transport.
     * Used by the failed-detail panel to populate its class-filter combo.
     */
    #[Route('/failed/message-classes', name: 'failed_classes', methods: ['GET'])]
    public function failedMessageClasses(Request $request): JsonResponse
    {
        $this->permissionChecker->assertView($this->currentUser($request));
        $failed = $this->resolveFailedAdapter();
        if (!$failed->capabilities()->canList) {
            return new JsonResponse(['classes' => []]);
        }

        $classes = [];
        foreach ($failed->list(0, self::FILTER_FETCH_CAP) as $descriptor) {
            $classes[$descriptor->messageClass] = true;
        }
        $names = array_keys($classes);
        sort($names);

        return new JsonResponse(['classes' => $names]);
    }

    #[Route('/failed/messages/{id}', name: 'failed_show', methods: ['GET'])]
    public function showFailed(Request $request, string $id): JsonResponse
    {
        $this->permissionChecker->assertView($this->currentUser($request));
        $failed = $this->resolveFailedAdapter();
        $desc = $failed->find($id);
        if (!$desc instanceof MessageDescriptor) {
            return $this->error('not_found', sprintf('Failed message %s not found.', $id), 404);
        }

        return new JsonResponse($desc->toArray());
    }

    #[Route('/failed/messages/{id}', name: 'failed_delete', methods: ['DELETE'])]
    public function deleteFailed(Request $request, string $id): JsonResponse
    {
        $this->permissionChecker->assertEdit($this->currentUser($request));
        $failed = $this->resolveFailedAdapter();
        if (!$failed->deleteOne($id)) {
            return $this->error('not_found', sprintf('Failed message %s not found.', $id), 404);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/failed/messages/{id}/requeue', name: 'failed_requeue', methods: ['POST'])]
    public function requeueFailed(Request $request, string $id): JsonResponse
    {
        $this->permissionChecker->assertEdit($this->currentUser($request));
        $failed = $this->resolveFailedAdapter();
        $result = $this->operations->requeueMany($failed->name(), [$id]);

        if ($result['processed'] === 1) {
            return new JsonResponse(null, Response::HTTP_ACCEPTED);
        }
        $reason = $result['failed'][0]['reason'] ?? 'unknown';

        return $this->error('requeue_failed', $reason, 400);
    }

    #[Route('/failed/messages/bulk-delete', name: 'failed_bulk_delete', methods: ['POST'])]
    public function bulkDeleteFailed(Request $request): JsonResponse
    {
        $this->permissionChecker->assertEdit($this->currentUser($request));
        $failed = $this->resolveFailedAdapter();
        $payload = $this->decodePayload($request);

        if (($payload['all'] ?? false) === true) {
            return new JsonResponse($this->operations->purge($failed->name()));
        }
        $ids = $payload['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->error('invalid_payload', 'Expected non-empty {ids: [...]} or {all: true}.', 400);
        }

        return new JsonResponse($this->operations->deleteMany($failed->name(), array_values(array_map('strval', $ids))));
    }

    #[Route('/failed/messages/bulk-requeue', name: 'failed_bulk_requeue', methods: ['POST'])]
    public function bulkRequeueFailed(Request $request): JsonResponse
    {
        $this->permissionChecker->assertEdit($this->currentUser($request));
        $failed = $this->resolveFailedAdapter();
        $payload = $this->decodePayload($request);

        if (($payload['all'] ?? false) === true) {
            return new JsonResponse($this->operations->requeueAll($failed->name()));
        }
        $ids = $payload['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->error('invalid_payload', 'Expected non-empty {ids: [...]} or {all: true}.', 400);
        }

        return new JsonResponse($this->operations->requeueMany($failed->name(), array_values(array_map('strval', $ids))));
    }

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $this->permissionChecker->assertView($this->currentUser($request));

        $rawWindows = (string) $request->query->get('windows', '1h,12h,24h');
        $windows = array_filter(array_map('trim', explode(',', $rawWindows)));
        $now = new \DateTimeImmutable();

        $result = [];
        foreach ($this->registry->adapters() as $adapter) {
            $entry = ['lastHandledAt' => $this->stats->lastHandledAt($adapter->name())?->format(\DateTimeInterface::ATOM)];
            foreach ($windows as $w) {
                $since = $this->resolveWindow($now, $w);
                if (!$since instanceof \DateTimeImmutable) {
                    continue;
                }
                $entry[$w] = $this->stats->countSplit($adapter->name(), $since);
            }
            $result[$adapter->name()] = $entry;
        }

        return new JsonResponse($result);
    }

    // ---------------- helpers ----------------

    /** @return array<string, mixed> */
    private function transportSummary(TransportAdapterInterface $adapter): array
    {
        return [
            'name' => $adapter->name(),
            'type' => $adapter->type(),
            'capabilities' => $adapter->capabilities()->toArray(),
            'count' => $this->safeCount($adapter),
            'lastHandledAt' => $this->stats->lastHandledAt($adapter->name())?->format(\DateTimeInterface::ATOM),
            'isFailedTransport' => $this->failedTransportName !== null
                && $adapter->name() === $this->failedTransportName,
        ];
    }

    private function safeCount(TransportAdapterInterface $adapter): int|string
    {
        try {
            return $adapter->count();
        } catch (\Throwable) {
            return 'unavailable';
        }
    }

    /**
     * Integer total used as `totalProperty` by the ExtJS pager. If the
     * transport's backend is unreachable, return 0 so the grid shows an
     * empty page instead of "Page X of NaN".
     */
    private function totalForPaging(TransportAdapterInterface $adapter): int
    {
        $count = $this->safeCount($adapter);

        return is_int($count) ? $count : 0;
    }

    /**
     * Integer total for the listable+filtered view. Mirrors totalForPaging()
     * but routes through countListable($query). Transports that don't
     * support listable counts (Amqp/Sqs/Beanstalkd/Redis) throw
     * \LogicException — for those we fall back to the unfiltered live
     * count, and the search filter is effectively ignored. Those transports
     * are already hidden from the dashboard's clickable listing UI, so
     * this is a defensive path.
     */
    private function totalListableForPaging(TransportAdapterInterface $adapter, ?string $query): int
    {
        try {
            return $adapter->countListable($query);
        } catch (\LogicException) {
            // Transport doesn't support filtered listing — fall back to its
            // unfiltered live count. Search is ignored for these transports.
            return $this->totalForPaging($adapter);
        }
    }

    /**
     * Extract the optional `q` search filter from the request. Trims, treats
     * empty strings as "no filter" (returns null), and enforces a hard cap
     * on length to avoid pathological LIKE patterns and oversized URLs.
     *
     * Throws \InvalidArgumentException with code "query_too_long" if the
     * query exceeds MAX_QUERY_LENGTH characters — the caller catches and
     * returns a 400 with that error code.
     */
    private function extractSearchQuery(Request $request): ?string
    {
        $raw = $request->query->get('q');
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_QUERY_LENGTH) {
            throw new \InvalidArgumentException('query_too_long');
        }

        return $trimmed;
    }

    private function resolveAdapter(string $name): TransportAdapterInterface
    {
        if (!in_array($name, $this->registry->names(), true)) {
            throw new NotFoundHttpException(sprintf('Transport "%s" is not configured.', $name));
        }

        return $this->registry->adapter($name);
    }

    private function resolveFailedAdapter(): TransportAdapterInterface
    {
        if ($this->failedTransportName === null) {
            throw new NotFoundHttpException('No failed transport is configured (set framework.messenger.failure_transport).');
        }
        if (!in_array($this->failedTransportName, $this->registry->names(), true)) {
            throw new NotFoundHttpException(sprintf('Configured failed transport "%s" is not registered.', $this->failedTransportName));
        }

        return $this->registry->adapter($this->failedTransportName);
    }

    /** @return array<string, mixed> */
    private function decodePayload(Request $request): array
    {
        $body = (string) $request->getContent();
        if ($body === '') {
            return [];
        }
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new NotFoundHttpException('Invalid JSON body.');
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveWindow(\DateTimeImmutable $now, string $window): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d+)([hdm])$/', $window, $m)) {
            return null;
        }
        $unit = match ($m[2]) {
            'h' => 'hour',
            'd' => 'day',
            'm' => 'minute',
        };

        return $now->modify(sprintf('-%d %s', (int) $m[1], $unit));
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }

    protected function currentUser(Request $request): ?\Pimcore\Model\User
    {
        // Pimcore's session-based admin authentication. Returns null for
        // unauthenticated requests; the admin firewall will already have
        // bounced those, but null-handling keeps the type-system honest.
        return Authentication::authenticateSession($request);
    }
}
