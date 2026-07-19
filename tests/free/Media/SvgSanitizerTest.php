<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Tools\Media\Svg_Sanitizer;

/**
 * Bundled SVG sanitizer (issue #64). Fails closed: any construct with script
 * or external-fetch capability REJECTS the whole document (throws), while
 * merely-unwanted-but-harmless content (comments, metadata) is stripped from
 * accepted documents. The malicious corpus below is the acceptance criterion:
 * script / foreignObject / event-handler payloads must all be rejected.
 */
class SvgSanitizerTest extends \WP_UnitTestCase
{
    /** @return array<string, array{string}> */
    public function malicious_corpus(): array
    {
        return [
            'script element'                 => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'mixed-case script element'      => ['<svg xmlns="http://www.w3.org/2000/svg"><ScRiPt>alert(1)</ScRiPt></svg>'],
            'onload handler on root'         => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>'],
            'uppercase ONLOAD handler'       => ['<svg xmlns="http://www.w3.org/2000/svg" ONLOAD="alert(1)"><rect width="1" height="1"/></svg>'],
            'onclick handler on shape'       => ['<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" onclick="alert(1)"/></svg>'],
            'foreignObject payload'          => ['<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><iframe xmlns="http://www.w3.org/1999/xhtml" src="https://evil.example"></iframe></foreignObject></svg>'],
            'javascript: xlink href'         => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>'],
            'javascript: plain href'         => ['<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>'],
            'external use href'              => ['<svg xmlns="http://www.w3.org/2000/svg"><use href="https://evil.example/sprite.svg#icon"/></svg>'],
            'external image href'            => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="http://evil.example/x.png"/></svg>'],
            'data:text/html href'            => ['<svg xmlns="http://www.w3.org/2000/svg"><a href="data:text/html;base64,PHNjcmlwdD4="><rect width="1" height="1"/></a></svg>'],
            'doctype entity (billion laughs)' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;">]><svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>'],
            'style with external url()'      => ['<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" style="fill:url(https://evil.example/f.svg#p)"/></svg>'],
            'html root instead of svg'       => ['<html xmlns="http://www.w3.org/1999/xhtml"><body>hi</body></html>'],
            'malformed xml'                  => ['<svg xmlns="http://www.w3.org/2000/svg"><rect'],
            'empty document'                 => [''],
        ];
    }

    /** @dataProvider malicious_corpus */
    public function test_rejects_malicious_corpus(string $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Svg_Sanitizer::sanitize($payload);
    }

    public function test_accepts_and_preserves_a_benign_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            . '<title>Dot</title>'
            . '<circle cx="5" cy="5" r="4" fill="#3858e9"/>'
            . '<rect x="1" y="1" width="2" height="2"/>'
            . '</svg>';

        $out = Svg_Sanitizer::sanitize($svg);

        $this->assertStringContainsString('<circle', $out);
        $this->assertStringContainsString('<rect', $out);
        $this->assertStringContainsString('viewBox="0 0 10 10"', $out);
        $this->assertStringContainsString('fill="#3858e9"', $out);
    }

    public function test_strips_comments_and_metadata_from_accepted_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
            . '<!-- exported by SomeTool 1.2 -->'
            . '<metadata>creator info</metadata>'
            . '<rect width="1" height="1"/>'
            . '</svg>';

        $out = Svg_Sanitizer::sanitize($svg);

        $this->assertStringNotContainsString('exported by', $out);
        $this->assertStringNotContainsString('<metadata', $out);
        $this->assertStringContainsString('<rect', $out);
    }

    public function test_allows_internal_fragment_references(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<defs><linearGradient id="g"><stop offset="0" stop-color="#000"/></linearGradient></defs>'
            . '<rect width="1" height="1" fill="url(#g)"/>'
            . '<use xlink:href="#g"/>'
            . '</svg>';

        $out = Svg_Sanitizer::sanitize($svg);

        $this->assertStringContainsString('linearGradient', $out);
        $this->assertStringContainsString('url(#g)', $out);
    }
}
