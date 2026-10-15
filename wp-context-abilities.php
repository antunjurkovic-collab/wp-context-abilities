<?php
/**
 * Plugin Name: WP Context Abilities
 * Description: Read-only structured WordPress context for AI workflows. v0 exposes post/page MR, Markdown, CID/ETag, catalog, and Abilities API surfaces.
 * Version: 0.1.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Antun Jurkovikj
 * License: GPL v2 or later
 * Text Domain: wp-context-abilities
 */

if (!defined('ABSPATH')) { exit; }

define('WPCA_VERSION', '0.1.1');
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
