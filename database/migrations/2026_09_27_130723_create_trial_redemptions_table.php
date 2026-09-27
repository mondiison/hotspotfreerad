<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per free-trial grant -- deliberately append-only, never
     * upserted, since the daily per-device use cap needs an actual count of
     * how many times a MAC has started the trial today. Subscriptions rows
     * can't serve this purpose: PortalController keys them on
     * ['shop_id','mac_address'] and upserts on every grant, so a second
     * trial the same day would silently overwrite the first row rather than
     * leaving a countable history.
     */
    public function up(): void
    {
        Schema::create('trial_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('mac_address');
            $table->timestamps();

            $table->index(['shop_id', 'mac_address', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_redemptions');
    }
};
