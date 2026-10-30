<?php

namespace App\Services;

/**
 * Lets a standalone generator's script double as a bootstrap file saved on
 * the router: upload the .rsc to Files once, then every reset runs it with
 * "/system reset-configuration no-defaults=yes run-after-reset=<file>".
 * Uploaded files survive a configuration reset, so the same file is reused.
 *
 * A run-after-reset script starts during boot, before every interface has
 * appeared, and RouterOS stops it silently at the first failing line. So the
 * script first waits (up to 60s) for the interfaces it configures. When the
 * script is pasted into a terminal instead, the wait returns immediately.
 */
class RouterOsBootstrap
{
    public const WAIT_SECONDS = 60;

    /**
     * @param  list<string>  $interfaces
     * @return list<string>
     */
    public static function waitLines(array $interfaces): array
    {
        $missing = implode(' || ', array_map(
            fn (string $interface) => '[:len [/interface find name="'.$interface.'"]] = 0',
            array_values(array_unique(array_filter($interfaces)))
        ));

        return [
            '# Boot-safe: when run after a reset, wait for the interfaces to appear first (no wait when pasted)',
            '{ :local tries 0; :while (('.$missing.') && $tries < '.self::WAIT_SECONDS.') do={ :delay 1s; :set tries ($tries + 1) } }',
            '',
        ];
    }

    public static function resetCommand(string $fileName): string
    {
        return '/system reset-configuration no-defaults=yes skip-backup=yes run-after-reset='.$fileName;
    }
}
