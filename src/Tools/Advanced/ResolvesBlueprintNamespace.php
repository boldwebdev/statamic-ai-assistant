<?php

namespace BoldWeb\StatamicAiAssistant\Tools\Advanced;

use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;

/**
 * Shared blueprint addressing for the advanced tools: a blueprint belongs to
 * exactly one collection ("collections.{handle}") or taxonomy
 * ("taxonomies.{handle}"). Errors list the available handles so the model can
 * self-correct instead of guessing.
 */
trait ResolvesBlueprintNamespace
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: true, namespace: string, owner_type: string, owner: string}|array{ok: false, error: string}
     */
    private function resolveBlueprintNamespace(array $args): array
    {
        $collection = $this->stringArg($args, 'collection');
        $taxonomy = $this->stringArg($args, 'taxonomy');

        if (($collection === '') === ($taxonomy === '')) {
            return ['ok' => false, 'error' => 'Provide exactly one of "collection" or "taxonomy" — the handle the blueprint belongs to.'];
        }

        if ($collection !== '') {
            if (! Collection::find($collection)) {
                return ['ok' => false, 'error' => "Collection \"{$collection}\" not found. Available: ".Collection::handles()->sort()->implode(', ').'. Create it first with create_collection.'];
            }

            return ['ok' => true, 'namespace' => "collections.{$collection}", 'owner_type' => 'collection', 'owner' => $collection];
        }

        if (! Taxonomy::find($taxonomy)) {
            return ['ok' => false, 'error' => "Taxonomy \"{$taxonomy}\" not found. Available: ".Taxonomy::handles()->sort()->implode(', ').'. Create it first with create_taxonomy.'];
        }

        return ['ok' => true, 'namespace' => "taxonomies.{$taxonomy}", 'owner_type' => 'taxonomy', 'owner' => $taxonomy];
    }

    private function findBlueprintIn(string $namespace, string $handle): ?\Statamic\Fields\Blueprint
    {
        return Blueprint::in($namespace)->first(fn ($bp) => $bp->handle() === $handle);
    }
}
