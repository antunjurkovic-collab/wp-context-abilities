<?php
/**
 * Plugin Name: WP Context Abilities
 * Description: Read-only structured WordPress context for AI workflows. v0 exposes post/page MR, Markdown, CID/ETag, catalog, and Abilities API surfaces.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Antun Jurkovikj
 * License: GPL v2 or later
 * Text Domain: wp-context-abilities
 */

if (!defined('ABSPATH')) { exit; }

define('WPCA_VERSION', '0.1.0');
define('WPCA_DIR', plugin_dir_path(__FILE__));

// Machine Representation profile identifier used in Content-Type headers.
if (!defined('WPCA_PROFILE')) {
    define('WPCA_PROFILE', 'wp-context-content-1.0');
}

require_once WPCA_DIR . 'includes/class-wpca-mr.php';
require_once WPCA_DIR . 'includes/class-wpca-cid.php';
require_once WPCA_DIR . 'includes/class-wpca-rest.php';
require_once WPCA_DIR . 'includes/class-wpca-abilities.php';

WPCA_Abilities::init();

// Content-Digest (RFC 9530) computed over final bytes at serve time for WP Context routes.
add_filter('rest_pre_serve_request', function($served, $result, $request, $server){
    try {
        if (!($request instanceof WP_REST_Request)) return $served;
        $route = (string) $request->get_route();
        if (strpos($route, '/wp-context/v1/') !== 0) return $served;
        $status = (int) $result->get_status();
        if ($status < 200 || $status >= 300 || $status === 204) return $served;

        $data = $server->response_to_data($result, false);
        $headers = $result->get_headers();
        $ctype = is_array($headers) && isset($headers['Content-Type']) ? (string)$headers['Content-Type'] : '';
        $is_json = (stripos($ctype, 'application/json') !== false) || is_array($data) || is_object($data);

        if ($is_json) {
            $json_options = apply_filters('rest_json_encode_options', 0, $request);
            $body = wp_json_encode($data, $json_options);
            if ($body !== false && $body !== null) {
                header('Content-Digest: sha-256=:' . base64_encode(hash('sha256', (string)$body, true)) . ':', true);
            }
            return $served;
        }

        $body = is_string($data) ? $data : (is_scalar($data) ? (string)$data : wp_json_encode($data));
        if ($body !== false && $body !== null) {
            header('Content-Digest: sha-256=:' . base64_encode(hash('sha256', (string)$body, true)) . ':', true);
            if (!headers_sent()) {
                $server->send_headers($result);
            }
            echo (string)$body;
            return true;
        }
    } catch (Throwable $e) {
        // Digest failure must not break the REST response.
    }
    return $served;
}, 11, 4);
