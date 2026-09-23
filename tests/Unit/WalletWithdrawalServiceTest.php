<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletWithdrawal;
use App\Services\WalletWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WalletWithdrawalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithWallet(float $balance = 1000): Tenant
    {
        $tenant = Tenant::create([
            'company_name' => fake()->unique()->company(),
            'owner_email' => fake()->unique()->safeEmail(),
            'wallet_enabled' => true,
        ]);

        Wallet::create(['tenant_id' => $tenant->id, 'balance' => $balance]);

        return $tenant;
    }

    private function bankDetails(): array
    {
        return ['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Demo Tenant'];
    }

    public function test_request_reserves_the_amount_immediately(): void
    {
        $tenant = $this->tenantWithWallet(1000);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);

        $withdrawal = app(WalletWithdrawalService::class)->request($tenant, 400, $this->bankDetails(), $admin);

        $this->assertSame('pending', $withdrawal->status);
        $this->assertEquals(600, Wallet::where('tenant_id', $tenant->id)->value('balance'));
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_withdrawal_id' => $withdrawal->id,
            'type' => 'debit',
            'amount' => 400,
        ]);
    }

    public function test_request_rejects_an_amount_exceeding_the_balance(): void
    {
        $tenant = $this->tenantWithWallet(100);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);

        $this->expectException(ValidationException::class);

        app(WalletWithdrawalService::class)->request($tenant, 400, $this->bankDetails(), $admin);
    }

    public function test_two_sequential_requests_cannot_overdraw_the_wallet(): void
    {
        $tenant = $this->tenantWithWallet(500);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);
        $service = app(WalletWithdrawalService::class);

        $service->request($tenant, 400, $this->bankDetails(), $admin);

        $this->expectException(ValidationException::class);

        $service->request($tenant, 400, $this->bankDetails(), $admin);
    }

    public function test_reject_reverses_the_reservation(): void
    {
        $tenant = $this->tenantWithWallet(1000);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $service = app(WalletWithdrawalService::class);

        $withdrawal = $service->request($tenant, 400, $this->bankDetails(), $admin);
        $service->reject($withdrawal, $superAdmin, 'Invalid account details');

        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertEquals(1000, Wallet::where('tenant_id', $tenant->id)->value('balance'));
    }

    public function test_mark_paid_changes_status_only_with_no_further_balance_change(): void
    {
        $tenant = $this->tenantWithWallet(1000);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $service = app(WalletWithdrawalService::class);

        $withdrawal = $service->request($tenant, 400, $this->bankDetails(), $admin);
        $service->markPaid($withdrawal, $superAdmin, 'Sent via bank transfer');

        $this->assertSame('paid', $withdrawal->fresh()->status);
        $this->assertEquals(600, Wallet::where('tenant_id', $tenant->id)->value('balance'));
    }

    public function test_reject_fails_for_a_non_pending_withdrawal(): void
    {
        $tenant = $this->tenantWithWallet(1000);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'is_active' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $service = app(WalletWithdrawalService::class);

        $withdrawal = $service->request($tenant, 400, $this->bankDetails(), $admin);
        $service->markPaid($withdrawal, $superAdmin);

        $this->expectException(ValidationException::class);

        $service->reject($withdrawal, $superAdmin);
    }
}
