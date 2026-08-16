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
            $table->string('tunnel_mode')->default('wireguard')->after('wireguard_endpoint_override_port');
            $table->string('zerotier_node_id', 16)->nullable()->after('tunnel_mode');
            $table->string('zerotier_ip')->nullable()->after('zerotier_node_id');
            $table->timestamp('zerotier_authorized_at')->nullable()->after('zerotier_ip');
            $table->unique('zerotier_node_id');
            $table->unique('zerotier_ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['tunnel_mode', 'zerotier_node_id', 'zerotier_ip', 'zerotier_authorized_at']);
        });
    }
};
