<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service;

use Pimcore\Model\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Single point of policy for the dashboard's two permissions:
 *
 *  - messenger_dashboard_view  → see transports, messages, failed list, stats
 *  - messenger_dashboard_edit  → delete, requeue, purge (implies view)
 *
 * Pimcore admins (User::isAdmin() === true) bypass both checks.
 */
class PermissionChecker
{
    public function canView(?User $user): bool
    {
        if (!$user instanceof \Pimcore\Model\User) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isAllowed('messenger_dashboard_view')
            || $user->isAllowed('messenger_dashboard_edit'); // edit implies view
    }

    public function canEdit(?User $user): bool
    {
        if (!$user instanceof \Pimcore\Model\User) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isAllowed('messenger_dashboard_edit');
    }

    public function assertView(?User $user): void
    {
        if (!$this->canView($user)) {
            throw new AccessDeniedHttpException('Requires messenger_dashboard_view permission.');
        }
    }

    public function assertEdit(?User $user): void
    {
        if (!$this->canEdit($user)) {
            throw new AccessDeniedHttpException('Requires messenger_dashboard_edit permission.');
        }
    }
}
