<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->boolean('show_bank_transfer_fallback')->default(false)->after('require_customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn('show_bank_transfer_fallback');
        });
    }
};
