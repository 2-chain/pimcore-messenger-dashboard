<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Functional;

use Pimcore\Model\User;
use Symfony\Component\HttpFoundation\Request;
use TwoChain\PimcoreMessengerDashboardBundle\Controller\DashboardController;
use Override;

/**
 * Functional-test stand-in for the production controller.
 *
 * Overrides the protected `currentUser()` so the controller doesn't try
 * to authenticate against a Pimcore session that doesn't exist in this
 * minimal test kernel. Permission decisions are delegated to
 * {@see TestablePermissionChecker}, which the production PermissionChecker
 * service is replaced with at container-build time.
 */
final class TestableDashboardController extends DashboardController
{
    #[Override]
    protected function currentUser(Request $request): ?User
    {
        return null;
    }
}
