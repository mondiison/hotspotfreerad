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
 * that section's own tab on the router show page (Hotspot Script / PPPoE
 * Script / POS Script), instead of needing the full 4-step router wizard for
 * a single field. Reused for all three networks rather than near-identical
 * components per network, since the field shape mostly overlaps -- SSID only
 * applies to hotspot/pos (PPPoE has no SSID at all, being wired/PPP dial-in
 * rather than Wi-Fi -- see supportsSsid()), and the Wi-Fi password only
 * applies to pos. PPPoE gained its own address pool and extra-untagged-port
 * support on 2026-09-23 (see supportsExtraPorts()/supportsAddressPool()),
 * once PPP's own client addressing (via remote-address, not DHCP) and
 * extra-access-port model were added to match Staff/POS.
 * Mirrors the small-embedded-Livewire-island pattern RouterCredentialsCard
 * already established on this same (otherwise plain Blade) page -- no
 * Livewire conversion needed for the rest of the page.
 *
 * Deliberately save-only: it never pushes live to the router itself. Saving
 * updates provisioning_settings; pushing that live stays a separate,
 * explicit "Provision via API" click, matching how the full wizard already
 * separates "save" from "provision". None of these fields (SSID/VLAN/
 * addressing/ports) are actually read by this network's own incremental
 * script (generateScript()/generatePppoeScript()/generatePosScript() only
 * read RADIUS/profile settings) -- they only affect the Fresh Infrastructure
 * Script, which is what actually creates the VLAN/addressing/Wi-Fi
 * configuration. The card's own copy says this plainly rather than implying
 * the tab it lives on will visibly change.
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
        $extraPortsEditable = $this->extraPortsEditable($existing);

        $rules = [
            'vlan' => ['required', 'integer', 'min:1', 'max:4094'],
            'gateway' => ['nullable', 'string', 'max:32'],
        ];

        if ($this->supportsSsid()) {
            $rules['ssid'] = ['nullable', 'string', 'max:32'];
        }

        if ($this->supportsAddressPool()) {
            $rules['networkCidr'] = ['nullable', 'string', 'max:32'];
            $rules['pool'] = ['nullable', 'string', 'max:64'];
        }

        if ($extraPortsEditable) {
            $rules['extraPorts'] = $advanced
                ? ['nullable', 'string', 'max:200']
                : ['nullable', 'string', 'max:120', 'regex:/^\d+(,\s*\d+)*$/'];
        }

        if ($this->supportsWifiPassword()) {
            $rules['wifiPassword'] = ['nullable', 'string', 'min:8', 'max:63'];
        }

        $validated = $this->validate($rules);

        $updated = $existing;
        $updated["{$prefix}_vlan"] = $validated['vlan'];
        $updated["{$prefix}_gateway"] = $validated['gateway'] ?? '';

        if ($this->supportsSsid()) {
            $updated["{$prefix}_ssid"] = $validated['ssid'] ?? '';
        }

        if ($this->supportsAddressPool()) {
            $updated["{$prefix}_network"] = $validated['networkCidr'] ?? '';
            $updated["{$prefix}_pool"] = $validated['pool'] ?? '';
        }

        if ($this->supportsExtraPorts()) {
            // Confirmed live 2026-09-23: RouterManagementService::rules() requires
            // extra_pos_port_numbers to be BLANK whenever enable_pos is false
            // (Rule::prohibitedIf) -- this card used to let extraPorts be saved
            // regardless, which silently poisoned a router's settings (a
            // non-blank value while enable_pos stayed false) and broke the
            // wizard's own step-2-to-3 validation on that router with no
            // visible error, since the wizard hides the "Extra POS port" input
            // entirely while POS is disabled. Forced blank here whenever this
            // network isn't actually enabled, matching that constraint exactly
            // instead of the earlier "pre-configure before enabling" design,
            // which turned out to directly contradict it.
            $extraPorts = $extraPortsEditable ? ($validated['extraPorts'] ?? '') : '';

            if ($advanced) {
                $updated["extra_{$prefix}_ports"] = $extraPorts;
                $updated["extra_{$prefix}_port_numbers"] = implode(
                    ',',
                    RouterPortLayout::portNumbersFromInterfaceList($extraPorts) ?? []
                );
            } else {
                $updated["extra_{$prefix}_port_numbers"] = $extraPorts;
                $updated["extra_{$prefix}_ports"] = implode(
                    ',',
                    RouterPortLayout::interfaceNamesFromNumberList($extraPorts)
                );
            }
        }

        if ($this->supportsWifiPassword()) {
            $updated["{$prefix}_wifi_password"] = filled($validated['wifiPassword'] ?? null)
                ? $validated['wifiPassword']
                : ($existing["{$prefix}_wifi_password"] ?? '');
        }

        if ($extraPortsEditable) {
            // Reuses the exact same cross-field conflict check the full wizard
            // runs (RouterManagementService::portConflictRule(), now public
            // for exactly this) -- built against a copy of the router's full
            // existing settings with just this network's fields overridden, so
            // a saved extra port here still can't collide with another role's
            // port. A blank extraPorts value (the $extraPortsEditable-false
            // branch above) never reaches here -- portConflictRule() itself
            // no-ops on a blank value, so there's nothing to check.
            $conflictError = null;
            $conflictRule = $routers->portConflictRule($updated);
            $conflictRule(
                'provisioning_settings.port_count',
                $updated['port_count'] ?? null,
                function (string $message) use (&$conflictError): void {
                    $conflictError = $message;
                }
            );

            if ($conflictError !== null) {
                $this->addError('extraPorts', $conflictError);

                return;
            }
        }

        $router->forceFill(['provisioning_settings' => $updated])->save();

        $this->showEditModal = false;

        Flux::toast(
            heading: $this->label().' settings saved',
            text: 'See the Fresh Infrastructure Script tab for the updated script, and push it via API (or paste it) when ready.',
            variant: 'success',
        );

        // A full reload so the Fresh Infrastructure Script tab -- the one
        // that actually reads these settings, see this class's own docblock
        // -- reflects the change; it's rendered once by the controller at
        // page load, not reactively by this Livewire island.
        $this->redirect(route('admin.routers.show', $router));
    }

    public function label(): string
    {
        return match ($this->network) {
            'pos' => 'POS',
            'pppoe' => 'PPPoE',
            default => 'Hotspot',
        };
    }

    public function supportsSsid(): bool
    {
        return in_array($this->network, ['hotspot', 'pos'], true);
    }

    public function supportsAddressPool(): bool
    {
        return in_array($this->network, ['hotspot', 'pos', 'pppoe'], true);
    }

    public function supportsExtraPorts(): bool
    {
        return in_array($this->network, ['hotspot', 'pos', 'pppoe'], true);
    }

    public function supportsWifiPassword(): bool
    {
        return $this->network === 'pos';
    }

    /**
     * Whether extra untagged ports can actually be set right now for this
     * network -- true for hotspot unconditionally (no enable_hotspot flag
     * exists, it's always on), but for POS/PPPoE only when enable_pos/
     * enable_pppoe is currently true, matching RouterManagementService::
     * rules()'s own Rule::prohibitedIf(!enable_pos)/Rule::prohibitedIf(!enable_pppoe)
     * constraints on extra_pos_port_numbers/extra_pppoe_port_numbers exactly.
     * See the note in save() for why this matters.
     */
    public function extraPortsEditable(array $settings): bool
    {
        if (! $this->supportsExtraPorts()) {
            return false;
        }

        if ($this->network === 'pos') {
            return (bool) ($settings['enable_pos'] ?? false);
        }

        if ($this->network === 'pppoe') {
            return (bool) ($settings['enable_pppoe'] ?? false);
        }

        return true;
    }

    private function loadFromRouter(Router $router): void
    {
        $settings = (array) $router->provisioning_settings;
        $prefix = $this->network;
        $defaultVlan = match ($prefix) {
            'pos' => 50,
            'pppoe' => 40,
            default => 20,
        };

        $this->vlan = (int) ($settings["{$prefix}_vlan"] ?? $defaultVlan);
        $this->gateway = (string) ($settings["{$prefix}_gateway"] ?? '');
        $this->wifiPassword = '';

        if ($this->supportsSsid()) {
            $defaultSsid = $prefix === 'pos' ? 'MMS POS' : 'MMS Hotspot';
            $this->ssid = (string) ($settings["{$prefix}_ssid"] ?? $defaultSsid);
        }

        if ($this->supportsAddressPool()) {
            $this->networkCidr = (string) ($settings["{$prefix}_network"] ?? '');
            $this->pool = (string) ($settings["{$prefix}_pool"] ?? '');
        }

        if ($this->extraPortsEditable($settings)) {
            $advanced = (bool) ($settings['ports_advanced_mode'] ?? false);
            $this->extraPorts = $advanced
                ? (string) ($settings["extra_{$prefix}_ports"] ?? '')
                : (string) ($settings["extra_{$prefix}_port_numbers"] ?? '');
        }
    }

    public function render()
    {
        $router = Router::find($this->routerId);
        $settings = (array) ($router->provisioning_settings ?? []);

        return view('livewire.admin.router-network-settings-card', [
            'router' => $router,
            'builtinWifiEnabled' => (bool) ($settings['enable_builtin_wifi'] ?? false),
            'portsAdvancedMode' => (bool) ($settings['ports_advanced_mode'] ?? false),
            'extraPortsEditable' => $this->extraPortsEditable($settings),
        ]);
    }
}
