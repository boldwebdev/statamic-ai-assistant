<?php

namespace BoldWeb\StatamicAiAssistant\Services\Migration;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Facades\AssetContainer;

/**
 * Pulls every <img> from a fetched page into a Statamic asset container under
 * a caller-supplied folder, so the resulting entry references real local
 * images instead of random container assets. Used by the website migration
 * flow (folder = bold-agent-migration/{sessionId}).
 *
 * Filename format is deterministic ({slug}-{8 chars of url md5}.{ext}) so a
 * retried job sees the existing file on disk and reuses it instead of
 * re-uploading.
 */
class MigrationAssetDownloader
{
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

            $downloaded = $this->downloadOne($container, $folder, $absolute, $timeout, $maxBytes);
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

    private function slugFilename(string $url, string $contentType): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $name = pathinfo($path, PATHINFO_FILENAME) ?: 'image';
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '' || strlen($ext) > 5) {
            $ext = match (true) {
                str_contains($contentType, 'jpeg') => 'jpg',
                str_contains($contentType, 'png') => 'png',
                str_contains($contentType, 'webp') => 'webp',
                str_contains($contentType, 'gif') => 'gif',
                str_contains($contentType, 'svg') => 'svg',
                default => 'bin',
            };
        }
        $base = Str::slug(Str::limit($name, 80, ''));
        if ($base === '') {
            $base = 'image';
        }
        $hash = substr(md5($url), 0, 8);

        return $base.'-'.$hash.'.'.$ext;
    }

    /**
     * @return array{path: string, public_url: ?string}|null
     */
    private function downloadOne($container, string $folder, string $url, int $timeout, int $maxBytes): ?array
    {
        try {
            // Provisional path so we can short-circuit on retry without a HEAD.
            // Filename is fully URL-derived, so this is stable across attempts.
            $filename = $this->slugFilename($url, 'application/octet-stream');
            $tentativePath = $folder.'/'.$filename;
            if ($container->disk()->exists($tentativePath)) {
                $existing = $container->makeAsset($tentativePath);

                return ['path' => $tentativePath, 'public_url' => $this->safeUrl($existing)];
            }

            $response = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'StatamicAiAssistant-Migration/1.0'])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $contentType = (string) ($response->header('Content-Type') ?: '');
            if (! str_starts_with(strtolower($contentType), 'image/')) {
                return null;
            }

            $body = (string) $response->body();
            if ($body === '' || strlen($body) > $maxBytes) {
                return null;
            }

            // Re-derive filename now that we know the real content type, in case
            // the URL had no extension.
            $filename = $this->slugFilename($url, $contentType);
            $path = $folder.'/'.$filename;

            // Race window: another worker may have written this exact path
            // between the early check and here. upload() overwrites idempotently
            // (same URL → same bytes), so the race is benign.
            $tmp = tempnam(sys_get_temp_dir(), 'bold-mig-');
            if ($tmp === false) {
                return null;
            }
            file_put_contents($tmp, $body);

            $upload = new UploadedFile($tmp, basename($filename), $contentType, null, true);
            $asset = $container->makeAsset($path);
            $asset->upload($upload);

            @unlink($tmp);

            return ['path' => $path, 'public_url' => $this->safeUrl($asset)];
        } catch (\Throwable $e) {
            Log::notice('Migration asset download failed', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function safeUrl($asset): ?string
    {
        try {
            $url = $asset->url();

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
