<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors platform_billing_payments exactly, one layer deeper: a Client
     * paying its Tenant instead of a Tenant paying the platform.
     */
    public function up(): void
    {
        Schema::create('client_billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_billing_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_billing_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('tx_ref')->unique();
            $table->string('provider_reference')->nullable()->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('status')->default('pending');
            $table->json('payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_billing_payments');
    }
};
