<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->text('payment_gateway_settings')->nullable()->after('public_site_slides');
        });

        if (Schema::hasColumn('shops', 'payment_gateway_settings')) {
            DB::table('shops')
                ->select('tenant_id', 'payment_gateway_settings')
                ->whereNotNull('payment_gateway_settings')
                ->orderBy('id')
                ->get()
                ->each(function (object $shop): void {
                    DB::table('tenants')
                        ->where('id', $shop->tenant_id)
                        ->whereNull('payment_gateway_settings')
                        ->update(['payment_gateway_settings' => $shop->payment_gateway_settings]);
                });

            Schema::table('shops', function (Blueprint $table): void {
                $table->dropColumn('payment_gateway_settings');
            });
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->text('payment_gateway_settings')->nullable()->after('payment_gateway');
        });

        DB::table('tenants')
            ->select('id', 'payment_gateway_settings')
            ->whereNotNull('payment_gateway_settings')
            ->get()
            ->each(function (object $tenant): void {
                DB::table('shops')
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('payment_gateway_settings')
                    ->update(['payment_gateway_settings' => $tenant->payment_gateway_settings]);
            });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('payment_gateway_settings');
        });
    }
};
