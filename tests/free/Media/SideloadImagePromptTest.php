<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Plugin;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Media\Sideload_Image;

/**
 * sideload-image prompt mode (issue #456): generate an image through
 * WordPress core's AI client (wp_ai_client_prompt(), 7.0+) with whatever
 * provider the site owner configured under Settings > Connectors, then save
 * it through the same image guard as a remote sideload. wpmcp never holds a
 * provider key and never picks a provider.
 *
 * No test here reaches a provider: the prompt builder is injected (a fake
 * with the core builder's method names), and the two tests that use core's
 * real builder run with no provider configured, where core refuses before
 * any request. Every outbound HTTP request is counted and must stay at zero
 * unless a test serves one on purpose.
 */
class SideloadImagePromptTest extends \WP_UnitTestCase
{
    private int $http_calls = 0;

    /** @var string|null body served for a remote image URL, null to fail the request. */
    private ?string $serve = null;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        add_filter('pre_http_request', [$this, 'intercept'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'intercept'], 10);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function intercept($preempt, $parsed_args, $url)
    {
        $this->http_calls++;
        if (null === $this->serve) {
            return new \WP_Error('wpmcp_test_no_network', 'No outbound requests in this test: ' . $url);
        }
        if (! empty($parsed_args['filename'])) {
            file_put_contents($parsed_args['filename'], $this->serve);
        }
        return [
            'headers'  => ['content-type' => 'image/jpeg', 'content-length' => (string) strlen($this->serve)],
            'body'     => '',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => $parsed_args['filename'] ?? null,
        ];
    }

    private static function jpeg(): string
    {
        // 640x480, so WordPress builds thumbnail and medium sizes from it.
        return (string) file_get_contents(DIR_TESTDATA . '/images/canola.jpg');
    }

    private function attachment_count(): int
    {
        return count(get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1]));
    }

    /**
     * A stand-in for WP_AI_Client_Prompt_Builder: the same snake_case method
     * names core's builder exposes, recording what the tool asked for.
     */
    private function fake_builder(bool $supported = true, $result = null): object
    {
        $file = $result ?? self::file(base64_encode(self::jpeg()), 'image/jpeg');

        return new class ($supported, $file) {
            public array $calls = [];
            public ?string $ratio = null;

            public function __construct(private bool $supported, private $file)
            {
            }

            public function as_output_media_aspect_ratio(string $ratio): self
            {
                $this->calls[] = 'as_output_media_aspect_ratio';
                $this->ratio   = $ratio;
                return $this;
            }

            public function is_supported_for_image_generation(): bool
            {
                $this->calls[] = 'is_supported_for_image_generation';
                return $this->supported;
            }

            public function generate_image_result()
            {
                $this->calls[] = 'generate_image_result';
                if ($this->file instanceof \WP_Error) {
                    return $this->file;
                }
                $file = $this->file;
                return new class ($file) {
                    public function __construct(private $file)
                    {
                    }
                    public function toImageFile()
                    {
                        return $this->file;
                    }
                    public function getProviderMetadata()
                    {
                        return new class () {
                            public function getId(): string
                            {
                                return 'fake-provider';
                            }
                        };
                    }
                    public function getModelMetadata()
                    {
                        return new class () {
                            public function getId(): string
                            {
                                return 'fake-image-model-1';
                            }
                        };
                    }
                };
            }
        };
    }

    /** A stand-in for the SDK's File DTO, inline or remote. */
    private static function file(?string $base64, string $mime, ?string $url = null): object
    {
        return new class ($base64, $mime, $url) {
            public function __construct(private ?string $base64, private string $mime, private ?string $url)
            {
            }
            public function isRemote(): bool
            {
                return null !== $this->url;
            }
            public function getUrl(): ?string
            {
                return $this->url;
            }
            public function getBase64Data(): ?string
            {
                return $this->base64;
            }
            public function getMimeType(): string
            {
                return $this->mime;
            }
        };
    }

    private function tool(object $builder, ?array &$prompts = null): Sideload_Image
    {
        $prompts = [];
        return new Sideload_Image(static function (string $prompt) use ($builder, &$prompts) {
            $prompts[] = $prompt;
            return $builder;
        });
    }

    public function test_a_prompt_creates_one_attachment_with_sizes_alt_title_and_generation_meta(): void
    {
        $before  = $this->attachment_count();
        $parent  = self::factory()->post->create();
        $builder = $this->fake_builder();

        $out = $this->tool($builder, $prompts)->handle([
            'prompt'      => 'A canola field under a wide blue sky',
            'alt'         => 'Yellow canola field',
            'description' => 'Canola field',
            'post_id'     => $parent,
        ]);

        $this->assertSame(['A canola field under a wide blue sky'], $prompts);
        $this->assertSame(['is_supported_for_image_generation', 'generate_image_result'], $builder->calls);
        $this->assertSame($before + 1, $this->attachment_count());

        $post = get_post($out['media_id']);
        $this->assertNotNull($post);
        $this->assertSame('attachment', $post->post_type);
        $this->assertSame('image/jpeg', $post->post_mime_type);
        $this->assertSame('Canola field', $post->post_title);
        $this->assertSame($parent, (int) $post->post_parent);
        $this->assertSame('Yellow canola field', get_post_meta($out['media_id'], '_wp_attachment_image_alt', true));

        $meta = wp_get_attachment_metadata($out['media_id']);
        $this->assertNotEmpty($meta['sizes'] ?? [], 'the generated image must get its intermediate sizes');

        $this->assertSame('A canola field under a wide blue sky', get_post_meta($out['media_id'], '_wpmcp_ai_prompt', true));
        $this->assertSame('fake-provider', get_post_meta($out['media_id'], '_wpmcp_ai_provider', true));
        $this->assertSame('fake-image-model-1', get_post_meta($out['media_id'], '_wpmcp_ai_model', true));

        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame('fake-provider', $out['provider']);
        $this->assertSame('fake-image-model-1', $out['model']);
        $this->assertSame(0, $this->http_calls);
    }

    public function test_ratio_is_passed_to_the_ai_client(): void
    {
        $builder = $this->fake_builder();

        $this->tool($builder)->handle(['prompt' => 'A lighthouse', 'ratio' => '16:9']);

        $this->assertSame('16:9', $builder->ratio);
    }

    public function test_a_malformed_ratio_is_refused_before_the_ai_client_is_asked(): void
    {
        $builder = $this->fake_builder();

        try {
            $this->tool($builder)->handle(['prompt' => 'A lighthouse', 'ratio' => 'wide']);
            $this->fail('Expected a malformed ratio to be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('ratio', $e->getMessage());
        }
        $this->assertSame([], $builder->calls);
    }

    public function test_no_image_capable_provider_is_refused_without_generating_or_any_request(): void
    {
        $before  = $this->attachment_count();
        $builder = $this->fake_builder(false);

        try {
            $this->tool($builder)->handle(['prompt' => 'A lighthouse']);
            $this->fail('Expected the call to be refused without an image-capable provider.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Connectors', $e->getMessage());
            $this->assertStringContainsString('image', $e->getMessage());
        }

        $this->assertNotContains('generate_image_result', $builder->calls);
        $this->assertSame($before, $this->attachment_count());
        $this->assertSame(0, $this->http_calls);
    }

    public function test_core_ai_client_with_no_provider_configured_refuses_without_a_request(): void
    {
        if (! function_exists('wp_ai_client_prompt')) {
            $this->markTestSkipped('This WordPress has no AI client (7.0+).');
        }
        $before = $this->attachment_count();

        try {
            (new Sideload_Image())->handle(['prompt' => 'A lighthouse at dusk']);
            $this->fail('Expected a refusal with no AI provider configured.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Connectors', $e->getMessage());
        }

        $this->assertSame($before, $this->attachment_count());
        $this->assertSame(0, $this->http_calls, 'no request may leave the site when no provider is configured');
    }

    public function test_ai_disabled_on_the_site_is_refused_with_its_own_reason(): void
    {
        if (! function_exists('wp_supports_ai')) {
            $this->markTestSkipped('This WordPress has no AI client (7.0+).');
        }
        add_filter('wp_supports_ai', '__return_false');
        $builder = $this->fake_builder();

        try {
            $this->tool($builder)->handle(['prompt' => 'A lighthouse']);
            $this->fail('Expected a refusal when AI is disabled on the site.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        } finally {
            remove_filter('wp_supports_ai', '__return_false');
        }

        $this->assertSame([], $builder->calls);
        $this->assertSame(0, $this->http_calls);
    }

    public function test_a_provider_error_is_reported_and_creates_nothing(): void
    {
        $before  = $this->attachment_count();
        $builder = $this->fake_builder(true, new \WP_Error('prompt_client_error', 'Quota exceeded'));

        try {
            $this->tool($builder)->handle(['prompt' => 'A lighthouse']);
            $this->fail('Expected the provider error to surface.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Quota exceeded', $e->getMessage());
        }

        $this->assertSame($before, $this->attachment_count());
    }

    public function test_generated_bytes_that_are_not_an_image_are_refused_by_the_guard(): void
    {
        $before  = $this->attachment_count();
        $builder = $this->fake_builder(true, self::file(base64_encode('<?php echo 1; ?>'), 'image/png'));

        try {
            $this->tool($builder)->handle(['prompt' => 'A lighthouse']);
            $this->fail('Expected non-image bytes to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('image', $e->getMessage());
        }

        $this->assertSame($before, $this->attachment_count());
    }

    public function test_an_oversized_generated_image_is_refused(): void
    {
        $cap = static fn () => 1024;
        add_filter('wpmcp_remote_media_max_bytes', $cap);
        $before = $this->attachment_count();

        try {
            $this->tool($this->fake_builder())->handle(['prompt' => 'A lighthouse']);
            $this->fail('Expected an image over the byte cap to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        } finally {
            remove_filter('wpmcp_remote_media_max_bytes', $cap);
        }

        $this->assertSame($before, $this->attachment_count());
    }

    public function test_a_remote_result_is_fetched_through_the_guarded_download(): void
    {
        $this->serve = self::jpeg();
        $resolve     = static fn () => ['93.184.216.34'];
        add_filter('wpmcp_remote_host_addresses', $resolve);
        $builder = $this->fake_builder(true, self::file(null, 'image/jpeg', 'https://cdn.provider.example/out/abc.jpg'));

        try {
            $out = $this->tool($builder)->handle(['prompt' => 'A lighthouse']);
        } finally {
            remove_filter('wpmcp_remote_host_addresses', $resolve);
        }

        $this->assertSame(1, $this->http_calls);
        $this->assertSame('image/jpeg', get_post_mime_type($out['media_id']));
    }

    public function test_a_remote_result_on_a_private_address_is_refused(): void
    {
        $this->serve = self::jpeg();
        $builder     = $this->fake_builder(true, self::file(null, 'image/jpeg', 'https://127.0.0.1/out.jpg'));

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->tool($builder)->handle(['prompt' => 'A lighthouse']);
        } finally {
            $this->assertSame(0, $this->http_calls);
        }
    }

    public function test_rollback_removes_the_generated_attachment_and_its_files(): void
    {
        $out   = $this->tool($this->fake_builder())->handle(['prompt' => 'A lighthouse']);
        $file  = (string) get_attached_file($out['media_id']);
        $meta  = wp_get_attachment_metadata($out['media_id']);
        $sizes = array_map(static fn ($size) => dirname($file) . '/' . $size['file'], $meta['sizes'] ?? []);
        $this->assertFileExists($file);
        $this->assertNotEmpty($sizes);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertNull(get_post($out['media_id']));
        $this->assertFileDoesNotExist($file);
        foreach ($sizes as $size) {
            $this->assertFileDoesNotExist($size);
        }
    }

    public function test_url_and_prompt_together_are_refused(): void
    {
        $builder = $this->fake_builder();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->tool($builder)->handle(['url' => 'https://example.com/a.jpg', 'prompt' => 'A lighthouse']);
        } finally {
            $this->assertSame([], $builder->calls);
            $this->assertSame(0, $this->http_calls);
        }
    }

    public function test_the_real_core_result_dto_is_read_correctly(): void
    {
        if (! class_exists(\WordPress\AiClient\Results\DTO\GenerativeAiResult::class)) {
            $this->markTestSkipped('This WordPress has no AI client (7.0+).');
        }

        $file   = new \WordPress\AiClient\Files\DTO\File(base64_encode(self::jpeg()), 'image/jpeg');
        $result = new \WordPress\AiClient\Results\DTO\GenerativeAiResult(
            'result-1',
            [
                new \WordPress\AiClient\Results\DTO\Candidate(
                    new \WordPress\AiClient\Messages\DTO\ModelMessage([new \WordPress\AiClient\Messages\DTO\MessagePart($file)]),
                    \WordPress\AiClient\Results\Enums\FinishReasonEnum::stop()
                ),
            ],
            new \WordPress\AiClient\Results\DTO\TokenUsage(1, 1, 2),
            new \WordPress\AiClient\Providers\DTO\ProviderMetadata('acme-ai', 'Acme AI', \WordPress\AiClient\Providers\Enums\ProviderTypeEnum::cloud()),
            new \WordPress\AiClient\Providers\Models\DTO\ModelMetadata('acme-image-2', 'Acme Image 2', [\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::imageGeneration()], [])
        );

        $builder = new class ($result) {
            public function __construct(private $result)
            {
            }
            public function is_supported_for_image_generation(): bool
            {
                return true;
            }
            public function generate_image_result()
            {
                return $this->result;
            }
        };

        $out = (new Sideload_Image(static fn () => $builder))->handle(['prompt' => 'A lighthouse']);

        $this->assertSame('acme-ai', get_post_meta($out['media_id'], '_wpmcp_ai_provider', true));
        $this->assertSame('acme-image-2', get_post_meta($out['media_id'], '_wpmcp_ai_model', true));
        $this->assertSame('image/jpeg', get_post_mime_type($out['media_id']));
    }

    public function test_schema_offers_prompt_and_ratio_and_no_longer_requires_url(): void
    {
        $ability = Plugin::instance()->registrar()->get('wpmcp/sideload-image');
        $this->assertNotNull($ability);

        $this->assertArrayHasKey('prompt', $ability->input_schema['properties']);
        $this->assertArrayHasKey('ratio', $ability->input_schema['properties']);
        $this->assertNotContains('url', $ability->input_schema['required'] ?? []);
        $this->assertStringContainsString('prompt', $ability->description);
    }

    public function test_creating_media_needs_upload_files(): void
    {
        $ability = Plugin::instance()->registrar()->get('wpmcp/sideload-image');
        $this->assertSame('upload_files', $ability->capability);

        $abilities = wp_get_abilities();
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $this->assertFalse($abilities['wpmcp/sideload-image']->check_permissions(), 'a Contributor cannot upload files');

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertTrue($abilities['wpmcp/sideload-image']->check_permissions());
    }
}
