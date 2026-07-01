<?php

namespace BoldWeb\StatamicAiAssistant\Services\Migration;

use BoldWeb\StatamicAiAssistant\Services\AssetImageDownloader;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\AssetContainer;

/**
 * Pulls every <img> from a fetched page into a Statamic asset container under
 * a caller-supplied folder, so the resulting entry references real local
 * images instead of random container assets. Used by the website migration
 * flow (folder = bold-agent-migration/{sessionId}).
 *
 * The per-image download + upload is delegated to {@see AssetImageDownloader};
 * this class only handles markdown URL discovery, relative-URL resolution and
 * rewriting the markdown to point at the local public URLs.
 */
class MigrationAssetDownloader
{
    public function __construct(private AssetImageDownloader $downloader) {}

    /**
     * @return array{
     *   markdown: string,
     *   preferred: PreferredAssetPaths,
     * }
     */
    public function downloadFromMarkdown(string $folder, string $sourceUrl, string $markdown): array
    {
        $empty = ['markdown' => $markdown, 'preferred' => new PreferredAssetPaths];

        $containerHandle = $this->resolveContainerHandle();
        if (! $containerHandle) {
            return $empty;
        }

        $container = AssetContainer::find($containerHandle);
        if (! $container) {
            Log::notice('Migration asset container not found', ['container' => $containerHandle]);

            return $empty;
        }

        $folder = trim($folder, '/');
        if ($folder === '') {
            $folder = 'bold-agent-fetched';
        }

        $urls = $this->extractImageUrls($markdown);
        if ($urls === []) {
            return $empty;
        }

        $maxImages = max(0, (int) config('statamic-ai-assistant.migration.asset_max_per_page', 20));
        $maxBytes = max(1024, (int) config('statamic-ai-assistant.migration.asset_max_bytes', 10 * 1024 * 1024));
        $timeout = max(1, (int) config('statamic-ai-assistant.migration.asset_timeout', 15));

        if ($maxImages === 0) {
            return $empty;
        }

        $entries = [];
        $replacements = [];
        $seen = [];

        foreach ($urls as $url) {
            if (count($entries) >= $maxImages) {
                break;
            }
            $absolute = $this->resolveUrl($url, $sourceUrl);
            if ($absolute === null || isset($seen[$absolute])) {
                continue;
            }
            $seen[$absolute] = true;

            $downloaded = $this->downloader->save($container, $folder, $absolute, $timeout, $maxBytes);
            if ($downloaded === null) {
                continue;
            }

            $entries[] = ['container' => $containerHandle, 'path' => $downloaded['path']];

            // Rewrite both the form found in the markdown and the resolved
            // absolute form so all variants point at the local public URL.
            if ($downloaded['public_url'] !== null) {
                $replacements[$url] = $downloaded['public_url'];
                $replacements[$absolute] = $downloaded['public_url'];
            }
        }

        $rewritten = $markdown;
        foreach ($replacements as $from => $to) {
            $rewritten = str_replace($from, $to, $rewritten);
        }

        return [
            'markdown' => $rewritten,
            'preferred' => new PreferredAssetPaths($entries),
        ];
    }

    private function resolveContainerHandle(): ?string
    {
        $configured = config('statamic-ai-assistant.migration.asset_container');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $first = AssetContainer::all()->first();

        return $first?->handle();
    }

    /**
     * @return array<int, string>
     */
    private function extractImageUrls(string $markdown): array
    {
        $urls = [];
        if (preg_match_all('~!\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)~', $markdown, $m)) {
            foreach ($m[1] as $url) {
                $urls[] = $url;
            }
        }
        if (preg_match_all('~<img[^>]+src=["\']([^"\']+)["\']~i', $markdown, $m)) {
            foreach ($m[1] as $url) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function resolveUrl(string $candidate, string $base): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '' || str_starts_with($candidate, 'data:')) {
            return null;
        }
        if (preg_match('~^https?://~i', $candidate)) {
            return $candidate;
        }
        $bp = parse_url($base);
        if (! is_array($bp) || empty($bp['scheme']) || empty($bp['host'])) {
            return null;
        }
        $scheme = (string) $bp['scheme'];
        $host = (string) $bp['host'];
        $port = isset($bp['port']) ? ':'.$bp['port'] : '';

        if (str_starts_with($candidate, '//')) {
            return $scheme.':'.$candidate;
        }
        if (str_starts_with($candidate, '/')) {
            return $scheme.'://'.$host.$port.$candidate;
        }

        $basePath = (string) ($bp['path'] ?? '/');
        $basePath = (string) preg_replace('~/[^/]*$~', '/', $basePath);

        return $scheme.'://'.$host.$port.$basePath.$candidate;
    }
}
