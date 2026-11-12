<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only -- shops.tenant_id and every TenantAccess method that
     * reads it are left completely untouched. A shop with client_id null
     * stays directly tenant-owned exactly as before this migration; a shop
     * with client_id set belongs to that Client instead. Same shape for
     * users.client_id -- a 'client'-role user gets both tenant_id
     * (inherited from the owning Client, for consistency with every
     * existing nullable-tenant_id assumption) and client_id set; every
     * other role keeps client_id null.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
