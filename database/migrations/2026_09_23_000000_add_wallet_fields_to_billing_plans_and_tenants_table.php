<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billing_plans', function (Blueprint $table) {
            $table->boolean('supports_wallet')->default(false)->after('is_active');
            $table->decimal('wallet_commission_rate', 5, 2)->nullable()->after('supports_wallet');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('wallet_enabled')->default(false)->after('commission_rate');
            $table->string('wallet_commission_bearer')->default('tenant')->after('wallet_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_plans', function (Blueprint $table) {
            $table->dropColumn(['supports_wallet', 'wallet_commission_rate']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['wallet_enabled', 'wallet_commission_bearer']);
        });
    }
};
