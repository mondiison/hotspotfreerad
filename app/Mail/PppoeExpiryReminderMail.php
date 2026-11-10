<?php

namespace App\Mail;

use App\Models\PppoeSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

/**
 * 2026-11-10, direct request ("can we send cut off notification, 3 days
 * to expiry, 1 day and expired email"): one parameterized Mailable for
 * all three stages rather than three near-duplicate classes, since they
 * only differ in subject/tone -- the actual renewal link/copy and
 * everything else is identical. See routes/console.php's
 * hotspot:pppoe-expiry-reminders command for when each stage fires.
 */
class PppoeExpiryReminderMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public const STAGE_THREE_DAY = 'three_day';

    public const STAGE_ONE_DAY = 'one_day';

    public const STAGE_EXPIRED = 'expired';

    private const SUBJECTS = [
        self::STAGE_THREE_DAY => 'Your internet plan expires in 3 days',
        self::STAGE_ONE_DAY => 'Your internet plan expires tomorrow',
        self::STAGE_EXPIRED => 'Your internet plan has expired',
    ];

    public function __construct(
        public readonly PppoeSubscriber $subscriber,
        public readonly string $stage,
        public readonly string $renewalUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject(self::SUBJECTS[$this->stage].' -- '.$this->subscriber->shop->name)
            ->view('mail.pppoe-expiry-reminder');
    }
}
