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
     * Which physical port numbers belong to which provisioning role --
     * extracted 2026-10-15 from RoutersIndex::portBucketAssignments() (which
     * is now a thin wrapper around this) so the same bucket logic is
     * reusable outside the wizard's own bound Livewire state, for the
     * read-only Port Map tab on a router's own show page. Works from either
     * simple-mode numeric fields (trunk_port_number, etc.) or, when those
     * are blank, advanced-mode raw interface-name fields (trunk_port, etc.)
     * run back through portNumberFromInterfaceName() -- a router saved
     * entirely in advanced mode (no *_port_number fields populated at all,
     * which is also exactly the router that motivated building this in the
     * first place) still gets a correct port map as long as its interface
     * names follow the standard "etherN" convention; a genuinely
     * non-standard name (e.g. "sfp-sfpplus1") has no port number to place
     * it at and is simply omitted from the numbered grid, the same
     * "advanced mode escapes the numbered picker entirely" limitation this
     * file already has elsewhere.
     *
     * @param  array<string, mixed>  $settings  a router's provisioning_settings
     * @return array<string, list<int>>
     */
    public static function bucketAssignments(array $settings): array
    {
        $singular = function (string $numberKey, string $nameKey) use ($settings): array {
            $number = $settings[$numberKey] ?? null;

            if ($number !== null && $number !== '') {
                return [(int) $number];
            }

            $derived = self::portNumberFromInterfaceName($settings[$nameKey] ?? null);

            return $derived === null ? [] : [$derived];
        };

        $plural = function (string $numbersKey, string $namesKey) use ($settings): array {
            $csv = $settings[$numbersKey] ?? null;

            if (filled($csv)) {
                return collect(explode(',', (string) $csv))
                    ->map(fn (string $piece): string => trim($piece))
                    ->filter(fn (string $piece): bool => $piece !== '')
                    ->map(fn (string $piece): int => (int) $piece)
                    ->values()
                    ->all();
            }

            return collect(explode(',', (string) ($settings[$namesKey] ?? '')))
                ->map(fn (string $piece): string => trim($piece))
                ->filter(fn (string $piece): bool => $piece !== '')
                ->map(fn (string $piece): ?int => self::portNumberFromInterfaceName($piece))
                ->filter(fn (?int $number): bool => $number !== null)
                ->values()
                ->all();
        };

        return [
            'wan1' => $singular('wan1_port_number', 'wan1'),
            // wan2_port_number/wan2 default to a real value (8/"ether8")
            // regardless of enable_second_wan -- unlike wan1/trunk/pi, which
            // are always-present roles, a second WAN is opt-in, so an
            // unenabled router's port never actually occupies this bucket.
            // Gating here (not just hiding the bucket's label in the blade)
            // is what keeps that port from vanishing out of "Unassigned
            // ports" too -- confirmed live 2026-10-15: without this, a
            // router with enable_second_wan off showed its WAN2 default
            // port neither in any bucket nor in the unassigned pool.
            'wan2' => ($settings['enable_second_wan'] ?? false) ? $singular('wan2_port_number', 'wan2') : [],
            'trunk' => array_values(array_unique(array_merge(
                $singular('trunk_port_number', 'trunk_port'),
                $plural('extra_trunk_port_numbers', 'extra_trunk_ports')
            ))),
            'pi' => $singular('pi_port_number', 'pi_port'),
            'extra_mgmt' => $plural('extra_mgmt_port_numbers', 'extra_mgmt_ports'),
            'extra_hotspot' => $plural('extra_hotspot_port_numbers', 'extra_hotspot_ports'),
            'extra_staff' => $plural('extra_staff_port_numbers', 'extra_staff_ports'),
            'extra_pos' => $plural('extra_pos_port_numbers', 'extra_pos_ports'),
            'extra_pppoe' => $plural('extra_pppoe_port_numbers', 'extra_pppoe_ports'),
        ];
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
     * Generic over its values -- works identically for port-NUMBER conflicts
     * (RouterManagementService::portConflictRule()'s own number-picker mode)
     * and for raw interface-NAME conflicts (that same method's advanced-mode
     * check), since the comparison is just an array-key lookup either way.
     *
     * @param  array<string, int|string|null>  $portNumbersByRole  role label => port number or interface name
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
