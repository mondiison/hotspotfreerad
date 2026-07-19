<?php

namespace Tests\Feature;

use App\Livewire\Admin\ExpenseCategoriesIndex;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
                'monthly_budget' => 75000,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.expense-categories.index'));

        $this->assertDatabaseHas('expense_categories', [
            'tenant_id' => $tenant->id,
            'name' => 'Generator fuel',
            'monthly_budget' => 75000,
            'is_active' => true,
        ]);

        $category = ExpenseCategory::where('name', 'Generator fuel')->firstOrFail();
        Expense::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $category->id,
            'title' => 'Diesel refill',
            'amount' => 15000,
            'currency' => 'NGN',
            'incurred_on' => now()->toDateString(),
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $category->id,
            'title' => 'Old diesel refill',
            'amount' => 50000,
            'currency' => 'NGN',
            'incurred_on' => now()->subMonth()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.expense-categories.index'))
            ->assertOk()
            ->assertSee('Generator fuel')
            ->assertSee('NGN 75,000.00')
            ->assertSee('NGN 15,000.00')
            ->assertSee('20%')
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

    public function test_monthly_budget_must_be_positive(): void
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
                'monthly_budget' => -100,
                'is_active' => 1,
            ])
            ->assertSessionHasErrors('monthly_budget');
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

    public function test_livewire_category_index_creates_category_from_modal(): void
    {
        [$tenant, $user] = $this->tenantUser();

        Livewire::actingAs($user)
            ->test(ExpenseCategoriesIndex::class)
            ->call('create')
            ->assertSet('showFormModal', true)
            ->set('name', 'Tower lease')
            ->set('description', 'Monthly tower site rent.')
            ->set('monthly_budget', '25000')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Expense category created.')
            ->assertSee('Tower lease');

        $this->assertDatabaseHas('expense_categories', [
            'tenant_id' => $tenant->id,
            'name' => 'Tower lease',
            'monthly_budget' => 25000,
        ]);
    }

    public function test_livewire_category_index_edits_category_from_modal(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Old category',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseCategoriesIndex::class)
            ->call('edit', $category->id)
            ->assertSet('showFormModal', true)
            ->assertSet('name', 'Old category')
            ->set('name', 'Updated category')
            ->set('monthly_budget', '12000')
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSee('Expense category updated.')
            ->assertSee('Updated category');

        $this->assertDatabaseHas('expense_categories', [
            'id' => $category->id,
            'name' => 'Updated category',
            'monthly_budget' => 12000,
            'is_active' => false,
        ]);
    }

    public function test_livewire_category_index_filters_without_page_reload(): void
    {
        [$tenant, $user] = $this->tenantUser();
        ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Generator fuel',
            'monthly_budget' => 50000,
            'is_active' => true,
        ]);
        ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Inactive no budget',
            'is_active' => false,
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseCategoriesIndex::class)
            ->set('search', 'Generator')
            ->set('budget', 'budgeted')
            ->assertSee('Generator fuel')
            ->assertDontSee('Inactive no budget')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('budget', '')
            ->assertSee('Inactive no budget');
    }

    public function test_livewire_category_index_deletes_unused_category_with_confirmation(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Unused category',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseCategoriesIndex::class)
            ->call('confirmDelete', $category->id)
            ->assertSet('showDeleteModal', true)
            ->assertSee('Unused category')
            ->call('delete')
            ->assertSet('showDeleteModal', false)
            ->assertSee('Expense category deleted.');

        $this->assertDatabaseMissing('expense_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_livewire_category_index_shows_delete_error_for_used_category(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Used category',
            'is_active' => true,
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $category->id,
            'title' => 'Used expense',
            'amount' => 1000,
            'currency' => 'NGN',
            'incurred_on' => '2026-07-10',
        ]);

        Livewire::actingAs($user)
            ->test(ExpenseCategoriesIndex::class)
            ->call('confirmDelete', $category->id)
            ->call('delete')
            ->assertSet('showDeleteModal', true)
            ->assertSee('Deactivate it instead of deleting it.');

        $this->assertDatabaseHas('expense_categories', [
            'id' => $category->id,
        ]);
    }

    private function tenantUser(): array
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Tenant',
            'owner_email' => fake()->unique()->safeEmail(),
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        return [$tenant, $user];
    }
}
