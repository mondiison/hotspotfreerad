<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors billing_plans/tenant_billing_subscriptions exactly, one layer
     * deeper: unlike the global, super-admin-only billing_plans, each
     * tenant defines its OWN client_billing_plans for its own Clients --
     * hence the tenant_id foreign key here that billing_plans doesn't have.
     */
    public function up(): void
    {
        Schema::create('client_billing_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->unsignedInteger('shop_limit')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('client_billing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_billing_plan_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('trialing');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_billing_subscriptions');
        Schema::dropIfExists('client_billing_plans');
    }
};
