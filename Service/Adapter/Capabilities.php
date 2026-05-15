<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

/**
 * Immutable capability matrix per transport adapter. The UI reads these flags
 * via the REST API to enable/disable destructive controls so the user never
 * sees a button that will 405 on click.
 */
final readonly class Capabilities
{
    public function __construct(
        public bool $canCount = false,
        public bool $canList = false,
        public bool $canInspectIndividual = false,
        public bool $canDeleteIndividual = false,
        public bool $canBulkDelete = false,
        public bool $canPurge = false,
        public bool $canRequeue = false,
    ) {
    }

    public static function countOnly(): self
    {
        return new self(canCount: true);
    }

    public static function full(): self
    {
        return new self(
            canCount: true,
            canList: true,
            canInspectIndividual: true,
            canDeleteIndividual: true,
            canBulkDelete: true,
            canPurge: true,
            canRequeue: true,
        );
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'canCount' => $this->canCount,
            'canList' => $this->canList,
            'canInspectIndividual' => $this->canInspectIndividual,
            'canDeleteIndividual' => $this->canDeleteIndividual,
            'canBulkDelete' => $this->canBulkDelete,
            'canPurge' => $this->canPurge,
            'canRequeue' => $this->canRequeue,
        ];
    }
}
