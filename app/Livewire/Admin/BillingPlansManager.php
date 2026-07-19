<?php

namespace App\Livewire\Admin;

use App\Models\BillingPlan;
use App\Services\BillingPlanManagementService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class BillingPlansManager extends Component
{
    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    public ?int $editingPlanId = null;

    public ?int $deletingPlanId = null;

    public string $name = '';

    public string $slug = '';

    public string $monthly_price = '0';

    public string $currency = 'NGN';

    public string $shop_limit = '';

    public string $router_limit = '';

    public string $package_limit = '';

    public string $features = '';

    public bool $is_active = true;

    public ?string $savedMessage = null;

    public function mount(BillingPlanManagementService $plans): void
    {
        $plans->assertSuperAdmin(auth()->user());
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function edit(int $planId, BillingPlanManagementService $plans): void
    {
        $plans->assertSuperAdmin(auth()->user());

        $plan = BillingPlan::findOrFail($planId);

        $this->editingPlanId = $plan->id;
        $this->name = (string) $plan->name;
        $this->slug = (string) $plan->slug;
        $this->monthly_price = (string) $plan->monthly_price;
        $this->currency = (string) $plan->currency;
        $this->shop_limit = (string) $plan->shop_limit;
        $this->router_limit = (string) $plan->router_limit;
        $this->package_limit = (string) $plan->package_limit;
        $this->features = collect($plan->features ?? [])->implode("\n");
        $this->is_active = (bool) $plan->is_active;
        $this->savedMessage = null;
        $this->showFormModal = true;
    }

    public function save(BillingPlanManagementService $plans): void
    {
        $plan = $this->editingPlanId ? BillingPlan::findOrFail($this->editingPlanId) : null;
        $data = Validator::make([
            'name' => $this->name,
            'slug' => $this->slug,
            'monthly_price' => $this->monthly_price,
            'currency' => $this->currency,
            'shop_limit' => $this->shop_limit,
            'router_limit' => $this->router_limit,
            'package_limit' => $this->package_limit,
            'features' => $this->features,
            'is_active' => $this->is_active,
        ], $plans->rules($plan))->validate();

        if ($plan) {
            $plans->update($plan, $data, auth()->user());
            $this->savedMessage = 'Billing plan updated.';
        } else {
            $plans->create($data, auth()->user());
            $this->savedMessage = 'Billing plan created.';
        }

        $this->showFormModal = false;
        $this->resetForm();
    }

    public function confirmDelete(int $planId, BillingPlanManagementService $plans): void
    {
        $plans->assertSuperAdmin(auth()->user());

        $this->deletingPlanId = BillingPlan::findOrFail($planId)->id;
        $this->showDeleteModal = true;
    }

    public function delete(BillingPlanManagementService $plans): void
    {
        if (! $this->deletingPlanId) {
            return;
        }

        try {
            $plans->delete(BillingPlan::findOrFail($this->deletingPlanId), auth()->user());
        } catch (ValidationException $exception) {
            $this->showDeleteModal = false;
            $this->addError('billing_plan', $exception->errors()['billing_plan'][0] ?? 'Unable to delete this billing plan.');

            return;
        }

        $this->showDeleteModal = false;
        $this->deletingPlanId = null;
        $this->savedMessage = 'Billing plan deleted.';
    }

    public function render()
    {
        return view('livewire.admin.billing-plans-manager', [
            'plans' => BillingPlan::withCount('tenantSubscriptions')->orderBy('monthly_price')->get(),
            'deletingPlan' => $this->deletingPlanId ? BillingPlan::find($this->deletingPlanId) : null,
        ]);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingPlanId',
            'name',
            'slug',
            'shop_limit',
            'router_limit',
            'package_limit',
            'features',
        ]);
        $this->monthly_price = '0';
        $this->currency = 'NGN';
        $this->is_active = true;
        $this->resetValidation();
    }
}
