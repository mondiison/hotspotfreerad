<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('subaccount_settlement_gateway')->nullable()->after('settlement_verified_at');
            $table->string('subaccount_code')->nullable()->after('subaccount_settlement_gateway');
            $table->timestamp('subaccount_created_at')->nullable()->after('subaccount_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['subaccount_settlement_gateway', 'subaccount_code', 'subaccount_created_at']);
        });
    }
};
