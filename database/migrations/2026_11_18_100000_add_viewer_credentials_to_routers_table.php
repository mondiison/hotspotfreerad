<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mobile "remote access" feature (2026-11-18): a second, deliberately
     * minimal RouterOS account -- distinct from api_username/api_password
     * (the operational mmsradius-api user this app's own backend uses,
     * whose policy explicitly denies winbox/ssh/web/local connection
     * methods) -- meant for a human (tenant_admin) to log into Winbox/SSH/
     * WebFig directly. read-only at the RouterOS policy level (no write
     * at all), so it can never make a breaking change regardless of what
     * the admin clicks around in. See
     * MikroTikProvisioningService::viewerGroupPolicy().
     */
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('viewer_username')->nullable()->after('api_port');
            $table->text('viewer_password')->nullable()->after('viewer_username');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['viewer_username', 'viewer_password']);
        });
    }
};
