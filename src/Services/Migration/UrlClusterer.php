<?php

namespace BoldWeb\StatamicAiAssistant\Services\Migration;

/**
 * Groups discovered URLs by path pattern so the CP can ask the user to map each
 * cluster (e.g. /blog/*, /products/*) to a specific collection + blueprint.
 *
 * Rules:
 *   - Empty path or "/"           → pattern "/"
 *   - One-segment path "/foo"     → pattern "/foo"
 *   - Multi-segment "/foo/bar..." → pattern "/foo/*"
 *
 * The output is a stable, count-desc sorted list.
 */
class UrlClusterer
{
    private const SAMPLE_SIZE = 3;

    /**
     * @param  array<int, array{url: string, lastmod?: ?string, source?: string}>  $urls
     * @return array<int, array{pattern: string, count: int, sample_urls: array<int, string>, urls: array<int, string>}>
     */
    public function cluster(array $urls): array
    {
        $clusters = [];

        foreach ($urls as $entry) {
            $url = (string) ($entry['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $pattern = $this->patternFor($url);

            if (! isset($clusters[$pattern])) {
                $clusters[$pattern] = [
                    'pattern' => $pattern,
                    'count' => 0,
                    'sample_urls' => [],
                    'urls' => [],
                ];
            }

            $clusters[$pattern]['count']++;
            $clusters[$pattern]['urls'][] = $url;

            if (count($clusters[$pattern]['sample_urls']) < self::SAMPLE_SIZE) {
                $clusters[$pattern]['sample_urls'][] = $url;
            }
        }

        $result = array_values($clusters);

        usort($result, function ($a, $b) {
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }

            return strcmp($a['pattern'], $b['pattern']);
        });

        return $result;
    }

    private function patternFor(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');

        if ($path === '') {
            return '/';
        }

        $segments = explode('/', $path);

        if (count($segments) === 1) {
            return '/'.$segments[0];
        }

        return '/'.$segments[0].'/*';
    }
}
