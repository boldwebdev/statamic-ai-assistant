<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Statamic\Facades\AssetContainer;

/**
 * Deterministically resolves "@asset:container::path" / "@folder:container::path"
 * mentions from chat prompts into an authoritative context block, the same way
 * PromptUrlFetcher pre-fetches URLs. Without this, models answer questions about
 * asset folders from the entries catalog (collections often share names with
 * folders — "apartments" the collection vs. assets::apartments the folder).
 */
class PromptAssetMentionResolver
{
    private const MAX_REFS = 8;

    private const MAX_FILES_LISTED = 40;

    /** Matches the exact token format the mention picker inserts. */
    private const MENTION_PATTERN = '/@(asset|folder):([a-z0-9_-]+)::([^\s"\'<>`]+)/iu';

    /**
     * Scan one or more prompt texts and resolve every unique mention.
     * Returns an empty appendix when there is nothing to resolve.
     *
     * @param  array<int, string>  $texts
     * @return array{appendix: string}
     */
    public function resolve(array $texts): array
    {
        $refs = [];

        foreach ($texts as $text) {
            if (! is_string($text) || $text === '') {
                continue;
            }

            preg_match_all(self::MENTION_PATTERN, $text, $matches, PREG_SET_ORDER);

            foreach ($matches as $m) {
                $kind = strtolower($m[1]);
                $container = $m[2];
                // Refs can sit at the end of a sentence — strip trailing punctuation.
                $path = rtrim($m[3], '.,;:!?)');
                $refs[$kind.':'.$container.'::'.$path] = [$kind, $container, $path];
            }
        }

        if ($refs === []) {
            return ['appendix' => ''];
        }

        $lines = [];

        foreach (array_slice(array_values($refs), 0, self::MAX_REFS) as [$kind, $containerHandle, $path]) {
            $container = AssetContainer::findByHandle($containerHandle);

            if (! $container) {
                $lines[] = "- {$kind} {$containerHandle}::{$path} — container \"{$containerHandle}\" not found.";

                continue;
            }

            $lines[] = $kind === 'folder'
                ? $this->folderLine($container, $path)
                : $this->assetLine($container, $path);
        }

        return [
            'appendix' => "\n\nREFERENCED ASSETS (resolved from the asset library — authoritative; these are FILES, not collections or entries; do not re-verify them with list_assets):\n"
                .implode("\n", $lines),
        ];
    }

    private function folderLine(mixed $container, string $folder): string
    {
        $folder = trim($folder, '/');
        $assets = $container->assets($folder !== '' ? $folder : '/', false);
        $total = $assets->count();
        $images = $assets->filter(fn ($a) => $a->isImage())->count();

        $files = $assets->take(self::MAX_FILES_LISTED)
            ->map(fn ($a) => $a->basename())
            ->implode(', ');

        $line = "- folder {$container->handle()}::{$folder} — {$total} files ({$images} images)";

        if ($files !== '') {
            $line .= ': '.$files;
            if ($total > self::MAX_FILES_LISTED) {
                $line .= ', … ('.($total - self::MAX_FILES_LISTED).' more)';
            }
        }

        return $line;
    }

    private function assetLine(mixed $container, string $path): string
    {
        $asset = $container->asset(trim($path, '/'));

        if (! $asset) {
            return "- asset {$container->handle()}::{$path} — not found in this container.";
        }

        $parts = [$asset->isImage() ? 'image' : 'file'];

        // Non-empty blueprint meta values (alt texts etc.) so read-only questions
        // about the asset are answerable without any tool call.
        foreach ($container->blueprint()?->fields()->all()->keys()->all() ?? [] as $handle) {
            $value = $asset->get($handle);
            if (is_string($value) && trim($value) !== '') {
                $parts[] = $handle.': "'.$value.'"';
            }
        }

        return "- asset {$container->handle()}::{$path} — ".implode('; ', $parts);
    }
}
