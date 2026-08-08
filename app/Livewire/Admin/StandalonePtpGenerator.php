<?php

namespace App\Livewire\Admin;

use App\Services\StandalonePtpScriptGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class StandalonePtpGenerator extends Component
{
    public int $step = 1;

    public string $link_name = 'PTP Link';

    public string $link_mode = 'routed';

    public string $link_topology = 'ptp';

    public string $ap_end = 'radio_a';

    public array $radio_a = [
        'identity' => 'Radio A',
        'ros_version' => '7',
        'wireless_interface' => 'wifi1',
        'cpe_only' => false,
        'add_remote_route' => false,
        'remote_network' => '',
    ];

    public array $radio_b = [
        'identity' => 'Radio B',
        'ros_version' => '7',
        'wireless_interface' => 'wifi1',
        'cpe_only' => false,
        'add_remote_route' => false,
        'remote_network' => '',
    ];

    public int $frequency_mhz = 5745;

    public int $channel_width_mhz = 20;

    public string $ssid = 'ptp-link';

    public string $security_mode = 'wpa2';

    public string $psk = '';

    public float $distance_km = 1.0;

    public string $ptp_subnet = '10.20.30.0/30';

    public ?array $generatedScripts = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
    }

    public function updated(string $name, mixed $value): void
    {
        if ($name === 'radio_a.ros_version') {
            $this->radio_a['wireless_interface'] = $value === '6' ? 'wlan1' : 'wifi1';
        }

        if ($name === 'radio_b.ros_version') {
            $this->radio_b['wireless_interface'] = $value === '6' ? 'wlan1' : 'wifi1';
        }

        // A CPE/dish-style radio (e.g. SXTsq, LHG, Disc) is on MikroTik's base
        // wireless license tier, which covers single-peer "bridge" mode fine
        // but not the multi-client "ap-bridge" mode a point-to-multipoint hub
        // needs. So this only matters for 'ptmp' topology -- in plain 'ptp'
        // mode a CPE-only radio can still be the AP end. If the admin marks
        // the current AP end as CPE-only while topology is 'ptmp' (or flips
        // to 'ptmp' while the current AP end is already CPE-only), flip the
        // AP role to the other radio automatically, unless it's CPE-only too.
        if (in_array($name, ['radio_a.cpe_only', 'radio_b.cpe_only', 'link_topology'], true)) {
            $this->reconcileApEndForTopology();
        }
    }

    private function reconcileApEndForTopology(): void
    {
        if ($this->link_topology !== 'ptmp') {
            return;
        }

        $current = $this->{$this->ap_end};
        $otherKey = $this->ap_end === 'radio_a' ? 'radio_b' : 'radio_a';

        if (($current['cpe_only'] ?? false) && ! ($this->{$otherKey}['cpe_only'] ?? false)) {
            $this->ap_end = $otherKey;
        }
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

    public function generate(StandalonePtpScriptGenerator $generator): void
    {
        $this->validate($this->allRules());

        $this->generatedScripts = $generator->generate([
            'link_name' => $this->link_name,
            'link_mode' => $this->link_mode,
            'link_topology' => $this->link_topology,
            'ap_end' => $this->ap_end,
            'frequency_mhz' => $this->frequency_mhz,
            'channel_width_mhz' => $this->channel_width_mhz,
            'ssid' => $this->ssid,
            'security_mode' => $this->security_mode,
            'psk' => $this->psk,
            'distance_km' => $this->distance_km,
            'ptp_subnet' => $this->ptp_subnet,
            'radio_a' => $this->radio_a,
            'radio_b' => $this->radio_b,
        ]);

        $this->step = 5;
    }

    public function startOver(): void
    {
        $this->generatedScripts = null;
        $this->step = 1;
    }

    private function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'link_name' => ['required', 'string', 'max:64'],
                'radio_a.identity' => ['required', 'string', 'max:64'],
                'radio_a.ros_version' => ['required', Rule::in(['6', '7'])],
                'radio_b.identity' => ['required', 'string', 'max:64'],
                'radio_b.ros_version' => ['required', Rule::in(['6', '7'])],
            ],
            2 => [
                'ap_end' => ['required', Rule::in(['radio_a', 'radio_b']), function ($attribute, $value, $fail) {
                    if ($this->link_topology === 'ptmp' && ($this->{$value}['cpe_only'] ?? false)) {
                        $fail('This radio is CPE-only and can\'t run AP mode for point-to-multipoint -- pick the other radio as the AP/hub end.');
                    }
                }],
                'link_mode' => ['required', Rule::in(['routed', 'bridged'])],
                'link_topology' => ['required', Rule::in(['ptp', 'ptmp'])],
                'radio_a.wireless_interface' => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z-]+\d+$/'],
                'radio_a.cpe_only' => ['boolean'],
                'radio_b.wireless_interface' => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z-]+\d+$/'],
                'radio_b.cpe_only' => ['boolean', function ($attribute, $value, $fail) {
                    if ($this->link_topology === 'ptmp' && $value && ($this->radio_a['cpe_only'] ?? false)) {
                        $fail('Both radios can\'t be CPE-only for a point-to-multipoint AP end -- at least one needs full AP support.');
                    }
                }],
            ],
            3 => [
                'frequency_mhz' => ['required', 'integer', 'min:2312', 'max:6000'],
                'channel_width_mhz' => ['required', Rule::in([20, 40])],
                'ssid' => ['required', 'string', 'max:32'],
                'security_mode' => ['required', Rule::in(['wpa2', 'wpa2_wpa3']), function ($attribute, $value, $fail) {
                    if ($value === 'wpa2_wpa3' && ($this->radio_a['ros_version'] !== '7' || $this->radio_b['ros_version'] !== '7')) {
                        $fail('Mixed WPA2/WPA3 needs both radios on RouterOS 7.');
                    }
                }],
                'psk' => ['required', 'string', 'min:8', 'max:63'],
            ],
            4 => [
                'distance_km' => ['required', 'numeric', 'min:0', 'max:300'],
                'ptp_subnet' => ['required', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/(2[4-9]|30)$/'],
                'radio_a.add_remote_route' => ['boolean'],
                'radio_a.remote_network' => ['required_if:radio_a.add_remote_route,true', 'nullable', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/([0-9]|[12]\d|3[0-2])$/'],
                'radio_b.add_remote_route' => ['boolean'],
                'radio_b.remote_network' => ['required_if:radio_b.add_remote_route,true', 'nullable', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/([0-9]|[12]\d|3[0-2])$/'],
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
        return view('livewire.admin.standalone-ptp-generator');
    }
}
