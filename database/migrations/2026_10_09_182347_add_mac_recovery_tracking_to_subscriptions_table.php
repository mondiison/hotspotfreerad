<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('mac_recovered_from')->nullable()->after('mac_address');
            $table->timestamp('mac_auto_recovered_at')->nullable()->after('mac_recovered_from');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['mac_recovered_from', 'mac_auto_recovered_at']);
        });
    }
};
