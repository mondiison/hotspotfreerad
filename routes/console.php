<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\HotspotTestMail;
use App\Models\Tenant;
use App\Models\User;

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

    Mail::to($email)->send(new HotspotTestMail());

    $this->info("Test email handed to mailer for {$email}");
})->purpose('Send a test email and show the active mail transport without secrets');
