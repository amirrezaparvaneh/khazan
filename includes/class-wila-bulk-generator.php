<?php
/**
 * تولید محتوای انبوه محصولات از ردیف‌های شیت: تطبیق با محصولات سایت و پردازش تک‌به‌تک
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_Bulk_Generator {

    const GENERATED_META = \Khazan\Services\Csv\ProductMatcher::GENERATED_META;

    public static function normalize($text) {
        return \Khazan\Services\Csv\TextNormalizer::normalize($text);
    }

    public static function normalize_slug($slug) {
        return \Khazan\Services\Csv\TextNormalizer::normalize_slug($slug);
    }

    public static function match_rows($rows, $file_name = '') {
        return \Khazan\Services\Csv\ProductMatcher::match_rows($rows, $file_name);
    }

    private static function get_generated_info($product_id) {
        return \Khazan\Services\Csv\ProductMatcher::generated_info($product_id);
    }

    private static function clean_row($row) {
        $fields = array('name', 'slug', 'description', 'notes', 'sole', 'upper', 'lining', 'general', 'seo_title', 'meta_description', 'focus_keyword', 'related_keywords');
        $clean = array();
        foreach ($fields as $field) {
            $clean[$field] = isset($row[$field]) ? sanitize_textarea_field(wp_unslash((string) $row[$field])) : '';
        }
        return $clean;
    }

    /**
     * پردازش یک محصول: ساخت زمینه و لینک‌ها، تولید محتوا با هوش مصنوعی و جاگذاری طبق حالت انتخابی
     */
    public static function process($product_id, $raw_row, $options) {
        $product_id = absint($product_id);
        $row = self::clean_row($raw_row);
        $mode = isset($options['mode']) && in_array($options['mode'], array('replace', 'append', 'draft'), true) ? $options['mode'] : 'draft';

        // ردیف یافت‌نشده: ابتدا محصول پیش‌نویس ساخته می‌شود و محتوا مستقیم روی همان نوشته می‌نشیند.
        $created = false;
        if (!$product_id) {
            if (empty($options['create_missing'])) return new WP_Error('missing_product', 'محصول مقصد مشخص نشده است.', array('status' => 400));
            // اگر تلاش قبلی بعد از ساخت پیش‌نویس شکست خورده باشد، همان نوشته دوباره استفاده می‌شود.
            $product_id = self::find_product_id_by_title($row['name']) ?: self::create_draft_product($row);
            if (is_wp_error($product_id)) return $product_id;
            $created = true;
            $mode = 'replace';
        }

        WILA_Content_Targets::remember_csv_focus($product_id, $row['focus_keyword']);
        $context = WILA_Content_Targets::get_context(WILA_Content_Targets::KIND_PRODUCT, $product_id);
        if (is_wp_error($context)) return $context;
        if (function_exists('set_time_limit')) @set_time_limit(240);

        $inject_seo = !empty($options['inject_seo']);
        $auto_links = !empty($options['auto_links']);

        $internal_links = array();
        if ($auto_links) {
            foreach (array_slice($context['parent_categories'], 0, 1) as $category) {
                $internal_links[] = array('anchor' => $category['name'], 'url' => $category['url'], 'required' => true, 'type' => 'category');
            }
            $context['related_products'] = WILA_Content_Targets::related_products($product_id, 2);
            foreach ($context['related_products'] as $related) {
                $internal_links[] = array('anchor' => $related['anchor'], 'url' => $related['url'], 'locked' => true, 'required' => true, 'type' => 'product');
            }
        } else {
            $context['parent_categories'] = array();
            $context['related_products'] = array();
        }

        $focus = $row['focus_keyword'] !== '' ? $row['focus_keyword'] : $context['title'];
        $params = array(
            'content_kind'      => WILA_Content_Targets::KIND_PRODUCT,
            'target_id'         => $product_id,
            'h1_title'          => $context['title'],
            'focus_keyword'     => $focus,
            'long_tail'         => $row['related_keywords'],
            'seo_synonyms'      => $row['related_keywords'],
            'word_count'        => 1000,
            'content_type'      => WILA_Content_Targets::kind_label(WILA_Content_Targets::KIND_PRODUCT),
            'funnel_stage'      => 'BOFU',
            'description'       => '',
            'internal_links'    => $internal_links,
            'use_orphan_links'  => false,
            'target'            => $context,
        );

        $result = (new WILA_AvalAI_Client())->generate_bulk_product_content($params, $row);
        if (is_wp_error($result)) return $result;

        // The curated sheet values win over model suggestions.
        $seo_title = $row['seo_title'] !== '' ? $row['seo_title'] : $result['seo_title'];
        $meta = $row['meta_description'] !== '' ? $row['meta_description'] : $result['meta_description'];
        $synonyms = $row['related_keywords'] !== '' ? $row['related_keywords'] : $result['keyword_synonyms'];
        $result['seo_title'] = $seo_title;
        $result['meta_description'] = $meta;
        $result['keyword_synonyms'] = $synonyms;
        WILA_DB::insert_content_history($params, $result);

        $applied = WILA_Content_Targets::apply(WILA_Content_Targets::KIND_PRODUCT, $product_id, array(
            'content'          => $result['content'],
            'mode'             => $mode,
            'seo_title'        => $inject_seo ? $seo_title : '',
            'meta_description' => $inject_seo ? $meta : '',
            'focus_keyword'    => $inject_seo ? $focus : '',
            'keyword_synonyms' => $inject_seo ? $synonyms : '',
        ));
        if (is_wp_error($applied)) return $applied;

        $generated = array('at' => current_time('mysql'), 'model' => WILA_AvalAI_Client::MODEL, 'mode' => $mode);
        update_post_meta($product_id, self::GENERATED_META, wp_json_encode($generated));

        return array(
            'product_id'          => $product_id,
            'mode'                => $mode,
            'created'             => $created,
            'title'               => $context['title'],
            'url'                 => $context['url'],
            'edit_url'            => $applied['edit_url'],
            'internal_link_count' => $result['internal_link_count'],
            'seo_title'           => $seo_title,
            'generated'           => $generated,
        );
    }

    private static function find_product_id_by_title($name) {
        global $wpdb;
        if ($name === '') return 0;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s AND post_status IN ('draft', 'pending', 'private', 'publish') ORDER BY ID DESC LIMIT 1",
            WILA_Content_Targets::product_post_type(),
            $name
        ));
    }

    /**
     * ساخت محصول پیش‌نویس از ردیف شیت (نام و نامک)؛ وردپرس اگر نامک تکراری باشد پسوند عددی می‌دهد
     */
    private static function create_draft_product($row) {
        if ($row['name'] === '') return new WP_Error('missing_product_name', 'برای ساخت محصول جدید، نام محصول در ردیف الزامی است.', array('status' => 400));
        $type = get_post_type_object(WILA_Content_Targets::product_post_type());
        if (!$type || !current_user_can($type->cap->create_posts)) return new WP_Error('cannot_create_product', 'اجازه ساخت محصول جدید را ندارید.', array('status' => 403));

        $post_id = wp_insert_post(array(
            'post_type'   => WILA_Content_Targets::product_post_type(),
            'post_status' => 'draft',
            'post_title'  => $row['name'],
            'post_name'   => $row['slug'] !== '' ? sanitize_title(self::normalize_slug($row['slug'])) : '',
        ), true);
        return $post_id;
    }
}
