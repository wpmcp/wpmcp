<?php

namespace WPMCP\Tests\Free\Sync;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Backup\Site_Backup_Dir;
use WPMCP\Tools\Sync\Build_Change_Set;
use WPMCP\Tools\Sync\Change_Set_Builder;
use WPMCP\Tools\Sync\Get_Change_Set;

/**
 * The two MCP-facing halves of change-set export.
 *
 * The containment and failure-mode tests are the important ones. Both tools
 * are driven by a language model acting on text it read somewhere on the
 * site, so "inspect the change set at ../../wp-config.php" is a request that
 * will eventually be made; and a failure that comes back as a normal result
 * is a failure the caller records as a success.
 */
class ChangeSetToolsTest extends \WP_UnitTestCase
{
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    private function note(string $op, string $session, int $post_id): int
    {
        return Snapshot_Store::save(
            $op,
            $session,
            ['object_type' => 'post', 'object_id' => $post_id, 'data' => ['post' => null, 'meta' => []]],
            'update-post',
            str_repeat('a', 64)
        );
    }

    private function build_artifact(string $session = 'sess'): array
    {
        $post = self::factory()->post->create(['post_title' => 'Synced']);
        $this->note('op-' . $session, $session, $post);

        $out = (new Build_Change_Set())->handle(['session_id' => $session]);
        $this->cleanup[] = $out['file'];
        return $out;
    }

    public function test_missing_marker_throws_rather_than_returning_a_successful_looking_result(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Build_Change_Set())->handle([]);
    }

    public function test_an_empty_session_id_is_an_argument_error_not_an_empty_change_set(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Build_Change_Set())->handle(['session_id' => '   ']);
    }

    public function test_two_markers_are_refused_rather_than_one_being_silently_dropped(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Build_Change_Set())->handle(['session_id' => 'sess', 'since_id' => 1]);
    }

    public function test_a_marker_with_nothing_syncable_writes_no_artifact(): void
    {
        $out = (new Build_Change_Set())->handle(['session_id' => 'empty-session']);

        $this->assertArrayNotHasKey('file', $out);
        $this->assertSame(0, $out['objects']['total']);
    }

    public function test_object_counts_are_reported_as_exported_and_deleted_separately(): void
    {
        $kept    = self::factory()->post->create();
        $removed = self::factory()->post->create();
        $this->note('op-1', 'counts', $kept);
        $this->note('op-2', 'counts', $removed);
        wp_delete_post($removed, true);

        $out = (new Build_Change_Set())->handle(['session_id' => 'counts']);
        $this->cleanup[] = $out['file'];

        $this->assertSame(1, $out['objects']['exported'], 'A deletion marker is not an object anyone can push');
        $this->assertSame(1, $out['objects']['deleted']);
        $this->assertSame(2, $out['objects']['total']);
    }

    public function test_the_artifact_is_written_into_the_protected_backup_directory(): void
    {
        $out = $this->build_artifact();

        $this->assertFileExists($out['file']);
        $this->assertStringStartsWith(realpath(Site_Backup_Dir::path()), realpath($out['file']));
        $this->assertGreaterThan(0, $out['size']);
    }

    public function test_the_artifact_is_inspectable_before_it_is_applied_anywhere(): void
    {
        $out = $this->build_artifact('inspect');

        $summary = (new Get_Change_Set())->handle(['path' => $out['file']]);

        $this->assertSame(Change_Set_Builder::FORMAT_VERSION, $summary['format_version']);
        $this->assertSame(site_url(), $summary['origin']['site_url']);
        $this->assertCount(1, $summary['objects']);
        $this->assertSame('post', $summary['objects'][0]['object_type']);
        $this->assertFalse($summary['objects'][0]['deleted']);
        $this->assertArrayNotHasKey('objects_full', $summary);

        $full = (new Get_Change_Set())->handle(['path' => $out['file'], 'include_objects' => true]);
        $this->assertSame('Synced', $full['objects_full'][0]['data']['post_title']);
    }

    public function test_a_path_outside_the_backup_directory_is_refused(): void
    {
        $outside = trailingslashit(sys_get_temp_dir()) . 'wpmcp-not-a-change-set.json';
        file_put_contents($outside, '{"format_version":1}');
        $this->cleanup[] = $outside;

        $this->expectException(\RuntimeException::class);
        (new Get_Change_Set())->handle(['path' => $outside]);
    }

    public function test_an_empty_path_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Get_Change_Set())->handle(['path' => '']);
    }

    public function test_a_hand_edited_artifact_is_reported_not_fatal(): void
    {
        $out = $this->build_artifact('edited');

        // Anything with write access to the directory could truncate this
        // file; decoding it must not be a TypeError in array_map().
        file_put_contents($out['file'], wp_json_encode([
            'format_version' => Change_Set_Builder::FORMAT_VERSION,
            'objects'        => ['not-an-object', ['object_type' => 'post', 'object_id' => 1]],
        ]));

        $summary = (new Get_Change_Set())->handle(['path' => $out['file']]);

        $this->assertCount(1, $summary['objects']);
        $this->assertSame(1, $summary['objects'][0]['object_id']);
    }

    public function test_an_artifact_from_a_future_format_version_is_refused(): void
    {
        $out = $this->build_artifact('future');
        file_put_contents($out['file'], wp_json_encode([
            'format_version' => Change_Set_Builder::FORMAT_VERSION + 1,
            'objects'        => [],
        ]));

        $this->expectException(\RuntimeException::class);
        (new Get_Change_Set())->handle(['path' => $out['file']]);
    }
}
