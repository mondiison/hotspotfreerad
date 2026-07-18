<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('company_name');
            $table->boolean('public_site_enabled')->default(true)->after('is_active');
            $table->string('brand_color', 20)->default('#0f766e')->after('public_site_enabled');
            $table->string('public_site_tagline')->nullable()->after('brand_color');
            $table->text('public_site_about')->nullable()->after('public_site_tagline');
            $table->string('contact_phone')->nullable()->after('public_site_about');
            $table->string('contact_email')->nullable()->after('contact_phone');
            $table->text('contact_address')->nullable()->after('contact_email');
        });

        DB::table('tenants')
            ->select(['id', 'company_name'])
            ->orderBy('id')
            ->get()
            ->each(function ($tenant): void {
                $base = Str::slug($tenant->company_name) ?: 'tenant-'.$tenant->id;
                $slug = $base;
                $counter = 2;

                while (DB::table('tenants')->where('slug', $slug)->where('id', '!=', $tenant->id)->exists()) {
                    $slug = "{$base}-{$counter}";
                    $counter++;
                }

                DB::table('tenants')
                    ->where('id', $tenant->id)
                    ->update([
                        'slug' => $slug,
                        'contact_email' => DB::raw('owner_email'),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn([
                'slug',
                'public_site_enabled',
                'brand_color',
                'public_site_tagline',
                'public_site_about',
                'contact_phone',
                'contact_email',
                'contact_address',
            ]);
        });
    }
};
