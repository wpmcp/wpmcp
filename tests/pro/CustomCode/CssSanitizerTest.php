<?php

namespace WPMCP\Tests\Pro\CustomCode;

use WPMCP\Tools\CustomCode\Css_Sanitizer;

/**
 * The adversarial payload corpus issue #63 asks for, written against
 * Css_Sanitizer directly because it is deliberately pure: every rejection
 * rule the write path and the render path rely on is decided here.
 *
 * The corpus has two halves and both matter. The MUST-REJECT half covers each
 * vector in at least three spellings - plain, escape-obfuscated, and
 * comment-split - because CSS treats all three as the same token and a
 * raw-text blacklist only catches the first. The MUST-ACCEPT half exists
 * because an over-strict sanitizer is its own failure mode: if ordinary CSS
 * (icon-font content values, media range queries) cannot be stored, agents
 * fall back to raw file editing, which is the behavior this issue exists to
 * prevent.
 */
class CssSanitizerTest extends \WP_UnitTestCase
{
    /** @return array<string, array{0:string}> */
    public static function rejected_payloads(): array
    {
        return [
            // Element breakout: the primary threat model.
            'style close'            => ['</style><script>alert(1)</script>'],
            'style close spaced'     => ['</ style><script>alert(1)</script>'],
            'script open'            => ['a{}<script>alert(1)</script>'],
            'img onerror'            => ['a{}<img src=x onerror=alert(1)>'],
            'comment opener'         => ['a{}<!-- x'],

            // Legacy script-capable CSS features, plain spelling.
            'expression'             => ['a{width:expression(alert(1))}'],
            'behavior'               => ['a{behavior:url(evil.htc)}'],
            'moz binding'            => ['a{-moz-binding:url(evil.xml#x)}'],
            'javascript scheme'      => ['a{background:url(javascript:alert(1))}'],
            'vbscript scheme'        => ['a{background:url(vbscript:msgbox(1))}'],
            'import'                 => ['@import url("//evil.example/x.css");'],
            'charset'                => ['@charset "UTF-8";'],
            'data url'               => ['a{background:url(data:text/html,<script>alert(1)</script>)}'],

            // Escape-obfuscated spellings. Every one of these is valid CSS an
            // engine resolves to the plain spelling above, and every one of
            // them passed the original raw-text blacklist.
            'import escaped'         => ['@im\\port url("//evil.example/x.css");'],
            'behavior escaped'       => ['a{beha\\vior:url(evil.htc)}'],
            'expression escaped'     => ['a{width:expres\\sion(alert(1))}'],
            'javascript escaped'     => ['a{background:url(java\\script:alert(1))}'],
            'import hex escaped'     => ['@\\69 mport url("//evil.example/x.css");'],
            'javascript hex escaped' => ['a{background:url(\\6a avascript:alert(1))}'],

            // Comment-split spellings, same idea via a different CSS feature.
            'expression comment'     => ['a{width:expression/**/(alert(1))}'],
            'import comment'         => ['@imp/**/ort url("//evil.example/x.css");'],

            // A '{' hidden behind an escape must still count toward balance,
            // or the block swallows whatever the renderer prints after it.
            'unbalanced braces'      => ['a{color:red'],
            'escaped brace'          => ['a{color:red}\\7b'],

            // An unterminated comment hides its tail from analysis.
            'unterminated comment'   => ['a{color:red} /* @import url("//evil.example/x.css");'],
        ];
    }

    /** @dataProvider rejected_payloads */
    public function test_sanitize_rejects_payload(string $css): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Css_Sanitizer::sanitize($css);
    }

    /** @return array<string, array{0:string}> */
    public static function accepted_payloads(): array
    {
        return [
            'plain rule'         => ['.hero { color: #fff; }'],
            'icon font content'  => ['.icon:before { content: "\\f105"; }'],
            'typographic quotes' => ['q:before { content: "\\201C"; }'],
            'escaped class'      => ['.foo\\/bar { color: red; }'],
            'media range query'  => ['@media (400px < width) { .a { color: red; } }'],
            'media min width'    => ['@media (min-width: 400px) { .a { color: red; } }'],
            'comment in css'     => ['/* a note */ .a { color: red; }'],
            'remote font url'    => ['.a { background: url(https://example.com/x.png); }'],
            'attribute selector' => ['a[href^="https"] { color: red; }'],
        ];
    }

    /** @dataProvider accepted_payloads */
    public function test_sanitize_accepts_payload(string $css): void
    {
        $this->assertSame(trim($css), Css_Sanitizer::sanitize($css));
    }

    /**
     * The stored value is the author's original text, not the canonical form:
     * canonicalization is a lens for the decision only. A rewritten stylesheet
     * that changed meaning would be worse than a hard error.
     */
    public function test_sanitize_stores_the_original_text_not_the_canonical_form(): void
    {
        $css = '.icon:before { content: "\\f105"; } /* keep me */';

        $this->assertSame($css, Css_Sanitizer::sanitize($css));
    }

    /**
     * wp_check_invalid_utf8() returns '' for input it cannot validate.
     * Storing that silently would blank the page's existing block while the
     * tool reported a successful write, so it has to be an error.
     */
    public function test_sanitize_rejects_invalid_utf8_instead_of_storing_nothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Css_Sanitizer::sanitize(".a { color: red; } \xC3\x28\xA0\xA1");
    }

    public function test_sanitize_selector_accepts_real_selectors(): void
    {
        $this->assertSame('.hero > h1:first-child', Css_Sanitizer::sanitize_selector('  .hero > h1:first-child  '));
        $this->assertSame('a[href^="https"]', Css_Sanitizer::sanitize_selector('a[href^="https"]'));
    }

    public function test_sanitize_selector_rejects_markup_and_unbalanced_parens(): void
    {
        foreach (['<script>', '.a { color: red }', ':is(.a'] as $selector) {
            try {
                Css_Sanitizer::sanitize_selector($selector);
                $this->fail("Selector \"{$selector}\" should have been rejected.");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
