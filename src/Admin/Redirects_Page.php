<?php

namespace WPMCP\Admin;

use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Redirects\Redirect_Suggestions;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Redirects admin screen (issue #128).
 *
 * Two tables. The first is what IS redirected, with the target visitors are
 * actually sent to (a post-id-backed row shows that post's current permalink)
 * and its hit count. The second is what the plugin thinks SHOULD be
 * redirected: the pending suggestions raised when a published post was
 * deleted or moved to a new URL.
 *
 * The suggestions table is where a human confirms. Nothing on this screen
 * creates a redirect on its own; Create posts to
 * Redirect_Suggestion_Controller, which calls the create-redirect tool.
 */
class Redirects_Page
{
    public const SLUG = 'wpmcp-redirects';

    public function render(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('wpmcp: Redirects', 'wpmcp') . '</h1>';

        $this->render_redirects();
        $this->render_suggestions();

        echo '</div>';
        $this->render_script();
    }

    private function render_redirects(): void
    {
        $rows = Redirect_Store::all(['limit' => 100]);

        echo '<h2>' . esc_html__('Managed redirects', 'wpmcp') . '</h2>';

        if ([] === $rows) {
            echo '<p>' . esc_html__('No redirects yet. An agent creates them with the create-redirect tool.', 'wpmcp') . '</p>';
            return;
        }

        echo '<table class="widefat"><thead><tr>'
            . '<th>' . esc_html__('Source', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Target', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Code', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Status', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Hits', 'wpmcp') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $target = Redirect_Store::resolve_target($row);
            $active = $row['enabled'] && '' !== $target;

            printf(
                '<tr><td><code>%s</code></td><td>%s</td><td>%d</td><td>%s</td><td>%d</td></tr>',
                esc_html($row['source_path']),
                '' === $target
                    ? '<em>' . esc_html__('target missing', 'wpmcp') . '</em>'
                    : esc_html($target),
                (int) $row['status_code'],
                $active
                    ? esc_html__('active', 'wpmcp')
                    : esc_html__('inactive', 'wpmcp'),
                (int) $row['hits']
            );
        }

        echo '</tbody></table>';
    }

    private function render_suggestions(): void
    {
        $suggestions = Redirect_Suggestions::all();

        echo '<h2>' . esc_html__('Pending suggestions', 'wpmcp') . '</h2>';
        echo '<p>' . esc_html__(
            'Raised when a published post was deleted or moved to a new URL. Nothing is redirected until you create it.',
            'wpmcp'
        ) . '</p>';

        if ([] === $suggestions) {
            echo '<p>' . esc_html__('No pending suggestions.', 'wpmcp') . '</p>';
            return;
        }

        $nonce = wp_create_nonce(Redirect_Suggestion_Controller::NONCE);

        echo '<table class="widefat"><thead><tr>'
            . '<th>' . esc_html__('Source', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Reason', 'wpmcp') . '</th>'
            . '<th>' . esc_html__('Proposed target', 'wpmcp') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody>';

        foreach ($suggestions as $suggestion) {
            $source  = (string) ($suggestion['source'] ?? '');
            $post_id = (int) ($suggestion['target_post_id'] ?? 0);
            $target  = $post_id > 0 ? (string) get_permalink($post_id) : '';

            printf(
                '<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>'
                . '<button class="button button-primary wpmcp-suggestion" data-op="create" data-source="%s" data-post="%d" data-nonce="%s"%s>%s</button> '
                . '<button class="button wpmcp-suggestion" data-op="dismiss" data-source="%s" data-nonce="%s">%s</button>'
                . '</td></tr>',
                esc_html($source),
                esc_html((string) ($suggestion['reason'] ?? '')),
                '' === $target
                    ? '<em>' . esc_html__('choose a target with create-redirect', 'wpmcp') . '</em>'
                    : esc_html($target),
                esc_attr($source),
                (int) $post_id,
                esc_attr($nonce),
                $post_id > 0 ? '' : ' disabled="disabled"',
                esc_html__('Create redirect', 'wpmcp'),
                esc_attr($source),
                esc_attr($nonce),
                esc_html__('Dismiss', 'wpmcp')
            );
        }

        echo '</tbody></table>';
    }

    private function render_script(): void
    {
        ?>
        <script>
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.wpmcp-suggestion');
            if (! btn) {
                return;
            }
            e.preventDefault();
            var body = new URLSearchParams();
            body.set('action', '<?php echo esc_js(Redirect_Suggestion_Controller::ACTION); ?>');
            body.set('op', btn.dataset.op);
            body.set('source', btn.dataset.source);
            body.set('nonce', btn.dataset.nonce);
            if (btn.dataset.post) {
                body.set('target_post_id', btn.dataset.post);
            }
            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function () { window.location.reload(); });
        });
        </script>
        <?php
    }
}
