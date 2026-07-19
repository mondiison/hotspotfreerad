<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExpenseManagementService
{
    public function rules(User $user, ?int $tenantId = null): array
    {
        $tenantId = $this->tenantId($user, $tenantId);

        return [
            'tenant_id' => [$user->isSuperAdmin() ? 'required' : 'nullable', 'integer', Rule::exists('tenants', 'id')],
            'expense_category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')->where(function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'incurred_on' => ['required', 'date'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'is_recurring' => ['nullable', 'boolean'],
            'recurring_frequency' => ['nullable', 'required_if:is_recurring,1', Rule::in(['weekly', 'monthly', 'quarterly', 'yearly'])],
            'next_due_on' => ['nullable', 'date'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:4096'],
            'remove_receipt' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function validated(Request $request): array
    {
        $tenantId = $this->tenantId($request->user(), $request->integer('tenant_id'));

        return $this->normalize(
            $request->validate($this->rules($request->user(), $tenantId)) + ['is_recurring' => false],
            $request->user(),
            $tenantId
        );
    }

    public function create(array $data, User $user, mixed $receipt = null): Expense
    {
        $data = $this->normalize($data, $user, isset($data['tenant_id']) ? (int) $data['tenant_id'] : null);

        if ($receipt) {
            $data['receipt_path'] = $this->storeReceipt($receipt, $data['tenant_id']);
        }

        return Expense::create($data);
    }

    public function update(Expense $expense, array $data, User $user, mixed $receipt = null, bool $removeReceipt = false): Expense
    {
        TenantAccess::assertExpense($expense, $user);

        $data = $this->normalize($data, $user, isset($data['tenant_id']) ? (int) $data['tenant_id'] : null);

        if ($removeReceipt && $expense->receipt_path) {
            Storage::disk('local')->delete($expense->receipt_path);
            $data['receipt_path'] = null;
        }

        if ($receipt) {
            if ($expense->receipt_path) {
                Storage::disk('local')->delete($expense->receipt_path);
            }

            $data['receipt_path'] = $this->storeReceipt($receipt, $data['tenant_id']);
        }

        $expense->update($data);

        return $expense;
    }

    public function delete(Expense $expense, User $user): void
    {
        TenantAccess::assertExpense($expense, $user);

        if ($expense->receipt_path) {
            Storage::disk('local')->delete($expense->receipt_path);
        }

        $expense->delete();
    }

    public function recordRecurring(Expense $expense, User $user): void
    {
        TenantAccess::assertExpense($expense, $user);

        abort_unless($expense->is_recurring && $expense->recurring_frequency && $expense->next_due_on, 404);

        DB::transaction(function () use ($expense): void {
            Expense::create([
                'tenant_id' => $expense->tenant_id,
                'expense_category_id' => $expense->expense_category_id,
                'recurring_source_expense_id' => $expense->id,
                'title' => $expense->title,
                'amount' => $expense->amount,
                'currency' => $expense->currency,
                'incurred_on' => $expense->next_due_on,
                'vendor' => $expense->vendor,
                'is_recurring' => false,
                'notes' => trim(($expense->notes ? $expense->notes."\n\n" : '').'Recorded from recurring schedule due '.$expense->next_due_on->toDateString().'.'),
            ]);

            $expense->update([
                'next_due_on' => $this->nextDueDate($expense->next_due_on, $expense->recurring_frequency),
            ]);
        });
    }

    public function normalize(array $data, User $user, ?int $tenantId = null): array
    {
        $data['tenant_id'] = $this->tenantId($user, $tenantId);
        $data['currency'] = strtoupper((string) ($data['currency'] ?? 'NGN'));
        $data['is_recurring'] = (bool) ($data['is_recurring'] ?? false);

        foreach (['expense_category_id', 'vendor', 'recurring_frequency', 'next_due_on', 'notes'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? $data[$field] : null;
        }

        if (! $data['is_recurring']) {
            $data['recurring_frequency'] = null;
            $data['next_due_on'] = null;
        }

        unset($data['receipt'], $data['remove_receipt']);

        return $data;
    }

    public function tenantId(User $user, ?int $tenantId = null): int
    {
        $tenantId = $user->isSuperAdmin()
            ? $tenantId
            : $user->tenant_id;

        abort_unless($tenantId, 403);

        return (int) $tenantId;
    }

    public function storeReceipt(mixed $receipt, int $tenantId): string
    {
        return $receipt->store("tenant-expenses/{$tenantId}", 'local');
    }

    public function nextDueDate(Carbon $date, string $frequency): Carbon
    {
        return match ($frequency) {
            'weekly' => $date->copy()->addWeek(),
            'quarterly' => $date->copy()->addQuarterNoOverflow(),
            'yearly' => $date->copy()->addYearNoOverflow(),
            default => $date->copy()->addMonthNoOverflow(),
        };
    }
}
