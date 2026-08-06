<?php

namespace Tests\Feature;

use App\Livewire\Admin\NotificationBell;
use App\Livewire\Admin\ProfileCard;
use App\Models\Router;
use App\Models\RouterMetricSample;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\RouterAlertNotification;
use App\Services\RouterMetricSamplingService;
use App\Services\RouterOsConnectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class RouterAlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeRouterForTenant(Tenant $tenant, string $ip): Router
    {
        $shop = Shop::create(['tenant_id' => $tenant->id, 'name' => 'Demo Shop']);

        return Router::create([
            'shop_id' => $shop->id,
            'name' => 'Alert Router',
            'nas_identifier' => 'alert-router-'.$ip,
            'wireguard_internal_ip' => $ip,
            'shared_secret' => 'radius-secret',
        ]);
    }

    public function test_notification_is_queued(): void
    {
        $router = $this->makeRouterForTenant(
            Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']),
            '192.0.2.30'
        );

        $notification = new RouterAlertNotification($router, RouterAlertNotification::TYPE_OFFLINE, 'test message');

        $this->assertInstanceOf(ShouldQueue::class, $notification);
    }

    public function test_email_channel_is_included_only_when_user_opted_in(): void
    {
        $router = $this->makeRouterForTenant(
            Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']),
            '192.0.2.31'
        );
        $notification = new RouterAlertNotification($router, RouterAlertNotification::TYPE_OFFLINE, 'test message');

        $optedIn = User::factory()->create(['notify_by_email' => true]);
        $optedOut = User::factory()->create(['notify_by_email' => false]);

        $this->assertSame(['database', 'mail'], $notification->via($optedIn));
        $this->assertSame(['database'], $notification->via($optedOut));
    }

    public function test_offline_alert_fires_only_on_transition_not_while_persisting(): void
    {
        Notification::fake();

        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $router = $this->makeRouterForTenant($tenant, '192.0.2.32');
        User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $sampler = app(RouterMetricSamplingService::class);

        // Seed one prior sample where the router WAS reachable, dated
        // before "now" so it's genuinely the most recent prior sample.
        RouterMetricSample::create([
            'router_id' => $router->id,
            'latency_ms' => 5,
            'sampled_at' => now()->subMinutes(5),
        ]);

        // Sampling now: the reserved test IP never responds, so this is a
        // reachable -> unreachable transition and should alert once.
        $sampler->sample($router);
        Notification::assertSentTimes(RouterAlertNotification::class, 1);

        // Sampling again while still unreachable must not re-fire.
        $sampler->sample($router);
        Notification::assertSentTimes(RouterAlertNotification::class, 1);
    }

    public function test_first_ever_sample_never_fires_an_offline_alert(): void
    {
        Notification::fake();

        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $router = $this->makeRouterForTenant($tenant, '192.0.2.36');
        User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        // No prior sample at all -- there is nothing to transition from.
        app(RouterMetricSamplingService::class)->sample($router);

        Notification::assertNothingSent();
    }

    public function test_alert_recipients_are_super_admins_and_the_owning_tenants_admins_only(): void
    {
        Notification::fake();

        $ownTenant = Tenant::create(['company_name' => 'Own ISP', 'owner_email' => 'own@example.com']);
        $otherTenant = Tenant::create(['company_name' => 'Other ISP', 'owner_email' => 'other@example.com']);
        $router = $this->makeRouterForTenant($ownTenant, '192.0.2.33');

        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $ownTenantAdmin = User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => $ownTenant->id, 'is_active' => true]);
        $otherTenantAdmin = User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => $otherTenant->id, 'is_active' => true]);
        $inactiveSuperAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => false]);

        RouterMetricSample::create([
            'router_id' => $router->id,
            'latency_ms' => 5,
            'sampled_at' => now()->subMinutes(5),
        ]);

        app(RouterMetricSamplingService::class)->sample($router);

        Notification::assertSentTo($superAdmin, RouterAlertNotification::class);
        Notification::assertSentTo($ownTenantAdmin, RouterAlertNotification::class);
        Notification::assertNotSentTo($otherTenantAdmin, RouterAlertNotification::class);
        Notification::assertNotSentTo($inactiveSuperAdmin, RouterAlertNotification::class);
    }

    public function test_high_cpu_alert_fires_once_when_crossing_threshold(): void
    {
        Notification::fake();

        $tenant = Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']);
        $router = $this->makeRouterForTenant($tenant, '192.0.2.34');
        User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        // Previous sample below the threshold -- no alert yet.
        RouterMetricSample::create([
            'router_id' => $router->id,
            'cpu_percent' => 50,
            'sampled_at' => now()->subMinutes(5),
        ]);

        $this->mock(\App\Services\RouterOsConnectionService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('systemResource')->andReturn([
                'success' => true,
                'cpu_percent' => 92,
                'ram_used_bytes' => 0,
                'ram_total_bytes' => 0,
                'disk_used_bytes' => 0,
                'disk_total_bytes' => 0,
                'uptime_seconds' => 100,
                'board_name' => 'Test',
                'version' => '7.0',
            ]);
            $mock->shouldReceive('systemHealth')->andReturn(['success' => true, 'fields' => []]);
        });

        app(RouterMetricSamplingService::class)->sample($router);

        Notification::assertSentTimes(RouterAlertNotification::class, 1);
    }

    public function test_profile_can_toggle_email_notifications(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true, 'notify_by_email' => true]);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Admin\ProfileCard::class)
            ->call('toggleEmailNotifications')
            ->assertSet('user.notify_by_email', false);

        $this->assertFalse($user->refresh()->notify_by_email);
    }

    public function test_notification_bell_shows_unread_count_and_marks_as_read(): void
    {
        $router = $this->makeRouterForTenant(
            Tenant::create(['company_name' => 'Demo ISP', 'owner_email' => 'owner@example.com']),
            '192.0.2.35'
        );
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $user->notify(new RouterAlertNotification($router, RouterAlertNotification::TYPE_OFFLINE, 'Router offline'));

        $notificationId = $user->notifications()->first()->id;
        $this->assertSame(1, $user->fresh()->unreadNotifications()->count());

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Admin\NotificationBell::class)
            ->assertSee('Router offline')
            ->call('markAsRead', $notificationId);

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }
}
