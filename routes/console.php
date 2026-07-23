<?php

use App\Mail\HotspotTestMail;
use App\Models\PppoeSubscriber;
use App\Models\SecurityActivity;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PppoeSubscriberManagementService;
use App\Services\RadiusProvisioningService;
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
