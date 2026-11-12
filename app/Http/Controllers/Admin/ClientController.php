<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientBillingPlan;
use App\Models\Shop;
use App\Services\ClientManagementService;
use App\Services\ClientResellingService;
use App\Support\BillingPlanLimits;
use App\Support\PaymentGatewayCatalog;
use App\Support\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Tenant admin's own CRUD for onboarding Clients (the reseller-tier model,
 * 2026-11-12) -- deliberately plain Blade, not a Livewire index, matching
 * this phase's "ship only the minimum needed to prove the model end-to-end"
 * scope. Reachable by tenant_admin/super_admin only; not added to
 * StaffPermissions' route map, so tenant_staff is denied by default.
 */
class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $clients = TenantAccess::scopeClients(Client::with(['shops', 'currentBillingSubscription.clientBillingPlan']), $request->user())
            ->latest()
            ->paginate(15);

        return view('admin.clients.index', [
            'clients' => $clients,
            'canEnableReselling' => $this->canEnableReselling($request),
        ]);
    }

    /**
     * Mirrors WalletIndex::enableWallet()'s self-service shape -- a tenant
     * admin opts in from here, gated by their own platform billing plan,
     * rather than a super admin flipping it on their behalf.
     */
    public function enableReselling(Request $request, ClientResellingService $reselling): RedirectResponse
    {
        abort_if($request->user()->isSuperAdmin(), 403);

        try {
            BillingPlanLimits::assertCanEnableClientReselling($request->user());
        } catch (ValidationException $exception) {
            return redirect()->route('admin.clients.index')
                ->withErrors($exception->errors());
        }

        $reselling->enable($request->user()->tenant);

        return redirect()->route('admin.clients.index')->with('status', 'Client reselling enabled.');
    }

    public function disableReselling(Request $request, ClientResellingService $reselling): RedirectResponse
    {
        abort_if($request->user()->isSuperAdmin(), 403);

        $reselling->disable($request->user()->tenant);

        return redirect()->route('admin.clients.index')->with('status', 'Client reselling disabled. Existing Clients and their shops are unaffected.');
    }

    /**
     * Which of the tenant's OWN already-saved gateway credentials to charge
     * Clients through -- ClientHostedCheckoutManager/ClientBillingController
     * read `tenant.client_checkout_gateway` directly, this is the one place
     * a tenant admin actually sets it. Scoped to the same Monnify/Paystack/
     * Squad/Stripe set ClientBillingController::SUPPORTED_GATEWAYS already
     * enforces -- Flutterwave has no tenant-level credential storage yet.
     */
    public function saveCheckoutGateway(Request $request): RedirectResponse
    {
        abort_if($request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'client_checkout_gateway' => ['nullable', Rule::in([
                PaymentGatewayCatalog::MONNIFY,
                PaymentGatewayCatalog::PAYSTACK,
                PaymentGatewayCatalog::SQUAD,
                PaymentGatewayCatalog::STRIPE,
            ])],
        ]);

        $request->user()->tenant->update(['client_checkout_gateway' => $data['client_checkout_gateway'] ?: null]);

        return redirect()->route('admin.clients.index')->with('status', 'Client checkout gateway updated.');
    }

    private function canEnableReselling(Request $request): bool|string
    {
        if ($request->user()->isSuperAdmin() || $request->user()->tenant?->client_reselling_enabled) {
            return true;
        }

        try {
            BillingPlanLimits::assertCanEnableClientReselling($request->user());

            return true;
        } catch (ValidationException $exception) {
            return $exception->errors()['billing'][0] ?? 'This feature is not available on your current platform plan.';
        }
    }

    public function create(Request $request): View
    {
        return view('admin.clients.form', [
            'client' => new Client,
            'plans' => $this->plansFor($request),
            'shops' => $this->unassignedShopsFor($request, null),
        ]);
    }

    public function store(Request $request, ClientManagementService $clients): RedirectResponse
    {
        $clients->create($clients->validated($request), $request->user());

        return redirect()->route('admin.clients.index')->with('status', 'Client created with a free trial.');
    }

    public function edit(Request $request, Client $client): View
    {
        TenantAccess::assertClient($client, $request->user());

        return view('admin.clients.form', [
            'client' => $client,
            'plans' => $this->plansFor($request),
            'shops' => $this->unassignedShopsFor($request, $client),
            'selectedShopIds' => $client->shops()->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, Client $client, ClientManagementService $clients): RedirectResponse
    {
        $clients->update($client, $clients->validated($request, isCreate: false), $request->user());

        return redirect()->route('admin.clients.index')->with('status', 'Client updated.');
    }

    public function destroy(Request $request, Client $client, ClientManagementService $clients): RedirectResponse
    {
        $clients->delete($client, $request->user());

        return redirect()->route('admin.clients.index')->with('status', 'Client deleted. Its shops now belong directly to you again.');
    }

    private function plansFor(Request $request): \Illuminate\Support\Collection
    {
        return ClientBillingPlan::where('tenant_id', $request->user()->tenant_id)
            ->where('is_active', true)
            ->orderBy('price')
            ->get();
    }

    /**
     * A shop already owned by this same Client needs to stay selectable
     * (so unchecking nothing on save doesn't silently orphan it), on top
     * of every shop that's either unowned or owned by a DIFFERENT Client.
     */
    private function unassignedShopsFor(Request $request, ?Client $client): \Illuminate\Support\Collection
    {
        return TenantAccess::scopeShops(Shop::query(), $request->user())
            ->where(function ($query) use ($client) {
                $query->whereNull('client_id');

                if ($client?->exists) {
                    $query->orWhere('client_id', $client->id);
                }
            })
            ->orderBy('name')
            ->get();
    }
}
