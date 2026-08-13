<?php

use App\Mail\HotspotTestMail;
use App\Models\PosDevice;
use App\Models\PppoeSubscriber;
use App\Models\SecurityActivity;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Router;
use App\Models\Voucher;
use App\Services\PppoeSubscriberManagementService;
use App\Services\PosDeviceManagementService;
use App\Services\FreeRadiusClientSyncService;
use App\Services\RadiusProvisioningService;
use App\Services\RouterMetricSamplingService;
use App\Services\RouterOsConnectionService;
use App\Services\VoucherManagementService;
use App\Services\WireGuardPeerSyncService;
use App\Support\SchedulerHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('hotspot:create-super-admin {email} {password} {--name=Super Admin}', function (string $email, string $password): void {
    $user = User::updateOrCreate(
        ['email' => $email],
        [
            'name' => (string) $this->option('name'),
            'password' => Hash::make($password),
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]
    );

    $this->info("Super admin ready: {$user->email}");
})->purpose('Create or update the first super admin user');

Artisan::command('hotspot:seed-super-admin {password} {--name=Mondiison}', function (string $password): void {
    $email = 'mondiison@yahoo.com';

    $user = User::updateOrCreate(
        ['email' => $email],
        [
            'name' => (string) $this->option('name'),
            'password' => Hash::make($password),
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]
    );

    $this->info("Super admin ready: {$user->email}");
})->purpose('Create or update the default Mondiison super admin user');

Artisan::command('hotspot:create-tenant-admin {tenant} {password} {--email=} {--name=Tenant Admin}', function (string $tenant, string $password): void {
    $tenantModel = Tenant::query()
        ->where('slug', $tenant)
        ->orWhere('owner_email', $tenant)
        ->orWhere('id', $tenant)
        ->firstOrFail();

    $email = (string) ($this->option('email') ?: $tenantModel->owner_email);

    $user = User::updateOrCreate(
        ['email' => $email],
        [
            'name' => (string) $this->option('name'),
            'password' => Hash::make($password),
            'role' => 'tenant_admin',
            'tenant_id' => $tenantModel->id,
            'is_active' => true,
        ]
    );

    $this->info("Tenant admin ready: {$user->email}");
    $this->line("Tenant: {$tenantModel->company_name} ({$tenantModel->slug})");
})->purpose('Create or update a tenant admin user for an existing tenant');

Artisan::command('hotspot:test-mail {email}', function (string $email): void {
    $this->line('Mailer: '.config('mail.default'));
    $this->line('Host: '.(config('mail.mailers.smtp.host') ?: 'not configured'));
    $this->line('Port: '.(config('mail.mailers.smtp.port') ?: 'not configured'));
    $this->line('From: '.config('mail.from.address'));

    Mail::to($email)->send(new HotspotTestMail);

    $this->info("Test email handed to mailer for {$email}");
})->purpose('Send a test email and show the active mail transport without secrets');

Artisan::command('hotspot:scheduler-heartbeat', function (SchedulerHealth $schedulerHealth): int {
    $schedulerHealth->record();

    $this->info('Scheduler heartbeat recorded at '.now()->toDateTimeString().'.');

    return Command::SUCCESS;
})->purpose('Record the last time the Laravel scheduler ran');

Artisan::command('hotspot:prune-security-activity {--days=} {--dry-run}', function (): int {
    $optionDays = $this->option('days');
    $days = (int) ($optionDays !== null && $optionDays !== ''
        ? $optionDays
        : config('hotspot.security_activity_retention_days', 180));

    if ($days < 1) {
        $this->error('Retention days must be at least 1.');

        return Command::FAILURE;
    }

    $cutoff = now()->subDays($days);
    $query = SecurityActivity::query()->where('created_at', '<', $cutoff);
    $count = (clone $query)->count();

    if ($this->option('dry-run')) {
        $this->info("{$count} security activity record(s) older than {$days} day(s) would be pruned.");

        return Command::SUCCESS;
    }

    $deleted = $query->delete();

    $this->info("Pruned {$deleted} security activity record(s) older than {$days} day(s).");

    return Command::SUCCESS;
})->purpose('Prune old security activity audit records');

Artisan::command('hotspot:backfill-voucher-payments {--dry-run}', function (VoucherManagementService $vouchers): int {
    $query = Voucher::query()
        ->with(['shop.tenant', 'package', 'soldBy'])
        ->whereNotNull('sold_at')
        ->whereNotNull('sale_amount')
        ->whereDoesntHave('payment');
    $count = (clone $query)->count();

    if ($this->option('dry-run')) {
        $this->info("{$count} sold voucher(s) would receive internal payment records.");

        return Command::SUCCESS;
    }

    $created = 0;

    $query->chunkById(100, function ($chunk) use ($vouchers, &$created): void {
        foreach ($chunk as $voucher) {
            if (! $voucher->shop || ! $voucher->package) {
                continue;
            }

            $vouchers->recordVoucherPayment(
                $voucher,
                $voucher->shop,
                (float) $voucher->sale_amount,
                $voucher->sale_reference,
                $voucher->soldBy,
            );

            $created++;
        }
    });

    $this->info("Created {$created} internal payment record(s) for sold vouchers.");

    return Command::SUCCESS;
})->purpose('Create internal payment records for existing sold vouchers');

Artisan::command('hotspot:sync-expired-pppoe {--dry-run}', function (PppoeSubscriberManagementService $subscribers): int {
    $query = PppoeSubscriber::query()
        ->with('package')
        ->where(function ($query): void {
            $query
                ->where('is_active', false)
                ->orWhere(fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()));
        });
    $count = (clone $query)->count();

    if ($this->option('dry-run')) {
        $this->info("{$count} inactive or expired PPPoE subscriber(s) would be revoked from RADIUS.");

        return Command::SUCCESS;
    }

    $revoked = 0;

    $query->chunkById(100, function ($chunk) use ($subscribers, &$revoked): void {
        foreach ($chunk as $subscriber) {
            $subscribers->syncSystem($subscriber);
            $revoked++;
        }
    });

    $this->info("Revoked {$revoked} inactive or expired PPPoE subscriber(s) from RADIUS.");

    return Command::SUCCESS;
})->purpose('Revoke expired or disabled PPPoE subscribers from FreeRADIUS');

Artisan::command('hotspot:sync-expired-hotspot {--dry-run}', function (RadiusProvisioningService $radius): int {
    $expiredMacs = Subscription::query()
        ->where('expires_at', '<=', now())
        ->whereNotExists(function ($query): void {
            $query
                ->selectRaw('1')
                ->from('subscriptions as active_subscriptions')
                ->whereColumn('active_subscriptions.mac_address', 'subscriptions.mac_address')
                ->where('active_subscriptions.expires_at', '>', now());
        })
        ->distinct()
        ->pluck('mac_address')
        ->filter()
        ->values();

    if ($this->option('dry-run')) {
        $this->info($expiredMacs->count().' expired hotspot device(s) would be revoked from RADIUS.');

        return Command::SUCCESS;
    }

    $expiredMacs->each(fn (string $macAddress) => $radius->revokeMacAccess($macAddress));

    $this->info('Revoked '.$expiredMacs->count().' expired hotspot device(s) from RADIUS.');

    return Command::SUCCESS;
})->purpose('Revoke expired hotspot MAC access from FreeRADIUS');

Artisan::command('hotspot:sync-expired-pos {--dry-run}', function (PosDeviceManagementService $devices): int {
    $query = PosDevice::query()
        ->with('package')
        ->where(function ($query): void {
            $query
                ->where('is_active', false)
                ->orWhere(fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()));
        });
    $count = (clone $query)->count();

    if ($this->option('dry-run')) {
        $this->info("{$count} inactive or expired POS device(s) would be revoked from RADIUS.");

        return Command::SUCCESS;
    }

    $revoked = 0;

    $query->chunkById(100, function ($chunk) use ($devices, &$revoked): void {
        foreach ($chunk as $device) {
            $devices->syncSystem($device);
            $revoked++;
        }
    });

    $this->info("Revoked {$revoked} inactive or expired POS device(s) from RADIUS.");

    return Command::SUCCESS;
})->purpose('Revoke expired or disabled POS devices from FreeRADIUS');

Schedule::command('hotspot:prune-security-activity')
    ->dailyAt('02:15')
    ->withoutOverlapping();

Schedule::command('hotspot:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('hotspot:sync-expired-pppoe')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('hotspot:sync-expired-hotspot')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('hotspot:sync-expired-pos')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Artisan::command('hotspot:sync-wireguard-peers {--dry-run}', function (WireGuardPeerSyncService $wireguard): int {
    $dryRun = (bool) $this->option('dry-run');
    $result = $wireguard->reconcile(dryRun: $dryRun);

    if (! $result['enabled']) {
        $this->info('WireGuard peer management is disabled. Set WIREGUARD_MANAGE_PEERS=true on the Pi (see docs/wireguard-server-setup.md) to enable it. No changes made.');

        return Command::SUCCESS;
    }

    if (! $result['binary_available']) {
        $this->error('Could not read WireGuard interface "'.$result['interface'].'": '.implode(' ', $result['errors']));

        return Command::FAILURE;
    }

    $added = count($result['added']);
    $updated = count($result['updated']);
    $errorCount = count($result['errors']);
    $addedLabel = $dryRun ? 'would add' : 'added';
    $updatedLabel = $dryRun ? 'would update' : 'updated';

    $this->info("WireGuard sync on \"{$result['interface']}\": {$addedLabel} {$added} peer(s), {$updatedLabel} {$updated} peer(s), {$errorCount} error(s).");

    foreach ($result['errors'] as $error) {
        $this->error($error);
    }

    return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
})->purpose('Reconcile Raspberry Pi WireGuard peers from router records (adds/updates only, never removes a peer)');

Schedule::command('hotspot:sync-wireguard-peers')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Artisan::command('hotspot:sync-radius-clients {--dry-run} {--reload}', function (FreeRadiusClientSyncService $clients): int {
    $dryRun = (bool) $this->option('dry-run');
    $reload = (bool) $this->option('reload');
    $result = $clients->sync(dryRun: $dryRun, reload: $reload);

    if (! $result['enabled']) {
        $this->info('FreeRADIUS client-file management is disabled. Set RADIUS_MANAGE_CLIENTS=true on the Pi to enable writes.');
        $this->line('Preview of managed file:');
        $this->line($result['content']);

        return Command::SUCCESS;
    }

    if ($dryRun) {
        $this->info("Dry run for {$result['desired_count']} router client(s). Target: {$result['path']}");
        $this->line($result['content']);

        return Command::SUCCESS;
    }

    $this->info("FreeRADIUS clients sync target: {$result['path']}");
    $this->info($result['written'] ? 'Managed clients file written.' : 'Managed clients file was not written.');

    if ($reload) {
        $this->info($result['reloaded'] ? 'FreeRADIUS reloaded.' : 'FreeRADIUS was not reloaded.');
    }

    foreach ($result['errors'] as $error) {
        $this->error($error);
    }

    return count($result['errors']) > 0 ? Command::FAILURE : Command::SUCCESS;
})->purpose('Write the managed FreeRADIUS clients include file from router records');

Schedule::command('hotspot:sync-radius-clients --reload')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Artisan::command('hotspot:sample-router-metrics', function (RouterMetricSamplingService $sampler): int {
    $routers = Router::all();
    $sampled = 0;

    foreach ($routers as $router) {
        try {
            $sampler->sample($router);
            $sampled++;
        } catch (\Throwable $e) {
            $this->error("Failed to sample router {$router->id} ({$router->name}): {$e->getMessage()}");
        }
    }

    $this->info("Sampled {$sampled}/{$routers->count()} router(s).");

    return Command::SUCCESS;
})->purpose('Record a CPU/RAM/disk/latency snapshot for every router (latency always attempted; RouterOS metrics only if API credentials are configured)');

Schedule::command('hotspot:sample-router-metrics')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Artisan::command('hotspot:resync-walled-garden {router? : Router ID to resync} {--all : Resync every router with RouterOS API credentials configured}', function (RouterOsConnectionService $routerOs, ?string $router = null): int {
    if (! $router && ! $this->option('all')) {
        $this->error('Pass a router ID or --all.');

        return Command::FAILURE;
    }

    $routers = $router
        ? Router::where('id', $router)->get()
        : Router::whereNotNull('api_username')->get();

    if ($routers->isEmpty()) {
        $this->info('No matching router(s) found.');

        return Command::SUCCESS;
    }

    $failures = 0;

    foreach ($routers as $routerModel) {
        $label = "Router {$routerModel->id} ({$routerModel->name})";

        if (! $routerOs->isConfigured($routerModel)) {
            $this->warn("{$label}: no RouterOS API credentials configured yet, skipped.");

            continue;
        }

        $result = $routerOs->syncWalledGarden($routerModel);

        if ($result['success']) {
            $this->info("{$label}: walled garden synced (gateway: {$routerModel->shop?->paymentGateway()}).");

            continue;
        }

        $failures++;
        $this->error("{$label}: sync had failures.");

        foreach ($result['steps'] as $step) {
            if (! $step['success']) {
                $this->line("  - {$step['label']}: {$step['error']}");
            }
        }
    }

    return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
})->purpose('Re-push a router walled-garden allow-list live over the RouterOS API synchronously -- use when no queue worker is running or to force a resync without changing the gateway');
