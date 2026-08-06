<?php

namespace App\Support;

use App\Models\Payment;

class GuestCustomerEmail
{
    /**
     * Every hotspot gateway integration needs an email to initialize checkout,
     * but the portal's email field is optional -- most hotspot customers skip
     * it. Falls back to a synthetic address using this app's own real domain
     * (never a reserved/non-routable TLD like ".local", which strict gateway
     * validators such as Squad's reject outright with "must be a valid email"
     * even though it's a syntactically fine address).
     */
    public static function resolve(Payment $payment, ?string $email): string
    {
        if (filled($email)) {
            return $email;
        }

        return 'guest-'.$payment->id.'@'.self::fallbackDomain();
    }

    private static function fallbackDomain(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        $looksLikeARealDomain = filled($host)
            && str_contains($host, '.')
            && ! filter_var($host, FILTER_VALIDATE_IP);

        // Local dev (127.0.0.1, localhost) has no real domain to borrow --
        // example.com is IANA-reserved for exactly this (RFC 2606), and unlike
        // ".local" it has a real, publicly-recognized TLD.
        return $looksLikeARealDomain ? $host : 'example.com';
    }
}
