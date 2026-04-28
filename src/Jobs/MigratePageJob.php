<?php

namespace BoldWeb\StatamicAiAssistant\Jobs;

use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Services\Migration\MigrationAssetDownloader;
use BoldWeb\StatamicAiAssistant\Services\Migration\MigrationStructurePlacement;
use BoldWeb\StatamicAiAssistant\Services\Migration\MigrationUrlNormalizer;
use BoldWeb\StatamicAiAssistant\Services\Migration\WebsiteMigrationService;
use BoldWeb\StatamicAiAssistant\Services\PromptUrlFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Migrate a single page from a source website into a Statamic entry draft.
 *
 * Flow:
 *   1. Fetch page via Jina (errors surface verbatim to the session, no retry).
 *   2. Dedupe on content hash — skip if unchanged since a prior successful run.
 *   3. Call EntryGeneratorService::generateContent with a synthetic prompt.
 *   4. Save the draft via EntryGeneratorService::saveEntry.
 *   5. Record success/failure on the session.
 */
class MigratePageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public string $sessionId,
        public string $url,
        public string $collectionHandle,
        public string $blueprintHandle,
        public string $locale,
        public ?string $parentUrl = null,
    ) {
        $this->queue = (string) config('statamic-ai-assistant.migration.queue', 'default');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // On the sync queue driver "release back to queue" is a silent no-op —
        // any rate-limited or overlap-blocked job is dropped instead of retried.
        // Skip release-based middleware in that mode (sync is single-threaded
        // anyway, so per-host throttling adds no value there).
        if (config('queue.default') === 'sync') {
            return [];
        }

        $host = (string) parse_url($this->url, PHP_URL_HOST);
        if ($host === '') {
            return [];
        }

        // Per-host rate limit only — WithoutOverlapping is intentionally not
        // used: it leaves stale locks on PHP-FPM kills, which then block every
        // subsequent attempt for the same host until the 5-minute TTL expires.
        // RateLimited covers the "don't hammer the source site" use case.
        return [new RateLimited('ai-migration-host')];
    }

    public function handle(
        WebsiteMigrationService $migration,
        EntryGeneratorService $generator,
        PromptUrlFetcher $fetcher,
        MigrationAssetDownloader $assetDownloader,
    ): void {
        $this->url = MigrationUrlNormalizer::normalize($this->url);
        if ($this->parentUrl !== null) {
            $this->parentUrl = MigrationUrlNormalizer::normalize($this->parentUrl);
        }

        $session = $migration->getSession($this->sessionId);
        if (! is_array($session) || ($session['status'] ?? '') === 'cancelled') {
            return;
        }

        $migration->markPageStatus($this->sessionId, $this->url, 'fetching');

        $fetched = $fetcher->fetchSingle($this->url);
        if (! $fetched['ok']) {
            $migration->markPageStatus(
                $this->sessionId,
                $this->url,
                'failed',
                (string) __('Content fetch failed: :err', ['err' => $fetched['error'] ?? 'unknown']),
            );
            $migration->markCompletedIfDone($this->sessionId);

            return;
        }

        $hash = hash('sha256', $fetched['body']);
        if ($migration->isUnchanged($this->sessionId, $this->url, $hash)) {
            $migration->markPageStatus($this->sessionId, $this->url, 'skipped');
            $migration->markCompletedIfDone($this->sessionId);

            return;
        }

        $migration->markPageStatus($this->sessionId, $this->url, 'generating');

        // Download every <img> in the fetched markdown into the configured
        // asset container under bold-agent-migration/{sessionId}/, and rewrite
        // the markdown so any image URLs the LLM emits in bard/markdown fields
        // already point at local public URLs.
        $migrationFolder = trim((string) config('statamic-ai-assistant.migration.asset_folder', 'bold-agent-migration'), '/');
        if ($migrationFolder === '') {
            $migrationFolder = 'bold-agent-migration';
        }
        $assetDownload = $assetDownloader->downloadFromMarkdown(
            $migrationFolder.'/'.$this->sessionId,
            $this->url,
            $fetched['body'],
        );

        $prompt = $migration->buildMigrationPrompt($this->url, $assetDownload['markdown']);

        try {
            $result = $generator->generateContent(
                $this->collectionHandle,
                $this->blueprintHandle,
                $prompt,
                $this->locale,
                attachmentContent: null,
                preferredAssets: $assetDownload['preferred'],
            );
        } catch (\Throwable $e) {
            Log::notice('MigratePageJob: generation failed', [
                'session_id' => $this->sessionId,
                'url' => $this->url,
                'message' => $e->getMessage(),
            ]);
            $migration->markPageStatus(
                $this->sessionId,
                $this->url,
                'failed',
                (string) __('Generation failed: :err', ['err' => $e->getMessage()]),
            );

            // Rethrow so Laravel can retry up to $tries.
            throw $e;
        }

        try {
            $entry = $generator->saveEntry(
                $this->collectionHandle,
                $this->blueprintHandle,
                $this->locale,
                $result['data'],
            );
        } catch (\Throwable $e) {
            Log::error('MigratePageJob: save failed', [
                'session_id' => $this->sessionId,
                'url' => $this->url,
                'message' => $e->getMessage(),
            ]);
            $migration->markPageStatus(
                $this->sessionId,
                $this->url,
                'failed',
                (string) __('Save failed: :err', ['err' => $e->getMessage()]),
            );
            throw $e;
        }

        $migration->markPageSuccess($this->sessionId, $this->url, $entry->id(), $hash);

        $this->placeInStructure($migration, $entry->id());

        $migration->markCompletedIfDone($this->sessionId);
    }

    /**
     * Place the new entry under its parent in the collection's structure tree
     * (persists to the site collection tree YAML). Waits briefly if the parent
     * is in the same migration batch but has not finished saving yet.
     *
     * A final reconciliation pass runs when the session completes so hierarchy
     * stays correct even under parallel workers.
     *
     * Errors here are swallowed — structure placement must not flip a
     * successfully-drafted entry to "failed".
     */
    private function placeInStructure(WebsiteMigrationService $migration, string $entryId): void
    {
        try {
            $session = $migration->getSession($this->sessionId);
            $parentEntryId = null;
            if ($this->parentUrl && is_array($session) && isset($session['pages'][$this->parentUrl])) {
                $parentEntryId = $this->waitForParentEntryId($migration);
            }

            MigrationStructurePlacement::ensure(
                $this->collectionHandle,
                $this->locale,
                is_string($parentEntryId) && $parentEntryId !== '' ? $parentEntryId : null,
                $entryId,
            );
        } catch (\Throwable $e) {
            Log::notice('MigratePageJob: structure placement skipped', [
                'url' => $this->url,
                'parent_url' => $this->parentUrl,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Block until the parent row has an entry_id, the parent has failed/skipped, or timeout.
     */
    private function waitForParentEntryId(WebsiteMigrationService $migration): ?string
    {
        $maxAttempts = max(10, (int) config('statamic-ai-assistant.migration.parent_entry_wait_attempts', 60));
        $sleepMs = max(50, (int) config('statamic-ai-assistant.migration.parent_entry_wait_ms', 250));

        for ($i = 0; $i < $maxAttempts; $i++) {
            $session = $migration->getSession($this->sessionId);
            if (! is_array($session) || $this->parentUrl === null) {
                return null;
            }

            $parent = $session['pages'][$this->parentUrl] ?? null;
            if (! is_array($parent)) {
                return null;
            }

            $status = (string) ($parent['status'] ?? 'pending');
            if (in_array($status, ['failed', 'skipped', 'skipped_unchanged'], true)) {
                return null;
            }

            $id = $parent['entry_id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }

            usleep($sleepMs * 1000);
        }

        return null;
    }

    public function failed(?\Throwable $exception): void
    {
        // markPageStatus has already been called in handle(); this is the final
        // after-all-retries hook. Only mark failed here if something slipped past.
        $urlKey = MigrationUrlNormalizer::normalize($this->url);
        $migration = app(WebsiteMigrationService::class);
        $session = $migration->getSession($this->sessionId);
        $currentStatus = $session['pages'][$urlKey]['status'] ?? null;
        if ($currentStatus !== 'failed') {
            $migration->markPageStatus(
                $this->sessionId,
                $urlKey,
                'failed',
                $exception?->getMessage() ?? 'Unknown error',
            );
        }

        // Final terminal state for this job — nudge the session in case this was
        // the last outstanding page. Without DB-backed batches there is no
        // "finally" callback to do this for us.
        $migration->markCompletedIfDone($this->sessionId);
    }
}
