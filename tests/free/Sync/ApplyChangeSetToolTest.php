<?php

namespace WPMCP\Tests\Free\Sync;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Sync\Apply_Change_Set;
use WPMCP\Tools\Sync\Build_Change_Set;
use WPMCP\Tools\Sync\Get_Change_Set;

/**
 * The MCP-facing apply tool: the origin-to-target handoff over the connect
 * layer (get-change-set raw=true on one site, apply-change-set on the
 * other), and the safe default of dry_run.
 */
class ApplyChangeSetToolTest extends \WP_UnitTestCase
{
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /** @return array{0:int, 1:string} post id, artifact path */
    private function built(): array
    {
        $post = self::factory()->post->create(['post_content' => 'base']);
        Safe_Mutation::run(
            ['object_type' => 'post', 'object_id' => $post, 'session_id' => 'tool-build', 'tool_name' => 'update-post', 'args' => []],
            static fn () => wp_update_post(['ID' => $post, 'post_content' => 'pushed'])
        );
        $out             = (new Build_Change_Set())->handle(['session_id' => 'tool-build']);
        $this->cleanup[] = $out['file'];
        wp_update_post(['ID' => $post, 'post_content' => 'base']);
        return [$post, $out['file']];
    }

    public function test_dry_run_is_the_default(): void
    {
        [$post, $file] = $this->built();
        $raw           = (new Get_Change_Set())->handle(['path' => $file, 'raw' => true]);

        $report = (new Apply_Change_Set())->handle(['change_set' => $raw['change_set']]);

        $this->assertTrue($report['dry_run']);
        $this->assertSame(1, $report['summary']['would_apply']);
        $this->assertSame('base', get_post_field('post_content', $post));
    }

    public function test_the_raw_artifact_round_trips_through_json_and_applies(): void
    {
        [$post, $file] = $this->built();
        $raw           = (new Get_Change_Set())->handle(['path' => $file, 'raw' => true]);

        // What an MCP client does with it: JSON over the wire and back.
        $wire = json_decode((string) wp_json_encode($raw['change_set']), true);

        $report = (new Apply_Change_Set())->handle(['change_set' => $wire, 'dry_run' => false, 'session_id' => 'tool-sync']);

        $this->assertFalse($report['dry_run']);
        $this->assertSame(1, $report['summary']['applied']);
        $this->assertSame('pushed', get_post_field('post_content', $post));
        $this->assertStringContainsString('tool-sync', (string) $report['rollback']);
    }

    public function test_an_artifact_in_the_backup_directory_can_be_applied_by_path(): void
    {
        [$post, $file] = $this->built();

        (new Apply_Change_Set())->handle(['path' => $file, 'dry_run' => 'false']);

        $this->assertSame('pushed', get_post_field('post_content', $post));
    }

    public function test_exactly_one_source_is_required(): void
    {
        [, $file] = $this->built();
        $raw      = (new Get_Change_Set())->handle(['path' => $file, 'raw' => true]);

        foreach ([[], ['path' => $file, 'change_set' => $raw['change_set']]] as $args) {
            try {
                (new Apply_Change_Set())->handle($args);
                $this->fail('Ambiguous or missing source must throw');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('exactly one', $e->getMessage());
            }
        }
    }

    public function test_an_inline_artifact_that_is_not_one_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Apply_Change_Set())->handle(['change_set' => ['format_version' => 2, 'objects' => []]]);
    }

    public function test_the_summary_never_carries_media_bytes(): void
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixture bytes.
        $upload = wp_upload_bits('summary-pixel.png', null, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $image  = (int) wp_insert_attachment(['post_mime_type' => 'image/png', 'post_status' => 'inherit'], $upload['file']);
        $post   = self::factory()->post->create();
        Safe_Mutation::run(
            ['object_type' => 'post', 'object_id' => $post, 'session_id' => 'bytes', 'tool_name' => 'update-post', 'args' => []],
            static fn () => wp_update_post(['ID' => $post, 'post_content' => '<img class="wp-image-' . $image . '">'])
        );
        $out             = (new Build_Change_Set())->handle(['session_id' => 'bytes']);
        $this->cleanup[] = $out['file'];

        $summary = (new Get_Change_Set())->handle(['path' => $out['file']]);

        $this->assertArrayNotHasKey('bytes', $summary['attachments'][0]);
        $this->assertTrue($summary['attachments'][0]['bytes_included']);
    }
}
