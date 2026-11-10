<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 2026-11-10, direct request: "phone number for follow up is suppose to
 * be 11 digit in nigeria" -- a Nigerian mobile number is always 11 digits
 * starting with 0 (e.g. 08012345678). Applied everywhere this app already
 * collects a customer-facing phone number for follow-up (the hotspot
 * portal's collect_customer_phone field, PPPoE subscribers, POS device
 * owners) so the numbers feeding the "unique phone numbers" directory
 * (CustomerContactDirectoryService) are all in one clean, comparable
 * format -- without this, "+2348012345678", "234 801 234 5678", and
 * "08012345678" would all show up as three different "unique" numbers
 * for the exact same person.
 */
class NigerianPhoneNumber implements ValidationRule
{
    /**
     * Strips everything but digits, then collapses a leading country code
     * (234 or +234) down to the local 0-prefixed form -- so a number typed
     * as "+234 801 234 5678", "2348012345678", or "08012345678" all
     * normalize to the identical "08012345678".
     */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 13 && str_starts_with($digits, '234')) {
            $digits = '0'.substr($digits, 3);
        }

        return $digits;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^0\d{10}$/', self::normalize((string) $value))) {
            $fail('Enter a valid 11-digit Nigerian phone number (e.g. 08012345678).');
        }
    }
}
