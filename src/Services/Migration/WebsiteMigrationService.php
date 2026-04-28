<?php

namespace BoldWeb\StatamicAiAssistant\Services\Migration;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Holds the state for a website migration session.
 *
 * State lives in Redis (via the cache facade — a Redis-backed cache store is
 * required) with a 24h TTL that is refreshed on every write. Concurrent
 * updates from queue workers are serialised through a Redis lock per session
 * so the read-modify-write inside update() is atomic. No database is required
 * — completion is tracked by counting page statuses on the session itself.
 * Shape:
 *   [
 *     'id' => uuid,
 *     'site_url' => string,
 *     'created_at' => iso8601,
 *     'updated_at' => iso8601,
 *     'status' => 'planning'|'running'|'completed'|'cancelled',
 *     'total' => int,
 *     'counts' => [pending, running, completed, failed, skipped],
 *     'pages' => [
 *       url => [
 *         'url', 'status', 'collection', 'blueprint', 'locale',
 *         'entry_id'?, 'error'?, 'content_hash'?, 'started_at'?, 'completed_at'?,
 *       ],
 *     ],
 *     'warnings' => array<string>,
 *     'structure_reconcile_done' => bool,
 *   ]
 */
class WebsiteMigrationService
{
    private const CACHE_TTL_HOURS = 24;

    /** Seconds a session lock is held before auto-release (safety net for crashes). */
    private const LOCK_TTL_SECONDS = 10;

    /** Seconds a writer will block waiting for the session lock. */
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * Create a session snapshot from a planning payload.
     *
     * @param  array<int, array{url: string, collection: string, blueprint: string, locale: string}>  $pagePlan
     * @param  array<int, string>  $warnings
     */
    public function createSession(string $siteUrl, array $pagePlan, array $warnings = []): string
    {
        $id = (string) Str::uuid();

        $pages = [];
        foreach ($pagePlan as $p) {
            $url = MigrationUrlNormalizer::normalize((string) ($p['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $pages[$url] = [
                'url' => $url,
                'status' => 'pending',
                'collection' => (string) ($p['collection'] ?? ''),
                'blueprint' => (string) ($p['blueprint'] ?? ''),
                'locale' => (string) ($p['locale'] ?? ''),
                'entry_id' => null,
                'error' => null,
                'content_hash' => null,
                'started_at' => null,
                'completed_at' => null,
            ];
        }

        $session = [
            'id' => $id,
            'site_url' => $siteUrl,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'status' => 'planning',
            'total' => count($pages),
            'counts' => [
                'pending' => count($pages),
                'running' => 0,
                'completed' => 0,
                'failed' => 0,
                'skipped' => 0,
            ],
            'pages' => $pages,
            'warnings' => array_values(array_filter($warnings, 'is_string')),
            'structure_reconcile_done' => false,
        ];

        $this->persist($session);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSession(string $sessionId): ?array
    {
        $session = Cache::get($this->cacheKey($sessionId));

        return is_array($session) ? $session : null;
    }

    public function markRunning(string $sessionId): void
    {
        $this->update($sessionId, function (array $session): array {
            $session['status'] = 'running';

            return $session;
        });
    }

    public function markCompletedIfDone(string $sessionId): void
    {
        $this->update($sessionId, function (array $session): array {
            $pending = $session['counts']['pending'] ?? 0;
            $running = $session['counts']['running'] ?? 0;
            if ($pending === 0 && $running === 0 && ($session['status'] ?? '') === 'running') {
                $session['status'] = 'completed';
            }

            return $session;
        });

        $session = $this->getSession($sessionId);
        if (is_array($session) && ($session['status'] ?? '') === 'completed') {
            app(MigrationStructureReconciler::class)->reconcileIfCompleted($sessionId);
        }
    }

    public function markStructureReconcileDone(string $sessionId): void
    {
        $this->update($sessionId, function (array $session): array {
            $session['structure_reconcile_done'] = true;

            return $session;
        });
    }

    public function cancelSession(string $sessionId): void
    {
        $this->update($sessionId, function (array $session): array {
            $session['status'] = 'cancelled';

            return $session;
        });
    }

    public function markPageStatus(string $sessionId, string $url, string $status, ?string $error = null): void
    {
        $this->update($sessionId, function (array $session) use ($url, $status, $error): array {
            if (! isset($session['pages'][$url])) {
                return $session;
            }
            $oldStatus = $session['pages'][$url]['status'] ?? 'pending';
            $session['pages'][$url]['status'] = $status;
            if ($error !== null) {
                $session['pages'][$url]['error'] = $error;
            }
            if (in_array($status, ['fetching', 'generating'], true) && empty($session['pages'][$url]['started_at'])) {
                $session['pages'][$url]['started_at'] = now()->toIso8601String();
            }
            if (in_array($status, ['completed', 'failed', 'skipped'], true)) {
                $session['pages'][$url]['completed_at'] = now()->toIso8601String();
            }
            $session['counts'] = $this->recountCounts($session);
            unset($oldStatus);

            return $session;
        });
    }

    public function markPageSuccess(string $sessionId, string $url, string $entryId, string $contentHash): void
    {
        $this->update($sessionId, function (array $session) use ($url, $entryId, $contentHash): array {
            if (! isset($session['pages'][$url])) {
                return $session;
            }
            $session['pages'][$url]['status'] = 'completed';
            $session['pages'][$url]['entry_id'] = $entryId;
            $session['pages'][$url]['content_hash'] = $contentHash;
            $session['pages'][$url]['error'] = null;
            $session['pages'][$url]['completed_at'] = now()->toIso8601String();
            $session['counts'] = $this->recountCounts($session);

            return $session;
        });
    }

    /**
     * Returns true if a previous successful run recorded the same content hash
     * for this URL, so the job can skip regeneration. (A content hash is only
     * ever written via markPageSuccess, so its mere presence implies success.)
     */
    public function isUnchanged(string $sessionId, string $url, string $contentHash): bool
    {
        $session = $this->getSession($sessionId);
        if (! is_array($session) || ! isset($session['pages'][$url])) {
            return false;
        }
        $stored = $session['pages'][$url]['content_hash'] ?? null;

        return is_string($stored) && $stored === $contentHash;
    }

    /**
     * Build the synthetic prompt sent to the LLM for a migrated page.
     */
    public function buildMigrationPrompt(string $url, string $fetchedContent): string
    {
        return implode("\n", [
            __('Create an entry that mirrors the source page below.'),
            __('Source URL: :url', ['url' => $url]),
            __('Stay faithful to the source content. Preserve headings, lists, and emphasis. Do not invent facts that are not present in the source.'),
            __('Use ONLY the main page content. Do not include site-wide chrome that appears on every page: top navigation menus, mega menus, language switchers, breadcrumbs, headers, footers, sidebars, cookie banners, newsletter signups, "share on social" links, "related articles", "you may also like", and similar boilerplate. If a fetched block is clearly a list of links to other pages of the site rather than content of this page, ignore it.'),
            __('Do not turn navigation links or footer links into page sections, CTAs, or content blocks.'),
            '',
            '---',
            '',
            $fetchedContent,
        ]);
    }

    /**
     * Run a read-modify-write under a Redis lock so concurrent queue workers
     * can't clobber each other's updates to the same session.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    private function update(string $sessionId, callable $mutator): void
    {
        $lock = Cache::lock($this->lockKey($sessionId), self::LOCK_TTL_SECONDS);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS, function () use ($sessionId, $mutator): void {
                $session = $this->getSession($sessionId);
                if (! is_array($session)) {
                    Log::notice('Migration session not found', ['session_id' => $sessionId]);

                    return;
                }

                $session = $mutator($session);
                $session['updated_at'] = now()->toIso8601String();

                $this->persist($session);
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::error('Migration session lock timeout', [
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function persist(array $session): void
    {
        $id = (string) ($session['id'] ?? '');
        if ($id === '') {
            return;
        }
        Cache::put($this->cacheKey($id), $session, now()->addHours(self::CACHE_TTL_HOURS));
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{pending: int, running: int, completed: int, failed: int, skipped: int}
     */
    private function recountCounts(array $session): array
    {
        $counts = ['pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($session['pages'] ?? [] as $page) {
            $status = $page['status'] ?? 'pending';
            $bucket = match ($status) {
                'pending' => 'pending',
                'fetching', 'generating' => 'running',
                'completed' => 'completed',
                'failed' => 'failed',
                'skipped', 'skipped_unchanged' => 'skipped',
                default => 'pending',
            };
            $counts[$bucket]++;
        }

        return $counts;
    }

    private function cacheKey(string $sessionId): string
    {
        return "ai_migration:session:{$sessionId}";
    }

    private function lockKey(string $sessionId): string
    {
        return "ai_migration:session:{$sessionId}:lock";
    }
}
