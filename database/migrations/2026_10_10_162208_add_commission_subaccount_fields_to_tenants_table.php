<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('commission_subaccount_gateway')->nullable()->after('subaccount_created_at');
            $table->string('commission_subaccount_code')->nullable()->after('commission_subaccount_gateway');
            $table->timestamp('commission_subaccount_created_at')->nullable()->after('commission_subaccount_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['commission_subaccount_gateway', 'commission_subaccount_code', 'commission_subaccount_created_at']);
        });
    }
};
