<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Url_Rewriter;

/**
 * Url_Rewriter is the serialization-aware search and replace that restore and
 * migration both depend on. The tests that matter here are the ones that
 * catch the classic WordPress migration bug: a replaced string inside
 * serialized data whose declared byte length was not updated, which
 * unserialize() then rejects, silently emptying the option.
 */
class UrlRewriterTest extends \WP_UnitTestCase
{
    private Url_Rewriter $rewriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rewriter = new Url_Rewriter();
    }

    public function test_replaces_inside_a_plain_string(): void
    {
        $this->assertSame(
            'go to https://new.example/page',
            $this->rewriter->rewrite('go to https://old.example/page', 'https://old.example', 'https://new.example')
        );
    }

    public function test_serialized_string_stays_unserializable_with_a_corrected_length(): void
    {
        // The whole point: the replacement is longer than the original, so a
        // byte-level replace would leave s:19 in front of a 23-byte string.
        $original = serialize(['url' => 'https://old.example']);

        $rewritten = $this->rewriter->rewrite($original, 'https://old.example', 'https://longer.example.com');

        $decoded = unserialize($rewritten);

        $this->assertIsArray($decoded, 'The rewritten value must still unserialize.');
        $this->assertSame('https://longer.example.com', $decoded['url']);
    }

    public function test_rewrites_nested_serialized_structures(): void
    {
        $original = serialize([
            'widget' => serialize(['link' => 'https://old.example/a']),
            'list'   => ['https://old.example/b', 'untouched'],
        ]);

        $decoded = unserialize($this->rewriter->rewrite($original, 'https://old.example', 'https://new.example'));

        $this->assertSame('https://new.example/b', $decoded['list'][0]);
        $this->assertSame('untouched', $decoded['list'][1]);

        $inner = unserialize($decoded['widget']);
        $this->assertSame('https://new.example/a', $inner['link'], 'A serialized string nested inside serialized data must be rewritten too.');
    }

    public function test_preserves_scalar_types(): void
    {
        $original = serialize(['count' => 5, 'ratio' => 1.5, 'on' => true, 'nothing' => null]);

        $decoded = unserialize($this->rewriter->rewrite($original, 'https://old.example', 'https://new.example'));

        $this->assertSame(5, $decoded['count']);
        $this->assertSame(1.5, $decoded['ratio']);
        $this->assertTrue($decoded['on']);
        $this->assertNull($decoded['nothing']);
    }

    public function test_rewrites_array_keys_as_well_as_values(): void
    {
        $out = $this->rewriter->rewrite(['https://old.example' => 'v'], 'https://old.example', 'https://new.example');

        $this->assertSame(['https://new.example' => 'v'], $out);
    }

    public function test_undecodable_serialized_looking_value_is_returned_untouched(): void
    {
        // Declares 99 bytes but carries far fewer: unserialize() fails, and
        // guessing at a repair would corrupt data rather than preserve it.
        $broken = 'a:1:{s:3:"url";s:99:"https://old.example";}';

        $this->assertSame(
            $broken,
            $this->rewriter->rewrite($broken, 'https://old.example', 'https://new.example')
        );
    }

    public function test_serialized_false_is_not_mistaken_for_a_decode_failure(): void
    {
        $this->assertSame('b:0;', $this->rewriter->rewrite('b:0;', 'x', 'y'));
    }

    public function test_serialized_object_is_left_byte_identical_rather_than_rewritten(): void
    {
        // Re-serializing a __PHP_Incomplete_Class does not reliably reproduce
        // the original bytes, so a value holding an object is refused rather
        // than rewritten. A missed URL is recoverable; a mangled object is
        // not. rewrite() must also never instantiate the class.
        $payload = 'O:8:"stdClass":1:{s:3:"url";s:19:"https://old.example";}';

        $out = $this->rewriter->rewrite($payload, 'https://old.example', 'https://new.example');

        $this->assertSame($payload, $out);
        $this->assertFalse($this->rewriter->would_rewrite($payload));
    }

    public function test_object_nested_inside_an_array_also_blocks_the_rewrite(): void
    {
        // The unsafe part can be buried: an options row holding an array of
        // settings where one entry is a serialized object must be refused as
        // a whole, not partially rewritten.
        $payload = 'a:2:{s:3:"url";s:19:"https://old.example";s:3:"obj";O:8:"stdClass":0:{}}';

        $this->assertSame(
            $payload,
            $this->rewriter->rewrite($payload, 'https://old.example', 'https://new.example')
        );
        $this->assertFalse($this->rewriter->would_rewrite($payload));
    }

    public function test_would_rewrite_is_true_for_ordinary_values(): void
    {
        $this->assertTrue($this->rewriter->would_rewrite(serialize(['url' => 'https://old.example'])));
        $this->assertTrue($this->rewriter->would_rewrite('a plain string'));
    }

    public function test_no_op_when_from_and_to_match_or_from_is_empty(): void
    {
        $this->assertSame('unchanged', $this->rewriter->rewrite('unchanged', 'same', 'same'));
        $this->assertSame('unchanged', $this->rewriter->rewrite('unchanged', '', 'x'));
    }

    public function test_depth_limit_stops_runaway_recursion(): void
    {
        $value = 'https://old.example';
        for ($i = 0; $i < Url_Rewriter::MAX_DEPTH + 5; $i++) {
            $value = serialize([$value]);
        }

        // Must return rather than exhaust the stack; the outer levels are
        // still rewritten, the ones past the bound are left as they were.
        $out = $this->rewriter->rewrite($value, 'https://old.example', 'https://new.example');

        $this->assertIsString($out);
    }

    public function test_replacement_pairs_cover_json_escaped_and_scheme_relative_forms(): void
    {
        $pairs = $this->rewriter->replacement_pairs('https://old.example', 'https://new.example');

        $froms = array_column($pairs, 'from');

        $this->assertSame('https://old.example', $froms[0], 'The full URL must be replaced first, before its substrings.');
        $this->assertContains('https:\\/\\/old.example', $froms, 'Block editor content stores JSON-escaped URLs.');
        $this->assertContains('//old.example', $froms, 'Scheme-relative URLs appear in enqueued asset markup.');
    }

    public function test_replacement_pairs_are_deduplicated(): void
    {
        $pairs = $this->rewriter->replacement_pairs('https://same.example', 'https://same.example');

        $this->assertSame([], $pairs, 'A no-op migration must produce no replacements at all.');
    }

    public function test_rewrite_url_applies_every_form(): void
    {
        $content = 'a href="https://old.example/x" and src="//old.example/y" and json "https:\\/\\/old.example\\/z"';

        $out = $this->rewriter->rewrite_url($content, 'https://old.example', 'https://new.example');

        $this->assertStringNotContainsString('old.example', $out);
        $this->assertStringContainsString('https://new.example/x', $out);
        $this->assertStringContainsString('//new.example/y', $out);
        $this->assertStringContainsString('https:\\/\\/new.example\\/z', $out);
    }

    public function test_trailing_slash_in_the_source_url_does_not_leave_a_double_slash(): void
    {
        $out = $this->rewriter->rewrite_url('https://old.example/page', 'https://old.example/', 'https://new.example/');

        $this->assertSame('https://new.example/page', $out);
    }
}
