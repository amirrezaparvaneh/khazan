<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * تولید و مدیریت محتوا: پیشنهاد لینک، طرح، محتوای کامل، رفع خطای سئو، اعمال روی مقصد، پیش‌نویس و آرشیو
 */
final class ContentController extends BaseController {

    public function generate_ai_suggestions($request) {
        $params = $request->get_json_params();
        $avalai = new \WILA_AvalAI_Client();
        
        $item_id = isset($params['id']) ? absint($params['id']) : 0;
        $item_type = isset($params['type']) ? sanitize_text_field($params['type']) : '';
        
        $suggestions = $avalai->generate_link_suggestions($item_id, $item_type);
        if (is_wp_error($suggestions)) return $suggestions;
        
        foreach ($suggestions as &$s) {
            if (isset($s['source_type'])) {
                $s['type_label'] = $this->get_type_label($s['source_type']);
            } else {
                $s['type_label'] = 'نامشخص';
            }
        }
        
        return rest_ensure_response(array('success' => true, 'suggestions' => $suggestions));
    }

    public function generate_content_blueprint($request) {
        try {
            $params = $request->get_json_params();
            $avalai = new \WILA_AvalAI_Client();
            
            $clean_params = array(
                'h1_title'         => isset($params['h1_title']) ? sanitize_text_field($params['h1_title']) : '',
                'focus_keyword'    => isset($params['focus_keyword']) ? sanitize_text_field($params['focus_keyword']) : '',
                'long_tail'        => isset($params['long_tail']) ? sanitize_text_field($params['long_tail']) : '',
                'content_type'     => isset($params['content_type']) ? sanitize_text_field($params['content_type']) : '',
                'funnel_stage'     => isset($params['funnel_stage']) ? sanitize_text_field($params['funnel_stage']) : '',
                'word_count'       => isset($params['word_count']) ? absint($params['word_count']) : 1000,
            );

            $blueprint = $avalai->generate_content_blueprint($clean_params);
            if (is_wp_error($blueprint)) {
                return $blueprint;
            }

            return rest_ensure_response(array('success' => true, 'blueprint' => $blueprint));
        } catch (\Exception $e) {
            return new \WP_Error('rest_blueprint_error', $e->getMessage());
        }
    }

    private function content_generator_params($request) {
        $params = $request->get_json_params();
        $content_kind = \WILA_Content_Targets::normalize_kind(isset($params['content_kind']) ? $params['content_kind'] : '');
        $target = null;
        if (\WILA_Content_Targets::is_target_kind($content_kind)) {
            $target = \WILA_Content_Targets::get_context($content_kind, isset($params['target_id']) ? $params['target_id'] : 0);
            if (is_wp_error($target)) return $target;
        }
        $internal_links = isset($params['internal_links']) && is_array($params['internal_links']) ? array_map(function ($link) {
            return array('anchor' => isset($link['anchor']) ? sanitize_text_field($link['anchor']) : '', 'url' => isset($link['url']) ? esc_url_raw($link['url']) : '');
        }, $params['internal_links']) : array();
        $use_orphan_links = !empty($params['use_orphan_links']);

        if ($use_orphan_links && empty($internal_links)) {
            $scanner = new \WILA_Scanner();
            $orphans = \Khazan\Services\Links\OrphanFinder::calculate($scanner->get_all_scannable_items());
            $priorities = array('product_cat' => 1, 'product' => 2, 'post' => 3, 'page' => 4);
            usort($orphans['list'], function ($a, $b) use ($priorities) {
                $a_priority = isset($priorities[$a['type']]) ? $priorities[$a['type']] : 3;
                $b_priority = isset($priorities[$b['type']]) ? $priorities[$b['type']] : 3;
                return $a_priority === $b_priority ? strcasecmp($a['title'], $b['title']) : $a_priority - $b_priority;
            });
            foreach ($orphans['list'] as $orphan) {
                if ($target && (int) $orphan['id'] === (int) $target['id'] && $orphan['type'] === $content_kind) continue;
                if (!empty($orphan['url']) && !empty($orphan['title'])) {
                    // محصول با کلیدواژه کانونی: انکر قفل‌شده؛ بدون آن، نام کامل محصول بدون دستکاری
                    $focus_anchor = $orphan['type'] === \WILA_Content_Targets::product_post_type() ? \WILA_Content_Targets::product_link_anchor($orphan['id']) : '';
                    $anchor = $focus_anchor !== '' ? $focus_anchor : $orphan['title'];
                    $internal_links[] = array('anchor' => $anchor, 'url' => esc_url_raw($orphan['url']), 'orphan' => true, 'locked' => $focus_anchor !== '');
                }
            }
        }

        if ($target) {
            $internal_links = array_values(array_filter($internal_links, function ($link) use ($target) {
                return untrailingslashit($link['url']) !== untrailingslashit($target['url']);
            }));
        }

        $result = array(
            'content_kind' => $content_kind,
            'target_id' => $target ? $target['id'] : 0,
            'h1_title' => $target ? $target['title'] : (isset($params['h1_title']) ? sanitize_text_field($params['h1_title']) : ''),
            'focus_keyword' => isset($params['focus_keyword']) ? sanitize_text_field($params['focus_keyword']) : '',
            'long_tail' => isset($params['long_tail']) ? sanitize_text_field($params['long_tail']) : '',
            'word_count' => isset($params['word_count']) ? max(300, min(10000, absint($params['word_count']))) : 1200,
            'content_type' => \WILA_Content_Targets::kind_label($content_kind),
            'funnel_stage' => isset($params['funnel_stage']) ? sanitize_text_field($params['funnel_stage']) : '',
            'description' => isset($params['description']) ? sanitize_textarea_field($params['description']) : '',
            'internal_links' => $internal_links,
            'use_orphan_links' => $use_orphan_links,
        );
        // The homepage link is a blog-article feature only.
        if ($target) {
            $result['target'] = $target;
        } else {
            $result['homepage_keywords'] = get_option('wila_homepage_keywords', '');
            $result['homepage_url'] = home_url('/');
        }
        return $result;
    }

    public function get_content_targets($request) {
        $kind = \WILA_Content_Targets::normalize_kind($request->get_param('kind'));
        $items = \WILA_Content_Targets::list_targets($kind, sanitize_text_field((string) $request->get_param('search')));
        return is_wp_error($items) ? $items : rest_ensure_response(array('success' => true, 'items' => $items));
    }

    public function get_current_content($request) {
        $kind = \WILA_Content_Targets::normalize_kind($request->get_param('content_kind'));
        $result = \WILA_Content_Targets::current_content($kind, $request->get_param('target_id'));
        return is_wp_error($result) ? $result : rest_ensure_response(array_merge(array('success' => true), $result));
    }

    public function fix_seo_errors($request) {
        $params = $request->get_json_params();
        $kind = \WILA_Content_Targets::normalize_kind(isset($params['content_kind']) ? $params['content_kind'] : '');
        $errors = isset($params['errors']) ? sanitize_textarea_field(mb_substr((string) $params['errors'], 0, 3000)) : '';
        if (trim($errors) === '') return new \WP_Error('missing_seo_errors', 'متن خطاهای سئو را وارد کنید.', array('status' => 400));
        $current = \WILA_Content_Targets::current_content($kind, isset($params['target_id']) ? $params['target_id'] : 0);
        if (is_wp_error($current)) return $current;
        if (trim(wp_strip_all_tags($current['content'])) === '') return new \WP_Error('empty_content', 'این صفحه محتوایی ندارد؛ ابتدا محتوا تولید کنید.', array('status' => 400));
        $result = (new \WILA_AvalAI_Client())->generate_seo_fix($kind, $current, $errors);
        return is_wp_error($result) ? $result : rest_ensure_response(array_merge(array('success' => true), $result));
    }

    public function apply_content_to_target($request) {
        $params = $request->get_json_params();
        $kind = \WILA_Content_Targets::normalize_kind(isset($params['content_kind']) ? $params['content_kind'] : '');
        $result = \WILA_Content_Targets::apply($kind, isset($params['target_id']) ? $params['target_id'] : 0, is_array($params) ? $params : array());
        return is_wp_error($result) ? $result : rest_ensure_response(array_merge(array('success' => true), $result));
    }

    public function generate_content_outline($request) {
        $params = $this->content_generator_params($request);
        if (is_wp_error($params)) return $params;
        if (empty($params['h1_title']) || empty($params['focus_keyword'])) return new \WP_Error('missing_content_details', 'عنوان و کلیدواژه کانونی الزامی هستند.', array('status' => 400));
        $result = (new \WILA_AvalAI_Client())->generate_content_outline($params);
        return is_wp_error($result) ? $result : rest_ensure_response(array('success' => true, 'outline' => $result));
    }

    public function generate_full_content($request) {
        $params = $this->content_generator_params($request);
        if (is_wp_error($params)) return $params;
        $body = $request->get_json_params();
        $outline = isset($body['outline']) && is_array($body['outline']) ? $body['outline'] : array();
        if (empty($params['h1_title']) || empty($params['focus_keyword']) || empty($outline)) return new \WP_Error('missing_content_details', 'مشخصات و طرح تاییدشده محتوا الزامی هستند.', array('status' => 400));
        $result = (new \WILA_AvalAI_Client())->generate_full_content($params, $outline);
        if (is_wp_error($result)) return $result;

        \WILA_DB::insert_content_history($params, $result);

        return rest_ensure_response(array('success' => true, 'content_kind' => $params['content_kind'], 'content' => $result['content'], 'featured_image_prompt' => $result['featured_image_prompt'], 'slug' => $result['slug'], 'seo_title' => $result['seo_title'], 'meta_description' => $result['meta_description'], 'keyword_synonyms' => $result['keyword_synonyms'], 'internal_link_count' => $result['internal_link_count'], 'external_link_count' => $result['external_link_count'], 'external_links' => $result['external_links']));
    }

    public function get_content_history() {
        global $wpdb;
        $table = \WILA_DB::get_content_history_table_name();
        $items = $wpdb->get_results("SELECT id, title, focus_keyword, slug, content_kind, target_id, internal_link_count, external_link_count, seo_title, meta_description, keyword_synonyms, created_at FROM {$table} ORDER BY created_at DESC, id DESC LIMIT 100", ARRAY_A);
        return rest_ensure_response(array('success' => true, 'items' => $items));
    }

    public function get_content_history_item($request) {
        global $wpdb;
        $table = \WILA_DB::get_content_history_table_name();
        $item = $wpdb->get_row($wpdb->prepare("SELECT id, title, focus_keyword, slug, content_kind, target_id, content, featured_image_prompt, internal_link_count, external_link_count, external_links, seo_title, meta_description, keyword_synonyms, created_at FROM {$table} WHERE id = %d", absint($request['id'])), ARRAY_A);
        if (!$item) return new \WP_Error('content_not_found', 'محتوای درخواستی یافت نشد.', array('status' => 404));
        return rest_ensure_response(array('success' => true, 'item' => $item));
    }

    public function delete_content_history_item($request) {
        global $wpdb;
        $deleted = $wpdb->delete(\WILA_DB::get_content_history_table_name(), array('id' => absint($request['id'])), array('%d'));
        if (!$deleted) return new \WP_Error('content_not_found', 'محتوای درخواستی یافت نشد.', array('status' => 404));
        return rest_ensure_response(array('success' => true));
    }

    public function create_content_draft($request) {
        $params = $request->get_json_params();
        $title = isset($params['title']) ? sanitize_text_field($params['title']) : '';
        $content = isset($params['content']) ? wp_kses_post($params['content']) : '';
        $slug = isset($params['slug']) ? strtolower(trim(sanitize_text_field($params['slug']))) : '';
        if (!preg_match('/^[a-z]+(?:-[a-z0-9]+)*$/D', $slug)) {
            return new \WP_Error('invalid_english_slug', 'نامک انگلیسی مرتبط را با حروف لاتین و خط تیره وارد کنید.', array('status' => 400));
        }
        if (empty($title) || empty($content)) return new \WP_Error('missing_draft_content', 'عنوان و محتوای پیش‌نویس الزامی هستند.', array('status' => 400));
        $blog_post_type = apply_filters('wila_blog_post_type', 'blog');
        if (!post_type_exists($blog_post_type)) {
            return new \WP_Error('blog_post_type_not_found', 'نوع محتوای «وبلاگ» یافت نشد. با فیلتر wila_blog_post_type نام post type وبلاگ را تنظیم کنید.', array('status' => 400));
        }
        if (!current_user_can(get_post_type_object($blog_post_type)->cap->create_posts)) {
            return new \WP_Error('cannot_create_blog_draft', 'اجازه ایجاد پیش‌نویس در بخش وبلاگ را ندارید.', array('status' => 403));
        }
        $post_id = wp_insert_post(array('post_title' => $title, 'post_content' => $content, 'post_status' => 'draft', 'post_type' => $blog_post_type, 'post_name' => $slug), true);
        if (is_wp_error($post_id)) return $post_id;

        $focus_keyword = isset($params['focus_keyword']) ? sanitize_text_field($params['focus_keyword']) : '';
        $synonyms = isset($params['keyword_synonyms']) ? sanitize_text_field($params['keyword_synonyms']) : '';
        $seo_title = isset($params['seo_title']) ? sanitize_text_field($params['seo_title']) : $title;
        $meta_description = isset($params['meta_description']) ? sanitize_textarea_field($params['meta_description']) : '';
        \WILA_Content_Targets::write_post_seo($post_id, array(
            'title'    => $seo_title,
            'desc'     => $meta_description,
            'focuskw'  => $focus_keyword,
            'synonyms' => $synonyms,
        ));

        return rest_ensure_response(array('success' => true, 'post_id' => $post_id, 'slug' => get_post_field('post_name', $post_id), 'edit_url' => get_edit_post_link($post_id, 'raw')));
    }
}
