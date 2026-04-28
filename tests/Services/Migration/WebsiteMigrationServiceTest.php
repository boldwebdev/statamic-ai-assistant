<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services\Migration;

use BoldWeb\StatamicAiAssistant\Services\Migration\WebsiteMigrationService;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class WebsiteMigrationServiceTest extends TestCase
{
    private WebsiteMigrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = new WebsiteMigrationService;
    }

    public function test_creates_session_with_pending_pages(): void
    {
        $id = $this->service->createSession('https://example.com', [
            ['url' => 'https://example.com/a', 'collection' => 'pages', 'blueprint' => 'page', 'locale' => 'default'],
            ['url' => 'https://example.com/b', 'collection' => 'pages', 'blueprint' => 'page', 'locale' => 'default'],
        ], ['some warning']);

        $session = $this->service->getSession($id);
        $this->assertIsArray($session);
        $this->assertSame('planning', $session['status']);
        $this->assertSame(2, $session['total']);
        $this->assertSame(2, $session['counts']['pending']);
        $this->assertSame(0, $session['counts']['completed']);
        $this->assertCount(2, $session['pages']);
        $this->assertSame('pending', $session['pages']['https://example.com/a']['status']);
        $this->assertSame(['some warning'], $session['warnings']);
        $this->assertFalse($session['structure_reconcile_done'] ?? true);
    }

    public function test_mark_page_status_updates_counts(): void
    {
        $id = $this->service->createSession('https://example.com', [
            ['url' => 'https://example.com/a', 'collection' => 'c', 'blueprint' => 'b', 'locale' => 'default'],
            ['url' => 'https://example.com/b', 'collection' => 'c', 'blueprint' => 'b', 'locale' => 'default'],
        ]);

        $this->service->markPageStatus($id, 'https://example.com/a', 'fetching');
        $session = $this->service->getSession($id);
        $this->assertSame(1, $session['counts']['running']);
        $this->assertSame(1, $session['counts']['pending']);
        $this->assertNotNull($session['pages']['https://example.com/a']['started_at']);

        $this->service->markPageStatus($id, 'https://example.com/a', 'failed', 'Jina 429');
        $session = $this->service->getSession($id);
        $this->assertSame(1, $session['counts']['failed']);
        $this->assertSame(0, $session['counts']['running']);
        $this->assertSame('Jina 429', $session['pages']['https://example.com/a']['error']);
    }

    public function test_mark_page_success_records_entry_id_and_hash(): void
    {
        $id = $this->service->createSession('https://example.com', [
            ['url' => 'https://example.com/a', 'collection' => 'c', 'blueprint' => 'b', 'locale' => 'default'],
        ]);

        $this->service->markPageSuccess($id, 'https://example.com/a', 'entry-123', 'abc123');

        $session = $this->service->getSession($id);
        $this->assertSame('completed', $session['pages']['https://example.com/a']['status']);
        $this->assertSame('entry-123', $session['pages']['https://example.com/a']['entry_id']);
        $this->assertSame('abc123', $session['pages']['https://example.com/a']['content_hash']);
        $this->assertSame(1, $session['counts']['completed']);
    }

    public function test_is_unchanged_returns_true_when_hash_matches_and_status_is_completed(): void
    {
        $id = $this->service->createSession('https://example.com', [
            ['url' => 'https://example.com/a', 'collection' => 'c', 'blueprint' => 'b', 'locale' => 'default'],
        ]);
        $this->service->markPageSuccess($id, 'https://example.com/a', 'e1', 'HASH');

        $this->assertTrue($this->service->isUnchanged($id, 'https://example.com/a', 'HASH'));
        $this->assertFalse($this->service->isUnchanged($id, 'https://example.com/a', 'DIFFERENT'));
        $this->assertFalse($this->service->isUnchanged($id, 'https://example.com/unknown', 'HASH'));
    }

    public function test_cancel_session_sets_status(): void
    {
        $id = $this->service->createSession('https://example.com', [
            ['url' => 'https://example.com/a', 'collection' => 'c', 'blueprint' => 'b', 'locale' => 'default'],
        ]);
        $this->service->markRunning($id);

        $this->service->cancelSession($id);
        $session = $this->service->getSession($id);
        $this->assertSame('cancelled', $session['status']);
    }

    public function test_mark_completed_only_transitions_when_pending_and_running_are_zero(): void
    {
        $id = $this->service->createSession('https://example.com', [
            ['url' => 'https://example.com/a', 'collection' => 'c', 'blueprint' => 'b', 'locale' => 'default'],
            ['url' => 'https://example.com/b', 'collection' => 'c', 'blueprint' => 'b', 'locale' => 'default'],
        ]);
        $this->service->markRunning($id);

        // Only one done — should stay running.
        $this->service->markPageSuccess($id, 'https://example.com/a', 'e1', 'h1');
        $this->service->markCompletedIfDone($id);
        $this->assertSame('running', $this->service->getSession($id)['status']);

        // All done.
        $this->service->markPageStatus($id, 'https://example.com/b', 'failed', 'nope');
        $this->service->markCompletedIfDone($id);
        $final = $this->service->getSession($id);
        $this->assertSame('completed', $final['status']);
        $this->assertTrue($final['structure_reconcile_done'] ?? false);
    }

    public function test_build_migration_prompt_includes_url_and_content(): void
    {
        $prompt = $this->service->buildMigrationPrompt('https://example.com/a', 'Hello content');
        $this->assertStringContainsString('https://example.com/a', $prompt);
        $this->assertStringContainsString('Hello content', $prompt);
    }
}
