<?php

namespace BoldWeb\StatamicAiAssistant\Tools;

use Statamic\Facades\AssetContainer;

/**
 * Lets the agent browse existing assets: containers → folders → files with
 * their metadata. Resolves "@folder:…" / "@asset:…" mentions from the chat and
 * feeds update_asset / use_assets with exact "container::path" references.
 */
class ListAssetsTool implements ChatTool
{
    private const MAX_ASSETS = 60;

    public function name(): string
    {
        return 'list_assets';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'list_assets',
                'description' => 'Browse existing assets. Without arguments: lists asset containers and their top-level folders. '
                    .'With container (and optional folder): lists that folder\'s files with metadata, plus subfolders. '
                    .'Use it to resolve @folder:/@asset: references from the user. Asset references are "container::path". '
                    .'The response includes the container\'s meta field handles (alt text etc.) for update_asset.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'container' => ['type' => 'string', 'description' => 'Asset container handle.'],
                        'folder' => ['type' => 'string', 'description' => 'Folder path within the container (omit for root).'],
                        'recursive' => ['type' => 'boolean', 'description' => 'Include files of all nested folders (default false).'],
                        'search' => ['type' => 'string', 'description' => 'Filter files by filename substring (applied before the listing cap).'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        if (! is_array($args)) {
            return ['ok' => false, 'error' => 'invalid_arguments_shape'];
        }

        $containerHandle = isset($args['container']) && is_string($args['container']) ? trim($args['container']) : '';

        if ($containerHandle === '') {
            return [
                'ok' => true,
                'containers' => AssetContainer::all()->map(fn ($c) => [
                    'handle' => (string) $c->handle(),
                    'title' => (string) $c->title(),
                    'folders' => collect($c->folders())->take(40)->map(fn ($f) => (string) $f)->values()->all(),
                ])->values()->all(),
            ];
        }

        $container = AssetContainer::findByHandle($containerHandle);
        if (! $container) {
            return ['ok' => false, 'error' => "Asset container \"{$containerHandle}\" not found. Available: ".AssetContainer::all()->map(fn ($c) => (string) $c->handle())->sort()->values()->implode(', ')];
        }

        $folder = isset($args['folder']) && is_string($args['folder']) ? trim($args['folder'], "/ \t") : '';
        $recursive = ($args['recursive'] ?? false) === true;

        $context->reportActivity((string) __('Browsing assets in :target', ['target' => $containerHandle.($folder !== '' ? '/'.$folder : '')]));

        $assets = $container->assets($folder !== '' ? $folder : '/', $recursive);

        $search = isset($args['search']) && is_string($args['search']) ? mb_strtolower(trim($args['search'])) : '';
        if ($search !== '') {
            $assets = $assets->filter(fn ($asset) => str_contains(mb_strtolower($asset->basename()), $search))->values();
        }

        $total = $assets->count();

        $rows = $assets->take(self::MAX_ASSETS)->map(function ($asset) {
            $row = [
                'ref' => $asset->containerHandle().'::'.$asset->path(),
                'filename' => $asset->basename(),
                'is_image' => (bool) $asset->isImage(),
            ];

            $alt = $asset->get('alt');
            if (is_string($alt) && $alt !== '') {
                $row['alt'] = $alt;
            }

            return $row;
        })->values()->all();

        $subfolders = collect($container->folders($folder !== '' ? $folder : '/'))
            ->take(40)
            ->map(fn ($f) => (string) $f)
            ->values()
            ->all();

        $result = [
            'ok' => true,
            'container' => $containerHandle,
            'folder' => $folder,
            'assets' => $rows,
            'subfolders' => $subfolders,
            'meta_fields' => $container->blueprint()?->fields()->all()->keys()->values()->all() ?? [],
        ];

        if ($total > self::MAX_ASSETS) {
            $result['note'] = 'Showing '.self::MAX_ASSETS." of {$total} assets — narrow the folder to see the rest.";
        }

        return $result;
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_cms_reads', 12));
    }
}
