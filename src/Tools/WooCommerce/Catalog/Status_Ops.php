<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The WooCommerce system status report and status tools behind the
 * system-status.* ops (issue #292).
 *
 *  - system-status.get reads the wc/v3 system status report, by section,
 *    and masks any secret-like value (a key, secret, password or token by
 *    name) wherever it appears, since extensions can add to the report.
 *  - system-status.tools lists the status tools and says, for each, whether
 *    wpmcp runs it, whether it needs confirm:true, and why none of them can
 *    be rolled back.
 *  - system-status.run-tool runs an allowlisted tool through the wc/v3 tools
 *    endpoint. The safe ones clear or rebuild derived data WooCommerce
 *    regenerates itself (caches, lookup tables, term counts); the ones that
 *    delete store rows need confirm:true. Nothing is snapshotted, and the
 *    response says so and why. Every other tool (roles, pages, tax rates,
 *    database upgrades, extension tools running their own callbacks) is
 *    refused: its effect could not be rolled back and is not maintenance.
 */
final class Status_Ops
{
    /** Report sections a caller may ask for. */
    private const SECTIONS = [ 'environment', 'database', 'active_plugins', 'inactive_plugins', 'dropins_mu_plugins', 'theme', 'settings', 'security', 'pages', 'post_type_counts', 'logging' ];

    /** Sections returned when none are named. */
    private const DEFAULT_SECTIONS = [ 'environment', 'database', 'active_plugins', 'theme', 'settings', 'security' ];

    /** Keys whose string values are masked anywhere in the report. */
    private const SECRET_PATTERN = '/(secret|pass(word|wd|phrase)?|token|signature|credential|private|_key$|^key$|api_?key)/i';

    /** Tools that only clear or rebuild derived data: run without confirm. */
    private const SAFE_TOOLS = [
        'clear_transients',
        'clear_expired_transients',
        'clear_template_cache',
        'clear_system_status_theme_info_cache',
        'clear_woocommerce_analytics_cache',
        'regenerate_product_lookup_tables',
        'regenerate_product_attributes_lookup_table',
        'repair_coupons_lookup_table',
        'recount_terms',
        'verify_db_tables',
    ];

    /** Tools that delete store rows: run only with confirm:true. Tool => what it deletes. */
    private const CONFIRM_TOOLS = [
        'delete_orphaned_variations'         => 'variations whose product no longer exists',
        'clear_expired_download_permissions' => 'expired download permissions and their download logs',
        'clear_sessions'                     => 'every customer session and saved cart',
    ];

    private const SAFE_NOTE    = 'Not recoverable, and nothing to recover: it clears a cache or rebuilds derived data (lookup tables, counts) that WooCommerce regenerates from the store.';
    private const REFUSED_NOTE = 'Not run by wpmcp: it changes or deletes store data (or runs extension code) in a way that could not be rolled back.';

    /**
     * The system-status.get and system-status.tools reads.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function read(string $handler, array $params): array
    {
        try {
            if ('status_tools' === $handler) {
                self::refuse_unknown($params, []);
                return self::tools();
            }
            self::refuse_unknown($params, [ 'sections' ]);
            $sections = self::sections($params);
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }

        $out = (new Wc_Rest_Dispatch())->get('/wc/v3/system_status', [ '_fields' => implode(',', $sections) ]);
        if ($out['status'] < 200 || $out['status'] >= 300 || ! is_array($out['body'])) {
            return $out;
        }

        return [ 'status' => $out['status'], 'body' => self::scrub(array_intersect_key($out['body'], array_flip($sections))) ];
    }

    /**
     * Validate a tool run. Returns a structured error, or ['body' => the
     * wc/v3 body, 'report' => what the response adds]. Runs nothing.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function prepare(string $handler, array $params, bool $confirm = false): array
    {
        try {
            if ('status_run_tool' !== $handler) {
                self::refuse('invalid_params', 'Unknown system status handler.');
            }
            self::refuse_unknown($params, [ 'id' ]);
            $id = is_string($params['id'] ?? null) ? $params['id'] : '';

            $tools = self::wc_tools();
            if (null === $tools) {
                self::refuse('integration_unavailable', 'The WooCommerce status tools could not be read.');
            }
            if (! isset($tools[ $id ])) {
                self::refuse('unknown_tool', 'No status tool has that id. List them with system-status.tools.');
            }
            if (! self::runnable($id)) {
                self::refuse('tool_not_allowed', "Tool \"{$id}\" is not run by wpmcp. " . self::REFUSED_NOTE, [ 'tool' => $id ]);
            }
            if (isset(self::CONFIRM_TOOLS[ $id ]) && ! $confirm) {
                self::refuse('confirmation_required', "Tool \"{$id}\" permanently deletes " . self::CONFIRM_TOOLS[ $id ] . ' and requires confirm:true.', [ 'tool' => $id ]);
            }
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }

        return [ 'body' => [], 'report' => [ 'why_unrecoverable' => self::note($id) ] ];
    }

    /** @return array<string, mixed> */
    private static function tools(): array
    {
        $tools = self::wc_tools();
        if (null === $tools) {
            return Op_Guard::error('integration_unavailable', 'The WooCommerce status tools could not be read.');
        }

        $rows = [];
        foreach ($tools as $id => $tool) {
            $rows[] = [
                'id'               => $id,
                'name'             => wp_strip_all_tags((string) ($tool['name'] ?? $id)),
                'description'      => trim(wp_strip_all_tags((string) ($tool['description'] ?? ''))),
                'runnable'         => self::runnable($id),
                'requires_confirm' => isset(self::CONFIRM_TOOLS[ $id ]),
                'recoverable'      => false,
                'note'             => self::note($id),
            ];
        }
        return [ 'status' => 200, 'body' => [ 'tools' => $rows ] ];
    }

    /**
     * The tools the store offers, keyed by id, from the wc/v3 tools endpoint.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private static function wc_tools(): ?array
    {
        $out = (new Wc_Rest_Dispatch())->get('/wc/v3/system_status/tools');
        if ($out['status'] < 200 || $out['status'] >= 300 || ! is_array($out['body'])) {
            return null;
        }
        $tools = [];
        foreach ($out['body'] as $tool) {
            if (is_array($tool) && is_string($tool['id'] ?? null)) {
                $tools[ $tool['id'] ] = $tool;
            }
        }
        return $tools;
    }

    private static function runnable(string $id): bool
    {
        return in_array($id, self::SAFE_TOOLS, true) || isset(self::CONFIRM_TOOLS[ $id ]);
    }

    private static function note(string $id): string
    {
        if (isset(self::CONFIRM_TOOLS[ $id ])) {
            return 'Not recoverable: it permanently deletes ' . self::CONFIRM_TOOLS[ $id ] . ', which is not snapshotted. Needs confirm:true.';
        }
        return in_array($id, self::SAFE_TOOLS, true) ? self::SAFE_NOTE : self::REFUSED_NOTE;
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private static function sections(array $params): array
    {
        if (! array_key_exists('sections', $params)) {
            return self::DEFAULT_SECTIONS;
        }
        $given = $params['sections'];
        if (! is_array($given) || [] === $given || ! array_is_list($given)) {
            self::refuse('invalid_params', 'sections must be a non-empty list of: ' . implode(', ', self::SECTIONS) . '.');
        }
        foreach ($given as $section) {
            if (! is_string($section) || ! in_array($section, self::SECTIONS, true)) {
                self::refuse('invalid_params', 'sections must be a non-empty list of: ' . implode(', ', self::SECTIONS) . '.');
            }
        }
        return array_values(array_unique($given));
    }

    /**
     * Mask secret-like string values at any depth.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[ $key ] = self::scrub($value);
            } elseif (is_string($key) && is_string($value) && '' !== $value && preg_match(self::SECRET_PATTERN, $key)) {
                $data[ $key ] = '[redacted]';
            }
        }
        return $data;
    }

    /**
     * Stop validation with a structured refusal.
     *
     * @param array<string, mixed> $data
     */
    private static function refuse(string $code, string $message, array $data = []): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the caller and returned as a JSON error, never rendered.
        throw new Order_Op_Refused($code, $message, $data);
    }

    private static function refuse_unknown(array $given, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($given)), $allowed));
        if ([] !== $unknown) {
            self::refuse(
                'invalid_params',
                'Unknown field(s): ' . implode(', ', $unknown) . '. Accepted: ' . ([] === $allowed ? 'none' : implode(', ', $allowed)) . '.',
                [ 'unknown' => $unknown ]
            );
        }
    }
}
