<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('NGN');
            $table->date('incurred_on');
            $table->string('vendor')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'incurred_on']);
        });

        foreach ([
            ['Subscription', 'Internet upstream, cloud tools, domain, SMS, email, or software subscriptions.'],
            ['Personnel', 'Staff salaries, support agents, installers, or temporary labour.'],
            ['Maintenance', 'Router repair, cable replacement, site visits, and technical support.'],
            ['Equipment', 'MikroTik routers, access points, UPS, batteries, cables, and mounting materials.'],
            ['Rent and Utilities', 'Shop rent, electricity, generator fuel, security, and facility bills.'],
            ['Other', 'Expenses that do not fit the standard operating categories.'],
        ] as [$name, $description]) {
            DB::table('expense_categories')->insert([
                'name' => $name,
                'description' => $description,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
