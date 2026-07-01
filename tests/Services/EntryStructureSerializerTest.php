<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Services\EntryStructureSerializer;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Fields\Field;

class EntryStructureSerializerTest extends TestCase
{
    private function blueprint(): \Statamic\Fields\Blueprint
    {
        return Blueprint::make('test')->setContents([
            'tabs' => [
                'main' => [
                    'sections' => [
                        [
                            'fields' => [
                                ['handle' => 'title', 'field' => ['type' => 'text']],
                                ['handle' => 'image', 'field' => ['type' => 'assets', 'max_files' => 1]],
                                ['handle' => 'blocks', 'field' => ['type' => 'replicator']],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_reference_serialization_lists_component_types_in_order(): void
    {
        $entry = Entry::make()->data([
            'title' => 'Hello',
            'image' => 'photos/a.jpg',
            'blocks' => [
                ['type' => 'hero', 'heading' => 'Top'],
                ['type' => 'text_with_image', 'body' => 'Mid'],
                ['type' => 'quote', 'quote' => 'End'],
            ],
        ]);

        $out = (new EntryStructureSerializer)->serialize($entry, $this->blueprint());

        // Component types are present and in source order — the whole point of a
        // layout reference.
        $this->assertStringContainsString('"type":"hero"', $out);
        $this->assertStringContainsString('"type":"text_with_image"', $out);
        $this->assertStringContainsString('"type":"quote"', $out);
        $this->assertLessThan(strpos($out, '"type":"quote"'), strpos($out, '"type":"hero"'));
        // Scalars and assets are rendered too.
        $this->assertStringContainsString('title:', $out);
        $this->assertStringContainsString('photos/a.jpg', $out);
    }

    public function test_large_complex_field_is_truncated_not_dropped(): void
    {
        $entry = Entry::make()->data([
            'blocks' => [['type' => 'text', 'body' => str_repeat('x', 9000)]],
        ]);

        $out = (new EntryStructureSerializer)->serialize($entry, $this->blueprint());

        // The set type survives; the oversized value is capped inline.
        $this->assertStringContainsString('"type":"text"', $out);
        $this->assertStringContainsString('truncated', $out);
    }

    /**
     * Cross-blueprint safety: when a referenced entry's set type does not exist
     * in the target blueprint, the mapper skips it with a warning instead of
     * crashing — so "create X based on Y" never produces invalid data.
     */
    public function test_unknown_set_type_is_skipped_with_warning(): void
    {
        $field = new Field('blocks', [
            'type' => 'replicator',
            'sets' => [
                'group_one' => [
                    'sets' => [
                        'hero' => ['fields' => [['handle' => 'heading', 'field' => ['type' => 'text']]]],
                    ],
                ],
            ],
        ]);

        $service = app(EntryGeneratorService::class);
        $method = new \ReflectionMethod($service, 'mapReplicatorData');
        $method->setAccessible(true);

        $warnings = [];
        $sets = [
            ['type' => 'hero', 'heading' => 'Known set'],
            ['type' => 'ghost_from_other_blueprint', 'heading' => 'Foreign set'],
        ];
        $args = [$sets, $field, &$warnings, 'default'];
        $result = $method->invokeArgs($service, $args);

        $this->assertCount(1, $result);
        $this->assertSame('hero', $result[0]['type']);
        $this->assertNotEmpty($warnings);
    }
}
