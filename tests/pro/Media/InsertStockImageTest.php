<?php

namespace WPMCP\Tests\Pro\Media;

use WPMCP\MCP\{Ability, Registrar};
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Media\Stock\Insert_Stock_Image;

/**
 * insert-stock-image (issue #64, PRO): the composite search → sideload →
 * insert flow's final step. Imports a stock image (same SSRF-guarded path as
 * import-stock-image) AND inserts it into builder content (a Gutenberg image
 * block appended to the post) as recoverable operations. PRO via Pro\Gate,
 * mirroring the run-php-snippet registration-gating precedent.
 */
class InsertStockImageTest extends \WP_UnitTestCase
{
    private const IMAGE_URL = 'https://images.pexels.com/photos/123/field.jpeg';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        add_filter('pre_http_request', [$this, 'serve_image'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'serve_image'], 10);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function serve_image($preempt, $parsed_args, $url)
    {
        $body = (string) file_get_contents(DIR_TESTDATA . '/images/canola.jpg');
        if (! empty($parsed_args['filename'])) {
            file_put_contents($parsed_args['filename'], $body);
            $body = '';
        }
        return [
            'headers'  => ['content-type' => 'image/jpeg', 'content-length' => (string) filesize(DIR_TESTDATA . '/images/canola.jpg')],
            'body'     => $body,
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => $parsed_args['filename'] ?? null,
        ];
    }

    private function make_ability(): Ability
    {
        return new Ability(
            'wpmcp/insert-stock-image',
            'pro',
            'Import a stock image and insert it into a post as an image block.',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'   => ['type' => 'integer'],
                    'image_url' => ['type' => 'string'],
                ],
                'required'   => ['post_id', 'image_url'],
            ],
            [new Insert_Stock_Image(), 'handle'],
            'edit_posts',
            'media',
            'create'
        );
    }

    public function test_registrar_skips_insert_stock_image_when_free(): void
    {
        Gate::set_pro_for_tests(false);

        $registrar = new Registrar();
        $registrar->register($this->make_ability());

        $this->assertCount(0, $registrar->all());
    }

    public function test_registrar_keeps_insert_stock_image_when_pro(): void
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        $registrar->register($this->make_ability());

        $this->assertCount(1, $registrar->all());
        $this->assertSame('wpmcp/insert-stock-image', $registrar->all()[0]->name);
    }

    public function test_handler_refuses_without_a_pro_license(): void
    {
        Gate::set_pro_for_tests(false);
        $post_id = (int) $this->factory->post->create();

        $this->expectException(\RuntimeException::class);
        (new Insert_Stock_Image())->handle(['post_id' => $post_id, 'image_url' => self::IMAGE_URL]);
    }

    public function test_imports_and_inserts_an_image_block_into_the_post(): void
    {
        Gate::set_pro_for_tests(true);
        $post_id = (int) $this->factory->post->create(['post_content' => '<!-- wp:paragraph --><p>Intro.</p><!-- /wp:paragraph -->']);

        $out = (new Insert_Stock_Image())->handle([
            'post_id'     => $post_id,
            'image_url'   => self::IMAGE_URL,
            'provider'    => 'pexels',
            'alt'         => 'Golden field',
            'attribution' => 'Sam Shooter',
        ]);

        $media_id = (int) $out['media_id'];
        $this->assertNotNull(get_post($media_id));
        $this->assertNotEmpty($out['import_operation_id']);
        $this->assertNotEmpty($out['insert_operation_id']);

        $content = (string) get_post($post_id)->post_content;
        $this->assertStringContainsString('<!-- wp:image', $content);
        $this->assertStringContainsString('wp-image-' . $media_id, $content);
        $this->assertStringContainsString('Golden field', $content);
        $this->assertStringContainsString('<p>Intro.</p>', $content); // Existing content untouched.

        // Attribution metadata persisted on the attachment, same as import.
        $this->assertSame('Sam Shooter', get_post_meta($media_id, '_wpmcp_stock_attribution', true));
    }

    public function test_both_steps_roll_back_independently(): void
    {
        Gate::set_pro_for_tests(true);
        $original = '<!-- wp:paragraph --><p>Intro.</p><!-- /wp:paragraph -->';
        $post_id  = (int) $this->factory->post->create(['post_content' => $original]);

        $out  = (new Insert_Stock_Image())->handle(['post_id' => $post_id, 'image_url' => self::IMAGE_URL]);
        $file = (string) get_attached_file((int) $out['media_id']);

        // Undo the insert: post content returns to its pre-insert state.
        $this->assertTrue(Rollback_Service::restore_operation($out['insert_operation_id']));
        $this->assertSame($original, (string) get_post($post_id)->post_content);

        // Undo the import: the attachment and its file are removed.
        $this->assertTrue(Rollback_Service::restore_operation($out['import_operation_id']));
        $this->assertNull(get_post((int) $out['media_id']));
        $this->assertFileDoesNotExist($file);
    }
}
