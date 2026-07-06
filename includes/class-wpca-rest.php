<?php
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/class-wpca-mr.php';
require_once __DIR__ . '/class-wpca-cid.php';

class WPCA_REST {
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route('wp-context/v1', '/posts/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_post_mr'),
            'permission_callback' => function($req) {
                $id = (int) $req['id'];
                $allow = current_user_can('edit_post', $id);
                return apply_filters('wpca_can_read_mr', $allow, $id, $req);
            },
        ));

        register_rest_route('wp-context/v1', '/posts/(?P<id>\d+)/md', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_post_md'),
            'permission_callback' => function($req) {
                $id = (int) $req['id'];
                $allow = current_user_can('edit_post', $id);
                return apply_filters('wpca_can_read_mr', $allow, $id, $req);
            },
        ));

        register_rest_route('wp-context/v1', '/catalog', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_catalog'),
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => array(
                'since' => array('required' => false),
                'cursor' => array('required' => false),
                'status' => array('required' => false),
                'types' => array('required' => false),
                'per_page' => array('required' => false),
                'page' => array('required' => false),
            ),
        ));
    }

    public static function get_post_mr(WP_REST_Request $req) {
        $id = (int) $req['id'];
        $mr = WPCA_MR::build($id);
        if (!$mr) {
            return new WP_REST_Response(array('error' => 'not_found'), 404);
        }

        $cid = WPCA_CID::compute($mr);
        $mr['cid'] = $cid;

        if (self::if_none_match($req, $cid)) {
            $resp = new WP_REST_Response(null, 304);
            self::add_context_headers($resp, $cid, $mr['modified'] ?? null, 'application/json; profile="' . WPCA_PROFILE . '"');
            return $resp;
        }

        $resp = new WP_REST_Response($mr, 200);
        self::add_context_headers($resp, $cid, $mr['modified'] ?? null, 'application/json; profile="' . WPCA_PROFILE . '"');
        return $resp;
    }

    public static function get_post_md(WP_REST_Request $req) {
        $id = (int) $req['id'];
        $mr = WPCA_MR::build($id);
        if (!$mr) {
            return new WP_REST_Response(array('error' => 'not_found'), 404);
        }

        $md = self::to_markdown($mr);
        $md = apply_filters('wpca_markdown', $md, $mr, $req);
        $etag = 'sha256-' . hash('sha256', $md);

        $resp = new WP_REST_Response(self::if_none_match($req, $etag) ? null : $md, self::if_none_match($req, $etag) ? 304 : 200);
        self::add_context_headers($resp, $etag, $mr['modified'] ?? null, 'text/markdown; charset=UTF-8');
        return $resp;
    }

    public static function get_catalog(WP_REST_Request $req) {
        $since = $req->get_param('since');
        if (!$since) {
            $since = $req->get_param('cursor');
        }

        $status = $req->get_param('status');
        $types = $req->get_param('types');
        $per_page = absint($req->get_param('per_page'));
        if ($per_page < 1) {
            $per_page = 100;
        }
        $per_page = min($per_page, 100);

        $page = absint($req->get_param('page'));
        if ($page < 1) {
            $page = 1;
        }

        $post_status = in_array($status, array('draft', 'publish', 'any'), true) ? $status : 'any';
        $post_types = array('post', 'page');
        if (is_string($types) && trim($types) !== '') {
            $requested = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $types))));
            $post_types = array_values(array_intersect($requested, array('post', 'page')));
            if (empty($post_types)) {
                $post_types = array('post', 'page');
            }
        }

        $args = array(
            'post_type' => $post_types,
            'post_status' => $post_status,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        );

        if ($since) {
            $args['date_query'] = array(
                array(
                    'column' => 'post_modified_gmt',
                    'after' => sanitize_text_field((string) $since),
                ),
            );
        }

        $ids = get_posts(apply_filters('wpca_catalog_args', $args, $req));
        $items = array();
        $max_modified_iso = null;

        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if (!current_user_can('edit_post', $id)) {
                continue;
            }

            $mr_for_cid = WPCA_MR::build($id);
            if (!$mr_for_cid) {
                continue;
            }

            $cid = WPCA_CID::compute($mr_for_cid);
            $modified_iso = WPCA_MR::normalize_gmt_datetime(get_post_field('post_modified_gmt', $id));
            $mr_url = rest_url('wp-context/v1/posts/' . $id);
            $hr_url = get_permalink($id);

            $items[] = array(
                'rid' => $id,
                'cid' => $cid,
                'modified' => $modified_iso,
                'status' => get_post_status($id) ?: null,
                'title' => get_the_title($id),
                'hr' => $hr_url,
                'mr' => $mr_url,
                'representations' => array(
                    array(
                        'role' => 'human_projection',
                        'url' => $hr_url,
                    ),
                    array(
                        'role' => 'machine_projection',
                        'url' => $mr_url,
                        'validator' => $cid,
                    ),
                ),
            );

            if ($modified_iso && ($max_modified_iso === null || strcmp($modified_iso, $max_modified_iso) > 0)) {
                $max_modified_iso = $modified_iso;
            }
        }

        return array(
            'count' => count($items),
            'cursor' => $max_modified_iso,
            'page' => $page,
            'per_page' => $per_page,
            'items' => $items,
        );
    }

    public static function to_markdown(array $mr): string {
        $out = '';
        if (!empty($mr['title'])) {
            $out .= '# ' . $mr['title'] . "\n\n";
        }

        $blocks = isset($mr['blocks']) && is_array($mr['blocks']) ? $mr['blocks'] : array();
        foreach ($blocks as $blk) {
            $type = isset($blk['type']) ? $blk['type'] : '';
            if ($type === 'core/heading') {
                $lvl = isset($blk['level']) ? max(1, min(6, (int) $blk['level'])) : 2;
                $txt = isset($blk['content']) ? trim((string) $blk['content']) : '';
                if ($txt !== '') {
                    $out .= str_repeat('#', $lvl) . ' ' . $txt . "\n\n";
                }
            } elseif ($type === 'core/paragraph' || $type === 'unknown') {
                $txt = isset($blk['content']) ? trim((string) $blk['content']) : '';
                if ($txt !== '') {
                    $out .= $txt . "\n\n";
                }
            } elseif ($type === 'core/list') {
                $items = isset($blk['items']) && is_array($blk['items']) ? $blk['items'] : array();
                $ordered = !empty($blk['ordered']);
                foreach ($items as $idx => $it) {
                    $out .= ($ordered ? (($idx + 1) . '. ') : '- ') . $it . "\n";
                }
                if (!empty($items)) {
                    $out .= "\n";
                }
            } elseif ($type === 'core/image') {
                $alt = isset($blk['altText']) ? (string) $blk['altText'] : '';
                $url = isset($blk['url']) ? (string) $blk['url'] : '';
                if ($url !== '') {
                    $out .= '![' . $alt . '](' . $url . ')' . "\n\n";
                }
            } elseif ($type === 'core/code') {
                $txt = isset($blk['content']) ? (string) $blk['content'] : '';
                if ($txt !== '') {
                    $out .= "```\n" . $txt . "\n```\n\n";
                }
            } elseif ($type === 'core/quote') {
                $txt = isset($blk['content']) ? (string) $blk['content'] : '';
                if ($txt !== '') {
                    foreach (preg_split('/\r\n|\r|\n/', $txt) as $ln) {
                        $out .= '> ' . $ln . "\n";
                    }
                    $out .= "\n";
                }
            }
        }

        return rtrim($out) . "\n";
    }

    private static function if_none_match(WP_REST_Request $req, string $etag): bool {
        $header = (string) $req->get_header('if-none-match');
        if ($header === '' && isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            $header = trim((string) $_SERVER['HTTP_IF_NONE_MATCH']);
        }

        if ($header === '') {
            return false;
        }

        foreach (explode(',', $header) as $token) {
            $token = trim($token);
            if (stripos($token, 'W/') === 0) {
                $token = trim(substr($token, 2));
            }
            if (strlen($token) >= 2 && $token[0] === '"' && substr($token, -1) === '"') {
                $token = substr($token, 1, -1);
            }
            if ($token === $etag) {
                return true;
            }
        }

        return false;
    }

    private static function add_context_headers(WP_REST_Response $resp, string $etag, $modified, string $content_type): void {
        $resp->header('Content-Type', $content_type);
        $resp->header('ETag', '"' . $etag . '"');
        if (!empty($modified)) {
            $timestamp = strtotime((string) $modified);
            if ($timestamp !== false) {
                $resp->header('Last-Modified', gmdate('D, d M Y H:i:s', $timestamp) . ' GMT');
            }
        }
        $resp->header('Cache-Control', 'max-age=0, must-revalidate');
    }
}

WPCA_REST::init();

