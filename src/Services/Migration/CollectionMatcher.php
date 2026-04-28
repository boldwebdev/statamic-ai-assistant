<?php

namespace BoldWeb\StatamicAiAssistant\Services\Migration;

use BoldWeb\StatamicAiAssistant\Services\AbstractAiService;
use Illuminate\Support\Facades\Log;

/**
 * Suggests which Statamic collection + blueprint each URL cluster should map to.
 *
 * Strategy (cheapest first):
 *   1. Exact handle / title match (no LLM).
 *   2. Partial / contains match (no LLM).
 *   3. A single LLM call for everything still unmatched.
 *   4. Fallback to "pages" collection (or the first available collection).
 *
 * If the chosen collection has exactly one blueprint, its handle is included
 * as the suggested blueprint so the UI can skip asking the user.
 */
class CollectionMatcher
{
    public function __construct(private AbstractAiService $ai) {}

    /**
     * @param  array<int, array{pattern: string, count: int, sample_urls: array<int, string>, urls: array<int, string>}>  $clusters
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $collections
     * @return array<string, array{collection: string, blueprint: string}>  keyed by cluster pattern
     */
    public function suggest(array $clusters, array $collections): array
    {
        if ($collections === [] || $clusters === []) {
            return [];
        }

        $default = $this->pickDefault($collections);
        $suggestions = [];
        $unmatched = [];

        foreach ($clusters as $c) {
            $match = $this->fuzzyMatch($c['pattern'] ?? '', $collections);
            if ($match !== null) {
                $suggestions[$c['pattern']] = [
                    'collection' => $match['handle'],
                    'blueprint' => $this->onlyBlueprintHandle($match),
                ];
            } else {
                $unmatched[] = $c['pattern'];
            }
        }

        if ($unmatched !== []) {
            $llmMap = $this->askLlm($unmatched, $collections);

            foreach ($unmatched as $pattern) {
                $handle = $llmMap[$pattern] ?? null;
                $col = $handle ? $this->findCollection((string) $handle, $collections) : null;
                $col = $col ?? $default;
                $suggestions[$pattern] = [
                    'collection' => $col['handle'],
                    'blueprint' => $this->onlyBlueprintHandle($col),
                ];
            }
        }

        return $suggestions;
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $collections
     * @return array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}|null
     */
    private function fuzzyMatch(string $pattern, array $collections): ?array
    {
        $slug = strtolower(trim(str_replace('*', '', $pattern), '/ '));
        if ($slug === '') {
            return null;
        }

        // Exact handle match.
        foreach ($collections as $c) {
            if (strtolower($c['handle']) === $slug) {
                return $c;
            }
        }

        // Exact title match.
        foreach ($collections as $c) {
            if (strtolower($c['title']) === $slug) {
                return $c;
            }
        }

        // Contains match either direction.
        foreach ($collections as $c) {
            $h = strtolower($c['handle']);
            $t = strtolower($c['title']);
            if (str_contains($h, $slug) || str_contains($slug, $h) || str_contains($t, $slug)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Single LLM call: map each pattern to a collection handle.
     *
     * @param  array<int, string>  $patterns
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $collections
     * @return array<string, string>  keyed by pattern → collection handle
     */
    private function askLlm(array $patterns, array $collections): array
    {
        $summary = array_map(fn ($c) => ['handle' => $c['handle'], 'title' => $c['title']], $collections);
        $validHandles = array_column($collections, 'handle');

        $prompt = "You match URL path patterns to the best-fitting Statamic collection.\n\n".
            "Collections available:\n".json_encode($summary, JSON_UNESCAPED_SLASHES)."\n\n".
            "URL patterns to map:\n".json_encode($patterns, JSON_UNESCAPED_SLASHES)."\n\n".
            "Rules:\n".
            "- Return ONLY a JSON object — no commentary, no code fences.\n".
            "- Keys are the exact patterns, values are the chosen collection handle.\n".
            "- Use only handles from the list above.\n".
            "- If unsure, prefer a generic 'pages' collection if present.\n\n".
            'Example: {"/blog/*": "blog", "/about": "pages"}';

        try {
            $response = $this->ai->generateFromMessages([
                ['role' => 'system', 'content' => 'You output ONLY valid JSON. Never include commentary or code fences.'],
                ['role' => 'user', 'content' => $prompt],
            ], 600);
        } catch (\Throwable $e) {
            Log::notice('CollectionMatcher LLM call failed', ['message' => $e->getMessage()]);

            return [];
        }

        $clean = $this->stripFences((string) $response);
        $decoded = json_decode($clean, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $pattern => $handle) {
            if (in_array($handle, $validHandles, true)) {
                $out[(string) $pattern] = (string) $handle;
            }
        }

        return $out;
    }

    private function stripFences(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
        $s = preg_replace('/\s*```$/', '', $s);

        return trim($s);
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $collections
     * @return array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}
     */
    private function pickDefault(array $collections): array
    {
        foreach ($collections as $c) {
            if (strtolower($c['handle']) === 'pages') {
                return $c;
            }
        }

        return $collections[0];
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $collections
     * @return array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}|null
     */
    private function findCollection(string $handle, array $collections): ?array
    {
        foreach ($collections as $c) {
            if ($c['handle'] === $handle) {
                return $c;
            }
        }

        return null;
    }

    /**
     * If the collection has exactly one blueprint, return its handle so the UI
     * can pre-fill it. Otherwise return '' so the UI shows the selector.
     *
     * @param  array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}  $collection
     */
    private function onlyBlueprintHandle(array $collection): string
    {
        $bps = $collection['blueprints'] ?? [];

        return count($bps) === 1 ? (string) $bps[0]['handle'] : '';
    }
}
