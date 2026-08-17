<?php

namespace App\Livewire\Admin;

use App\Support\DocsCatalog;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class DocsIndex extends Component
{
    #[Url(as: 'doc', history: true)]
    public ?string $activeSlug = null;

    public string $search = '';

    public bool $showListOnMobile = true;

    public function mount(): void
    {
        $entries = DocsCatalog::entries();

        if ($this->activeSlug === null && $entries->isNotEmpty()) {
            $this->activeSlug = $entries->first()['slug'];
        } else {
            $this->showListOnMobile = false;
        }
    }

    public function open(string $slug): void
    {
        $this->activeSlug = $slug;
        $this->showListOnMobile = false;
    }

    public function back(): void
    {
        $this->showListOnMobile = true;
    }

    /**
     * @return Collection<int, array{slug: string, title: string, excerpt: string, content: string}>
     */
    public function getFilteredEntriesProperty(): Collection
    {
        $entries = DocsCatalog::entries();

        $search = trim($this->search);

        if ($search === '') {
            return $entries;
        }

        return $entries->filter(function (array $entry) use ($search): bool {
            return str_contains(mb_strtolower($entry['title']), mb_strtolower($search))
                || str_contains(mb_strtolower($entry['content']), mb_strtolower($search));
        })->values();
    }

    public function getActiveEntryProperty(): ?array
    {
        if ($this->activeSlug === null) {
            return null;
        }

        return DocsCatalog::find($this->activeSlug);
    }

    public function getActiveHtmlProperty(): ?string
    {
        $entry = $this->activeEntry;

        return $entry === null ? null : DocsCatalog::renderHtml($entry['content']);
    }

    public function render()
    {
        return view('livewire.admin.docs-index');
    }
}
