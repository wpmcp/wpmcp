<?php

namespace WPMCP\Tests\Free\ACF;

use WPMCP\Tools\ACF\List_Field_Groups;

class ListFieldGroupsTest extends \WP_UnitTestCase
{
    public function test_lists_registered_field_groups(): void
    {
        if (! wpmcp_acf_active()) {
            $this->markTestSkipped('ACF not active');
        }

        acf_add_local_field_group([
            'key'      => 'group_wpmcp_test_list',
            'title'    => 'WPMCP Test Group',
            'fields'   => [
                [
                    'key'   => 'field_wpmcp_test_list_text',
                    'label' => 'Test Text',
                    'name'  => 'wpmcp_test_text',
                    'type'  => 'text',
                ],
            ],
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'post',
                    ],
                ],
            ],
        ]);

        $out = (new List_Field_Groups())->handle([]);

        $this->assertArrayHasKey('field_groups', $out);
        $keys = array_column($out['field_groups'], 'key');
        $this->assertContains('group_wpmcp_test_list', $keys);

        $row = $out['field_groups'][array_search('group_wpmcp_test_list', $keys, true)];
        $this->assertSame('WPMCP Test Group', $row['title']);
        $this->assertArrayHasKey('location', $row);
    }
}
