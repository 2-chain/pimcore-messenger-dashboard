<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\LikePatternToRegex;

final class LikePatternToRegexTest extends TestCase
{
    public function testPlainSubstring(): void
    {
        $regex = LikePatternToRegex::convert('order');
        $this->assertSame(1, preg_match($regex, 'has order somewhere'));
        $this->assertSame(0, preg_match($regex, 'has nothing'));
    }

    public function testCaseInsensitive(): void
    {
        $regex = LikePatternToRegex::convert('order');
        $this->assertSame(1, preg_match($regex, 'has ORDER somewhere'));
    }

    public function testPercentIsWildcard(): void
    {
        $regex = LikePatternToRegex::convert('order%done');
        $this->assertSame(1, preg_match($regex, 'order whatever done'));
        $this->assertSame(1, preg_match($regex, 'orderdone'));
        $this->assertSame(0, preg_match($regex, 'done order'));
    }

    public function testUnderscoreIsSingleChar(): void
    {
        $regex = LikePatternToRegex::convert('a_b');
        $this->assertSame(1, preg_match($regex, 'aXb'));
        $this->assertSame(1, preg_match($regex, 'a-b'));
        $this->assertSame(0, preg_match($regex, 'aXXb'));
        $this->assertSame(0, preg_match($regex, 'ab'));
    }

    public function testEscapedPercentMatchesLiteralPercent(): void
    {
        $regex = LikePatternToRegex::convert('100\\%');
        $this->assertSame(1, preg_match($regex, 'paid 100% off'));
        $this->assertSame(0, preg_match($regex, 'paid 100 off'));
    }

    public function testEscapedUnderscoreMatchesLiteralUnderscore(): void
    {
        $regex = LikePatternToRegex::convert('foo\\_bar');
        $this->assertSame(1, preg_match($regex, 'foo_bar'));
        $this->assertSame(0, preg_match($regex, 'fooXbar'));
    }

    public function testRegexSpecialsAreEscaped(): void
    {
        $regex = LikePatternToRegex::convert('a.b+c?');
        $this->assertSame(1, preg_match($regex, 'something a.b+c? somewhere'));
        $this->assertSame(0, preg_match($regex, 'somethingabc'));
    }

    public function testBackslashWithoutWildcardIsLiteralBackslash(): void
    {
        // A bare backslash (not followed by % or _) is preserved as a
        // literal backslash in the matched text.
        $regex = LikePatternToRegex::convert('foo\\bar');
        $this->assertSame(1, preg_match($regex, 'foo\\bar'));
    }

    public function testEmptyStringMatchesEverything(): void
    {
        // Defensive: empty input shouldn't crash. The normalizer (Task 2)
        // is supposed to nullify empty queries before they reach here, but
        // tolerate the case anyway.
        $regex = LikePatternToRegex::convert('');
        $this->assertSame(1, preg_match($regex, 'anything'));
    }
}
