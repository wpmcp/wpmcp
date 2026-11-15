<?php

namespace WPMCP\Tests\Free\Privacy;

use WPMCP\Integrations\WS_Form_Pack;
use WPMCP\Privacy\Masking;

/**
 * The shared list/export masking rule (#708, #866 and the list masking audit).
 */
class MaskingTest extends \WP_UnitTestCase
{
    public function test_an_email_keeps_its_first_character_and_domain(): void
    {
        $this->assertSame('j***@example.com', Masking::value('jane.doe@example.com'));
        $this->assertSame('j***@example.com', Masking::email(' jane.doe@example.com '));
    }

    public function test_other_text_keeps_its_first_character(): void
    {
        $this->assertSame('J***', Masking::value('Jane Doe'));
        $this->assertSame('2***', Masking::value('203.0.113.7'));
        $this->assertSame('É***', Masking::value('Émile'));
    }

    public function test_arrays_mask_item_by_item_and_empty_values_stay(): void
    {
        $this->assertSame([ 'a' => 'J***', 'b' => [ 'x***@y.test' ], 'c' => '', 'd' => null, 'e' => false ], Masking::value([ 'a' => 'Jane', 'b' => [ 'xx@y.test' ], 'c' => '', 'd' => null, 'e' => false ]));
    }

    public function test_keys_masks_only_the_named_keys(): void
    {
        $this->assertSame([ 'id' => 7, 'email' => 'j***@e.test', 'name' => 'J***' ], Masking::keys([ 'id' => 7, 'email' => 'jane@e.test', 'name' => 'Jane' ], [ 'email', 'name', 'absent' ]));
    }

    public function test_the_ws_form_helper_is_the_shared_rule(): void
    {
        $this->assertSame(Masking::value('jane@e.test'), WS_Form_Pack::mask('jane@e.test'));
    }
}
