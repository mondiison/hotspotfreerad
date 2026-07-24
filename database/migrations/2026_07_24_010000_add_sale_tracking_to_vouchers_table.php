<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->foreignId('sold_by_user_id')->nullable()->after('subscription_id')->constrained('users')->nullOnDelete();
            $table->timestamp('sold_at')->nullable()->after('used_at');
            $table->decimal('sale_amount', 10, 2)->nullable()->after('sold_at');
            $table->string('sale_reference')->nullable()->after('sale_amount');
            $table->text('sale_notes')->nullable()->after('sale_reference');

            $table->index('sold_at');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropIndex(['sold_at']);
            $table->dropConstrainedForeignId('sold_by_user_id');
            $table->dropColumn(['sold_at', 'sale_amount', 'sale_reference', 'sale_notes']);
        });
    }
};
