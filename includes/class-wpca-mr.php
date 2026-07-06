<?php
if (!defined('ABSPATH')) { exit; }

class WPCA_MR {
    public static function build(int $post_id): ?array {
        $post = get_post($post_id);
        if (!$post) {
            return null;
        }

        $blocks = self::extract_blocks($post);
        $core_text = self::flatten_text($blocks);
        $thumb_id = get_post_thumbnail_id($post);
        $featured_image = null;

        if ($thumb_id) {
            $img = wp_get_attachment_image_src($thumb_id, 'full');
            $alt = get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
            if (is_array($img) && !empty($img[0])) {
                $featured_image = array(
                    'id' => (int) $thumb_id,
                    'url' => $img[0],
                    'alt' => (string) $alt,
                    'width' => isset($img[1]) ? (int) $img[1] : null,
                    'height' => isset($img[2]) ? (int) $img[2] : null,
                );
            }
        }

        $mr = array(
            'rid' => (int) $post_id,
            'title' => get_the_title($post),
            'status' => $post->post_status,
            'modified' => self::normalize_gmt_datetime($post->post_modified_gmt ?? ''),
            'published' => self::normalize_gmt_datetime($post->post_date_gmt ?? ''),
            'author' => array(
                'id' => (int) $post->post_author,
                'name' => get_the_author_meta('display_name', $post->post_author),
                'url' => get_author_posts_url($post->post_author),
            ),
            'image' => $featured_image,
            'categories' => self::categories($post_id),
            'tags' => self::tags($post_id),
            'word_count' => self::word_count($core_text),
            'core_content_text' => $core_text,
            'blocks' => $blocks,
            'links' => array(
                'human_url' => get_permalink($post_id),
                'api_url' => rest_url('wp-context/v1/posts/' . $post_id),
                'md_url' => rest_url('wp-context/v1/posts/' . $post_id . '/md'),
            ),
        );

        return apply_filters('wpca_mr', $mr, $post_id);
    }

    public static function normalize_gmt_datetime($datetime): ?string {
        $datetime = trim((string) $datetime);
        if ($datetime === '' || $datetime === '0000-00-00 00:00:00' || strpos($datetime, '0000-') === 0) {
            return null;
        }

        $timestamp = strtotime($datetime);
        if ($timestamp === false || (int) gmdate('Y', $timestamp) < 1000) {
            return null;
        }

        return gmdate('c', $timestamp);
    }

    private static function categories(int $post_id): array {
        $categories = array();
        $cats = get_the_category($post_id);
        if (is_array($cats)) {
            foreach ($cats as $cat) {
                if ($cat instanceof WP_Term) {
                    $categories[] = array(
                        'id' => (int) $cat->term_id,
                        'name' => $cat->name,
                        'slug' => $cat->slug,
                        'url' => get_category_link($cat->term_id),
                    );
                }
            }
        }

        usort($categories, function($a, $b) {
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });

        return $categories;
    }

    private static function tags(int $post_id): array {
        $tags = array();
        $terms = get_the_tags($post_id);
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    $tags[] = array(
                        'id' => (int) $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'url' => get_tag_link($term->term_id),
                    );
                }
            }
        }

        usort($tags, function($a, $b) {
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });

        return $tags;
    }

    private static function extract_blocks(WP_Post $post): array {
        if (!function_exists('parse_blocks')) {
            return array();
        }

        $blocks = array();
        $parsed = parse_blocks($post->post_content ?? '');
        foreach ((array) $parsed as $block) {
            if (is_array($block)) {
                $blocks = array_merge($blocks, self::map_block($block));
            }
        }

        return apply_filters('wpca_blocks', $blocks, $post->ID, $post);
    }

    private static function map_block(array $block): array {
        $name = isset($block['blockName']) ? (string) $block['blockName'] : '';
        $html = isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        $text = trim(wp_strip_all_tags($html, true));
        $out = array();

        switch ($name) {
            case 'core/paragraph':
                $out[] = array('type' => 'core/paragraph', 'content' => $text);
                break;
            case 'core/heading':
                $level = isset($attrs['level']) ? (int) $attrs['level'] : 2;
                $out[] = array('type' => 'core/heading', 'level' => max(1, min(6, $level)), 'content' => $text);
                break;
            case 'core/list':
                $items = array();
                if (preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $html, $matches)) {
                    foreach ($matches[1] as $item) {
                        $item = trim(wp_strip_all_tags($item, true));
                        if ($item !== '') {
                            $items[] = $item;
                        }
                    }
                }
                $out[] = array(
                    'type' => 'core/list',
                    'ordered' => isset($attrs['ordered']) ? (bool) $attrs['ordered'] : (strpos($html, '<ol') !== false),
                    'items' => $items,
                );
                break;
            case 'core/image':
                $src = '';
                if (preg_match('/src="([^"]+)"/i', $html, $matches)) {
                    $src = $matches[1];
                }
                $out[] = array(
                    'type' => 'core/image',
                    'imageId' => isset($attrs['id']) ? (int) $attrs['id'] : 0,
                    'altText' => isset($attrs['alt']) ? (string) $attrs['alt'] : '',
                    'url' => $src ?: null,
                );
                break;
            case 'core/code':
            case 'core/preformatted':
                $out[] = array('type' => 'core/code', 'content' => $text);
                break;
            case 'core/quote':
                $out[] = array('type' => 'core/quote', 'content' => $text);
                break;
            default:
                if ($text !== '') {
                    $out[] = array('type' => $name ?: 'unknown', 'content' => $text);
                }
        }

        if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $child) {
                if (is_array($child)) {
                    $out = array_merge($out, self::map_block($child));
                }
            }
        }

        return apply_filters('wpca_map_block', $out, $block);
    }

    private static function flatten_text(array $blocks): string {
        $parts = array();
        foreach ($blocks as $block) {
            if (!empty($block['content']) && is_string($block['content'])) {
                $parts[] = $block['content'];
            }
            if (!empty($block['items']) && is_array($block['items'])) {
                $parts[] = implode(' ', array_map('strval', $block['items']));
            }
        }

        $text = trim(implode(' ', $parts));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    private static function word_count(string $text): int {
        if ($text === '') {
            return 0;
        }
        if (preg_match_all('/\p{L}+/u', $text, $matches)) {
            return (int) count($matches[0]);
        }
        return 0;
    }
}

