<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tenant's contact_phone (Tenant::contact_phone, used tenant-wide on
     * the public site's "Call support" link) is a single number shared by
     * every shop -- but a tenant with several shops may staff each one with
     * a different attendant/contact, so the hotspot portal's "Call to get a
     * voucher" link needs a per-shop override. Nullable: a shop with no
     * override falls back to the tenant's own contact_phone.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('contact_phone')->nullable()->after('location_city');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('contact_phone');
        });
    }
};
