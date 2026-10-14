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

    /**
     * یکسان‌سازی متن برای مقایسه: ی/ک عربی، نیم‌فاصله، ارقام، ایموجی و فاصله‌های اضافه
     */
    public static function normalize($text) {
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8');
        $text = strtr($text, array(
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه', 'ـ' => '',
            "\u{200c}" => ' ', "\u{200f}" => '', "\u{200e}" => '',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ));
        $text = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return mb_strtolower(trim($text));
    }

    private static function normalize_slug($slug) {
        $slug = trim((string) $slug);
        if ($slug === '') return '';
        if (preg_match('~^https?://~i', $slug)) {
            $slug = (string) wp_parse_url($slug, PHP_URL_PATH);
        }
        $parts = array_filter(explode('/', trim($slug, '/')), 'strlen');
        $slug = $parts ? end($parts) : '';
        return mb_strtolower(trim(rawurldecode($slug)));
    }

    /**
     * ایندکس محصولات سایت برای تطبیق: عنوان نرمال‌شده و نامک
     */
    private static function build_index() {
        $by_title = array();
        $by_slug = array();
        $posts = get_posts(array(
            'post_type'      => WILA_Content_Targets::product_post_type(),
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ));
        foreach ($posts as $post) {
            $entry = array('id' => (int) $post->ID, 'title' => get_the_title($post), 'url' => get_permalink($post), 'status' => $post->post_status);
            $key = self::normalize($post->post_title);
            $by_title[$key][] = $entry;
            $by_slug[mb_strtolower(rawurldecode($post->post_name))] = $entry;
        }
        return array($by_title, $by_slug);
    }

    /**
     * تطبیق دومرحله‌ای: ۱) نام محصول ۲) نامک
     */
    public static function match_rows($rows) {
        if (!post_type_exists(WILA_Content_Targets::product_post_type())) {
            return new WP_Error('product_type_unavailable', 'نوع محتوای محصول در این سایت یافت نشد. آن را در تنظیمات مشخص کنید.', array('status' => 400));
        }
        list($by_title, $by_slug) = self::build_index();
        $matches = array();
        foreach ($rows as $row) {
            $name = self::normalize(isset($row['name']) ? $row['name'] : '');
            $slug = self::normalize_slug(isset($row['slug']) ? $row['slug'] : '');
            $found = null;
            $via = '';
            if ($name !== '' && isset($by_title[$name])) {
                $found = $by_title[$name][0];
                $via = 'name';
                if (count($by_title[$name]) > 1) $via = 'name_ambiguous';
            } elseif ($slug !== '' && isset($by_slug[$slug])) {
                $found = $by_slug[$slug];
                $via = 'slug';
            }
            $matches[] = $found ? array('matched' => true, 'via' => $via, 'product_id' => $found['id'], 'title' => $found['title'], 'url' => $found['url'], 'status' => $found['status'], 'generated' => self::get_generated_info($found['id'])) : array('matched' => false);
        }
        return $matches;
    }

    const GENERATED_META = '_wila_csv_generated_at';

    /**
     * سابقه تولید از طریق CSV برای یک محصول (یا null)
     */
    private static function get_generated_info($product_id) {
        $info = json_decode((string) get_post_meta($product_id, self::GENERATED_META, true), true);
        return is_array($info) && !empty($info['at']) ? $info : null;
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
        $context = WILA_Content_Targets::get_context(WILA_Content_Targets::KIND_PRODUCT, $product_id);
        if (is_wp_error($context)) return $context;
        if (function_exists('set_time_limit')) @set_time_limit(240);

        $row = self::clean_row($raw_row);
        $mode = isset($options['mode']) && in_array($options['mode'], array('replace', 'append', 'draft'), true) ? $options['mode'] : 'draft';
        $inject_seo = !empty($options['inject_seo']);
        $auto_links = !empty($options['auto_links']);

        $internal_links = array();
        if ($auto_links) {
            foreach (array_slice($context['parent_categories'], 0, 1) as $category) {
                $internal_links[] = array('anchor' => $category['name'], 'url' => $category['url']);
            }
            $context['related_products'] = WILA_Content_Targets::related_products($product_id, 2);
            foreach ($context['related_products'] as $related) {
                $internal_links[] = array('anchor' => $related['anchor'], 'url' => $related['url']);
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
            'edit_url'            => $applied['edit_url'],
            'internal_link_count' => $result['internal_link_count'],
            'seo_title'           => $seo_title,
            'generated'           => $generated,
        );
    }
}
