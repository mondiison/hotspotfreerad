<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('billing_model')->default('subscription')->after('subscription_plan');
            $table->decimal('commission_rate', 5, 2)->default(0)->after('billing_model');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('gross_amount', 10, 2)->default(0)->after('amount');
            $table->decimal('platform_fee_amount', 10, 2)->default(0)->after('gross_amount');
            $table->decimal('tenant_net_amount', 10, 2)->default(0)->after('platform_fee_amount');
            $table->decimal('commission_rate', 5, 2)->default(0)->after('tenant_net_amount');
            $table->string('billing_model')->default('subscription')->after('commission_rate');
        });

        DB::table('payments')->update([
            'gross_amount' => DB::raw('amount'),
            'platform_fee_amount' => 0,
            'tenant_net_amount' => DB::raw('amount'),
            'commission_rate' => 0,
            'billing_model' => 'subscription',
        ]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'gross_amount',
                'platform_fee_amount',
                'tenant_net_amount',
                'commission_rate',
                'billing_model',
            ]);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['billing_model', 'commission_rate']);
        });
    }
};
