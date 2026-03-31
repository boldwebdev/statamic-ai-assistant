<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Services\Concerns\TranslatesFields;
use DeepL\TranslateTextOptions;
use Illuminate\Support\Str;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;

class EntryTranslator
{
    use TranslatesFields;

    private const REFERENCE_TYPES = ['entries', 'link'];

    private DeeplService $deepl;

    private EntryReferenceResolver $referenceResolver;

    private string $sourceLang;

    private string $targetLang;

    public function __construct(
        DeeplService $deeplService,
        EntryReferenceResolver $entryReferenceResolver,
    ) {
        $this->deepl = $deeplService;
        $this->referenceResolver = $entryReferenceResolver;
        $this->referenceResolver->setEntryTranslator($this);
    }

    public function translateEntry(
        mixed $originEntry,
        string $targetSite,
        mixed $existingTarget = null,
        int $currentDepth = 0,
        int $maxDepth = 1,
    ): StatamicEntry {
        $this->sourceLang = $originEntry->locale();
        $this->targetLang = $targetSite;

        $blueprint = $originEntry->blueprint();
        $originData = $originEntry->data()->toArray();
        $fields = $this->getFieldDefinitions($blueprint);

        // Phase 1: Collect all translatable texts into a flat list
        $this->resetCollector();
        $dataWithPlaceholders = $this->collectFromEntryFields($originData, $fields, $currentDepth, $maxDepth);

        // Phase 2: One single API call for ALL texts in this entry
        $translatedTexts = [];
        if (! empty($this->collectedTexts)) {
            $translatedTexts = $this->deepl->translateBatch(
                $this->prepareTextsForApi(),
                $this->sourceLang,
                $this->targetLang,
                [TranslateTextOptions::TAG_HANDLING => 'html'],
            );

            $translatedTexts = $this->decodeTranslatedTexts($translatedTexts);
        }

        // Phase 3: Replace placeholders with translated texts
        $translatedData = $this->replaceInFields($dataWithPlaceholders, $translatedTexts);

        $translatedTitle = $translatedData['title'] ?? '';
        if (is_string($translatedTitle) && $translatedTitle !== '') {
            $translatedSlug = Str::slug($translatedTitle);
        } else {
            $translatedSlug = Str::slug($originEntry->slug());
        }

        // Resolve entry references (ID remapping, not translation)
        $translatedData = $this->resolveReferences($translatedData, $fields, $targetSite, $currentDepth, $maxDepth);

        $targetEntry = $existingTarget;
        if (! $targetEntry) {
            $targetEntry = Entry::make()
                ->collection($originEntry->collectionHandle())
                ->blueprint($blueprint->handle())
                ->locale($targetSite)
                ->origin($originEntry->id());
        }

        $targetEntry->slug($translatedSlug);
        $targetEntry->data($translatedData);

        if ($originEntry->published()) {
            $targetEntry->published(true);
        }

        $targetEntry->save();

        return $targetEntry;
    }

    // ── Entry-specific collect (handles REFERENCE_TYPES pass-through) ─

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{type: string, localizable: bool, sets?: array, fields?: array}>  $fields
     * @return array<string, mixed>
     */
    private function collectFromEntryFields(array $data, array $fields, int $currentDepth, int $maxDepth): array
    {
        $result = [];

        foreach ($data as $handle => $value) {
            if ($value === null || $value === '') {
                $result[$handle] = $value;

                continue;
            }

            $fieldDef = $fields[$handle] ?? null;
            $fieldType = $fieldDef['type'] ?? null;
            $isLocalizable = $fieldDef['localizable'] ?? true;

            if (! $isLocalizable && ! in_array($fieldType, self::REFERENCE_TYPES)) {
                continue;
            }

            $result[$handle] = $this->collectFromField($value, $fieldType, $fieldDef);
        }

        return $result;
    }

    // ── Phase 3b: Resolve entry references ───────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{type: string, localizable: bool, sets?: array, fields?: array}>  $fields
     * @return array<string, mixed>
     */
    private function resolveReferences(array $data, array $fields, string $targetSite, int $currentDepth, int $maxDepth): array
    {
        foreach ($data as $handle => &$value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $fieldDef = $fields[$handle] ?? null;
            $fieldType = $fieldDef['type'] ?? null;

            if (in_array($fieldType, self::REFERENCE_TYPES)) {
                $value = $this->referenceResolver->resolve($value, $targetSite, $currentDepth, $maxDepth);

                continue;
            }

            if (in_array($fieldType, self::BARD_TYPES) && is_array($value)) {
                $value = $this->resolveBardEntryLinks($value, $targetSite, $currentDepth, $maxDepth);

                continue;
            }

            if (in_array($fieldType, self::RECURSIVE_TYPES) && is_array($value)) {
                $value = $this->resolveReplicatorReferences($value, $fieldDef, $targetSite, $currentDepth, $maxDepth);

                continue;
            }
        }

        return $data;
    }

    /**
     * @param  array<mixed>  $nodes
     * @return array<mixed>
     */
    private function resolveBardEntryLinks(array $nodes, string $targetSite, int $currentDepth, int $maxDepth): array
    {
        foreach ($nodes as &$node) {
            if (! is_array($node)) {
                continue;
            }

            if (isset($node['marks']) && is_array($node['marks'])) {
                foreach ($node['marks'] as &$mark) {
                    if (($mark['type'] ?? null) === 'link' && isset($mark['attrs']['href'])) {
                        $mark['attrs']['href'] = (string) $this->referenceResolver->resolve(
                            $mark['attrs']['href'],
                            $targetSite,
                            $currentDepth,
                            $maxDepth,
                        );
                    }
                }
            }

            if (isset($node['content']) && is_array($node['content'])) {
                $node['content'] = $this->resolveBardEntryLinks($node['content'], $targetSite, $currentDepth, $maxDepth);
            }
        }

        return $nodes;
    }

    /**
     * @param  array<mixed>  $sets
     * @param  array{sets?: array, fields?: array}|null  $fieldDef
     * @return array<mixed>
     */
    private function resolveReplicatorReferences(array $sets, ?array $fieldDef, string $targetSite, int $currentDepth, int $maxDepth): array
    {
        $setsDefs = $fieldDef['sets'] ?? null;
        $gridFieldsDefs = $fieldDef['fields'] ?? null;

        foreach ($sets as &$set) {
            if (! is_array($set)) {
                continue;
            }

            $setType = $set['type'] ?? null;
            $subFields = null;

            if ($setsDefs !== null && $setType !== null) {
                $subFields = $setsDefs[$setType] ?? null;
            } elseif ($gridFieldsDefs !== null) {
                $subFields = $gridFieldsDefs;
            }

            foreach ($set as $key => &$value) {
                if (in_array($key, ['id', 'type', 'enabled']) || $value === null || $value === '' || $value === []) {
                    continue;
                }

                if ($subFields !== null) {
                    $subFieldType = $subFields[$key]['type'] ?? null;
                    $subFieldDef = $subFields[$key] ?? null;

                    if (in_array($subFieldType, self::REFERENCE_TYPES)) {
                        $value = $this->referenceResolver->resolve($value, $targetSite, $currentDepth, $maxDepth);

                        continue;
                    }

                    if (in_array($subFieldType, self::BARD_TYPES) && is_array($value)) {
                        $value = $this->resolveBardEntryLinks($value, $targetSite, $currentDepth, $maxDepth);

                        continue;
                    }

                    if (in_array($subFieldType, self::RECURSIVE_TYPES) && is_array($value)) {
                        $value = $this->resolveReplicatorReferences($value, $subFieldDef, $targetSite, $currentDepth, $maxDepth);

                        continue;
                    }
                } else {
                    $value = $this->resolveReplicatorReferenceFallback($value, $targetSite, $currentDepth, $maxDepth);
                }
            }
        }

        return $sets;
    }

    private function resolveReplicatorReferenceFallback(mixed $value, string $targetSite, int $currentDepth, int $maxDepth): mixed
    {
        if (is_string($value) && $this->looksLikeEntryReference($value)) {
            return (string) $this->referenceResolver->resolve($value, $targetSite, $currentDepth, $maxDepth);
        }

        if (is_array($value)) {
            if ($this->isBardContent($value)) {
                return $this->resolveBardEntryLinks($value, $targetSite, $currentDepth, $maxDepth);
            }

            if ($this->isUuidArray($value)) {
                return $this->referenceResolver->resolve($value, $targetSite, $currentDepth, $maxDepth);
            }

            if ($this->isNestedSets($value)) {
                return $this->resolveReplicatorReferences($value, null, $targetSite, $currentDepth, $maxDepth);
            }
        }

        return $value;
    }
}
