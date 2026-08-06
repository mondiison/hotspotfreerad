<?php

namespace Tests\Feature;

use App\Livewire\Admin\PaymentSettingsCard;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PaymentGatewayCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
            ->assertSee('OPay/transfer ready')
            ->assertSee('Card checkout ready')
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
                'payment_gateway' => 'flutterwave',
                'flutterwave_client_id' => 'tenant-client-id',
                'flutterwave_client_secret' => 'tenant-client-secret',
                'flutterwave_secret_key' => 'FLWSECK_TEST-tenant-secret-key',
                'flutterwave_webhook_secret' => 'tenant-webhook-secret',
            ])
            ->assertRedirect(route('admin.payment-settings.index'));

        $shop->refresh();

        $this->assertTrue($shop->hasCompleteFlutterwaveCredentials());
        $this->assertTrue($shop->hasFlutterwaveHostedCheckoutKey());
        $this->assertTrue($shop->hasFlutterwaveWebhookSecret());
        $this->assertSame('flutterwave', $shop->payment_gateway);
        $this->assertSame('tenant-client-id', $shop->flutterwave_client_id);
        $this->assertSame('tenant-client-secret', $shop->flutterwave_client_secret);
        $this->assertSame('FLWSECK_TEST-tenant-secret-key', $shop->flutterwave_secret_key);
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

    public function test_tenant_admin_can_select_future_shop_gateway_without_credentials(): void
    {
        [$tenant] = $this->tenants();
        $shop = $this->shop($tenant, 'Future Gateway Shop', false);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('admin.payment-settings.update', $shop), [
                'payment_gateway' => 'monnify',
            ])
            ->assertRedirect(route('admin.payment-settings.index'));

        $this->assertSame('monnify', $shop->refresh()->payment_gateway);
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

    public function test_livewire_payment_settings_card_saves_credentials(): void
    {
        [$tenant] = $this->tenants();
        $shop = $this->shop($tenant, 'Livewire Shop', false)->load('tenant');
        $shop->payments_count = 0;
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(PaymentSettingsCard::class, ['shop' => $shop])
            ->set('flutterwave_client_id', 'livewire-client-id')
            ->set('flutterwave_client_secret', 'livewire-client-secret')
            ->set('flutterwave_secret_key', 'FLWSECK_TEST-livewire-secret-key')
            ->set('flutterwave_webhook_secret', 'livewire-webhook-secret')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Payment settings updated for Livewire Shop.')
            ->assertSee('OPay/transfer ready')
            ->assertSee('Card checkout ready')
            ->assertSee('Webhook ready');

        $shop->refresh();

        $this->assertSame('livewire-client-id', $shop->flutterwave_client_id);
        $this->assertSame('livewire-client-secret', $shop->flutterwave_client_secret);
        $this->assertSame('FLWSECK_TEST-livewire-secret-key', $shop->flutterwave_secret_key);
        $this->assertSame('livewire-webhook-secret', $shop->flutterwave_webhook_secret);
    }

    public function test_livewire_payment_settings_card_saves_branded_gateway_settings(): void
    {
        [$tenant] = $this->tenants();
        $shop = $this->shop($tenant, 'Monnify Shop', false)->load('tenant');
        $shop->payments_count = 0;
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(PaymentSettingsCard::class, ['shop' => $shop])
            ->set('payment_gateway', 'monnify')
            ->set('gateway_settings.public_key', 'MK_TEST_PUBLIC')
            ->set('gateway_settings.secret_key', 'MK_TEST_SECRET')
            ->set('gateway_settings.contract_code', '1234567890')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Monnify');

        $shop->refresh();
        $tenant->refresh();

        $this->assertSame('monnify', $shop->payment_gateway);
        $this->assertSame('MK_TEST_PUBLIC', $tenant->payment_gateway_settings['monnify']['public_key']);
        $this->assertSame('MK_TEST_SECRET', $tenant->payment_gateway_settings['monnify']['secret_key']);
        $this->assertSame('1234567890', $tenant->payment_gateway_settings['monnify']['contract_code']);
    }

    public function test_leaving_a_gateway_field_blank_keeps_its_saved_value_instead_of_wiping_it(): void
    {
        [$tenant] = $this->tenants();
        $shop = $this->shop($tenant, 'Squad Shop', false)->load('tenant');
        $shop->payments_count = 0;
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        // First save: public_key + secret_key.
        Livewire::test(PaymentSettingsCard::class, ['shop' => $shop])
            ->set('payment_gateway', 'squad')
            ->set('gateway_settings.public_key', 'sq_pk_original')
            ->set('gateway_settings.secret_key', 'sq_sk_original')
            ->call('save')
            ->assertHasNoErrors();

        $shop->refresh();
        $tenant->refresh();
        $this->assertSame('sq_pk_original', $tenant->payment_gateway_settings['squad']['public_key']);
        $this->assertSame('sq_sk_original', $tenant->payment_gateway_settings['squad']['secret_key']);

        // Second save: only change environment, leave secret fields blank
        // (as the "leave blank to keep saved value" placeholder promises).
        Livewire::test(PaymentSettingsCard::class, ['shop' => $shop])
            ->set('gateway_settings.environment', 'live')
            ->call('save')
            ->assertHasNoErrors();

        $tenant->refresh();
        $this->assertSame('sq_pk_original', $tenant->payment_gateway_settings['squad']['public_key']);
        $this->assertSame('sq_sk_original', $tenant->payment_gateway_settings['squad']['secret_key']);
        $this->assertSame('live', $tenant->payment_gateway_settings['squad']['environment']);
    }

    public function test_gateway_environment_only_accepts_live_or_test(): void
    {
        [$tenant] = $this->tenants();
        $shop = $this->shop($tenant, 'Squad Shop', false)->load('tenant');
        $shop->payments_count = 0;
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(PaymentSettingsCard::class, ['shop' => $shop])
            ->set('payment_gateway', 'squad')
            ->set('gateway_settings.environment', 'production')
            ->call('save')
            ->assertHasErrors('gateway_settings.environment');
    }

    public function test_environment_is_excluded_from_gateway_readiness_badges(): void
    {
        [$tenant] = $this->tenants();
        $shop = $this->shop($tenant, 'Squad Shop', false)->load('tenant');
        $shop->update(['payment_gateway' => 'squad']);

        $readiness = PaymentGatewayCatalog::tenantReadiness($shop->fresh());

        $this->assertArrayNotHasKey('environment', $readiness);
        $this->assertArrayHasKey('public_key', $readiness);
        $this->assertArrayHasKey('secret_key', $readiness);
    }

    public function test_livewire_payment_settings_card_validates_credentials_together(): void
    {
        [$tenant] = $this->tenants();
        $shop = $this->shop($tenant, 'Livewire Shop', false)->load('tenant');
        $shop->payments_count = 0;
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(PaymentSettingsCard::class, ['shop' => $shop])
            ->set('flutterwave_client_id', 'livewire-client-id')
            ->call('save')
            ->assertHasErrors('flutterwave_client_secret');
    }

    public function test_livewire_payment_settings_card_clears_saved_credentials(): void
    {
        [$tenant] = $this->tenants();
        $shop = $this->shop($tenant, 'Configured Shop', true)->load('tenant');
        $shop->payments_count = 0;
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(PaymentSettingsCard::class, ['shop' => $shop])
            ->set('clear_flutterwave_credentials', true)
            ->set('clear_flutterwave_secret_key', true)
            ->set('clear_flutterwave_webhook_secret', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Payment settings updated for Configured Shop.')
            ->assertSee('OPay/transfer missing')
            ->assertSee('Card key missing')
            ->assertSee('Webhook missing');

        $shop->refresh();

        $this->assertNull($shop->flutterwave_client_id);
        $this->assertNull($shop->flutterwave_client_secret);
        $this->assertNull($shop->flutterwave_secret_key);
        $this->assertNull($shop->flutterwave_webhook_secret);
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
            'flutterwave_secret_key' => $configured ? 'FLWSECK_TEST-secret-key' : null,
            'flutterwave_webhook_secret' => $configured ? 'webhook-secret' : null,
        ]);
    }
}
