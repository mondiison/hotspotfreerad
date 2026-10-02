<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('public_token', 32)->nullable()->unique()->after('nas_identifier');
        });

        // Router::creating() only generates this for new rows -- every router
        // that already existed before this migration needs one backfilled
        // directly, since the "scan this QR at the shop" feature needs every
        // router to have a token, not just ones created from today onward.
        DB::table('routers')->whereNull('public_token')->orderBy('id')->each(function ($router) {
            DB::table('routers')->where('id', $router->id)->update([
                'public_token' => Str::random(22),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
    }
};
