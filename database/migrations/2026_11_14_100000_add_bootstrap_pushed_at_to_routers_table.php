<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reseller model Phase 3 (2026-11-14): lets the mobile app's status
     * polling screen distinguish "bootstrap script not yet pushed" from
     * "pushed, waiting for the WireGuard tunnel to come up" from "fully
     * auto-provisioned" (auto_provisioned_at) without guessing from
     * elapsed time -- set by Api\RouterProvisioningController::markBootstrapPushed()
     * the moment the phone's own REST push to the router succeeds.
     */
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->timestamp('bootstrap_pushed_at')->nullable()->after('auto_provisioned_at');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('bootstrap_pushed_at');
        });
    }
};
