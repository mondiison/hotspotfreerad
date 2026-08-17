<?php

namespace Tests\Unit;

use App\Support\CidrOverlap;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CidrOverlapTest extends TestCase
{
    #[DataProvider('overlapProvider')]
    public function test_overlaps(string $cidrA, string $cidrB, bool $expected): void
    {
        $this->assertSame($expected, CidrOverlap::overlaps($cidrA, $cidrB));
    }

    public static function overlapProvider(): array
    {
        return [
            'identical subnets overlap' => ['192.168.10.0/24', '192.168.10.0/24', true],
            'unrelated subnets do not overlap' => ['192.168.10.0/24', '192.168.30.0/24', false],
            'a /25 subset overlaps its parent /24' => ['192.168.10.0/24', '192.168.10.128/25', true],
            'adjacent /25 halves of the same /24 do not overlap each other' => ['192.168.10.0/25', '192.168.10.128/25', false],
            'a single host inside a range overlaps it' => ['10.8.0.0/24', '10.8.0.10/32', true],
            'a bare address (implicit /32) overlaps a containing range' => ['10.8.0.0/24', '10.8.0.10', true],
            'a bare address outside the range does not overlap' => ['10.8.0.0/24', '192.168.10.10', false],
            'invalid address never overlaps' => ['not-an-ip/24', '192.168.10.0/24', false],
        ];
    }
}
