<?php

namespace Tests\Feature;

use App\Mail\PppoeExpiryReminderMail;
use App\Models\Package;
use App\Models\PppoeSubscriber;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\PppoeSubscriberManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PppoeExpiryReminderCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_three_day_reminder_once_within_the_window(): void
    {
        Mail::fake();
        [$shop, $package] = $this->fixture(reminders: true);
        $subscriber = $this->subscriber($shop, $package, now()->addDays(2));

        $this->artisan('hotspot:pppoe-expiry-reminders')
            ->expectsOutput('Sent 1 three-day, 0 one-day, and 0 expired PPPoE reminder email(s).')
            ->assertExitCode(0);

        Mail::assertQueued(PppoeExpiryReminderMail::class, fn ($mail) => $mail->hasTo($subscriber->email)
            && $mail->stage === PppoeExpiryReminderMail::STAGE_THREE_DAY);

        $subscriber->refresh();
        $this->assertNotNull($subscriber->reminder_3day_sent_at);

        // Running it again should not resend the same reminder.
        Mail::fake();
        $this->artisan('hotspot:pppoe-expiry-reminders')
            ->expectsOutput('Sent 0 three-day, 0 one-day, and 0 expired PPPoE reminder email(s).');
        Mail::assertNothingQueued();
    }

    /**
     * An already-expired subscriber who's never had ANY reminder sent yet
     * (e.g. the feature was just turned on for this shop) qualifies for the
     * one-day reminder too, not just "expired" -- both fire independently
     * in the same run, confirmed deliberately rather than assumed: this is
     * the same "catch up" semantics a subscriber crossing straight past an
     * earlier, never-fired threshold is expected to get.
     */
    public function test_sends_one_day_and_expired_reminders_independently(): void
    {
        Mail::fake();
        [$shop, $package] = $this->fixture(reminders: true);
        $oneDay = $this->subscriber($shop, $package, now()->addHours(12));
        $expired = $this->subscriber($shop, $package, now()->subDay(), email: 'expired@example.com');

        $this->artisan('hotspot:pppoe-expiry-reminders')
            ->expectsOutput('Sent 2 three-day, 2 one-day, and 1 expired PPPoE reminder email(s).')
            ->assertExitCode(0);

        Mail::assertQueued(PppoeExpiryReminderMail::class, fn ($mail) => $mail->hasTo($oneDay->email) && $mail->stage === PppoeExpiryReminderMail::STAGE_ONE_DAY);
        Mail::assertQueued(PppoeExpiryReminderMail::class, fn ($mail) => $mail->hasTo($expired->email) && $mail->stage === PppoeExpiryReminderMail::STAGE_EXPIRED);

        // A subscriber one day out also still qualifies for the three-day
        // reminder (never sent yet) -- both fire independently in the same run.
        Mail::assertQueued(PppoeExpiryReminderMail::class, fn ($mail) => $mail->hasTo($oneDay->email) && $mail->stage === PppoeExpiryReminderMail::STAGE_THREE_DAY);
    }

    public function test_reminders_are_never_sent_for_a_shop_that_has_not_opted_in(): void
    {
        Mail::fake();
        [$shop, $package] = $this->fixture(reminders: false);
        $this->subscriber($shop, $package, now()->subDay());

        $this->artisan('hotspot:pppoe-expiry-reminders')
            ->expectsOutput('Sent 0 three-day, 0 one-day, and 0 expired PPPoE reminder email(s).');

        Mail::assertNothingQueued();
    }

    public function test_renewing_resets_the_reminder_flags_for_the_next_cycle(): void
    {
        [$shop, $package] = $this->fixture(reminders: true);
        $subscriber = $this->subscriber($shop, $package, now()->subDay());
        $subscriber->forceFill([
            'reminder_3day_sent_at' => now()->subDays(2),
            'reminder_1day_sent_at' => now()->subDay(),
            'reminder_expired_sent_at' => now(),
        ])->save();

        app(PppoeSubscriberManagementService::class)->renewFromOnlinePayment($subscriber);

        $subscriber->refresh();
        $this->assertNull($subscriber->reminder_3day_sent_at);
        $this->assertNull($subscriber->reminder_1day_sent_at);
        $this->assertNull($subscriber->reminder_expired_sent_at);
        $this->assertTrue($subscriber->expires_at->isFuture());
    }

    private function fixture(bool $reminders): array
    {
        $tenant = Tenant::create([
            'company_name' => 'Reminder Tenant',
            'owner_email' => fake()->unique()->safeEmail(),
        ]);
        $shop = Shop::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reminder Shop',
            'pppoe_expiry_reminders_enabled' => $reminders,
        ]);
        $package = Package::create([
            'shop_id' => $shop->id,
            'name' => 'Home 10M',
            'service_type' => 'pppoe',
            'price' => 12000,
            'currency' => 'NGN',
            'limit_uptime_seconds' => 2592000,
            'speed_limit_profile' => '10M/10M',
            'is_active' => true,
        ]);

        return [$shop, $package];
    }

    private function subscriber(Shop $shop, Package $package, Carbon $expiresAt, string $email = 'customer@example.com'): PppoeSubscriber
    {
        return PppoeSubscriber::create([
            'shop_id' => $shop->id,
            'package_id' => $package->id,
            'username' => 'customer-'.uniqid(),
            'password' => 'secret-pass',
            'email' => $email,
            'starts_at' => now()->subMonth(),
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);
    }
}
