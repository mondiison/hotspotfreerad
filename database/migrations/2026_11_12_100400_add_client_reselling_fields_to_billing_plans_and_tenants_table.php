<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors the existing supports_wallet/wallet_commission_rate +
     * wallet_enabled/commission_rate shape exactly, one layer deeper: a
     * super admin decides which platform billing plans allow a tenant to
     * resell to its own Clients, and what the default trial/commission
     * terms are; a tenant that enables it gets those defaults copied onto
     * its own row (ClientResellingService::enable()), and a super admin can
     * then override the per-tenant rate/trial/strictness directly.
     */
    public function up(): void
    {
        Schema::table('billing_plans', function (Blueprint $table) {
            $table->boolean('supports_client_reselling')->default(false)->after('wallet_commission_rate');
            $table->decimal('default_client_commission_rate', 5, 2)->default(0)->after('supports_client_reselling');
            $table->unsignedInteger('default_client_trial_days')->default(14)->after('default_client_commission_rate');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('client_reselling_enabled')->default(false)->after('wallet_commission_bearer');
            $table->decimal('client_commission_rate', 5, 2)->default(0)->after('client_reselling_enabled');
            $table->unsignedInteger('default_client_trial_days')->default(14)->after('client_commission_rate');
            // The stricter/"premium" lapse-enforcement option: void every
            // currently-unused voucher at the end of EVERY billing cycle,
            // not only when a Client's cycle actually lapses unpaid. See
            // hotspot:enforce-client-billing.
            $table->boolean('auto_void_unused_vouchers_every_cycle')->default(false)->after('default_client_trial_days');
        });
    }

    public function down(): void
    {
        Schema::table('billing_plans', function (Blueprint $table) {
            $table->dropColumn(['supports_client_reselling', 'default_client_commission_rate', 'default_client_trial_days']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'client_reselling_enabled',
                'client_commission_rate',
                'default_client_trial_days',
                'auto_void_unused_vouchers_every_cycle',
            ]);
        });
    }
};
