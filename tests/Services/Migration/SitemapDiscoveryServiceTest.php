<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services\Migration;

use BoldWeb\StatamicAiAssistant\Services\Migration\SitemapDiscoveryService;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class SitemapDiscoveryServiceTest extends TestCase
{
    public function test_discovers_urls_from_a_flat_sitemap(): void
    {
        Http::fake([
            'example.com/robots.txt' => Http::response('User-agent: *'."\n".'Allow: /'."\n".'Sitemap: https://example.com/sitemap.xml', 200),
            'example.com/sitemap.xml' => Http::response($this->urlsetXml([
                ['loc' => 'https://example.com/', 'lastmod' => '2025-01-01'],
                ['loc' => 'https://example.com/about', 'lastmod' => '2025-02-01'],
                ['loc' => 'https://example.com/blog/post-1'],
            ]), 200),
        ]);

        $result = (new SitemapDiscoveryService)->discover('https://example.com');

        $this->assertSame('sitemap', $result['source']);
        $this->assertCount(3, $result['urls']);
        $this->assertSame('https://example.com/', $result['urls'][0]['url']);
        $this->assertSame('2025-01-01', $result['urls'][0]['lastmod']);
        $this->assertSame('https://example.com/about', $result['urls'][1]['url']);
        $this->assertNull($result['urls'][2]['lastmod']);
    }

    public function test_recurses_into_sitemap_index(): void
    {
        Http::fake([
            'example.com/robots.txt' => Http::response('', 404),
            'example.com/sitemap.xml' => Http::response($this->sitemapIndexXml([
                'https://example.com/sitemap-pages.xml',
                'https://example.com/sitemap-blog.xml',
            ]), 200),
            'example.com/sitemap-pages.xml' => Http::response($this->urlsetXml([
                ['loc' => 'https://example.com/'],
                ['loc' => 'https://example.com/contact'],
            ]), 200),
            'example.com/sitemap-blog.xml' => Http::response($this->urlsetXml([
                ['loc' => 'https://example.com/blog/a'],
                ['loc' => 'https://example.com/blog/b'],
            ]), 200),
        ]);

        $result = (new SitemapDiscoveryService)->discover('https://example.com');

        $this->assertCount(4, $result['urls']);
        $urls = array_column($result['urls'], 'url');
        $this->assertContains('https://example.com/contact', $urls);
        $this->assertContains('https://example.com/blog/a', $urls);
    }

    public function test_pulls_sitemap_list_from_robots_txt(): void
    {
        Http::fake([
            'example.com/robots.txt' => Http::response(
                "User-agent: *\n".
                "Disallow: /admin\n".
                "Sitemap: https://example.com/custom-sitemap.xml\n".
                "Sitemap: https://example.com/other.xml\n",
                200
            ),
            'example.com/custom-sitemap.xml' => Http::response($this->urlsetXml([
                ['loc' => 'https://example.com/page-1'],
            ]), 200),
            'example.com/other.xml' => Http::response($this->urlsetXml([
                ['loc' => 'https://example.com/page-2'],
            ]), 200),
            'example.com/sitemap.xml' => Http::response('', 404),
        ]);

        $result = (new SitemapDiscoveryService)->discover('https://example.com');

        $urls = array_column($result['urls'], 'url');
        $this->assertContains('https://example.com/page-1', $urls);
        $this->assertContains('https://example.com/page-2', $urls);
    }

    public function test_falls_back_to_crawler_when_no_sitemap_is_available(): void
    {
        Http::fake([
            'example.com/robots.txt' => Http::response('', 404),
            'example.com/sitemap.xml' => Http::response('', 404),
            'https://example.com' => Http::response(
                '<html><body>'.
                '<a href="/about">About</a>'.
                '<a href="https://example.com/contact">Contact</a>'.
                '<a href="https://other-site.com/x">External</a>'.
                '<a href="mailto:a@b.com">Mail</a>'.
                '</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'example.com/about' => Http::response('<html><body><a href="/team">Team</a></body></html>', 200, ['Content-Type' => 'text/html']),
            'example.com/contact' => Http::response('<html><body></body></html>', 200, ['Content-Type' => 'text/html']),
            'example.com/team' => Http::response('<html><body></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = (new SitemapDiscoveryService)->discover('https://example.com', [
            'follow_depth' => 2,
        ]);

        $this->assertSame('crawl', $result['source']);
        $urls = array_column($result['urls'], 'url');
        $this->assertContains('https://example.com/', $urls);
        $this->assertContains('https://example.com/about', $urls);
        $this->assertContains('https://example.com/contact', $urls);
        $this->assertNotContains('https://other-site.com/x', $urls);
        $this->assertNotContains('mailto:a@b.com', $urls);
    }

    public function test_respects_include_and_exclude_regex(): void
    {
        Http::fake([
            'example.com/robots.txt' => Http::response('', 404),
            'example.com/sitemap.xml' => Http::response($this->urlsetXml([
                ['loc' => 'https://example.com/blog/post-1'],
                ['loc' => 'https://example.com/blog/post-2'],
                ['loc' => 'https://example.com/products/a'],
                ['loc' => 'https://example.com/admin/secret'],
            ]), 200),
        ]);

        $result = (new SitemapDiscoveryService)->discover('https://example.com', [
            'include' => ['#/blog/#'],
            'exclude' => ['#/admin/#'],
        ]);

        $urls = array_column($result['urls'], 'url');
        $this->assertContains('https://example.com/blog/post-1', $urls);
        $this->assertContains('https://example.com/blog/post-2', $urls);
        $this->assertNotContains('https://example.com/products/a', $urls);
        $this->assertNotContains('https://example.com/admin/secret', $urls);
    }

    public function test_excludes_urls_blocked_by_robots_disallow(): void
    {
        Http::fake([
            'example.com/robots.txt' => Http::response(
                "User-agent: *\nDisallow: /private",
                200
            ),
            'example.com/sitemap.xml' => Http::response($this->urlsetXml([
                ['loc' => 'https://example.com/public/page'],
                ['loc' => 'https://example.com/private/hidden'],
            ]), 200),
        ]);

        $result = (new SitemapDiscoveryService)->discover('https://example.com');

        $urls = array_column($result['urls'], 'url');
        $this->assertContains('https://example.com/public/page', $urls);
        $this->assertNotContains('https://example.com/private/hidden', $urls);
        $this->assertContains('https://example.com/private/hidden', $result['robots_excluded']);
    }

    public function test_caps_at_max_pages(): void
    {
        Http::fake([
            'example.com/robots.txt' => Http::response('', 404),
            'example.com/sitemap.xml' => Http::response($this->urlsetXml([
                ['loc' => 'https://example.com/a'],
                ['loc' => 'https://example.com/b'],
                ['loc' => 'https://example.com/c'],
                ['loc' => 'https://example.com/d'],
            ]), 200),
        ]);

        $result = (new SitemapDiscoveryService)->discover('https://example.com', [
            'max_pages' => 2,
        ]);

        $this->assertCount(2, $result['urls']);
    }

    public function test_dedupes_and_normalizes_trailing_slash(): void
    {
        Http::fake([
            'example.com/robots.txt' => Http::response('', 404),
            'example.com/sitemap.xml' => Http::response($this->urlsetXml([
                ['loc' => 'https://example.com/about/'],
                ['loc' => 'https://example.com/about'],
                ['loc' => 'https://example.com/about#section'],
            ]), 200),
        ]);

        $result = (new SitemapDiscoveryService)->discover('https://example.com');

        $this->assertCount(1, $result['urls']);
        $this->assertSame('https://example.com/about', $result['urls'][0]['url']);
    }

    public function test_returns_warning_on_invalid_site_url(): void
    {
        $result = (new SitemapDiscoveryService)->discover('not a url at all');

        $this->assertSame([], $result['urls']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_warns_when_sitemap_is_malformed(): void
    {
        // Prevent stray requests so the crawler fallback doesn't silently hit
        // Laravel's default empty-200 fake for unmatched URLs.
        Http::preventStrayRequests();
        Http::fake([
            'example.com/robots.txt' => Http::response('', 404),
            'example.com/sitemap.xml' => Http::response('<not-valid-xml', 200),
            // Crawler fallback still runs; give it a minimal empty page.
            'https://example.com' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = (new SitemapDiscoveryService)->discover('https://example.com');

        $this->assertNotEmpty($result['warnings']);
        $this->assertTrue(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w, 'Could not parse sitemap')),
            'Expected a warning about the malformed sitemap.'
        );
    }

    public function test_filters_out_cross_host_urls_inside_sitemap(): void
    {
        Http::fake([
            'example.com/robots.txt' => Http::response('', 404),
            'example.com/sitemap.xml' => Http::response($this->urlsetXml([
                ['loc' => 'https://example.com/keep'],
                ['loc' => 'https://other-domain.com/skip'],
            ]), 200),
        ]);

        $result = (new SitemapDiscoveryService)->discover('https://example.com');

        $urls = array_column($result['urls'], 'url');
        $this->assertSame(['https://example.com/keep'], $urls);
    }

    public function test_sends_user_agent_header(): void
    {
        Http::fake([
            '*' => Http::response('', 404),
        ]);

        (new SitemapDiscoveryService)->discover('https://example.com');

        Http::assertSent(function (Request $req) {
            return str_contains((string) $req->header('User-Agent')[0] ?? '', 'StatamicAiAssistant');
        });
    }

    /**
     * @param  array<int, array{loc: string, lastmod?: string}>  $urls
     */
    private function urlsetXml(array $urls): string
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $body .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $u) {
            $body .= '<url><loc>'.htmlspecialchars($u['loc']).'</loc>';
            if (isset($u['lastmod'])) {
                $body .= '<lastmod>'.$u['lastmod'].'</lastmod>';
            }
            $body .= '</url>';
        }
        $body .= '</urlset>';

        return $body;
    }

    /**
     * @param  array<int, string>  $locs
     */
    private function sitemapIndexXml(array $locs): string
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $body .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($locs as $loc) {
            $body .= '<sitemap><loc>'.htmlspecialchars($loc).'</loc></sitemap>';
        }
        $body .= '</sitemapindex>';

        return $body;
    }
}
