<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services\Migration;

use BoldWeb\StatamicAiAssistant\Services\Migration\MigrationUrlNormalizer;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;

class MigrationUrlNormalizerTest extends TestCase
{
    public function test_normalize_lowercases_host_and_strips_trailing_slash_on_path(): void
    {
        $this->assertSame(
            'https://example.com/foo/bar',
            MigrationUrlNormalizer::normalize('https://EXAMPLE.com/foo/bar/')
        );
    }

    public function test_parent_url_drops_last_segment(): void
    {
        $this->assertSame(
            'https://example.com/stiftung',
            MigrationUrlNormalizer::parentUrl('https://example.com/stiftung/me')
        );
    }

    public function test_parent_url_returns_null_for_single_segment(): void
    {
        $this->assertNull(MigrationUrlNormalizer::parentUrl('https://example.com/stiftung'));
    }

    public function test_path_depth(): void
    {
        $this->assertSame(0, MigrationUrlNormalizer::pathDepth('https://example.com'));
        $this->assertSame(1, MigrationUrlNormalizer::pathDepth('https://example.com/a'));
        $this->assertSame(2, MigrationUrlNormalizer::pathDepth('https://example.com/a/b'));
    }
}
