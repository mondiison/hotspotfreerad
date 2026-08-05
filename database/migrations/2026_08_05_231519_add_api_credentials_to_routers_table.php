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
            $table->string('api_username')->nullable()->after('wireguard_public_key');
            $table->text('api_password')->nullable()->after('api_username');
            $table->unsignedInteger('api_port')->nullable()->after('api_password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['api_username', 'api_password', 'api_port']);
        });
    }
};
