<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Support\Str;

/**
 * Decompose a free-text user request into a list of independent entry plans.
 *
 * Each plan targets one collection + blueprint and carries a focused brief
 * so the existing single-entry generator can be reused per item.
 */
class EntryGenerationPlanner
{
    public const MAX_ENTRIES = 5;

    private AbstractAiService $aiService;

    private EntryGeneratorService $generator;

    private PromptUrlFetcher $promptUrlFetcher;

    private ?FigmaContentFetcher $figma;

    public function __construct(
        AbstractAiService $aiService,
        EntryGeneratorService $generator,
        PromptUrlFetcher $promptUrlFetcher,
        ?FigmaContentFetcher $figma = null,
    ) {
        $this->aiService = $aiService;
        $this->generator = $generator;
        $this->promptUrlFetcher = $promptUrlFetcher;
        $this->figma = $figma;
    }

    /**
     * Always returns at least one entry. When the LLM cannot produce a usable plan,
     * falls back to single-entry resolution so the existing flow keeps working.
     *
     * @return array{entries: array<int, array{collection: string, blueprint: string, prompt: string, label: string}>, warnings: string[]}
     */
    public function plan(string $prompt, ?string $attachmentContent = null, ?string $siteLocale = null): array
    {
        $catalog = $this->generator->getCollectionsCatalog();

        if ($catalog === []) {
            throw new \RuntimeException(__('No collections with blueprints are available.'));
        }

        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $maxEntries = self::MAX_ENTRIES;

        $system = "You are a Statamic CMS planner. The user describes one or more entries they want to create. "
            ."Split the request into a list of independent entries and pick the best collection + blueprint for each, drawn ONLY from the catalog provided.\n\n"
            ."Return ONLY a JSON object shaped like:\n"
            ."{\"entries\":[{\"collection\":\"<handle>\",\"blueprint\":\"<handle>\",\"label\":\"<2-6 word title>\",\"prompt\":\"<self-contained brief for this single entry>\"}]}\n\n"
            ."Rules:\n"
            ."- If the user asks for one entry, return exactly one item.\n"
            ."- If the user asks for several entries (\"create 2 pages…\", \"a blog post and a page about X\"), return one item per entry, in the order requested.\n"
            ."- Cap the list at {$maxEntries} items even if more are requested.\n"
            ."- collection and blueprint MUST match the catalog exactly (case-sensitive). Do not invent handles.\n"
            ."- The blueprint MUST be one listed for the chosen collection.\n"
            ."- If unsure which collection fits, prefer the collection whose handle is \"pages\" if present; otherwise use the first catalog collection.\n"
            ."- The per-entry \"prompt\" must be a complete, self-contained brief in the user's language — include every detail from the user's request that pertains to this entry. Do not reference \"the other entry\" or rely on context outside the prompt.\n"
            ."- The \"label\" is a short human title (2-6 words) for the UI, in the user's language.\n"
            .$this->germanNoEszettPlannerRule($siteLocale)
            ."- Output JSON only. No markdown fences, no commentary.";

        $attachmentPart = $attachmentContent
            ? "\n\nAdditional context from an attached document (excerpt):\n".Str::limit($attachmentContent, 6000)
            : '';

        $urlAug = $this->promptUrlFetcher->buildAugmentation($prompt);
        $figmaAug = $this->figma ? $this->figma->buildAugmentation($prompt) : ['appendix' => '', 'warnings' => []];

        $user = "Available collections and blueprints (JSON):\n{$catalogJson}\n\nUser request:\n{$prompt}{$urlAug['appendix']}{$figmaAug['appendix']}{$attachmentPart}";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        $warnings = [];

        try {
            $raw = $this->aiService->generateFromMessages($messages, 1024);
        } catch (\Throwable) {
            return $this->singleEntryFallback($prompt, $attachmentContent, $warnings, $urlAug, $figmaAug);
        }

        if ($raw === null || trim($raw) === '') {
            return $this->singleEntryFallback($prompt, $attachmentContent, $warnings, $urlAug, $figmaAug);
        }

        try {
            $entries = $this->parseAndNormalize($raw, $catalog, $prompt, $warnings);
        } catch (\RuntimeException) {
            return $this->singleEntryFallback($prompt, $attachmentContent, $warnings, $urlAug, $figmaAug);
        }

        if ($entries === []) {
            return $this->singleEntryFallback($prompt, $attachmentContent, $warnings, $urlAug, $figmaAug);
        }

        if (count($entries) > self::MAX_ENTRIES) {
            $dropped = count($entries) - self::MAX_ENTRIES;
            $entries = array_slice($entries, 0, self::MAX_ENTRIES);
            $warnings[] = __(':n more entries were requested but only the first :max will be created. Ask again to create the rest.', [
                'n' => $dropped,
                'max' => self::MAX_ENTRIES,
            ]);
        }

        foreach ($urlAug['warnings'] as $w) {
            $warnings[] = $w;
        }

        foreach ($figmaAug['warnings'] as $w) {
            $warnings[] = $w;
        }

        $combinedAppendix = $urlAug['appendix'].$figmaAug['appendix'];

        if ($combinedAppendix !== '') {
            foreach ($entries as &$entry) {
                $entry['prompt'] = trim((string) ($entry['prompt'] ?? '')).$combinedAppendix;
            }
            unset($entry);
        }

        return ['entries' => $entries, 'warnings' => $warnings];
    }

    /**
     * When the CP site locale is German, planner-written labels/prompts must avoid ß.
     */
    private function germanNoEszettPlannerRule(?string $siteLocale): string
    {
        if ($siteLocale === null || trim($siteLocale) === '') {
            return '';
        }

        $normalized = strtolower(str_replace('_', '-', trim($siteLocale)));

        if (! str_starts_with($normalized, 'de')) {
            return '';
        }

        return "- For any German in \"label\" or \"prompt\": NEVER use ß; always use ss (e.g. Strasse, gross, heiss).\n";
    }

    /**
     * Tolerant JSON parsing — accepts {entries:[…]}, a bare array, or a single object.
     *
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @param  string[]  $warnings
     * @return array<int, array{collection: string, blueprint: string, prompt: string, label: string}>
     */
    private function parseAndNormalize(string $raw, array $catalog, string $originalPrompt, array &$warnings): array
    {
        $response = trim($raw);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/', '', $response);
        $response = trim($response);

        $candidates = [];

        $firstBrace = strpos($response, '{');
        $lastBrace = strrpos($response, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $candidates[] = substr($response, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        $firstBracket = strpos($response, '[');
        $lastBracket = strrpos($response, ']');
        if ($firstBracket !== false && $lastBracket !== false && $lastBracket > $firstBracket) {
            $candidates[] = substr($response, $firstBracket, $lastBracket - $firstBracket + 1);
        }

        $decoded = null;
        foreach ($candidates as $jsonStr) {
            try {
                $decoded = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
                break;
            } catch (\JsonException) {
                continue;
            }
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException(__('Could not parse planner response.'));
        }

        $list = null;

        if (isset($decoded['entries']) && is_array($decoded['entries'])) {
            $list = $decoded['entries'];
        } elseif (array_is_list($decoded)) {
            $list = $decoded;
        } elseif (isset($decoded['collection']) || isset($decoded['blueprint'])) {
            $list = [$decoded];
        }

        if (! is_array($list) || $list === []) {
            return [];
        }

        $normalized = [];

        foreach ($list as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $collection = isset($row['collection']) && is_string($row['collection']) ? trim($row['collection']) : '';
            $blueprint = isset($row['blueprint']) && is_string($row['blueprint']) ? trim($row['blueprint']) : '';
            $entryPrompt = isset($row['prompt']) && is_string($row['prompt']) ? trim($row['prompt']) : '';
            $label = isset($row['label']) && is_string($row['label']) ? trim($row['label']) : '';

            $validated = $this->validateAndCoerceTarget($catalog, $collection, $blueprint);

            if ($validated === null) {
                $warnings[] = __('Skipped entry #:n: invalid collection or blueprint returned by the AI.', ['n' => $i + 1]);

                continue;
            }

            if ($entryPrompt === '') {
                $entryPrompt = $originalPrompt;
            }

            if ($label === '') {
                $label = Str::limit(strip_tags($entryPrompt), 60);
            }

            $normalized[] = [
                'collection' => $validated['collection'],
                'blueprint' => $validated['blueprint'],
                'prompt' => $entryPrompt,
                'label' => $label,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{collection: string, blueprint: string}|null
     */
    private function validateAndCoerceTarget(array $catalog, string $collection, string $blueprint): ?array
    {
        if ($collection === '') {
            return null;
        }

        foreach ($catalog as $row) {
            if (($row['handle'] ?? '') !== $collection) {
                continue;
            }

            $blueprints = $row['blueprints'] ?? [];
            $bpHandles = array_map(fn ($b) => $b['handle'] ?? '', $blueprints);

            if ($blueprint !== '' && in_array($blueprint, $bpHandles, true)) {
                return ['collection' => $collection, 'blueprint' => $blueprint];
            }

            if (! empty($bpHandles)) {
                return ['collection' => $collection, 'blueprint' => $bpHandles[0]];
            }
        }

        return null;
    }

    /**
     * Fallback when the planner fails: defer to the existing single-entry resolver.
     *
     * @param  string[]  $warnings
     * @param  array{appendix: string, warnings: array<int, string>}  $urlAug
     * @param  array{appendix: string, warnings: array<int, string>}  $figmaAug
     * @return array{entries: array<int, array{collection: string, blueprint: string, prompt: string, label: string}>, warnings: string[]}
     */
    private function singleEntryFallback(string $prompt, ?string $attachmentContent, array $warnings, array $urlAug, array $figmaAug = ['appendix' => '', 'warnings' => []]): array
    {
        $resolved = $this->generator->resolveTargetFromPrompt($prompt, $attachmentContent, $urlAug, $figmaAug);

        foreach ($urlAug['warnings'] as $w) {
            $warnings[] = $w;
        }

        foreach ($figmaAug['warnings'] as $w) {
            $warnings[] = $w;
        }

        $combinedPrompt = trim($prompt.$urlAug['appendix'].$figmaAug['appendix']);

        return [
            'entries' => [[
                'collection' => $resolved['collection'],
                'blueprint' => $resolved['blueprint'],
                'prompt' => $combinedPrompt,
                'label' => Str::limit(strip_tags($prompt), 60),
            ]],
            'warnings' => $warnings,
        ];
    }
}
