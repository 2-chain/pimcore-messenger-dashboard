<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreMessengerDashboardBundle\Installer;

final class PermissionsTest extends TestCase
{
    public function testDeclaresViewAndEditPermissions(): void
    {
        $permissions = Installer::PERMISSION_KEYS;

        $this->assertSame(
            ['messenger_dashboard_view', 'messenger_dashboard_edit'],
            $permissions
        );
    }
}
