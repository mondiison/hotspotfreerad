<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->unsignedTinyInteger('code_length')->default(8);
            $table->string('prefix', 16)->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('status')->default('unused');
            $table->string('used_mac_address')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index('used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('voucher_batches');
    }
};
