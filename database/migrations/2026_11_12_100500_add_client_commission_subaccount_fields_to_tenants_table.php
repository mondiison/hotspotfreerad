<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mirror image of commission_subaccount_* (TenantManagementService
     * ::createCommissionSubaccount(), which collects the platform's cut of
     * a tenant's OWN hotspot customers) -- this is the platform's cut of
     * what a Client pays the tenant instead, one layer deeper. Deliberately
     * distinct column names: a tenant could run both at once, at two
     * different rates, needing two independent Paystack subaccounts on the
     * same underlying tenant gateway account.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('client_commission_subaccount_gateway')->nullable()->after('auto_void_unused_vouchers_every_cycle');
            $table->string('client_commission_subaccount_code')->nullable()->after('client_commission_subaccount_gateway');
            $table->timestamp('client_commission_subaccount_created_at')->nullable()->after('client_commission_subaccount_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'client_commission_subaccount_gateway',
                'client_commission_subaccount_code',
                'client_commission_subaccount_created_at',
            ]);
        });
    }
};
