<?php

namespace WPMCP\MCP;

use WPMCP\Memory\Memory_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds the instructions string served in the MCP initialize response
 * (issue #80): an admin-editable option merged with a bounded auto-generated
 * site summary (site name, active builder, safety-model one-liner) derived
 * from the same data get-site-context reports. An empty setting degrades to
 * the auto-summary only.
 *
 * Authorization: the site-derived summary reuses the REAL permission gate of
 * the wpmcp/get-site-context ability (capability + Governance + identity
 * scope), so a connecting identity is never handed context in the handshake
 * that it could not query through the tool itself. When that gate denies,
 * the summary degrades to the generic safety one-liner, which names no site
 * data. The admin-authored text is exempt from that gate on purpose: its
 * whole contract is "guidance served to every agent that connects", and it
 * is not queryable through any tool.
 *
 * Bounds and escaping: instructions travel as plain text inside the
 * initialize JSON, never as HTML, but the option can be written outside the
 * sanitized admin form (wp-cli, direct update_option), so tags are stripped
 * again at read time and both the admin text and the site name are
 * length-clamped. Total output is therefore bounded at roughly
 * MAX_ADMIN_LENGTH plus the small fixed-size summary.
 */
class Handshake_Instructions
{
    public const OPTION = 'wpmcp_handshake_instructions';

    /** Upper bound (characters) on the admin-authored portion. */
    public const MAX_ADMIN_LENGTH = 4000;

    /** Upper bound (characters) on the site name inside the auto-summary. */
    public const MAX_SITE_NAME_LENGTH = 120;

    /** Upper bound (characters) on the injected project-memory block. */
    public const MAX_MEMORY_LENGTH = 1500;

    public function build(): string
    {
        $parts = [];

        $admin = $this->admin_text();
        if ('' !== $admin) {
            $parts[] = $admin;
        }

        $parts[] = $this->can_view_site_context() ? $this->auto_summary() : $this->safety_line();

        $memory = $this->memory_block();
        if ('' !== $memory) {
            $parts[] = $memory;
        }

        return implode("\n\n", $parts);
    }

    /**
     * The approved project-memory block (issue #131), or '' when the site has
     * published no entries.
     *
     * Only PUBLISHED entries appear: an agent proposal sits pending and
     * inert until an administrator approves it, so this channel can never be
     * used by one session to plant instructions for the next. That makes the
     * block admin-authored content in the same sense as admin_text(), which
     * is why it is served on the same terms rather than behind the
     * get-site-context permission gate (it discloses no queryable site data,
     * only the guidance an administrator chose to broadcast).
     *
     * Enforced guardrails are listed first and labelled as enforced, because
     * they are: the server denies them in Registrar::is_permitted(), so an
     * agent that ignores the text still cannot perform the action.
     */
    public function memory_block(): string
    {
        $entries = Memory_Store::guidance(50);
        if ([] === $entries) {
            return '';
        }

        $enforced = [];
        $advisory = [];
        foreach ($entries as $entry) {
            if ('block' === $entry['severity']) {
                $enforced[] = sprintf('%s [%s]', $entry['text'], implode(' ', $entry['targets']));
                continue;
            }
            $advisory[] = $entry['text'];
        }

        $lines = ['## Project memory (approved by this site\'s administrator)'];
        if ([] !== $enforced) {
            $lines[] = 'Enforced guardrails, refused by the server (not advice): ' . implode(' | ', $enforced);
        }
        if ([] !== $advisory) {
            $lines[] = 'Notes: ' . implode(' | ', $advisory);
        }
        $lines[] = 'Call memory-recall for the full set and session history.';

        return $this->clamp(implode("\n", $lines), self::MAX_MEMORY_LENGTH);
    }

    /**
     * The admin-authored instructions, defensively re-sanitized at read time
     * (the option may have been written without going through the settings
     * form's sanitize callback) and clamped to MAX_ADMIN_LENGTH.
     */
    public function admin_text(): string
    {
        $raw = get_option(self::OPTION, '');
        if (! is_string($raw)) {
            return '';
        }

        return $this->clamp(trim(wp_strip_all_tags($raw)), self::MAX_ADMIN_LENGTH);
    }

    /**
     * The site-derived summary: site name, active builder, and the safety
     * one-liner. Only served when can_view_site_context() allows it.
     */
    public function auto_summary(): string
    {
        // get_bloginfo() entity-encodes for display, so decode BEFORE
        // stripping: otherwise "&lt;script&gt;alert(1)&lt;/script&gt;"
        // survives wp_strip_all_tags() and the script body leaks into the
        // handshake. Decoding first lets the strip remove the tag pair AND
        // its contents.
        $name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $name = $this->clamp(trim(wp_strip_all_tags($name, true)), self::MAX_SITE_NAME_LENGTH);

        return sprintf(
            'You are connected to the WordPress site "%s" via wpmcp. Active builder: %s. %s',
            $name,
            $this->active_builder(),
            $this->safety_line()
        );
    }

    /**
     * The generic safety-model one-liner: names no site data, so it is safe
     * to serve to any connecting client, however narrowly scoped.
     */
    public function safety_line(): string
    {
        return 'wpmcp safety model: every write is snapshotted before it runs and can be rolled back'
            . ' (list-operations, rollback-operation, rollback-session).';
    }

    /**
     * Site-level builder detection, reusing the same markers the builder
     * tools check: Elementor's plugin class, then the Bricks and Divi theme
     * constants/templates, then the WPBakery, Beaver Builder, Breakdance
     * engine (Breakdance or Oxygen 6), Avada builder and Thrive Architect
     * plugin constants, falling back to gutenberg (the WP default).
     */
    public function active_builder(): string
    {
        if (class_exists('\\Elementor\\Plugin')) {
            return 'elementor';
        }

        $template = function_exists('get_template') ? strtolower((string) get_template()) : '';

        if (defined('BRICKS_VERSION') || 'bricks' === $template) {
            return 'bricks';
        }
        if (defined('ET_BUILDER_VERSION') || 'divi' === $template) {
            return 'divi';
        }
        if (defined('WPB_VC_VERSION')) {
            return 'wpbakery';
        }
        if (defined('FL_BUILDER_VERSION')) {
            return 'beaver-builder';
        }
        if (defined('__BREAKDANCE_VERSION')) {
            // Oxygen 6 is the same engine, run with BREAKDANCE_MODE 'oxygen'.
            return defined('BREAKDANCE_MODE') && 'oxygen' === constant('BREAKDANCE_MODE') ? 'oxygen' : 'breakdance';
        }
        if (defined('FUSION_BUILDER_VERSION')) {
            return 'avada';
        }
        if (defined('TVE_VERSION')) {
            return 'thrive';
        }

        return 'gutenberg';
    }

    /**
     * Whether the current request could call wpmcp/get-site-context itself.
     * Delegates to the registered ability's real permission callback, so
     * every layer that gates the tool (capability, Governance narrowing,
     * identity scope, and the audit record) gates the handshake summary
     * identically. An unregistered ability (governance disabled it, or the
     * Abilities API is absent) is a deny.
     */
    public function can_view_site_context(): bool
    {
        if (! function_exists('wp_get_abilities')) {
            return false;
        }

        $ability = wp_get_abilities()['wpmcp/get-site-context'] ?? null;
        if (null === $ability) {
            return false;
        }

        try {
            return (bool) $ability->check_permissions();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Callback for the MCP Adapter's mcp_adapter_initialize_response filter:
     * replaces the initialize result's `instructions` with build(), leaving
     * every other field untouched. Duck-typed against the InitializeResult
     * DTO's documented toArray()/fromArray() round-trip (the adapter is a
     * separate plugin, so its DTO class cannot be referenced here); anything
     * not honoring that contract passes through unchanged.
     *
     * @param mixed $result The adapter's InitializeResult DTO.
     * @param mixed $server The McpServer instance (unused).
     * @return mixed
     */
    public function filter_initialize($result, $server = null)
    {
        if (
            ! is_object($result)
            || ! method_exists($result, 'toArray')
            || ! method_exists($result, 'fromArray')
        ) {
            return $result;
        }

        $data = $result->toArray();
        if (! is_array($data)) {
            return $result;
        }

        $data['instructions'] = $this->build();

        return $result::fromArray($data);
    }

    /** Multibyte-safe length clamp that never splits a UTF-8 character. */
    private function clamp(string $text, int $max): string
    {
        return mb_substr($text, 0, $max);
    }
}
