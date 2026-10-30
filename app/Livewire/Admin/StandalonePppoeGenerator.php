<?php

namespace App\Livewire\Admin;

use App\Services\StandalonePppoeScriptGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class StandalonePppoeGenerator extends Component
{
    public int $step = 1;

    public string $router_identity = 'Starlink PPPoE';

    public string $wan_download_mbps = '100';

    public string $wan_upload_mbps = '15';

    /** The Starlink router's own network, where the operator's PC sits on the Starlink Wi-Fi. */
    public string $admin_network = '192.168.1.0/24';

    /** @var array<string, string> */
    public array $port_roles = [
        'ether2' => 'pppoe',
        'ether3' => 'pppoe',
        'ether4' => 'pppoe',
        'ether5' => 'pppoe',
    ];

    public string $pppoe_network = '10.20.0.1/24';

    public string $pppoe_service_name = 'internet';

    public string $lan_network = '192.168.88.1/24';

    public string $lan_device_download_mbps = '10';

    public string $lan_device_upload_mbps = '3';

    public array $plans = [
        ['name' => 'Basic 5M', 'download_mbps' => '5', 'upload_mbps' => '2'],
        ['name' => 'Standard 10M', 'download_mbps' => '10', 'upload_mbps' => '3'],
    ];

    public ?string $generatedScript = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
    }

    public function addPlan(): void
    {
        $this->plans[] = ['name' => '', 'download_mbps' => '5', 'upload_mbps' => '2'];
    }

    public function removePlan(int $index): void
    {
        unset($this->plans[$index]);
        $this->plans = array_values($this->plans);
    }

    public function nextStep(): void
    {
        $this->validate($this->rulesForStep($this->step));
        $this->step = min(4, $this->step + 1);
    }

    public function previousStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function goToStep(int $targetStep): void
    {
        if ($targetStep >= 1 && $targetStep <= $this->step) {
            $this->step = $targetStep;
        }
    }

    public function generate(StandalonePppoeScriptGenerator $generator): void
    {
        $this->validate(array_merge($this->rulesForStep(1), $this->rulesForStep(2), $this->rulesForStep(3)));

        $this->generatedScript = $generator->generate([
            'router_identity' => $this->router_identity,
            'wan_port' => 'ether1',
            'wan_download_mbps' => (float) $this->wan_download_mbps,
            'wan_upload_mbps' => (float) $this->wan_upload_mbps,
            'admin_network' => $this->admin_network,
            'port_roles' => $this->port_roles,
            'pppoe_network' => $this->pppoe_network,
            'pppoe_service_name' => $this->pppoe_service_name,
            'lan_network' => $this->lan_network,
            'lan_device_download_mbps' => (float) $this->lan_device_download_mbps,
            'lan_device_upload_mbps' => (float) $this->lan_device_upload_mbps,
            'plans' => $this->plans,
        ]);

        $this->step = 4;
    }

    public function startOver(): void
    {
        $this->generatedScript = null;
        $this->step = 1;
    }

    public function usesRole(string $role): bool
    {
        return in_array($role, $this->port_roles, true);
    }

    private function rulesForStep(int $step): array
    {
        $network = ['required', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/24$/'];
        $speed = ['required', 'numeric', 'min:0.1', 'max:10000'];

        return match ($step) {
            1 => [
                'router_identity' => ['required', 'string', 'max:64'],
                'wan_download_mbps' => $speed,
                'wan_upload_mbps' => $speed,
                'admin_network' => ['required', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/\d{1,2}$/'],
            ],
            2 => [
                'port_roles' => ['required', 'array', function ($attribute, $value, $fail) {
                    if (! in_array('pppoe', $value, true) && ! in_array('lan', $value, true)) {
                        $fail('Set at least one port to PPPoE clients or Plain LAN.');
                    }
                }],
                'port_roles.*' => ['required', Rule::in(array_keys(StandalonePppoeScriptGenerator::PORT_ROLES))],
                'pppoe_network' => $this->usesRole('pppoe') ? $network : ['nullable'],
                'pppoe_service_name' => $this->usesRole('pppoe') ? ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'] : ['nullable'],
                'lan_network' => $this->usesRole('lan') ? $network : ['nullable'],
            ],
            3 => [
                'plans' => $this->usesRole('pppoe') ? ['required', 'array', 'min:1'] : ['array'],
                'plans.*.name' => ['required', 'string', 'max:32'],
                'plans.*.download_mbps' => $speed,
                'plans.*.upload_mbps' => $speed,
                'lan_device_download_mbps' => $this->usesRole('lan') ? $speed : ['nullable'],
                'lan_device_upload_mbps' => $this->usesRole('lan') ? $speed : ['nullable'],
            ],
            default => [],
        };
    }

    public function render()
    {
        return view('livewire.admin.standalone-pppoe-generator');
    }
}
