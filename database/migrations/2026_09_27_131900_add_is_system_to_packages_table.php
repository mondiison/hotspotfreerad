<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks a Package row as internally auto-managed rather than something
     * an admin created themselves -- currently only Shop::ensureTrialPackage()'s
     * hidden per-shop "Free Trial" package. is_active=false already keeps a
     * system package out of every customer-facing/package-picker query in the
     * app (they all already filter is_active), but the admin Packages index
     * deliberately lists inactive packages too (that's the point of a
     * management screen), so it needed its own explicit exclusion -- this
     * flag is that, rather than matching on a fragile package name string.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
