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
}
