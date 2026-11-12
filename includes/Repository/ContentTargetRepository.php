<?php
/**
 * Data access for content targets (products and product categories): listing,
 * AI context, current content, applying generated content and review drafts.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Repository;

use Khazan\Services\Links\SmartLinkDistributor;
use Khazan\Services\Links\TransientAssignmentLedger;
use Khazan\Services\Links\WordPressLinkCandidateProvider;
use Khazan\Services\Seo\KeyphraseExtractor;
use Khazan\Services\Seo\SeoMetaService;
use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentTargetRepository {

    /** @var SeoMetaService */
    private $seo;

    public function __construct(SeoMetaService $seo = null) {
        $this->seo = $seo ?: new SeoMetaService();
    }

    /**
     * فهرست محصولات یا دسته‌بندی‌ها برای انتخاب در رابط کاربری
     */
    public function list_targets($kind, $search = '') {
        if (!Settings::is_target_kind($kind) || !Settings::kind_available($kind)) {
            return new \WP_Error('target_kind_unavailable', 'ووکامرس یا نوع محتوای درخواستی در این سایت فعال نیست.', array('status' => 400));
        }

        $items = array();
        if ($kind === Settings::KIND_PRODUCT) {
            $args = array(
                'post_type'      => Settings::product_post_type(),
                'post_status'    => array('publish', 'draft', 'pending', 'private'),
                'posts_per_page' => 300,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
            );
            if ($search !== '') $args['s'] = $search;
            foreach (get_posts($args) as $post_id) {
                $items[] = array('id' => (int) $post_id, 'title' => get_the_title($post_id), 'url' => get_permalink($post_id), 'status' => get_post_status($post_id));
            }
            return $items;
        }

        $terms = get_terms(array('taxonomy' => Settings::category_taxonomy(), 'hide_empty' => false, 'number' => 300, 'search' => $search, 'orderby' => 'name'));
        if (is_wp_error($terms)) return $terms;
        foreach ($terms as $term) {
            $link = get_term_link($term);
            $items[] = array('id' => (int) $term->term_id, 'title' => $this->term_path_label($term), 'url' => is_wp_error($link) ? '' : $link, 'status' => 'publish');
        }
        return $items;
    }

    private function term_path_label($term) {
        $names = array($term->name);
        $parent = (int) $term->parent;
        $guard = 0;
        while ($parent && $guard++ < 5) {
            $parent_term = get_term($parent, $term->taxonomy);
            if (!$parent_term || is_wp_error($parent_term)) break;
            array_unshift($names, $parent_term->name);
            $parent = (int) $parent_term->parent;
        }
        return implode(' › ', $names);
    }

    /**
     * داده‌های واقعی مقصد که به هوش مصنوعی داده می‌شود تا چیزی ساخته نشود
     */
    public function get_context($kind, $id, $include_related = true) {
        $id = absint($id);
        if (!$id || !Settings::is_target_kind($kind) || !Settings::kind_available($kind)) {
            return new \WP_Error('invalid_target', 'صفحه مقصد انتخاب‌شده معتبر نیست.', array('status' => 400));
        }
        return $kind === Settings::KIND_PRODUCT ? $this->product_context($id, $include_related) : $this->category_context($id);
    }

    private function plain($html, $words) {
        return wp_strip_all_tags(wp_trim_words(strip_shortcodes((string) $html), $words, '…'));
    }

    private function product_context($id, $include_related = true) {
        $post = get_post($id);
        if (!$post || $post->post_type !== Settings::product_post_type()) {
            return new \WP_Error('invalid_target', 'محصول یافت نشد.', array('status' => 404));
        }
        $context = array(
            'kind'              => Settings::KIND_PRODUCT,
            'id'                => $id,
            'title'             => get_the_title($id),
            'url'               => get_permalink($id),
            'short_description' => $this->plain($post->post_excerpt, 80),
            'current_content'   => $this->plain($post->post_content, 120),
            'parent_categories' => $this->product_parent_categories($id),
            'related_products'  => $include_related ? $this->related_products($id) : array(),
            // ویژگی‌های دستی ثبت‌شده در «مدیریت سریع ویژگی‌های محصولات»؛ تزریق اجباری به پرامپت در PromptBuilder
            'custom_specs'      => (new ProductSpecsRepository())->get($id),
            'inferred_specs'    => (new ProductSpecsRepository())->get_inferred($id),
        );
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($id);
            if ($product) {
                if ($product->get_sku()) $context['sku'] = $product->get_sku();
                if ($product->get_price() !== '') $context['price'] = wp_strip_all_tags(html_entity_decode(wc_price($product->get_price())));
                $attributes = array();
                foreach ($product->get_attributes() as $attribute) {
                    if (!is_object($attribute) || !method_exists($attribute, 'get_name')) continue;
                    $name  = $attribute->get_name();
                    $value = $product->get_attribute($name);
                    if ($value !== '') $attributes[wc_attribute_label($name)] = $value;
                }
                if ($attributes) $context['attributes'] = $attributes;
            }
        }
        return $context;
    }

    private function product_parent_categories($id) {
        $terms = get_the_terms($id, Settings::category_taxonomy());
        if (!is_array($terms)) return array();
        $parent_ids = array_map('intval', wp_list_pluck($terms, 'parent'));
        $categories = array();
        foreach ($terms as $term) {
            if (in_array((int) $term->term_id, $parent_ids, true) && count($terms) > 1) continue;
            $link = get_term_link($term);
            if (!is_wp_error($link)) $categories[(int) $term->term_id] = array('name' => $term->name, 'url' => $link);
        }
        // دسته‌بندی اصلی (Yoast / Rank Math) همیشه اولین مورد است تا لینک دسته به دسته خودِ محصول اشاره کند.
        $primary = (new SiteEntityRepository())->primary_category_id($id, array_map('intval', wp_list_pluck($terms, 'term_id')));
        if ($primary) {
            foreach ($terms as $term) {
                if ((int) $term->term_id === $primary) {
                    $link = get_term_link($term);
                    if (!is_wp_error($link)) {
                        unset($categories[$primary]);
                        $categories = array($primary => array('name' => $term->name, 'url' => $link)) + $categories;
                    }
                }
            }
        }
        return array_slice(array_values($categories), 0, 3);
    }

    /**
     * Related products for a standalone content generation: same-category products only, least-linked first
     * (Khazan\Services\Links\SmartLinkDistributor; counts come from the link index).
     */
    public function related_products($id, $limit = 5) {
        return $this->link_distributor()->related((int) $id, (int) $limit);
    }

    /**
     * Distributor wired to WordPress data and a transient ledger (shared across the requests of one bulk run).
     */
    public function link_distributor() {
        return new SmartLinkDistributor(new WordPressLinkCandidateProvider(null, null, $this), new TransientAssignmentLedger());
    }

    /**
     * Published products of a category (and its sub-categories) for the link planner: {id, title, url, model}.
     * The model name is the exact product title - category pages link 3-4 products by their real names.
     */
    public function category_products($term_id, $limit = 24) {
        $ids = get_posts(array(
            'post_type'      => Settings::product_post_type(),
            'post_status'    => 'publish',
            'posts_per_page' => max(1, (int) $limit),
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => array(array('taxonomy' => Settings::category_taxonomy(), 'field' => 'term_id', 'terms' => (int) $term_id, 'include_children' => true)),
        ));
        if ($ids && function_exists('_prime_post_caches')) _prime_post_caches($ids, false, false);
        $out = array();
        foreach ($ids as $id) {
            $title = trim(html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'));
            $url = get_permalink($id);
            if ($title !== '' && $url) $out[] = array('id' => (int) $id, 'title' => $title, 'url' => $url, 'model' => $title);
        }
        return $out;
    }

    private function category_context($id) {
        $term = get_term($id, Settings::category_taxonomy());
        if (!$term || is_wp_error($term)) {
            return new \WP_Error('invalid_target', 'دسته‌بندی یافت نشد.', array('status' => 404));
        }
        $link = get_term_link($term);
        $children = get_terms(array('taxonomy' => Settings::category_taxonomy(), 'parent' => $id, 'hide_empty' => false, 'number' => 20));
        $products = get_posts(array(
            'post_type'      => Settings::product_post_type(),
            'post_status'    => 'publish',
            'posts_per_page' => 12,
            'fields'         => 'ids',
            'tax_query'      => array(array('taxonomy' => Settings::category_taxonomy(), 'field' => 'term_id', 'terms' => $id)),
        ));
        $context = array(
            'kind'            => Settings::KIND_CATEGORY,
            'id'              => $id,
            'title'           => $term->name,
            'url'             => is_wp_error($link) ? '' : $link,
            'current_content' => $this->plain($term->description, 120),
            'product_count'   => (int) $term->count,
            'sample_products' => array_map('get_the_title', $products),
            'subcategories'   => is_wp_error($children) ? array() : array_values(array_filter(array_map(function ($child) {
                $child_link = get_term_link($child);
                return is_wp_error($child_link) ? null : array('name' => $child->name, 'url' => $child_link);
            }, $children))),
        );
        if ($term->parent) {
            $parent = get_term($term->parent, Settings::category_taxonomy());
            $parent_link = $parent && !is_wp_error($parent) ? get_term_link($parent) : null;
            if ($parent_link && !is_wp_error($parent_link)) $context['parent_category'] = array('name' => $parent->name, 'url' => $parent_link);
        }
        return $context;
    }

    /**
     * محتوای فعلی صفحه مقصد به‌همراه متاهای سئوی آن؛ ورودی رفع خطای سئو و نمایش در پنل
     */
    public function current_content($kind, $id) {
        $id = absint($id);
        if (!$id || !Settings::is_target_kind($kind) || !Settings::kind_available($kind)) {
            return new \WP_Error('invalid_target', 'صفحه مقصد انتخاب‌شده معتبر نیست.', array('status' => 400));
        }
        return $kind === Settings::KIND_PRODUCT ? $this->current_product($id) : $this->current_category($id);
    }

    private function current_product($id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== Settings::product_post_type()) {
            return new \WP_Error('invalid_target', 'محصول یافت نشد.', array('status' => 404));
        }
        $seo = $this->seo->read_post_seo($id);
        return array(
            'title'   => get_the_title($id),
            'url'     => get_permalink($id),
            'content' => $post->post_content,
            'seo'     => $seo,
        );
    }

    private function current_category($id) {
        $term = get_term($id, Settings::category_taxonomy());
        if (!$term || is_wp_error($term)) {
            return new \WP_Error('invalid_target', 'دسته‌بندی یافت نشد.', array('status' => 404));
        }
        $link = get_term_link($term);
        return array(
            'title'   => $term->name,
            'url'     => is_wp_error($link) ? '' : $link,
            'content' => $term->description,
            'seo'     => $this->seo->read_term_seo(Settings::category_taxonomy(), $id),
        );
    }

    /**
     * جاگذاری محتوای تولیدشده در صفحه مقصد؛ نسخه قبلی برای بازگردانی نگه داشته می‌شود
     */
    public function apply($kind, $id, $data) {
        $id = absint($id);
        $content = isset($data['content']) ? \Khazan\Services\Content\ContentGuard::wrap(wp_kses_post($data['content'])) : '';
        if (!$id || !Settings::is_target_kind($kind) || !Settings::kind_available($kind) || trim($content) === '') {
            return new \WP_Error('missing_apply_content', 'مقصد و محتوای انتشار الزامی هستند.', array('status' => 400));
        }

        $seo = array(
            'title'    => isset($data['seo_title']) ? sanitize_text_field($data['seo_title']) : '',
            'desc'     => isset($data['meta_description']) ? sanitize_textarea_field($data['meta_description']) : '',
            'focuskw'  => isset($data['focus_keyword']) ? sanitize_text_field($data['focus_keyword']) : '',
            'synonyms' => isset($data['keyword_synonyms']) ? sanitize_text_field($data['keyword_synonyms']) : '',
        );

        $mode = isset($data['mode']) ? sanitize_key($data['mode']) : 'replace';
        if ($kind === Settings::KIND_PRODUCT) return $this->apply_to_product($id, $content, $seo, $mode);
        return $this->apply_to_category($id, $content, $seo);
    }

    /**
     * Focus keyphrase of a product: the stored / title-derived phrase, extended with the distinguishing words of the
     * title when other products would get the very same phrase (Yoast: "keyphrase previously used").
     */
    public function product_focus($id, $stored = '', $title = null) {
        $id = (int) $id;
        // a review draft speaks for its source product
        $source = $id ? (int) get_post_meta($id, Settings::META_SOURCE_PRODUCT, true) : 0;
        $own = $source ?: $id;
        if ($title === null || $source) $title = $own ? (string) get_the_title($own) : (string) $title;
        $category = $own ? $this->primary_category_name($own) : '';
        $stored = trim(preg_replace('/\s+/u', ' ', (string) $stored));
        $derived = KeyphraseExtractor::from_title($title, $category);
        // an already extended phrase (derived phrase + extra title words) is first reduced to its base
        $base = KeyphraseExtractor::optimal(KeyphraseExtractor::count_words($stored) > Settings::KEYPHRASE_MAX_WORDS ? '' : $stored, $title, $category);
        if ($base === '') $base = $derived;
        $title_keys = array_map(array(KeyphraseExtractor::class, 'key'), KeyphraseExtractor::words($title));
        $in_title = array_filter(KeyphraseExtractor::words($base), function ($w) use ($title_keys) { return in_array(KeyphraseExtractor::key($w), $title_keys, true); });
        return KeyphraseExtractor::disambiguate($base, $title, $this->sibling_titles($own, implode(' ', $in_title), $source ? $id : 0));
    }

    /** Titles of the other products whose title carries every word of $focus (review drafts excluded). */
    private function sibling_titles($id, $focus, $also_exclude = 0) {
        global $wpdb;
        $words = KeyphraseExtractor::words($focus);
        if (!$words || !isset($wpdb)) return array();
        $where = ''; $args = array(Settings::product_post_type(), (int) $id, (int) $also_exclude);
        foreach ($words as $w) { $where .= ' AND post_title LIKE %s'; $args[] = '%' . $wpdb->esc_like($w) . '%'; }
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT post_title FROM {$wpdb->posts} WHERE post_type = %s AND ID NOT IN (%d, %d) AND post_status IN ('draft', 'pending', 'private', 'publish', 'future'){$where} AND post_title NOT LIKE %s LIMIT 50",
            array_merge($args, array($wpdb->esc_like('[بازبینی]') . '%'))
        ));
        return is_array($rows) ? $rows : array();
    }

    /**
     * ذخیره متای سئو محصول در یوست و رنک‌مث (فقط مقادیر غیرخالی)
     */
    public function write_post_seo($id, $seo) {
        if (get_post_type($id) === Settings::product_post_type() && !empty($seo['title'])) {
            $focus = $this->product_focus($id, isset($seo['focuskw']) ? $seo['focuskw'] : '');
            $seo['focuskw'] = $focus;
            $seo['focuskw_exact'] = KeyphraseExtractor::count_words($focus) > Settings::KEYPHRASE_MAX_WORDS;
            $seo['title'] = \Khazan\Services\Seo\SeoMetaRules::fit_title($seo['title'], $focus !== '' ? $focus : get_the_title($id), \Khazan\Core\Settings::brand_name());
        }
        elseif (get_post_type($id) === Settings::blog_post_type() && !empty($seo['title']) && !empty($seo['focuskw'])) {
            // مقاله: همان رشته‌ای که به‌عنوان کلیدواژه کانونی ذخیره می‌شود، عیناً ابتدای عنوان سئو می‌نشیند
            $seo['focuskw'] = trim($seo['focuskw']);
            $seo['title'] = \Khazan\Services\Seo\SeoMetaRules::fit_title($seo['title'], $seo['focuskw'] !== '' ? $seo['focuskw'] : get_the_title($id), \Khazan\Core\Settings::brand_name());
        }
        $this->seo->write_post_seo($id, $seo);
    }

    /**
     * ذخیره محتوا به‌صورت پیش‌نویس بازبینی (نسخه جدید از همان نوع محتوا)؛ صفحه زنده دست‌نخورده می‌ماند
     */
    public function create_review_draft($id, $content, $seo) {
        $post = get_post($id);
        if (!$post || $post->post_type !== Settings::product_post_type()) {
            return new \WP_Error('invalid_target', 'محصول یافت نشد.', array('status' => 404));
        }
        $type = get_post_type_object($post->post_type);
        if (!$type || !current_user_can($type->cap->create_posts)) {
            return new \WP_Error('cannot_create_draft', 'اجازه ایجاد پیش‌نویس را ندارید.', array('status' => 403));
        }
        $draft_id = wp_insert_post(array('post_type' => $post->post_type, 'post_status' => 'draft', 'post_title' => '[بازبینی] ' . $post->post_title, 'post_content' => $content), true);
        if (is_wp_error($draft_id)) return $draft_id;
        update_post_meta($draft_id, Settings::META_SOURCE_PRODUCT, $id);
        $this->write_post_seo($draft_id, $seo);
        return array('target_id' => $draft_id, 'edit_url' => get_edit_post_link($draft_id, 'raw'), 'view_url' => '');
    }

    private function apply_to_product($id, $content, $seo, $mode = 'replace') {
        $post = get_post($id);
        if (!$post || $post->post_type !== Settings::product_post_type()) {
            return new \WP_Error('invalid_target', 'محصول یافت نشد.', array('status' => 404));
        }
        if (!current_user_can('edit_post', $id)) {
            return new \WP_Error('cannot_edit_target', 'اجازه ویرایش این محصول را ندارید.', array('status' => 403));
        }
        if ($mode === 'draft') return $this->create_review_draft($id, $content, $seo);
        update_post_meta($id, Settings::META_PREVIOUS_CONTENT, $post->post_content);
        $new_content = $mode === 'append'
            ? \Khazan\Services\Content\ContentGuard::wrap(\Khazan\Services\Content\ContentGuard::unwrap($post->post_content) . "\n\n" . \Khazan\Services\Content\ContentGuard::unwrap($content))
            : $content;
        $result = wp_update_post(array('ID' => $id, 'post_content' => $new_content), true);
        if (is_wp_error($result)) return $result;

        $this->write_post_seo($id, $seo);
        return array('target_id' => $id, 'edit_url' => get_edit_post_link($id, 'raw'), 'view_url' => get_permalink($id));
    }

    private function apply_to_category($id, $content, $seo) {
        $term = get_term($id, Settings::category_taxonomy());
        if (!$term || is_wp_error($term)) {
            return new \WP_Error('invalid_target', 'دسته‌بندی یافت نشد.', array('status' => 404));
        }
        if (!current_user_can('edit_term', $id)) {
            return new \WP_Error('cannot_edit_target', 'اجازه ویرایش این دسته‌بندی را ندارید.', array('status' => 403));
        }
        update_term_meta($id, Settings::META_PREVIOUS_DESCRIPTION, $term->description);
        $result = wp_update_term($id, Settings::category_taxonomy(), array('description' => $content));
        if (is_wp_error($result)) return $result;

        $this->seo->write_term_seo(Settings::category_taxonomy(), $id, $seo);
        $link = get_term_link($term);
        return array('target_id' => $id, 'edit_url' => get_edit_term_link($id, Settings::category_taxonomy(), Settings::product_post_type()), 'view_url' => is_wp_error($link) ? '' : $link);
    }

    public function find_product_id_by_title($name) {
        global $wpdb;
        if ($name === '') return 0;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s AND post_status IN ('draft', 'pending', 'private', 'publish') ORDER BY ID DESC LIMIT 1",
            Settings::product_post_type(),
            $name
        ));
    }

    /**
     * ساخت محصول پیش‌نویس از ردیف شیت (نام و نامک)؛ وردپرس اگر نامک تکراری باشد پسوند عددی می‌دهد
     */
    public function create_draft_product($row) {
        if ($row['name'] === '') return new \WP_Error('missing_product_name', 'برای ساخت محصول جدید، نام محصول در ردیف الزامی است.', array('status' => 400));
        $type = get_post_type_object(Settings::product_post_type());
        if (!$type || !current_user_can($type->cap->create_posts)) return new \WP_Error('cannot_create_product', 'اجازه ساخت محصول جدید را ندارید.', array('status' => 403));

        $post_id = wp_insert_post(array(
            'post_type'   => Settings::product_post_type(),
            'post_status' => 'draft',
            'post_title'  => $row['name'],
            'post_name'   => $row['slug'] !== '' ? sanitize_title(\Khazan\Services\Csv\TextNormalizer::normalize_slug($row['slug'])) : '',
        ), true);
        return $post_id;
    }

    /**
     * انکرتکست لینک به یک محصول، حداکثر ۴ کلمه.
     * ترتیب منبع: RankMath، Yoast، سپس ستون کلیدواژه کانونی شیت CSV؛ اگر عبارت ذخیره‌شده ≤۴ کلمه باشد همان استفاده می‌شود،
     * وگرنه ۲ تا ۴ کلمه از عنوان محصول (دسته‌بندی اصلی + مدل/ویژگی) استخراج می‌شود.
     */
    public function product_link_anchor($id) {
        $stored = '';
        $rank = SeoMetaService::split_keywords((string) get_post_meta($id, 'rank_math_focus_keyword', true));
        if ($rank) {
            $stored = $rank[0];
        } else {
            foreach (array('_yoast_wpseo_focuskw', Settings::META_CSV_FOCUS) as $meta_key) {
                $value = trim(preg_replace('/\s+/u', ' ', (string) get_post_meta($id, $meta_key, true)));
                if ($value !== '') { $stored = $value; break; }
            }
        }
        return KeyphraseExtractor::optimal($stored, (string) get_the_title($id), $this->primary_category_name($id));
    }

    /**
     * انکر لینک به یک مقاله: کلیدواژه کانونی دقیق ذخیره‌شده (Rank Math / Yoast)، وگرنه عبارت کوتاه‌شده عنوان؛ حداکثر ۴ کلمه
     */
    public function article_link_anchor($id) {
        $stored = '';
        $rank = SeoMetaService::split_keywords((string) get_post_meta($id, 'rank_math_focus_keyword', true));
        if ($rank) {
            $stored = $rank[0];
        } else {
            $stored = trim(preg_replace('/\s+/u', ' ', (string) get_post_meta($id, '_yoast_wpseo_focuskw', true)));
        }
        return KeyphraseExtractor::limit($stored !== '' ? $stored : KeyphraseExtractor::from_title((string) get_the_title($id)));
    }

    /**
     * مترادف‌ها و عبارات فرعی ذخیره‌شده محصول (Yoast / RankMath) به‌صورت فهرست؛ برای جلوگیری از خودرقابتی انکرها
     */
    public function product_synonyms($id) {
        $seo = $this->seo->read_post_seo($id);
        return SeoMetaService::split_keywords($seo['synonyms'], $seo['focuskw']);
    }

    /**
     * نام دستهٔ اصلی محصول (Yoast / Rank Math) یا اولین دستهٔ اختصاص‌یافته؛ خالی اگر دسته‌ای نباشد
     */
    public function primary_category_name($id) {
        $repo = new SiteEntityRepository();
        $categories = $repo->product_categories($id);
        if (!$categories) return '';
        $primary = $repo->primary_category_id($id, array_keys($categories));
        $entry = $primary && isset($categories[$primary]) ? $categories[$primary] : reset($categories);
        return isset($entry['name']) ? (string) $entry['name'] : '';
    }

    /**
     * کلیدواژه کانونی ستون شیت را روی محصول ثبت می‌کند تا محصولات دیگر پیش از تولید محتوای آن هم بتوانند با انکر درست به آن لینک دهند
     */
    public function remember_csv_focus($id, $focus) {
        $focus = trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string) $focus)));
        if (!$id || $focus === '' || get_post_type($id) !== \Khazan\Core\Settings::product_post_type()) return;
        update_post_meta($id, \Khazan\Core\Settings::META_CSV_FOCUS, $focus);
    }

    /**
     * شناسه محصولِ یک لینک داخلی؛ برای اصلاح انکرتکست پس از تولید محتوا
     */
    public function product_id_from_url($url) {
        $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host !== '' && $host !== $site_host) return 0;
        $id = url_to_postid($url);
        return $id && get_post_type($id) === \Khazan\Core\Settings::product_post_type() ? (int) $id : 0;
    }

    /**
     * Published products for the bulk queue (no CSV): list + content statistics.
     *
     * The counters describe the same scope as the list ignoring the content filter (category + search), so the
     * three stat cards always add up: total_count = empty_count + has_content_count.
     *
     * @param array $args filter (all|empty|has_content; legacy: no_description, ungenerated), category (term id), search, limit
     * @return array{items:array,has_more:bool,counts:array{total_count:int,empty_count:int,has_content_count:int}}
     */
    public function list_site_products(array $args) {
        $filter = isset($args['filter']) ? $args['filter'] : 'all';
        if ($filter === 'no_description') $filter = 'empty';
        $limit  = isset($args['limit']) ? max(1, (int) $args['limit']) : 300;
        $query = array(
            'post_type'      => Settings::product_post_type(),
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        );
        if (!empty($args['search'])) $query['s'] = $args['search'];
        if (!empty($args['category'])) {
            $query['tax_query'] = array(array('taxonomy' => Settings::category_taxonomy(), 'field' => 'term_id', 'terms' => array((int) $args['category'])));
        }

        $items = array();
        $has_more = false;
        $counts = array('total_count' => 0, 'empty_count' => 0, 'has_content_count' => 0);
        for ($page = 1; $page <= 100; $page++) {
            $posts = get_posts($query + array('paged' => $page));
            foreach ($posts as $post) {
                $has_description = trim(wp_strip_all_tags((string) $post->post_content)) !== '';
                $counts['total_count']++;
                $counts[$has_description ? 'has_content_count' : 'empty_count']++;
                if (!$this->matches_site_filter($filter, $post, $has_description)) continue;
                if (count($items) >= $limit) { $has_more = true; continue; }
                $items[] = $this->site_product_row($post, $has_description);
            }
            if (count($posts) < 200) break;
        }
        return array('items' => $items, 'has_more' => $has_more, 'counts' => $counts);
    }

    private function matches_site_filter($filter, $post, $has_description) {
        switch ($filter) {
            case 'empty':
                return !$has_description;
            case 'has_content':
                return $has_description;
            case 'ungenerated':
                return \Khazan\Services\Csv\ProductMatcher::generated_info($post->ID) === null;
            default:
                return true;
        }
    }

    private function site_product_row($post, $has_description) {
        $categories = array();
        foreach ((new SiteEntityRepository())->product_categories($post->ID) as $category) {
            $categories[] = array('id' => $category['id'], 'name' => $category['name']);
        }
        return array(
            'id'              => (int) $post->ID,
            'title'           => get_the_title($post),
            'slug'            => $post->post_name,
            'url'             => get_permalink($post),
            'status'          => $post->post_status,
            'categories'      => $categories,
            'has_description' => $has_description,
            'generated'       => \Khazan\Services\Csv\ProductMatcher::generated_info($post->ID),
            'seo'             => $this->seo->read_post_seo($post->ID),
        );
    }

    /**
     * Categories for the site-product filter: id, hierarchical label and product count.
     *
     * @return array<int,array{id:int,name:string,count:int}>
     */
    public function product_filter_categories() {
        $terms = get_terms(array('taxonomy' => Settings::category_taxonomy(), 'hide_empty' => false, 'orderby' => 'name'));
        $out = array();
        if (is_wp_error($terms) || !is_array($terms)) return $out;
        foreach ($terms as $term) {
            $out[] = array('id' => (int) $term->term_id, 'name' => $this->term_path_label($term), 'count' => (int) $term->count);
        }
        return $out;
    }

    /**
     * Creates a blog draft (title, content, English slug) and writes its SEO meta.
     *
     * @return int|\WP_Error Post ID on success.
     */
    public function create_blog_draft($title, $content, $slug, array $seo, $featured_image_id = 0) {
        $blog_post_type = apply_filters(Settings::FILTER_BLOG_POST_TYPE, 'blog');
        if (!post_type_exists($blog_post_type)) {
            return new \WP_Error('blog_post_type_not_found', 'نوع محتوای «وبلاگ» یافت نشد. با فیلتر wila_blog_post_type نام post type وبلاگ را تنظیم کنید.', array('status' => 400));
        }
        if (!current_user_can(get_post_type_object($blog_post_type)->cap->create_posts)) {
            return new \WP_Error('cannot_create_blog_draft', 'اجازه ایجاد پیش‌نویس در بخش وبلاگ را ندارید.', array('status' => 403));
        }
        $post_id = wp_insert_post(array('post_title' => $title, 'post_content' => $content, 'post_status' => 'draft', 'post_type' => $blog_post_type, 'post_name' => $slug), true);
        if (is_wp_error($post_id)) return $post_id;

        $this->write_post_seo($post_id, $seo);
        (new MediaRepository())->adopt($post_id, $featured_image_id, $content);
        return $post_id;
    }
}
