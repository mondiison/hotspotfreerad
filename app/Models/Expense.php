<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'incurred_on' => 'date',
            'is_recurring' => 'boolean',
            'next_due_on' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function recurringSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurring_source_expense_id');
    }

    public function recurringOccurrences(): HasMany
    {
        return $this->hasMany(self::class, 'recurring_source_expense_id');
    }
}
