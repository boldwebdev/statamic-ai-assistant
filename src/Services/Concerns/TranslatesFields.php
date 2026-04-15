<?php

namespace BoldWeb\StatamicAiAssistant\Services\Concerns;

use Statamic\Fields\Blueprint;

trait TranslatesFields
{
    private const TRANSLATABLE_TYPES = ['text', 'textarea', 'ai_text', 'ai_textarea'];

    private const BARD_TYPES = ['bard'];

    private const RECURSIVE_TYPES = ['replicator', 'grid', 'components'];

    private const SKIP_TYPES = ['assets', 'date', 'toggle', 'section', 'color', 'select', 'button_group', 'terms', 'video'];

    /** @var array<string, string> ProseMirror mark type → HTML tag */
    private const MARK_TO_TAG = [
        'bold' => 'b',
        'italic' => 'i',
        'underline' => 'u',
        'strike' => 's',
        'code' => 'code',
        'superscript' => 'sup',
        'subscript' => 'sub',
        'small' => 'small',
    ];

    /** @var array<string, string> HTML tag → ProseMirror mark type */
    private const TAG_TO_MARK = [
        'b' => 'bold',
        'strong' => 'bold',
        'i' => 'italic',
        'em' => 'italic',
        'u' => 'underline',
        's' => 'strike',
        'del' => 'strike',
        'code' => 'code',
        'sup' => 'superscript',
        'sub' => 'subscript',
        'small' => 'small',
    ];

    /** @var array<int, string> Collected texts to translate in one batch */
    private array $collectedTexts = [];

    /** @var array<int, bool> Tracks which collected texts are HTML (from merged Bard content) */
    private array $htmlTexts = [];

    private int $textIndex = 0;

    // ── Phase 1: Collect ─────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{type: string, localizable: bool, sets?: array, fields?: array}>  $fields
     * @return array<string, mixed>
     */
    private function collectFromFields(array $data, array $fields): array
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

            if (! $isLocalizable && ! $this->shouldForceTranslateHandle($handle)) {
                continue;
            }

            $result[$handle] = $this->collectFromField($value, $fieldType, $fieldDef);
        }

        return $result;
    }

    /**
     * Blueprint fields marked localizable: false are skipped unless listed in
     * config('deepl.force_translate_handles') (e.g. hero_title on shared hero fieldsets).
     */
    private function shouldForceTranslateHandle(string $handle): bool
    {
        /** @var array<int, string> $handles */
        $handles = config('deepl.force_translate_handles', ['hero_title']);

        return in_array($handle, $handles, true);
    }

    /**
     * @param  array{type: string, localizable: bool, sets?: array, fields?: array}|null  $fieldDef
     */
    private function collectFromField(mixed $value, ?string $fieldType, ?array $fieldDef = null): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return $value;
        }

        if (in_array($fieldType, self::TRANSLATABLE_TYPES)) {
            return is_string($value) ? $this->collectText($value) : $value;
        }

        if (in_array($fieldType, self::BARD_TYPES) && is_array($value)) {
            return $this->collectFromBard($value);
        }

        if (in_array($fieldType, self::RECURSIVE_TYPES) && is_array($value)) {
            return $this->collectFromReplicator($value, $fieldDef);
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $nodes
     * @return array<mixed>
     */
    private function collectFromBard(array $nodes): array
    {
        foreach ($nodes as &$node) {
            if (! is_array($node)) {
                continue;
            }

            if (isset($node['content']) && is_array($node['content'])) {
                if ($this->shouldMergeBardContent($node['content'])) {
                    $html = $this->bardContentToHtml($node['content']);
                    $placeholder = $this->collectText($html);
                    $this->htmlTexts[$placeholder] = true;
                    $node['content'] = ['__bard_merged__' => $placeholder];
                } else {
                    $node['content'] = $this->collectFromBard($node['content']);
                }

                continue;
            }

            if (($node['type'] ?? null) === 'text' && isset($node['text']) && trim($node['text']) !== '') {
                $node['text'] = $this->collectText($node['text']);
            }
        }

        return $nodes;
    }

    /**
     * @param  array<mixed>  $sets
     * @param  array{sets?: array, fields?: array}|null  $fieldDef
     * @return array<mixed>
     */
    private function collectFromReplicator(array $sets, ?array $fieldDef = null): array
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
                    $value = $this->collectFromField($value, $subFieldType, $subFieldDef);
                } else {
                    $value = $this->collectFromReplicatorFieldFallback($value);
                }
            }
        }

        return $sets;
    }

    private function collectFromReplicatorFieldFallback(mixed $value): mixed
    {
        if (is_string($value) && ! $this->looksLikeEntryReference($value)
            && ! $this->looksLikeFilePath($value) && ! $this->looksLikeUrl($value)) {
            return $this->collectText($value);
        }

        if (is_array($value)) {
            if ($this->isBardContent($value)) {
                return $this->collectFromBard($value);
            }

            if ($this->isNestedSets($value)) {
                return $this->collectFromReplicator($value);
            }
        }

        return $value;
    }

    private function collectText(string $text): int
    {
        $index = $this->textIndex;
        $this->collectedTexts[$index] = $text;
        $this->textIndex++;

        return $index;
    }

    // ── Phase 3a: Replace placeholders ───────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $translations
     * @return array<string, mixed>
     */
    private function replaceInFields(array $data, array $translations): array
    {
        $result = [];

        foreach ($data as $handle => $value) {
            $result[$handle] = $this->replaceInValue($value, $translations);
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $translations
     */
    private function replaceInValue(mixed $value, array $translations): mixed
    {
        if (is_int($value) && isset($translations[$value])) {
            return $translations[$value];
        }

        if (is_array($value)) {
            if (isset($value['__bard_merged__']) && is_int($value['__bard_merged__'])) {
                $html = $translations[$value['__bard_merged__']] ?? '';

                return $this->htmlToBardContent($html);
            }

            return array_map(fn ($v) => $this->replaceInValue($v, $translations), $value);
        }

        return $value;
    }

    private function resetCollector(): void
    {
        $this->collectedTexts = [];
        $this->htmlTexts = [];
        $this->textIndex = 0;
    }

    /**
     * @return array<int, string>
     */
    private function prepareTextsForApi(): array
    {
        $textsForApi = $this->collectedTexts;

        foreach ($textsForApi as $index => $text) {
            if (! isset($this->htmlTexts[$index])) {
                $textsForApi[$index] = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }

        return $textsForApi;
    }

    /**
     * @param  array<int, string>  $translatedTexts
     * @return array<int, string>
     */
    private function decodeTranslatedTexts(array $translatedTexts): array
    {
        foreach ($translatedTexts as $index => $text) {
            if (! isset($this->htmlTexts[$index])) {
                $translatedTexts[$index] = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
            }
        }

        return $translatedTexts;
    }

    // ── Bard HTML merge/parse ─────────────────────────────────────────

    /**
     * @param  array<mixed>  $content
     */
    private function shouldMergeBardContent(array $content): bool
    {
        $hasMarkedText = false;

        foreach ($content as $node) {
            if (! is_array($node)) {
                return false;
            }

            $type = $node['type'] ?? null;

            if ($type === 'text' || $type === 'hardBreak') {
                if ($type === 'text' && ! empty($node['marks'])) {
                    $hasMarkedText = true;
                }

                continue;
            }

            return false;
        }

        return $hasMarkedText;
    }

    /**
     * @param  array<mixed>  $content
     */
    private function bardContentToHtml(array $content): string
    {
        $html = '';

        foreach ($content as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? null;

            if ($type === 'hardBreak') {
                $html .= '<br>';

                continue;
            }

            if ($type !== 'text' || ! isset($node['text'])) {
                continue;
            }

            $text = htmlspecialchars($node['text'], ENT_QUOTES, 'UTF-8');
            $marks = $node['marks'] ?? [];

            foreach ($marks as $mark) {
                $tag = self::MARK_TO_TAG[$mark['type']] ?? null;

                if ($tag) {
                    $html .= "<{$tag}>";
                } elseif ($mark['type'] === 'link') {
                    $href = htmlspecialchars($mark['attrs']['href'] ?? '', ENT_QUOTES, 'UTF-8');
                    $attrs = " href=\"{$href}\"";

                    if (! empty($mark['attrs']['target'])) {
                        $attrs .= ' target="'.htmlspecialchars($mark['attrs']['target'], ENT_QUOTES, 'UTF-8').'"';
                    }

                    if (! empty($mark['attrs']['rel'])) {
                        $attrs .= ' rel="'.htmlspecialchars($mark['attrs']['rel'], ENT_QUOTES, 'UTF-8').'"';
                    }

                    $html .= "<a{$attrs}>";
                }
            }

            $html .= $text;

            foreach (array_reverse($marks) as $mark) {
                $tag = self::MARK_TO_TAG[$mark['type']] ?? null;

                if ($tag) {
                    $html .= "</{$tag}>";
                } elseif ($mark['type'] === 'link') {
                    $html .= '</a>';
                }
            }
        }

        return $html;
    }

    /**
     * @return array<mixed>
     */
    private function htmlToBardContent(string $html): array
    {
        if (trim($html) === '') {
            return [['type' => 'text', 'text' => '']];
        }

        $doc = new \DOMDocument;
        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>';
        @$doc->loadHTML($wrapped, LIBXML_NOERROR);

        $body = $doc->getElementsByTagName('body')->item(0);

        if (! $body) {
            return [['type' => 'text', 'text' => html_entity_decode($html, ENT_QUOTES, 'UTF-8')]];
        }

        $nodes = [];
        $this->walkDomNodes($body, [], $nodes);

        return $nodes ?: [['type' => 'text', 'text' => html_entity_decode($html, ENT_QUOTES, 'UTF-8')]];
    }

    /**
     * @param  array<array{type: string, attrs?: array<string, mixed>}>  $currentMarks
     * @param  array<mixed>  $result
     */
    private function walkDomNodes(\DOMNode $parent, array $currentMarks, array &$result): void
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = $child->textContent;

                if ($text === '') {
                    continue;
                }

                $node = ['type' => 'text', 'text' => $text];

                if (! empty($currentMarks)) {
                    $node['marks'] = $currentMarks;
                }

                $result[] = $node;
            } elseif ($child instanceof \DOMElement) {
                $tagName = strtolower($child->tagName);

                if ($tagName === 'br') {
                    $result[] = ['type' => 'hardBreak'];

                    continue;
                }

                $mark = $this->domElementToMark($child);
                $newMarks = $mark ? array_merge($currentMarks, [$mark]) : $currentMarks;
                $this->walkDomNodes($child, $newMarks, $result);
            }
        }
    }

    /**
     * @return array{type: string, attrs?: array<string, mixed>}|null
     */
    private function domElementToMark(\DOMElement $element): ?array
    {
        $tagName = strtolower($element->tagName);

        if (isset(self::TAG_TO_MARK[$tagName])) {
            return ['type' => self::TAG_TO_MARK[$tagName]];
        }

        if ($tagName === 'a') {
            $mark = ['type' => 'link', 'attrs' => ['href' => $element->getAttribute('href')]];

            if ($element->hasAttribute('target')) {
                $mark['attrs']['target'] = $element->getAttribute('target');
            }

            if ($element->hasAttribute('rel')) {
                $mark['attrs']['rel'] = $element->getAttribute('rel');
            }

            return $mark;
        }

        return null;
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * @return array<string, array{type: string, localizable: bool, sets?: array, fields?: array}>
     */
    private function getFieldDefinitions(Blueprint $blueprint): array
    {
        $result = [];

        foreach ($blueprint->fields()->all() as $field) {
            $result[$field->handle()] = $this->buildFieldDef($field);
        }

        return $result;
    }

    /**
     * @return array{type: string, localizable: bool, sets?: array, fields?: array}
     */
    private function buildFieldDef(\Statamic\Fields\Field $field): array
    {
        $def = [
            'type' => $field->type(),
            'localizable' => $field->isLocalizable(),
        ];

        if (in_array($field->type(), ['replicator', 'components'])) {
            $def['sets'] = [];
            $fieldtype = $field->fieldtype();

            foreach ($fieldtype->flattenedSetsConfig() as $setHandle => $setConfig) {
                $setFields = $fieldtype->fields($setHandle);
                $def['sets'][$setHandle] = $this->extractFieldDefs($setFields);
            }
        } elseif ($field->type() === 'grid') {
            $def['fields'] = $this->extractFieldDefs($field->fieldtype()->fields());
        }

        return $def;
    }

    /**
     * @return array<string, array{type: string, localizable: bool, sets?: array, fields?: array}>
     */
    private function extractFieldDefs(\Statamic\Fields\Fields $fields): array
    {
        $defs = [];

        foreach ($fields->all() as $field) {
            $defs[$field->handle()] = $this->buildFieldDef($field);
        }

        return $defs;
    }

    private function looksLikeEntryReference(string $value): bool
    {
        return str_starts_with($value, 'entry::')
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    private function looksLikeFilePath(string $value): bool
    {
        return str_contains($value, '/')
            && preg_match('/\.(pdf|jpg|jpeg|png|svg|gif|webp|mp4|mp3|doc|docx|xls|xlsx|ppt|pptx|gltf|glb|bin|zip|tar|gz|csv)$/i', $value);
    }

    private function looksLikeUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, 'mailto:');
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isBardContent(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        $first = reset($value);

        return is_array($first) && isset($first['type']) && in_array($first['type'], [
            'paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote',
            'codeBlock', 'hardBreak', 'horizontalRule', 'image', 'table', 'set',
        ]);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isUuidArray(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isNestedSets(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        $first = reset($value);

        return is_array($first) && (isset($first['type']) || isset($first['id']));
    }
}
