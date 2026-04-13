<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection;
use Statamic\Facades\YAML;

/**
 * Stores and retrieves BOLD agent settings (per-set hints) for replicator / components sets.
 *
 * For each set handle the author can configure:
 *   - ai_description : one-paragraph description of what the block is
 *   - when_to_use    : array of short trigger phrases describing ideal usage
 *
 * Both are optional. When absent, the entry is omitted from the LLM
 * catalog and the previous behaviour is preserved.
 *
 * Storage: configurable via statamic-ai-assistant.set_hints_path (default:
 * content/statamic-ai-assistant/set-hints.yaml). Legacy storage path is
 * migrated automatically on first read when the configured file is missing.
 *
 * File shape (new):
 *   hints:
 *     hero:
 *       ai_description: "Large, visually prominent opener …"
 *       when_to_use:
 *         - "Page introduction immediately after hero"
 *         - "Executive summary"
 *
 * File shape (legacy, still parsed):
 *   hints:
 *     hero: "Use for large visual openers."
 */
class SetHintsService
{
    /** @var array<string, array{ai_description: string, when_to_use: array<int, string>}>|null */
    private ?array $cache = null;

    /**
     * Absolute path to the YAML file storing hints.
     */
    public function storagePath(): string
    {
        return $this->resolvedStoragePath();
    }

    /**
     * Legacy path used before set_hints_path was configurable.
     */
    public function legacyStoragePath(): string
    {
        return storage_path('app/statamic-ai-assistant/set-hints.yaml');
    }

    protected function resolvedStoragePath(): string
    {
        $path = config('statamic-ai-assistant.set_hints_path');

        if (! is_string($path) || $path === '') {
            return base_path('content/statamic-ai-assistant/set-hints.yaml');
        }

        return $this->toAbsoluteProjectPath($path);
    }

    protected function toAbsoluteProjectPath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * If the configured file is missing but the legacy storage file exists, copy once.
     */
    protected function migrateLegacyIfNeeded(): void
    {
        $path = $this->resolvedStoragePath();
        $legacy = $this->legacyStoragePath();

        if (is_file($path) || ! is_file($legacy)) {
            return;
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (! @copy($legacy, $path)) {
            Log::warning('Could not migrate set-hints.yaml from legacy storage path.', [
                'from' => $legacy,
                'to' => $path,
            ]);

            return;
        }

        Log::info('Migrated statamic-ai-assistant set-hints.yaml to versioned path.', [
            'path' => $path,
        ]);

        @unlink($legacy);
    }

    /**
     * All saved hints, keyed by set handle.
     *
     * @return array<string, array{ai_description: string, when_to_use: array<int, string>}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->migrateLegacyIfNeeded();

        $path = $this->storagePath();

        if (! is_file($path)) {
            return $this->cache = [];
        }

        try {
            $raw = (string) file_get_contents($path);
            $parsed = $raw !== '' ? YAML::parse($raw) : [];
        } catch (\Throwable $e) {
            Log::warning('Failed to parse set-hints.yaml', ['error' => $e->getMessage()]);

            return $this->cache = [];
        }

        $hints = [];

        if (is_array($parsed) && isset($parsed['hints']) && is_array($parsed['hints'])) {
            foreach ($parsed['hints'] as $handle => $value) {
                if (! is_string($handle) || $handle === '') {
                    continue;
                }

                $normalized = $this->normalizeHint($value);

                if ($normalized !== null) {
                    $hints[$handle] = $normalized;
                }
            }
        }

        return $this->cache = $hints;
    }

    /**
     * Return the hint structure for a single set (or null when none set).
     *
     * @return array{ai_description: string, when_to_use: array<int, string>}|null
     */
    public function forSet(string $setHandle): ?array
    {
        return $this->all()[$setHandle] ?? null;
    }

    /**
     * Persist the full hint map. Empty entries are removed.
     *
     * Accepts items either as a structured array
     *   ['ai_description' => '…', 'when_to_use' => ['…', '…']]
     * or as a plain string (treated as ai_description only, for legacy calls).
     *
     * @param  array<string, mixed>  $hints
     */
    public function save(array $hints): void
    {
        $clean = [];

        foreach ($hints as $handle => $value) {
            if (! is_string($handle) || $handle === '') {
                continue;
            }

            $normalized = $this->normalizeHint($value);

            if ($normalized !== null) {
                $clean[$handle] = $normalized;
            }
        }

        ksort($clean);

        $this->migrateLegacyIfNeeded();

        $path = $this->storagePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, YAML::dump(['hints' => $clean]));

        $this->cache = $clean;
    }

    /**
     * Enumerate every replicator / components set across all collection
     * blueprints, grouped by set handle, and attach any saved hint.
     *
     * @return array<int, array{
     *   handle: string,
     *   title: string,
     *   ai_description: string,
     *   when_to_use: array<int, string>,
     *   locations: array<int, array{collection: string, blueprint: string, field: string}>
     * }>
     */
    public function discoverSets(): array
    {
        $hints = $this->all();
        $sets = [];

        foreach (Collection::all() as $collection) {
            $collectionHandle = (string) $collection->handle();
            $collectionTitle = (string) $collection->title();

            foreach ($collection->entryBlueprints()->reject->hidden() as $blueprint) {
                $blueprintHandle = (string) $blueprint->handle();
                $blueprintTitle = (string) $blueprint->title();

                foreach ($blueprint->fields()->all() as $field) {
                    $this->collectSetsFromField(
                        $field,
                        $collectionHandle,
                        $collectionTitle,
                        $blueprintHandle,
                        $blueprintTitle,
                        $sets
                    );
                }
            }
        }

        $rows = [];

        foreach ($sets as $handle => $meta) {
            $existing = $hints[$handle] ?? ['ai_description' => '', 'when_to_use' => []];

            $rows[] = [
                'handle' => $handle,
                'title' => $meta['title'],
                'ai_description' => $existing['ai_description'],
                'when_to_use' => $existing['when_to_use'],
                'locations' => array_values($meta['locations']),
            ];
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['handle'], $b['handle']));

        return $rows;
    }

    /**
     * Inspect a single set across all blueprints and gather everything
     * the LLM needs to suggest a description and usage tips.
     *
     * Returns null when the handle is not found in any blueprint.
     *
     * @return array{
     *   handle: string,
     *   title: string,
     *   instructions: string,
     *   inner_fields: array<int, array{handle: string, type: string, display: string, instructions: string, options: array<int, string>}>,
     *   locations: array<int, array{collection: string, blueprint: string, field: string}>
     * }|null
     */
    public function collectSetContext(string $setHandle): ?array
    {
        if ($setHandle === '') {
            return null;
        }

        $title = '';
        $instructions = '';
        $innerFields = [];
        $locations = [];
        $sampledInner = false;

        foreach (Collection::all() as $collection) {
            $collectionHandle = (string) $collection->handle();
            $collectionTitle = (string) $collection->title();

            foreach ($collection->entryBlueprints()->reject->hidden() as $blueprint) {
                $blueprintHandle = (string) $blueprint->handle();
                $blueprintTitle = (string) $blueprint->title();

                foreach ($blueprint->fields()->all() as $field) {
                    $this->matchSetInField(
                        $field,
                        $setHandle,
                        $collectionHandle,
                        $collectionTitle,
                        $blueprintHandle,
                        $blueprintTitle,
                        $title,
                        $instructions,
                        $innerFields,
                        $locations,
                        $sampledInner
                    );
                }
            }
        }

        if ($locations === [] && $title === '') {
            return null;
        }

        if ($title === '') {
            $title = \Illuminate\Support\Str::headline(str_replace('_', ' ', $setHandle));
        }

        return [
            'handle' => $setHandle,
            'title' => $title,
            'instructions' => $instructions,
            'inner_fields' => $innerFields,
            'locations' => array_values($locations),
        ];
    }

    /**
     * Recursive helper for collectSetContext — mirrors collectSetsFromField but
     * captures inner-field metadata and instructions for one specific handle.
     *
     * @param  array<int, array{handle: string, type: string, display: string, instructions: string, options: array<int, string>}>  $innerFields
     * @param  array<string, array{collection: string, blueprint: string, field: string}>  $locations
     */
    private function matchSetInField(
        \Statamic\Fields\Field $field,
        string $targetHandle,
        string $collectionHandle,
        string $collectionTitle,
        string $blueprintHandle,
        string $blueprintTitle,
        string &$title,
        string &$instructions,
        array &$innerFields,
        array &$locations,
        bool &$sampledInner,
    ): void {
        $type = $field->type();

        if (in_array($type, ['replicator', 'components'], true)) {
            try {
                $fieldtype = $field->fieldtype();
                $setsConfig = $fieldtype->flattenedSetsConfig();
            } catch (\Throwable) {
                return;
            }

            foreach ($setsConfig as $setHandle => $setConfig) {
                $setHandle = (string) $setHandle;

                if ($setHandle === $targetHandle) {
                    if ($title === '' && is_array($setConfig) && isset($setConfig['display']) && is_string($setConfig['display'])) {
                        $title = $setConfig['display'];
                    }

                    if ($instructions === '' && is_array($setConfig) && isset($setConfig['instructions']) && is_string($setConfig['instructions'])) {
                        $instructions = trim($setConfig['instructions']);
                    }

                    $locKey = $collectionHandle.'::'.$blueprintHandle.'::'.$field->handle();
                    $locations[$locKey] = [
                        'collection' => $collectionTitle !== '' ? $collectionTitle : $collectionHandle,
                        'blueprint' => $blueprintTitle !== '' ? $blueprintTitle : $blueprintHandle,
                        'field' => (string) ($field->display() ?: $field->handle()),
                    ];

                    if (! $sampledInner) {
                        try {
                            $setFields = $fieldtype->fields($setHandle);
                            foreach ($setFields->all() as $f) {
                                $innerFields[] = [
                                    'handle' => (string) $f->handle(),
                                    'type' => (string) $f->type(),
                                    'display' => (string) ($f->display() ?: $f->handle()),
                                    'instructions' => (string) ($f->instructions() ?? ''),
                                    'options' => $this->extractFieldOptions($f),
                                ];
                            }
                            if ($innerFields !== []) {
                                $sampledInner = true;
                            }
                        } catch (\Throwable) {
                            // ignore — best-effort sampling
                        }
                    }
                }

                // Recurse into the set's inner fields to find nested matches.
                try {
                    $innerFieldsForRecursion = $fieldtype->fields($setHandle);
                } catch (\Throwable) {
                    continue;
                }

                foreach ($innerFieldsForRecursion->all() as $innerField) {
                    $this->matchSetInField(
                        $innerField,
                        $targetHandle,
                        $collectionHandle,
                        $collectionTitle,
                        $blueprintHandle,
                        $blueprintTitle,
                        $title,
                        $instructions,
                        $innerFields,
                        $locations,
                        $sampledInner
                    );
                }
            }

            return;
        }

        if ($type === 'grid') {
            try {
                $innerFieldsForRecursion = $field->fieldtype()->fields();
            } catch (\Throwable) {
                return;
            }

            foreach ($innerFieldsForRecursion->all() as $innerField) {
                $this->matchSetInField(
                    $innerField,
                    $targetHandle,
                    $collectionHandle,
                    $collectionTitle,
                    $blueprintHandle,
                    $blueprintTitle,
                    $title,
                    $instructions,
                    $innerFields,
                    $locations,
                    $sampledInner
                );
            }
        }
    }

    /**
     * Pull human-readable option labels (or values) from a field config.
     *
     * @return array<int, string>
     */
    private function extractFieldOptions(\Statamic\Fields\Field $field): array
    {
        $options = $field->get('options', []);

        if (! is_array($options) || $options === []) {
            return [];
        }

        $out = [];

        foreach ($options as $key => $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            } elseif (is_string($key) && $key !== '') {
                $out[] = $key;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 12);
    }

    /**
     * Coerce a hint value into the canonical structure, or return null
     * when the resulting entry would be empty.
     *
     * @return array{ai_description: string, when_to_use: array<int, string>}|null
     */
    private function normalizeHint(mixed $value): ?array
    {
        if (is_string($value)) {
            $desc = trim($value);

            return $desc !== '' ? ['ai_description' => $desc, 'when_to_use' => []] : null;
        }

        if (! is_array($value)) {
            return null;
        }

        $desc = isset($value['ai_description']) && is_string($value['ai_description'])
            ? trim($value['ai_description'])
            : '';

        $tipsRaw = $value['when_to_use'] ?? [];
        $tips = [];

        if (is_array($tipsRaw)) {
            foreach ($tipsRaw as $tip) {
                if (! is_string($tip)) {
                    continue;
                }

                $t = trim($tip);

                if ($t !== '') {
                    $tips[] = $t;
                }
            }
        }

        if ($desc === '' && $tips === []) {
            return null;
        }

        return [
            'ai_description' => $desc,
            'when_to_use' => array_values(array_unique($tips)),
        ];
    }

    /**
     * Recursively walk a field tree, collecting every replicator / components
     * set we encounter into the $sets accumulator keyed by set handle.
     *
     * @param  array<string, array{title: string, locations: array<string, array{collection: string, blueprint: string, field: string}>}>  $sets
     */
    private function collectSetsFromField(
        \Statamic\Fields\Field $field,
        string $collectionHandle,
        string $collectionTitle,
        string $blueprintHandle,
        string $blueprintTitle,
        array &$sets,
    ): void {
        $type = $field->type();

        if (in_array($type, ['replicator', 'components'], true)) {
            try {
                $fieldtype = $field->fieldtype();
                $setsConfig = $fieldtype->flattenedSetsConfig();
            } catch (\Throwable) {
                return;
            }

            foreach ($setsConfig as $setHandle => $setConfig) {
                $setHandle = (string) $setHandle;

                if ($setHandle === '') {
                    continue;
                }

                $title = is_array($setConfig) && isset($setConfig['display']) && is_string($setConfig['display'])
                    ? $setConfig['display']
                    : \Illuminate\Support\Str::headline(str_replace('_', ' ', $setHandle));

                if (! isset($sets[$setHandle])) {
                    $sets[$setHandle] = [
                        'title' => $title,
                        'locations' => [],
                    ];
                }

                $locationKey = $collectionHandle.'::'.$blueprintHandle.'::'.$field->handle();

                $sets[$setHandle]['locations'][$locationKey] = [
                    'collection' => $collectionTitle !== '' ? $collectionTitle : $collectionHandle,
                    'blueprint' => $blueprintTitle !== '' ? $blueprintTitle : $blueprintHandle,
                    'field' => (string) ($field->display() ?: $field->handle()),
                ];

                try {
                    $innerFields = $fieldtype->fields($setHandle);
                } catch (\Throwable) {
                    continue;
                }

                foreach ($innerFields->all() as $innerField) {
                    $this->collectSetsFromField(
                        $innerField,
                        $collectionHandle,
                        $collectionTitle,
                        $blueprintHandle,
                        $blueprintTitle,
                        $sets
                    );
                }
            }

            return;
        }

        if ($type === 'grid') {
            try {
                $innerFields = $field->fieldtype()->fields();
            } catch (\Throwable) {
                return;
            }

            foreach ($innerFields->all() as $innerField) {
                $this->collectSetsFromField(
                    $innerField,
                    $collectionHandle,
                    $collectionTitle,
                    $blueprintHandle,
                    $blueprintTitle,
                    $sets
                );
            }
        }
    }
}
