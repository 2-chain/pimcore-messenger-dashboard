<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Functional;

use Pimcore\Model\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use TwoChain\PimcoreMessengerDashboardBundle\Service\PermissionChecker;
use Override;

/**
 * Permission-checker replacement used by functional tests.
 *
 * Defaults to allowing everything so most tests don't need to think
 * about auth. The `denyView` / `denyEdit` flags let permission-gating
 * tests force the production-side AccessDeniedHttpException response
 * without going through a real Pimcore session.
 */
final class TestablePermissionChecker extends PermissionChecker
{
    public bool $denyView = false;
    public bool $denyEdit = false;

    #[Override]
    public function canView(?User $user): bool
    {
        return !$this->denyView;
    }

    #[Override]
    public function canEdit(?User $user): bool
    {
        return !$this->denyEdit;
    }

    #[Override]
    public function assertView(?User $user): void
    {
        if ($this->denyView) {
            throw new AccessDeniedHttpException('view denied');
        }
    }

    #[Override]
    public function assertEdit(?User $user): void
    {
        if ($this->denyEdit) {
            throw new AccessDeniedHttpException('edit denied');
        }
    }
}
