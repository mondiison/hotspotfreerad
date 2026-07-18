<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
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
