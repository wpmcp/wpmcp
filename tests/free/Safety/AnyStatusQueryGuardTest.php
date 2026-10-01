<?php

namespace WPMCP\Tests\Free\Safety;

/**
 * Issue #465 guard: WP_Query applies no read permission to post_status
 * 'any' (nor to an explicit status), so a listing built on it shows other
 * users' drafts and private posts to anyone who can run it. Every such query
 * goes through WPMCP\Tools\Content\Readable_Posts, which keeps the rows to
 * core's read_post rule in SQL so totals stay honest.
 *
 * This scans src/ with the tokenizer for:
 *  - a 'post_status' array key (or $args['post_status'] assignment) whose
 *    value contains the literal 'any', and
 *  - a get_children() call that names no post_status, since its default is
 *    'any';
 * and fails on any that is neither inside a Readable_Posts::query() or
 * Readable_Posts::get_posts() call nor listed below with its reason.
 */
class AnyStatusQueryGuardTest extends \WP_UnitTestCase
{
    /** Calls that apply the per-row read filter. */
    private const HELPERS = [ 'Readable_Posts::query', 'Readable_Posts::get_posts' ];

    /**
     * File (relative to src/) :: enclosing function => [count, reason].
     *
     * @var array<string, array{count: int, reason: string}>
     */
    private const ALLOWED = [
        'Tools/Content/Readable_Posts.php::query'                          => [
            'count'  => 1,
            'reason' => 'The helper itself: it defaults post_status to any and adds the read filter.',
        ],
        'Integrations/LMS_Adapter.php::child_posts'                        => [
            'count'  => 1,
            'reason' => 'Write path only: a reorder or insert renumbers every sibling, and edit_refusal() refuses the call when a sibling the caller may not edit would change.',
        ],
        'Integrations/FunnelKit_Pack.php::offer_rows'                      => [
            'count'  => 1,
            'reason' => 'Runs only in get-funnelkit-funnel, gated on manage_woocommerce (the capability of FunnelKit\'s own funnel screens, which list every offer); returns offer ids.',
        ],
        'Integrations/MetForm_Integration.php::entry_query'                => [
            'count'  => 1,
            'reason' => 'Entry rows are listed only by list-entries at the forms entry capability (manage_options); list-forms reads only the count.',
        ],
        'Tools/WooCommerce/Catalog/Woo_Write.php::variations_at_risk'      => [
            'count'  => 1,
            'reason' => 'Existence check before a product write: answers whether any variation exists, no row leaves.',
        ],
        'Tools/Sync/Change_Set_Applier.php::find_attachment_by_file'       => [
            'count'  => 1,
            'reason' => 'apply-change-set runs at manage_options; maps an incoming attachment to the existing row by file.',
        ],
        'Tools/Sync/Change_Set_Applier.php::find_by_identity'              => [
            'count'  => 1,
            'reason' => 'apply-change-set runs at manage_options; maps an incoming post to the existing row by slug and date.',
        ],
        'Safety/Rollback_Service.php::apply_wc_product_create_snapshot'    => [
            'count'  => 1,
            'reason' => 'Rollback of an import: deletes the variations of the product the import created; nothing is listed.',
        ],
        'Pro/Chat/Conversation_Store.php::purge_for_user'                  => [
            'count'  => 1,
            'reason' => 'Author-scoped to the user being deleted; runs on user deletion, lists nothing.',
        ],
        'Pro/Chat/Conversation_Store.php::find_by_client_id'               => [
            'count'  => 1,
            'reason' => 'Author-scoped to the caller\'s own conversations, and the hit is re-checked for ownership.',
        ],
        'Pro/Chat/Conversation_Store.php::list_for_user'                   => [
            'count'  => 1,
            'reason' => 'Author-scoped to the caller\'s own conversations, and every row is re-checked for ownership.',
        ],
    ];

    private static function src(): string
    {
        return dirname(__DIR__, 3) . '/src';
    }

    /**
     * Unfiltered any-status queries in one PHP source, by enclosing named
     * function ('' at file level).
     *
     * @return array<string, int> function => count
     */
    public static function scan(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t) => ! is_array($t) || ! in_array($t[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true)
        ));
        $text   = static fn ($t): string => is_array($t) ? $t[1] : $t;
        $is_str = static fn ($t, string $value): bool => is_array($t) && T_CONSTANT_ENCAPSED_STRING === $t[0] && $value === substr($t[1], 1, -1);

        $found     = [];
        $functions = []; // [name, brace depth]
        $pending   = null;
        $depth     = 0;
        $calls     = []; // callee per open '('

        // The tokens of a value: from $start to the first ',' ';' or closing
        // bracket at its own nesting level.
        $value_has_any = static function (int $start) use ($tokens, $text, $is_str): bool {
            $level = 0;
            for ($j = $start, $n = count($tokens); $j < $n; ++$j) {
                $t = $text($tokens[ $j ]);
                if (in_array($t, [ '(', '[', '{' ], true)) {
                    ++$level;
                } elseif (in_array($t, [ ')', ']', '}' ], true)) {
                    if (0 === $level--) {
                        return false;
                    }
                } elseif (0 === $level && (',' === $t || ';' === $t)) {
                    return false;
                }
                if ($is_str($tokens[ $j ], 'any')) {
                    return true;
                }
            }
            return false;
        };
        $routed = static function () use (&$calls): bool {
            foreach ($calls as $callee) {
                if (in_array($callee, self::HELPERS, true)) {
                    return true;
                }
            }
            return false;
        };
        $record = static function () use (&$found, &$functions): void {
            $name           = [] === $functions ? '' : $functions[ count($functions) - 1 ][0];
            $found[ $name ] = ($found[ $name ] ?? 0) + 1;
        };

        foreach ($tokens as $i => $token) {
            $t = $text($token);

            if (is_array($token) && T_FUNCTION === $token[0]) {
                $next    = $tokens[ $i + 1 ] ?? null;
                $next    = '&' === $next ? ($tokens[ $i + 2 ] ?? null) : $next;
                $pending = is_array($next) && T_STRING === $next[0] ? $next[1] : null;
                continue;
            }
            if ('{' === $t || (is_array($token) && in_array($token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true))) {
                ++$depth;
                if (null !== $pending) {
                    $functions[] = [ $pending, $depth ];
                    $pending     = null;
                }
                continue;
            }
            if ('}' === $t) {
                if ([] !== $functions && $functions[ count($functions) - 1 ][1] === $depth) {
                    array_pop($functions);
                }
                --$depth;
                continue;
            }
            if (';' === $t && [] === $calls) {
                $pending = null; // an abstract or interface method has no body
            }
            if ('(' === $t) {
                $prev   = $tokens[ $i - 1 ] ?? null;
                $callee = '';
                if (is_array($prev) && in_array($prev[0], [ T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED ], true)) {
                    $callee = ltrim($prev[1], '\\');
                    $sep    = $tokens[ $i - 2 ] ?? null;
                    $class  = $tokens[ $i - 3 ] ?? null;
                    if (is_array($sep) && T_DOUBLE_COLON === $sep[0] && is_array($class)) {
                        $parts  = explode('\\', $class[1]);
                        $callee = end($parts) . '::' . $callee;
                    }
                    if ('get_children' === $callee && ! (is_array($sep) && in_array($sep[0], [ T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR, T_NEW ], true))) {
                        // get_children() defaults post_status to 'any'.
                        $names_status = false;
                        $level        = 0;
                        for ($j = $i, $n = count($tokens); $j < $n; ++$j) {
                            $u = $text($tokens[ $j ]);
                            $level += '(' === $u ? 1 : (')' === $u ? -1 : 0);
                            if ($is_str($tokens[ $j ], 'post_status')) {
                                $names_status = true;
                            }
                            if (0 === $level) {
                                break;
                            }
                        }
                        if (! $names_status && ! $routed()) {
                            $record();
                        }
                    }
                }
                $calls[] = $callee;
                continue;
            }
            if (')' === $t) {
                array_pop($calls);
                continue;
            }

            if (! $is_str($token, 'post_status')) {
                continue;
            }
            $next = $tokens[ $i + 1 ] ?? null;
            if (is_array($next) && T_DOUBLE_ARROW === $next[0]) {
                $start = $i + 2;
            } elseif (']' === $next && '=' === ($tokens[ $i + 2 ] ?? null)) {
                $start = $i + 3;
            } else {
                continue;
            }
            if ($value_has_any($start) && ! $routed()) {
                $record();
            }
        }

        return $found;
    }

    /** @return array<string, int> "file::function" => count, for all of src/ */
    private static function scan_src(): array
    {
        $root  = self::src();
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            foreach (self::scan((string) file_get_contents($file->getPathname())) as $function => $count) {
                $found[ $relative . '::' . $function ] = $count;
            }
        }
        ksort($found);
        return $found;
    }

    public function test_the_scan_catches_an_unfiltered_any_status_query(): void
    {
        $source = <<<'PHP'
<?php
class Fixture {
    public function listed() {
        return get_posts([ 'post_type' => 'page', 'post_status' => 'any' ]);
    }
    public function built_first() {
        $args = [ 'post_type' => 'page', 'post_status' => isset($x) ? $x : 'any' ];
        return new \WP_Query($args);
    }
    public function assigned() {
        $args['post_status'] = 'any';
        $closure = function () { return get_children([ 'post_parent' => 3 ]); };
        return get_posts($args);
    }
    public function filtered() {
        $q = Readable_Posts::query([ 'post_type' => 'page', 'post_status' => 'any', 'meta_query' => [ [ 'key' => 'k' ] ] ]);
        $c = \WPMCP\Tools\Content\Readable_Posts::get_posts([ 'post_parent' => 3, 'post_status' => 'any' ]);
        return [ get_posts([ 'post_status' => 'publish' ]), get_children([ 'post_parent' => 3, 'post_status' => 'inherit' ]), $q, $c ];
    }
    abstract protected function declared(int $x): array;
    public function after_abstract() {
        return get_children([ 'post_parent' => 3 ]);
    }
}
PHP;

        $this->assertSame(
            [ 'listed' => 1, 'built_first' => 1, 'assigned' => 2, 'after_abstract' => 1 ],
            self::scan($source),
            'The scan must flag every unfiltered any-status query and none routed through Readable_Posts.'
        );
    }

    public function test_every_any_status_query_is_filtered_or_on_the_documented_allowlist(): void
    {
        $unlisted = [];
        foreach (self::scan_src() as $site => $count) {
            $allowed = self::ALLOWED[ $site ]['count'] ?? 0;
            if ($count > $allowed) {
                $unlisted[] = sprintf('src/%s: %d unfiltered any-status quer%s, %d allowed', $site, $count, 1 === $count ? 'y' : 'ies', $allowed);
            }
        }

        $this->assertSame(
            [],
            $unlisted,
            "A query over every post status must go through WPMCP\\Tools\\Content\\Readable_Posts (query() or get_posts()),\n"
            . "which keeps the rows to what the caller may read. If it never lists rows to a caller who may not read them,\n"
            . "add it to ALLOWED with a one-line reason.\n"
        );
    }

    public function test_the_allowlist_has_no_stale_entries(): void
    {
        $found = self::scan_src();
        $stale = [];
        foreach (self::ALLOWED as $site => $entry) {
            $this->assertNotSame('', trim($entry['reason']), "src/{$site} needs a reason.");
            $count = $found[ $site ] ?? 0;
            if ($count !== $entry['count']) {
                $stale[] = sprintf('src/%s: allowed %d, found %d', $site, $entry['count'], $count);
            }
        }
        $this->assertSame([], $stale, 'Keep ALLOWED exact, so a removed query does not leave room for a new one.');
    }
}
