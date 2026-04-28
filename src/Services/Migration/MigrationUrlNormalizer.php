<?php

namespace BoldWeb\StatamicAiAssistant\Services\Migration;

/**
 * Canonical URLs for migration session keys so parent/child resolution stays stable
 * (scheme/host casing, trailing slashes, etc.).
 */
class MigrationUrlNormalizer
{
    /**
     * Normalize a public http(s) URL for use as a session page key or lookup.
     */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $portPart = self::defaultPortPart($scheme, $port);

        $path = (string) ($parts['path'] ?? '');
        $path = '/'.trim($path, '/');
        if ($path === '/') {
            $path = '';
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$portPart.$path.$query;
    }

    /**
     * Parent URL for a hierarchical path, or null if the URL has no parent segment.
     * $url should already be normalized.
     */
    public static function parentUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        array_pop($segments);
        if ($segments === []) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $portPart = self::defaultPortPart($scheme, $port);
        $newPath = '/'.implode('/', $segments);
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$portPart.$newPath.$query;
    }

    public static function pathDepth(string $url): int
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return 0;
        }

        return substr_count($path, '/') + 1;
    }

    private static function defaultPortPart(string $scheme, ?int $port): string
    {
        if ($port === null || $port <= 0) {
            return '';
        }

        if ($scheme === 'http' && $port === 80) {
            return '';
        }

        if ($scheme === 'https' && $port === 443) {
            return '';
        }

        return ':'.$port;
    }
}
