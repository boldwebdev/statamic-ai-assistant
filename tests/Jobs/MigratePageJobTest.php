<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Jobs;

use BoldWeb\StatamicAiAssistant\Jobs\MigratePageJob;
use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Services\Migration\MigrationAssetDownloader;
use BoldWeb\StatamicAiAssistant\Services\Migration\PreferredAssetPaths;
use BoldWeb\StatamicAiAssistant\Services\Migration\WebsiteMigrationService;
use BoldWeb\StatamicAiAssistant\Services\PromptUrlFetcher;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Statamic\Entries\Entry as StatamicEntry;

class MigratePageJobTest extends TestCase
{
    private WebsiteMigrationService $migration;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // PromptUrlFetcher caches responses in a static property for the life
        // of the request — reset it between tests so a Jina fake from one test
        // doesn't bleed into the next.
        $ref = new \ReflectionClass(PromptUrlFetcher::class);
        $prop = $ref->getProperty('fetchCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $this->migration = new WebsiteMigrationService;
        $this->app->instance(WebsiteMigrationService::class, $this->migration);

        $this->sessionId = $this->migration->createSession('https://example.com', [
            ['url' => 'https://example.com/a', 'collection' => 'pages', 'blueprint' => 'page', 'locale' => 'default'],
        ]);
    }

    public function test_jina_failure_marks_page_failed_without_invoking_generator(): void
    {
        Http::fake([
            'r.jina.ai/*' => Http::response('Rate limit exceeded', 429),
        ]);

        $generator = $this->createMock(EntryGeneratorService::class);
        $generator->expects($this->never())->method('generateContent');
        $generator->expects($this->never())->method('saveEntry');

        $job = $this->makeJob();
        $job->handle($this->migration, $generator, new PromptUrlFetcher, $this->noopDownloader());

        $session = $this->migration->getSession($this->sessionId);
        $this->assertSame('failed', $session['pages']['https://example.com/a']['status']);
        $this->assertStringContainsString('Content fetch failed', $session['pages']['https://example.com/a']['error']);
        $this->assertSame(1, $session['counts']['failed']);
    }

    public function test_successful_flow_marks_page_completed_with_hash(): void
    {
        Http::fake([
            'r.jina.ai/*' => Http::response('# Hello World', 200),
        ]);

        $entry = $this->createStub(StatamicEntry::class);
        $entry->method('id')->willReturn('entry-42');

        $generator = $this->createMock(EntryGeneratorService::class);
        $generator->expects($this->once())
            ->method('generateContent')
            ->willReturn([
                'data' => ['title' => 'Hello World'],
                'displayData' => ['title' => 'Hello World'],
                'warnings' => [],
            ]);
        $generator->expects($this->once())
            ->method('saveEntry')
            ->willReturn($entry);

        $job = $this->makeJob();
        $job->handle($this->migration, $generator, new PromptUrlFetcher, $this->noopDownloader());

        $session = $this->migration->getSession($this->sessionId);
        $this->assertSame('completed', $session['pages']['https://example.com/a']['status']);
        $this->assertSame('entry-42', $session['pages']['https://example.com/a']['entry_id']);
        $this->assertNotEmpty($session['pages']['https://example.com/a']['content_hash']);
    }

    public function test_unchanged_content_skips_generation(): void
    {
        Http::fake([
            'r.jina.ai/*' => Http::response('# Unchanged body', 200),
        ]);

        $priorHash = hash('sha256', '# Unchanged body');
        $this->migration->markPageSuccess($this->sessionId, 'https://example.com/a', 'old-entry-1', $priorHash);

        $generator = $this->createMock(EntryGeneratorService::class);
        $generator->expects($this->never())->method('generateContent');
        $generator->expects($this->never())->method('saveEntry');

        $job = $this->makeJob();
        $job->handle($this->migration, $generator, new PromptUrlFetcher, $this->noopDownloader());

        $session = $this->migration->getSession($this->sessionId);
        $this->assertSame('skipped', $session['pages']['https://example.com/a']['status']);
    }

    public function test_cancelled_session_aborts_before_fetching(): void
    {
        $this->migration->cancelSession($this->sessionId);

        Http::fake();

        $generator = $this->createMock(EntryGeneratorService::class);
        $generator->expects($this->never())->method('generateContent');

        $job = $this->makeJob();
        $job->handle($this->migration, $generator, new PromptUrlFetcher, $this->noopDownloader());

        Http::assertNothingSent();
    }

    public function test_generator_exception_marks_page_failed_and_rethrows(): void
    {
        Http::fake([
            'r.jina.ai/*' => Http::response('# content', 200),
        ]);

        $generator = $this->createMock(EntryGeneratorService::class);
        $generator->method('generateContent')->willThrowException(new \RuntimeException('LLM timeout'));
        $generator->expects($this->never())->method('saveEntry');

        $job = $this->makeJob();

        $thrown = null;
        try {
            $job->handle($this->migration, $generator, new PromptUrlFetcher, $this->noopDownloader());
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame('LLM timeout', $thrown->getMessage());

        $session = $this->migration->getSession($this->sessionId);
        $this->assertSame('failed', $session['pages']['https://example.com/a']['status']);
        $this->assertStringContainsString('Generation failed', $session['pages']['https://example.com/a']['error']);
    }

    private function makeJob(): MigratePageJob
    {
        return new MigratePageJob(
            sessionId: $this->sessionId,
            url: 'https://example.com/a',
            collectionHandle: 'pages',
            blueprintHandle: 'page',
            locale: 'default',
        );
    }

    private function noopDownloader(): MigrationAssetDownloader
    {
        // Returns the markdown unchanged with no preferred assets, so the
        // existing job tests aren't affected by the new asset-download step.
        return new class extends MigrationAssetDownloader {
            public function downloadFromMarkdown(string $sessionId, string $sourceUrl, string $markdown): array
            {
                return ['markdown' => $markdown, 'preferred' => new PreferredAssetPaths];
            }
        };
    }
}
