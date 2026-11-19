<?php

namespace WPMCP\Tests\Pro\Export;

use WPMCP\Tools\Export\Static_Site\Link_Rewriter;

/**
 * Static HTML export (issue #854): where a URL lands in the ZIP and how a
 * reference is rewritten to a relative path that resolves offline.
 */
class StaticLinkRewriterTest extends \WP_UnitTestCase
{
    public function test_page_files_mirror_the_url_space_with_explicit_index_files(): void
    {
        $this->assertSame('index.html', Link_Rewriter::page_file('http://example.org/'));
        $this->assertSame('index.html', Link_Rewriter::page_file('http://example.org'));
        $this->assertSame('about/index.html', Link_Rewriter::page_file('http://example.org/about/'));
        $this->assertSame('about/index.html', Link_Rewriter::page_file('http://example.org/about'));
        $this->assertSame('blog/page/2/index.html', Link_Rewriter::page_file('http://example.org/blog/page/2/'));
        $this->assertSame('old.html', Link_Rewriter::page_file('http://example.org/old.html'));
        $this->assertSame('café/index.html', Link_Rewriter::page_file('http://example.org/caf%C3%A9/'));
        $this->assertNull(Link_Rewriter::page_file('http://example.org/?s=x'));
        $this->assertNull(Link_Rewriter::page_file('http://example.org/a/%2e%2e/b/'));
    }

    public function test_asset_files_drop_the_query_string(): void
    {
        $this->assertSame('wp-content/themes/t/style.css', Link_Rewriter::asset_file('http://example.org/wp-content/themes/t/style.css?ver=6.9'));
        $this->assertNull(Link_Rewriter::asset_file('http://example.org/wp-content/'));
    }

    public function test_relative_paths_between_zip_files(): void
    {
        $this->assertSame('about/index.html', Link_Rewriter::relative('index.html', 'about/index.html'));
        $this->assertSame('../index.html', Link_Rewriter::relative('about/index.html', 'index.html'));
        $this->assertSame('team/index.html', Link_Rewriter::relative('about/index.html', 'about/team/index.html'));
        $this->assertSame('../../wp-content/x.css', Link_Rewriter::relative('a/b/index.html', 'wp-content/x.css'));
        $this->assertSame('caf%C3%A9/index.html', Link_Rewriter::relative('index.html', 'café/index.html'));
    }

    public function test_resolves_relative_root_relative_and_protocol_relative_references(): void
    {
        $base = 'http://example.org/wp-content/themes/t/css/main.css';
        $this->assertSame('http://example.org/wp-content/themes/t/fonts/a.woff2', Link_Rewriter::resolve('../fonts/a.woff2', $base));
        $this->assertSame('http://example.org/img/x.png', Link_Rewriter::resolve('/img/x.png', $base));
        $this->assertSame('https://cdn.example.com/x.js', Link_Rewriter::resolve('//cdn.example.com/x.js', 'https://example.org/'));
        $this->assertSame('http://example.org/about/team/', Link_Rewriter::resolve('team/', 'http://example.org/about/'));
        $this->assertSame('http://example.org/x.css', Link_Rewriter::resolve('x.css', 'http://example.org/style.css'));
        $this->assertNull(Link_Rewriter::resolve('#top', $base));
        $this->assertNull(Link_Rewriter::resolve('mailto:a@example.org', $base));
        $this->assertNull(Link_Rewriter::resolve('data:image/png;base64,AAAA', $base));
        $this->assertNull(Link_Rewriter::resolve('javascript:void(0)', $base));
    }

    public function test_dynamic_endpoints_are_recognised(): void
    {
        foreach (['/wp-admin/', '/wp-login.php?x=1', '/wp-json/wp/v2/posts', '/xmlrpc.php', '/feed/', '/hello/feed/', '/wp-comments-post.php'] as $path) {
            $this->assertTrue(Link_Rewriter::is_dynamic('http://example.org' . $path), $path);
        }
        $this->assertFalse(Link_Rewriter::is_dynamic('http://example.org/feedback/'));
    }

    public function test_rewrites_internal_references_and_leaves_the_rest(): void
    {
        $html = '<link rel="stylesheet" href="http://example.org/wp-content/style.css?ver=1">'
            . '<a href="/about/#team">About</a>'
            . '<a href="https://other.example.com/">Other</a>'
            . '<a href="http://example.org/wp-admin/">Admin</a>'
            . '<a href="#top">Top</a>'
            . '<a href="/wp-login.php?a=1&amp;b=2">Log in</a>'
            . '<img src="/wp-content/uploads/a.jpg" srcset="/wp-content/uploads/a-300.jpg 300w, /wp-content/uploads/a-600.jpg 600w">'
            . '<div style="background:url(\'/wp-content/uploads/bg.png\')"></div>'
            . '<style>.x{background:url(/wp-content/uploads/s.png)}</style>';

        $seen     = [];
        $resolver = static function (string $url, string $kind) use (&$seen): ?string {
            $seen[] = [$url, $kind];
            if (! Link_Rewriter::is_internal($url, 'http://example.org/') || Link_Rewriter::is_dynamic($url)) {
                return null;
            }
            return 'page' === $kind ? Link_Rewriter::page_file($url) : Link_Rewriter::asset_file($url);
        };

        $out = Link_Rewriter::rewrite_html($html, 'http://example.org/blog/post/', 'blog/post/index.html', $resolver);

        $this->assertStringContainsString('href="../../wp-content/style.css"', $out);
        $this->assertStringContainsString('href="../../about/index.html#team"', $out);
        $this->assertStringContainsString('href="https://other.example.com/"', $out);
        $this->assertStringContainsString('href="http://example.org/wp-admin/"', $out);
        $this->assertStringContainsString('href="#top"', $out);
        $this->assertStringContainsString('href="http://example.org/wp-login.php?a=1&amp;b=2"', $out, 'a root-relative live-only link is made absolute');
        $this->assertStringContainsString('src="../../wp-content/uploads/a.jpg"', $out);
        $this->assertStringContainsString('srcset="../../wp-content/uploads/a-300.jpg 300w, ../../wp-content/uploads/a-600.jpg 600w"', $out);
        $this->assertStringContainsString("url('../../wp-content/uploads/bg.png')", $out);
        $this->assertStringContainsString('url(../../wp-content/uploads/s.png)', $out);
    }

    public function test_rewrites_stylesheet_urls_relative_to_the_stylesheet(): void
    {
        $css = '@import "parts/a.css"; @font-face{src:url("../fonts/f.woff2") format("woff2")} .y{background:url(data:image/png;base64,AA)}';

        $resolver = static fn(string $url, string $kind): ?string => 'asset' === $kind ? Link_Rewriter::asset_file($url) : null;
        $out      = Link_Rewriter::rewrite_css($css, 'http://example.org/wp-content/themes/t/css/main.css?ver=2', 'wp-content/themes/t/css/main.css', $resolver);

        $this->assertStringContainsString('@import "parts/a.css"', $out);
        $this->assertStringContainsString('url("../fonts/f.woff2")', $out);
        $this->assertStringContainsString('url(data:image/png;base64,AA)', $out);
    }
}
