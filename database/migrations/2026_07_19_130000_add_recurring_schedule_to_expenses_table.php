<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('recurring_frequency')->nullable()->after('is_recurring');
            $table->date('next_due_on')->nullable()->after('recurring_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['recurring_frequency', 'next_due_on']);
        });
    }
};
