@props(['file'])

<div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
    <flux:heading size="sm">Save on the router as a bootstrap script</flux:heading>
    <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-zinc-600 dark:text-zinc-400">
        <li>Download the script above. It is named <span class="font-mono">{{ $file }}</span>; keep that name.</li>
        <li>In Winbox, open <strong>Files</strong> and drag the file in. Files stay on the router after a configuration reset.</li>
        <li>Run the command below. The router resets with no default configuration and runs the script as it boots.</li>
    </ol>
    <div class="mt-3 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
        <x-script-block>{{ \App\Services\RouterOsBootstrap::resetCommand($file) }}</x-script-block>
    </div>
    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
        Use the same command for every later reset; the file is reused. If the router comes up unconfigured, the script stopped at an error: reconnect with MAC Winbox and run <span class="font-mono">/import file-name={{ $file }}</span> to see the failing line.
        A reset with the physical button does not run this file. To make it the router's permanent default, use Netinstall's configure script option instead.
    </p>
</div>
