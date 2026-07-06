<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class FindEntriesShortlistTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::default()->handle();

        // The Stache persists across tests in a single process run, so start clean.
        Entry::all()->each(fn ($e) => $e->delete());

        Blueprint::make('package')->setNamespace('collections.packages')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ]]]]],
        ])->save();

        Collection::make('packages')->title('Packages')->sites([$this->site])->save();
    }

    private function makeEntry(string $title, string $slug): void
    {
        Entry::make()
            ->collection('packages')
            ->locale($this->site)
            ->slug($slug)
            ->data(['title' => $title])
            ->save();
    }

    private function service(): EntryGeneratorService
    {
        return app(EntryGeneratorService::class);
    }

    /** @return array<int, string> */
    private function titles(?string $collection, string $query, int $limit = 10): array
    {
        return array_column($this->service()->findEntriesShortlist($collection, $query, $limit), 'title');
    }

    /**
     * The bug that started this: an ampersand title could not be found once the
     * user/LLM normalised the "&" away. All of these must resolve to the entry.
     */
    #[DataProvider('ampersandQueries')]
    public function test_ampersand_titles_are_found_regardless_of_how_the_connector_is_written(string $query): void
    {
        $this->makeEntry('Body & Soul', 'body-soul');
        $this->makeEntry('After Sunrise', 'after-sunrise'); // decoy

        $this->assertContains('Body & Soul', $this->titles('packages', $query), "query [{$query}] did not find the entry");
    }

    /** @return array<int, array<int, string>> */
    public static function ampersandQueries(): array
    {
        return [
            'literal ampersand' => ['Body & Soul'],
            'spelled out and' => ['Body and Soul'],
            'connector dropped' => ['Body Soul'],
            'plus sign' => ['Body + Soul'],
            'lower case' => ['body soul'],
            'reversed order' => ['Soul Body'],
            'single token' => ['Soul'],
        ];
    }

    public function test_diacritics_are_folded_for_the_query_side(): void
    {
        $this->makeEntry('Café Menü', 'cafe-menu');

        // Accented stored title, plain-ASCII query.
        $this->assertContains('Café Menü', $this->titles('packages', 'cafe menu'));
    }

    public function test_best_match_is_ranked_first(): void
    {
        $this->makeEntry('Body & Soul', 'body-soul');
        $this->makeEntry('Body & Soul Deluxe Weekend', 'body-soul-deluxe');

        $titles = $this->titles('packages', 'Body & Soul');

        // Exact title beats the longer superset title.
        $this->assertSame('Body & Soul', $titles[0]);
    }

    public function test_requires_all_tokens_so_unrelated_entries_are_excluded(): void
    {
        $this->makeEntry('Body & Soul', 'body-soul');
        $this->makeEntry('Day Spa', 'day-spa');

        $titles = $this->titles('packages', 'Body Soul');

        $this->assertContains('Body & Soul', $titles);
        $this->assertNotContains('Day Spa', $titles);
    }

    public function test_relaxes_to_any_token_when_no_entry_matches_all(): void
    {
        $this->makeEntry('Body & Soul', 'body-soul');

        // "Wellness" matches nothing; without the relax fallback the strict AND
        // would return zero rows and the agent would give up.
        $titles = $this->titles('packages', 'Body Wellness');

        $this->assertContains('Body & Soul', $titles);
    }

    public function test_empty_query_returns_recent_entries(): void
    {
        $this->makeEntry('Body & Soul', 'body-soul');
        $this->makeEntry('Day Spa', 'day-spa');

        $this->assertCount(2, $this->titles('packages', '   '));
    }
}
