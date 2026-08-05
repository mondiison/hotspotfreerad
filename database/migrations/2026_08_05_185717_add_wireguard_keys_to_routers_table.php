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
        Schema::table('routers', function (Blueprint $table) {
            $table->text('wireguard_private_key')->nullable()->after('wireguard_internal_ip');
            $table->string('wireguard_public_key')->nullable()->after('wireguard_private_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['wireguard_private_key', 'wireguard_public_key']);
        });
    }
};
