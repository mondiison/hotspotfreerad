<?php

namespace Tests\Unit;

use App\Rules\NigerianPhoneNumber;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NigerianPhoneNumberTest extends TestCase
{
    #[DataProvider('validNumberProvider')]
    public function test_it_accepts_valid_nigerian_numbers(string $input, string $expectedNormalized): void
    {
        $validator = Validator::make(['phone' => $input], ['phone' => [new NigerianPhoneNumber]]);

        $this->assertFalse($validator->fails(), $validator->errors()->first('phone'));
        $this->assertSame($expectedNormalized, NigerianPhoneNumber::normalize($input));
    }

    public static function validNumberProvider(): array
    {
        return [
            'plain local format' => ['08012345678', '08012345678'],
            'with spaces' => ['0801 234 5678', '08012345678'],
            'with dashes' => ['0801-234-5678', '08012345678'],
            'international with plus' => ['+2348012345678', '08012345678'],
            'international without plus' => ['2348012345678', '08012345678'],
            'international with spaces' => ['+234 801 234 5678', '08012345678'],
        ];
    }

    #[DataProvider('invalidNumberProvider')]
    public function test_it_rejects_invalid_numbers(string $input): void
    {
        $validator = Validator::make(['phone' => $input], ['phone' => [new NigerianPhoneNumber]]);

        $this->assertTrue($validator->fails());
    }

    public static function invalidNumberProvider(): array
    {
        return [
            'too short' => ['0801234567'],
            'too long' => ['080123456789'],
            'does not start with zero' => ['18012345678'],
            'landline-style without leading zero' => ['8012345678'],
            'letters' => ['08abc45678'],
        ];
    }
}
