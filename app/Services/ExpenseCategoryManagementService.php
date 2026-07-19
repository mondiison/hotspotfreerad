<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseCategoryManagementService
{
    public function rules(User $user, ?ExpenseCategory $category = null): array
    {
        $tenantId = $this->tenantId($user);

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('expense_categories', 'name')
                    ->where('tenant_id', $tenantId)
                    ->ignore($category?->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function validated(Request $request, ?ExpenseCategory $category = null): array
    {
        return $this->normalize(
            $request->validate($this->rules($request->user(), $category)) + ['is_active' => false],
            $request->user()
        );
    }

    public function create(array $data, User $user): ExpenseCategory
    {
        return ExpenseCategory::create($this->normalize($data, $user));
    }

    public function update(ExpenseCategory $category, array $data, User $user): ExpenseCategory
    {
        $this->assertCanManage($user, $category);

        $category->update($this->normalize($data, $user));

        return $category;
    }

    public function delete(ExpenseCategory $category, User $user): void
    {
        $this->assertCanManage($user, $category);

        if ($category->expenses()->exists()) {
            throw new \DomainException('This category is already used by expenses. Deactivate it instead of deleting it.');
        }

        $category->delete();
    }

    public function assertCanManage(User $user, ExpenseCategory $category): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        abort_unless($category->tenant_id === $user->tenant_id, 403);
    }

    public function normalize(array $data, User $user): array
    {
        $data['tenant_id'] = $this->tenantId($user);
        $data['description'] = filled($data['description'] ?? null) ? $data['description'] : null;
        $data['monthly_budget'] = filled($data['monthly_budget'] ?? null)
            ? round((float) $data['monthly_budget'], 2)
            : null;
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        return $data;
    }

    private function tenantId(User $user): ?int
    {
        $tenantId = $user->isSuperAdmin()
            ? null
            : $user->tenant_id;

        abort_unless($user->isSuperAdmin() || $tenantId, 403);

        return $tenantId;
    }
}
