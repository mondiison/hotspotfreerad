<?php

namespace Tests\Feature;

use App\Livewire\Admin\RoutersIndex;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RouterOsConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 2026-10-14, direct request after a live incident where a manual bridge-port
 * remove/re-add silently corrupted a bridge-vlan table on a board that turned
 * out to have hardware switch offload -- nobody knew that going in, since
 * nothing in the wizard surfaced it. Covers RoutersIndex::detectHardware()/
 * applyDetectedPortCount(), the Hardware step's "Detect from live router"
 * action.
 */
class RouterHardwareDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_detect_hardware_requires_an_existing_router(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('create')
            ->call('detectHardware')
            ->assertSet('detectHardwareError', 'Save the router once with working API connectivity first, then detect its hardware.')
            ->assertSet('detectedHardware', null);
    }

    public function test_detect_hardware_surfaces_a_clear_error_for_an_unreachable_router(): void
    {
        $router = Router::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Unreachable Hardware Router',
            'nas_identifier' => 'unreachable-hardware-router',
            // Reserved documentation-only address, guaranteed unreachable.
            'wireguard_internal_ip' => '192.0.2.30',
            'shared_secret' => 'radius-secret',
        ]);

        // api_username/api_password are auto-generated on Router::creating(),
        // so isConfigured() is true here -- the failure comes from the live
        // connection attempt itself failing against the reserved address.
        $component = Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->call('detectHardware')
            ->assertSet('detectedHardware', null);

        $this->assertNotNull($component->get('detectHardwareError'));
        $this->assertStringStartsWith('Could not detect hardware:', $component->get('detectHardwareError'));
    }

    public function test_detect_hardware_populates_the_detected_fields_and_flags_a_switch_chip(): void
    {
        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('detectHardware')
                ->with(\Mockery::type(Router::class))
                ->andReturn([
                    'success' => true,
                    'board_name' => 'RB5009UPr+S+IN',
                    'routeros_version' => '7.18.2 (stable)',
                    'port_count' => 9,
                    'ports' => [
                        ['name' => 'ether1', 'has_switch' => true],
                        ['name' => 'ether4', 'has_switch' => true],
                    ],
                    'has_switch_chip' => true,
                    'has_builtin_wireless' => false,
                    'wireless_driver' => null,
                ]);
        });

        $router = Router::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Detected Router',
            'nas_identifier' => 'detected-router',
            'wireguard_internal_ip' => '10.8.0.96',
            'shared_secret' => 'radius-secret',
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->call('detectHardware')
            ->assertSet('detectHardwareError', null)
            ->assertSet('detectedHardware.board_name', 'RB5009UPr+S+IN')
            ->assertSet('detectedHardware.port_count', 9)
            ->assertSet('detectedHardware.has_switch_chip', true)
            ->assertSet('detectedHardware.has_builtin_wireless', false)
            ->call('applyDetectedPortCount')
            ->assertSet('provisioning_settings.port_count', 9);
    }

    public function test_detect_hardware_reports_the_service_error_when_detection_fails(): void
    {
        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('detectHardware')
                ->with(\Mockery::type(Router::class))
                ->andReturn(['success' => false, 'error' => 'timed out']);
        });

        $router = Router::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Failing Detection Router',
            'nas_identifier' => 'failing-detection-router',
            'wireguard_internal_ip' => '10.8.0.97',
            'shared_secret' => 'radius-secret',
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('edit', $router->id)
            ->call('detectHardware')
            ->assertSet('detectedHardware', null)
            ->assertSet('detectHardwareError', 'Could not detect hardware: timed out');
    }

    public function test_switching_to_a_different_router_clears_previously_detected_hardware(): void
    {
        $shop = $this->shop();

        $firstRouter = Router::create([
            'shop_id' => $shop->id,
            'name' => 'First Router',
            'nas_identifier' => 'first-detected-router',
            'wireguard_internal_ip' => '10.8.0.98',
            'shared_secret' => 'radius-secret',
        ]);

        $secondRouter = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Second Router',
            'nas_identifier' => 'second-detected-router',
            'wireguard_internal_ip' => '10.8.0.99',
            'shared_secret' => 'radius-secret',
        ]);

        $this->mock(RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('detectHardware')
                ->with(\Mockery::type(Router::class))
                ->andReturn([
                    'success' => true,
                    'board_name' => 'hAP ax3',
                    'routeros_version' => '7.18.2 (stable)',
                    'port_count' => 5,
                    'ports' => [],
                    'has_switch_chip' => false,
                    'has_builtin_wireless' => true,
                    'wireless_driver' => 'wifi',
                ]);
        });

        Livewire::actingAs($this->superAdmin())
            ->test(RoutersIndex::class)
            ->call('edit', $firstRouter->id)
            ->call('detectHardware')
            ->assertSet('detectedHardware.board_name', 'hAP ax3')
            ->call('edit', $secondRouter->id)
            ->assertSet('detectedHardware', null)
            ->assertSet('detectHardwareError', null);
    }

    private function shop(): Shop
    {
        return Shop::create([
            'tenant_id' => Tenant::create([
                'company_name' => 'Demo Tenant',
                'owner_email' => fake()->unique()->safeEmail(),
            ])->id,
            'name' => 'Demo Shop',
            'is_active' => true,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
