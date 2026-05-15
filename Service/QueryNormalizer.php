<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service;

/**
 * Normalizes a free-form search string from the HTTP layer. Pure static
 * helper — no DI surface, easy to unit-test, used by DashboardController.
 *
 *   null     → null   (no filter)
 *   ""       → null
 *   "  "     → null   (whitespace-only is no filter)
 *   "  x  "  → "x"    (trimmed but otherwise unchanged)
 *
 * Wildcards (`%`, `_`, `\`) pass through unchanged — wildcard semantics
 * are interpreted downstream by the adapter (SQL LIKE or the
 * LikePatternToRegex helper).
 */
final class QueryNormalizer
{
    public static function normalize(?string $q): ?string
    {
        if ($q === null) {
            return null;
        }
        $trimmed = trim($q);

        return $trimmed === '' ? null : $trimmed;
    }
}
