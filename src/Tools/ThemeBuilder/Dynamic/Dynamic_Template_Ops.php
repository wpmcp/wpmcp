<?php

namespace WPMCP\Tools\ThemeBuilder\Dynamic;

use WPMCP\Integrations\Operation_Refused;
use WPMCP\Tools\ThemeBuilder\Condition_Schema;
use WPMCP\Tools\ThemeBuilder\Template_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Dynamic templates (issue #290) as paid-tier ops on the theme dispatcher
 * pair, so the feature adds no top-level tools to tools/list:
 *  - list-dynamic-sources (theme-read): the bindable sources per context.
 *  - preview-dynamic-template (theme-read): render a stored template against
 *    a chosen post, without touching the front end.
 *  - create-dynamic-template (theme-write): a single, archive or search
 *    template, stored in the site parts store (#70) with the same display
 *    conditions, filtered with wp_kses_post and token-checked on the way in.
 *  - update-dynamic-template (theme-write): snapshot-first on the template
 *    post, so rollback-operation restores content, title, conditions and
 *    priority exactly.
 *
 * Every refusal (bad context, unknown token, a rule that can never match
 * the context) is decided in 'validate', before the dispatcher captures a
 * snapshot. A create is not snapshotted, the same create-only exemption
 * create-site-part takes; wpmcp/delete-site-part, which is snapshot-first,
 * undoes it, and the site part tools list, toggle and trash these templates
 * like any other.
 */
class Dynamic_Template_Ops
{
    /**
     * Include rule types that can match each context. A single template
     * whose only include rule is `search` would be stored and never shown,
     * so it is refused instead.
     */
    public const CONTEXT_RULES = [
        'single'  => ['entire_site', 'singular', 'post_type', 'term', 'user_role', 'front_page'],
        'archive' => ['entire_site', 'archive', 'post_type', 'term', 'user_role'],
        'search'  => ['entire_site', 'search', 'user_role'],
    ];

    private const FIELDS = ['title', 'content', 'conditions', 'priority'];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $context    = ['type' => 'string', 'enum' => Dynamic_Sources::CONTEXTS];
        $rule       = [
            'type'       => 'object',
            'properties' => [
                'type'  => ['type' => 'string', 'enum' => array_keys(Condition_Schema::RULE_TYPES)],
                'value' => ['type' => ['string', 'integer']],
            ],
            'required'   => ['type'],
        ];
        $conditions = [
            'type'       => 'object',
            'properties' => [
                'include' => ['type' => 'array', 'items' => $rule],
                'exclude' => ['type' => 'array', 'items' => $rule],
            ],
            'required'   => ['include'],
        ];

        return [
            'list-dynamic-sources'     => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'capability'   => 'manage_options',
                'description'  => 'List the {{group.field}} binding tokens a single, archive or search template can print (post, site, archive, search and loop fields, plus ACF fields when ACF is active), each with the type that decides its escaping, and the placeholder syntax',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'context'   => $context,
                        'post_type' => ['type' => 'string'],
                    ],
                ],
                'handler'      => [self::class, 'list_sources'],
            ],
            'preview-dynamic-template' => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'capability'   => 'manage_options',
                'description'  => 'Render a stored dynamic template with its bindings resolved against post_id (a loop repeats over recent posts of that post type), without changing the site',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'template_id' => ['type' => 'integer'],
                        'post_id'     => ['type' => 'integer'],
                    ],
                    'required'   => ['template_id'],
                ],
                'handler'      => [self::class, 'preview'],
            ],
            'create-dynamic-template'  => [
                'mode'         => 'write',
                'tier'         => 'pro',
                'capability'   => 'manage_options',
                'description'  => 'Create a single, archive or search template shown where its conditions match (same rules as site parts). content is block markup with {{group.field}} tokens from list-dynamic-sources; unknown tokens and rules that cannot match the context are refused. Undo with wpmcp/delete-site-part',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'context'    => $context,
                        'title'      => ['type' => 'string'],
                        'content'    => ['type' => 'string'],
                        'conditions' => $conditions,
                        'priority'   => ['type' => 'integer'],
                    ],
                    'required'   => ['context', 'title', 'content', 'conditions'],
                ],
                'validate'     => [self::class, 'validate_create'],
                'handler'      => [self::class, 'create'],
            ],
            'update-dynamic-template'  => [
                'mode'         => 'write',
                'tier'         => 'pro',
                'capability'   => 'manage_options',
                'description'  => 'Edit a dynamic template\'s title, content, conditions or priority; omitted fields are kept and the context is fixed. Snapshot-first: operation_id rolls it back',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'template_id' => ['type' => 'integer'],
                        'title'       => ['type' => 'string'],
                        'content'     => ['type' => 'string'],
                        'conditions'  => $conditions,
                        'priority'    => ['type' => 'integer'],
                    ],
                    'required'   => ['template_id'],
                ],
                'validate'     => [self::class, 'validate_update'],
                'snapshot'     => [self::class, 'snapshot_target'],
                'handler'      => [self::class, 'update'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function list_sources(array $args): array
    {
        $out = Dynamic_Sources::discover((string) ($args['context'] ?? 'single'), (string) ($args['post_type'] ?? ''));
        if (is_wp_error($out)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The dispatcher returns this as a JSON error envelope; it is never rendered.
            throw new Operation_Refused($out->get_error_code(), $out->get_error_message());
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function preview(array $args): array
    {
        $template = self::dynamic_template((int) ($args['template_id'] ?? 0));
        if (null === $template) {
            throw new Operation_Refused('wpmcp_template_not_found', 'No dynamic template found with that id.');
        }

        $post    = null;
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id > 0) {
            $post = get_post($post_id);
            if (! $post instanceof \WP_Post || ! current_user_can('read_post', $post_id)) {
                throw new Operation_Refused('wpmcp_post_not_found', 'No readable post found with that post_id.');
            }
        }

        $loop = [];
        if (in_array($template['part_type'], Dynamic_Sources::LOOP_CONTEXTS, true)) {
            $loop = get_posts([
                'post_type'   => null === $post ? 'post' : $post->post_type,
                'post_status' => 'publish',
                'numberposts' => 10,
            ]);
        }

        return [
            'template_id' => $template['template_id'],
            'context'     => $template['part_type'],
            'post_id'     => null === $post ? null : $post->ID,
            'html'        => Binding_Resolver::resolve(do_blocks((string) $template['content']), $post, $loop),
        ];
    }

    /** @return array<string,mixed>|null a refusal, or null to proceed */
    public static function validate_create(array $args): ?array
    {
        $context = (string) ($args['context'] ?? '');
        if (! in_array($context, Dynamic_Sources::CONTEXTS, true)) {
            return self::refusal(Dynamic_Sources::unknown_context($context));
        }

        return self::validate_fields($context, $args);
    }

    /** @return array<string,mixed>|null a refusal, or null to proceed */
    public static function validate_update(array $args): ?array
    {
        $template = self::dynamic_template((int) ($args['template_id'] ?? 0));
        if (null === $template) {
            return [
                'code'    => 'wpmcp_template_not_found',
                'message' => 'No dynamic template found with that id; site parts are edited with wpmcp/update-site-part.',
            ];
        }
        if ([] === self::changes($args)) {
            return [
                'code'    => 'wpmcp_nothing_to_update',
                'message' => sprintf('Pass at least one of: %s.', implode(', ', self::FIELDS)),
            ];
        }

        return self::validate_fields((string) $template['part_type'], $args);
    }

    /** @return array{object_type:string,object_id:int} */
    public static function snapshot_target(array $args): array
    {
        return ['object_type' => 'post', 'object_id' => (int) ($args['template_id'] ?? 0)];
    }

    /** @return array<string,mixed> */
    public static function create(array $args): array
    {
        $id = Template_Store::insert(
            (string) $args['context'],
            (string) $args['title'],
            (string) $args['content'],
            (array) $args['conditions'],
            (int) ($args['priority'] ?? 0)
        );
        if (is_wp_error($id)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The dispatcher returns this as a JSON error envelope; it is never rendered.
            throw new Operation_Refused($id->get_error_code(), $id->get_error_message());
        }

        return (array) Template_Store::get($id) + ['undo_tool' => 'wpmcp/delete-site-part'];
    }

    /** @return array<string,mixed> */
    public static function update(array $args): array
    {
        $id      = (int) $args['template_id'];
        $updated = Template_Store::update($id, self::changes($args));
        if (is_wp_error($updated)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The dispatcher returns this as a JSON error envelope; it is never rendered.
            throw new Operation_Refused($updated->get_error_code(), $updated->get_error_message());
        }

        return ['template' => Template_Store::get($id)];
    }

    /** @return array<string,mixed>|null the stored template when it is a dynamic one */
    private static function dynamic_template(int $id): ?array
    {
        $template = Template_Store::get($id);
        if (null === $template || ! in_array($template['part_type'], Dynamic_Sources::CONTEXTS, true)) {
            return null;
        }
        return $template;
    }

    /** @return array<string,mixed> */
    private static function changes(array $args): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $args) && null !== $args[$field]) {
                $out[$field] = $args[$field];
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function validate_fields(string $context, array $args): ?array
    {
        if (array_key_exists('content', $args)) {
            $valid = Binding_Resolver::validate((string) $args['content'], $context);
            if (is_wp_error($valid)) {
                return self::refusal($valid);
            }
        }

        if (array_key_exists('conditions', $args)) {
            $conditions = (array) $args['conditions'];
            $valid      = Condition_Schema::validate($conditions);
            if (is_wp_error($valid)) {
                return self::refusal($valid);
            }
            foreach ((array) $conditions['include'] as $rule) {
                $type = (string) ($rule['type'] ?? '');
                if (! in_array($type, self::CONTEXT_RULES[$context], true)) {
                    return [
                        'code'    => 'wpmcp_condition_context_mismatch',
                        'message' => sprintf(
                            'A "%s" include rule can never match a %s template. Use: %s.',
                            $type,
                            $context,
                            implode(', ', self::CONTEXT_RULES[$context])
                        ),
                    ];
                }
            }
        }

        return null;
    }

    /** @return array{code:string,message:string,data:array<string,mixed>} */
    private static function refusal(\WP_Error $error): array
    {
        $data = $error->get_error_data();

        return [
            'code'    => (string) $error->get_error_code(),
            'message' => $error->get_error_message(),
            'data'    => is_array($data) ? $data : [],
        ];
    }
}
