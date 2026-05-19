<?php
declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use TwoChain\PimcoreMessengerDashboardBundle\Service\PermissionChecker;

/**
 * The Pimcore\Model\User class is hard to instantiate in a pure unit test
 * (it pulls model events, the resource registry, etc. on construction), so
 * we cover the null-user boundary here. Real user permission semantics are
 * exercised in functional tests against a booted Pimcore kernel.
 */
final class PermissionCheckerTest extends TestCase
{
    public function testNullUserHasNoPermissions(): void
    {
        $checker = new PermissionChecker();

        $this->assertFalse($checker->canView(null));
        $this->assertFalse($checker->canEdit(null));
    }

    public function testAssertViewThrowsForNullUser(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        (new PermissionChecker())->assertView(null);
    }

    public function testAssertEditThrowsForNullUser(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        (new PermissionChecker())->assertEdit(null);
    }
}
