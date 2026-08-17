<?php

namespace Tests\Unit;

use App\Support\DocsCatalog;
use Tests\TestCase;

class DocsCatalogTest extends TestCase
{
    public function test_entries_lists_every_markdown_file_in_the_docs_directory(): void
    {
        $slugs = DocsCatalog::entries()->pluck('slug');

        $this->assertTrue($slugs->contains('current-project-status'));
        $this->assertTrue($slugs->contains('wireguard-server-setup'));
        $this->assertSame(count(glob(base_path('docs').'/*.md')), $slugs->count());
    }

    public function test_entries_puts_the_priority_docs_first_in_the_documented_reading_order(): void
    {
        $slugs = DocsCatalog::entries()->pluck('slug')->take(5)->values()->all();

        $this->assertSame([
            'current-project-status',
            'roles-and-product-direction',
            'deployment-architecture',
            'router-onboarding',
            'raspberry-pi-deployment',
        ], $slugs);
    }

    public function test_find_returns_the_matching_entry_by_slug(): void
    {
        $entry = DocsCatalog::find('current-project-status');

        $this->assertNotNull($entry);
        $this->assertSame('current-project-status', $entry['slug']);
        $this->assertNotSame('', $entry['title']);
        $this->assertStringContainsString('#', $entry['content']);
    }

    public function test_find_returns_null_for_an_unknown_slug(): void
    {
        $this->assertNull(DocsCatalog::find('does-not-exist'));
        $this->assertNull(DocsCatalog::find('../../.env'));
    }

    public function test_render_html_converts_markdown_to_html_and_strips_raw_html(): void
    {
        $html = DocsCatalog::renderHtml("# Title\n\nSome *text* with a <script>alert(1)</script> tag.");

        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('<em>text</em>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_render_html_leaves_fenced_code_blocks_as_escaped_text(): void
    {
        $html = DocsCatalog::renderHtml("```html\n<body>hi</body>\n```");

        $this->assertStringContainsString('&lt;body&gt;hi&lt;/body&gt;', $html);
        $this->assertStringNotContainsString('<body>hi</body>', $html);
    }
}
