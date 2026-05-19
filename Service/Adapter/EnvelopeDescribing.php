<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use TwoChain\PimcoreMessengerDashboardBundle\Stamp\DashboardRequeueCountStamp;
use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionObject;
use UnitEnum;

/**
 * Shared envelope→descriptor logic. Used by every adapter that surfaces a
 * Symfony Messenger envelope through the dashboard's MessageDescriptor DTO,
 * so the dashboard renders the same shape regardless of which transport
 * produced the envelope.
 */
trait EnvelopeDescribing
{
    /**
     * Substring-match an envelope against a regex pattern.
     *
     * Tries cheap matches first (message class FQCN, then ErrorDetailsStamp's
     * exception class/message if the envelope came from a failure transport)
     * and falls back to the serialized body preview.
     *
     * Including ErrorDetailsStamp here means failed-transport searches can
     * find messages by their exception text, not just by their message body.
     */
    protected function envelopeMatches(Envelope $envelope, string $regex): bool
    {
        $message = $envelope->getMessage();
        if (preg_match($regex, $message::class) === 1) {
            return true;
        }

        $errorDetails = $envelope->last(ErrorDetailsStamp::class);
        if ($errorDetails instanceof ErrorDetailsStamp) {
            if (preg_match($regex, $errorDetails->getExceptionClass()) === 1
                || preg_match($regex, $errorDetails->getExceptionMessage()) === 1) {
                return true;
            }
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
     * timestamp back on the envelope, so subclasses that can recover it
     * (e.g. DoctrineTransportAdapter via a side-channel query) pass it in
     * via $createdAtOverride. Without an override we fall back to the
     * RedeliveryStamp's redelivered-at, then to "now" as a last resort —
     * the fallback is unstable across renders but at least non-null.
     */
    protected function envelopeToDescriptor(Envelope $envelope, ?DateTimeImmutable $createdAtOverride = null): MessageDescriptor
    {
        $idStamp = $envelope->last(TransportMessageIdStamp::class);
        $id = $idStamp instanceof StampInterface ? (string) $idStamp->getId() : '';

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
        $retryCount = (!$redelivery instanceof StampInterface && !$manualRequeues instanceof StampInterface) ? null : $retries;

        $headers = [];
        if ($sentToFailure instanceof StampInterface) {
            $headers['sentFromTransport'] = $sentToFailure->getOriginalReceiverName();
        }
        if ($manualRequeues instanceof StampInterface) {
            $headers['manualRequeues'] = $manualRequeues->count;
        }

        $message = $envelope->getMessage();
        $body = method_exists($message, '__toString')
            ? (string) $message
            : json_encode($this->summarizeMessage($message), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return new MessageDescriptor(
            id: $id,
            messageClass: $message::class,
            createdAt: $this->resolveCreatedAt($createdAtOverride, $redelivery?->getRedeliveredAt()),
            retryCount: $retryCount,
            headers: $headers,
            bodyPreview: is_string($body) ? mb_strcut($body, 0, MessageDescriptor::MAX_BODY_PREVIEW_BYTES, 'UTF-8') : null,
            failureClass: $errorDetails?->getExceptionClass(),
            failureMessage: $errorDetails?->getExceptionMessage(),
        );
    }

    /**
     * RedeliveryStamp's `getRedeliveredAt()` is typed `\DateTimeInterface`,
     * but our DTO insists on `\DateTimeImmutable`. Convert when needed and
     * never propagate a mutable instance into the descriptor.
     */
    private function resolveCreatedAt(?DateTimeImmutable $override, ?DateTimeInterface $redelivered): DateTimeImmutable
    {
        if ($override !== null) {
            return $override;
        }
        if ($redelivered === null) {
            return new DateTimeImmutable();
        }

        return $redelivered instanceof DateTimeImmutable
            ? $redelivered
            : DateTimeImmutable::createFromInterface($redelivered);
    }

    /**
     * Best-effort scalar/array snapshot for the body preview when the message
     * isn't \Stringable. Picks public properties and primitive values.
     *
     * @return array<string, mixed>
     */
    protected function summarizeMessage(object $message): array
    {
        $out = [];
        foreach ((new ReflectionObject($message))->getProperties() as $prop) {
            if (!$prop->isInitialized($message)) {
                continue;
            }
            $value = $prop->getValue($message);
            $out[$prop->getName()] = match (true) {
                $value === null, is_scalar($value) => $value,
                is_array($value) => $value,
                $value instanceof BackedEnum => $value->value,
                $value instanceof UnitEnum => $value->name,
                $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
                default => '[' . \get_debug_type($value) . ']',
            };
        }

        return $out;
    }
}
