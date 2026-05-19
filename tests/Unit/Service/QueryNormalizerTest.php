<?php
declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreMessengerDashboardBundle\Service\QueryNormalizer;

final class QueryNormalizerTest extends TestCase
{
    public function testNullReturnsNull(): void
    {
        $this->assertNull(QueryNormalizer::normalize(null));
    }

    public function testEmptyStringReturnsNull(): void
    {
        $this->assertNull(QueryNormalizer::normalize(''));
    }

    public function testWhitespaceOnlyReturnsNull(): void
    {
        $this->assertNull(QueryNormalizer::normalize('   '));
        $this->assertNull(QueryNormalizer::normalize("\t \n"));
    }

    public function testContentIsTrimmed(): void
    {
        $this->assertSame('order-123', QueryNormalizer::normalize('  order-123  '));
    }

    public function testInternalWhitespacePreserved(): void
    {
        $this->assertSame('hello world', QueryNormalizer::normalize(' hello world '));
    }

    public function testWildcardsPassedThrough(): void
    {
        $this->assertSame('100\\%', QueryNormalizer::normalize(' 100\\% '));
        $this->assertSame('a_b%c', QueryNormalizer::normalize('a_b%c'));
    }
}
