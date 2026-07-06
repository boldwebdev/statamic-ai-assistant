<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class SearchEntryContentTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::default()->handle();
        Entry::all()->each(fn ($e) => $e->delete());

        Blueprint::make('package')->setNamespace('collections.packages')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'body', 'field' => ['type' => 'replicator']],
            ]]]]],
        ])->save();

        Collection::make('packages')->title('Packages')->sites([$this->site])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function makeEntry(string $id, string $title, array $data = []): void
    {
        Entry::make()->id($id)->collection('packages')->locale($this->site)
            ->slug(\Illuminate\Support\Str::slug($title))
            ->data(array_merge(['title' => $title], $data))
            ->save();
    }

    private function service(): EntryGeneratorService
    {
        return app(EntryGeneratorService::class);
    }

    public function test_finds_entry_by_a_phrase_split_across_rich_text_nodes(): void
    {
        // Mirrors the real data: "Kursleitung:" and "Claudia Eva Reinig" live in
        // separate rich-text nodes, so the phrase is never stored contiguously.
        $this->makeEntry('yoga-id', 'Yoga & Wellness', ['body' => [
            [
                'type' => 'set',
                'id' => 'set-1',
                'values' => [
                    'text' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Kursleitung:']]],
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => ' Claudia Eva Reinig']]],
                    ],
                ],
            ],
        ]]);
        $this->makeEntry('spa-id', 'Day Spa', ['body' => [
            ['type' => 'set', 'id' => 's', 'values' => ['text' => 'A relaxing day at the spa.']],
        ]]);

        $results = $this->service()->searchEntryContent('packages', 'Kursleitung: Claudia Eva Reinig', 10);

        $this->assertCount(1, $results);
        $this->assertSame('yoga-id', $results[0]['id']);
        $this->assertSame('Yoga & Wellness', $results[0]['title']);
        $this->assertStringContainsString('Claudia', $results[0]['snippet']);
    }

    public function test_title_only_search_does_not_find_body_phrases(): void
    {
        // Proves why this tool is needed: find_entries (title/slug) cannot see it.
        $this->makeEntry('yoga-id', 'Yoga & Wellness', ['body' => [
            ['type' => 'set', 'id' => 's', 'values' => ['text' => 'Kursleitung Claudia Eva Reinig']],
        ]]);

        $this->assertSame([], $this->service()->findEntriesShortlist('packages', 'Claudia Eva Reinig', 10));
        $this->assertCount(1, $this->service()->searchEntryContent('packages', 'Claudia Eva Reinig', 10));
    }

    public function test_requires_all_tokens_present_in_content(): void
    {
        $this->makeEntry('a-id', 'A', ['body' => ['text' => 'Claudia teaches yoga.']]);
        $this->makeEntry('b-id', 'B', ['body' => ['text' => 'Reinig is a surname.']]);

        // Neither entry contains BOTH "Claudia" and "Reinig".
        $this->assertSame([], $this->service()->searchEntryContent('packages', 'Claudia Reinig', 10));
    }

    public function test_matching_is_case_and_diacritic_tolerant(): void
    {
        $this->makeEntry('cafe-id', 'Cafe', ['body' => ['text' => 'Unser Café bietet Kücheküche.']]);

        $this->assertCount(1, $this->service()->searchEntryContent('packages', 'cafe', 10));
    }

    public function test_empty_query_returns_nothing(): void
    {
        $this->makeEntry('a-id', 'A', ['body' => ['text' => 'anything']]);

        $this->assertSame([], $this->service()->searchEntryContent('packages', '   ', 10));
    }

    public function test_structural_keys_are_not_matched(): void
    {
        // "set" / "paragraph" are structural type values; a query for them must not
        // match every entry that has a replicator/Bard body.
        $this->makeEntry('a-id', 'A', ['body' => [
            ['type' => 'set', 'id' => 'xyz', 'values' => ['text' => 'Hello world.']],
        ]]);

        $this->assertSame([], $this->service()->searchEntryContent('packages', 'set', 10));
        $this->assertSame([], $this->service()->searchEntryContent('packages', 'xyz', 10));
    }
}
