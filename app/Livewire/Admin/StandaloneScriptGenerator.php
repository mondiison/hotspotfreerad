<?php

namespace App\Livewire\Admin;

use App\Services\StandaloneHotspotScriptGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class StandaloneScriptGenerator extends Component
{
    public int $step = 1;

    public string $router_identity = 'My Router';

    public string $hotspot_name = 'My Hotspot';

    public string $ros_version = '7';

    public bool $has_wireless = true;

    public string $wan_port = 'ether1';

    public int $ethernet_port_count = 5;

    public string $wireless_interface = 'wifi1';

    public bool $enable_mgmt_network = true;

    public string $mgmt_network = '10.10.20.1/24';

    public string $mgmt_password = '';

    public string $hotspot_network = '10.10.10.1/24';

    public array $profiles = [
        ['name' => '1 Hour', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 10, 'voucher_prefix' => '', 'voucher_code_length' => 8, 'voucher_username_password_same' => true, 'voucher_character_set' => 'alnum_safe'],
    ];

    public string $voucher_template = 'grid';

    public ?string $generatedScript = null;

    public array $generatedVouchers = [];

    public function mount(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
    }

    public function updatedHasWireless(): void
    {
        if (! $this->has_wireless) {
            $this->wireless_interface = '';
        } elseif ($this->wireless_interface === '') {
            $this->wireless_interface = $this->ros_version === '6' ? 'wlan1' : 'wifi1';
        }
    }

    public function updatedRosVersion(): void
    {
        if (! $this->has_wireless) {
            return;
        }

        $this->wireless_interface = $this->ros_version === '6' ? 'wlan1' : 'wifi1';
    }

    public function addProfile(): void
    {
        $this->profiles[] = ['name' => '', 'download_mbps' => 5, 'upload_mbps' => 5, 'session_minutes' => 60, 'shared_users' => 1, 'voucher_count' => 10, 'voucher_prefix' => '', 'voucher_code_length' => 8, 'voucher_username_password_same' => true, 'voucher_character_set' => 'alnum_safe'];
    }

    public function removeProfile(int $index): void
    {
        unset($this->profiles[$index]);
        $this->profiles = array_values($this->profiles);
    }

    public function nextStep(): void
    {
        $this->validate($this->rulesForStep($this->step));
        $this->step = min(5, $this->step + 1);
    }

    public function previousStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function generate(StandaloneHotspotScriptGenerator $generator): void
    {
        $this->validate($this->allRules());

        $result = $generator->generate([
            'router_identity' => $this->router_identity,
            'hotspot_name' => $this->hotspot_name,
            'ros_version' => $this->ros_version,
            'has_wireless' => $this->has_wireless,
            'wan_port' => $this->wan_port,
            'ethernet_port_count' => $this->ethernet_port_count,
            'wireless_interface' => $this->wireless_interface,
            'hotspot_network' => $this->hotspot_network,
            'enable_mgmt_network' => $this->enable_mgmt_network,
            'mgmt_network' => $this->mgmt_network,
            'mgmt_password' => $this->mgmt_password,
            'profiles' => $this->profiles,
        ]);

        $this->generatedScript = $result['script'];
        $this->generatedVouchers = $result['vouchers'];
        $this->step = 5;
    }

    public function startOver(): void
    {
        $this->generatedScript = null;
        $this->generatedVouchers = [];
        $this->step = 1;
    }

    private function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'router_identity' => ['required', 'string', 'max:64'],
                'hotspot_name' => ['required', 'string', 'max:64'],
                'ros_version' => ['required', Rule::in(['6', '7'])],
                'has_wireless' => ['boolean'],
                'wan_port' => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z-]+\d+$/'],
                'ethernet_port_count' => ['required', 'integer', 'min:2', 'max:64', function ($attribute, $value, $fail) {
                    if (! preg_match('/(\d+)$/', $this->wan_port, $matches)) {
                        return;
                    }

                    if ((int) $matches[1] > $value) {
                        $fail('The WAN port number is higher than the total Ethernet port count.');
                    }
                }],
                'wireless_interface' => ['required_if:has_wireless,true', 'nullable', 'string', 'max:32'],
            ],
            2 => [
                'enable_mgmt_network' => ['boolean'],
                'mgmt_network' => ['required_if:enable_mgmt_network,true', 'nullable', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/24$/'],
                'mgmt_password' => [
                    ($this->enable_mgmt_network && $this->has_wireless) ? 'required' : 'nullable',
                    'string', 'min:8', 'max:63',
                ],
            ],
            3 => [
                'hotspot_network' => ['required', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/24$/'],
                'profiles' => ['required', 'array', 'min:1'],
                'profiles.*.name' => ['required', 'string', 'max:32'],
                'profiles.*.download_mbps' => ['required', 'integer', 'min:1', 'max:1000'],
                'profiles.*.upload_mbps' => ['required', 'integer', 'min:1', 'max:1000'],
                'profiles.*.session_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
                'profiles.*.shared_users' => ['required', 'integer', 'min:1', 'max:50'],
                'profiles.*.voucher_count' => ['required', 'integer', 'min:0', 'max:500'],
                'profiles.*.voucher_prefix' => ['nullable', 'string', 'max:8', 'regex:/^[A-Za-z0-9-]*$/'],
                'profiles.*.voucher_code_length' => ['required', 'integer', 'min:4', 'max:16'],
                'profiles.*.voucher_username_password_same' => ['boolean'],
                'profiles.*.voucher_character_set' => ['required', Rule::in(array_keys(StandaloneHotspotScriptGenerator::CHARACTER_SETS))],
            ],
            4 => [
                'voucher_template' => ['required', Rule::in(['grid', 'receipt', 'compact'])],
            ],
            default => [],
        };
    }

    private function allRules(): array
    {
        return array_merge(
            $this->rulesForStep(1),
            $this->rulesForStep(2),
            $this->rulesForStep(3),
            $this->rulesForStep(4),
        );
    }

    public function render()
    {
        return view('livewire.admin.standalone-script-generator');
    }
}
