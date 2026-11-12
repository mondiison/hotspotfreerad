<?php

use App\Mail\HotspotTestMail;
use App\Mail\PppoeExpiryReminderMail;
use App\Models\Client;
use App\Models\ClientBillingSubscription;
use App\Models\PosDevice;
use App\Models\PppoeSubscriber;
use App\Models\Payment;
use App\Models\Router;
use App\Models\RouterMetricSample;
use App\Models\SecurityActivity;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\FreeRadiusClientSyncService;
use App\Services\HotspotPaymentConfirmationService;
use App\Services\MikroTikProvisioningService;
use App\Services\PosDeviceManagementService;
use App\Services\PppoeSubscriberManagementService;
use App\Services\RadiusProvisioningService;
use App\Services\RouterAutoProvisioningService;
use App\Services\RouterMetricSamplingService;
use App\Services\RouterOsConnectionService;
use App\Services\VoucherManagementService;
use App\Services\WireGuardPeerSyncService;
use App\Services\WireGuardRouteSyncService;
use App\Services\ZeroTierMembershipSyncService;
use App\Support\PaymentGatewayCatalog;
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

Artisan::command('hotspot:prune-router-metrics {--days=} {--dry-run}', function (): int {
    $optionDays = $this->option('days');
    $days = (int) ($optionDays !== null && $optionDays !== ''
        ? $optionDays
        : config('hotspot.router_metrics_retention_days', 90));

    if ($days < 1) {
        $this->error('Retention days must be at least 1.');

        return Command::FAILURE;
    }

    $cutoff = now()->subDays($days);
    $query = RouterMetricSample::query()->where('sampled_at', '<', $cutoff);
    $count = (clone $query)->count();

    if ($this->option('dry-run')) {
        $this->info("{$count} router metric sample(s) older than {$days} day(s) would be pruned.");

        return Command::SUCCESS;
    }

    $deleted = $query->delete();

    $this->info("Pruned {$deleted} router metric sample(s) older than {$days} day(s).");

    return Command::SUCCESS;
})->purpose('Prune old router metric samples (one row per router every 5 minutes otherwise grows unbounded)');

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

Artisan::command('hotspot:sync-expired-pppoe {--dry-run}', function (PppoeSubscriberManagementService $subscribers, RouterOsConnectionService $routerOs): int {
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
    $disconnected = 0;

    $query->chunkById(100, function ($chunk) use ($subscribers, $routerOs, &$revoked, &$disconnected): void {
        foreach ($chunk as $subscriber) {
            $subscribers->syncSystem($subscriber);
            $revoked++;

            // Confirmed live 2026-11-10, direct report: revoking RADIUS alone
            // leaves an already-established PPPoE session completely
            // untouched -- unlike the hotspot captive portal, a PPP link
            // never re-checks RADIUS mid-session, so an expired subscriber
            // kept passing traffic indefinitely until the router was
            // manually restarted (which forces every session to
            // re-authenticate). Best-effort across every router on this
            // subscriber's shop -- a username not currently active on a
            // given router is a normal no-op, not a failure.
            Router::query()->where('shop_id', $subscriber->shop_id)->get()->each(function (Router $router) use ($routerOs, $subscriber, &$disconnected): void {
                if ($routerOs->disconnectPppoeUser($router, $subscriber->username)['success']) {
                    $disconnected++;
                }
            });
        }
    });

    $this->info("Revoked {$revoked} inactive or expired PPPoE subscriber(s) from RADIUS; disconnected {$disconnected} active router session check(s).");

    return Command::SUCCESS;
})->purpose('Revoke expired or disabled PPPoE subscribers from FreeRADIUS and disconnect any already-active PPP session');

/**
 * 2026-11-10, direct request ("can we send cut off notification, 3 days
 * to expiry, 1 day and expired email, is it possible to integrate payment
 * link from the email... automated when they pay they get renewed
 * automatically") -- per-shop opt-in (pppoe_expiry_reminders_enabled, off
 * by default, the same "capability toggle, not forced on" pattern every
 * other optional feature in this codebase uses). The automated-renewal
 * half is already fully wired by the time this command exists: the link
 * each email carries (MikroTikProvisioningService::pppoeRenewalUrl())
 * leads to PppoeRenewalController, whose checkout flow reuses
 * HotspotPaymentConfirmationService's existing, payment-row-generic
 * confirmation plumbing -- a paid renewal link extends the subscriber's
 * expiry with no further action here.
 *
 * Three independent, idempotent one-shot dedup flags (reminder_3day_sent_at/
 * reminder_1day_sent_at/reminder_expired_sent_at) rather than one combined
 * "last reminder" field -- a subscriber can legitimately cross all three
 * thresholds over time within the same billing cycle, and each should only
 * ever fire once per cycle. All three reset back to null the moment the
 * subscriber actually renews (PppoeSubscriberManagementService::extendExpiry()),
 * so the next cycle's reminders can fire again. Scheduled dailyAt('09:00')
 * below -- these are billing reminders, not something that needs minute-
 * level timeliness the way RADIUS expiry/session-disconnect syncs do.
 */
Artisan::command('hotspot:pppoe-expiry-reminders {--dry-run}', function (MikroTikProvisioningService $provisioning): int {
    $sent = ['three_day' => 0, 'one_day' => 0, 'expired' => 0];

    $eligible = fn () => PppoeSubscriber::query()
        ->where('is_active', true)
        ->whereNotNull('email')
        ->whereNotNull('expires_at')
        ->whereHas('shop', fn ($query) => $query->where('pppoe_expiry_reminders_enabled', true))
        ->with(['shop.tenant', 'package']);

    $threeDay = $eligible()->whereNull('reminder_3day_sent_at')->where('expires_at', '<=', now()->addDays(3))->get();
    $oneDay = $eligible()->whereNull('reminder_1day_sent_at')->where('expires_at', '<=', now()->addDay())->get();
    $expired = $eligible()->whereNull('reminder_expired_sent_at')->where('expires_at', '<=', now())->get();

    if ($this->option('dry-run')) {
        $this->info("{$threeDay->count()} three-day, {$oneDay->count()} one-day, and {$expired->count()} expired PPPoE reminder(s) would be sent.");

        return Command::SUCCESS;
    }

    foreach ($threeDay as $subscriber) {
        Mail::to($subscriber->email)->send(new PppoeExpiryReminderMail($subscriber, PppoeExpiryReminderMail::STAGE_THREE_DAY, $provisioning->pppoeRenewalUrl($subscriber)));
        $subscriber->forceFill(['reminder_3day_sent_at' => now()])->save();
        $sent['three_day']++;
    }

    foreach ($oneDay as $subscriber) {
        Mail::to($subscriber->email)->send(new PppoeExpiryReminderMail($subscriber, PppoeExpiryReminderMail::STAGE_ONE_DAY, $provisioning->pppoeRenewalUrl($subscriber)));
        $subscriber->forceFill(['reminder_1day_sent_at' => now()])->save();
        $sent['one_day']++;
    }

    foreach ($expired as $subscriber) {
        Mail::to($subscriber->email)->send(new PppoeExpiryReminderMail($subscriber, PppoeExpiryReminderMail::STAGE_EXPIRED, $provisioning->pppoeRenewalUrl($subscriber)));
        $subscriber->forceFill(['reminder_expired_sent_at' => now()])->save();
        $sent['expired']++;
    }

    $this->info("Sent {$sent['three_day']} three-day, {$sent['one_day']} one-day, and {$sent['expired']} expired PPPoE reminder email(s).");

    return Command::SUCCESS;
})->purpose('Email PPPoE subscribers nearing or past expiry a pay-to-renew link');

Artisan::command('hotspot:sync-expired-hotspot {--dry-run}', function (RadiusProvisioningService $radius, RouterOsConnectionService $routerOs): int {
    // Grouped by mac_address (not a flat pluck) so each expired device keeps
    // its shop_id(s) -- needed below to know which router(s) to actively
    // disconnect it from, not just which RADIUS rows to delete.
    $expiredByMac = Subscription::query()
        ->where('expires_at', '<=', now())
        ->whereNotExists(function ($query): void {
            $query
                ->selectRaw('1')
                ->from('subscriptions as active_subscriptions')
                ->whereColumn('active_subscriptions.mac_address', 'subscriptions.mac_address')
                ->where('active_subscriptions.expires_at', '>', now());
        })
        ->whereNotNull('mac_address')
        ->get(['mac_address', 'shop_id'])
        ->groupBy('mac_address');

    if ($this->option('dry-run')) {
        $this->info($expiredByMac->count().' expired hotspot device(s) would be revoked from RADIUS.');

        return Command::SUCCESS;
    }

    $disconnected = 0;

    $expiredByMac->each(function ($rows, string $macAddress) use ($radius, $routerOs, &$disconnected): void {
        $radius->revokeMacAccess($macAddress);

        // Confirmed live 2026-09-28: revoking RADIUS access alone leaves an
        // already-authenticated hotspot session completely untouched --
        // RouterOS only consults RADIUS at login/session-renewal time, not
        // on every packet, so a phone that's already connected just has its
        // traffic silently stop working with no indication why. Most phones
        // don't spontaneously re-open their captive-portal login screen once
        // they've already decided they have working internet, so a customer
        // sees what looks like a bad network rather than an expired plan,
        // until they manually disconnect and reconnect. Removing the
        // matching /ip/hotspot/active entry is what actually ends the
        // session -- RouterOS then intercepts the device's very next request
        // and serves the login page again. Best-effort across every router
        // on the shop(s) this mac's now-expired subscription(s) belonged to
        // -- a mac not currently active on a given router is a normal no-op,
        // not a failure.
        $shopIds = $rows->pluck('shop_id')->filter()->unique();

        Router::query()->whereIn('shop_id', $shopIds)->get()->each(function (Router $router) use ($routerOs, $macAddress, &$disconnected): void {
            if ($routerOs->disconnectHotspotUser($router, $macAddress)['success']) {
                $disconnected++;
            }
        });
    });

    $this->info('Revoked '.$expiredByMac->count().' expired hotspot device(s) from RADIUS; disconnected '.$disconnected.' active router session/lease check(s).');

    return Command::SUCCESS;
})->purpose('Revoke expired hotspot MAC access from FreeRADIUS and disconnect any already-active hotspot session');

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

/**
 * 2026-10-16, direct report: "I notice someone make payment and it's
 * after a day that i clicked verify that it became successful" -- a
 * customer's online payment can genuinely complete on the gateway's own
 * side (webhook never arrived, or they never got redirected back through
 * the callback URL) and sit at status=pending/verification_failed
 * indefinitely until an admin happens to notice it in the Payments list
 * and clicks "Verify" by hand. This re-checks it automatically instead,
 * reusing the exact same HotspotPaymentConfirmationService::verifyAndGrant()
 * the manual "Verify" button already calls -- same gateway call, same
 * success/failure handling, same idempotency guard against a concurrent
 * manual click -- so this is purely "do it on a schedule too," not a
 * second verification mechanism.
 *
 * Scoped to the five real online gateways only (PaymentGatewayCatalog::
 * onlineProviderKeys()) -- manual_bank requires an admin's own bank-
 * statement confirmation and has no live API to check, and voucher_cash/
 * pos_manual are already created with status=successful at the moment
 * they're recorded, so neither would ever match the pending/
 * verification_failed query below anyway; excluding them here is just
 * defensive. A payment with no provider_reference yet can't be verified
 * at all (mirrors PaymentsIndex::verifyPayment()'s own early-exit).
 *
 * "3 times at different interval," per the direct request: retries are
 * counted on Payment.verification_attempts and scheduled relative to
 * Payment.created_at (not the previous attempt) for a simple, predictable
 * schedule -- 5 minutes, 30 minutes, then 3 hours after checkout. After
 * the third attempt with still no success, this deliberately stops
 * retrying that payment forever rather than hammering a gateway
 * indefinitely for a transaction that may never actually complete --
 * it's left for an admin to check manually via the existing Payments
 * list "Verify"/"Recover access" actions at that point, same as today.
 */
Artisan::command('hotspot:retry-pending-payments {--dry-run}', function (HotspotPaymentConfirmationService $payments): int {
    $retryThresholds = [
        0 => now()->subMinutes(5),
        1 => now()->subMinutes(30),
        2 => now()->subHours(3),
    ];

    $candidates = Payment::query()
        ->with(['shop.tenant', 'package', 'subscription'])
        ->whereIn('status', ['pending', 'verification_failed'])
        ->whereIn('provider', PaymentGatewayCatalog::onlineProviderKeys())
        ->whereNotNull('provider_reference')
        ->where('verification_attempts', '<', 3)
        ->get()
        ->filter(fn (Payment $payment): bool => $payment->created_at->lte($retryThresholds[$payment->verification_attempts]));

    if ($this->option('dry-run')) {
        $this->info($candidates->count().' pending payment(s) would be retried.');

        return Command::SUCCESS;
    }

    $confirmed = 0;
    $stillUnconfirmed = 0;

    foreach ($candidates as $payment) {
        // Same "chg" vs "order" resource-type detection
        // PaymentsIndex::verifyPayment()'s own paymentResourceType() uses --
        // only meaningful to Flutterwave's verification call, harmlessly
        // ignored by every other gateway.
        $resourceType = str_starts_with(strtolower((string) $payment->provider_reference), 'chg') ? 'charge' : 'order';

        try {
            $subscription = $payments->verifyAndGrant($payment, (string) $payment->provider_reference, $resourceType);
        } catch (\Throwable $e) {
            $subscription = null;
        }

        $payment->forceFill([
            'verification_attempts' => $payment->verification_attempts + 1,
            'last_verification_attempt_at' => now(),
        ])->save();

        $subscription ? $confirmed++ : $stillUnconfirmed++;
    }

    $this->info("Retried {$candidates->count()} pending payment(s): {$confirmed} confirmed, {$stillUnconfirmed} still unconfirmed.");

    return Command::SUCCESS;
})->purpose('Automatically re-check pending/unconfirmed online payments at escalating intervals, up to 3 attempts');

/**
 * 2026-11-07, direct report: "pending transaction with no reference, stuck
 * some devices". Checkouts that never reached the gateway stay "pending"
 * forever because the retry job above needs a provider reference. See
 * AbandonedPaymentCleanupService for the Flutterwave card exception.
 */
Artisan::command('hotspot:expire-abandoned-payments {--minutes=60 : Only payments older than this} {--dry-run}', function (App\Services\AbandonedPaymentCleanupService $cleanup): int {
    $result = $cleanup->run((int) $this->option('minutes'), (bool) $this->option('dry-run'));

    if ($this->option('dry-run')) {
        $this->info("{$result['candidates']} pending payment(s) with no reference would be checked.");

        return Command::SUCCESS;
    }

    $this->info("Checked {$result['candidates']}: {$result['abandoned']} marked abandoned, {$result['recovered']} found paid and granted, {$result['skipped']} left for the next run.");

    return Command::SUCCESS;
})->purpose('Mark pending payments that never got a gateway reference as abandoned (after a final Flutterwave tx_ref check)');

/**
 * 2026-11-12: the reseller-tier Client's trial-to-paywall lapse
 * enforcement -- nothing in this codebase's billing code (BillingPlanLimits)
 * ever cuts off EXISTING access, it only blocks creating new things, so this
 * is genuinely new, not an extension of an existing job.
 *
 * Deliberately scoped to the Client's own admin access only -- a Client's
 * real hotspot/PPPoE/POS customers have no dependency on
 * client_billing_subscriptions.status at all (their RADIUS/Subscription
 * flow never reads it), so nothing here can disrupt them.
 *
 * Voids every currently-UNUSED voucher across a lapsed Client's shop(s) --
 * reusing VoucherManagementService's own existing void logic, which already
 * leaves sold/redeemed codes alone -- specifically so a Client can't print
 * an unlimited stash of vouchers right up to lockout and keep selling them
 * forever without ever paying. That baseline void only ever fires once per
 * lapse -- `$subscription->status` flips to `past_due` the same run, so
 * tomorrow's `match()` falls through to `default => false` and nothing
 * re-voids a batch that's already void.
 *
 * `auto_void_unused_vouchers_every_cycle` (the stricter, tenant-opt-in
 * option) can't reuse that same status-flip guard at all -- a PAID/renewed
 * subscription's `current_period_ends_at` gets pushed INTO THE FUTURE the
 * moment it renews (see ClientBillingConfirmationService::activateSubscription()),
 * which is exactly why that field can never be used to detect "has this
 * Client's cycle genuinely ended" independent of whether they already
 * paid -- by the time this command runs, a paying Client's boundary is
 * never in the past at all. Deliberately decoupled from the subscription's
 * own billing dates entirely: it fires at most once per real calendar
 * month (`now()->format('Y-m')`, stamped into the subscription's own
 * `payload.last_auto_voided_period` once applied), regardless of this
 * Client's individual lapse/renewal status -- simple, predictable, and
 * genuinely "every cycle, regardless of payment" for this codebase's
 * monthly billing cadence, without risking a daily wipe.
 */
Artisan::command('hotspot:enforce-client-billing {--dry-run}', function (VoucherManagementService $vouchers): int {
    $dryRun = (bool) $this->option('dry-run');
    $currentPeriodKey = now()->format('Y-m');
    $clients = Client::query()
        ->where('is_active', true)
        ->with(['tenant', 'currentBillingSubscription', 'shops.voucherBatches' => fn ($query) => $query->where('status', 'active')])
        ->get();

    $lapsedCount = 0;
    $voidedBatches = 0;
    $voidedVouchers = 0;

    foreach ($clients as $client) {
        $subscription = $client->currentBillingSubscription;

        if (! $subscription) {
            continue;
        }

        $lapsed = match ($subscription->status) {
            'trialing' => (bool) ($subscription->trial_ends_at?->isPast()),
            'active' => (bool) ($subscription->current_period_ends_at?->isPast()),
            default => false,
        };

        $autoVoidDue = (bool) $client->tenant?->auto_void_unused_vouchers_every_cycle
            && ($subscription->payload['last_auto_voided_period'] ?? null) !== $currentPeriodKey;

        $shouldVoid = $lapsed || $autoVoidDue;

        if (! $lapsed && ! $shouldVoid) {
            continue;
        }

        if ($dryRun) {
            $lapsed && $lapsedCount++;

            continue;
        }

        if ($lapsed) {
            $subscription->forceFill(['status' => 'past_due'])->save();
            $lapsedCount++;
        }

        if ($shouldVoid) {
            foreach ($client->shops as $shop) {
                foreach ($shop->voucherBatches as $batch) {
                    $voidedVouchers += $vouchers->voidUnusedVouchersInBatchAsSystem($batch);
                    $voidedBatches++;
                }
            }

            if ($autoVoidDue) {
                $subscription->forceFill(['payload' => array_merge($subscription->payload ?? [], [
                    'last_auto_voided_period' => $currentPeriodKey,
                ])])->save();
            }
        }
    }

    if ($dryRun) {
        $this->info("{$lapsedCount} Client subscription(s) would be flipped to past_due.");

        return Command::SUCCESS;
    }

    $this->info("Flipped {$lapsedCount} Client subscription(s) to past_due. Voided {$voidedVouchers} unused voucher(s) across {$voidedBatches} batch(es).");

    return Command::SUCCESS;
})->purpose('Flip a Client subscription to past_due once its trial/cycle lapses unpaid, locking its own admin access and voiding its unused vouchers -- never touches its real hotspot/PPPoE/POS customers');

Schedule::command('hotspot:enforce-client-billing')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('hotspot:prune-security-activity')
    ->dailyAt('02:15')
    ->withoutOverlapping();

Schedule::command('hotspot:prune-router-metrics')
    ->dailyAt('02:20')
    ->withoutOverlapping();

Schedule::command('hotspot:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('hotspot:sync-expired-pppoe')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('hotspot:pppoe-expiry-reminders')
    ->dailyAt('09:00')
    ->withoutOverlapping();

Schedule::command('hotspot:sync-expired-hotspot')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('hotspot:sync-expired-pos')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('hotspot:retry-pending-payments')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('hotspot:expire-abandoned-payments')
    ->everyFifteenMinutes()
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

Artisan::command('hotspot:sync-zerotier-members {--dry-run}', function (ZeroTierMembershipSyncService $zeroTier): int {
    $dryRun = (bool) $this->option('dry-run');
    $result = $zeroTier->reconcile(dryRun: $dryRun);

    if (! $result['enabled']) {
        $this->info('ZeroTier membership management is disabled. Set ZEROTIER_MANAGE_MEMBERS=true on the Pi (see docs/zerotier-fallback-setup.md) to enable it. No changes made.');

        return Command::SUCCESS;
    }

    $authorizedCount = count($result['authorized']);
    $errorCount = count($result['errors']);
    $authorizedLabel = $dryRun ? 'would authorize' : 'authorized';

    $this->info("ZeroTier sync: {$authorizedLabel} {$authorizedCount} node(s), {$errorCount} error(s).");

    foreach ($result['errors'] as $error) {
        $this->error($error);
    }

    if ($result['unmatched_members'] !== []) {
        $this->warn('Unmatched nodes on the controller (not attributed to any router): '.implode(', ', $result['unmatched_members']));
    }

    return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
})->purpose('Authorize known router ZeroTier nodes on the self-hosted controller and report any unmatched pending nodes');

Schedule::command('hotspot:sync-zerotier-members')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Artisan::command('hotspot:sync-wireguard-routes {--dry-run}', function (WireGuardRouteSyncService $routes): int {
    $dryRun = (bool) $this->option('dry-run');
    $result = $routes->reconcile(dryRun: $dryRun);

    if (! $result['enabled']) {
        $this->info('WireGuard route management is disabled. Set WIREGUARD_MANAGE_ROUTES=true on the Pi (see docs/wireguard-server-setup.md) to enable it. No changes made.');

        return Command::SUCCESS;
    }

    if (! $result['binary_available']) {
        $this->error('Could not list routes with proto "'.config('services.wireguard.route_proto').'": '.implode(' ', $result['errors']));

        return Command::FAILURE;
    }

    $added = count($result['added']);
    $removed = count($result['removed']);
    $errorCount = count($result['errors']);
    $addedLabel = $dryRun ? 'would add' : 'added';
    $removedLabel = $dryRun ? 'would remove' : 'removed';

    $this->info("WireGuard route sync on \"{$result['interface']}\": {$addedLabel} {$added} route(s), {$removedLabel} {$removed} route(s), {$errorCount} error(s).");

    foreach ($result['errors'] as $error) {
        $this->error($error);
    }

    return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
})->purpose('Reconcile the Pi\'s kernel routes for routers with route_lan_through_tunnel enabled (adds and removes, scoped to a dedicated proto tag)');

Schedule::command('hotspot:sync-wireguard-routes')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Artisan::command('hotspot:auto-provision-routers {--dry-run}', function (RouterAutoProvisioningService $autoProvision): int {
    $dryRun = (bool) $this->option('dry-run');
    $result = $autoProvision->reconcile(dryRun: $dryRun);

    if (! $result['enabled']) {
        $this->info('Router auto-provisioning is disabled. Set AUTO_PROVISION_ROUTERS=true to enable it.');

        return Command::SUCCESS;
    }

    $provisionedLabel = $dryRun ? 'would provision' : 'provisioned';
    $this->info("Router auto-provisioning: {$provisionedLabel} ".count($result['provisioned']).' router(s), '.count($result['pending']).' still pending, '.count($result['errors']).' error(s).');

    foreach ($result['errors'] as $error) {
        $this->error($error);
    }

    return $result['errors'] !== [] ? Command::FAILURE : Command::SUCCESS;
})->purpose('Auto-complete provisioning for routers whose bootstrap script has been applied and are now reachable');

Schedule::command('hotspot:auto-provision-routers')
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
        } catch (Throwable $e) {
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
