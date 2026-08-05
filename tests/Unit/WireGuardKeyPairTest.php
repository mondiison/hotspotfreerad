<?php

namespace Tests\Unit;

use App\Support\WireGuardKeyPair;
use Tests\TestCase;

class WireGuardKeyPairTest extends TestCase
{
    public function test_it_generates_a_valid_base64_curve25519_keypair(): void
    {
        $keyPair = WireGuardKeyPair::generate();

        $private = base64_decode($keyPair['private'], true);
        $public = base64_decode($keyPair['public'], true);

        $this->assertNotFalse($private);
        $this->assertNotFalse($public);
        $this->assertSame(32, strlen($private));
        $this->assertSame(32, strlen($public));
    }

    public function test_it_generates_unique_keypairs_each_time(): void
    {
        $first = WireGuardKeyPair::generate();
        $second = WireGuardKeyPair::generate();

        $this->assertNotSame($first['private'], $second['private']);
        $this->assertNotSame($first['public'], $second['public']);
    }

    public function test_public_key_for_is_deterministic_for_a_given_private_key(): void
    {
        $keyPair = WireGuardKeyPair::generate();

        $this->assertSame($keyPair['public'], WireGuardKeyPair::publicKeyFor($keyPair['private']));
    }
}
