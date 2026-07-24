<?php

namespace Tests\Feature;

use App\Livewire\Admin\VouchersIndex;
use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class AdminVoucherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createRadiusTables();
    }

    public function test_tenant_admin_can_generate_voucher_batch(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(VouchersIndex::class)
            ->call('create')
            ->assertSet('showGenerateModal', true)
            ->assertSee('Generate Vouchers')
            ->set('shop_id', (string) $shop->id)
            ->set('package_id', (string) $package->id)
            ->set('name', 'Front Desk Daily')
            ->set('quantity', '5')
            ->set('code_length', '8')
            ->set('prefix', 'MMS')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('5 vouchers generated for Front Desk Daily.');

        $batch = VoucherBatch::where('name', 'Front Desk Daily')->firstOrFail();

        $this->assertSame(5, $batch->vouchers()->count());
        $this->assertDatabaseHas('vouchers', [
            'voucher_batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'status' => 'unused',
        ]);
        $this->assertTrue($batch->vouchers->every(fn (Voucher $voucher) => str($voucher->code)->startsWith('MMS-')));
    }

    public function test_voucher_print_page_displays_compact_codes(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $batch = VoucherBatch::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'name' => 'Print Batch',
            'quantity' => 2,
            'code_length' => 8,
            'prefix' => 'MMS',
            'status' => 'active',
        ]);
        Voucher::create([
            'voucher_batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'code' => 'MMS-ABC12345',
            'status' => 'unused',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.voucher-batches.print', $batch))
            ->assertOk()
            ->assertSee('Print / Save PDF')
            ->assertSee('MMS-ABC12345')
            ->assertSee('@page', false);
    }

    public function test_voucher_print_page_can_filter_unused_codes_and_compress_columns(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $batch = VoucherBatch::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'name' => 'Mixed Print Batch',
            'quantity' => 2,
            'code_length' => 8,
            'prefix' => 'MMS',
            'status' => 'active',
        ]);
        Voucher::create([
            'voucher_batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'code' => 'MMS-UNUSED1',
            'status' => 'unused',
        ]);
        Voucher::create([
            'voucher_batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'code' => 'MMS-USED001',
            'status' => 'used',
            'used_at' => now(),
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.voucher-batches.print', ['voucherBatch' => $batch, 'columns' => 5, 'status' => 'unused']))
            ->assertOk()
            ->assertSee('repeat(5', false)
            ->assertSee('Unused only')
            ->assertSee('MMS-UNUSED1')
            ->assertDontSee('MMS-USED001');
    }

    public function test_voucher_dashboard_filters_used_codes_by_shop_and_date_range(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $otherShop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Annex',
        ]);
        $batch = VoucherBatch::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'name' => 'July Batch',
            'quantity' => 1,
            'code_length' => 8,
            'status' => 'active',
        ]);
        Voucher::create([
            'voucher_batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'code' => 'MMS-JULY01',
            'status' => 'used',
            'used_at' => now()->setDate(2026, 7, 10),
        ]);
        $otherBatch = VoucherBatch::create([
            'shop_id' => $otherShop->id,
            'package_id' => $package->id,
            'name' => 'Annex Batch',
            'quantity' => 1,
            'code_length' => 8,
            'status' => 'active',
        ]);
        Voucher::create([
            'voucher_batch_id' => $otherBatch->id,
            'shop_id' => $otherShop->id,
            'package_id' => $package->id,
            'code' => 'MMS-ANNEX1',
            'status' => 'used',
            'used_at' => now()->setDate(2026, 7, 10),
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(VouchersIndex::class)
            ->set('shop', (string) $shop->id)
            ->set('status', 'used')
            ->set('used_from', '2026-07-01')
            ->set('used_to', '2026-07-31')
            ->assertSee('July Batch')
            ->assertDontSee('Annex Batch')
            ->assertSee('1');
    }

    public function test_tenant_admin_can_inspect_voucher_batch_codes(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $batch = VoucherBatch::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'name' => 'Inspect Batch',
            'quantity' => 2,
            'code_length' => 8,
            'status' => 'active',
            'notes' => 'Front desk cards',
        ]);
        Voucher::create([
            'voucher_batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'code' => 'MMS-UNUSED2',
            'status' => 'unused',
        ]);
        $subscription = Subscription::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'starts_at' => now(),
            'expires_at' => now()->addDay(),
            'is_throttled' => false,
        ]);
        Voucher::create([
            'voucher_batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'subscription_id' => $subscription->id,
            'code' => 'MMS-USED002',
            'status' => 'used',
            'used_mac_address' => 'AA:BB:CC:DD:EE:FF',
            'used_at' => now(),
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(VouchersIndex::class)
            ->call('inspect', $batch->id)
            ->assertSet('showInspectModal', true)
            ->assertSee('Inspect Batch')
            ->assertSee('Front desk cards')
            ->assertSee('MMS-UNUSED2')
            ->assertSee('MMS-USED002')
            ->assertSee('AA:BB:CC:DD:EE:FF')
            ->assertSee('Print unused');
    }

    public function test_hotspot_customer_can_redeem_unused_voucher(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'shop-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);
        $batch = VoucherBatch::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'name' => 'Redeem Batch',
            'quantity' => 1,
            'code_length' => 8,
            'status' => 'active',
        ]);
        $voucher = Voucher::create([
            'voucher_batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'code' => 'MMS-REDEEM1',
            'status' => 'unused',
        ]);

        $this->post(route('hotspot.voucher.redeem'), [
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'nasid' => $router->nas_identifier,
            'voucher_code' => 'mms-redeem1',
            'link-login' => 'http://hotspot.local/login',
        ])
            ->assertOk()
            ->assertSee('Access provisioned')
            ->assertSee('http://hotspot.local/login', false);

        $voucher->refresh();

        $this->assertSame('used', $voucher->status);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $voucher->used_mac_address);
        $this->assertNotNull($voucher->subscription_id);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'AA:BB:CC:DD:EE:FF',
            'attribute' => 'Cleartext-Password',
        ]);
        $this->assertDatabaseHas('radusergroup', [
            'username' => 'AA:BB:CC:DD:EE:FF',
            'groupname' => $package->refresh()->radius_group_name,
        ]);
    }

    public function test_hotspot_portal_shows_voucher_entry(): void
    {
        [$tenant, $shop, $package] = $this->fixture();
        $router = Router::create([
            'shop_id' => $shop->id,
            'name' => 'Main Router',
            'nas_identifier' => 'shop-router',
            'wireguard_internal_ip' => '10.8.0.10',
            'shared_secret' => 'radius-secret',
        ]);

        $this->get('/hotspot/portal?mac=AA:BB:CC:DD:EE:FF&nasid='.$router->nas_identifier)
            ->assertOk()
            ->assertSee('Have a voucher?')
            ->assertSee(route('hotspot.voucher.redeem'), false);
    }

    private function fixture(): array
    {
        $tenant = Tenant::create([
            'company_name' => 'MMS Tenant',
            'owner_email' => 'owner@example.com',
            'brand_color' => '#2563eb',
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Park Area',
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Daily 5GB',
            'service_type' => 'hotspot',
            'price' => 1000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 86400,
            'speed_limit_profile' => '5M/5M',
            'data_limit_bytes' => 5368709120,
            'is_active' => true,
        ]);

        return [$tenant, $shop, $package];
    }

    private function createRadiusTables(): void
    {
        if (Schema::hasTable('radcheck')) {
            return;
        }

        Schema::create('radcheck', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('attribute');
            $table->string('op', 2)->default('==');
            $table->string('value');
        });

        Schema::create('radreply', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('attribute');
            $table->string('op', 2)->default('=');
            $table->string('value');
        });

        Schema::create('radusergroup', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('groupname');
            $table->integer('priority')->default(1);
        });

        Schema::create('radgroupreply', function (Blueprint $table): void {
            $table->id();
            $table->string('groupname');
            $table->string('attribute');
            $table->string('op', 2)->default('=');
            $table->string('value');
        });
    }
}
