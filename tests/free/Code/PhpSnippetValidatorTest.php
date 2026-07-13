<?php

namespace WPMCP\Tests\Free\Code;

use WPMCP\Tools\Code\Php_Snippet_Validator;

class PhpSnippetValidatorTest extends \WP_UnitTestCase
{
    public function test_valid_snippet_reports_syntax_valid_and_safe(): void
    {
        $result = Php_Snippet_Validator::validate('<?php echo 1 + 1;');

        $this->assertTrue($result['syntax_valid']);
        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['safe']);
    }

    public function test_syntax_error_snippet_reports_invalid_with_error(): void
    {
        $result = Php_Snippet_Validator::validate("<?php echo 'unterminated;");

        $this->assertFalse($result['syntax_valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertArrayHasKey('message', $result['errors'][0]);
        $this->assertArrayHasKey('line', $result['errors'][0]);
        $this->assertSame(1, $result['errors'][0]['line']);
    }
}
