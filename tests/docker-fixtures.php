<?php
// CLI-only fixture setup for the disposable Docker test, never a plugin endpoint.
if (PHP_SAPI !== 'cli' || getenv('WPCA_DISPOSABLE_TEST') !== '1') {
    http_response_code(404);
    exit(1);
}
$fixture_action = $argv[1] ?? '';
$base_url = getenv('WPCA_TEST_URL');
if (!preg_match('#^http://127\.0\.0\.1:[0-9]+$#D', $base_url ?: '')) {
    throw new RuntimeException('Disposable loopback URL required');
}
$_SERVER['HTTP_HOST'] = substr($base_url, strlen('http://'));
$_SERVER['REQUEST_URI'] = '/';
if ($fixture_action === 'setup') {
    define('WP_INSTALLING', true);
}
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

function fixture_value($value) {
    if (is_wp_error($value)) {
        throw new RuntimeException('Fixture API failed: ' . $value->get_error_code());
    }
    return $value;
}

if ($fixture_action === 'setup') {
    if (is_blog_installed()) {
        throw new RuntimeException('Refusing to reuse an installed site');
    }
    $password = getenv('WPCA_TEST_PASSWORD');
    if (!is_string($password) || strlen($password) < 32) {
        throw new RuntimeException('Ephemeral password required');
    }
    add_filter('pre_wp_mail', '__return_true');
    $installed = wp_install('Disposable context test', 'fixture_admin', 'admin@example.invalid', 0, '', $password);
    $admin = (int) $installed['user_id'];
    update_option('home', $base_url);
    update_option('siteurl', $base_url);
    update_option('permalink_structure', '/%postname%/');
    $author = fixture_value(wp_insert_user(array(
        'user_login' => 'fixture_author', 'user_pass' => $password,
        'user_email' => 'author@example.invalid', 'role' => 'author',
    )));
    $subscriber = fixture_value(wp_insert_user(array(
        'user_login' => 'fixture_subscriber', 'user_pass' => $password,
        'user_email' => 'subscriber@example.invalid', 'role' => 'subscriber',
    )));
    $runtime = array('wp-context-abilities.php', 'includes/class-wpca-abilities.php',
        'includes/class-wpca-cid.php', 'includes/class-wpca-mr.php', 'includes/class-wpca-rest.php');
    $destination = WP_PLUGIN_DIR . '/wp-context-abilities/';
    wp_mkdir_p($destination . 'includes');
    $hashes = array();
    foreach ($runtime as $file) {
        if (!copy('/opt/wpca-source/' . $file, $destination . $file)) {
            throw new RuntimeException('Runtime copy failed');
        }
        $hashes[$file] = hash_file('sha256', $destination . $file);
    }
    fixture_value(activate_plugin('wp-context-abilities/wp-context-abilities.php'));
    $content = '<!-- wp:heading --><h2>Review heading</h2><!-- /wp:heading -->'
        . '<!-- wp:list --><ul><!-- wp:list-item --><li>Alpha</li><!-- /wp:list-item -->'
        . '<!-- wp:list-item --><li>Beta</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
        . '<!-- wp:list --><ul><!-- wp:list-item --><li>Parent<!-- wp:list {"ordered":true} -->'
        . '<ol><!-- wp:list-item --><li>Child</li><!-- /wp:list-item -->'
        . '<!-- wp:list-item --><li>Sibling</li><!-- /wp:list-item --></ol><!-- /wp:list -->'
        . '</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
        . '<!-- wp:list {"ordered":true} --><ol><li>Legacy one</li><li>Legacy two</li></ol><!-- /wp:list -->';
    $ids = array();
    foreach (array('own' => array($author, 'draft'), 'other' => array($admin, 'draft'),
        'published' => array($author, 'publish')) as $key => $binding) {
        $ids[$key] = fixture_value(wp_insert_post(wp_slash(array(
            'post_title' => 'Context fixture ' . $key, 'post_content' => $content,
            'post_name' => 'context-fixture-' . $key, 'post_type' => 'post',
            'post_author' => $binding[0], 'post_status' => $binding[1],
            'post_date' => '2026-01-01 12:00:00', 'post_date_gmt' => '2026-01-01 12:00:00',
        )), true));
    }
    update_option('wpca_docker_fixture_ids', $ids, false);
    $credentials = array();
    foreach (array('admin' => $admin, 'author' => $author, 'subscriber' => $subscriber) as $role => $id) {
        $created = fixture_value(WP_Application_Passwords::create_new_application_password(
            $id, array('name' => 'Disposable Docker HTTP test')));
        $credentials[$role] = array('user' => 'fixture_' . $role, 'password' => $created[0]);
    }
    echo wp_json_encode(array('ids' => $ids, 'credentials' => $credentials,
        'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION,
        'database' => $wpdb->get_var('SELECT VERSION()'), 'runtime_hashes' => $hashes));
} elseif ($fixture_action === 'fidelity-checks') {
    $results = array();
    $record = function($name, $ok) use (&$results) {
        $results[] = array('name' => $name, 'ok' => (bool) $ok);
    };
    $inspect = function($html) {
        $id = fixture_value(wp_insert_post(wp_slash(array(
            'post_title' => 'Disposable fidelity case', 'post_content' => $html,
            'post_status' => 'draft', 'post_type' => 'post',
        )), true));
        try {
            $mr = WPCA_MR::build($id);
            return array($mr, WPCA_REST::to_markdown($mr));
        } finally {
            wp_delete_post($id, true);
        }
    };
    list($mr, $md) = $inspect('<!-- wp:image {"alt":"Stale attribute"} -->'
        . '<figure><img src=\'https://example.invalid/a_(b).png?x=1&amp;y=2\' alt=\'A &amp; B [diagram]\' />'
        . '<figcaption><em>Caption</em> &amp; notes</figcaption></figure><!-- /wp:image -->');
    $record('html_alt_overrides_stale_comment_attribute', $mr['blocks'][0]['altText'] === 'A & B [diagram]');
    $record('single_quoted_image_url_decoded', $mr['blocks'][0]['url'] === 'https://example.invalid/a_(b).png?x=1&y=2');
    $record('caption_markup_removed_and_entities_decoded', $mr['blocks'][0]['caption'] === 'Caption & notes');
    $record('caption_in_plain_text', strpos($mr['core_content_text'], 'Caption & notes') !== false);
    $record('markdown_image_label_escaped', strpos($md, '![A & B \\[diagram\\]](<https://example.invalid/a_(b).png?x=1&y=2>)') !== false);
    $record('markdown_caption_once', substr_count($md, 'Caption & notes') === 1);

    list($mr, $md) = $inspect('<!-- wp:image {"alt":"Stale description"} -->'
        . '<figure><img src="https://example.invalid/empty.png" alt="" /></figure><!-- /wp:image -->');
    $record('explicit_empty_html_alt_is_retained', $mr['blocks'][0]['altText'] === '');
    $record('empty_alt_does_not_fall_back_to_stale_description', strpos($md, 'Stale description') === false);
    list($mr, $md) = $inspect('<!-- wp:image {"alt":"Legacy description"} -->'
        . '<figure><img src="https://example.invalid/legacy.png" /></figure><!-- /wp:image -->');
    $record('missing_html_alt_uses_legacy_attribute', $mr['blocks'][0]['altText'] === 'Legacy description');

    list($mr, $md) = $inspect('<!-- wp:paragraph --><p>Read '
        . '<a href=\'https://example.invalid/a_(b)?x=1&amp;y=2\'><strong>Guide</strong> &amp; notes</a>'
        . ' then <a href="/next">[Details]</a>.</p><!-- /wp:paragraph -->');
    $targets = $mr['blocks'][0]['link_targets'] ?? array();
    $record('both_link_targets_and_labels_retained', $targets === array(
        array('text' => 'Guide & notes', 'url' => 'https://example.invalid/a_(b)?x=1&y=2'),
        array('text' => '[Details]', 'url' => '/next')));
    $record('markdown_link_preserves_position_and_spacing', strpos($md,
        'Read [Guide & notes](<https://example.invalid/a_(b)?x=1&y=2>) then [\\[Details\\]](</next>).') !== false);
    $record('linked_label_not_duplicated', substr_count($md, 'Guide & notes') === 1);
    $changed = $mr;
    $changed['blocks'][0]['link_targets'][0]['url'] = 'https://example.invalid/changed';
    $record('link_targets_participate_in_application_cid', WPCA_CID::compute($mr) !== WPCA_CID::compute($changed));

    list($mr, $md) = $inspect('<!-- wp:heading --><h2>Read <a href="/guide">Guide</a></h2><!-- /wp:heading -->');
    $record('heading_link_preserved', strpos($md, '## Read [Guide](</guide>)') !== false);
    list($mr, $md) = $inspect('<!-- wp:image --><figure><img src="/zero.png" alt="" />'
        . '<figcaption>0</figcaption></figure><!-- /wp:image -->');
    $record('zero_caption_preserved_in_text_and_markdown', $mr['core_content_text'] === '0' && strpos($md, "\n\n0\n") !== false);
    list($mr, $md) = $inspect('<!-- wp:paragraph --><p>&lt;em&gt;literal&lt;/em&gt; '
        . '<a href="/safe">safe</a></p><!-- /wp:paragraph -->');
    $record('literal_html_beside_link_stays_text', strpos($md, '&lt;em&gt;literal&lt;/em&gt; [safe](</safe>)') !== false && strpos($md, '<em>') === false);
    $failed = count(array_filter($results, function($result) { return !$result['ok']; }));
    echo wp_json_encode(array('ok' => $failed === 0, 'checks' => count($results), 'failed' => $failed, 'results' => $results));
} elseif ($fixture_action === 'editorial-setup') {
    $ids = get_option('wpca_docker_fixture_ids');
    $post = get_post($ids['own']);
    fixture_value(wp_update_user(array('ID' => $post->post_author, 'display_name' => 'Fixture Editor')));
    $category = fixture_value(wp_insert_term('Operations', 'category'));
    $tag = fixture_value(wp_insert_term('Restore rehearsal', 'post_tag'));
    $upload = wp_upload_bits('editorial-fixture.png', null, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0ZkAAAAASUVORK5CYII='));
    if ($upload['error']) {
        throw new RuntimeException('Fixture image creation failed');
    }
    $image = fixture_value(wp_insert_attachment(array(
        'post_title' => 'Fixture backup image', 'post_mime_type' => 'image/png',
        'post_status' => 'inherit', 'post_author' => $post->post_author,
    ), $upload['file'], $ids['own'], true));
    wp_update_attachment_metadata($image, array(
        'width' => 1, 'height' => 1, 'file' => _wp_relative_upload_path($upload['file']),
    ));
    update_post_meta($image, '_wp_attachment_image_alt', 'Featured backup overview');
    set_post_thumbnail($ids['own'], $image);
    $guide_url = get_permalink($ids['published']);
    $content = '<!-- wp:heading --><h2>Before publication</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Review backups &amp; restore steps before publishing. '
        . 'Read the <a href="' . esc_url($guide_url) . '">backup guide</a>.</p><!-- /wp:paragraph -->'
        . '<!-- wp:list --><ul><!-- wp:list-item --><li>Check backups</li><!-- /wp:list-item -->'
        . '<!-- wp:list-item --><li>Confirm rollback</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
        . '<!-- wp:list --><ul><!-- wp:list-item --><li>Release checklist<!-- wp:list {"ordered":true} -->'
        . '<ol><!-- wp:list-item --><li>Export data</li><!-- /wp:list-item -->'
        . '<!-- wp:list-item --><li>Verify restore</li><!-- /wp:list-item --></ol><!-- /wp:list -->'
        . '</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
        . '<!-- wp:image {"id":' . $image . ',"sizeSlug":"full","linkDestination":"none"} -->'
        . '<figure class="wp-block-image size-full"><img src="' . esc_url($upload['url'])
        . '" alt="Database backup diagram" class="wp-image-' . $image . '"/>'
        . '<figcaption class="wp-element-caption">Backup and restore sequence</figcaption></figure><!-- /wp:image -->';
    fixture_value(wp_update_post(wp_slash(array(
        'ID' => $ids['own'], 'post_title' => 'Release readiness checklist', 'post_content' => $content,
    )), true));
    fixture_value(wp_set_post_terms($ids['own'], array((int) $category['term_id']), 'category'));
    fixture_value(wp_set_post_terms($ids['own'], array((int) $tag['term_id']), 'post_tag'));
    echo wp_json_encode(array('expected' => array(
        'title' => 'Release readiness checklist', 'status' => 'draft', 'author' => 'Fixture Editor',
        'heading' => 'Before publication', 'paragraph' => 'Review backups & restore steps before publishing.',
        'unordered_items' => array('Check backups', 'Confirm rollback'),
        'nested_list' => array('Release checklist', 'Export data', 'Verify restore'),
        'inline_image_alt' => 'Database backup diagram', 'image_caption' => 'Backup and restore sequence',
        'internal_link' => $guide_url, 'category' => 'Operations', 'tag' => 'Restore rehearsal',
        'featured_image_alt' => 'Featured backup overview',
    )));
} elseif ($fixture_action === 'state') {
    $posts = $wpdb->get_results("SELECT * FROM {$wpdb->posts} ORDER BY ID", ARRAY_A);
    $meta = $wpdb->get_results("SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A);
    echo wp_json_encode(array('content_commitment' => hash('sha256', wp_json_encode(array($posts, $meta)))));
} elseif ($fixture_action === 'link-change') {
    $ids = get_option('wpca_docker_fixture_ids');
    // Change only an emitted link dependency, not the post's supported MR fields.
    $changed = $wpdb->update($wpdb->posts, array('post_name' => 'context-fixture-new-link'), array('ID' => $ids['published']));
    clean_post_cache($ids['published']);
    echo wp_json_encode(array('changed' => $changed === 1));
} elseif ($fixture_action === 'author-change') {
    $ids = get_option('wpca_docker_fixture_ids');
    $post = get_post($ids['published']);
    $before = $post->post_modified_gmt;
    fixture_value(wp_update_user(array('ID' => $post->post_author, 'display_name' => 'Renamed fixture author')));
    echo wp_json_encode(array('post_modified_unchanged' => get_post($ids['published'])->post_modified_gmt === $before));
} else {
    throw new RuntimeException('Unknown fixture action');
}
