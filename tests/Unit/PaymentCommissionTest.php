<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Models\Tenant;
use App\Support\PaymentCommission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentCommissionTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $billingModel = 'subscription', float $commissionRate = 0, string $bearer = 'tenant'): Shop
    {
        $tenant = Tenant::create([
            'company_name' => fake()->unique()->company(),
            'owner_email' => fake()->unique()->safeEmail(),
            'billing_model' => $billingModel,
            'commission_rate' => $commissionRate,
            'wallet_commission_bearer' => $bearer,
        ]);

        return Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
    }

    public function test_for_shop_is_unaffected_by_the_new_wallet_checkout_method(): void
    {
        $shop = $this->shop('commission', 10);

        $result = PaymentCommission::forShop($shop, 500);

        $this->assertSame(500.0, $result['gross_amount']);
        $this->assertSame(50.0, $result['platform_fee_amount']);
        $this->assertSame(450.0, $result['tenant_net_amount']);
        $this->assertSame(10.0, $result['commission_rate']);
        $this->assertSame('commission', $result['billing_model']);
        $this->assertArrayNotHasKey('charged_amount', $result);
    }

    public function test_for_wallet_checkout_when_tenant_bears_the_commission(): void
    {
        $shop = $this->shop('commission', 10, 'tenant');

        $result = PaymentCommission::forWalletCheckout($shop, 500);

        $this->assertSame(500.0, $result['charged_amount']);
        $this->assertSame(500.0, $result['gross_amount']);
        $this->assertSame(50.0, $result['platform_fee_amount']);
        $this->assertSame(450.0, $result['tenant_net_amount']);
    }

    public function test_for_wallet_checkout_when_customer_bears_the_commission(): void
    {
        $shop = $this->shop('commission', 10, 'customer');

        $result = PaymentCommission::forWalletCheckout($shop, 500);

        $this->assertSame(550.0, $result['charged_amount']);
        $this->assertSame(550.0, $result['gross_amount']);
        $this->assertSame(50.0, $result['platform_fee_amount']);
        $this->assertSame(500.0, $result['tenant_net_amount']);
    }

    public function test_for_wallet_checkout_with_zero_commission_charges_the_package_price_in_either_mode(): void
    {
        $tenantBears = $this->shop('commission', 0, 'tenant');
        $customerBears = $this->shop('commission', 0, 'customer');

        $tenantResult = PaymentCommission::forWalletCheckout($tenantBears, 500);
        $customerResult = PaymentCommission::forWalletCheckout($customerBears, 500);

        $this->assertSame(500.0, $tenantResult['charged_amount']);
        $this->assertSame(0.0, $tenantResult['platform_fee_amount']);
        $this->assertSame(500.0, $tenantResult['tenant_net_amount']);

        $this->assertSame(500.0, $customerResult['charged_amount']);
        $this->assertSame(0.0, $customerResult['platform_fee_amount']);
        $this->assertSame(500.0, $customerResult['tenant_net_amount']);
    }
}
