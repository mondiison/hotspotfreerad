<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A genuinely separate feature from the existing `allow_test_access`
     * per-package "Start test access" button -- that one is an admin/staff
     * debugging convenience (pick any package, grant its full duration and
     * bandwidth for free) with no daily limit or dedicated throttle of its
     * own. This is a real, admin-configured customer-facing free trial:
     * fixed duration/bandwidth regardless of which package a customer would
     * otherwise buy, capped at a limited number of uses per device per day.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('trial_enabled')->default(false)->after('allow_test_access');
            $table->unsignedInteger('trial_duration_minutes')->default(15)->after('trial_enabled');
            $table->unsignedTinyInteger('trial_max_uses_per_day')->default(1)->after('trial_duration_minutes');
            $table->string('trial_speed_limit_profile')->default('1M/1M')->after('trial_max_uses_per_day');
            $table->foreignId('trial_package_id')->nullable()->after('trial_speed_limit_profile')->constrained('packages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_package_id');
            $table->dropColumn(['trial_enabled', 'trial_duration_minutes', 'trial_max_uses_per_day', 'trial_speed_limit_profile']);
        });
    }
};
