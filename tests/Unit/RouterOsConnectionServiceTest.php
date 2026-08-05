<?php

namespace Tests\Unit;

use App\Services\RouterOsConnectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RouterOsConnectionServiceTest extends TestCase
{
    #[DataProvider('uptimeProvider')]
    public function test_it_parses_routeros_uptime_strings(string $uptime, int $expectedSeconds): void
    {
        $this->assertSame($expectedSeconds, RouterOsConnectionService::parseRouterOsUptime($uptime));
    }

    public static function uptimeProvider(): array
    {
        return [
            'seconds only' => ['5s', 5],
            'minutes and seconds' => ['4m5s', 245],
            'hours minutes seconds' => ['3h4m5s', 11045],
            'days hours minutes seconds' => ['2d3h4m5s', 183_845],
            'weeks days hours minutes seconds' => ['4w2d3h4m5s', 2_603_045],
            'empty string' => ['', 0],
            'garbage input' => ['not-a-duration', 0],
        ];
    }
}
