<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter;

use Symfony\Component\Messenger\Envelope;

/**
 * Best-effort envelope deserializer for Doctrine-stored bodies.
 *
 * Tries the standard PHP `unserialize()` first (which handles bodies written
 * by Symfony's PhpSerializer). If that fails, falls back to
 * `stripslashes()` first — that reverses an addslashes-style escape pass
 * that some projects apply between `serialize($envelope)` and the DB INSERT
 * (e.g. legacy DB writers that double-quote-escape, or migrations that
 * passed the body through `json_encode()` and stripped the outer quotes).
 *
 * Returning null is intentional: callers (list/find/purge) skip rows we
 * can't reconstruct, rather than crashing the whole view.
 */
final class BodyDeserializer
{
    public static function tryDeserialize(string $body): ?Envelope
    {
        $envelope = @unserialize($body);
        if ($envelope instanceof Envelope) {
            return $envelope;
        }

        // stripslashes() reverses addslashes() — restoring `\"` → `"`,
        // `\\` → `\`, and `\0` (backslash + zero) → the actual NUL byte
        // that PHP's serialize() uses to mark private-property names.
        $envelope = @unserialize(stripslashes($body));

        return $envelope instanceof Envelope ? $envelope : null;
    }
}
