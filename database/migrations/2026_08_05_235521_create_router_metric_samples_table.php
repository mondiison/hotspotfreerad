<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('router_metric_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('cpu_percent')->nullable();
            $table->unsignedBigInteger('ram_used_bytes')->nullable();
            $table->unsignedBigInteger('ram_total_bytes')->nullable();
            $table->unsignedBigInteger('disk_used_bytes')->nullable();
            $table->unsignedBigInteger('disk_total_bytes')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('health')->nullable();
            $table->timestamp('sampled_at');
            $table->timestamps();

            $table->index(['router_id', 'sampled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('router_metric_samples');
    }
};
