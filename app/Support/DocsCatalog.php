<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Lists and renders the markdown files in /docs for the super-admin "Docs" page.
 * Read-only: it never accepts a caller-supplied path, only a slug looked up
 * against the set of *.md files actually found in the docs directory, so it
 * can't be used to read anything outside that one folder.
 */
final class DocsCatalog
{
    /**
     * Slugs listed here sort first, in this order -- mirroring the reading
     * order CLAUDE.md itself recommends. Anything not listed sorts after
     * them alphabetically by title, so a newly added doc shows up with no
     * code change here.
     */
    private const PRIORITY_ORDER = [
        'current-project-status',
        'roles-and-product-direction',
        'deployment-architecture',
        'router-onboarding',
        'raspberry-pi-deployment',
    ];

    /**
     * @return Collection<int, array{slug: string, title: string, excerpt: string, content: string}>
     */
    public static function entries(): Collection
    {
        $directory = base_path('docs');

        if (! File::isDirectory($directory)) {
            return collect();
        }

        return collect(File::files($directory))
            ->filter(fn ($file) => strtolower($file->getExtension()) === 'md')
            ->map(fn ($file) => self::toEntry($file->getFilenameWithoutExtension(), File::get($file->getPathname())))
            ->sortBy(fn (array $entry) => [self::priorityIndex($entry['slug']), $entry['title']])
            ->values();
    }

    /**
     * @return array{slug: string, title: string, excerpt: string, content: string}|null
     */
    public static function find(string $slug): ?array
    {
        return self::entries()->firstWhere('slug', $slug);
    }

    public static function renderHtml(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * @return array{slug: string, title: string, excerpt: string, content: string}
     */
    private static function toEntry(string $slug, string $content): array
    {
        return [
            'slug' => $slug,
            'title' => self::extractTitle($content, $slug),
            'excerpt' => self::extractExcerpt($content),
            'content' => $content,
        ];
    }

    private static function extractTitle(string $content, string $slug): string
    {
        if (preg_match('/^#\s+(.+)$/m', $content, $matches) === 1) {
            return trim($matches[1], " \t#");
        }

        return Str::headline($slug);
    }

    private static function extractExcerpt(string $content): string
    {
        $withoutHeading = preg_replace('/^#\s+.+$/m', '', $content, 1) ?? $content;

        $firstParagraph = collect(preg_split('/\R{2,}/', trim($withoutHeading)) ?: [])
            ->map(fn (string $block) => trim(preg_replace('/[#*`_>-]/', '', $block) ?? ''))
            ->first(fn (string $block) => $block !== '');

        return Str::limit((string) $firstParagraph, 160);
    }

    private static function priorityIndex(string $slug): int
    {
        $index = array_search($slug, self::PRIORITY_ORDER, true);

        return $index === false ? count(self::PRIORITY_ORDER) : $index;
    }
}
