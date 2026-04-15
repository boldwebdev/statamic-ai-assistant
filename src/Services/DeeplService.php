<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use DeepL\DeepLClient;
use DeepL\DeepLException;
use DeepL\LanguageCode;
use DeepL\Translator;
use DeepL\TranslatorOptions;
use DeepL\Usage;
use DeepL\UsageDetail;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Site;

class DeeplService
{
    /**
     * DeepL may return very large character_limit / document_limit values (e.g. 1e12) for
     * Pro keys with no hard cap or internal metering; showing that raw limit in the CP is
     * misleading, so treat it like unlimited for UI purposes.
     */
    private const USAGE_LIMIT_EFFECTIVELY_UNLIMITED = 1_000_000_000_000;

    private ?DeepLClient $client = null;

    /** @var array<string, string> */
    private array $languageMapping;

    public function __construct()
    {
        $this->languageMapping = config('deepl.language_mapping', []);
    }

    private function client(): DeepLClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $apiKey = config('deepl.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('DeepL API key is not configured. Set DEEPL_API_KEY in your .env file.');
        }

        $this->client = new DeepLClient($apiKey);

        return $this->client;
    }

    /**
     * Translate an array of texts in a single API call.
     * Empty strings are preserved and not sent to the API.
     *
     * @param  array<int, string>  $texts
     * @param  array<string, mixed>  $options  DeepL TranslateTextOptions (e.g. tag_handling)
     * @return array<int, string>
     */
    public function translateBatch(array $texts, ?string $sourceLang, string $targetLang, array $options = []): array
    {
        $targetLang = $this->resolveStatamicLocale($targetLang);
        $mappedSource = $this->mapSourceLanguageForDeepL($sourceLang);
        $mappedTarget = $this->normalizeDeepLTargetLanguage($this->mapLanguage($targetLang));

        $nonEmptyTexts = [];
        $indexMap = [];

        foreach ($texts as $index => $text) {
            $trimmed = trim($text);
            if ($trimmed !== '') {
                $nonEmptyTexts[] = $trimmed;
                $indexMap[] = $index;
            }
        }

        if (empty($nonEmptyTexts)) {
            return $texts;
        }

        try {
            $results = $this->client()->translateText(
                $nonEmptyTexts,
                $mappedSource,
                $mappedTarget,
                $options,
            );
        } catch (DeepLException $e) {
            if ($mappedSource !== null && $this->shouldRetryTranslationWithAutoDetectedSource($e)) {
                $results = $this->client()->translateText(
                    $nonEmptyTexts,
                    null,
                    $mappedTarget,
                    $options,
                );
            } else {
                throw $e;
            }
        }

        $translated = $texts;
        foreach ($results as $i => $result) {
            $translated[$indexMap[$i]] = $result->text;
        }

        return $translated;
    }

    /**
     * Translate a single text string via DeepL.
     * Pass null for $sourceLang to let DeepL detect the source language (omit source_lang in the API request).
     */
    public function translateText(string $text, ?string $sourceLang, string $targetLang, array $options = []): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $results = $this->translateBatch([$text], $sourceLang, $targetLang, $options);

        return $results[0] ?? $text;
    }

    /**
     * Current billing-period DeepL API usage (characters, documents) for display in the CP.
     * Uses GET /v2/usage. Pro API returns account totals plus api_key_character_* for the key used;
     * we prefer key-level numbers for the main meter when present.
     *
     * @return array{
     *   character: ?array,
     *   character_account: ?array,
     *   character_is_api_key_scoped: bool,
     *   document: ?array,
     *   team_document: ?array,
     *   any_limit_reached: bool,
     *   billing: array{period_start?: string, period_end?: string},
     *   estimated_cost: array{
     *     enabled: bool,
     *     base_fee_eur?: float,
     *     per_million_chars_eur?: float,
     *     account_character_count?: int,
     *     estimated_total_eur?: float
     *   }
     * }
     */
    public function getUsageForApi(): array
    {
        $apiKey = config('deepl.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('DeepL API key is not configured. Set DEEPL_API_KEY in your .env file.');
        }

        $body = $this->fetchUsageResponseBody($apiKey);
        $usage = new Usage($body);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        // Per DeepL: start_time / end_time are only in some Pro responses.
        // https://developers.deepl.com/api-reference/usage-and-quota
        $billing = [];
        $endRaw = $decoded['end_time'] ?? null;
        $startRaw = $decoded['start_time'] ?? null;
        if (is_string($endRaw) && trim($endRaw) !== '') {
            $billing['period_end'] = trim($endRaw);
        }
        if (is_string($startRaw) && trim($startRaw) !== '') {
            $billing['period_start'] = trim($startRaw);
        }

        $primaryChar = null;
        $fromApiKey = isset($decoded['api_key_character_count'], $decoded['api_key_character_limit'])
            && is_numeric($decoded['api_key_character_count'])
            && is_numeric($decoded['api_key_character_limit']);
        if ($fromApiKey) {
            $primaryChar = $this->serializeUsagePair(
                (int) $decoded['api_key_character_count'],
                (int) $decoded['api_key_character_limit']
            );
        }
        if ($primaryChar === null && $usage->character !== null) {
            $primaryChar = $this->serializeUsageDetail($usage->character);
        }

        $accountChar = null;
        if (isset($decoded['character_count'], $decoded['character_limit'])
            && is_numeric($decoded['character_count'])
            && is_numeric($decoded['character_limit'])) {
            $accountChar = $this->serializeUsagePair(
                (int) $decoded['character_count'],
                (int) $decoded['character_limit']
            );
        }

        $characterIsApiKeyScoped = $fromApiKey && $accountChar !== null;

        $anyReached = $usage->anyLimitReached();
        if ($primaryChar !== null && ! $primaryChar['unlimited']
            && isset($primaryChar['limit']) && $primaryChar['limit'] !== null
            && $primaryChar['count'] >= $primaryChar['limit']) {
            $anyReached = true;
        }

        $estimatedCost = ['enabled' => false];
        if (config('deepl.estimated_cost_enabled', true)
            && isset($decoded['character_count'])
            && is_numeric($decoded['character_count'])) {
            $accountCount = (int) $decoded['character_count'];
            $base = (float) config('deepl.estimated_monthly_base_fee_eur', 4.99);
            $perM = (float) config('deepl.estimated_per_million_chars_eur', 20);
            $variable = ($accountCount / 1_000_000.0) * $perM;
            $estimatedCost = [
                'enabled' => true,
                'base_fee_eur' => $base,
                'per_million_chars_eur' => $perM,
                'account_character_count' => $accountCount,
                'estimated_total_eur' => round($base + $variable, 2),
            ];
        }

        return [
            'character' => $primaryChar,
            'character_account' => $characterIsApiKeyScoped ? $accountChar : null,
            'character_is_api_key_scoped' => $characterIsApiKeyScoped,
            'document' => $this->serializeUsageDetail($usage->document),
            'team_document' => $this->serializeUsageDetail($usage->teamDocument),
            'any_limit_reached' => $anyReached,
            'billing' => $billing,
            'estimated_cost' => $estimatedCost,
        ];
    }

    /**
     * Raw JSON body from GET /v2/usage (same host as the DeepL PHP client uses for free vs Pro keys).
     */
    protected function fetchUsageResponseBody(string $apiKey): string
    {
        $baseUrl = Translator::isAuthKeyFreeAccount($apiKey)
            ? TranslatorOptions::DEFAULT_SERVER_URL_FREE
            : TranslatorOptions::DEFAULT_SERVER_URL;

        $response = Http::withHeaders([
            'Authorization' => 'DeepL-Auth-Key '.$apiKey,
            'User-Agent' => 'BoldWebStatamicAiAssistant/1.0',
        ])->timeout(20)->get($baseUrl.'/v2/usage');

        if (! $response->successful()) {
            throw new \RuntimeException('DeepL usage request failed (HTTP '.$response->status().').');
        }

        return $response->body();
    }

    /**
     * @return array{count: int, limit: ?int, unlimited: bool, percent: ?int}|null
     */
    protected function serializeUsageDetail(?UsageDetail $detail): ?array
    {
        if ($detail === null) {
            return null;
        }

        return $this->serializeUsagePair($detail->count, $detail->limit);
    }

    /**
     * @return array{count: int, limit: ?int, unlimited: bool, percent: ?int}|null
     */
    protected function serializeUsagePair(int $count, int $limit): ?array
    {
        if ($limit <= 0 || $limit >= self::USAGE_LIMIT_EFFECTIVELY_UNLIMITED) {
            return [
                'count' => $count,
                'limit' => null,
                'unlimited' => true,
                'percent' => null,
            ];
        }

        return [
            'count' => $count,
            'limit' => $limit,
            'unlimited' => false,
            'percent' => min(100, (int) round(($count / $limit) * 100)),
        ];
    }

    /**
     * Turn a site handle (e.g. default, de_ch) into a Statamic locale (e.g. en, de-CH) when possible.
     */
    public function resolveStatamicLocale(string $handleOrLocale): string
    {
        $trimmed = trim($handleOrLocale);
        if ($trimmed === '') {
            return $trimmed;
        }

        $lower = strtolower($trimmed);
        $normalizedLocale = strtolower(str_replace('_', '-', $trimmed));

        $site = Site::all()->first(fn ($s) => strtolower($s->handle()) === $lower);
        if ($site) {
            return $site->locale();
        }

        $site = Site::all()->first(function ($s) use ($normalizedLocale) {
            $loc = strtolower(str_replace('_', '-', $s->locale()));

            return $loc === $normalizedLocale;
        });
        if ($site) {
            return $site->locale();
        }

        $site = Site::all()->first(function ($s) use ($normalizedLocale) {
            $lang = strtolower(str_replace('_', '-', (string) $s->lang()));

            return $lang === $normalizedLocale;
        });
        if ($site) {
            return $site->locale();
        }

        return $trimmed;
    }

    /**
     * Map Statamic locale / site keys to DeepL language codes.
     * Handles regional tags (de-CH), underscores (de_CH), and site handles via config.
     */
    public function mapLanguage(string $localeOrHandle): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($localeOrHandle)));

        $candidates = array_unique(array_filter([
            $localeOrHandle,
            $normalized,
            $this->primaryLanguageSubtag($normalized),
        ]));

        foreach ($candidates as $key) {
            if ($key !== null && $key !== '' && isset($this->languageMapping[$key])) {
                return $this->languageMapping[$key];
            }
        }

        $primary = $this->primaryLanguageSubtag($normalized);
        if ($primary !== null && $primary !== '') {
            return $primary;
        }

        return $localeOrHandle;
    }

    /**
     * DeepL rejects ambiguous target codes: use en-GB/en-US and pt-PT/pt-BR (see Translator::buildBodyParams).
     */
    protected function normalizeDeepLTargetLanguage(string $mapped): string
    {
        $lower = strtolower(str_replace('_', '-', trim($mapped)));

        if ($lower === 'en') {
            return (string) config('deepl.english_target', 'en-GB');
        }

        if ($lower === 'pt') {
            return (string) config('deepl.portuguese_target', 'pt-PT');
        }

        return $mapped;
    }

    /**
     * Resolve and map source language for DeepL, or null for automatic detection.
     *
     * `language_mapping` may use target-only codes (e.g. en-GB, pt-PT). DeepL rejects those for
     * `source_lang` — only base codes are valid (en, pt, de, …). Strip regional variants for sources.
     *
     * @see \DeepL\LanguageCode (e.g. ENGLISH_BRITISH is target-only)
     */
    protected function mapSourceLanguageForDeepL(?string $sourceLang): ?string
    {
        if ($sourceLang === null || trim($sourceLang) === '') {
            return null;
        }

        $mapped = $this->mapLanguage($this->resolveStatamicLocale($sourceLang));
        if ($mapped === '') {
            return null;
        }

        try {
            return LanguageCode::removeRegionalVariant($mapped);
        } catch (DeepLException) {
            return null;
        }
    }

    /**
     * When DeepL returns HTTP 400 for an invalid source_lang, retry once with auto-detection.
     */
    protected function shouldRetryTranslationWithAutoDetectedSource(DeepLException $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'source_lang')
            || str_contains($msg, 'source language');
    }

    protected function primaryLanguageSubtag(string $normalized): ?string
    {
        if (preg_match('/^([a-z]{2,3})(?:[-_]|$)/', $normalized, $m)) {
            return $m[1];
        }

        return null;
    }
}
