<?php

namespace WPMCP\Tests\Pro\Chat;

use WPMCP\Pro\Chat\Conversation_Store;
use WPMCP\Tests\Free\Privacy\Privacy_Request_Runner;

/**
 * Issue #478: chat conversations take part in WordPress's personal data
 * export and erasure, through core's own admin-ajax handlers. A person's own
 * conversations are exported and deleted; another administrator's
 * conversation that quotes the person's address keeps its turns, with the
 * address taken out.
 *
 * @group ajax
 */
class ChatPrivacyRequestTest extends \WP_Ajax_UnitTestCase
{
    use Privacy_Request_Runner;

    private const EMAIL = 'grace.hopper@example.test';

    private Conversation_Store $store;
    private int $grace;
    private int $other;
    private int $hers;
    private int $theirs;
    private int $unrelated;

    protected function setUp(): void
    {
        parent::setUp();
        Conversation_Store::register_post_type();
        add_filter('pre_http_request', [$this, 'stub_version_check'], 10, 3);
        $this->store = new Conversation_Store();
        $this->grace = self::factory()->user->create([ 'role' => 'administrator', 'user_email' => self::EMAIL ]);
        $this->other = self::factory()->user->create([ 'role' => 'administrator' ]);

        $this->hers = $this->store->create($this->grace, 'Compiler notes');
        $this->store->append_message($this->hers, $this->grace, [ 'role' => 'user', 'content' => 'Draft a post about the first bug.' ]);

        $this->theirs = $this->store->create($this->other, 'Support follow-up');
        $this->store->append_message($this->theirs, $this->other, [ 'role' => 'user', 'content' => 'Email ' . self::EMAIL . ' about her order.' ]);
        $this->store->append_message($this->theirs, $this->other, [
            'role'    => 'assistant',
            'content' => 'Sent to ' . strtoupper(self::EMAIL) . '.',
            'blocks'  => [ [ 'type' => 'text', 'text' => 'Sent to ' . self::EMAIL . '.' ] ],
        ]);

        $this->unrelated = $this->store->create($this->other, 'Menus');
        $this->store->append_message($this->unrelated, $this->other, [ 'role' => 'user', 'content' => 'Rename the footer menu.' ]);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'stub_version_check'], 10);
        parent::tearDown();
    }

    public function stub_version_check($preempt, $parsed_args, $url)
    {
        if (false === strpos($url, 'api.wordpress.org') || false === strpos($url, '-check')) {
            return $preempt;
        }
        return [
            'headers'  => [],
            'body'     => '',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    public function test_an_export_lists_her_conversations_and_the_turns_that_quote_her(): void
    {
        $export = $this->run_core_export(self::EMAIL);

        $this->assertArrayHasKey('wpmcp-chat', $export);
        $text = self::export_text($export['wpmcp-chat']);
        $this->assertStringContainsString('Compiler notes', $text);
        $this->assertStringContainsString('Draft a post about the first bug.', $text);
        $this->assertStringContainsString('Email ' . self::EMAIL . ' about her order.', $text);
        $this->assertStringNotContainsString('Rename the footer menu.', $text);
    }

    public function test_an_erase_deletes_her_conversations_and_takes_her_address_out_of_others(): void
    {
        $responses = $this->run_core_erasure(self::EMAIL);
        $this->assertArrayHasKey('wpmcp-chat', $responses);

        $this->assertNotEmpty(array_filter($responses['wpmcp-chat'], static fn (array $page): bool => ! empty($page['items_removed'])));
        $this->assertNull(get_post($this->hers), 'Her own conversation is deleted');

        $messages = $this->store->get_messages($this->theirs, $this->other);
        $this->assertCount(2, $messages, 'Another administrator keeps their conversation');
        $this->assertStringNotContainsStringIgnoringCase(self::EMAIL, (string) wp_json_encode($messages));
        $this->assertSame('Rename the footer menu.', $this->store->get_messages($this->unrelated, $this->other)[0]['content']);
    }
}
