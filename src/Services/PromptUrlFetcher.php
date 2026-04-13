<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Detects http(s) URLs in a user prompt and pulls readable text via Jina Reader
 * (https://r.jina.ai/{url}) so the LLM can use page content without native browsing.
 */
class PromptUrlFetcher
{
    /** @var array<string, array{ok: bool, body: string, error: ?string}> */
    private static array $fetchCache = [];

    /**
     * @return array{appendix: string, warnings: array<int, string>}
     */
    public function buildAugmentation(string $prompt): array
    {
        $warnings = [];
        $appendix = '';

        if (! (bool) config('statamic-ai-assistant.prompt_url_fetch.enabled', true)) {
            return ['appendix' => '', 'warnings' => []];
        }

        $urls = $this->extractPublicHttpUrls($prompt);

        if ($urls === []) {
            return ['appendix' => '', 'warnings' => []];
        }

        $maxUrls = max(1, (int) config('statamic-ai-assistant.prompt_url_fetch.max_urls', 5));
        $maxPer = max(1000, (int) config('statamic-ai-assistant.prompt_url_fetch.max_chars_per_url', 12000));
        $maxTotal = max($maxPer, (int) config('statamic-ai-assistant.prompt_url_fetch.max_total_chars', 40000));
        $timeout = max(5, (int) config('statamic-ai-assistant.prompt_url_fetch.timeout', 25));
        $base = rtrim((string) config('statamic-ai-assistant.prompt_url_fetch.reader_base', 'https://r.jina.ai'), '/');
        $apiKey = config('statamic-ai-assistant.prompt_url_fetch.api_key');

        $urls = array_slice($urls, 0, $maxUrls);

        $blocks = [];
        $totalUsed = 0;

        foreach ($urls as $url) {
            if ($totalUsed >= $maxTotal) {
                $warnings[] = __('URL context was truncated: reached the maximum amount of fetched text.');

                break;
            }

            $result = $this->fetchViaJina($url, $base, $timeout, $apiKey ? (string) $apiKey : null);

            if (! $result['ok']) {
                $warnings[] = __('Could not fetch :url: :reason', [
                    'url' => $url,
                    'reason' => $result['error'] ?? __('unavailable or blocked'),
                ]);

                continue;
            }

            $chunk = Str::limit($result['body'], $maxPer);
            $remaining = $maxTotal - $totalUsed;

            if (strlen($chunk) > $remaining) {
                $chunk = Str::limit($chunk, $remaining);
            }

            $totalUsed += strlen($chunk);
            $blocks[] = '### '.$url."\n\n".$chunk;
        }

        if ($blocks !== []) {
            $appendix = "\n\n--- ".__('Content fetched from URLs in your prompt (reader service)')." ---\n\n"
                .implode("\n\n---\n\n", $blocks);
        }

        return ['appendix' => $appendix, 'warnings' => $warnings];
    }

    /**
     * @return array<int, string>
     */
    private function extractPublicHttpUrls(string $text): array
    {
        if (! preg_match_all('~\bhttps?://[^\s<>\]\}\)\"\'`]+~iu', $text, $m)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($m[0] as $raw) {
            $url = $this->normalizeUrlCandidate($raw);

            if ($url === null || isset($seen[$url])) {
                continue;
            }

            if (! $this->isUrlAllowed($url)) {
                continue;
            }

            $seen[$url] = true;
            $out[] = $url;
        }

        return $out;
    }

    private function normalizeUrlCandidate(string $raw): ?string
    {
        $url = rtrim($raw, '.,;:!?)]}\'"');

        if (strlen($url) > 2048) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    private function isUrlAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $hostLower = strtolower($host);

        if ($hostLower === 'localhost' || str_ends_with($hostLower, '.localhost')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        // Figma URLs are handled by FigmaContentFetcher with OAuth — don't
        // try to scrape them through the public reader.
        if ($hostLower === 'figma.com' || str_ends_with($hostLower, '.figma.com')) {
            return false;
        }

        return true;
    }

    /**
     * @return array{ok: bool, body: string, error: ?string}
     */
    private function fetchViaJina(string $targetUrl, string $readerBase, int $timeout, ?string $apiKey): array
    {
        if (isset(self::$fetchCache[$targetUrl])) {
            return self::$fetchCache[$targetUrl];
        }

        // Jina Reader expects: https://r.jina.ai/https://example.com/path
        $readerUrl = $readerBase.'/'.$targetUrl;

        try {
            $request = Http::timeout($timeout)
                ->withHeaders([
                    'User-Agent' => 'StatamicAiAssistant/1.0 (+https://statamic.com)',
                    'Accept' => 'text/plain,text/markdown,*/*;q=0.8',
                ]);

            if ($apiKey !== null && $apiKey !== '') {
                $request = $request->withToken($apiKey);
            }

            $response = $request->get($readerUrl);
        } catch (\Throwable $e) {
            Log::notice('Jina Reader fetch failed', ['url' => $targetUrl, 'message' => $e->getMessage()]);
            $out = ['ok' => false, 'body' => '', 'error' => $e->getMessage()];
            self::$fetchCache[$targetUrl] = $out;

            return $out;
        }

        if (! $response->successful()) {
            $hint = Str::limit(trim((string) ($response->json('message') ?? $response->body())), 200);
            $out = [
                'ok' => false,
                'body' => '',
                'error' => $hint !== '' ? $hint : (string) __('HTTP :code', ['code' => $response->status()]),
            ];
            self::$fetchCache[$targetUrl] = $out;

            return $out;
        }

        $body = trim((string) $response->body());

        if ($body === '') {
            $out = ['ok' => false, 'body' => '', 'error' => (string) __('Empty response')];
            self::$fetchCache[$targetUrl] = $out;

            return $out;
        }

        $out = ['ok' => true, 'body' => $body, 'error' => null];
        self::$fetchCache[$targetUrl] = $out;

        return $out;
    }
}
