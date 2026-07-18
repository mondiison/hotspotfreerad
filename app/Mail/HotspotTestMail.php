<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class HotspotTestMail extends Mailable
{
    public function build(): self
    {
        return $this
            ->subject('HotspotFreeRAD mail test')
            ->text('mail.test');
    }
}
