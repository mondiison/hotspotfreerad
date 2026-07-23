<?php

namespace Tests\Feature;

use App\Livewire\Admin\PppoeSubscribersIndex;
use App\Models\Package;
use App\Models\PppoeSubscriber;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPppoeSubscriberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createRadiusTables();
    }

    public function test_tenant_admin_can_create_pppoe_subscriber_and_sync_radius(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(PppoeSubscribersIndex::class)
            ->set('shop_id', (string) $shop->id)
            ->set('package_id', (string) $package->id)
            ->set('username', 'customer001')
            ->set('password', 'secret-pass')
            ->set('full_name', 'Customer One')
            ->set('phone', '08000000000')
            ->set('starts_at', now()->format('Y-m-d\TH:i'))
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('PPPoE subscriber created and synced to RADIUS.');

        $subscriber = PppoeSubscriber::firstOrFail();

        $this->assertSame('secret-pass', $subscriber->password);
        $this->assertDatabaseHas('pppoe_subscribers', [
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'customer001',
            'full_name' => 'Customer One',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'customer001',
            'attribute' => 'Cleartext-Password',
            'value' => 'secret-pass',
        ]);
        $this->assertDatabaseHas('radusergroup', [
            'username' => 'customer001',
            'groupname' => $package->refresh()->radius_group_name,
        ]);
    }

    public function test_tenant_admin_can_disable_pppoe_subscriber_and_revoke_radius(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $subscriber = PppoeSubscriber::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'customer001',
            'password' => 'secret-pass',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'is_active' => true,
        ]);
        app(\App\Services\RadiusProvisioningService::class)->provisionPppoeSubscriber($subscriber);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(PppoeSubscribersIndex::class)
            ->call('edit', $subscriber->id)
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('PPPoE subscriber updated and synced to RADIUS.');

        $this->assertFalse($subscriber->refresh()->is_active);
        $this->assertDatabaseMissing('radcheck', ['username' => 'customer001']);
        $this->assertDatabaseMissing('radusergroup', ['username' => 'customer001']);
    }

    public function test_tenant_admin_only_sees_their_pppoe_subscribers(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        [$otherTenant, $otherShop, $otherPackage] = $this->fixture('Other Tenant', 'Other Shop');
        PppoeSubscriber::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'own-customer',
            'password' => 'secret-pass',
            'is_active' => true,
        ]);
        PppoeSubscriber::create([
            'shop_id' => $otherShop->id,
            'package_id' => $otherPackage->id,
            'username' => 'other-customer',
            'password' => 'secret-pass',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.pppoe-subscribers.index'))
            ->assertOk()
            ->assertSee('own-customer')
            ->assertDontSee('other-customer');
    }

    public function test_tenant_admin_can_renew_pppoe_subscriber(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $currentExpiry = now()->addDays(5);
        $subscriber = PppoeSubscriber::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'customer001',
            'password' => 'secret-pass',
            'starts_at' => now()->subMonth(),
            'expires_at' => $currentExpiry,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(PppoeSubscribersIndex::class)
            ->call('renew', $subscriber->id)
            ->assertSee('PPPoE subscriber renewed and synced to RADIUS.');

        $subscriber->refresh();

        $this->assertTrue($subscriber->is_active);
        $this->assertTrue($subscriber->expires_at->greaterThan($currentExpiry->copy()->addDays(29)));
        $this->assertDatabaseHas('radcheck', [
            'username' => 'customer001',
            'attribute' => 'Cleartext-Password',
            'value' => 'secret-pass',
        ]);
    }

    public function test_pppoe_subscriber_index_shows_usage_and_inspect_sessions(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $subscriber = PppoeSubscriber::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'customer001',
            'password' => 'secret-pass',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        \DB::table('radacct')->insert([
            'acctsessionid' => 'session-001',
            'acctuniqueid' => 'unique-001',
            'username' => 'customer001',
            'nasipaddress' => '10.8.0.10',
            'framedipaddress' => '10.10.10.2',
            'callingstationid' => 'AA:BB:CC:DD:EE:FF',
            'acctstarttime' => now()->subHour(),
            'acctupdatetime' => now(),
            'acctstoptime' => null,
            'acctsessiontime' => 3600,
            'acctinputoctets' => 512,
            'acctoutputoctets' => 1536,
            'acctterminatecause' => null,
        ]);

        $this->actingAs($user);

        Livewire::test(PppoeSubscribersIndex::class)
            ->assertSee('2.0 KB')
            ->assertSee('1 online')
            ->call('inspect', $subscriber->id)
            ->assertSee('PPPoE Activity')
            ->assertSee('session-001')
            ->assertSee('Still online / no stop');
    }

    private function fixture(string $tenantName = 'Demo Tenant', string $shopName = 'Demo Shop'): array
    {
        $tenant = Tenant::create([
            'company_name' => $tenantName,
            'owner_email' => fake()->unique()->safeEmail(),
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => $shopName,
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Home 10M',
            'service_type' => 'pppoe',
            'price' => 12000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 2592000,
            'speed_limit_profile' => '10M/10M',
            'is_active' => true,
        ]);

        return [$tenant, $shop, $package];
    }

    private function createRadiusTables(): void
    {
        if (Schema::hasTable('radcheck')) {
            return;
        }

        Schema::create('radcheck', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('attribute');
            $table->string('op', 2);
            $table->string('value');
        });

        Schema::create('radreply', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('attribute');
            $table->string('op', 2);
            $table->string('value');
        });

        Schema::create('radusergroup', function (Blueprint $table) {
            $table->string('username');
            $table->string('groupname');
            $table->integer('priority')->default(1);
        });

        Schema::create('radgroupreply', function (Blueprint $table) {
            $table->id();
            $table->string('groupname');
            $table->string('attribute');
            $table->string('op', 2);
            $table->string('value');
        });

        Schema::create('radacct', function (Blueprint $table) {
            $table->id('radacctid');
            $table->string('acctsessionid')->nullable();
            $table->string('acctuniqueid')->nullable();
            $table->string('username')->nullable();
            $table->string('nasipaddress')->nullable();
            $table->string('framedipaddress')->nullable();
            $table->string('callingstationid')->nullable();
            $table->dateTime('acctstarttime')->nullable();
            $table->dateTime('acctupdatetime')->nullable();
            $table->dateTime('acctstoptime')->nullable();
            $table->integer('acctsessiontime')->nullable();
            $table->unsignedInteger('acctinputoctets')->nullable();
            $table->unsignedInteger('acctoutputoctets')->nullable();
            $table->unsignedInteger('acctinputgigawords')->nullable();
            $table->unsignedInteger('acctoutputgigawords')->nullable();
            $table->string('acctterminatecause')->nullable();
        });
    }
}
