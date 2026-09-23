<?php

namespace Tests\Feature;

use App\Livewire\Admin\WalletIndex;
use App\Models\BillingPlan;
use App\Models\Tenant;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminWalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_ineligible_tenant_admin_sees_the_upgrade_message_not_the_enable_form(): void
    {
        $tenant = $this->tenant();
        $user = $this->tenantAdmin($tenant);

        $this->actingAs($user)
            ->get(route('admin.wallet.index'))
            ->assertOk()
            ->assertSee('Enable the platform wallet')
            ->assertSee('Your tenant needs an active platform billing subscription before enabling the wallet.')
            ->assertDontSee('Enable Wallet');
    }

    public function test_super_admin_cannot_view_the_tenant_wallet_page(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($superAdmin)
            ->get(route('admin.wallet.index'))
            ->assertForbidden();
    }

    public function test_tenant_staff_cannot_view_the_wallet_page(): void
    {
        $tenant = $this->tenant();
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->get(route('admin.wallet.index'))
            ->assertForbidden();
    }

    public function test_eligible_tenant_admin_can_enable_the_wallet(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 7.5);
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->call('enableWallet')
            ->assertSet('statusMessage', 'Wallet enabled. Customer payments for this tenant now route through the platform gateway.');

        $tenant->refresh();
        $this->assertTrue($tenant->wallet_enabled);
        $this->assertEquals(7.5, $tenant->commission_rate);
        $this->assertDatabaseHas('wallets', ['tenant_id' => $tenant->id]);
    }

    public function test_tenant_admin_can_change_who_bears_the_commission(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 10);
        $tenant->forceFill(['wallet_enabled' => true])->save();
        Wallet::create(['tenant_id' => $tenant->id, 'balance' => 0]);
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->set('commissionBearer', 'customer')
            ->call('saveCommissionBearer');

        $this->assertSame('customer', $tenant->fresh()->wallet_commission_bearer);
    }

    public function test_tenant_admin_can_request_a_withdrawal_within_the_wallet_balance(): void
    {
        $tenant = $this->tenant();
        $this->subscribeTenant($tenant, supportsWallet: true, walletCommissionRate: 10);
        $tenant->forceFill(['wallet_enabled' => true])->save();
        $wallet = Wallet::create(['tenant_id' => $tenant->id, 'balance' => 1000]);
        $user = $this->tenantAdmin($tenant);

        Livewire::actingAs($user)
            ->test(WalletIndex::class, ['tenant' => $tenant])
            ->set('withdrawAmount', '400')
            ->set('bankName', 'GTBank')
            ->set('accountNumber', '0123456789')
            ->set('accountName', 'Demo Tenant')
            ->call('requestWithdrawal')
            ->assertSet('statusMessage', 'Withdrawal request submitted. It will be reviewed and paid out manually.');

        $this->assertDatabaseHas('wallet_withdrawals', [
            'tenant_id' => $tenant->id,
            'wallet_id' => $wallet->id,
            'amount' => 400,
            'status' => 'pending',
        ]);
        $this->assertEquals(600, $wallet->fresh()->balance);
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'company_name' => 'Demo ISP',
            'owner_email' => 'owner@example.com',
        ]);
    }

    private function tenantAdmin(Tenant $tenant): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);
    }

    private function subscribeTenant(Tenant $tenant, bool $supportsWallet = false, ?float $walletCommissionRate = null): TenantBillingSubscription
    {
        $plan = BillingPlan::create([
            'name' => 'Premium',
            'slug' => 'premium-'.$tenant->id,
            'monthly_price' => 10000,
            'currency' => 'NGN',
            'supports_wallet' => $supportsWallet,
            'wallet_commission_rate' => $walletCommissionRate,
            'is_active' => true,
        ]);

        return TenantBillingSubscription::create([
            'tenant_id' => $tenant->id,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'amount' => $plan->monthly_price,
            'currency' => $plan->currency,
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);
    }
}
