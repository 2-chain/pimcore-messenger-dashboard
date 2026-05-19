<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle;

use Pimcore\Db;
use Pimcore\Extension\Bundle\Installer\SettingsStoreAwareInstaller;
use Override;

final class Installer extends SettingsStoreAwareInstaller
{
    public const string PERMISSION_CATEGORY = 'Messenger Dashboard';

    /**
     * The user permissions this bundle installs into Pimcore's
     * `users_permission_definitions` table. The first grants visibility of
     * the dashboard; the second grants mutating actions (delete, requeue, purge).
     *
     * Pimcore admins (User::isAdmin() === true) bypass both checks via
     * PermissionChecker.
     */
    public const array PERMISSION_KEYS = [
        'messenger_dashboard_view',
        'messenger_dashboard_edit',
    ];

    #[Override]
    public function needsReloadAfterInstall(): bool
    {
        return true;
    }

    #[Override]
    public function install(): void
    {
        $db = Db::get();
        foreach (self::PERMISSION_KEYS as $key) {
            // Pimcore's Definition::create()->save() throws if the key already
            // exists, so use INSERT IGNORE for idempotency.
            $db->executeStatement(
                'INSERT IGNORE INTO users_permission_definitions (`key`, `category`) VALUES (?, ?)',
                [$key, self::PERMISSION_CATEGORY]
            );
        }

        parent::install();
    }

    #[Override]
    public function uninstall(): void
    {
        $db = Db::get();
        foreach (self::PERMISSION_KEYS as $key) {
            $db->executeStatement(
                'DELETE FROM users_permission_definitions WHERE `key` = ?',
                [$key]
            );
        }

        parent::uninstall();
    }
}
