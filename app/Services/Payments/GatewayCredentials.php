<?php

namespace App\Services\Payments;

/**
 * A gateway's resolved settings (whatever fields PaymentGatewayCatalog::credentialFields()
 * defines for it -- client_id, secret_key, contract_code, environment, etc.),
 * regardless of whether they came from a shop's own saved settings or the
 * platform's. Values are trimmed of stray whitespace/quotes the same way
 * every tenant-facing gateway service's own private setting()/secretKey()
 * helper already did before this class existed.
 */
final class GatewayCredentials
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(private readonly array $fields) {}

    public function get(string $key): ?string
    {
        $value = $this->fields[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        return trim($value, " \t\n\r\0\x0B\"'");
    }

    public function has(string $key): bool
    {
        return filled($this->get($key));
    }
}
