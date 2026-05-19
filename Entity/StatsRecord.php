<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use TwoChain\PimcoreMessengerDashboardBundle\Repository\StatsRecordRepository;
use DateTimeImmutable;

#[ORM\Entity(repositoryClass: StatsRecordRepository::class)]
#[ORM\Table(name: 'messenger_dashboard_stats')]
#[ORM\Index(name: 'idx_transport_handled_at', columns: ['transport', 'handled_at'])]
#[ORM\Index(name: 'idx_transport_status_handled_at', columns: ['transport', 'status', 'handled_at'])]
#[ORM\Index(name: 'idx_handled_at', columns: ['handled_at'])]
class StatsRecord
{
    public const STATUS_HANDLED = 'handled';
    public const STATUS_FAILED = 'failed';

    /**
     * Max length of stored failure message — UTF-8 byte count, not character
     * count. The subscriber truncates with mb_strcut before persisting.
     */
    public const MAX_FAILURE_MESSAGE_BYTES = 4096;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?string $id = null;

    #[ORM\Column(name: 'handled_at', type: 'datetime_immutable')]
    private DateTimeImmutable $handledAt;

    #[ORM\Column(name: 'duration_ms', type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    #[ORM\Column(name: 'retry_count', type: 'smallint', nullable: true, options: ['unsigned' => true])]
    private ?int $retryCount = null;

    #[ORM\Column(name: 'failure_class', type: 'string', length: 255, nullable: true)]
    private ?string $failureClass = null;

    #[ORM\Column(name: 'failure_message', type: 'text', nullable: true)]
    private ?string $failureMessage = null;

    private function __construct(#[ORM\Column(type: 'string', length: 190)]
        private string $transport, #[ORM\Column(name: 'message_class', type: 'string', length: 255)]
        private string $messageClass, #[ORM\Column(type: 'string', length: 16)]
        private string $status)
    {
        $this->handledAt = new DateTimeImmutable();
    }

    public static function handled(
        string $transport,
        string $messageClass,
        ?int $durationMs,
        ?int $retryCount,
    ): self {
        $rec = new self($transport, $messageClass, self::STATUS_HANDLED);
        $rec->durationMs = $durationMs;
        $rec->retryCount = $retryCount;

        return $rec;
    }

    public static function failed(
        string $transport,
        string $messageClass,
        ?int $durationMs,
        ?int $retryCount,
        ?string $failureClass,
        ?string $failureMessage,
    ): self {
        $rec = new self($transport, $messageClass, self::STATUS_FAILED);
        $rec->durationMs = $durationMs;
        $rec->retryCount = $retryCount;
        $rec->failureClass = $failureClass;
        $rec->failureMessage = $failureMessage !== null
            ? mb_strcut($failureMessage, 0, self::MAX_FAILURE_MESSAGE_BYTES, 'UTF-8')
            : null;

        return $rec;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getTransport(): string
    {
        return $this->transport;
    }

    public function getMessageClass(): string
    {
        return $this->messageClass;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getHandledAt(): DateTimeImmutable
    {
        return $this->handledAt;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getRetryCount(): ?int
    {
        return $this->retryCount;
    }

    public function getFailureClass(): ?string
    {
        return $this->failureClass;
    }

    public function getFailureMessage(): ?string
    {
        return $this->failureMessage;
    }
}
