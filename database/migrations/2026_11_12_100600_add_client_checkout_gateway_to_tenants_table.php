<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No tenant-level "which of my own saved gateways should I charge
     * Clients through" concept exists anywhere today -- gateway choice is
     * per-Shop (customer checkout) or the platform's own single active
     * gateway (platform billing). This is the tenant's own choice, among
     * whichever of their own Monnify/Paystack/Squad/Stripe credentials
     * (tenants.payment_gateway_settings) they've actually saved -- set by
     * the tenant admin themselves on the new Clients area, not super admin.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('client_checkout_gateway')->nullable()->after('client_commission_subaccount_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('client_checkout_gateway');
        });
    }
};
