<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

/**
 * Converts a SQL LIKE-style pattern into a case-insensitive PCRE regex
 * with equivalent semantics. Used by ListableReceiverAdapter's PHP
 * fallback path so it behaves the same way as DoctrineTransportAdapter's
 * SQL LIKE path.
 *
 *   %     → .*       (zero or more of anything)
 *   _     → .        (exactly one char)
 *   \%    → literal %
 *   \_    → literal _
 *   any other char  → preg_quoted into the regex
 *
 * The regex is un-anchored, so it matches anywhere in the haystack —
 * mirroring the `%…%` substring wrapping the controller applies for the
 * SQL path.
 */
final class LikePatternToRegex
{
    public static function convert(string $pattern): string
    {
        $out = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; ++$i) {
            $c = $pattern[$i];

            if ($c === '\\' && $i + 1 < $len) {
                $next = $pattern[$i + 1];
                if ($next === '%' || $next === '_') {
                    // \% or \_ → literal % or _ in the regex.
                    $out .= preg_quote($next, '/');
                    ++$i;
                    continue;
                }
            }

            if ($c === '%') {
                $out .= '.*';
                continue;
            }

            if ($c === '_') {
                $out .= '.';
                continue;
            }

            $out .= preg_quote($c, '/');
        }

        return '/' . $out . '/iu';
    }
}
