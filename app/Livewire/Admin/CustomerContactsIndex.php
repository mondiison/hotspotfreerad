<?php

namespace App\Livewire\Admin;

use App\Services\CustomerContactDirectoryService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 2026-11-10: a read-only, deduplicated directory of every customer-facing
 * phone number this app has collected -- see
 * CustomerContactDirectoryService's own docblock for why the merge/dedup
 * happens in PHP rather than SQL. Pagination is built by hand from the
 * already-in-memory, already-filtered Collection (there's no Eloquent
 * builder to paginate here), the standard "slice a Collection into a
 * LengthAwarePaginator" shape for a Livewire list backed by something
 * other than a query builder.
 */
class CustomerContactsIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $shopId = '';

    protected $queryString = [
        'search' => ['except' => ''],
        'shopId' => ['as' => 'shop', 'except' => ''],
    ];

    public function updated($property): void
    {
        if (in_array($property, ['search', 'shopId'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'shopId']);
        $this->resetPage();
    }

    public function render(CustomerContactDirectoryService $directory)
    {
        $user = auth()->user();

        $contacts = $directory->uniqueContacts($user, $this->shopId !== '' ? (int) $this->shopId : null);

        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $contacts = $contacts->filter(fn (array $contact): bool => str_contains($contact['phone'], $this->search)
                || str_contains(strtolower((string) $contact['contact_name']), $needle)
                || collect($contact['shops'])->contains(fn (string $shop): bool => str_contains(strtolower($shop), $needle)));
        }

        $perPage = 25;
        $page = $this->getPage();

        $paginated = new LengthAwarePaginator(
            $contacts->forPage($page, $perPage)->values(),
            $contacts->count(),
            $perPage,
            $page,
            ['pageName' => 'page']
        );

        return view('livewire.admin.customer-contacts-index', [
            'contacts' => $paginated,
            'totalUnique' => $contacts->count(),
            'allNumbers' => $contacts->pluck('phone')->implode(', '),
            'shops' => $directory->shopsFor($user),
        ]);
    }
}
