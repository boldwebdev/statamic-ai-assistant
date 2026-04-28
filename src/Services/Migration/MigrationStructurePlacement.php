<?php

namespace BoldWeb\StatamicAiAssistant\Services\Migration;

use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Places migrated entries into a structured collection tree (updates site trees / YAML).
 */
class MigrationStructurePlacement
{
    /**
     * Ensure $childEntryId sits under $parentEntryId, or at the root if $parentEntryId is null.
     * Uses move() when the child already exists in the tree to avoid duplicates.
     */
    public static function ensure(string $collectionHandle, string $locale, ?string $parentEntryId, string $childEntryId): void
    {
        $collection = Collection::findByHandle($collectionHandle);
        if (! $collection || ! $collection->hasStructure()) {
            return;
        }

        $lockKey = 'ai-migration:structure:'.$collectionHandle.':'.$locale;
        Cache::lock($lockKey, 30)->block(10, function () use ($collection, $locale, $parentEntryId, $childEntryId): void {
            $tree = $collection->structure()->in($locale);
            if (! $tree) {
                return;
            }

            if ($parentEntryId) {
                if ($tree->find($childEntryId)) {
                    $tree->move($childEntryId, $parentEntryId);
                } else {
                    $tree->appendTo($parentEntryId, $childEntryId);
                }
            } else {
                if (! $tree->find($childEntryId)) {
                    $entry = Entry::find($childEntryId);
                    if ($entry) {
                        $tree->append($entry);
                    }
                }
            }

            $tree->save();
        });
    }
}
