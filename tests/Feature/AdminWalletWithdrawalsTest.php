<?php

namespace Tests\Feature;

use App\Livewire\Admin\WalletWithdrawalsIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletWithdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminWalletWithdrawalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_the_withdrawals_queue(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.wallet-withdrawals.index'))
            ->assertOk()
            ->assertSee('Pending');
    }

    public function test_tenant_admin_cannot_view_the_withdrawals_queue(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.wallet-withdrawals.index'))
            ->assertForbidden();
    }

    public function test_tenant_staff_cannot_view_the_withdrawals_queue(): void
    {
        $tenant = $this->tenant();
        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_staff',
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->get(route('admin.wallet-withdrawals.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_approve_a_pending_withdrawal(): void
    {
        $withdrawal = $this->pendingWithdrawal();

        Livewire::actingAs($this->superAdmin())
            ->test(WalletWithdrawalsIndex::class)
            ->call('approve', $withdrawal->id)
            ->assertSet('statusMessage', 'Withdrawal request updated.');

        $this->assertSame('approved', $withdrawal->fresh()->status);
    }

    public function test_super_admin_can_reject_a_pending_withdrawal_and_funds_return_to_the_wallet(): void
    {
        $withdrawal = $this->pendingWithdrawal();
        $wallet = $withdrawal->wallet;
        $balanceBeforeReject = (float) $wallet->balance;

        Livewire::actingAs($this->superAdmin())
            ->test(WalletWithdrawalsIndex::class)
            ->call('reject', $withdrawal->id);

        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertEquals($balanceBeforeReject + (float) $withdrawal->amount, $wallet->fresh()->balance);
    }

    public function test_super_admin_can_mark_a_withdrawal_paid_with_no_further_balance_change(): void
    {
        $withdrawal = $this->pendingWithdrawal();
        $wallet = $withdrawal->wallet;
        $balanceBeforeMarkPaid = (float) $wallet->balance;

        Livewire::actingAs($this->superAdmin())
            ->test(WalletWithdrawalsIndex::class)
            ->call('markPaid', $withdrawal->id);

        $this->assertSame('paid', $withdrawal->fresh()->status);
        $this->assertEquals($balanceBeforeMarkPaid, $wallet->fresh()->balance);
    }

    public function test_the_status_filter_scopes_the_listed_withdrawals(): void
    {
        $pending = $this->pendingWithdrawal();
        $paid = $this->pendingWithdrawal();
        $paid->forceFill(['status' => 'paid'])->save();

        Livewire::actingAs($this->superAdmin())
            ->test(WalletWithdrawalsIndex::class)
            ->assertSee($pending->tenant->company_name)
            ->set('statusFilter', 'paid')
            ->assertSee($paid->tenant->company_name)
            ->assertDontSee($pending->tenant->company_name);
    }

    private function pendingWithdrawal(): WalletWithdrawal
    {
        $tenant = $this->tenant();
        $wallet = Wallet::create(['tenant_id' => $tenant->id, 'balance' => 1000]);
        $requester = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        return WalletWithdrawal::create([
            'tenant_id' => $tenant->id,
            'wallet_id' => $wallet->id,
            'amount' => 400,
            'bank_name' => 'GTBank',
            'account_number' => '0123456789',
            'account_name' => 'Demo Tenant',
            'status' => 'pending',
            'requested_by' => $requester->id,
        ]);
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'company_name' => fake()->unique()->company(),
            'owner_email' => fake()->unique()->safeEmail(),
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
