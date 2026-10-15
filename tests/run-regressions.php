<?php
require __DIR__ . '/wordpress-fixture.php';
require dirname(__DIR__) . '/wp-context-abilities.php';

$checks = array();
function check($name, $condition) {
    global $checks;
    $checks[] = array('name' => $name, 'ok' => (bool) $condition);
}
function identity_matches($response) {
    $hash = hash('sha256', $response['body'], true);
    return ($response['headers']['ETag'] ?? '') === '"sha256-' . bin2hex($hash) . '"' &&
        ($response['headers']['Content-Digest'] ?? '') === 'sha-256=:' . base64_encode($hash) . ':';
}

try {
    fixture_post(22, '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->');
    $GLOBALS['fixture_editable'] = array(22);
    $request = request();
    $first_mr = WPCA_REST::get_post_mr($request);
    $first = serve($request, $first_mr);
    check('json_body_identity', $first['served'] && identity_matches($first));
    check('http_etag_is_not_application_cid', $first['headers']['ETag'] !== '"' . $first_mr->get_data()['cid'] . '"');

    $GLOBALS['fixture_links'] = 'second';
    $request = request('/posts/22', array(), array('If-None-Match' => $first['headers']['ETag']));
    $second_mr = WPCA_REST::get_post_mr($request);
    $second = serve($request, $second_mr);
    check('link_only_change_keeps_application_cid', $first_mr->get_data()['cid'] === $second_mr->get_data()['cid']);
    check('link_only_change_returns_200_with_new_etag', $second['status'] === 200 && $first['headers']['ETag'] !== $second['headers']['ETag']);
    check('changed_body_digest_matches', identity_matches($second));

    foreach (array('strong' => $second['headers']['ETag'], 'weak' => 'W/' . $second['headers']['ETag'],
        'list' => '"different", ' . $second['headers']['ETag'], 'wildcard' => '*') as $name => $condition) {
        $request = request('/posts/22', array(), array('If-None-Match' => $condition));
        $response = serve($request, WPCA_REST::get_post_mr($request));
        check('unchanged_' . $name . '_304', $response['status'] === 304 && $response['body'] === '' &&
            $response['headers']['ETag'] === $second['headers']['ETag'] && !isset($response['headers']['Content-Digest']));
    }

    $current_tag = $second['headers']['ETag'];
    $opaque_tag = substr($current_tag, 1, -1);
    foreach (array(
        'quoted_comma_wildcard' => '"prefix,*,suffix"',
        'weak_quoted_comma_wildcard' => 'W/"prefix,*,suffix"',
        'quoted_comma_current_tag' => '"prefix,' . $opaque_tag . ',suffix"',
        'weak_quoted_comma_current_tag' => 'W/"prefix,' . $opaque_tag . ',suffix"',
        'quoted_wildcard' => '"*"',
        'bare_current_tag' => $opaque_tag,
        'wildcard_before_tag' => '*, ' . $current_tag,
        'wildcard_after_tag' => $current_tag . ', *',
        'matched_then_unterminated_tag' => $current_tag . ', "unterminated',
        'matched_then_adjacent_tag' => $current_tag . ' "different"',
        'spaced_weak_prefix' => 'W/ ' . $current_tag,
        'lowercase_weak_prefix' => 'w/' . $current_tag,
    ) as $name => $condition) {
        $request = request('/posts/22', array(), array('If-None-Match' => $condition));
        $response = serve($request, WPCA_REST::get_post_mr($request));
        check('if_none_match_' . $name . '_200', $response['status'] === 200 &&
            $response['body'] === $second['body'] && identity_matches($response));
    }
    foreach (array(
        'quoted_comma_before' => '"prefix,*,suffix", ' . $current_tag,
        'quoted_comma_before_weak' => '"prefix,' . $opaque_tag . ',suffix", W/' . $current_tag,
        'quoted_comma_after' => $current_tag . ', "prefix,*,suffix"',
        'ows_and_empty_members' => " \t, , " . $current_tag . " ,\t, ",
        'empty_opaque_before' => '"", ' . $current_tag,
        'literal_backslash_before' => '"back\\slash,tag", ' . $current_tag,
    ) as $name => $condition) {
        $request = request('/posts/22', array(), array('If-None-Match' => $condition));
        $response = serve($request, WPCA_REST::get_post_mr($request));
        check('if_none_match_' . $name . '_304', $response['status'] === 304 && $response['body'] === '' &&
            $response['headers']['ETag'] === $current_tag && !isset($response['headers']['Content-Digest']));
    }

    $request = request('/posts/22', array('_pretty' => '1'), array('If-None-Match' => $second['headers']['ETag']));
    $mr = WPCA_REST::get_post_mr($request);
    $pretty = serve($request, $mr);
    check('pretty_matches_actual_core_serializer', $pretty['body'] === (new WPCA_Test_Server())->core_json($mr->get_data(), $request));
    check('pretty_has_different_etag_and_200', $pretty['status'] === 200 && $pretty['headers']['ETag'] !== $second['headers']['ETag']);
    check('pretty_digest_matches_served_bytes', identity_matches($pretty));
    $request->set_header('If-None-Match', $pretty['headers']['ETag']);
    $response = serve($request, $mr);
    check('pretty_revalidation_304', $response['status'] === 304 && $response['body'] === '');

    $request = request('/posts/22', array(), array(), 'HEAD');
    $response = serve($request, WPCA_REST::get_post_mr($request));
    check('head_identity_without_body', $response['status'] === 200 && $response['body'] === '' &&
        $response['headers']['ETag'] === $second['headers']['ETag'] &&
        !isset($response['headers']['Content-Digest']));

    $filter_calls = 0;
    add_filter('rest_pre_echo_response', function($data) use (&$filter_calls) {
        ++$filter_calls;
        $data['late_field'] = 'late data';
        return $data;
    });
    $request = request('/posts/22', array(), array('If-None-Match' => $second['headers']['ETag']));
    $response = serve($request, WPCA_REST::get_post_mr($request));
    check('late_response_filter_applied_once', $filter_calls === 1 && isset(json_decode($response['body'], true)['late_field']));
    check('late_response_change_prevents_304', $response['status'] === 200 && identity_matches($response) && $response['headers']['ETag'] !== $second['headers']['ETag']);
    unset($GLOBALS['fixture_hooks']['rest_pre_echo_response']);

    add_filter('rest_json_encode_options', function($options) { return $options | JSON_UNESCAPED_SLASHES; });
    $request = request('/posts/22', array('_pretty' => '1'));
    $mr = WPCA_REST::get_post_mr($request);
    $response = serve($request, $mr);
    check('custom_encoding_options_match_core', $response['body'] === (new WPCA_Test_Server())->core_json($mr->get_data(), $request));
    check('custom_encoding_digest_matches', identity_matches($response));
    unset($GLOBALS['fixture_hooks']['rest_json_encode_options']);

    add_filter('rest_pre_serve_request', function($served, $result) {
        $data = $result->get_data();
        $data['earlier_filter'] = true;
        $result->set_data($data);
        return $served;
    }, 20, 2);
    $request = request();
    $response = serve($request, WPCA_REST::get_post_mr($request));
    check('earlier_serve_filter_included_in_identity', isset(json_decode($response['body'], true)['earlier_filter']) && identity_matches($response));
    unset($GLOBALS['fixture_hooks']['rest_pre_serve_request'][20]);

    foreach (array('_jsonp' => 'callback', '_envelope' => '1') as $name => $value) {
        $request = request('/posts/22', array($name => $value));
        $response = serve($request, WPCA_REST::get_post_mr($request));
        check($name . '_delegated_without_misleading_identity', !$response['served'] && $response['body'] === '' &&
            !isset($response['headers']['ETag']) && !isset($response['headers']['Content-Digest']));
    }
    $request = request();
    $response = serve($request, WPCA_REST::get_post_mr($request), true);
    check('already_served_response_not_overwritten', $response['body'] === '' && !isset($response['headers']['ETag']));
    $request = request('/posts/22', array(), array('If-None-Match' => '*'));
    $response = serve($request, new WP_REST_Response(array('error' => 'forbidden'), 403));
    check('error_not_converted_to_304', !$response['served'] && $response['status'] === 403 && !isset($response['headers']['ETag']));
    $response = serve($request, new WP_REST_Response(array('unencodable' => INF), 200));
    check('encoding_failure_delegated_without_identity', !$response['served'] && !isset($response['headers']['Content-Digest']) && !isset($response['headers']['ETag']));

    WPCA_REST::register_routes();
    $permission = $GLOBALS['fixture_routes']['/posts/(?P<id>\d+)']['permission_callback'];
    check('editable_post_permission_preserved', $permission(request('/posts/22')));
    check('noneditable_post_permission_preserved', !$permission(request('/posts/99', array(), array('If-None-Match' => '*'))));

    $list = '<!-- wp:list --><ul><!-- wp:list-item --><li>Alpha</li><!-- /wp:list-item --><!-- wp:list-item --><li>Beta</li><!-- /wp:list-item --></ul><!-- /wp:list -->';
    fixture_post(22, $list);
    $request = request('/posts/22/md');
    $mr = WPCA_MR::build(22);
    $md = serve($request, WPCA_REST::get_post_md($request));
    check('real_parser_list_items_preserved', strpos($md['body'], "- Alpha\n- Beta\n") !== false);
    check('list_items_not_duplicated', substr_count($md['body'], 'Alpha') === 1 && substr_count($md['body'], 'Beta') === 1);
    check('list_plain_text_preserved', $mr['core_content_text'] === 'Alpha Beta');
    check('markdown_digest_matches', identity_matches($md));
    $request->set_header('If-None-Match', $md['headers']['ETag']);
    $response = serve($request, WPCA_REST::get_post_md($request));
    check('markdown_revalidation_304', $response['status'] === 304 && $response['body'] === '');

    $nested = '<!-- wp:list --><ul><!-- wp:list-item --><li>Parent<!-- wp:list {"ordered":true} --><ol><!-- wp:list-item --><li>Child</li><!-- /wp:list-item --><!-- wp:list-item --><li>Sibling</li><!-- /wp:list-item --></ol><!-- /wp:list --></li><!-- /wp:list-item --></ul><!-- /wp:list -->';
    fixture_post(22, $nested);
    $request = request('/posts/22/md');
    $response = serve($request, WPCA_REST::get_post_md($request));
    check('nested_ordered_list_preserves_hierarchy', strpos($response['body'], "- Parent\n  1. Child\n  2. Sibling\n") !== false);
    check('nested_children_not_duplicated', substr_count($response['body'], 'Child') === 1 && substr_count($response['body'], 'Sibling') === 1);
    check('nested_list_identity_matches', identity_matches($response));

    fixture_post(22, '<!-- wp:list {"ordered":true} --><ol><li>Legacy one</li><li>Legacy two</li></ol><!-- /wp:list -->');
    $response = serve($request, WPCA_REST::get_post_md($request));
    check('legacy_list_preserved', strpos($response['body'], "1. Legacy one\n2. Legacy two\n") !== false);
    add_filter('wpca_markdown', function($text) { return $text . "Filtered.\n"; });
    add_filter('rest_pre_echo_response', function($text) { return $text . "Late.\n"; });
    $response = serve($request, WPCA_REST::get_post_md($request));
    check('markdown_filters_included_in_bytes', strpos($response['body'], "Filtered.\nLate.\n") !== false && identity_matches($response));
    unset($GLOBALS['fixture_hooks']['wpca_markdown'], $GLOBALS['fixture_hooks']['rest_pre_echo_response']);

    fixture_post(33);
    $request = request('/catalog', array('per_page' => 1, 'page' => 1));
    $page1 = WPCA_REST::get_catalog($request);
    $request->set_param('page', $page1['next_page']);
    $page2 = WPCA_REST::get_catalog($request);
    $request->set_param('page', $page2['next_page']);
    $page3 = WPCA_REST::get_catalog($request);
    check('empty_permission_filtered_page_continues', $page1['items'] === array() && $page1['next_page'] === 2);
    check('later_accessible_post_is_discoverable', $page2['count'] === 1 && $page2['items'][0]['rid'] === 22);
    check('query_exhaustion_terminates', $page3['items'] === array() && $page3['next_page'] === null);
    check('stable_tie_break_order', $GLOBALS['fixture_query']['orderby'] === array('modified' => 'DESC', 'ID' => 'DESC'));
    check('catalog_does_not_label_cid_http_validator', !isset($page2['items'][0]['representations'][1]['validator']));
    $response = serve($request, new WP_REST_Response($page3));
    check('catalog_digest_matches', identity_matches($response));
    $request->set_param('per_page', 100);
    $request->set_param('page', 1);
    $page = WPCA_REST::get_catalog($request);
    check('short_query_page_terminates', $page['count'] === 1 && $page['next_page'] === null);

    $old = WPCA_CID::compute(WPCA_MR::build(22));
    $GLOBALS['fixture_author'] = 'Renamed author';
    $new = WPCA_CID::compute(WPCA_MR::build(22));
    $page = WPCA_REST::get_catalog(request('/catalog', array('since' => '2026-10-15T10:00:01Z')));
    check('dependency_change_is_not_a_post_change_feed', $old !== $new && $page['items'] === array());

    WPCA_Abilities::register_abilities();
    $ability_mr = WPCA_Abilities::get_post_context(array('post_id' => 22));
    check('ability_cid_not_mislabelled_http_etag', isset($ability_mr['cid']) && !isset($ability_mr['etag']));
    $ability_page = WPCA_Abilities::list_content_context(array('per_page' => 1, 'page' => 1));
    check('ability_empty_page_has_continuation', $ability_page['count'] === 0 && $ability_page['next_page'] === 2);
    $schema = $GLOBALS['fixture_abilities']['wp-context/list-content-context']['output_schema'];
    check('ability_schema_requires_continuation', in_array('next_page', $schema['required'], true) && $schema['properties']['next_page']['type'] === array('integer', 'null'));

    $request = request('/posts/22', array('_pretty' => '1'));
    $mr = WPCA_REST::get_post_mr($request);
    $response = core_serve($request, $mr);
    check('core_serving_pipeline_emits_one_json_document', $response['status'] === 200 && $response['body'] === (new WPCA_Test_Server())->core_json($mr->get_data(), $request));
    check('core_serving_pipeline_digest_matches', identity_matches($response));
    $request->set_header('If-None-Match', $response['headers']['ETag']);
    $response = core_serve($request, $mr);
    check('core_serving_pipeline_304_no_body', $response['status'] === 304 && $response['body'] === '' && !isset($response['headers']['Content-Digest']));
    $request = request('/posts/22/md');
    $response = core_serve($request, WPCA_REST::get_post_md($request));
    check('core_serving_pipeline_markdown_not_json_encoded', strpos($response['body'], '# Fixture') === 0 && identity_matches($response));
} catch (Throwable $error) {
    check('unexpected_exception', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
}

$failed = array_values(array_filter($checks, function($check) { return !$check['ok']; }));
echo json_encode(array('ok' => !$failed, 'checks' => count($checks), 'failed' => count($failed),
    'scope' => 'Real WordPress REST/parser classes with in-memory database/host doubles; no hosted requests.',
    'results' => $checks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($failed ? 1 : 0);
