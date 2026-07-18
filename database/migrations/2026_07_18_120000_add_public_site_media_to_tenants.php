<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('hero_image_path')->nullable()->after('public_site_about');
            $table->string('flyer_image_path')->nullable()->after('hero_image_path');
            $table->json('public_site_slides')->nullable()->after('flyer_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'hero_image_path',
                'flyer_image_path',
                'public_site_slides',
            ]);
        });
    }
};
