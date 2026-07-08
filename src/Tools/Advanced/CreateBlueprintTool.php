<?php

namespace BoldWeb\StatamicAiAssistant\Tools\Advanced;

use BoldWeb\StatamicAiAssistant\Support\BlueprintFieldValidator;
use BoldWeb\StatamicAiAssistant\Tools\ToolContext;
use Illuminate\Support\Str;
use Statamic\Facades\Blueprint;

/**
 * Creates a new entry/term blueprint from validated field definitions. The
 * create is guarded by a file lock (adapted from cboxdk/statamic-mcp) because
 * planner tool calls run in queue workers that can race on the same handle.
 */
class CreateBlueprintTool extends AbstractAdvancedTool
{
    use ResolvesBlueprintNamespace;

    public function __construct(private BlueprintFieldValidator $validator = new BlueprintFieldValidator) {}

    public function name(): string
    {
        return 'create_blueprint';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_blueprint',
                'description' => 'Create a NEW blueprint for a collection (entry blueprint) or taxonomy (term blueprint). '
                    .'Each field is {"handle": "...", "field": {"type": "...", "display": "...", ...config}}. '
                    .'To reuse an EXISTING fieldset (hero, seo, ...) add an import row {"import": "<fieldset_handle>"} (optional "prefix": "x_") instead of redefining its fields — check list_fieldsets first. '
                    .'A single fieldset field can be referenced as {"handle": "...", "field": "<fieldset>.<field>"}. '
                    .'Include a title text field first unless the user asked otherwise. '
                    .'The collection or taxonomy must already exist. Changes apply IMMEDIATELY — only do this when the user explicitly asked for it.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => [
                            'type' => 'string',
                            'description' => 'snake_case handle for the new blueprint (e.g. "event").',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Human title (defaults to the handle, titleized).',
                        ],
                        'collection' => [
                            'type' => 'string',
                            'description' => 'Collection handle this entry blueprint belongs to.',
                        ],
                        'taxonomy' => [
                            'type' => 'string',
                            'description' => 'Taxonomy handle this term blueprint belongs to.',
                        ],
                        'fields' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                            'description' => 'Field definitions: [{"handle": "title", "field": {"type": "text", "display": "Title"}}, ...].',
                        ],
                    ],
                    'required' => ['handle', 'fields'],
                ],
            ],
        ];
    }

    protected function run(array $args, ToolContext $context): array
    {
        $handle = $this->stringArg($args, 'handle');
        if ($err = $this->invalidHandleError($handle, 'blueprint')) {
            return ['ok' => false, 'error' => $err];
        }

        $ns = $this->resolveBlueprintNamespace($args);
        if (! $ns['ok']) {
            return $ns;
        }

        $fields = isset($args['fields']) && is_array($args['fields']) ? $args['fields'] : [];
        $validation = $this->validator->validate($fields);
        if (! $validation['ok']) {
            return $validation;
        }

        $title = $this->stringArg($args, 'title');
        if ($title === '') {
            $title = Str::title(str_replace('_', ' ', $handle));
        }

        // File lock: queue workers can race on creating the same handle.
        $lockPath = storage_path('framework/cache/blueprint-'.md5($ns['namespace'].'.'.$handle).'.lock');
        $lockDir = dirname($lockPath);
        if (! is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        $lock = fopen($lockPath, 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            if ($lock) {
                fclose($lock);
            }

            return ['ok' => false, 'error' => 'Could not acquire lock for blueprint creation. Try again.'];
        }

        try {
            if ($this->findBlueprintIn($ns['namespace'], $handle)) {
                return ['ok' => false, 'error' => "Blueprint \"{$handle}\" already exists in {$ns['owner_type']} \"{$ns['owner']}\". Use update_blueprint to change it."];
            }

            $context->reportActivity((string) __('Creating blueprint :handle', ['handle' => $handle]));

            $blueprint = Blueprint::make($handle)
                ->setNamespace($ns['namespace'])
                ->setContents(['title' => $title, 'fields' => $validation['fields']]);
            $blueprint->save();

            return [
                'ok' => true,
                'created' => true,
                'handle' => $blueprint->handle(),
                'title' => $blueprint->title(),
                $ns['owner_type'] => $ns['owner'],
                'fields' => array_map(fn ($f) => $f['handle'] ?? 'import:'.$f['import'], $validation['fields']),
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
    }
}
