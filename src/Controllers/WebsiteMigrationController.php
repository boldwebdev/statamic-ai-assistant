<?php

namespace BoldWeb\StatamicAiAssistant\Controllers;

use BoldWeb\StatamicAiAssistant\Jobs\MigratePageJob;
use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Services\Migration\CollectionMatcher;
use BoldWeb\StatamicAiAssistant\Services\Migration\SitemapDiscoveryService;
use BoldWeb\StatamicAiAssistant\Services\Migration\MigrationUrlNormalizer;
use BoldWeb\StatamicAiAssistant\Services\Migration\UrlClusterer;
use BoldWeb\StatamicAiAssistant\Services\Migration\WebsiteMigrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Site;

class WebsiteMigrationController
{
    public function __construct(
        private SitemapDiscoveryService $discovery,
        private UrlClusterer $clusterer,
        private WebsiteMigrationService $migration,
        private EntryGeneratorService $generator,
        private CollectionMatcher $matcher,
    ) {}

    /**
     * Step 1: discover URLs for a website.
     */
    public function discover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_url' => 'required|string|max:2048',
            'max_pages' => 'sometimes|integer|min:1|max:10000',
            'follow_depth' => 'sometimes|integer|min:0|max:6',
            'include' => 'sometimes|array',
            'include.*' => 'string|max:500',
            'exclude' => 'sometimes|array',
            'exclude.*' => 'string|max:500',
            'respect_robots' => 'sometimes|boolean',
        ]);

        $maxPagesCap = (int) config('statamic-ai-assistant.migration.max_pages_per_session', 500);

        try {
            $result = $this->discovery->discover($data['site_url'], [
                'max_pages' => min((int) ($data['max_pages'] ?? $maxPagesCap), $maxPagesCap),
                'follow_depth' => (int) ($data['follow_depth'] ?? config('statamic-ai-assistant.migration.crawl_max_depth', 3)),
                'include' => $data['include'] ?? [],
                'exclude' => $data['exclude'] ?? [],
                'respect_robots' => (bool) ($data['respect_robots'] ?? config('statamic-ai-assistant.migration.respect_robots_txt', true)),
                'timeout' => (int) config('statamic-ai-assistant.migration.discovery_timeout', 20),
                'budget_seconds' => (int) config('statamic-ai-assistant.migration.discovery_budget', 25),
            ]);

            $clusters = $this->clusterer->cluster($result['urls']);
            $collections = $this->generator->getCollectionsCatalog();
            $suggestions = $this->matcher->suggest($clusters, $collections);

            $warnings = $result['warnings'];
            if ($queueWarning = $this->queueSetupWarning()) {
                $warnings[] = $queueWarning;
            }

            return response()->json([
                'source' => $result['source'],
                'urls' => $result['urls'],
                'clusters' => $clusters,
                'suggestions' => $suggestions,
                'warnings' => $warnings,
                'robots_excluded' => $result['robots_excluded'],
                'collections' => $collections,
                'locales' => Site::all()->map(fn ($s) => ['handle' => $s->handle(), 'locale' => $s->locale(), 'name' => $s->name()])->values(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Website migration discover failed', [
                'site_url' => $data['site_url'],
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => __('Discovery failed: :message', ['message' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Step 2: create a session from an explicit per-URL plan and kick off jobs.
     */
    public function start(Request $request): JsonResponse
    {
        if ($error = $this->queueSetupError()) {
            return response()->json(['error' => $error], 422);
        }

        $data = $request->validate([
            'site_url' => 'required|string|max:2048',
            'pages' => 'required|array|min:1',
            'pages.*.url' => 'required|string|max:2048',
            'pages.*.collection' => 'required|string|max:100',
            'pages.*.blueprint' => 'required|string|max:100',
            'pages.*.locale' => 'required|string|max:30',
            'warnings' => 'sometimes|array',
            'warnings.*' => 'string|max:500',
        ]);

        $maxPagesCap = (int) config('statamic-ai-assistant.migration.max_pages_per_session', 500);
        $pages = array_slice($data['pages'], 0, $maxPagesCap);

        foreach ($pages as $k => $p) {
            $pages[$k]['url'] = MigrationUrlNormalizer::normalize((string) ($p['url'] ?? ''));
        }

        // Sort by URL path depth so parent pages migrate (or at least start) before
        // their children — children can then be placed under the parent in the
        // collection's structure tree.
        usort($pages, function (array $a, array $b) {
            $ua = (string) ($a['url'] ?? '');
            $ub = (string) ($b['url'] ?? '');
            $da = MigrationUrlNormalizer::pathDepth($ua);
            $db = MigrationUrlNormalizer::pathDepth($ub);
            if ($da !== $db) {
                return $da <=> $db;
            }

            return strcmp($ua, $ub);
        });

        $sessionId = $this->migration->createSession(
            $data['site_url'],
            $pages,
            $data['warnings'] ?? [],
        );

        $this->migration->markRunning($sessionId);

        foreach ($pages as $p) {
            try {
                MigratePageJob::dispatch(
                    $sessionId,
                    $p['url'],
                    $p['collection'],
                    $p['blueprint'],
                    $p['locale'],
                    MigrationUrlNormalizer::parentUrl((string) $p['url']),
                );
            } catch (\Throwable $e) {
                // On the sync driver dispatch runs the job inline; an
                // un-handled throw would otherwise abort the rest of the
                // foreach. Mark this page failed and keep going.
                Log::error('MigratePageJob inline dispatch failed', [
                    'session_id' => $sessionId,
                    'url' => $p['url'],
                    'message' => $e->getMessage(),
                ]);
                $this->migration->markPageStatus(
                    $sessionId,
                    (string) $p['url'],
                    'failed',
                    (string) __('Dispatch failed: :err', ['err' => $e->getMessage()]),
                );
            }
        }

        $this->migration->markCompletedIfDone($sessionId);

        return response()->json([
            'session_id' => $sessionId,
            'total' => count($pages),
        ]);
    }

    /**
     * Step 3: poll session progress.
     */
    public function progress(string $sessionId): JsonResponse
    {
        $session = $this->migration->getSession($sessionId);
        if (! $session) {
            return response()->json(['error' => __('Session not found.')], 404);
        }

        // Re-evaluate terminal state in case the batch callback missed the session update.
        if (($session['status'] ?? '') === 'running') {
            $this->migration->markCompletedIfDone($sessionId);
            $session = $this->migration->getSession($sessionId);
        }

        return response()->json($session);
    }

    public function cancel(string $sessionId): JsonResponse
    {
        $session = $this->migration->getSession($sessionId);
        if (! $session) {
            return response()->json(['error' => __('Session not found.')], 404);
        }

        // Each MigratePageJob checks the session status before doing work, so
        // flipping the session to "cancelled" makes pending jobs exit cleanly
        // when workers pick them up. (No DB-backed batch to cancel.)
        $this->migration->cancelSession($sessionId);

        return response()->json(['ok' => true]);
    }

    public function retry(string $sessionId): JsonResponse
    {
        if ($error = $this->queueSetupError()) {
            return response()->json(['error' => $error], 422);
        }

        $session = $this->migration->getSession($sessionId);
        if (! $session) {
            return response()->json(['error' => __('Session not found.')], 404);
        }

        $failed = array_values(array_filter(
            $session['pages'] ?? [],
            fn ($p) => ($p['status'] ?? '') === 'failed',
        ));

        if ($failed === []) {
            return response()->json(['ok' => true, 'retried' => 0]);
        }

        $this->migration->markRunning($sessionId);

        foreach ($failed as $p) {
            $this->migration->markPageStatus($sessionId, $p['url'], 'pending');
            try {
                MigratePageJob::dispatch(
                    $sessionId,
                    $p['url'],
                    $p['collection'],
                    $p['blueprint'],
                    $p['locale'],
                    MigrationUrlNormalizer::parentUrl((string) $p['url']),
                );
            } catch (\Throwable $e) {
                Log::error('MigratePageJob inline retry dispatch failed', [
                    'session_id' => $sessionId,
                    'url' => $p['url'],
                    'message' => $e->getMessage(),
                ]);
                $this->migration->markPageStatus(
                    $sessionId,
                    (string) $p['url'],
                    'failed',
                    (string) __('Dispatch failed: :err', ['err' => $e->getMessage()]),
                );
            }
        }

        $this->migration->markCompletedIfDone($sessionId);

        return response()->json(['ok' => true, 'retried' => count($failed)]);
    }

    /**
     * Non-fatal hint returned with discover() so users fix queue setup before mapping clusters.
     */
    private function queueSetupWarning(): ?string
    {
        if (! $this->migrationQueueIsSync()) {
            return null;
        }

        return (string) __(
            'Website migration needs a background queue. Set QUEUE_CONNECTION=redis in your .env and run a worker (e.g. php artisan horizon or php artisan queue:work). The sync driver runs every page inside this request and often hangs or times out.',
        );
    }

    /**
     * Hard guard for start/retry — sync would dispatch MigratePageJob inline and block the HTTP request.
     */
    private function queueSetupError(): ?string
    {
        if (! $this->migrationQueueIsSync()) {
            return null;
        }

        return (string) __(
            'Website migration requires an asynchronous queue. Set QUEUE_CONNECTION=redis in your .env, then start php artisan horizon or php artisan queue:work. The sync driver cannot be used.',
        );
    }

    private function migrationQueueIsSync(): bool
    {
        return config('queue.default') === 'sync';
    }
}
