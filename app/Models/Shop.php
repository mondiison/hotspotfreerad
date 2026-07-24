<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'flutterwave_client_id',
        'flutterwave_client_secret',
        'flutterwave_secret_key',
        'flutterwave_webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'flutterwave_client_id' => 'encrypted',
            'flutterwave_client_secret' => 'encrypted',
            'flutterwave_secret_key' => 'encrypted',
            'flutterwave_webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function routers(): HasMany
    {
        return $this->hasMany(Router::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function pppoeSubscribers(): HasMany
    {
        return $this->hasMany(PppoeSubscriber::class);
    }

    public function voucherBatches(): HasMany
    {
        return $this->hasMany(VoucherBatch::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function hasCompleteFlutterwaveCredentials(): bool
    {
        return filled($this->flutterwave_client_id) && filled($this->flutterwave_client_secret);
    }

    public function hasFlutterwaveHostedCheckoutKey(): bool
    {
        return filled($this->flutterwave_secret_key);
    }

    public function hasFlutterwaveWebhookSecret(): bool
    {
        return filled($this->flutterwave_webhook_secret);
    }
}
