<?php

namespace App\Support;

/**
 * Turns a router's total Ethernet port count into pickable interface names,
 * and reverse-derives a port number from a legacy free-text interface name
 * (e.g. "ether3" -> 3) so already-saved routers can be reopened in the
 * port-picker UI. Assumes the common MikroTik "etherN" naming convention;
 * anything that doesn't match that pattern returns null so callers fall back
 * to manual/advanced entry instead of guessing.
 */
final class RouterPortLayout
{
    public static function interfaceName(int $portNumber, string $prefix = 'ether'): string
    {
        return $prefix.max(1, $portNumber);
    }

    /**
     * @return array<int, string>
     */
    public static function portOptions(int $portCount, string $prefix = 'ether'): array
    {
        $portCount = max(1, $portCount);

        return collect(range(1, $portCount))
            ->mapWithKeys(fn (int $n): array => [$n => "Port {$n} (".self::interfaceName($n, $prefix).')'])
            ->all();
    }

    public static function portNumberFromInterfaceName(?string $interfaceName, string $prefix = 'ether'): ?int
    {
        if ($interfaceName === null || $interfaceName === '') {
            return null;
        }

        if (! preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $interfaceName, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @param  array<string, int|null>  $portNumbersByRole  role label => port number
     * @return list<string>  human-readable collision descriptions, empty if none
     */
    public static function conflictingRoles(array $portNumbersByRole): array
    {
        $seenByPort = [];
        $conflicts = [];

        foreach ($portNumbersByRole as $role => $portNumber) {
            if ($portNumber === null) {
                continue;
            }

            if (isset($seenByPort[$portNumber])) {
                $conflicts[] = "{$seenByPort[$portNumber]} and {$role} are both on port {$portNumber}.";
            }

            $seenByPort[$portNumber] = $role;
        }

        return $conflicts;
    }
}
