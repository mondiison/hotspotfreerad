<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 reseller-model work (2026-11-13): a Client's own "landing
     * template" customization for their shop's hotspot portal -- a banner
     * image and a custom welcome message. Genuinely new (confirmed: all
     * existing branding lives on Tenant only, Shop had zero branding
     * columns before this). contact_phone already covers the "account
     * details" part of the original spec (Shop::contactPhone(), added
     * 2026-10-02), so nothing new is needed for that.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('portal_banner_path')->nullable()->after('contact_phone');
            $table->text('portal_welcome_message')->nullable()->after('portal_banner_path');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['portal_banner_path', 'portal_welcome_message']);
        });
    }
};
