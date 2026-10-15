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
                $out[] = array(
                    'type' => 'core/list',
                    'ordered' => isset($attrs['ordered']) ? (bool) $attrs['ordered'] : (strpos($html, '<ol') !== false),
                    'items' => self::list_items($block),
                );
                // List children are consumed here, not flattened and rendered a second time.
                return apply_filters('wpca_map_block', $out, $block);
            case 'core/image':
                $image = array();
                $caption_html = '';
                $in_caption = false;
                foreach (wp_html_split($html) as $token) {
                    if (!$image && preg_match('/^<img\b/i', $token)) {
                        $image = self::tag_attributes($token);
                    } elseif (preg_match('/^<figcaption(?:\s|>)/i', $token)) {
                        $in_caption = true;
                    } elseif (preg_match('#^</figcaption\s*>#i', $token)) {
                        $in_caption = false;
                    } elseif ($in_caption) {
                        $caption_html .= $token;
                    }
                }
                $out[] = array(
                    'type' => 'core/image',
                    'imageId' => isset($attrs['id']) ? (int) $attrs['id'] : 0,
                    'altText' => array_key_exists('alt', $image) ? $image['alt'] : (string) ($attrs['alt'] ?? ''),
                    'url' => !empty($image['src']) ? $image['src'] : null,
                    'caption' => html_entity_decode(wp_strip_all_tags($caption_html, true), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
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

        if (stripos($html, '<a') !== false && count($out) === 1 &&
            in_array($name, array('core/paragraph', 'core/heading', 'core/quote'), true)) {
            $inline = self::inline_links($html);
            if ($inline['link_targets']) {
                $out[0]['link_targets'] = $inline['link_targets'];
                $out[0]['markdown'] = $inline['markdown'];
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

    private static function tag_attributes(string $tag): array {
        if (!preg_match('/^<[a-z][a-z0-9:-]*\b(.*?)\/?\s*>$/si', $tag, $match)) {
            return array();
        }
        $attributes = array();
        foreach (wp_kses_hair($match[1], wp_allowed_protocols()) as $name => $attribute) {
            $attributes[$name] = html_entity_decode($attribute['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attributes;
    }

    private static function inline_links(string $html): array {
        $markdown = '';
        $targets = array();
        $anchor = null;
        // Match the existing text projection's omission of script/style contents.
        $html = preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $html);
        foreach (wp_html_split($html) as $token) {
            if ($anchor === null && preg_match('/^<a(?:\s|>)/i', $token)) {
                $attributes = self::tag_attributes($token);
                $anchor = array('url' => $attributes['href'] ?? '', 'html' => '');
            } elseif ($anchor !== null && preg_match('#^</a\s*>#i', $token)) {
                $label = html_entity_decode(wp_strip_all_tags($anchor['html'], true), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($anchor['url'] !== '') {
                    $targets[] = array('text' => $label, 'url' => $anchor['url']);
                    $escaped = str_replace(array('\\', '[', ']', '<', '>'), array('\\\\', '\\[', '\\]', '&lt;', '&gt;'), $label);
                    $url = str_replace(array(' ', '<', '>', "\r", "\n"), array('%20', '%3C', '%3E', '%0D', '%0A'), $anchor['url']);
                    $markdown .= '[' . $escaped . '](<' . $url . '>)';
                } else {
                    $markdown .= str_replace(array('<', '>'), array('&lt;', '&gt;'), $label);
                }
                $anchor = null;
            } elseif ($anchor !== null) {
                $anchor['html'] .= $token;
            } else {
                $plain = html_entity_decode(strip_tags($token), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $markdown .= str_replace(array('<', '>'), array('&lt;', '&gt;'), $plain);
            }
        }
        if ($anchor !== null) {
            $plain = html_entity_decode(wp_strip_all_tags($anchor['html'], true), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $markdown .= str_replace(array('<', '>'), array('&lt;', '&gt;'), $plain);
        }
        return array('link_targets' => $targets, 'markdown' => trim($markdown));
    }

    private static function list_items(array $block): array {
        $items = array();
        $children = isset($block['innerBlocks']) ? $block['innerBlocks'] : array();
        foreach ($children as $child) {
            if (($child['blockName'] ?? '') !== 'core/list-item') {
                continue;
            }
            $text = trim(wp_strip_all_tags($child['innerHTML'] ?? '', true));
            foreach ($child['innerBlocks'] ?? array() as $nested) {
                if (($nested['blockName'] ?? '') !== 'core/list') {
                    continue;
                }
                $ordered = !empty($nested['attrs']['ordered']) || strpos($nested['innerHTML'] ?? '', '<ol') !== false;
                foreach (self::list_items($nested) as $index => $item) {
                    $prefix = $ordered ? (($index + 1) . '. ') : '- ';
                    $text .= "\n" . $prefix . str_replace("\n", "\n" . str_repeat(' ', strlen($prefix)), $item);
                }
            }
            $items[] = $text;
        }

        // Older List blocks store their items in innerHTML rather than child blocks.
        if (!$children && preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $block['innerHTML'] ?? '', $matches)) {
            foreach ($matches[1] as $item) {
                $items[] = trim(wp_strip_all_tags($item, true));
            }
        }
        return $items;
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
            if (isset($block['caption']) && is_string($block['caption']) && $block['caption'] !== '') {
                $parts[] = $block['caption'];
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

