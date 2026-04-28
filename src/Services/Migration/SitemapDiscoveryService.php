<?php

namespace BoldWeb\StatamicAiAssistant\Services\Migration;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Discovers crawlable URLs for a target website without external APIs.
 *
 * Strategy:
 *   1. robots.txt   → collect Sitemap: directives + Disallow rules
 *   2. sitemap.xml  → recurse into <sitemapindex>, collect <url><loc> + <lastmod>
 *   3. Crawl fallback (BFS, same-host, depth-limited) when no sitemap is found
 *
 * Pure Guzzle (via Http facade) + SimpleXML + DOMDocument. No third-party deps.
 */
class SitemapDiscoveryService
{
    private const USER_AGENT = 'StatamicAiAssistant/1.0 (+https://statamic.com)';

    /**
     * @param  array{
     *     max_pages?: int,
     *     follow_depth?: int,
     *     include?: array<int, string>,
     *     exclude?: array<int, string>,
     *     respect_robots?: bool,
     *     timeout?: int,
     * }  $options
     * @return array{
     *     urls: array<int, array{url: string, lastmod: ?string, source: string}>,
     *     warnings: array<int, string>,
     *     robots_excluded: array<int, string>,
     *     source: string,
     * }
     */
    public function discover(string $siteUrl, array $options = []): array
    {
        $maxPages = max(1, (int) ($options['max_pages'] ?? 500));
        $followDepth = max(0, (int) ($options['follow_depth'] ?? 3));
        $include = $options['include'] ?? [];
        $exclude = $options['exclude'] ?? [];
        $respectRobots = (bool) ($options['respect_robots'] ?? true);
        $timeout = max(5, (int) ($options['timeout'] ?? 20));
        $budget = max(10, (int) ($options['budget_seconds'] ?? 25));
        $deadline = microtime(true) + $budget;

        $warnings = [];
        $robotsExcluded = [];

        $rootUrl = $this->normalizeRootUrl($siteUrl);

        if ($rootUrl === null) {
            return [
                'urls' => [],
                'warnings' => [(string) __('Invalid website URL.')],
                'robots_excluded' => [],
                'source' => 'none',
            ];
        }

        $host = (string) parse_url($rootUrl, PHP_URL_HOST);

        $robots = $this->fetchRobots($rootUrl, $timeout, $warnings);
        $disallows = $respectRobots ? $robots['disallow'] : [];

        $sitemapUrls = $robots['sitemaps'];

        // Always try the canonical location as a fallback hint.
        $canonicalSitemap = $rootUrl.'/sitemap.xml';
        if (! in_array($canonicalSitemap, $sitemapUrls, true)) {
            $sitemapUrls[] = $canonicalSitemap;
        }

        $collected = [];
        $source = 'sitemap';

        foreach ($sitemapUrls as $sitemapUrl) {
            if (microtime(true) >= $deadline) {
                $warnings[] = (string) __('Reached the :s-second time limit while reading the sitemap. Returning :n URLs found so far. Raise STATAMIC_AI_ASSISTANT_MIGRATION_DISCOVERY_BUDGET to allow more time.', [
                    's' => $budget,
                    'n' => count($collected),
                ]);
                break;
            }
            $this->collectFromSitemap($sitemapUrl, $timeout, $collected, $warnings, 0, $deadline);
            if (count($collected) >= $maxPages * 2) {
                // Grab a comfortable overage; final filter + cap happens below.
                break;
            }
        }

        if ($collected === [] && microtime(true) < $deadline) {
            $source = 'crawl';
            $warnings[] = (string) __('No usable sitemap.xml found at :url. Falling back to following links page-by-page, which is slower and less complete than a sitemap.', [
                'url' => $rootUrl,
            ]);
            $this->crawl($rootUrl, $host, $followDepth, $maxPages, $timeout, $disallows, $collected, $warnings, $deadline, $budget);
        }

        // Same-host filter, regex filter, robots filter, dedupe, cap.
        $seen = [];
        $final = [];

        foreach ($collected as $entry) {
            $url = $this->normalizeUrl($entry['url']);
            if ($url === null) {
                continue;
            }

            $entryHost = (string) parse_url($url, PHP_URL_HOST);
            if ($entryHost !== $host) {
                continue;
            }

            if ($this->looksLikeNonHtmlAsset($url)) {
                continue;
            }

            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            if ($include !== [] && ! $this->matchesAny($url, $include)) {
                continue;
            }
            if ($exclude !== [] && $this->matchesAny($url, $exclude)) {
                continue;
            }

            if ($respectRobots && $this->isBlockedByRobots($url, $disallows)) {
                $robotsExcluded[] = $url;
                continue;
            }

            $final[] = [
                'url' => $url,
                'lastmod' => $entry['lastmod'] ?? null,
                'source' => $entry['source'] ?? $source,
            ];

            if (count($final) >= $maxPages) {
                break;
            }
        }

        if ($final === []) {
            $warnings[] = (string) __('No pages were discovered for this website.');
        }

        return [
            'urls' => $final,
            'warnings' => $warnings,
            'robots_excluded' => $robotsExcluded,
            'source' => $source,
        ];
    }

    /**
     * @param  array<int, string>  $warnings
     * @return array{sitemaps: array<int, string>, disallow: array<int, string>}
     */
    private function fetchRobots(string $rootUrl, int $timeout, array &$warnings): array
    {
        $url = $rootUrl.'/robots.txt';

        try {
            $response = $this->http($timeout)->get($url);
        } catch (\Throwable $e) {
            Log::notice('robots.txt fetch failed', ['url' => $url, 'message' => $e->getMessage()]);

            return ['sitemaps' => [], 'disallow' => []];
        }

        if (! $response->successful()) {
            return ['sitemaps' => [], 'disallow' => []];
        }

        return $this->parseRobots((string) $response->body(), $rootUrl);
    }

    /**
     * @return array{sitemaps: array<int, string>, disallow: array<int, string>}
     */
    private function parseRobots(string $body, string $rootUrl): array
    {
        $sitemaps = [];
        $disallow = [];
        $inStarGroup = false;
        $currentAgents = [];

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Strip inline comments
            if (($hash = strpos($line, '#')) !== false) {
                $line = rtrim(substr($line, 0, $hash));
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $directive = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if ($directive === 'sitemap') {
                if ($value !== '') {
                    $sitemaps[] = $value;
                }
                continue;
            }

            if ($directive === 'user-agent') {
                // New record starts — reset tracker on first UA after a disallow block.
                $currentAgents[] = strtolower($value);
                $inStarGroup = in_array('*', $currentAgents, true);
                continue;
            }

            if ($directive === 'disallow' && $inStarGroup) {
                // Empty disallow = explicit allow-all; skip.
                if ($value !== '') {
                    $disallow[] = $value;
                }
                continue;
            }

            // Any directive other than user-agent after a UA group implicitly "closes"
            // the parsing window once we hit the next user-agent line, so we reset the
            // tracked agent list only at that transition.
            if ($directive === 'allow' || $directive === 'crawl-delay') {
                // No-op for v1; we intentionally keep the current group open.
                continue;
            }

            // On any other directive we do NOT reset $currentAgents; reset only on a
            // fresh "User-agent:" line.
        }

        return [
            'sitemaps' => array_values(array_unique($sitemaps)),
            'disallow' => array_values(array_unique($disallow)),
        ];
    }

    /**
     * Recursively fetches a sitemap or sitemap index and appends discovered URLs.
     *
     * @param  array<int, array{url: string, lastmod: ?string, source: string}>  $collected
     * @param  array<int, string>  $warnings
     */
    private function collectFromSitemap(
        string $sitemapUrl,
        int $timeout,
        array &$collected,
        array &$warnings,
        int $depth,
        float $deadline = PHP_FLOAT_MAX,
    ): void {
        if (microtime(true) >= $deadline) {
            return;
        }
        if ($depth > 5) {
            $warnings[] = (string) __('Sitemap recursion depth exceeded at :url', ['url' => $sitemapUrl]);

            return;
        }

        try {
            $response = $this->http($timeout)->get($sitemapUrl);
        } catch (\Throwable $e) {
            Log::notice('sitemap fetch failed', ['url' => $sitemapUrl, 'message' => $e->getMessage()]);

            return;
        }

        if (! $response->successful()) {
            return;
        }

        $body = (string) $response->body();

        if ($body === '' || str_starts_with($body, "\x1f\x8b")) {
            // Gzipped sitemaps are not supported in v1.
            if (str_starts_with($body, "\x1f\x8b")) {
                $warnings[] = (string) __('Gzipped sitemap skipped: :url', ['url' => $sitemapUrl]);
            }

            return;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();

        if ($xml === false) {
            $warnings[] = (string) __('Could not parse sitemap: :url', ['url' => $sitemapUrl]);

            return;
        }

        $name = strtolower($xml->getName());

        if ($name === 'sitemapindex') {
            foreach ($xml->sitemap as $child) {
                if (microtime(true) >= $deadline) {
                    return;
                }
                $childLoc = trim((string) $child->loc);
                if ($childLoc !== '') {
                    $this->collectFromSitemap($childLoc, $timeout, $collected, $warnings, $depth + 1, $deadline);
                }
            }

            return;
        }

        if ($name === 'urlset') {
            foreach ($xml->url as $u) {
                $loc = trim((string) $u->loc);
                if ($loc === '') {
                    continue;
                }
                $lastmod = trim((string) ($u->lastmod ?? ''));
                $collected[] = [
                    'url' => $loc,
                    'lastmod' => $lastmod !== '' ? $lastmod : null,
                    'source' => 'sitemap',
                ];
            }

            return;
        }

        // Unknown root element — silently ignore.
        unset($xmlErrors);
    }

    /**
     * BFS crawl when no sitemap is available.
     *
     * @param  array<int, string>  $disallows
     * @param  array<int, array{url: string, lastmod: ?string, source: string}>  $collected
     * @param  array<int, string>  $warnings
     */
    private function crawl(
        string $rootUrl,
        string $host,
        int $maxDepth,
        int $maxPages,
        int $timeout,
        array $disallows,
        array &$collected,
        array &$warnings,
        float $deadline = PHP_FLOAT_MAX,
        int $budgetSeconds = 0,
    ): void {
        $visited = [];
        $queue = [[$rootUrl, 0]];

        while ($queue !== [] && count($collected) < $maxPages) {
            if (microtime(true) >= $deadline) {
                $warnings[] = (string) __('Reached the :s-second time limit while following links. Stopped after discovering :n pages. To go further, add a sitemap.xml to the source site or raise STATAMIC_AI_ASSISTANT_MIGRATION_DISCOVERY_BUDGET.', [
                    's' => $budgetSeconds > 0 ? $budgetSeconds : (int) ceil($deadline - microtime(true) + 0.001),
                    'n' => count($collected),
                ]);
                break;
            }
            [$url, $depth] = array_shift($queue);

            if (isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;

            if ($this->isBlockedByRobots($url, $disallows)) {
                continue;
            }

            try {
                $response = $this->http($timeout)->get($url);
            } catch (\Throwable $e) {
                Log::notice('crawl fetch failed', ['url' => $url, 'message' => $e->getMessage()]);
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            if ($contentType !== '' && ! str_contains($contentType, 'html')) {
                continue;
            }

            $collected[] = ['url' => $url, 'lastmod' => null, 'source' => 'crawl'];

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($this->extractLinks((string) $response->body(), $url) as $link) {
                if (isset($visited[$link])) {
                    continue;
                }
                $linkHost = (string) parse_url($link, PHP_URL_HOST);
                if ($linkHost !== $host) {
                    continue;
                }
                $queue[] = [$link, $depth + 1];
            }
        }

        if (count($collected) === 0) {
            $warnings[] = (string) __('Could not reach any pages by following links from :url. The server may be unreachable, blocking crawlers, or the site is JavaScript-rendered (which this fallback cannot read).', [
                'url' => $rootUrl,
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractLinks(string $html, string $baseUrl): array
    {
        $links = [];

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        /** @var \DOMNodeList<\DOMElement> $anchors */
        $anchors = $doc->getElementsByTagName('a');
        foreach ($anchors as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            $resolved = $this->resolveUrl($href, $baseUrl);
            if ($resolved !== null) {
                $links[] = $resolved;
            }
        }

        return array_values(array_unique($links));
    }

    /**
     * @param  array<int, string>  $disallows
     */
    private function isBlockedByRobots(string $url, array $disallows): bool
    {
        if ($disallows === []) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $pathWithQuery = $query !== '' ? $path.'?'.$query : $path;

        foreach ($disallows as $rule) {
            if ($rule === '/') {
                return true;
            }
            if (str_contains($rule, '*') || str_ends_with($rule, '$')) {
                $regex = '#^'.str_replace('\*', '.*', preg_quote(rtrim($rule, '$'), '#')).(str_ends_with($rule, '$') ? '$' : '').'#';
                if (@preg_match($regex, $pathWithQuery) === 1) {
                    return true;
                }
                continue;
            }
            if (str_starts_with($pathWithQuery, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function matchesAny(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $delimited = $this->ensureRegexDelimiters($pattern);
            if ($delimited !== null && @preg_match($delimited, $url) === 1) {
                return true;
            }
        }

        return false;
    }

    private function ensureRegexDelimiters(string $pattern): ?string
    {
        if ($pattern === '') {
            return null;
        }
        // If the first char is a common delimiter and reappears somewhere after
        // position 0, trust the caller — it's already a complete regex literal.
        $first = $pattern[0];
        if (in_array($first, ['/', '#', '~', '!', '@', '%'], true)
            && strrpos($pattern, $first) > 0) {
            return $pattern;
        }

        return '#'.str_replace('#', '\\#', $pattern).'#';
    }

    private function normalizeRootUrl(string $siteUrl): ?string
    {
        $siteUrl = trim($siteUrl);
        if ($siteUrl === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $siteUrl)) {
            $siteUrl = 'https://'.$siteUrl;
        }

        $parts = parse_url($siteUrl);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/';
        // Collapse multiple slashes, strip trailing slash except for root.
        $path = preg_replace('#/{2,}#', '/', $path);
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        // Drop fragment.
        return $scheme.'://'.$host.$port.$path.$query;
    }

    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        // Already absolute.
        if (preg_match('#^https?://#i', $href)) {
            return $this->normalizeUrl($href);
        }

        $base = parse_url($baseUrl);
        if (! is_array($base) || empty($base['host'])) {
            return null;
        }
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'];
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        if (str_starts_with($href, '//')) {
            return $this->normalizeUrl($scheme.':'.$href);
        }
        if (str_starts_with($href, '/')) {
            return $this->normalizeUrl($scheme.'://'.$host.$port.$href);
        }

        // Relative path — resolve against base path's directory.
        $basePath = $base['path'] ?? '/';
        $basePath = substr($basePath, 0, strrpos($basePath, '/') ?: 0).'/';
        $combined = $basePath.$href;
        // Resolve ./ and ../ segments.
        $segments = [];
        foreach (explode('/', $combined) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $seg;
        }

        return $this->normalizeUrl($scheme.'://'.$host.$port.'/'.implode('/', $segments));
    }

    /**
     * Quick extension-based heuristic: skip URLs that obviously point at
     * non-HTML files. The crawler also does a Content-Type check after fetch,
     * but that's too late if the host is unreachable — we still pay connect
     * time. Filtering here keeps the pipeline cheap when the sitemap
     * includes PDFs / images / archives.
     */
    private function looksLikeNonHtmlAsset(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if ($path === '') {
            return false;
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return in_array($ext, [
            'pdf', 'zip', 'gz', 'tar', 'rar', '7z',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'tiff',
            'mp3', 'mp4', 'm4a', 'wav', 'mov', 'avi', 'mkv', 'webm',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'css', 'js', 'map', 'woff', 'woff2', 'ttf', 'eot', 'otf',
            'json', 'xml', 'rss', 'atom', 'txt', 'csv',
        ], true);
    }

    private function http(int $timeout): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout($timeout)
            ->withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,*/*;q=0.5',
            ]);
    }
}
