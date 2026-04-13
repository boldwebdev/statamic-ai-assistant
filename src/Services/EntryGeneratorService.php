<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Services\Concerns\TranslatesFields;
use Illuminate\Support\Str;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

class EntryGeneratorService
{
    use TranslatesFields;

    private const GEN_TEXT_TYPES = ['text', 'textarea', 'ai_text', 'ai_textarea'];

    private const GEN_HTML_TYPES = ['bard'];

    private const GEN_CHOICE_TYPES = ['select', 'button_group'];

    private const GEN_BOOLEAN_TYPES = ['toggle'];

    private const GEN_DATE_TYPES = ['date'];

    private const GEN_SKIP_TYPES = ['assets', 'section', 'color', 'terms'];

    private const GEN_GROUP_TYPES = ['group'];

    private const GEN_LINK_TYPES = ['link'];

    private const GEN_RECURSIVE_TYPES = ['replicator', 'grid', 'components'];

    private AbstractAiService $aiService;

    private EntryGeneratorAssetResolver $assetResolver;

    private EntryGeneratorLinkFallback $linkFallback;

    public function __construct(
        AbstractAiService $aiService,
        ?EntryGeneratorAssetResolver $assetResolver = null,
        ?EntryGeneratorLinkFallback $linkFallback = null,
    ) {
        $this->aiService = $aiService;
        $this->assetResolver = $assetResolver ?? new EntryGeneratorAssetResolver;
        $this->linkFallback = $linkFallback ?? new EntryGeneratorLinkFallback;
    }

    /**
     * Generate content for an entry from a prompt (does NOT save).
     *
     * @param  callable(string): void|null  $onStreamToken  Optional callback for each streamed assistant text delta (NDJSON / CP drawer)
     * @return array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: string[]}
     */
    public function generateContent(
        string $collectionHandle,
        string $blueprintHandle,
        string $prompt,
        string $locale,
        ?string $attachmentContent = null,
        ?callable $onStreamToken = null,
    ): array {
        $collection = Collection::findByHandle($collectionHandle);

        if (! $collection) {
            throw new \RuntimeException(__('Collection not found.'));
        }

        $visible = $collection->entryBlueprints()->reject->hidden();

        $blueprint = $blueprintHandle
            ? $visible->keyBy->handle()->get($blueprintHandle)
            : null;

        if (! $blueprint) {
            $blueprint = $visible->first();
        }

        if (! $blueprint) {
            throw new \RuntimeException(__('Blueprint not found.'));
        }

        $fieldSchema = $this->buildFieldSchema($blueprint);
        $systemMessage = $this->buildSystemMessage($fieldSchema, $locale);
        $userMessage = $this->buildUserMessage($prompt, $attachmentContent);

        $messages = [
            ['role' => 'system', 'content' => $systemMessage],
            ['role' => 'user', 'content' => $userMessage],
        ];

        $maxTokens = (int) config('statamic-ai-assistant.generator_max_tokens', 4000);
        $rawResponse = $this->aiService->generateFromMessages($messages, $maxTokens, $onStreamToken);

        if ($rawResponse === null || $rawResponse === '') {
            throw new \RuntimeException(__('The AI returned no content. Check your provider settings and try again.'));
        }

        $parsedData = $this->parseResponse($rawResponse);

        return $this->mapToFieldData($parsedData, $blueprint, $locale, $prompt);
    }

    /**
     * Save generated data as a new entry (draft).
     */
    public function saveEntry(
        string $collectionHandle,
        string $blueprintHandle,
        string $locale,
        array $data,
    ): StatamicEntry {
        return $this->createEntry($collectionHandle, $blueprintHandle, $locale, $data);
    }

    /**
     * Collections visible for entry generation (non-hidden blueprints only).
     *
     * @return array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>
     */
    public function getCollectionsCatalog(): array
    {
        return Collection::all()
            ->map(function ($collection) {
                $blueprints = $collection->entryBlueprints()
                    ->reject->hidden()
                    ->values()
                    ->map(function ($bp) {
                        return [
                            'handle' => $bp->handle(),
                            'title' => $bp->title(),
                        ];
                    });

                return [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                    'blueprints' => $blueprints->values()->all(),
                ];
            })
            ->filter(fn ($row) => $row['blueprints'] !== [])
            ->values()
            ->all();
    }

    /**
     * Ask the LLM which collection and blueprint best match the user request.
     * Falls back to the "pages" collection when unsure; if missing, the first catalog entry.
     *
     * @return array{collection: string, blueprint: string}
     */
    public function resolveTargetFromPrompt(string $prompt, ?string $attachmentContent = null): array
    {
        $catalog = $this->getCollectionsCatalog();

        if ($catalog === []) {
            throw new \RuntimeException(__('No collections with blueprints are available.'));
        }

        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $attachmentPart = $attachmentContent
            ? "\n\nAdditional context from an attached document (excerpt):\n".Str::limit($attachmentContent, 6000)
            : '';

        $system = 'You are a Statamic CMS assistant. Given the user\'s request and the list of collections with their entry blueprints, choose the single best collection and blueprint for a new entry.'
            .' Return ONLY a JSON object with exactly two string keys: "collection" (the collection handle) and "blueprint" (the blueprint handle). The handles must match the catalog exactly (case-sensitive).'
            .' Prefer the most specific collection that fits the topic. If you are unsure, several fit equally, or none clearly apply, use the collection with handle "pages" if it exists in the catalog; otherwise use the first collection in the catalog.'
            .' The blueprint must be one of the blueprints listed for that collection.'
            .' Do not include markdown fences or any text outside the JSON.';

        $user = "Available collections and blueprints (JSON):\n{$catalogJson}\n\nUser request:\n{$prompt}{$attachmentPart}";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        $raw = $this->aiService->generateFromMessages($messages, 256);

        if ($raw === null || trim($raw) === '') {
            return $this->fallbackTargetSelection($catalog);
        }

        try {
            $parsed = $this->parseTargetSelectionResponse($raw);
            $collectionHandle = isset($parsed['collection']) && is_string($parsed['collection']) ? trim($parsed['collection']) : '';
            $blueprintHandle = isset($parsed['blueprint']) && is_string($parsed['blueprint']) ? trim($parsed['blueprint']) : '';
        } catch (\RuntimeException) {
            return $this->fallbackTargetSelection($catalog);
        }

        if ($this->validateTargetSelection($catalog, $collectionHandle, $blueprintHandle)) {
            return ['collection' => $collectionHandle, 'blueprint' => $blueprintHandle];
        }

        return $this->fallbackTargetSelection($catalog);
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{collection: string, blueprint: string}
     */
    private function fallbackTargetSelection(array $catalog): array
    {
        foreach ($catalog as $row) {
            if (($row['handle'] ?? '') === 'pages' && ($row['blueprints'][0]['handle'] ?? '') !== '') {
                return [
                    'collection' => 'pages',
                    'blueprint' => $row['blueprints'][0]['handle'],
                ];
            }
        }

        $first = $catalog[0];

        return [
            'collection' => $first['handle'],
            'blueprint' => $first['blueprints'][0]['handle'],
        ];
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     */
    private function validateTargetSelection(array $catalog, string $collectionHandle, string $blueprintHandle): bool
    {
        if ($collectionHandle === '' || $blueprintHandle === '') {
            return false;
        }

        foreach ($catalog as $row) {
            if ($row['handle'] !== $collectionHandle) {
                continue;
            }

            foreach ($row['blueprints'] as $bp) {
                if (($bp['handle'] ?? '') === $blueprintHandle) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTargetSelectionResponse(string $rawResponse): array
    {
        $response = trim($rawResponse);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/', '', $response);
        $response = trim($response);

        $firstBrace = strpos($response, '{');
        $lastBrace = strrpos($response, '}');

        if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
            throw new \RuntimeException(__('Could not parse AI response as JSON. Please try again.'));
        }

        $jsonStr = substr($response, $firstBrace, $lastBrace - $firstBrace + 1);

        try {
            $decoded = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(__('Invalid JSON in AI response: ').$e->getMessage());
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException(__('AI response must be a JSON object, not an array.'));
        }

        return $decoded;
    }

    /**
     * Field schema for the CP review step (includes assets; LLM schema excludes them).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getFieldSchemaForPreview(Blueprint $blueprint): array
    {
        $schema = [];

        foreach ($blueprint->fields()->all() as $field) {
            $type = $field->type();

            if ($type === 'section') {
                continue;
            }

            if ($type === 'assets') {
                $schema[$field->handle()] = [
                    'label' => $field->display(),
                    'generatable' => true,
                    'type' => 'asset_description',
                ];

                continue;
            }

            $entry = $this->buildFieldSchemaEntry($field);

            if ($entry !== null) {
                $schema[$field->handle()] = $entry;
            }
        }

        return $schema;
    }

    /**
     * Build a JSON-serializable schema from the blueprint for the LLM.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildFieldSchema(Blueprint $blueprint): array
    {
        $schema = [];

        foreach ($blueprint->fields()->all() as $field) {
            $entry = $this->buildFieldSchemaEntry($field);

            if ($entry !== null) {
                $schema[$field->handle()] = $entry;
            }
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function finalizeSchemaEntry(Field $field, array $entry): array
    {
        if ($field->isRequired()) {
            $entry['required'] = true;
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildFieldSchemaEntry(\Statamic\Fields\Field $field, bool $insideReplicatorComponentsOrGridRow = false): ?array
    {
        $type = $field->type();
        $display = $field->display();
        $instructions = $field->instructions();
        $config = $field->config();

        if ($insideReplicatorComponentsOrGridRow && $type === 'assets') {
            return $this->finalizeSchemaEntry($field, [
                'label' => $display,
                'generatable' => true,
                'type' => 'asset_description',
                'description' => ($instructions ? $instructions.' ' : '')
                    .'Short vivid description of the image (subject, setting, mood). You may use an empty string for layout-only blocks; imagery may be auto-selected.',
            ]);
        }

        if (in_array($type, self::GEN_SKIP_TYPES)) {
            return null;
        }

        $entry = [
            'label' => $display,
            'generatable' => true,
        ];

        if ($instructions) {
            $entry['description'] = $instructions;
        }

        if (in_array($type, self::GEN_GROUP_TYPES)) {
            $entry['type'] = 'group';
            $entry['fields'] = [];

            foreach ($field->fieldtype()->fields()->all() as $sub) {
                $subEntry = $this->buildFieldSchemaEntry($sub, $insideReplicatorComponentsOrGridRow);

                if ($subEntry !== null) {
                    $entry['fields'][$sub->handle()] = $subEntry;
                }
            }

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_LINK_TYPES)) {
            $entry['type'] = 'link';
            $entry['description'] = ($instructions ? $instructions.' ' : '')
                .'Provide a full URL (https://...), a path starting with /, entry::UUID for an internal entry, asset::... for an asset, or @child when applicable.';

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if ($type === 'video') {
            $entry['type'] = 'video_url';
            $entry['description'] = ($instructions ? $instructions.' ' : '')
                .'YouTube or video page URL (https://...) or empty string.';

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_TEXT_TYPES)) {
            $entry['type'] = 'text';

            if (isset($config['character_limit'])) {
                $entry['max_length'] = $config['character_limit'];
            }

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_HTML_TYPES)) {
            $entry['type'] = 'html';
            $entry['description'] = ($instructions ? $instructions.' ' : '')
                .'Provide as HTML using only: p, h2, h3, h4, ul, ol, li, a, strong, em, blockquote, br tags.';

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_CHOICE_TYPES)) {
            $entry['type'] = 'select';
            $options = $config['options'] ?? [];

            if (is_array($options)) {
                $entry['options'] = array_values(
                    array_map(fn ($v) => is_array($v) ? ($v['value'] ?? $v['label'] ?? $v['key'] ?? '') : (string) $v, $options)
                );
            }

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_BOOLEAN_TYPES)) {
            $entry['type'] = 'boolean';

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_DATE_TYPES)) {
            $entry['type'] = 'date';
            $entry['description'] = ($instructions ? $instructions.' ' : '').'Use YYYY-MM-DD format.';

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_RECURSIVE_TYPES)) {
            $entry['type'] = 'structured';
            $entry['description'] = ($instructions ? $instructions.' ' : '')
                .'Provide as an array of ordered blocks. Each object must include a "type" key matching a set handle from the schema "sets" keys (see set_layout_catalog when present for titles and roles). '
                .'Use several different block types across the page when the schema offers them — do not default the whole page to one or two repetitive types (for example only plain text or teaser blocks) if other sets exist. '
                .'Include visual or image-led sets where they fit the narrative, not only text-heavy blocks.';

            $sets = $this->buildRecursiveSchema($field);

            if (! empty($sets)) {
                $entry['sets'] = $sets;
            }

            if (in_array($type, ['replicator', 'components'], true)) {
                $catalog = $this->buildSetLayoutCatalog($field);

                if ($catalog !== []) {
                    $entry['set_layout_catalog'] = $catalog;
                }
            }

            return $this->finalizeSchemaEntry($field, $entry);
        }

        // Unknown field type — skip
        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildRecursiveSchema(\Statamic\Fields\Field $field): array
    {
        $sets = [];
        $fieldtype = $field->fieldtype();

        if (in_array($field->type(), ['replicator', 'components'])) {
            foreach ($fieldtype->flattenedSetsConfig() as $setHandle => $setConfig) {
                $setFields = $fieldtype->fields($setHandle);
                $setSchema = [];

                foreach ($setFields->all() as $subField) {
                    $subEntry = $this->buildFieldSchemaEntry($subField, true);

                    if ($subEntry !== null) {
                        $setSchema[$subField->handle()] = $subEntry;
                    }
                }

                $sets[$setHandle] = $setSchema;
            }
        } elseif ($field->type() === 'grid') {
            $gridFields = $fieldtype->fields();
            $gridSchema = [];

            foreach ($gridFields->all() as $subField) {
                $subEntry = $this->buildFieldSchemaEntry($subField, true);

                if ($subEntry !== null) {
                    $gridSchema[$subField->handle()] = $subEntry;
                }
            }

            if (! empty($gridSchema)) {
                $sets['_grid_row'] = $gridSchema;
            }
        }

        return $sets;
    }

    /**
     * Human-oriented summary of each replicator / components set for the LLM.
     *
     * @return array<int, array{type_handle: string, title: string, content_mix: string}>
     */
    private function buildSetLayoutCatalog(\Statamic\Fields\Field $field): array
    {
        if (! in_array($field->type(), ['replicator', 'components'], true)) {
            return [];
        }

        $fieldtype = $field->fieldtype();
        $catalog = [];

        foreach ($fieldtype->flattenedSetsConfig() as $setHandle => $setConfig) {
            $title = is_array($setConfig) && isset($setConfig['display'])
                ? (string) $setConfig['display']
                : Str::headline(str_replace('_', ' ', (string) $setHandle));

            try {
                $setFields = $fieldtype->fields($setHandle);
            } catch (\Exception) {
                continue;
            }

            $catalog[] = [
                'type_handle' => (string) $setHandle,
                'title' => $title,
                'content_mix' => $this->describeSetContentMixForCatalog($setFields),
            ];
        }

        return $catalog;
    }

    /**
     * Short phrase describing dominant field kinds in a set (guides layout variety).
     */
    private function describeSetContentMixForCatalog(\Statamic\Fields\Fields $setFields): string
    {
        $tags = [];

        foreach ($setFields->all() as $f) {
            $t = $f->type();

            if ($t === 'assets') {
                $tags['images'] = 'images / visual';
            } elseif (in_array($t, self::GEN_HTML_TYPES, true)) {
                $tags['html'] = 'rich text';
            } elseif (in_array($t, self::GEN_TEXT_TYPES, true)) {
                $tags['text'] = 'short text';
            } elseif (in_array($t, self::GEN_LINK_TYPES, true)) {
                $tags['link'] = 'links';
            } elseif ($t === 'video') {
                $tags['video'] = 'video';
            } elseif (in_array($t, self::GEN_CHOICE_TYPES, true) || in_array($t, self::GEN_BOOLEAN_TYPES, true)) {
                $tags['control'] = 'choices / toggles';
            } elseif (in_array($t, self::GEN_RECURSIVE_TYPES, true)) {
                $tags['nested'] = 'nested layout';
            } elseif ($t === 'group') {
                $inner = $this->describeSetContentMixForCatalog($f->fieldtype()->fields());
                if ($inner !== '') {
                    $tags['group_'.$f->handle()] = $inner;
                }
            }
        }

        $parts = array_values(array_unique(array_filter(array_values($tags))));

        return $parts !== [] ? implode(', ', $parts) : 'layout block';
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema
     */
    private function fieldSchemaContainsStructuredType(array $schema): bool
    {
        foreach ($schema as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['type'] ?? null) === 'structured') {
                return true;
            }

            if (isset($entry['fields']) && is_array($entry['fields']) && $this->fieldSchemaContainsStructuredType($entry['fields'])) {
                return true;
            }

            if (isset($entry['sets']) && is_array($entry['sets'])) {
                foreach ($entry['sets'] as $setSchema) {
                    if (is_array($setSchema) && $this->fieldSchemaContainsStructuredType($setSchema)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function buildSystemMessage(array $fieldSchema, string $locale): string
    {
        $preface = config('statamic-ai-assistant.prompt_generator_preface',
            'You are a CMS content creation assistant. Generate structured content for website entries. Respond ONLY with a valid JSON object — no markdown fences, no commentary.'
        );

        $schemaJson = json_encode($fieldSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $structuredRules = '';

        if ($this->fieldSchemaContainsStructuredType($fieldSchema)) {
            $structuredRules = "\n"
                ."- For fields with type \"structured\" (replicator / components / grid): each array item is one block; include a \"type\" property with the set handle, then that set's field keys.\n"
                ."- Layout variety: when set_layout_catalog lists multiple type_handle values, deliberately mix several different block types across the page. Avoid composing the page almost entirely from one or two similar blocks (for example only generic text and teaser) if other sets exist.\n"
                ."- Visual rhythm: include blocks whose content_mix mentions images or visual layout when they support the story — not only text-heavy blocks.\n"
                ."- For type \"asset_description\" (inside sets): a concise phrase describing the desired image; empty string is allowed and imagery may be assigned automatically.\n";
        }

        return $preface."\n\n"
            ."You MUST write all content in this language/locale: {$locale}\n\n"
            .$this->germanNoEszettInstructions($locale)
            ."Here is the field schema for the entry you need to create. The JSON keys in your response must exactly match the field handles (the keys below):\n\n"
            .$schemaJson."\n\n"
            ."Rules:\n"
            ."- Respond with ONLY a valid JSON object. No markdown code fences, no explanation.\n"
            ."- For fields with type \"text\": provide plain text only, no HTML.\n"
            ."- For fields with type \"html\": provide valid HTML using only: p, h2, h3, h4, ul, ol, li, a, strong, em, blockquote, br tags.\n"
            ."- For fields with type \"select\": choose one of the provided options.\n"
            ."- For fields with type \"boolean\": provide true or false.\n"
            ."- For fields with type \"date\": use YYYY-MM-DD format.\n"
            ."- For fields with type \"structured\": provide an array of objects, each with a \"type\" key matching a set handle, plus the set's field values.\n"
            ."- For fields with type \"group\": provide a JSON object whose keys match the nested field handles (see \"fields\" in the schema).\n"
            ."- For fields with type \"link\": provide a URL, path, or entry::UUID reference as described in the field description.\n"
            ."- For fields with type \"video_url\": provide a YouTube or video page URL, or an empty string.\n"
            .$structuredRules
            ."- Every field in the schema that includes \"required\": true must have a non-empty, valid value for its type. Never omit those keys, never use empty strings for them, and never use HTML with no visible text. If the user is vague, says to do nothing, or gives minimal instructions, you must still invent sensible placeholder content so the entry would pass blueprint validation.\n"
            ."- For fields without \"required\": true, if you cannot determine content, use an empty string when appropriate.\n"
            ."- Generate meaningful, high-quality content that is relevant to the user's request.";
    }

    /**
     * Swiss-style German: never ß, always ss (project preference for generated copy).
     */
    private function germanNoEszettInstructions(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));

        if ($normalized === '' || ! str_starts_with($normalized, 'de')) {
            return '';
        }

        return "German orthography (mandatory for every German string you output, including titles and body text):\n"
            ."- NEVER use the letter ß (Eszett). Always use \"ss\" instead.\n"
            ."- Examples: \"Strasse\" not \"Straße\", \"gross\" not \"groß\", \"heiss\" not \"heiß\", \"dass\" stays \"dass\".\n\n";
    }

    private function buildUserMessage(string $prompt, ?string $attachmentContent): string
    {
        $message = $prompt;

        if ($attachmentContent) {
            $message .= "\n\n--- ATTACHED DOCUMENT CONTENT ---\n\n".$attachmentContent;
        }

        return $message;
    }

    /**
     * Parse the LLM response, extracting JSON even if wrapped in markdown fences.
     *
     * @return array<string, mixed>
     */
    private function parseResponse(string $rawResponse): array
    {
        $response = trim($rawResponse);

        // Strip markdown code fences
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/', '', $response);
        $response = trim($response);

        // Extract JSON between first { and last }
        $firstBrace = strpos($response, '{');
        $lastBrace = strrpos($response, '}');

        if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
            throw new \RuntimeException(__('Could not parse AI response as JSON. Please try again.'));
        }

        $jsonStr = substr($response, $firstBrace, $lastBrace - $firstBrace + 1);

        try {
            $decoded = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(__('Invalid JSON in AI response: ').$e->getMessage());
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException(__('AI response must be a JSON object, not an array.'));
        }

        return $decoded;
    }

    /**
     * Map parsed LLM data to Statamic field values.
     *
     * Returns both the Statamic-ready data and a display-friendly version
     * (e.g., HTML strings for Bard fields instead of ProseMirror JSON).
     *
     * @return array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: string[]}
     */
    private function mapToFieldData(array $parsedData, Blueprint $blueprint, string $locale, string $prompt = ''): array
    {
        $data = [];
        $displayData = [];
        $warnings = [];

        foreach ($blueprint->fields()->all() as $field) {
            $handle = $field->handle();
            $type = $field->type();

            if (! array_key_exists($handle, $parsedData)) {
                continue;
            }

            $value = $parsedData[$handle];

            if ($value === null || $value === '') {
                continue;
            }

            // For Bard fields, keep the raw HTML for display
            if (in_array($type, self::GEN_HTML_TYPES) && is_string($value)) {
                $displayData[$handle] = $value;
            }

            $mapped = $this->mapFieldValue($value, $field, $warnings);

            if ($mapped !== null) {
                $data[$handle] = $mapped;

                // For non-bard fields, display value is same as mapped value
                if (! isset($displayData[$handle])) {
                    $displayData[$handle] = $mapped;
                }
            }
        }

        $this->assetResolver->fillAssetFieldsWithRandom($data, $displayData, $blueprint, $warnings);
        $this->linkFallback->fillEmptyLinkFields($data, $displayData, $blueprint, $locale, $warnings);
        $this->applyMandatoryFieldFallbacks($data, $displayData, $blueprint, $warnings, $prompt);

        return ['data' => $data, 'displayData' => $displayData, 'warnings' => $warnings];
    }

    private function mapFieldValue(mixed $value, \Statamic\Fields\Field $field, array &$warnings): mixed
    {
        $type = $field->type();

        if (in_array($type, self::GEN_GROUP_TYPES)) {
            if (! is_array($value)) {
                return null;
            }

            $out = [];

            foreach ($field->fieldtype()->fields()->all() as $sub) {
                $sh = $sub->handle();

                if (! array_key_exists($sh, $value)) {
                    continue;
                }

                $mapped = $this->mapFieldValue($value[$sh], $sub, $warnings);

                if ($mapped !== null) {
                    $out[$sh] = $mapped;
                }
            }

            return $out;
        }

        if (in_array($type, self::GEN_LINK_TYPES)) {
            return $this->mapLinkFieldValue($value, $field, $warnings);
        }

        if ($type === 'video') {
            return $this->mapVideoFieldValue($value, $warnings);
        }

        if (in_array($type, self::GEN_TEXT_TYPES)) {
            $text = is_string($value) ? $value : (string) $value;

            return strip_tags($this->aiService->cleanResult($text));
        }

        if (in_array($type, self::GEN_HTML_TYPES)) {
            $html = is_string($value) ? $value : (string) $value;

            $nodes = $this->htmlToFullBardDocument($html);
            $buttons = $field->config()['buttons'] ?? null;

            return $this->sanitizeBardNodesForFieldButtons(
                $nodes,
                is_array($buttons) ? $buttons : null,
            );
        }

        if (in_array($type, self::GEN_CHOICE_TYPES)) {
            $options = $field->config()['options'] ?? [];
            $validValues = [];

            if (is_array($options)) {
                foreach ($options as $key => $opt) {
                    if (is_array($opt)) {
                        $validValues[] = $opt['value'] ?? $opt['key'] ?? (string) $key;
                    } else {
                        $validValues[] = (string) $key;
                    }
                }
            }

            $strValue = (string) $value;

            if (! empty($validValues) && ! in_array($strValue, $validValues)) {
                $warnings[] = __(':field: AI selected ":value" which is not a valid option. Field left empty.', [
                    'field' => $field->display(),
                    'value' => $strValue,
                ]);

                return null;
            }

            return $strValue;
        }

        if (in_array($type, self::GEN_BOOLEAN_TYPES)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if (in_array($type, self::GEN_DATE_TYPES)) {
            $strValue = (string) $value;
            $date = \DateTime::createFromFormat('Y-m-d', $strValue);

            if (! $date || $date->format('Y-m-d') !== $strValue) {
                $warnings[] = __(':field: AI provided invalid date ":value". Field left empty.', [
                    'field' => $field->display(),
                    'value' => $strValue,
                ]);

                return null;
            }

            return $strValue;
        }

        if (in_array($type, self::GEN_RECURSIVE_TYPES)) {
            if (! is_array($value)) {
                return null;
            }

            return $this->mapReplicatorData($value, $field, $warnings);
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    private function mapReplicatorData(array $sets, \Statamic\Fields\Field $field, array &$warnings): array
    {
        $result = [];
        $fieldtype = $field->fieldtype();

        foreach ($sets as $set) {
            if (! is_array($set)) {
                continue;
            }

            if ($field->type() === 'grid') {
                // Grid rows don't have a "type" key
                $gridFields = $fieldtype->fields();
                $row = ['id' => Str::uuid()->toString()];

                foreach ($gridFields->all() as $subField) {
                    $subHandle = $subField->handle();

                    if (array_key_exists($subHandle, $set)) {
                        $mapped = $this->mapFieldValue($set[$subHandle], $subField, $warnings);

                        if ($mapped !== null) {
                            $row[$subHandle] = $mapped;
                        }
                    }
                }

                $result[] = $row;
            } else {
                // Replicator/components — sets have a "type" key
                $setType = $set['type'] ?? null;

                if (! $setType) {
                    continue;
                }

                $mappedSet = [
                    'id' => Str::uuid()->toString(),
                    'type' => $setType,
                    'enabled' => true,
                ];

                try {
                    $setFields = $fieldtype->fields($setType);
                } catch (\Exception) {
                    $warnings[] = __('Unknown set type ":type" in :field. Skipped.', [
                        'type' => $setType,
                        'field' => $field->display(),
                    ]);

                    continue;
                }

                foreach ($setFields->all() as $subField) {
                    $subHandle = $subField->handle();

                    if (array_key_exists($subHandle, $set)) {
                        $mapped = $this->mapFieldValue($set[$subHandle], $subField, $warnings);

                        if ($mapped !== null) {
                            $mappedSet[$subHandle] = $mapped;
                        }
                    }
                }

                $result[] = $mappedSet;
            }
        }

        return $result;
    }

    private function mapLinkFieldValue(mixed $value, \Statamic\Fields\Field $field, array &$warnings): ?string
    {
        $str = is_string($value) ? trim($value) : (string) $value;

        if ($str === '') {
            return null;
        }

        if ($str === '/') {
            return '/';
        }

        if (preg_match('/^entry::[0-9a-f-]{36}$/i', $str)) {
            return $str;
        }

        if (str_starts_with($str, 'asset::')) {
            return $str;
        }

        if ($str === '@child') {
            return $str;
        }

        if (filter_var($str, FILTER_VALIDATE_URL)) {
            return $str;
        }

        if (str_starts_with($str, '/') && strlen($str) > 1) {
            return $str;
        }

        $warnings[] = __(':field: link value ":value" is not a valid URL or Statamic link reference.', [
            'field' => $field->display(),
            'value' => $str,
        ]);

        return null;
    }

    private function mapVideoFieldValue(mixed $value, array &$warnings): ?string
    {
        $str = is_string($value) ? trim($value) : (string) $value;

        if ($str === '') {
            return null;
        }

        if (filter_var($str, FILTER_VALIDATE_URL)) {
            return $str;
        }

        if (preg_match('#^https?://(www\.)?(youtube\.com|youtu\.be)\S+#i', $str)) {
            return $str;
        }

        $warnings[] = __('Invalid video URL: :value', ['value' => $str]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     * @param  array<string, string>  $warnings
     */
    private function applyMandatoryFieldFallbacks(array &$data, array &$displayData, Blueprint $blueprint, array &$warnings, string $prompt): void
    {
        foreach ($blueprint->fields()->all() as $field) {
            $this->applyMandatoryFieldFallbackForField($field, $data, $displayData, $warnings, $prompt);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     * @param  array<string, string>  $warnings
     */
    private function applyMandatoryFieldFallbackForField(Field $field, array &$data, array &$displayData, array &$warnings, string $prompt): void
    {
        $type = $field->type();
        $handle = $field->handle();

        if ($type === 'section' || in_array($type, self::GEN_SKIP_TYPES, true)) {
            return;
        }

        if (in_array($type, self::GEN_GROUP_TYPES, true)) {
            if (! isset($data[$handle]) || ! is_array($data[$handle])) {
                if (! $field->isRequired()) {
                    return;
                }
                $data[$handle] = [];
                $displayData[$handle] = [];
            }
            foreach ($field->fieldtype()->fields()->all() as $sub) {
                $this->applyMandatoryFieldFallbackForField($sub, $data[$handle], $displayData[$handle], $warnings, $prompt);
            }

            return;
        }

        if (! $field->isRequired()) {
            return;
        }

        if (! $this->generatableFieldMissingOrEmpty($field, $data, $handle)) {
            return;
        }

        if (in_array($type, self::GEN_TEXT_TYPES, true)) {
            $text = $this->syntheticTextForRequiredField($field, $prompt);
            $cleaned = strip_tags($this->aiService->cleanResult($text));
            $data[$handle] = $cleaned;
            $displayData[$handle] = $cleaned;
            $this->requiredWasAutofilledWarning($field, $warnings);

            return;
        }

        if (in_array($type, self::GEN_HTML_TYPES, true)) {
            $html = '<p>'.e($this->syntheticTextForRequiredField($field, $prompt)).'</p>';
            $mapped = $this->mapFieldValue($html, $field, $warnings);

            if ($mapped !== null) {
                $data[$handle] = $mapped;
                $displayData[$handle] = $html;
                $this->requiredWasAutofilledWarning($field, $warnings);
            }

            return;
        }

        if (in_array($type, self::GEN_CHOICE_TYPES, true)) {
            $first = $this->firstSelectOptionValue($field);

            if ($first !== null && $first !== '') {
                $data[$handle] = $first;
                $displayData[$handle] = $first;
                $this->requiredWasAutofilledWarning($field, $warnings);
            }

            return;
        }

        if (in_array($type, self::GEN_BOOLEAN_TYPES, true)) {
            $data[$handle] = false;
            $displayData[$handle] = false;
            $this->requiredWasAutofilledWarning($field, $warnings);

            return;
        }

        if (in_array($type, self::GEN_DATE_TYPES, true)) {
            $d = now()->format('Y-m-d');
            $data[$handle] = $d;
            $displayData[$handle] = $d;
            $this->requiredWasAutofilledWarning($field, $warnings);

            return;
        }

        if (in_array($type, self::GEN_LINK_TYPES, true)) {
            $mapped = $this->mapLinkFieldValue('/', $field, $warnings);

            if ($mapped !== null) {
                $data[$handle] = $mapped;
                $displayData[$handle] = $mapped;
                $this->requiredWasAutofilledWarning($field, $warnings);
            }

            return;
        }

        if ($type === 'video') {
            $warnings[] = __(':field is required but no video URL was provided. Add a URL in the editor after saving.', [
                'field' => $field->display(),
            ]);

            return;
        }

        if (in_array($type, self::GEN_RECURSIVE_TYPES, true)) {
            $warnings[] = __(':field is required but has no blocks. Add content in the editor after saving.', [
                'field' => $field->display(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function generatableFieldMissingOrEmpty(Field $field, array $data, string $handle): bool
    {
        if (! array_key_exists($handle, $data)) {
            return true;
        }

        $value = $data[$handle];
        $type = $field->type();

        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (in_array($type, self::GEN_TEXT_TYPES, true) && is_string($value) && trim($value) === '') {
            return true;
        }

        if (in_array($type, self::GEN_HTML_TYPES, true)) {
            return $this->isBardStoredValueEmpty($value);
        }

        if (in_array($type, self::GEN_RECURSIVE_TYPES, true)) {
            return ! is_array($value) || $value === [];
        }

        return false;
    }

    private function isBardStoredValueEmpty(mixed $value): bool
    {
        if (! is_array($value)) {
            return true;
        }

        if (($value['type'] ?? '') === 'doc') {
            $content = $value['content'] ?? [];

            return $content === [] || $content === null;
        }

        return false;
    }

    private function syntheticTextForRequiredField(Field $field, string $prompt): string
    {
        $p = trim($prompt);

        if ($p === '' || preg_match('/^(do nothing|nothing|nichts|mach nichts|tu rien|ne rien faire)\.?$/iu', $p)) {
            $label = $field->display();

            return $label !== '' ? $label : (string) __('Untitled entry');
        }

        $limit = $field->handle() === 'title' ? 100 : 240;

        return Str::limit($p, $limit);
    }

    private function firstSelectOptionValue(Field $field): ?string
    {
        $options = $field->config()['options'] ?? [];

        if (! is_array($options) || $options === []) {
            return null;
        }

        foreach ($options as $key => $opt) {
            if (is_array($opt)) {
                $v = $opt['value'] ?? $opt['key'] ?? null;

                if ($v !== null && $v !== '') {
                    return (string) $v;
                }
            } elseif (is_string($key) && $key !== '') {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $warnings
     */
    private function requiredWasAutofilledWarning(Field $field, array &$warnings): void
    {
        $warnings[] = __(':field was missing or empty but is required; a safe default was applied.', [
            'field' => $field->display(),
        ]);
    }

    private function createEntry(
        string $collectionHandle,
        string $blueprintHandle,
        string $locale,
        array $data,
    ): StatamicEntry {
        $entry = Entry::make()
            ->collection($collectionHandle)
            ->blueprint($blueprintHandle)
            ->locale($locale);

        $title = $data['title'] ?? null;
        $slug = is_string($title) && $title !== ''
            ? Str::slug($title)
            : 'untitled-'.now()->timestamp;

        $entry->slug($slug);
        $entry->data($data);
        $entry->published(false);
        $entry->save();

        return $entry;
    }
}
