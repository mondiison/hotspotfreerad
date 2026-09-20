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

    /**
     * The plural sibling of interfaceName() -- turns a comma-separated list of
     * port numbers (e.g. "5,6,7", as typed into an "extra ports" picker field)
     * into interface-name strings. Blank/null input is a valid "no extra ports
     * configured" state, not an error -- returns [] rather than null.
     *
     * @return list<string>
     */
    public static function interfaceNamesFromNumberList(?string $csv, string $prefix = 'ether'): array
    {
        return collect(explode(',', (string) $csv))
            ->map(fn (string $piece): string => trim($piece))
            ->filter(fn (string $piece): bool => $piece !== '')
            ->map(fn (string $piece): string => self::interfaceName((int) $piece, $prefix))
            ->values()
            ->all();
    }

    /**
     * The plural sibling of portNumberFromInterfaceName() -- turns a
     * comma-separated list of interface names back into port numbers, for
     * pre-filling the picker UI when editing an already-saved router. Unlike
     * the singular version, a single unparseable entry invalidates the WHOLE
     * list (returns null) rather than silently dropping it -- mirroring
     * RoutersIndex::routerProvisioningSettings()'s existing "any one
     * non-standard interface name flips the router into advanced mode"
     * behavior for the 4 singular port roles. Blank/null input is a valid
     * "no extra ports" state and returns [] (not null).
     *
     * @return list<int>|null
     */
    public static function portNumbersFromInterfaceList(?string $csv, string $prefix = 'ether'): ?array
    {
        $pieces = collect(explode(',', (string) $csv))
            ->map(fn (string $piece): string => trim($piece))
            ->filter(fn (string $piece): bool => $piece !== '')
            ->values();

        if ($pieces->isEmpty()) {
            return [];
        }

        $portNumbers = $pieces->map(fn (string $piece): ?int => self::portNumberFromInterfaceName($piece, $prefix));

        if ($portNumbers->contains(null)) {
            return null;
        }

        return $portNumbers->all();
    }
}
