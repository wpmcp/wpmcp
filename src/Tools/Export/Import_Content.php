<?php

namespace WPMCP\Tools\Export;

use WPMCP\Tools\Content\Content_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Import a WordPress eXtended RSS (WXR) file, creating posts via
 * wp_insert_post(). Content creation at scale is NOT trivially undoable
 * (unlike the rest of this plugin's mutations, there is no single
 * object_type/object_id for Safe_Mutation to snapshot: an import can create
 * an arbitrary number of new posts), so this tool is disabled by default
 * and requires an explicit opt-in plus confirm:true on every call. Every
 * created post id is returned so a caller can follow up with delete-post
 * for each one, but this is honestly reported as recoverable:false rather
 * than overclaiming an automatic rollback.
 *
 * Parses <item> elements with a lightweight reader (SimpleXMLElement) rather
 * than depending on the WordPress Importer plugin, which is not part of
 * WordPress core and cannot be assumed present.
 */
class Import_Content
{
    private const VALID_STATUSES = ['draft', 'publish', 'pending', 'private', 'future'];

    /**
     * Disabled by default: sites must opt in with
     * add_filter('wpmcp_enable_import', '__return_true') before this tool
     * will run at all, in addition to the caller passing confirm:true.
     */
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_import', false);
    }

    public function handle(array $args): array
    {
        // Restoring a page from its mirror file (issue #298) is a single
        // snapshotted write that rollback-operation undoes, so neither the
        // bulk-import opt-in nor confirm applies to it.
        if (! empty($args['mirror'])) {
            return (new Content_Mirror())->restore($args);
        }

        if (! self::is_enabled()) {
            throw new \RuntimeException('The import-content tool is disabled. Enable it with the wpmcp_enable_import filter.');
        }
        if (true !== ($args['confirm'] ?? null)) {
            throw new \WPMCP\MCP\Confirmation_Required('Importing content creates posts that are not automatically reversible. Pass confirm:true to proceed.');
        }

        $file = (string) ($args['file'] ?? '');
        if ('' === $file || ! is_file($file)) {
            throw new \InvalidArgumentException('"file" must be a path to an existing WXR file.');
        }

        $items = $this->parse_items($file);

        $created_post_ids = [];
        foreach ($items as $item) {
            $post_type = $item['post_type'] ?? 'post';
            if (! Content_Guard::is_writable_post_type($post_type)) {
                continue;
            }
            $status = in_array($item['status'], self::VALID_STATUSES, true) ? $item['status'] : 'draft';

            $post_id = wp_insert_post(wp_slash([
                'post_type'    => $post_type,
                'post_status'  => $status,
                'post_title'   => $item['title'],
                'post_content' => $item['content'],
            ]), true);
            if (is_wp_error($post_id)) {
                continue;
            }
            $post_id = (int) $post_id;

            foreach ($item['meta'] as $key => $value) {
                update_post_meta($post_id, $key, wp_slash($value));
            }

            $created_post_ids[] = $post_id;
        }

        return [
            'imported_count'    => count($created_post_ids),
            'created_post_ids'  => $created_post_ids,
            'recoverable'       => false,
            'note'              => 'Content creation is not automatically reversible. Use delete-post with each id in created_post_ids to undo.',
        ];
    }

    /** @return array<int, array{title: string, content: string, status: string, post_type: string, meta: array<string, string>}> */
    private function parse_items(string $file): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_file($file);
        libxml_use_internal_errors($previous);

        if (false === $xml) {
            throw new \InvalidArgumentException('"file" is not valid XML.');
        }

        $wp_ns = 'http://wordpress.org/export/1.2/';

        $items = [];
        foreach ($xml->channel->item ?? [] as $item) {
            $wp = $item->children($wp_ns);
            $content_ns = $item->children('http://purl.org/rss/1.0/modules/content/');

            $meta = [];
            foreach ($wp->postmeta ?? [] as $postmeta) {
                $meta_ns = $postmeta->children($wp_ns);
                $key     = (string) $meta_ns->meta_key;
                if ('' === $key) {
                    continue;
                }
                $meta[$key] = (string) $meta_ns->meta_value;
            }

            $items[] = [
                'title'     => (string) $item->title,
                'content'   => (string) $content_ns->encoded,
                'status'    => (string) $wp->status,
                'post_type' => '' !== (string) $wp->post_type ? (string) $wp->post_type : 'post',
                'meta'      => $meta,
            ];
        }

        return $items;
    }
}
