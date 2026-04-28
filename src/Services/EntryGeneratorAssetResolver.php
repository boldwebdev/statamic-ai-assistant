<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Services\Migration\PreferredAssetPaths;
use Illuminate\Support\Collection;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

class EntryGeneratorAssetResolver
{
    /** @var array<int, string> */
    private const RECURSIVE_CONTAINER_TYPES = ['replicator', 'components', 'grid'];

    /**
     * Fill every assets field in the blueprint tree (groups, replicator sets, grids)
     * when the value is empty. Assets are drawn from $preferred first (downloaded
     * by the migration job), then from random paths in the field's container.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     */
    public function fillAssetFieldsWithRandom(array &$data, array &$displayData, Blueprint $blueprint, array &$warnings, ?PreferredAssetPaths $preferred = null): void
    {
        foreach ($blueprint->fields()->all() as $field) {
            $this->fillAssetsForField($field, $data, $displayData, $warnings, $preferred);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     */
    private function fillAssetsForField(Field $field, array &$data, array &$displayData, array &$warnings, ?PreferredAssetPaths $preferred): void
    {
        $handle = $field->handle();
        $type = $field->type();

        if ($type === 'section') {
            return;
        }

        if ($type === 'assets') {
            $this->fillSingleAssetField($field, $data, $displayData, $warnings, $preferred);

            return;
        }

        if ($type === 'group') {
            if (! isset($data[$handle]) || ! is_array($data[$handle])) {
                $data[$handle] = [];
            }
            if (! isset($displayData[$handle]) || ! is_array($displayData[$handle])) {
                $displayData[$handle] = $data[$handle];
            }

            foreach ($field->fieldtype()->fields()->all() as $child) {
                $this->fillAssetsForField($child, $data[$handle], $displayData[$handle], $warnings, $preferred);
            }

            return;
        }

        if (in_array($type, self::RECURSIVE_CONTAINER_TYPES, true)) {
            if (! isset($data[$handle]) || ! is_array($data[$handle])) {
                return;
            }
            if (! isset($displayData[$handle]) || ! is_array($displayData[$handle])) {
                $displayData[$handle] = $data[$handle];
            }

            $fieldtype = $field->fieldtype();

            foreach ($data[$handle] as $idx => &$row) {
                if (! is_array($row)) {
                    continue;
                }

                if (! isset($displayData[$handle][$idx]) || ! is_array($displayData[$handle][$idx])) {
                    $displayData[$handle][$idx] = $row;
                }

                if ($type === 'grid') {
                    $subfields = $fieldtype->fields();

                    foreach ($subfields->all() as $child) {
                        $this->fillAssetsForField($child, $row, $displayData[$handle][$idx], $warnings, $preferred);
                    }

                    continue;
                }

                $setType = $row['type'] ?? null;

                if (! $setType) {
                    continue;
                }

                try {
                    $setFields = $fieldtype->fields($setType);
                } catch (\Exception) {
                    continue;
                }

                foreach ($setFields->all() as $child) {
                    $this->fillAssetsForField($child, $row, $displayData[$handle][$idx], $warnings, $preferred);
                }
            }
            unset($row);

            return;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     */
    private function fillSingleAssetField(Field $field, array &$data, array &$displayData, array &$warnings, ?PreferredAssetPaths $preferred): void
    {
        $handle = $field->handle();

        $current = $data[$handle] ?? null;

        $empty = $current === null || $current === '' || $current === [];

        if (! $empty) {
            return;
        }

        $config = $field->config();
        $maxFiles = max(1, (int) ($config['max_files'] ?? 1));
        $minFiles = max(0, (int) ($config['min_files'] ?? 0));
        $count = max($maxFiles, $minFiles);

        $picked = [];

        // Drain matching downloaded paths first so migrated entries actually
        // reference the page's own images instead of random container assets.
        $containerHandle = $config['container'] ?? null;
        if ($preferred !== null && is_string($containerHandle) && $containerHandle !== '') {
            $picked = $preferred->takeForContainer($containerHandle, $count);
        }

        if (count($picked) < $count) {
            $remaining = $count - count($picked);
            $randomPaths = $this->pickRandomPathsForField($field, $warnings);
            $picked = array_merge($picked, array_slice($randomPaths, 0, $remaining));
        }

        if ($picked === []) {
            return;
        }

        $value = $maxFiles === 1 ? $picked[0] : array_slice($picked, 0, $maxFiles);

        $data[$handle] = $value;
        $displayData[$handle] = $value;
    }

    /**
     * @return array<int, string>
     */
    private function pickRandomPathsForField(Field $field, array &$warnings): array
    {
        $config = $field->config();
        $maxFiles = max(1, (int) ($config['max_files'] ?? 1));
        $minFiles = max(0, (int) ($config['min_files'] ?? 0));
        $count = max($maxFiles, $minFiles);

        $candidates = $this->getCandidateAssets($field);

        if ($candidates->isEmpty()) {
            $warnings[] = __('No usable assets found in the container for :field.', ['field' => $field->display()]);

            return [];
        }

        $pool = $candidates->map(fn ($a) => $a->path())->values()->all();

        if ($pool === []) {
            return [];
        }

        if (count($pool) >= $count) {
            shuffle($pool);

            return array_slice($pool, 0, $count);
        }

        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = $pool[array_rand($pool)];
        }

        return $out;
    }

    /**
     * @return Collection<int, StatamicAsset|AssetContract>
     */
    private function getCandidateAssets(Field $field): Collection
    {
        $config = $field->config();
        $handle = $config['container'] ?? null;

        if (! $handle) {
            return collect();
        }

        $container = AssetContainer::find($handle);

        if (! $container) {
            return collect();
        }

        $folder = $config['folder'] ?? null;

        if ($folder) {
            $assets = $container->assets($folder, true);
        } else {
            $assets = $container->assets();
        }

        $images = $assets->filter(fn (AssetContract $a) => $a->isImage())->values();

        if ($images->isNotEmpty()) {
            $only = $images->filter(fn ($a) => $a instanceof StatamicAsset)->values();

            return $only->isNotEmpty() ? $only : $images;
        }

        $only = $assets->filter(fn ($a) => $a instanceof StatamicAsset)->values();

        return $only->isNotEmpty() ? $only : $assets->values();
    }
}
