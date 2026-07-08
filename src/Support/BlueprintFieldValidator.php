<?php

namespace BoldWeb\StatamicAiAssistant\Support;

use Statamic\Exceptions\FieldtypeNotFoundException;
use Statamic\Facades\Collection;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\FieldtypeRepository;

/**
 * Strict validation for LLM-provided blueprint field definitions (adapted from
 * cboxdk/statamic-mcp). The expected shape per field is:
 *
 *   {"handle": "title", "field": {"type": "text", "display": "Title"}}
 *
 * No auto-wrapping: near-miss mistakes ("name" instead of "handle", "type" at
 * the top level, "fieldtype" instead of "type") get a targeted correction hint
 * so a small model can fix its own call. Fieldtypes must exist (unknown types
 * get the full list of available ones), referenced taxonomies/collections must
 * exist, and Antlers template expressions are stripped from string config
 * values so a blueprint can never smuggle template code into the site.
 */
class BlueprintFieldValidator
{
    /** Common near-miss config keys LLMs send incorrectly. */
    private const PARAM_CORRECTIONS = [
        'taxonomy' => 'taxonomies',
        'collection' => 'collections',
        'field_type' => 'type',
        'fieldtype' => 'type',
        'name' => 'handle',
    ];

    /**
     * @param  array<int|string, mixed>  $fields
     * @return array{ok: true, fields: array<int, array{handle: string, field: array<string, mixed>}>}|array{ok: false, error: string}
     */
    public function validate(array $fields): array
    {
        if ($fields === []) {
            return ['ok' => false, 'error' => 'Provide at least one field as {"handle": "...", "field": {"type": "..."}}.'];
        }

        $fieldtypes = app(FieldtypeRepository::class);
        $seenHandles = [];
        $validated = [];

        foreach (array_values($fields) as $index => $field) {
            if (! is_array($field)) {
                return $this->error("Field at index {$index} must be an object. Expected format: {\"handle\": \"title\", \"field\": {\"type\": \"text\", \"display\": \"Title\"}}");
            }

            // Fieldset import row: {"import": "hero", "prefix": "hero_"} pulls a
            // whole existing fieldset in by reference — the preferred way to
            // reuse shared field groups instead of redefining them.
            if (array_key_exists('import', $field)) {
                $importResult = $this->validateImportRow($field, $index);

                if (isset($importResult['error'])) {
                    return $importResult;
                }

                $validated[] = $importResult['row'];

                continue;
            }

            if (! isset($field['handle']) || ! is_string($field['handle']) || trim($field['handle']) === '') {
                $hint = isset($field['name']) ? ' Did you mean "handle"? You sent "name".' : '';

                return $this->error("Field at index {$index} must have a \"handle\" key (string).{$hint} Example: {\"handle\": \"title\", \"field\": {\"type\": \"text\"}}");
            }

            $handle = trim($field['handle']);

            if (preg_match('/^[a-z][a-z0-9_]*$/', $handle) !== 1) {
                return $this->error("Field handle \"{$handle}\" is invalid. Handles are snake_case: lowercase letters, digits and underscores, starting with a letter (e.g. \"hero_title\").");
            }

            if (isset($seenHandles[$handle])) {
                return $this->error("Duplicate field handle: \"{$handle}\". Each field must have a unique handle.");
            }
            $seenHandles[$handle] = true;

            // Single-field reference: {"handle": "hero", "field": "hero.hero_title"}
            // links one field from a fieldset instead of redefining it inline.
            if (isset($field['field']) && is_string($field['field'])) {
                $refError = $this->validateFieldReference($field['field'], $handle);

                if ($refError !== null) {
                    return $refError;
                }

                $validated[] = ['handle' => $handle, 'field' => trim($field['field'])];

                continue;
            }

            if (! isset($field['field']) || ! is_array($field['field'])) {
                $hint = array_key_exists('type', $field)
                    ? ' It looks like you put "type" at the top level. Move it inside a "field" object.'
                    : '';

                return $this->error("Field \"{$handle}\" is missing the \"field\" key.{$hint} Correct format: {\"handle\": \"{$handle}\", \"field\": {\"type\": \"text\", \"display\": \"...\"}}, or reference a fieldset field with \"field\": \"<fieldset>.<field>\".");
            }

            $config = $field['field'];

            foreach (self::PARAM_CORRECTIONS as $wrong => $correct) {
                if (isset($config[$wrong]) && ! isset($config[$correct])) {
                    return $this->error("Field \"{$handle}\" has \"{$wrong}\" in its config. Did you mean \"{$correct}\"? Rename \"{$wrong}\" to \"{$correct}\".");
                }
            }

            $type = $config['type'] ?? null;
            if (! is_string($type) || trim($type) === '') {
                return $this->error("Field \"{$handle}\" is missing \"type\" in its field config. Example: {\"handle\": \"{$handle}\", \"field\": {\"type\": \"text\"}}");
            }

            try {
                $fieldtypes->find($type);
            } catch (FieldtypeNotFoundException) {
                $available = $fieldtypes->handles()->values()->sort()->implode(', ');

                return $this->error("Unknown field type \"{$type}\" for field \"{$handle}\". Available types: {$available}");
            }

            if (isset($config['taxonomies']) && is_array($config['taxonomies'])) {
                foreach ($config['taxonomies'] as $taxHandle) {
                    if (is_string($taxHandle) && ! Taxonomy::find($taxHandle)) {
                        return $this->error("Field \"{$handle}\" references taxonomy \"{$taxHandle}\" which does not exist. Create it first with create_taxonomy.");
                    }
                }
            }

            if (isset($config['collections']) && is_array($config['collections'])) {
                foreach ($config['collections'] as $colHandle) {
                    if (is_string($colHandle) && ! Collection::find($colHandle)) {
                        return $this->error("Field \"{$handle}\" references collection \"{$colHandle}\" which does not exist. Create it first with create_collection.");
                    }
                }
            }

            // Strip Antlers template expressions from string config values.
            foreach ($config as $key => $value) {
                if (is_string($value) && (str_contains($value, '{{') || str_contains($value, '{!!'))) {
                    $config[$key] = strip_tags((string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '', $value));
                }
            }

            $validated[] = ['handle' => $handle, 'field' => $config];
        }

        return ['ok' => true, 'fields' => $validated];
    }

    /**
     * Validate an {"import": "<fieldset>", "prefix"?: "x_"} row.
     *
     * @param  array<string, mixed>  $field
     * @return array{row: array<string, string>}|array{ok: false, error: string}
     */
    private function validateImportRow(array $field, int $index): array
    {
        $import = $field['import'] ?? null;

        if (! is_string($import) || trim($import) === '') {
            return $this->error("Field at index {$index}: \"import\" must be a fieldset handle string, e.g. {\"import\": \"hero\"}.");
        }

        $import = trim($import);

        if (! Fieldset::find($import)) {
            return $this->error("Fieldset \"{$import}\" not found. Available fieldsets: ".$this->availableFieldsets());
        }

        $row = ['import' => $import];

        if (isset($field['prefix'])) {
            $prefix = is_string($field['prefix']) ? trim($field['prefix']) : '';

            if ($prefix === '' || preg_match('/^[a-z][a-z0-9_]*$/', $prefix) !== 1) {
                return $this->error("Import of \"{$import}\": \"prefix\" must be snake_case (e.g. \"hero_\").");
            }

            $row['prefix'] = $prefix;
        }

        return ['row' => $row];
    }

    /**
     * Validate a "fieldset.field" string reference.
     *
     * @return array{ok: false, error: string}|null  Null when valid.
     */
    private function validateFieldReference(string $reference, string $handle): ?array
    {
        $reference = trim($reference);
        $parts = explode('.', $reference, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return $this->error("Field \"{$handle}\": a string \"field\" value must reference a fieldset field as \"<fieldset>.<field>\" (e.g. \"hero.hero_title\").");
        }

        $fieldset = Fieldset::find($parts[0]);

        if (! $fieldset) {
            return $this->error("Field \"{$handle}\" references fieldset \"{$parts[0]}\" which does not exist. Available fieldsets: ".$this->availableFieldsets());
        }

        $fieldsetHandles = $fieldset->fields()->all()->keys()->all();

        if (! in_array($parts[1], $fieldsetHandles, true)) {
            return $this->error("Field \"{$handle}\": fieldset \"{$parts[0]}\" has no field \"{$parts[1]}\". Its fields: ".implode(', ', $fieldsetHandles));
        }

        return null;
    }

    private function availableFieldsets(): string
    {
        $handles = Fieldset::all()->map(fn ($fs) => (string) $fs->handle())->sort()->values()->all();

        return $handles !== [] ? implode(', ', $handles) : '(none)';
    }

    /**
     * @return array{ok: false, error: string}
     */
    private function error(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
