<?php
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/class-wpca-mr.php';
require_once __DIR__ . '/class-wpca-cid.php';

class WPCA_REST {
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_filter('rest_pre_serve_request', array(__CLASS__, 'serve_response'), PHP_INT_MAX, 4);
    }

    public static function serve_response($served, $result, $request, $server) {
        if ($served || !($request instanceof WP_REST_Request) ||
            !($result instanceof WP_REST_Response) || $result->get_status() !== 200 ||
            !in_array($request->get_method(), array('GET', 'HEAD'), true) ||
            !preg_match('#^/wp-context/v1/(catalog|posts/[0-9]+(?:/md)?)$#D', $request->get_route())) {
            return $served;
        }

        // Core owns these wrappers; do not attach a validator for an unwrapped body.
        if ($request->has_param('_jsonp') || $request->has_param('_envelope')) {
            return $served;
        }

        $embed = $request->has_param('_embed') ? rest_parse_embed_param($request->get_param('_embed')) : false;
        $data = $server->response_to_data($result, $embed);
        $data = apply_filters('rest_pre_echo_response', $data, $server, $request);
        if ($data === null) {
            return $served;
        }

        $headers = $result->get_headers();
        $content_type = isset($headers['Content-Type']) ? $headers['Content-Type'] : '';
        $markdown = is_string($data) && stripos($content_type, 'text/markdown') === 0;
        if ($markdown) {
            $body = $data;
        } else {
            $options = $request->has_param('_pretty') ? JSON_PRETTY_PRINT : 0;
            $options = apply_filters('rest_json_encode_options', $options, $request);
            $body = wp_json_encode($data, $options);
            if ($body === false) {
                return $served;
            }
            $server->send_header('Content-Type', stripos($content_type, 'application/json') === 0 ? $content_type : 'application/json; charset=UTF-8');
        }

        // Hash and emit one serialization, after Core's final data/encoding filters.
        $digest = hash('sha256', $body, true);
        $etag = 'sha256-' . bin2hex($digest);
        $server->send_header('ETag', '"' . $etag . '"');
        if (self::if_none_match($request, $etag)) {
            status_header(304);
            $server->remove_header('Content-Digest');
            return true;
        }

        if ($request->get_method() === 'HEAD') {
            $server->remove_header('Content-Digest');
            return true;
        }
        $server->send_header('Content-Digest', 'sha-256=:' . base64_encode($digest) . ':');
        echo $body;
        return true;
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

        $resp = new WP_REST_Response($mr, 200);
        self::add_context_headers($resp, $mr['modified'] ?? null, 'application/json; profile="' . WPCA_PROFILE . '"');
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
        $resp = new WP_REST_Response($md, 200);
        self::add_context_headers($resp, $mr['modified'] ?? null, 'text/markdown; charset=UTF-8');
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
            'orderby' => array('modified' => 'DESC', 'ID' => 'DESC'),
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
        // A full query page may yield no editable items. Continue independently of count.
        $next_page = count($ids) >= $per_page ? $page + 1 : null;
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
            'next_page' => $next_page,
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
                $txt = trim((string) ($blk['markdown'] ?? $blk['content'] ?? ''));
                if ($txt !== '') {
                    $out .= str_repeat('#', $lvl) . ' ' . $txt . "\n\n";
                }
            } elseif ($type === 'core/paragraph' || $type === 'unknown') {
                $txt = trim((string) ($blk['markdown'] ?? $blk['content'] ?? ''));
                if ($txt !== '') {
                    $out .= $txt . "\n\n";
                }
            } elseif ($type === 'core/list') {
                $items = isset($blk['items']) && is_array($blk['items']) ? $blk['items'] : array();
                $ordered = !empty($blk['ordered']);
                foreach ($items as $idx => $it) {
                    $prefix = $ordered ? (($idx + 1) . '. ') : '- ';
                    $out .= $prefix . str_replace("\n", "\n" . str_repeat(' ', strlen($prefix)), $it) . "\n";
                }
                if (!empty($items)) {
                    $out .= "\n";
                }
            } elseif ($type === 'core/image') {
                $alt = isset($blk['altText']) ? (string) $blk['altText'] : '';
                $url = isset($blk['url']) ? (string) $blk['url'] : '';
                if ($url !== '') {
                    $alt = str_replace(array('\\', '[', ']', '<', '>'), array('\\\\', '\\[', '\\]', '&lt;', '&gt;'), $alt);
                    $url = str_replace(array(' ', '<', '>', "\r", "\n"), array('%20', '%3C', '%3E', '%0D', '%0A'), $url);
                    $out .= '![' . $alt . '](<' . $url . '>)' . "\n\n";
                }
                if (isset($blk['caption']) && (string) $blk['caption'] !== '') {
                    $out .= str_replace(array('<', '>'), array('&lt;', '&gt;'), $blk['caption']) . "\n\n";
                }
            } elseif ($type === 'core/code') {
                $txt = isset($blk['content']) ? (string) $blk['content'] : '';
                if ($txt !== '') {
                    $out .= "```\n" . $txt . "\n```\n\n";
                }
            } elseif ($type === 'core/quote') {
                $txt = (string) ($blk['markdown'] ?? $blk['content'] ?? '');
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
            $header = (string) $_SERVER['HTTP_IF_NONE_MATCH'];
        }
        $header = trim($header, " \t");
        if ($header === '') {
            return false;
        }
        if ($header === '*') {
            return true;
        }

        $matched = false;
        $offset = 0;
        $length = strlen($header);
        while ($offset < $length) {
            $offset += strspn($header, " \t,", $offset);
            if ($offset === $length) {
                break;
            }
            // RFC 9110 entity-tags can contain commas; backslashes do not escape quotes.
            if (preg_match('/\G(?:W\/)?"([\x21\x23-\x7e\x80-\xff]*)"[ \t]*(?:,|\z)/', $header, $token, 0, $offset) !== 1) {
                return false;
            }
            $matched = $matched || $token[1] === $etag;
            $offset += strlen($token[0]);
        }

        return $matched;
    }

    private static function add_context_headers(WP_REST_Response $resp, $modified, string $content_type): void {
        $resp->header('Content-Type', $content_type);
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

