<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Stamp\DashboardRequeueCountStamp;

/**
 * Generic listable-transport adapter. Works with anything that implements
 * Symfony's ListableReceiverInterface (Doctrine transport, in-memory transport,
 * any third-party transport that follows the contract).
 *
 * Capabilities: count + list + inspect + delete-individual.
 * Bulk delete and purge are left to specialized subclasses
 * (DoctrineTransportAdapter overrides with raw-DBAL implementations).
 */
class ListableReceiverAdapter implements TransportAdapterInterface
{
    public function __construct(
        protected readonly string $name,
        protected readonly ListableReceiverInterface $receiver,
        protected readonly string $type = 'unknown',
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function type(): string
    {
        return $this->type;
    }

    #[\Override]
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            canCount: $this->receiver instanceof MessageCountAwareInterface,
            canList: true,
            canInspectIndividual: true,
            canDeleteIndividual: true,
        );
    }

    #[\Override]
    public function count(): int
    {
        if (!$this->receiver instanceof MessageCountAwareInterface) {
            return 0;
        }

        return $this->receiver->getMessageCount();
    }

    #[\Override]
    public function countListable(?string $query = null): int
    {
        $count = 0;
        $regex = $query !== null ? LikePatternToRegex::convert($query) : null;

        foreach ($this->receiver->all($this->fetchCap()) as $envelope) {
            if ($regex === null || $this->envelopeMatches($envelope, $regex)) {
                ++$count;
            }
        }

        return $count;
    }

    #[\Override]
    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        $regex = $query !== null ? LikePatternToRegex::convert($query) : null;

        $matches = [];
        foreach ($this->receiver->all($this->fetchCap()) as $envelope) {
            if ($regex === null || $this->envelopeMatches($envelope, $regex)) {
                $matches[] = $envelope;
                if ($regex === null && count($matches) >= $offset + $limit) {
                    // Fast-exit when no filter is active — stop fetching as
                    // soon as we have enough envelopes to satisfy the page.
                    break;
                }
            }
        }

        $sliced = array_slice($matches, $offset, $limit);

        return array_map([$this, 'envelopeToDescriptor'], $sliced);
    }

    #[\Override]
    public function find(string $id): ?MessageDescriptor
    {
        $envelope = $this->findEnvelope($id);
        if (!$envelope instanceof \Symfony\Component\Messenger\Envelope) {
            return null;
        }

        return $this->envelopeToDescriptor($envelope);
    }

    #[\Override]
    public function findEnvelope(string $id): ?Envelope
    {
        return $this->receiver->find($id);
    }

    #[\Override]
    public function deleteOne(string $id): bool
    {
        $envelope = $this->findEnvelope($id);
        if (!$envelope instanceof \Symfony\Component\Messenger\Envelope) {
            return false;
        }

        $this->receiver->reject($envelope);

        return true;
    }

    #[\Override]
    public function purge(): int
    {
        throw new \LogicException(sprintf('Transport "%s" does not support purge in the generic adapter.', $this->name));
    }

    /**
     * Hard cap on how many envelopes we materialize per request when a
     * filter is active. Avoids unbounded memory on huge queues; documented
     * as "best effort above N" in the spec.
     */
    protected function fetchCap(): int
    {
        return 5000;
    }

    private function envelopeMatches(\Symfony\Component\Messenger\Envelope $envelope, string $regex): bool
    {
        // Match against the message class first (cheap), then the body
        // preview (less cheap because we may have to serialize public
        // properties for non-Stringable messages).
        $message = $envelope->getMessage();
        if (preg_match($regex, $message::class) === 1) {
            return true;
        }

        $body = method_exists($message, '__toString')
            ? (string) $message
            : json_encode($this->summarizeMessage($message), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($body) && preg_match($regex, $body) === 1;
    }

    /**
     * Builds a MessageDescriptor from an Envelope.
     *
     * Symfony's transport receivers don't carry the storage-side creation
     * timestamp back on the envelope they hand us, so subclasses that can
     * recover it (e.g. DoctrineTransportAdapter via a side-channel query)
     * pass it in via $createdAtOverride. Without an override we fall back
     * to the RedeliveryStamp's redelivered-at, then to "now" as a last
     * resort — the fallback is unstable across renders but at least
     * non-null.
     */
    protected function envelopeToDescriptor(Envelope $envelope, ?\DateTimeImmutable $createdAtOverride = null): MessageDescriptor
    {
        $idStamp = $envelope->last(TransportMessageIdStamp::class);
        $id = $idStamp instanceof \Symfony\Component\Messenger\Stamp\StampInterface ? (string) $idStamp->getId() : '';

        $redelivery = $envelope->last(RedeliveryStamp::class);
        $manualRequeues = $envelope->last(DashboardRequeueCountStamp::class);
        $errorDetails = $envelope->last(ErrorDetailsStamp::class);
        $sentToFailure = $envelope->last(SentToFailureTransportStamp::class);

        // Combined attempt count: automatic retries from Symfony's
        // RetryStrategy + dashboard-initiated manual requeues. Either may
        // be null if the message was never retried. We surface null only
        // when BOTH are absent so the column reads "—" instead of "0" for
        // a never-retried message.
        $retries = ($redelivery?->getRetryCount() ?? 0) + ($manualRequeues?->count ?? 0);
        $retryCount = (!$redelivery instanceof \Symfony\Component\Messenger\Stamp\StampInterface && !$manualRequeues instanceof \Symfony\Component\Messenger\Stamp\StampInterface) ? null : $retries;

        $headers = [];
        if ($sentToFailure instanceof \Symfony\Component\Messenger\Stamp\StampInterface) {
            $headers['sentFromTransport'] = $sentToFailure->getOriginalReceiverName();
        }
        if ($manualRequeues instanceof \Symfony\Component\Messenger\Stamp\StampInterface) {
            $headers['manualRequeues'] = $manualRequeues->count;
        }

        $message = $envelope->getMessage();
        $body = method_exists($message, '__toString')
            ? (string) $message
            : json_encode($this->summarizeMessage($message), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return new MessageDescriptor(
            id: $id,
            messageClass: $message::class,
            createdAt: $createdAtOverride ?? $redelivery?->getRedeliveredAt() ?? new \DateTimeImmutable(),
            retryCount: $retryCount,
            headers: $headers,
            bodyPreview: $body !== null ? mb_strcut($body, 0, MessageDescriptor::MAX_BODY_PREVIEW_BYTES, 'UTF-8') : null,
            failureClass: $errorDetails?->getExceptionClass(),
            failureMessage: $errorDetails?->getExceptionMessage(),
        );
    }

    /**
     * Best-effort scalar/array snapshot for the body preview when the message
     * isn't \Stringable. Picks public properties and primitive values.
     */
    protected function summarizeMessage(object $message): array
    {
        $out = [];
        foreach ((new \ReflectionObject($message))->getProperties() as $prop) {
            if (!$prop->isInitialized($message)) {
                continue;
            }
            $value = $prop->getValue($message);
            $out[$prop->getName()] = match (true) {
                $value === null, is_scalar($value) => $value,
                is_array($value) => $value,
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \UnitEnum => $value->name,
                $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
                default => '[' . \get_debug_type($value) . ']',
            };
        }

        return $out;
    }
}
