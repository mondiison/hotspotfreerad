<?php

namespace App\Support;

/**
 * Pure IPv4 CIDR range overlap check -- used to stop two routers from both
 * routing overlapping subnets through the same Pi WireGuard interface (see
 * RouterManagementService's route_lan_through_tunnel validation), since
 * WireGuard's AllowedIPs must be unique/non-overlapping per peer on a given
 * interface. Framework-free, matches the RouterPortLayout/WireGuardKeyPair
 * "pure static helper" convention.
 */
final class CidrOverlap
{
    public static function overlaps(string $cidrA, string $cidrB): bool
    {
        [$startA, $endA] = self::range($cidrA);
        [$startB, $endB] = self::range($cidrB);

        if ($startA === null || $startB === null) {
            return false;
        }

        return $startA <= $endB && $startB <= $endA;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private static function range(string $cidr): array
    {
        $parts = explode('/', trim($cidr), 2);
        $address = ip2long($parts[0] ?? '');

        if ($address === false) {
            return [null, null];
        }

        $prefixLength = isset($parts[1]) && $parts[1] !== '' ? (int) $parts[1] : 32;
        $prefixLength = max(0, min(32, $prefixLength));

        $hostBits = 32 - $prefixLength;
        $mask = $hostBits === 32 ? 0 : (-1 << $hostBits);

        $start = $address & $mask;
        $end = $start | (~$mask & 0xFFFFFFFF);

        return [$start, $end];
    }
}
