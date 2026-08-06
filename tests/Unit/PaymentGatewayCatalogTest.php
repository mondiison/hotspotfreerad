<?php

namespace Tests\Unit;

use App\Support\PaymentGatewayCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentGatewayCatalogTest extends TestCase
{
    #[DataProvider('gatewayProvider')]
    public function test_it_returns_walled_garden_hosts_per_gateway(?string $gateway, array $expected): void
    {
        $this->assertSame($expected, PaymentGatewayCatalog::walledGardenHosts($gateway));
    }

    public static function gatewayProvider(): array
    {
        return [
            'flutterwave' => [PaymentGatewayCatalog::FLUTTERWAVE, ['*.flutterwave.com', '*.ravepay.co']],
            'paystack' => [PaymentGatewayCatalog::PAYSTACK, ['*.paystack.com', '*.paystack.co']],
            'monnify' => [PaymentGatewayCatalog::MONNIFY, ['*.monnify.com']],
            'squad' => [PaymentGatewayCatalog::SQUAD, ['*.squadco.com']],
            'stripe' => [PaymentGatewayCatalog::STRIPE, ['*.stripe.com', '*.stripe.network']],
            'manual bank transfer needs no external walled-garden host' => [PaymentGatewayCatalog::MANUAL_BANK, []],
            'null falls back to flutterwave' => [null, ['*.flutterwave.com', '*.ravepay.co']],
            'unknown key falls back to flutterwave' => ['not-a-real-gateway', ['*.flutterwave.com', '*.ravepay.co']],
        ];
    }

    #[DataProvider('secretKeyProvider')]
    public function test_it_detects_environment_from_secret_key_prefix(?string $secretKey, ?string $expected): void
    {
        $this->assertSame($expected, PaymentGatewayCatalog::detectedEnvironment($secretKey));
    }

    public static function secretKeyProvider(): array
    {
        return [
            'live prefix' => ['sk_live_abc123', 'live'],
            'test prefix' => ['sk_test_abc123', 'test'],
            'null key' => [null, null],
            'blank key' => ['', null],
            'unrecognized prefix' => ['pk_live_abc123', null],
        ];
    }
}
