<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_sees_only_own_shop_payment_settings(): void
    {
        [$tenantOne, $tenantTwo] = $this->tenants();
        $ownShop = $this->shop($tenantOne, 'Own Shop', true);
        $otherShop = $this->shop($tenantTwo, 'Other Shop', false);

        $user = User::factory()->create([
            'tenant_id' => $tenantOne->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.payment-settings.index'))
            ->assertOk()
            ->assertSee('Payment Setup')
            ->assertSee($ownShop->name)
            ->assertSee('Payments configured')
            ->assertDontSee($otherShop->name);
    }

    public function test_tenant_admin_can_update_own_shop_payment_settings(): void
    {
        [$tenant] = $this->tenants();
        $shop = $this->shop($tenant, 'Main Shop', false);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('admin.payment-settings.update', $shop), [
                'flutterwave_client_id' => 'tenant-client-id',
                'flutterwave_client_secret' => 'tenant-client-secret',
                'flutterwave_webhook_secret' => 'tenant-webhook-secret',
            ])
            ->assertRedirect(route('admin.payment-settings.index'));

        $shop->refresh();

        $this->assertTrue($shop->hasCompleteFlutterwaveCredentials());
        $this->assertTrue($shop->hasFlutterwaveWebhookSecret());
        $this->assertSame('tenant-client-id', $shop->flutterwave_client_id);
        $this->assertSame('tenant-client-secret', $shop->flutterwave_client_secret);
        $this->assertSame('tenant-webhook-secret', $shop->flutterwave_webhook_secret);
    }

    public function test_tenant_admin_cannot_update_another_tenants_shop_payment_settings(): void
    {
        [$tenantOne, $tenantTwo] = $this->tenants();
        $otherShop = $this->shop($tenantTwo, 'Other Shop', false);
        $user = User::factory()->create([
            'tenant_id' => $tenantOne->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('admin.payment-settings.update', $otherShop), [
                'flutterwave_client_id' => 'blocked-client-id',
                'flutterwave_client_secret' => 'blocked-client-secret',
            ])
            ->assertForbidden();

        $this->assertNull($otherShop->refresh()->flutterwave_client_id);
    }

    public function test_client_credentials_must_be_saved_together(): void
    {
        [$tenant] = $this->tenants();
        $shop = $this->shop($tenant, 'Main Shop', false);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('admin.payment-settings.index'))
            ->put(route('admin.payment-settings.update', $shop), [
                'flutterwave_client_id' => 'tenant-client-id',
            ])
            ->assertRedirect(route('admin.payment-settings.index'))
            ->assertSessionHasErrors('flutterwave_client_secret');
    }

    private function tenants(): array
    {
        return [
            Tenant::create([
                'company_name' => 'Tenant One',
                'owner_email' => 'one@example.com',
            ]),
            Tenant::create([
                'company_name' => 'Tenant Two',
                'owner_email' => 'two@example.com',
            ]),
        ];
    }

    private function shop(Tenant $tenant, string $name, bool $configured): Shop
    {
        return Shop::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'is_active' => true,
            'flutterwave_client_id' => $configured ? 'client-id' : null,
            'flutterwave_client_secret' => $configured ? 'client-secret' : null,
            'flutterwave_webhook_secret' => $configured ? 'webhook-secret' : null,
        ]);
    }
}
