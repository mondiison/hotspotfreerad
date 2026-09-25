<?php

use App\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * PlatformPaymentSettingsService used to store every platform gateway's
 * credentials under one single-slot row (payments.platform.flutterwave)
 * using Flutterwave-shaped field names (client_id/client_secret/
 * webhook_secret_hash), regardless of which gateway was actually active --
 * PlatformStripeService's own secretKey()/webhookSecret() reused those same
 * two fields as Stripe's real credentials whenever Stripe was active. This
 * splits that one row into the new per-gateway shape
 * (payments.platform.general for active_gateway/default_payment_method,
 * plus payments.platform.gateway.{key} per gateway), mapping the old
 * Flutterwave-shaped fields to whichever gateway was actually active at
 * migration time rather than assuming they were always Flutterwave's.
 */
return new class extends Migration
{
    public function up(): void
    {
        $old = PlatformSetting::query()->where('key', 'payments.platform.flutterwave')->first();

        if (! $old || ! is_array($old->value)) {
            return;
        }

        $value = $old->value;
        $activeGateway = (string) ($value['active_gateway'] ?? 'flutterwave');

        PlatformSetting::query()->updateOrCreate(
            ['key' => 'payments.platform.general'],
            ['value' => [
                'active_gateway' => $activeGateway,
                'default_payment_method' => $value['default_payment_method'] ?? 'opay',
            ]]
        );

        $gatewaySettings = $activeGateway === 'stripe'
            ? array_filter([
                'secret_key' => $value['client_secret'] ?? null,
                'webhook_secret' => $value['webhook_secret_hash'] ?? null,
            ], fn ($v) => filled($v))
            : array_filter([
                'client_id' => $value['client_id'] ?? null,
                'client_secret' => $value['client_secret'] ?? null,
                'webhook_secret' => $value['webhook_secret_hash'] ?? null,
            ], fn ($v) => filled($v));

        if ($gatewaySettings !== []) {
            PlatformSetting::query()->updateOrCreate(
                ['key' => 'payments.platform.gateway.'.$activeGateway],
                ['value' => $gatewaySettings]
            );
        }

        PlatformSetting::query()->where('key', 'payments.platform.flutterwave')->delete();
    }

    public function down(): void
    {
        // Deliberately no-op -- the old single-slot shape conflated whichever
        // gateway was active under Flutterwave-labeled field names, which
        // can't be reliably reconstructed from the new per-gateway rows.
    }
};
