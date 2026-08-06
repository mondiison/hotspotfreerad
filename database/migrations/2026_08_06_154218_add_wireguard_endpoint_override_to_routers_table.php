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
            $table->string('wireguard_endpoint_override_host')->nullable()->after('wireguard_internal_ip');
            $table->unsignedInteger('wireguard_endpoint_override_port')->nullable()->after('wireguard_endpoint_override_host');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['wireguard_endpoint_override_host', 'wireguard_endpoint_override_port']);
        });
    }
};
