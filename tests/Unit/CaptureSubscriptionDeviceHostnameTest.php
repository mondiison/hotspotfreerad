<?php

namespace Tests\Unit;

use App\Jobs\CaptureSubscriptionDeviceHostname;
use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\RouterOsConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CaptureSubscriptionDeviceHostnameTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_a_no_op_when_the_subscription_no_longer_exists(): void
    {
        $routerOs = Mockery::mock(RouterOsConnectionService::class);
        $routerOs->shouldNotReceive('isConfigured');
        $routerOs->shouldNotReceive('dhcpLeases');

        (new CaptureSubscriptionDeviceHostname(999999))->handle($routerOs);

        $this->assertTrue(true);
    }

    public function test_it_is_a_no_op_when_the_shop_has_no_routers(): void
    {
        $subscription = $this->subscription();

        $routerOs = Mockery::mock(RouterOsConnectionService::class);
        $routerOs->shouldNotReceive('dhcpLeases');

        (new CaptureSubscriptionDeviceHostname($subscription->id))->handle($routerOs);

        $this->assertNull($subscription->refresh()->device_hostname);
    }

    public function test_it_skips_a_router_with_no_api_credentials_yet(): void
    {
        $subscription = $this->subscription();
        $this->router($subscription->shop_id);

        $routerOs = Mockery::mock(RouterOsConnectionService::class);
        $routerOs->shouldReceive('isConfigured')->once()->andReturn(false);
        $routerOs->shouldNotReceive('dhcpLeases');

        (new CaptureSubscriptionDeviceHostname($subscription->id))->handle($routerOs);

        $this->assertNull($subscription->refresh()->device_hostname);
    }

    public function test_it_saves_the_hostname_from_a_matching_lease(): void
    {
        $subscription = $this->subscription(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $router = $this->router($subscription->shop_id);

        $routerOs = Mockery::mock(RouterOsConnectionService::class);
        $routerOs->shouldReceive('isConfigured')->once()->andReturn(true);
        $routerOs->shouldReceive('dhcpLeases')->once()->withArgs(
            fn (Router $argRouter): bool => $argRouter->id === $router->id
        )->andReturn([
            'success' => true,
            'leases' => [
                ['mac_address' => '11:22:33:44:55:66', 'hostname' => 'Some-Other-Device'],
                ['mac_address' => 'aa:bb:cc:dd:ee:ff', 'hostname' => "Johns-iPhone"],
            ],
        ]);

        (new CaptureSubscriptionDeviceHostname($subscription->id))->handle($routerOs);

        $this->assertSame('Johns-iPhone', $subscription->refresh()->device_hostname);
    }

    public function test_it_leaves_the_hostname_blank_when_no_lease_matches(): void
    {
        $subscription = $this->subscription(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $this->router($subscription->shop_id);

        $routerOs = Mockery::mock(RouterOsConnectionService::class);
        $routerOs->shouldReceive('isConfigured')->once()->andReturn(true);
        $routerOs->shouldReceive('dhcpLeases')->once()->andReturn([
            'success' => true,
            'leases' => [
                ['mac_address' => '11:22:33:44:55:66', 'hostname' => 'Some-Other-Device'],
            ],
        ]);

        (new CaptureSubscriptionDeviceHostname($subscription->id))->handle($routerOs);

        $this->assertNull($subscription->refresh()->device_hostname);
    }

    public function test_it_leaves_the_hostname_blank_when_the_matching_lease_has_no_hostname(): void
    {
        $subscription = $this->subscription(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $this->router($subscription->shop_id);

        $routerOs = Mockery::mock(RouterOsConnectionService::class);
        $routerOs->shouldReceive('isConfigured')->once()->andReturn(true);
        $routerOs->shouldReceive('dhcpLeases')->once()->andReturn([
            'success' => true,
            'leases' => [
                ['mac_address' => 'AA:BB:CC:DD:EE:FF', 'hostname' => null],
            ],
        ]);

        (new CaptureSubscriptionDeviceHostname($subscription->id))->handle($routerOs);

        $this->assertNull($subscription->refresh()->device_hostname);
    }

    public function test_it_tries_every_router_in_the_shop_until_one_matches(): void
    {
        $subscription = $this->subscription(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $routerWithoutMatch = $this->router($subscription->shop_id, 'router-no-match');
        $routerWithMatch = $this->router($subscription->shop_id, 'router-with-match');

        $routerOs = Mockery::mock(RouterOsConnectionService::class);
        $routerOs->shouldReceive('isConfigured')->twice()->andReturn(true);
        $routerOs->shouldReceive('dhcpLeases')->once()->withArgs(
            fn (Router $argRouter): bool => $argRouter->id === $routerWithoutMatch->id
        )->andReturn(['success' => false, 'error' => 'unreachable']);
        $routerOs->shouldReceive('dhcpLeases')->once()->withArgs(
            fn (Router $argRouter): bool => $argRouter->id === $routerWithMatch->id
        )->andReturn([
            'success' => true,
            'leases' => [
                ['mac_address' => 'AA:BB:CC:DD:EE:FF', 'hostname' => 'Found-On-Second-Router'],
            ],
        ]);

        (new CaptureSubscriptionDeviceHostname($subscription->id))->handle($routerOs);

        $this->assertSame('Found-On-Second-Router', $subscription->refresh()->device_hostname);
    }

    private function subscription(array $overrides = []): Subscription
    {
        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'One Hour Ultra',
            'price' => 500,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 3600,
            'speed_limit_profile' => '5M/5M',
        ]);

        return Subscription::create(array_merge([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'starts_at' => now(),
            'expires_at' => now()->addHour(),
        ], $overrides));
    }

    private function router(int $shopId, string $nasIdentifier = 'job-router'): Router
    {
        return Router::create([
            'shop_id' => $shopId,
            'name' => 'Job Router',
            'nas_identifier' => $nasIdentifier,
            'wireguard_internal_ip' => '10.8.0.' . random_int(2, 250),
            'shared_secret' => 'radius-secret',
        ]);
    }
}
