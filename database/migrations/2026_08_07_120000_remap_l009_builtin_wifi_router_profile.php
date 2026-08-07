<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "l009_builtin_wifi" provisioning profile named a specific MikroTik
 * board in its key -- removed in favor of the independent "Built-in Wi-Fi"
 * toggle (any bandwidth/VLAN template can now have wireless on or off).
 * This only rewrites the `profile` key on already-saved rows; every other
 * field (wan1/wan2/trunk_port/pi_port/enable_builtin_wifi/etc.) is left
 * exactly as saved, so already-provisioned routers keep generating the
 * same RouterOS scripts unless an admin explicitly re-saves them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('routers')
            ->select('id', 'provisioning_settings')
            ->whereNotNull('provisioning_settings')
            ->orderBy('id')
            ->get()
            ->each(function (object $router): void {
                $settings = json_decode((string) $router->provisioning_settings, true);

                if (! is_array($settings) || ($settings['profile'] ?? null) !== 'l009_builtin_wifi') {
                    return;
                }

                $settings['profile'] = 'small_hotspot';

                DB::table('routers')
                    ->where('id', $router->id)
                    ->update(['provisioning_settings' => json_encode($settings)]);
            });
    }

    public function down(): void
    {
        // Best-effort only: routers that were on "small_hotspot" before this
        // migration ran are indistinguishable from ones remapped by it, so
        // this cannot be reversed exactly. Left as a no-op rather than
        // guessing and reintroducing the removed profile value incorrectly.
    }
};
