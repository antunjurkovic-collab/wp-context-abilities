<?php
if (!defined('ABSPATH')) { exit; }

class WPCA_Abilities {
    const CATEGORY = 'wp-context';

    public static function init(): void {
        add_action('wp_abilities_api_categories_init', [__CLASS__, 'register_category']);
        add_action('wp_abilities_api_init', [__CLASS__, 'register_abilities']);
    }

    public static function register_category(): void {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category(
            self::CATEGORY,
            array(
                'label' => __('WP Context', 'wp-context-abilities'),
                'description' => __('Read-only structured WordPress context for AI workflows. v0 exposes post/page content context.', 'wp-context-abilities'),
            )
        );
    }

    public static function register_abilities(): void {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        self::register_ability(
            'wp-context/get-post-context',
            array(
                'label' => __('Get Post Content Context', 'wp-context-abilities'),
                'description' => __('Returns a deterministic Machine Representation for a post or page, including CID, block-derived structure, plain text, taxonomy context, and representation links.', 'wp-context-abilities'),
                'category' => self::CATEGORY,
                'input_schema' => self::post_id_input_schema(),
                'output_schema' => self::post_context_output_schema(),
                'execute_callback' => [__CLASS__, 'get_post_context'],
                'permission_callback' => [__CLASS__, 'can_read_post_input'],
                'meta' => array(
                    'annotations' => self::readonly_annotations(),
                    'show_in_rest' => true,
                ),
            )
        );

        self::register_ability(
            'wp-context/get-post-markdown',
            array(
                'label' => __('Get Post Markdown Context', 'wp-context-abilities'),
                'description' => __('Returns a Markdown projection for a post or page with a deterministic ETag over the final Markdown bytes.', 'wp-context-abilities'),
                'category' => self::CATEGORY,
                'input_schema' => self::post_id_input_schema(),
                'output_schema' => self::markdown_output_schema(),
                'execute_callback' => [__CLASS__, 'get_post_markdown'],
                'permission_callback' => [__CLASS__, 'can_read_post_input'],
                'meta' => array(
                    'annotations' => self::readonly_annotations(),
                    'show_in_rest' => true,
                ),
            )
        );

        self::register_ability(
            'wp-context/list-content-context',
            array(
                'label' => __('List Content Context', 'wp-context-abilities'),
                'description' => __('Lists content items available to the current user, including stable CIDs and links to human and machine representations.', 'wp-context-abilities'),
                'category' => self::CATEGORY,
                'input_schema' => self::catalog_input_schema(false),
                'output_schema' => self::catalog_output_schema(),
                'execute_callback' => [__CLASS__, 'list_content_context'],
                'permission_callback' => [__CLASS__, 'can_list_content'],
                'meta' => array(
                    'annotations' => self::readonly_annotations(),
                    'show_in_rest' => true,
                ),
            )
        );

        self::register_ability(
            'wp-context/get-changed-content',
            array(
                'label' => __('Get Changed Content Context', 'wp-context-abilities'),
                'description' => __('Lists content changed since a supplied ISO timestamp or cursor, including CIDs for conditional AI context refresh.', 'wp-context-abilities'),
                'category' => self::CATEGORY,
                'input_schema' => self::catalog_input_schema(true),
                'output_schema' => self::catalog_output_schema(),
                'execute_callback' => [__CLASS__, 'get_changed_content'],
                'permission_callback' => [__CLASS__, 'can_list_content'],
                'meta' => array(
                    'annotations' => self::readonly_annotations(),
                    'show_in_rest' => true,
                ),
            )
        );
    }

    private static function register_ability(string $name, array $args): void {
        $ability = wp_register_ability($name, $args);
        if ($ability === null && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Context ability registration failed: ' . $name);
        }
    }

    public static function can_read_post_input($input): bool {
        $post_id = self::post_id_from_input($input);
        return $post_id > 0 && current_user_can('edit_post', $post_id);
    }

    public static function can_list_content($input = null): bool {
        return current_user_can('edit_posts');
    }

    public static function get_post_context($input) {
        $post_id = self::post_id_from_input($input);
        if ($post_id <= 0) {
            return new WP_Error('wpca_invalid_post_id', __('A valid post_id is required.', 'wp-context-abilities'));
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('wpca_forbidden', __('You are not allowed to read this post context.', 'wp-context-abilities'));
        }

        $mr = WPCA_MR::build($post_id);
        if (!$mr) {
            return new WP_Error('wpca_post_not_found', __('Post not found.', 'wp-context-abilities'));
        }

        $cid = WPCA_CID::compute($mr);
        $mr['cid'] = $cid;
        $mr['etag'] = '"' . $cid . '"';

        return $mr;
    }

    public static function get_post_markdown($input) {
        $mr = self::get_post_context($input);
        if (is_wp_error($mr)) {
            return $mr;
        }

        $md = WPCA_REST::to_markdown($mr);
        $md = apply_filters('wpca_markdown', $md, $mr, null);
        $etag = 'sha256-' . hash('sha256', $md);

        return array(
            'rid' => (int) $mr['rid'],
            'cid' => (string) $mr['cid'],
            'etag' => '"' . $etag . '"',
            'modified' => isset($mr['modified']) ? (string) $mr['modified'] : null,
            'markdown' => $md,
        );
    }

    public static function list_content_context($input) {
        return self::catalog_from_input($input, false);
    }

    public static function get_changed_content($input) {
        return self::catalog_from_input($input, true);
    }

    private static function catalog_from_input($input, bool $require_since) {
        if (!current_user_can('edit_posts')) {
            return new WP_Error('wpca_forbidden', __('You are not allowed to list content context.', 'wp-context-abilities'));
        }

        $input = is_array($input) ? $input : array();
        $since = isset($input['since']) ? sanitize_text_field((string) $input['since']) : '';
        $cursor = isset($input['cursor']) ? sanitize_text_field((string) $input['cursor']) : '';
        if ($require_since && $since === '' && $cursor === '') {
            return new WP_Error('wpca_missing_cursor', __('A since or cursor value is required.', 'wp-context-abilities'));
        }

        $request = new WP_REST_Request('GET', '/wp-context/v1/catalog');
        foreach (array('since', 'cursor', 'status', 'types', 'per_page', 'page') as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $request->set_param($key, sanitize_text_field((string) $input[$key]));
            }
        }

        return WPCA_REST::get_catalog($request);
    }

    private static function post_id_from_input($input): int {
        if (!is_array($input) || !isset($input['post_id'])) {
            return 0;
        }
        return absint($input['post_id']);
    }

    private static function readonly_annotations(): array {
        return array(
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        );
    }

    private static function post_id_input_schema(): array {
        return array(
            'type' => 'object',
            'properties' => array(
                'post_id' => array(
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'WordPress post or page ID.',
                ),
            ),
            'required' => array('post_id'),
            'additionalProperties' => false,
        );
    }

    private static function catalog_input_schema(bool $require_since): array {
        return array(
            'type' => 'object',
            'properties' => array(
                'since' => array(
                    'type' => 'string',
                    'description' => $require_since ? 'ISO timestamp used for changed-content discovery. Required when cursor is not supplied.' : 'Optional ISO timestamp used for changed-content discovery.',
                ),
                'cursor' => array(
                    'type' => 'string',
                    'description' => $require_since ? 'High-water cursor returned by a previous fully drained catalog window. Required when since is not supplied.' : 'Optional high-water cursor returned by a previous fully drained catalog window.',
                ),
                'status' => array(
                    'type' => 'string',
                    'enum' => array('draft', 'publish', 'any'),
                    'default' => 'any',
                    'description' => 'Post status filter.',
                ),
                'types' => array(
                    'type' => 'string',
                    'description' => 'Comma-separated post types. The v0 package supports post,page.',
                ),
                'per_page' => array(
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 100,
                    'description' => 'Maximum number of items to return. Capped at 100.',
                ),
                'page' => array(
                    'type' => 'integer',
                    'minimum' => 1,
                    'default' => 1,
                    'description' => 'Page number for catalog pagination.',
                ),
            ),
            'additionalProperties' => false,
        );
    }

    private static function post_context_output_schema(): array {
        return array(
            'type' => 'object',
            'properties' => array(
                'rid' => array('type' => 'integer'),
                'cid' => array('type' => 'string'),
                'etag' => array('type' => 'string'),
                'title' => array('type' => 'string'),
                'status' => array('type' => 'string'),
                'modified' => array('type' => array('string', 'null')),
                'published' => array('type' => array('string', 'null')),
                'word_count' => array('type' => 'integer'),
                'core_content_text' => array('type' => 'string'),
                'blocks' => array('type' => 'array', 'items' => array('type' => 'object')),
                'links' => array('type' => 'object'),
            ),
            'required' => array('rid', 'cid', 'title', 'status', 'modified', 'blocks'),
            'additionalProperties' => true,
        );
    }

    private static function markdown_output_schema(): array {
        return array(
            'type' => 'object',
            'properties' => array(
                'rid' => array('type' => 'integer'),
                'cid' => array('type' => 'string'),
                'etag' => array('type' => 'string'),
                'modified' => array('type' => array('string', 'null')),
                'markdown' => array('type' => 'string'),
            ),
            'required' => array('rid', 'cid', 'etag', 'markdown'),
            'additionalProperties' => false,
        );
    }

    private static function catalog_output_schema(): array {
        return array(
            'type' => 'object',
            'properties' => array(
                'count' => array('type' => 'integer'),
                'cursor' => array('type' => array('string', 'null')),
                'page' => array('type' => 'integer'),
                'per_page' => array('type' => 'integer'),
                'items' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'rid' => array('type' => 'integer'),
                            'cid' => array('type' => 'string'),
                            'modified' => array('type' => array('string', 'null')),
                            'status' => array('type' => 'string'),
                            'title' => array('type' => 'string'),
                            'hr' => array('type' => 'string'),
                            'mr' => array('type' => 'string'),
                            'representations' => array('type' => 'array', 'items' => array('type' => 'object')),
                        ),
                        'required' => array('rid', 'cid', 'modified', 'title', 'mr'),
                        'additionalProperties' => true,
                    ),
                ),
            ),
            'required' => array('count', 'items'),
            'additionalProperties' => false,
        );
    }
}
