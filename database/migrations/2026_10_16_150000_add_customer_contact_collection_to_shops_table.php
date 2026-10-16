<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->boolean('collect_customer_phone')->default(false)->after('contact_phone');
            $table->boolean('collect_customer_email')->default(false)->after('collect_customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['collect_customer_phone', 'collect_customer_email']);
        });
    }
};
