<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_billing_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('flutterwave');
            $table->string('tx_ref')->unique();
            $table->string('provider_reference')->nullable()->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('status')->default('pending');
            $table->json('payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_billing_payments');
    }
};
