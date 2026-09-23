<?php

namespace App\Livewire\Admin;

use App\Models\Router;
use App\Services\RouterManagementService;
use App\Support\RouterPortLayout;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lets an admin adjust one section's SSID/VLAN/addressing/ports directly from
 * that section's own tab on the router show page (Hotspot Script / POS
 * Script), instead of needing the full 4-step router wizard for a single
 * field. Reused for both networks rather than two near-identical components,
 * since the field shape is the same shape (SSID/VLAN/gateway/network/pool/
 * extra ports) apart from POS's Wi-Fi password. Mirrors the small-embedded-
 * Livewire-island pattern RouterCredentialsCard already established on this
 * same (otherwise plain Blade) page -- no Livewire conversion needed for the
 * rest of the page.
 *
 * Deliberately save-only: it never pushes live to the router itself. Saving
 * updates provisioning_settings and refreshes the generated script text on
 * the tab; pushing that live stays a separate, explicit "Provision via API"
 * click, matching how the full wizard already separates "save" from
 * "provision".
 */
class RouterNetworkSettingsCard extends Component
{
    #[Locked]
    public int $routerId;

    #[Locked]
    public string $network;

    public bool $showEditModal = false;

    public string $ssid = '';

    public ?int $vlan = null;

    public string $gateway = '';

    public string $networkCidr = '';

    public string $pool = '';

    public string $extraPorts = '';

    public string $wifiPassword = '';

    public function mount(Router $router, string $network): void
    {
        $this->routerId = $router->id;
        $this->network = $network;
        $this->loadFromRouter($router);
    }

    public function openEditModal(): void
    {
        $router = Router::find($this->routerId);

        if ($router) {
            $this->loadFromRouter($router);
        }

        $this->resetValidation();
        $this->showEditModal = true;
    }

    public function save(RouterManagementService $routers): void
    {
        $router = Router::find($this->routerId);

        if (! $router) {
            return;
        }

        $prefix = $this->network;
        $existing = (array) $router->provisioning_settings;
        $advanced = (bool) ($existing['ports_advanced_mode'] ?? false);

        $validated = $this->validate([
            'ssid' => ['nullable', 'string', 'max:32'],
            'vlan' => ['required', 'integer', 'min:1', 'max:4094'],
            'gateway' => ['nullable', 'string', 'max:32'],
            'networkCidr' => ['nullable', 'string', 'max:32'],
            'pool' => ['nullable', 'string', 'max:64'],
            'extraPorts' => $advanced
                ? ['nullable', 'string', 'max:200']
                : ['nullable', 'string', 'max:120', 'regex:/^\d+(,\s*\d+)*$/'],
            'wifiPassword' => $prefix === 'pos'
                ? ['nullable', 'string', 'min:8', 'max:63']
                : ['nullable'],
        ]);

        $updated = $existing;
        $updated["{$prefix}_ssid"] = $validated['ssid'];
        $updated["{$prefix}_vlan"] = $validated['vlan'];
        $updated["{$prefix}_gateway"] = $validated['gateway'];
        $updated["{$prefix}_network"] = $validated['networkCidr'];
        $updated["{$prefix}_pool"] = $validated['pool'];

        if ($advanced) {
            $updated["extra_{$prefix}_ports"] = $validated['extraPorts'];
            $updated["extra_{$prefix}_port_numbers"] = implode(
                ',',
                RouterPortLayout::portNumbersFromInterfaceList($validated['extraPorts']) ?? []
            );
        } else {
            $updated["extra_{$prefix}_port_numbers"] = $validated['extraPorts'];
            $updated["extra_{$prefix}_ports"] = implode(
                ',',
                RouterPortLayout::interfaceNamesFromNumberList($validated['extraPorts'])
            );
        }

        if ($prefix === 'pos') {
            $updated['pos_wifi_password'] = $validated['wifiPassword'] !== ''
                ? $validated['wifiPassword']
                : ($existing['pos_wifi_password'] ?? '');
        }

        // Reuses the exact same cross-field conflict check the full wizard
        // runs (RouterManagementService::portConflictRule(), now public for
        // exactly this) -- built against a copy of the router's full existing
        // settings with just this network's fields overridden, so a saved
        // extra port here still can't collide with another role's port.
        // portConflictRule() only checks a role's extra-ports list when that
        // role's own enable_* flag is true -- forced true here for JUST this
        // conflict check (not persisted) so editing POS's ports still catches
        // a collision even on a router where enable_pos happens to be off
        // right now (pre-configuring before flipping it on later shouldn't
        // let an unsafe port number slip through unchecked).
        $forConflictCheck = $updated;
        if ($prefix === 'pos') {
            $forConflictCheck['enable_pos'] = true;
        }

        $conflictError = null;
        $conflictRule = $routers->portConflictRule($forConflictCheck);
        $conflictRule(
            'provisioning_settings.port_count',
            $forConflictCheck['port_count'] ?? null,
            function (string $message) use (&$conflictError): void {
                $conflictError = $message;
            }
        );

        if ($conflictError !== null) {
            $this->addError('extraPorts', $conflictError);

            return;
        }

        $router->forceFill(['provisioning_settings' => $updated])->save();

        $this->showEditModal = false;

        Flux::toast(
            heading: ucfirst($prefix).' settings saved',
            text: 'Click "Provision via API" on this tab when you\'re ready to push these changes to the router.',
            variant: 'success',
        );

        $this->redirect(route('admin.routers.show', $router));
    }

    private function loadFromRouter(Router $router): void
    {
        $settings = (array) $router->provisioning_settings;
        $prefix = $this->network;
        $defaultSsid = $prefix === 'pos' ? 'MMS POS' : 'MMS Hotspot';
        $defaultVlan = $prefix === 'pos' ? 50 : 20;

        $this->ssid = (string) ($settings["{$prefix}_ssid"] ?? $defaultSsid);
        $this->vlan = (int) ($settings["{$prefix}_vlan"] ?? $defaultVlan);
        $this->gateway = (string) ($settings["{$prefix}_gateway"] ?? '');
        $this->networkCidr = (string) ($settings["{$prefix}_network"] ?? '');
        $this->pool = (string) ($settings["{$prefix}_pool"] ?? '');
        $this->wifiPassword = '';

        $advanced = (bool) ($settings['ports_advanced_mode'] ?? false);
        $this->extraPorts = $advanced
            ? (string) ($settings["extra_{$prefix}_ports"] ?? '')
            : (string) ($settings["extra_{$prefix}_port_numbers"] ?? '');
    }

    public function render()
    {
        $router = Router::find($this->routerId);
        $settings = (array) ($router->provisioning_settings ?? []);

        return view('livewire.admin.router-network-settings-card', [
            'router' => $router,
            'builtinWifiEnabled' => (bool) ($settings['enable_builtin_wifi'] ?? false),
            'portsAdvancedMode' => (bool) ($settings['ports_advanced_mode'] ?? false),
        ]);
    }
}
