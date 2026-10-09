<?php

namespace App\Livewire\Concerns;

/**
 * 2026-10-09: shared click-to-sort-column behavior, rolled out across
 * every admin data table after SubscriptionsIndex established the
 * pattern for "Access window"/"Created". Each component still declares
 * its own $sortBy/$sortDirection properties (defaulted to that table's
 * pre-existing order, so adopting this trait never silently changes what
 * loads first) and must implement sortableColumns() as an explicit
 * allow-list -- column names get interpolated directly into orderBy(),
 * so this validates them once here rather than trusting every call site.
 */
trait SortsTable
{
    public function sortByColumn(string $column): void
    {
        if (! in_array($column, $this->sortableColumns(), true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'desc';
        }

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    /**
     * @return string[] column names this table allows sorting by.
     */
    abstract protected function sortableColumns(): array;
}
