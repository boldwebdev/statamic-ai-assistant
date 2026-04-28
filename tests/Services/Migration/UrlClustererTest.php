<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services\Migration;

use BoldWeb\StatamicAiAssistant\Services\Migration\UrlClusterer;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;

class UrlClustererTest extends TestCase
{
    public function test_groups_urls_by_first_path_segment(): void
    {
        $clusters = (new UrlClusterer)->cluster([
            ['url' => 'https://example.com/blog/post-1'],
            ['url' => 'https://example.com/blog/post-2'],
            ['url' => 'https://example.com/blog/post-3'],
            ['url' => 'https://example.com/products/alpha'],
            ['url' => 'https://example.com/products/beta'],
            ['url' => 'https://example.com/about'],
            ['url' => 'https://example.com/'],
        ]);

        $byPattern = array_column($clusters, null, 'pattern');

        $this->assertArrayHasKey('/blog/*', $byPattern);
        $this->assertArrayHasKey('/products/*', $byPattern);
        $this->assertArrayHasKey('/about', $byPattern);
        $this->assertArrayHasKey('/', $byPattern);

        $this->assertSame(3, $byPattern['/blog/*']['count']);
        $this->assertSame(2, $byPattern['/products/*']['count']);
        $this->assertSame(1, $byPattern['/about']['count']);
        $this->assertSame(1, $byPattern['/']['count']);
    }

    public function test_clusters_are_sorted_by_count_desc_then_pattern(): void
    {
        $clusters = (new UrlClusterer)->cluster([
            ['url' => 'https://example.com/z'],
            ['url' => 'https://example.com/a'],
            ['url' => 'https://example.com/blog/1'],
            ['url' => 'https://example.com/blog/2'],
        ]);

        $this->assertSame('/blog/*', $clusters[0]['pattern']);
        $this->assertSame(2, $clusters[0]['count']);
        $this->assertSame('/a', $clusters[1]['pattern']);
        $this->assertSame('/z', $clusters[2]['pattern']);
    }

    public function test_collects_sample_urls_capped_at_three(): void
    {
        $clusters = (new UrlClusterer)->cluster([
            ['url' => 'https://example.com/blog/1'],
            ['url' => 'https://example.com/blog/2'],
            ['url' => 'https://example.com/blog/3'],
            ['url' => 'https://example.com/blog/4'],
            ['url' => 'https://example.com/blog/5'],
        ]);

        $this->assertCount(3, $clusters[0]['sample_urls']);
        $this->assertCount(5, $clusters[0]['urls']);
    }

    public function test_ignores_blank_urls(): void
    {
        $clusters = (new UrlClusterer)->cluster([
            ['url' => ''],
            ['url' => 'https://example.com/about'],
        ]);

        $this->assertCount(1, $clusters);
        $this->assertSame('/about', $clusters[0]['pattern']);
    }

    public function test_handles_root_only_urls(): void
    {
        $clusters = (new UrlClusterer)->cluster([
            ['url' => 'https://example.com/'],
            ['url' => 'https://example.com'],
        ]);

        $this->assertCount(1, $clusters);
        $this->assertSame('/', $clusters[0]['pattern']);
        $this->assertSame(2, $clusters[0]['count']);
    }
}
