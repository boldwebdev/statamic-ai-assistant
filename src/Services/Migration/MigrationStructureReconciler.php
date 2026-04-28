<?php

namespace BoldWeb\StatamicAiAssistant\Services\Migration;

/**
 * After a migration session completes, re-apply parent/child tree placement for every
 * successfully migrated page so hierarchy is correct even if jobs raced or retries ran.
 */
class MigrationStructureReconciler
{
    public function __construct(
        private WebsiteMigrationService $migration,
    ) {}

    public function reconcileIfCompleted(string $sessionId): void
    {
        $session = $this->migration->getSession($sessionId);
        if (! is_array($session) || ($session['status'] ?? '') !== 'completed') {
            return;
        }

        if ($session['structure_reconcile_done'] ?? false) {
            return;
        }

        $pages = $session['pages'] ?? [];
        if (! is_array($pages)) {
            $pages = [];
        }

        try {
            if ($pages !== []) {
                $ordered = array_values($pages);
                usort($ordered, function (array $a, array $b): int {
                    $ua = MigrationUrlNormalizer::normalize((string) ($a['url'] ?? ''));
                    $ub = MigrationUrlNormalizer::normalize((string) ($b['url'] ?? ''));
                    $da = MigrationUrlNormalizer::pathDepth($ua);
                    $db = MigrationUrlNormalizer::pathDepth($ub);
                    if ($da !== $db) {
                        return $da <=> $db;
                    }

                    return strcmp($ua, $ub);
                });

                foreach ($ordered as $page) {
                    if (($page['status'] ?? '') !== 'completed') {
                        continue;
                    }

                    $childId = $page['entry_id'] ?? null;
                    if (! is_string($childId) || $childId === '') {
                        continue;
                    }

                    $url = MigrationUrlNormalizer::normalize((string) ($page['url'] ?? ''));
                    if ($url === '') {
                        continue;
                    }

                    $parentUrl = MigrationUrlNormalizer::parentUrl($url);
                    if ($parentUrl === null) {
                        continue;
                    }

                    $parentPage = $pages[$parentUrl] ?? null;
                    if (! is_array($parentPage) || ($parentPage['status'] ?? '') !== 'completed') {
                        continue;
                    }

                    $parentId = $parentPage['entry_id'] ?? null;
                    if (! is_string($parentId) || $parentId === '') {
                        continue;
                    }

                    $collection = (string) ($page['collection'] ?? '');
                    $locale = (string) ($page['locale'] ?? '');
                    if ($collection === '' || $locale === '') {
                        continue;
                    }

                    if ($collection !== (string) ($parentPage['collection'] ?? '') || $locale !== (string) ($parentPage['locale'] ?? '')) {
                        continue;
                    }

                    try {
                        MigrationStructurePlacement::ensure($collection, $locale, $parentId, $childId);
                    } catch (\Throwable) {
                        // Same policy as MigratePageJob::placeInStructure — never fail reconcile pass loudly.
                    }
                }
            }
        } finally {
            $this->migration->markStructureReconcileDone($sessionId);
        }
    }
}
