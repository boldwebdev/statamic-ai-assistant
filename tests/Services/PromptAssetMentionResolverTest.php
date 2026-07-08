<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\PromptAssetMentionResolver;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;

class PromptAssetMentionResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        // Containers persist as YAML in the shared fixture dir — never leave one behind.
        AssetContainer::findByHandle('media')?->delete();
        parent::tearDown();
    }

    public function test_prompts_without_mentions_yield_no_appendix(): void
    {
        $result = (new PromptAssetMentionResolver)->resolve([
            'Create a page about our spa offers.',
            '',
        ]);

        $this->assertSame('', $result['appendix']);
    }

    public function test_unknown_container_is_reported_not_invented(): void
    {
        $result = (new PromptAssetMentionResolver)->resolve([
            'use images from @folder:ghost::somewhere please',
        ]);

        $this->assertStringContainsString('container "ghost" not found', $result['appendix']);
        $this->assertStringContainsString('REFERENCED ASSETS', $result['appendix']);
    }

    public function test_folder_mentions_resolve_to_file_counts_from_the_asset_library(): void
    {
        config(['filesystems.disks.test_assets' => [
            'driver' => 'local',
            'root' => Storage::fake('test_assets')->path(''),
        ]]);

        AssetContainer::make('media')->disk('test_assets')->title('Media')->save();

        Storage::disk('test_assets')->put('apartments/a.jpg', 'x');
        Storage::disk('test_assets')->put('apartments/b.jpg', 'x');
        Storage::disk('test_assets')->put('apartments/notes.txt', 'x');

        // Refs dropped in one message, question asked in another — both scanned.
        $result = (new PromptAssetMentionResolver)->resolve([
            '@folder:media::apartments',
            'which folder contains more assets ?',
        ]);

        $this->assertStringContainsString('folder media::apartments — 3 files (2 images)', $result['appendix']);
        $this->assertStringContainsString('a.jpg', $result['appendix']);
        $this->assertStringContainsString('notes.txt', $result['appendix']);
    }

    public function test_asset_mentions_include_meta_values_and_trailing_punctuation_is_stripped(): void
    {
        config(['filesystems.disks.test_assets' => [
            'driver' => 'local',
            'root' => Storage::fake('test_assets')->path(''),
        ]]);

        AssetContainer::make('media')->disk('test_assets')->title('Media')->save();
        Storage::disk('test_assets')->put('team/portrait.jpg', 'x');

        $asset = AssetContainer::findByHandle('media')->asset('team/portrait.jpg');
        $asset->set('alt', 'A portrait');
        $asset->save();

        $result = (new PromptAssetMentionResolver)->resolve([
            'does @asset:media::team/portrait.jpg have alt text?',
        ]);

        $this->assertStringContainsString('asset media::team/portrait.jpg', $result['appendix']);
        $this->assertStringNotContainsString('portrait.jpg?', $result['appendix']);
        $this->assertStringContainsString('alt: "A portrait"', $result['appendix']);
    }
}
