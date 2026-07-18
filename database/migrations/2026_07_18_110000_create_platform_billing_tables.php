<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('monthly_price', 10, 2)->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->unsignedInteger('shop_limit')->nullable();
            $table->unsignedInteger('router_limit')->nullable();
            $table->unsignedInteger('package_limit')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_billing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_plan_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('trialing');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('provider')->default('flutterwave');
            $table->string('provider_reference')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        DB::table('billing_plans')->insert([
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'monthly_price' => 15000,
                'currency' => 'NGN',
                'shop_limit' => 1,
                'router_limit' => 2,
                'package_limit' => 10,
                'features' => json_encode(['Tenant public site', 'Captive portal', 'RADIUS provisioning']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Growth',
                'slug' => 'growth',
                'monthly_price' => 35000,
                'currency' => 'NGN',
                'shop_limit' => 5,
                'router_limit' => 10,
                'package_limit' => 50,
                'features' => json_encode(['All Starter features', 'Multi-location reporting', 'Tenant-owned payments']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Scale',
                'slug' => 'scale',
                'monthly_price' => 75000,
                'currency' => 'NGN',
                'shop_limit' => null,
                'router_limit' => null,
                'package_limit' => null,
                'features' => json_encode(['All Growth features', 'Unlimited locations', 'Priority support']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_subscriptions');
        Schema::dropIfExists('billing_plans');
    }
};
