<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_create_private_expense_category(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Tenant',
            'owner_email' => 'owner@example.com',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.expense-categories.store'), [
                'name' => 'Generator fuel',
                'description' => 'Diesel and petrol used for hotspot locations.',
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.expense-categories.index'));

        $this->assertDatabaseHas('expense_categories', [
            'tenant_id' => $tenant->id,
            'name' => 'Generator fuel',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.expense-categories.index'))
            ->assertOk()
            ->assertSee('Generator fuel')
            ->assertSee('Tenant custom')
            ->assertSee('Subscription')
            ->assertSee('Read only');
    }

    public function test_tenant_admin_cannot_manage_platform_default_category(): void
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
            ->get(route('admin.expense-categories.edit', $category))
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('admin.expense-categories.update', $category), [
                'name' => 'Changed',
                'is_active' => 1,
            ])
            ->assertForbidden();
    }

    public function test_super_admin_can_manage_platform_default_categories(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.expense-categories.store'), [
                'name' => 'Licensing',
                'description' => 'Permits and business licensing.',
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.expense-categories.index'));

        $this->assertDatabaseHas('expense_categories', [
            'tenant_id' => null,
            'name' => 'Licensing',
        ]);

        $this->actingAs($user)
            ->get(route('admin.expense-categories.index'))
            ->assertOk()
            ->assertSee('Licensing')
            ->assertSee('Platform default');
    }

    public function test_used_category_cannot_be_deleted(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Tenant',
            'owner_email' => 'owner@example.com',
        ]);
        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fuel',
            'is_active' => true,
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $category->id,
            'title' => 'Fuel purchase',
            'amount' => 1000,
            'currency' => 'NGN',
            'incurred_on' => '2026-07-10',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.expense-categories.destroy', $category))
            ->assertSessionHasErrors('category');

        $this->assertDatabaseHas('expense_categories', [
            'id' => $category->id,
            'name' => 'Fuel',
        ]);
    }
}
