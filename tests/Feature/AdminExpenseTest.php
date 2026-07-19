<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_record_and_view_own_expenses(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Tenant',
            'owner_email' => 'owner@example.com',
        ]);
        $category = ExpenseCategory::where('name', 'Maintenance')->firstOrFail();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.expenses.store'), [
                'expense_category_id' => $category->id,
                'title' => 'Router repair',
                'amount' => 2500,
                'currency' => 'NGN',
                'incurred_on' => '2026-07-10',
                'vendor' => 'Technician',
                'is_recurring' => 1,
                'notes' => 'Replaced power adapter.',
            ])
            ->assertRedirect(route('admin.expenses.index'));

        $this->assertDatabaseHas('expenses', [
            'tenant_id' => $tenant->id,
            'expense_category_id' => $category->id,
            'title' => 'Router repair',
            'amount' => 2500,
            'is_recurring' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.expenses.index', [
                'from' => '2026-07-01',
                'to' => '2026-07-31',
            ]))
            ->assertOk()
            ->assertSee('Router repair')
            ->assertSee('Maintenance')
            ->assertSee('NGN 2,500.00')
            ->assertSee('Recurring');
    }

    public function test_tenant_admin_cannot_access_another_tenants_expense(): void
    {
        $ownTenant = Tenant::create([
            'company_name' => 'Own Tenant',
            'owner_email' => 'own@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Other Tenant',
            'owner_email' => 'other@example.com',
        ]);
        $expense = Expense::create([
            'tenant_id' => $otherTenant->id,
            'title' => 'Other expense',
            'amount' => 1000,
            'currency' => 'NGN',
            'incurred_on' => '2026-07-10',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.expenses.edit', $expense))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.expenses.index', [
                'from' => '2026-07-01',
                'to' => '2026-07-31',
            ]))
            ->assertOk()
            ->assertDontSee('Other expense');
    }

    public function test_super_admin_can_record_expense_for_selected_tenant(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Selected Tenant',
            'owner_email' => 'selected@example.com',
        ]);
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.expenses.store'), [
                'tenant_id' => $tenant->id,
                'title' => 'Generator fuel',
                'amount' => 4000,
                'currency' => 'NGN',
                'incurred_on' => '2026-07-12',
            ])
            ->assertRedirect(route('admin.expenses.index'));

        $this->assertDatabaseHas('expenses', [
            'tenant_id' => $tenant->id,
            'title' => 'Generator fuel',
            'amount' => 4000,
        ]);
    }
}
