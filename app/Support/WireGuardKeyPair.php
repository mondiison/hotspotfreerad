<?php

namespace App\Support;

class WireGuardKeyPair
{
    /**
     * Generate a WireGuard-compatible X25519 keypair, base64-encoded the same
     * way `wg genkey`/`wg pubkey` produce them. Uses sodium_compat so this works
     * whether or not the native libsodium PHP extension is compiled in.
     *
     * @return array{private: string, public: string}
     */
    public static function generate(): array
    {
        $private = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $public = \ParagonIE_Sodium_Compat::crypto_scalarmult_base($private);

        return [
            'private' => base64_encode($private),
            'public' => base64_encode($public),
        ];
    }

    public static function publicKeyFor(string $base64PrivateKey): string
    {
        $private = base64_decode($base64PrivateKey, true) ?: '';

        return base64_encode(\ParagonIE_Sodium_Compat::crypto_scalarmult_base($private));
    }
}
