<?php

namespace Tests\Feature;

use App\Livewire\Admin\VouchersIndex;
use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
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
