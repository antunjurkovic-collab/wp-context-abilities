<?php
// Real WordPress request/response/parser classes; only database and host services are doubled.
$root = isset($argv[1]) ? rtrim($argv[1], '/\\') : '';
if (!is_file($root . '/wp-includes/rest-api/class-wp-rest-server.php')) {
    fwrite(STDERR, "Usage: php tests/run-regressions.php <wordpress-source-root>\n");
    exit(2);
}
define('ABSPATH', $root . '/');
foreach (array('class-wp-error.php', 'class-wp-post.php', 'class-wp-http-response.php',
    'rest-api/class-wp-rest-response.php', 'rest-api/class-wp-rest-request.php',
    'rest-api/class-wp-rest-server.php', 'class-wp-block-parser-block.php',
    'class-wp-block-parser-frame.php', 'class-wp-block-parser.php') as $file) {
    require_once ABSPATH . 'wp-includes/' . $file;
}

$GLOBALS['fixture_hooks'] = array();
$GLOBALS['fixture_posts'] = array();
$GLOBALS['fixture_editable'] = array();
$GLOBALS['fixture_links'] = 'first';
$GLOBALS['fixture_author'] = 'Author';
$GLOBALS['fixture_status'] = 200;
$GLOBALS['fixture_routes'] = array();
$GLOBALS['fixture_abilities'] = array();

function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['fixture_hooks'][$tag][$priority][] = array($callback, $accepted_args);
}
function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {
    add_filter($tag, $callback, $priority, $accepted_args);
}
function apply_filters($tag, $value, ...$args) {
    $hooks = $GLOBALS['fixture_hooks'][$tag] ?? array();
    ksort($hooks);
    foreach ($hooks as $callbacks) {
        foreach ($callbacks as $entry) {
            $value = call_user_func_array($entry[0], array_slice(array_merge(array($value), $args), 0, $entry[1]));
        }
    }
    return $value;
}
function plugin_dir_path($path) { return dirname($path) . '/'; }
function __($text, $domain = '') { return $text; }
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
function wp_strip_all_tags($text, $remove_breaks = false) {
    $text = strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $text));
    return trim($remove_breaks ? preg_replace('/[\r\n\t ]+/', ' ', $text) : $text);
}
function parse_blocks($text) { return (new WP_Block_Parser())->parse($text); }
function status_header($code) { $GLOBALS['fixture_status'] = $code; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function rest_parse_embed_param($value) { return !$value || $value === 'true' || $value === '1' ? true : explode(',', $value); }
function apply_filters_deprecated($tag, $args, ...$unused) { return apply_filters($tag, ...$args); }
function get_option($name) { return $name === 'blog_charset' ? 'UTF-8' : null; }
function get_rest_url() { return 'https://example.invalid/wp-json/'; }
function sanitize_url($url) { return $url; }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function rest_ensure_response($response) { return $response instanceof WP_REST_Response ? $response : new WP_REST_Response($response); }
function is_user_logged_in() { return true; }
function wp_get_nocache_headers() { return array('Cache-Control' => 'no-store, private', 'Last-Modified' => false); }
function register_rest_route($namespace, $route, $args) { $GLOBALS['fixture_routes'][$route] = $args; }
function wp_register_ability($name, $args) { $GLOBALS['fixture_abilities'][$name] = $args; return true; }
function current_user_can($capability, $id = null) {
    return $capability === 'edit_posts' || in_array((int) $id, $GLOBALS['fixture_editable'], true);
}
function get_post($id) {
    return isset($GLOBALS['fixture_posts'][$id]) ? new WP_Post((object) $GLOBALS['fixture_posts'][$id]) : null;
}
function get_posts($args) {
    $GLOBALS['fixture_query'] = $args;
    $posts = array_values($GLOBALS['fixture_posts']);
    if (isset($args['date_query'][0]['after'])) {
        $since = strtotime($args['date_query'][0]['after']);
        $posts = array_values(array_filter($posts, function($post) use ($since) {
            return strtotime($post['post_modified_gmt']) > $since;
        }));
    }
    usort($posts, function($a, $b) {
        return strcmp($b['post_modified_gmt'], $a['post_modified_gmt']) ?: ($b['ID'] <=> $a['ID']);
    });
    $page = array_slice($posts, ($args['paged'] - 1) * $args['posts_per_page'], $args['posts_per_page']);
    return array_column($page, 'ID');
}
function get_post_field($field, $id) { return $GLOBALS['fixture_posts'][$id][$field] ?? ''; }
function get_post_status($id) { return get_post_field('post_status', $id); }
function get_the_title($post) { return get_post_field('post_title', $post instanceof WP_Post ? $post->ID : $post); }
function get_post_thumbnail_id($post) { return 0; }
function get_the_author_meta($field, $id) { return $GLOBALS['fixture_author']; }
function get_author_posts_url($id) { return 'https://example.invalid/author/' . $id; }
function get_the_category($id) { return array(); }
function get_the_tags($id) { return array(); }
function get_permalink($id) { return 'https://example.invalid/' . $GLOBALS['fixture_links'] . '/' . $id; }
function rest_url($path) { return 'https://example.invalid/wp-json/' . $path; }

class WPCA_Test_Server extends WP_REST_Server {
    public $sent = array();
    public $fixture_response;
    public function send_header($key, $value) { $this->sent[$key] = $value; }
    public function remove_header($key) { unset($this->sent[$key]); }
    public function core_json($data, $request) { return wp_json_encode($data, $this->get_json_encode_options($request)); }
    public function check_authentication() { return null; }
    public function dispatch($request) { return $this->fixture_response; }
}

function fixture_post($id, $content = '') {
    $GLOBALS['fixture_posts'][$id] = array(
        'ID' => $id, 'post_type' => 'post', 'post_title' => 'Fixture', 'post_content' => $content,
        'post_author' => 1, 'post_status' => 'draft', 'post_date_gmt' => '2026-10-01 10:00:00',
        'post_modified' => '2026-10-15 10:00:00', 'post_modified_gmt' => '2026-10-15 10:00:00',
    );
}
function request($path = '/posts/22', $params = array(), $headers = array(), $method = 'GET') {
    $request = new WP_REST_Request($method, '/wp-context/v1' . $path);
    $request->set_query_params($params);
    if (preg_match('#/posts/([0-9]+)#', $path, $matches)) {
        $request->set_url_params(array('id' => (int) $matches[1]));
    }
    $request->set_headers($headers);
    return $request;
}
function serve($request, $response, $already_served = false) {
    $server = new WPCA_Test_Server();
    $server->send_headers($response->get_headers());
    $GLOBALS['fixture_status'] = $response->get_status();
    ob_start();
    try {
        $served = apply_filters('rest_pre_serve_request', $already_served, $response, $request, $server);
        $body = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    return array('served' => $served, 'body' => $body, 'headers' => $server->sent, 'status' => $GLOBALS['fixture_status']);
}

function core_serve($request, $response) {
    $saved = array($_GET, $_POST, $_FILES, $_SERVER);
    $_GET = $request->get_query_params();
    $_POST = $_FILES = array();
    $_SERVER = array('REQUEST_METHOD' => $request->get_method());
    foreach ($request->get_headers() as $name => $values) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = implode(',', $values);
    }
    $server = new WPCA_Test_Server();
    $server->fixture_response = $response;
    ob_start();
    try {
        // Run the actual WordPress serving pipeline, not a copied serialization algorithm.
        $server->serve_request($request->get_route());
        $body = ob_get_contents();
    } finally {
        ob_end_clean();
        list($_GET, $_POST, $_FILES, $_SERVER) = $saved;
    }
    return array('body' => $body, 'headers' => $server->sent, 'status' => $GLOBALS['fixture_status']);
}
