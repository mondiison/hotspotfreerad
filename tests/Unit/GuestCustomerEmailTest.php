<?php

namespace Tests\Unit;

use App\Models\Payment;
use App\Support\GuestCustomerEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestCustomerEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_provided_email_unchanged(): void
    {
        $payment = Payment::make(['id' => 42]);

        $this->assertSame('customer@example.org', GuestCustomerEmail::resolve($payment, 'customer@example.org'));
    }

    public function test_it_falls_back_to_a_synthetic_address_on_the_apps_own_domain(): void
    {
        config(['app.url' => 'https://mmsradius.com']);

        $payment = Payment::make(['id' => 79]);

        $this->assertSame('guest-79@mmsradius.com', GuestCustomerEmail::resolve($payment, null));
        $this->assertSame('guest-79@mmsradius.com', GuestCustomerEmail::resolve($payment, ''));
    }

    public function test_it_never_falls_back_to_a_reserved_non_routable_tld(): void
    {
        config(['app.url' => 'https://mmsradius.com']);

        $payment = Payment::make(['id' => 1]);

        $this->assertStringNotContainsString('.local', GuestCustomerEmail::resolve($payment, null));
    }

    public function test_it_falls_back_to_example_com_when_app_url_has_no_real_domain(): void
    {
        $payment = Payment::make(['id' => 5]);

        config(['app.url' => 'http://127.0.0.1:8001']);
        $this->assertSame('guest-5@example.com', GuestCustomerEmail::resolve($payment, null));

        config(['app.url' => 'http://localhost:8001']);
        $this->assertSame('guest-5@example.com', GuestCustomerEmail::resolve($payment, null));
    }
}
