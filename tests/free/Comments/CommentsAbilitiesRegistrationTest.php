<?php

namespace WPMCP\Tests\Free\Comments;

class CommentsAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const TOOLS = [
        'wpmcp/list-comments',
        'wpmcp/get-comment',
        'wpmcp/moderate-comment',
        'wpmcp/edit-comment',
        'wpmcp/delete-comment',
        'wpmcp/create-comment',
        'wpmcp/reply-to-comment',
    ];

    public function test_all_comment_tools_are_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (self::TOOLS as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_comment_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach (self::TOOLS as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }
}
