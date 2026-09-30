<?php

namespace WPMCP\Tests\Free\Safety;

/**
 * Issue #440 guard: wp_update_post() and wp_trash_post() re-save a post's
 * whole row, so for a user without unfiltered_html kses rewrites the text
 * columns a call never meant to touch. Every write to an existing post goes
 * through WPMCP\Safety\Save_Filters, which filters only the columns the call
 * sets.
 *
 * This scans src/ for direct wp_update_post(), wp_insert_post() and
 * wp_trash_post() calls and fails on any that is not listed below with its
 * reason. Creating a new post is the usual legitimate case: every field of a
 * new row is new input, so all of it is filtered.
 */
class DirectPostWriteGuardTest extends \WP_UnitTestCase
{
    private const WATCHED = ['wp_update_post', 'wp_insert_post', 'wp_trash_post'];

    /**
     * File (relative to src/) => [function => number of direct calls], with
     * one line saying why each file may call it directly.
     *
     * @var array<string, array{calls: array<string, int>, reason: string}>
     */
    private const ALLOWED = [
        'Safety/Save_Filters.php'                     => [
            'calls'  => ['wp_update_post' => 1, 'wp_trash_post' => 1],
            'reason' => 'The helper itself: it lifts the filters of the columns a call leaves alone around these calls.',
        ],
        'Safety/Rollback_Service.php'                 => [
            'calls'  => ['wp_update_post' => 2, 'wp_trash_post' => 1, 'wp_insert_post' => 1],
            'reason' => 'Restores a snapshot row as stored; every call runs inside Save_Filters::without() with all post save filters lifted.',
        ],
        'Tools/SiteEditor/Site_Templates_Write.php'   => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates the first customization of a template; every field is new.',
        ],
        'Tools/SiteEditor/Global_Styles.php'          => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates the user global styles post; every field is new.',
        ],
        'Tools/Sync/Change_Set_Applier.php'           => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a post the target does not have yet; every field is new.',
        ],
        'Tools/WidgetBuilder/Widget_Spec_Store.php'   => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new widget spec post.',
        ],
        'Tools/BlockBuilder/Block_Spec_Store.php'     => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new block spec post.',
        ],
        'Tools/Compose/Build_Page.php'                => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new page.',
        ],
        'Tools/Content/Create_Post.php'               => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new post.',
        ],
        'Tools/Content/Duplicate_Post.php'            => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates the copy (or stage) of a post as a new row.',
        ],
        'Tools/Elementor/Elementor_Template_Data.php' => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new Elementor template post.',
        ],
        'Tools/Elementor/Create_Code_Snippet.php'     => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new code snippet post.',
        ],
        'Tools/ThemeBuilder/Template_Store.php'       => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new site part post.',
        ],
        'Tools/Export/Import_Content.php'             => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Imports a post as a new row.',
        ],
        'Memory/Memory_Store.php'                     => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new memory entry.',
        ],
        'Pro/Chat/Conversation_Store.php'             => [
            'calls'  => ['wp_insert_post' => 1],
            'reason' => 'Creates a new chat conversation post.',
        ],
    ];

    private static function src(): string
    {
        return dirname(__DIR__, 3) . '/src';
    }

    /**
     * Direct calls to the watched functions, found with the tokenizer so
     * comments, strings, method calls and function definitions never count.
     *
     * @return array<string, array<string, int>> file => [function => count]
     */
    private static function direct_calls(): array
    {
        $root  = self::src();
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($file->getPathname())),
                static fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ));
            foreach ($tokens as $i => $token) {
                if (! is_array($token)) {
                    continue;
                }
                $name = ltrim($token[1], '\\');
                if (! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || ! in_array($name, self::WATCHED, true)) {
                    continue;
                }
                if ('(' !== ($tokens[ $i + 1 ] ?? null)) {
                    continue;
                }
                $prev = $tokens[ $i - 1 ] ?? null;
                if (is_array($prev) && in_array($prev[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    continue;
                }
                $relative                  = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $found[ $relative ][ $name ] = ($found[ $relative ][ $name ] ?? 0) + 1;
            }
        }
        ksort($found);
        return $found;
    }

    public function test_the_scan_sees_the_known_direct_calls(): void
    {
        $found = self::direct_calls();
        $this->assertSame(['wp_update_post' => 1, 'wp_trash_post' => 1], $found['Safety/Save_Filters.php'] ?? null, 'The scan must find the calls inside Save_Filters, or it proves nothing.');
    }

    public function test_every_direct_post_write_is_on_the_documented_allowlist(): void
    {
        $unlisted = [];
        foreach (self::direct_calls() as $file => $calls) {
            foreach ($calls as $function => $count) {
                $allowed = self::ALLOWED[ $file ]['calls'][ $function ] ?? 0;
                if ($count > $allowed) {
                    $unlisted[] = sprintf('src/%s calls %s() directly %d time(s), %d allowed', $file, $function, $count, $allowed);
                }
            }
        }

        $this->assertSame(
            [],
            $unlisted,
            "A write to an existing post must go through WPMCP\\Safety\\Save_Filters (update_post, set_post_status, trash_post),\n"
            . "which filters only the columns it sets. If the call creates a new post, add it to ALLOWED with its reason.\n"
        );
    }

    public function test_the_allowlist_has_no_stale_entries(): void
    {
        $found = self::direct_calls();
        $stale = [];
        foreach (self::ALLOWED as $file => $entry) {
            $this->assertNotSame('', trim($entry['reason']), "src/{$file} needs a reason.");
            foreach ($entry['calls'] as $function => $allowed) {
                $count = $found[ $file ][ $function ] ?? 0;
                if ($count !== $allowed) {
                    $stale[] = sprintf('src/%s: %s() allowed %d, found %d', $file, $function, $allowed, $count);
                }
            }
        }
        $this->assertSame([], $stale, 'Keep ALLOWED exact, so a removed call does not leave room for a new one.');
    }
}
