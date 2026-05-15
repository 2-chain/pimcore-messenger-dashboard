<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

/**
 * Adapter-neutral description of a single in-flight message, returned by
 * TransportAdapterInterface::list() and ::find(). The controllers serialize
 * this to JSON for the dashboard UI; nothing transport-specific leaks past
 * this boundary.
 */
final readonly class MessageDescriptor
{
    public const int MAX_BODY_PREVIEW_BYTES = 4096;

    public function __construct(
        public string $id,
        public string $messageClass,
        public \DateTimeImmutable $createdAt,
        public ?int $retryCount = null,
        /** @var array<string, string|int|bool|null> arbitrary transport headers/stamps in flat form */
        public array $headers = [],
        public ?string $bodyPreview = null,
        public ?string $failureClass = null,
        public ?string $failureMessage = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'messageClass' => $this->messageClass,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'retryCount' => $this->retryCount,
            'headers' => $this->headers,
            'bodyPreview' => $this->bodyPreview,
            'failureClass' => $this->failureClass,
            'failureMessage' => $this->failureMessage,
        ];
    }
}
