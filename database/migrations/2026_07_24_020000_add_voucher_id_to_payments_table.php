<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('voucher_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('vouchers')
                ->nullOnDelete();

            $table->unique('voucher_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['voucher_id']);
            $table->dropConstrainedForeignId('voucher_id');
        });
    }
};
