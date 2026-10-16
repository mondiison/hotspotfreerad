<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedTinyInteger('verification_attempts')->default(0)->after('status');
            $table->timestamp('last_verification_attempt_at')->nullable()->after('verification_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['verification_attempts', 'last_verification_attempt_at']);
        });
    }
};
