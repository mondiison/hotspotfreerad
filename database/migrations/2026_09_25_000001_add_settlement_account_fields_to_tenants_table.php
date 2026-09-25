<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('settlement_bank_code')->nullable()->after('wallet_commission_bearer');
            $table->string('settlement_bank_name')->nullable()->after('settlement_bank_code');
            $table->string('settlement_account_number')->nullable()->after('settlement_bank_name');
            $table->string('settlement_account_name')->nullable()->after('settlement_account_number');
            $table->timestamp('settlement_verified_at')->nullable()->after('settlement_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'settlement_bank_code',
                'settlement_bank_name',
                'settlement_account_number',
                'settlement_account_name',
                'settlement_verified_at',
            ]);
        });
    }
};
