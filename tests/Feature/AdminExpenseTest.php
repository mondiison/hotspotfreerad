<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $category->update(['monthly_budget' => 5000]);
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
                'recurring_frequency' => 'monthly',
                'next_due_on' => '2026-08-10',
                'notes' => 'Replaced power adapter.',
            ])
            ->assertRedirect(route('admin.expenses.index'));

        $this->assertDatabaseHas('expenses', [
            'tenant_id' => $tenant->id,
            'expense_category_id' => $category->id,
            'title' => 'Router repair',
            'amount' => 2500,
            'is_recurring' => true,
            'recurring_frequency' => 'monthly',
            'next_due_on' => '2026-08-10 00:00:00',
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
            ->assertSee('Budget')
            ->assertSee('Remaining')
            ->assertSee('Budget Usage')
            ->assertSee('NGN 5,000.00')
            ->assertSee('50%')
            ->assertSee('Recurring')
            ->assertSee('Monthly')
            ->assertSee('Aug 10, 2026');
    }

    public function test_recurring_expense_requires_frequency_and_non_recurring_clears_schedule(): void
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
            ->post(route('admin.expenses.store'), [
                'title' => 'Monthly ISP',
                'amount' => 15000,
                'currency' => 'NGN',
                'incurred_on' => '2026-07-10',
                'is_recurring' => 1,
            ])
            ->assertSessionHasErrors('recurring_frequency');

        $this->actingAs($user)
            ->post(route('admin.expenses.store'), [
                'title' => 'One-time cable',
                'amount' => 5000,
                'currency' => 'NGN',
                'incurred_on' => '2026-07-10',
                'recurring_frequency' => 'monthly',
                'next_due_on' => '2026-08-10',
            ])
            ->assertRedirect(route('admin.expenses.index'));

        $expense = Expense::where('title', 'One-time cable')->firstOrFail();

        $this->assertFalse($expense->is_recurring);
        $this->assertNull($expense->recurring_frequency);
        $this->assertNull($expense->next_due_on);
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

    public function test_tenant_admin_can_upload_and_download_expense_receipt(): void
    {
        Storage::fake('local');

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
            ->post(route('admin.expenses.store'), [
                'title' => 'Router receipt',
                'amount' => 3500,
                'currency' => 'NGN',
                'incurred_on' => '2026-07-15',
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertRedirect(route('admin.expenses.index'));

        $expense = Expense::where('title', 'Router receipt')->firstOrFail();

        $this->assertNotNull($expense->receipt_path);
        Storage::disk('local')->assertExists($expense->receipt_path);

        $this->actingAs($user)
            ->get(route('admin.expenses.receipt', $expense))
            ->assertOk()
            ->assertDownload();

        $this->actingAs($user)
            ->get(route('admin.expenses.index', [
                'from' => '2026-07-01',
                'to' => '2026-07-31',
            ]))
            ->assertOk()
            ->assertSee('Receipt attached');
    }

    public function test_tenant_admin_cannot_download_another_tenants_receipt(): void
    {
        Storage::fake('local');

        $ownTenant = Tenant::create([
            'company_name' => 'Own Tenant',
            'owner_email' => 'own@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Other Tenant',
            'owner_email' => 'other@example.com',
        ]);
        $receiptPath = UploadedFile::fake()->image('receipt.jpg')->store("tenant-expenses/{$otherTenant->id}", 'local');
        $expense = Expense::create([
            'tenant_id' => $otherTenant->id,
            'title' => 'Other receipt',
            'amount' => 1000,
            'currency' => 'NGN',
            'incurred_on' => '2026-07-15',
            'receipt_path' => $receiptPath,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.expenses.receipt', $expense))
            ->assertForbidden();
    }

    public function test_receipt_can_be_replaced_and_removed(): void
    {
        Storage::fake('local');

        $tenant = Tenant::create([
            'company_name' => 'Mondi Tenant',
            'owner_email' => 'owner@example.com',
        ]);
        $oldPath = UploadedFile::fake()->image('old.jpg')->store("tenant-expenses/{$tenant->id}", 'local');
        $expense = Expense::create([
            'tenant_id' => $tenant->id,
            'title' => 'Replace receipt',
            'amount' => 1000,
            'currency' => 'NGN',
            'incurred_on' => '2026-07-15',
            'receipt_path' => $oldPath,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('admin.expenses.update', $expense), [
                'title' => 'Replace receipt',
                'amount' => 1000,
                'currency' => 'NGN',
                'incurred_on' => '2026-07-15',
                'receipt' => UploadedFile::fake()->image('new.jpg'),
            ])
            ->assertRedirect(route('admin.expenses.index'));

        $expense->refresh();
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($expense->receipt_path);

        $newPath = $expense->receipt_path;

        $this->actingAs($user)
            ->put(route('admin.expenses.update', $expense), [
                'title' => 'Replace receipt',
                'amount' => 1000,
                'currency' => 'NGN',
                'incurred_on' => '2026-07-15',
                'remove_receipt' => 1,
            ])
            ->assertRedirect(route('admin.expenses.index'));

        $this->assertNull($expense->fresh()->receipt_path);
        Storage::disk('local')->assertMissing($newPath);
    }

    public function test_tenant_admin_can_export_only_own_expenses(): void
    {
        $ownTenant = Tenant::create([
            'company_name' => 'Own Tenant',
            'owner_email' => 'own@example.com',
        ]);
        $otherTenant = Tenant::create([
            'company_name' => 'Other Tenant',
            'owner_email' => 'other@example.com',
        ]);
        $category = ExpenseCategory::where('name', 'Maintenance')->firstOrFail();
        $category->update(['monthly_budget' => 4400]);
        Expense::create([
            'tenant_id' => $ownTenant->id,
            'expense_category_id' => $category->id,
            'title' => 'Own router repair',
            'amount' => 2200,
            'currency' => 'NGN',
            'incurred_on' => '2026-07-16',
            'vendor' => 'Own Technician',
            'notes' => 'Tenant scoped export.',
            'is_recurring' => true,
            'recurring_frequency' => 'monthly',
            'next_due_on' => '2026-08-16',
        ]);
        Expense::create([
            'tenant_id' => $otherTenant->id,
            'title' => 'Other expense',
            'amount' => 9900,
            'currency' => 'NGN',
            'incurred_on' => '2026-07-16',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.expenses.export', [
                'from' => '2026-07-01',
                'to' => '2026-07-31',
            ]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('Own router repair', $content);
        $this->assertStringContainsString('Maintenance', $content);
        $this->assertStringContainsString('2200.00', $content);
        $this->assertStringContainsString('monthly', $content);
        $this->assertStringContainsString('2026-08-16', $content);
        $this->assertStringContainsString('Summary', $content);
        $this->assertStringContainsString('"Total Spent",2200.00', $content);
        $this->assertStringContainsString('Budget,4400.00', $content);
        $this->assertStringContainsString('Remaining,2200.00', $content);
        $this->assertStringContainsString('"Budget Usage",50%', $content);
        $this->assertStringContainsString('"Spend by Category"', $content);
        $this->assertStringContainsString('Category,Count,Amount,Budget,Variance,Usage', $content);
        $this->assertStringContainsString('Maintenance,1,2200.00,4400.00,2200.00,50%', $content);
        $this->assertStringNotContainsString('Other expense', $content);
        $this->assertStringNotContainsString('9900.00', $content);
    }

    public function test_tenant_admin_can_record_recurring_expense_occurrence(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Tenant',
            'owner_email' => 'owner@example.com',
        ]);
        $category = ExpenseCategory::where('name', 'Subscription')->firstOrFail();
        $expense = Expense::create([
            'tenant_id' => $tenant->id,
            'expense_category_id' => $category->id,
            'title' => 'Monthly upstream internet',
            'amount' => 25000,
            'currency' => 'NGN',
            'incurred_on' => '2026-07-01',
            'vendor' => 'Fiber Provider',
            'is_recurring' => true,
            'recurring_frequency' => 'monthly',
            'next_due_on' => '2026-08-01',
            'notes' => 'Main upstream link.',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.expenses.record-recurring', $expense))
            ->assertSessionHas('status');

        $expense->refresh();
        $occurrence = Expense::where('recurring_source_expense_id', $expense->id)->firstOrFail();

        $this->assertSame('2026-09-01', $expense->next_due_on->toDateString());
        $this->assertSame($tenant->id, $occurrence->tenant_id);
        $this->assertSame($category->id, $occurrence->expense_category_id);
        $this->assertSame('Monthly upstream internet', $occurrence->title);
        $this->assertSame('25000.00', $occurrence->amount);
        $this->assertFalse($occurrence->is_recurring);
        $this->assertSame('2026-08-01', $occurrence->incurred_on->toDateString());
        $this->assertStringContainsString('Recorded from recurring schedule due 2026-08-01.', $occurrence->notes);
    }

    public function test_expense_schedule_filter_shows_due_and_overdue_recurring_items(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Mondi Tenant',
            'owner_email' => 'owner@example.com',
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'title' => 'Overdue upstream internet',
            'amount' => 25000,
            'currency' => 'NGN',
            'incurred_on' => now()->subMonth()->toDateString(),
            'is_recurring' => true,
            'recurring_frequency' => 'monthly',
            'next_due_on' => now()->subDay()->toDateString(),
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'title' => 'Upcoming staff salary',
            'amount' => 50000,
            'currency' => 'NGN',
            'incurred_on' => now()->subMonth()->toDateString(),
            'is_recurring' => true,
            'recurring_frequency' => 'monthly',
            'next_due_on' => now()->addDays(10)->toDateString(),
        ]);
        Expense::create([
            'tenant_id' => $tenant->id,
            'title' => 'Future equipment lease',
            'amount' => 10000,
            'currency' => 'NGN',
            'incurred_on' => now()->toDateString(),
            'is_recurring' => true,
            'recurring_frequency' => 'monthly',
            'next_due_on' => now()->addDays(60)->toDateString(),
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.expenses.index', ['schedule' => 'overdue']))
            ->assertOk()
            ->assertSee('Overdue upstream internet')
            ->assertSee('Overdue')
            ->assertDontSee('Upcoming staff salary')
            ->assertDontSee('Future equipment lease');

        $this->actingAs($user)
            ->get(route('admin.expenses.index', ['schedule' => 'due_soon']))
            ->assertOk()
            ->assertSee('Upcoming staff salary')
            ->assertDontSee('Overdue upstream internet')
            ->assertDontSee('Future equipment lease');
    }

    public function test_tenant_admin_cannot_record_another_tenants_recurring_expense(): void
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
            'title' => 'Other recurring cost',
            'amount' => 15000,
            'currency' => 'NGN',
            'incurred_on' => '2026-07-01',
            'is_recurring' => true,
            'recurring_frequency' => 'monthly',
            'next_due_on' => '2026-08-01',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $ownTenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('admin.expenses.record-recurring', $expense))
            ->assertForbidden();

        $this->assertDatabaseMissing('expenses', [
            'recurring_source_expense_id' => $expense->id,
        ]);
    }
}
