<?php
/**
 * Processes one CSV sheet row end to end: validate -> resolve product -> build context and
 * prompt parameters -> generate (AI) -> merge sheet values -> save content, SEO meta and log.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Csv;

use Khazan\Repository\ContentTargetRepository;
use Khazan\Repository\DatabaseRepository;
use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class BulkRowProcessor {

    const MODES = array('replace', 'append', 'draft');

    /** @var ContentTargetRepository */
    private $targets;

    /** @var DatabaseRepository */
    private $database;

    /** @var object|null Client exposing generate_bulk_product_content($params, $row). */
    private $ai;

    public function __construct(ContentTargetRepository $targets = null, DatabaseRepository $database = null, $ai = null) {
        $this->targets  = $targets ?: new ContentTargetRepository();
        $this->database = $database ?: new DatabaseRepository();
        $this->ai       = $ai;
    }

    /**
     * پردازش یک محصول: ساخت زمینه و لینک‌ها، تولید محتوا با هوش مصنوعی و جاگذاری طبق حالت انتخابی
     *
     * @return array|\WP_Error
     */
    public function process($product_id, $raw_row, $options) {
        $product_id = absint($product_id);
        $row  = $this->validate_row($raw_row);
        $mode = $this->resolve_mode($options);

        $resolved = $this->resolve_product($product_id, $row, $options, $mode);
        if (is_wp_error($resolved)) return $resolved;
        list($product_id, $created, $mode) = $resolved;

        (new \Khazan\Repository\ContentTargetRepository())->remember_csv_focus($product_id, $row['focus_keyword']);
        $context = $this->targets->get_context(Settings::KIND_PRODUCT, $product_id);
        if (is_wp_error($context)) return $context;
        if (function_exists('set_time_limit')) @set_time_limit(240);

        $inject_seo = !empty($options['inject_seo']);
        list($internal_links, $context) = $this->build_links(!empty($options['auto_links']), $product_id, $context);
        $params = $this->build_params($product_id, $row, $context, $internal_links);

        $result = $this->ai()->generate_bulk_product_content($params, $row);
        if (is_wp_error($result)) return $result;

        $result  = $this->merge_sheet_values($row, $result);
        $applied = $this->save($params, $result, $product_id, $mode, $inject_seo);
        if (is_wp_error($applied)) return $applied;

        $generated = $this->mark_generated($product_id, $mode);
        return $this->build_response($product_id, $mode, $created, $context, $applied, $result, $generated);
    }

    // ---- 1. validation / normalization of the sheet row ----

    private function validate_row($row) {
        $fields = array('name', 'slug', 'description', 'notes', 'sole', 'upper', 'lining', 'general', 'seo_title', 'meta_description', 'focus_keyword', 'related_keywords');
        $clean = array();
        foreach ($fields as $field) {
            $clean[$field] = isset($row[$field]) ? sanitize_textarea_field(wp_unslash((string) $row[$field])) : '';
        }
        return $clean;
    }

    private function resolve_mode($options) {
        return isset($options['mode']) && in_array($options['mode'], self::MODES, true) ? $options['mode'] : 'draft';
    }

    /**
     * ردیف یافت‌نشده: ابتدا محصول پیش‌نویس ساخته می‌شود و محتوا مستقیم روی همان نوشته می‌نشیند.
     *
     * @return array|\WP_Error [product_id, created, mode]
     */
    private function resolve_product($product_id, array $row, $options, $mode) {
        $created = false;
        if (!$product_id) {
            if (empty($options['create_missing'])) return new \WP_Error('missing_product', 'محصول مقصد مشخص نشده است.', array('status' => 400));
            // اگر تلاش قبلی بعد از ساخت پیش‌نویس شکست خورده باشد، همان نوشته دوباره استفاده می‌شود.
            $product_id = $this->targets->find_product_id_by_title($row['name']) ?: $this->targets->create_draft_product($row);
            if (is_wp_error($product_id)) return $product_id;
            $created = true;
            $mode = 'replace';
        }
        return array($product_id, $created, $mode);
    }

    // ---- 2. context, links and prompt parameters (consumed by PromptBuilder) ----

    private function build_links($auto_links, $product_id, array $context) {
        $internal_links = array();
        if ($auto_links) {
            foreach (array_slice($context['parent_categories'], 0, 1) as $category) {
                $internal_links[] = array('anchor' => $category['name'], 'url' => $category['url'], 'required' => true, 'type' => 'category');
            }
            $context['related_products'] = $this->targets->related_products($product_id, 2);
            foreach ($context['related_products'] as $related) {
                $internal_links[] = array('anchor' => $related['anchor'], 'url' => $related['url'], 'locked' => true, 'required' => true, 'type' => 'product');
            }
        } else {
            $context['parent_categories'] = array();
            $context['related_products'] = array();
        }
        return array($internal_links, $context);
    }

    private function build_params($product_id, array $row, array $context, array $internal_links) {
        $focus = $row['focus_keyword'] !== '' ? $row['focus_keyword'] : $context['title'];
        return array(
            'content_kind'      => Settings::KIND_PRODUCT,
            'target_id'         => $product_id,
            'h1_title'          => $context['title'],
            'focus_keyword'     => $focus,
            'long_tail'         => $row['related_keywords'],
            'seo_synonyms'      => $row['related_keywords'],
            'word_count'        => 1000,
            'content_type'      => Settings::kind_label(Settings::KIND_PRODUCT),
            'funnel_stage'      => 'BOFU',
            'description'       => '',
            'internal_links'    => $internal_links,
            'use_orphan_links'  => false,
            'target'            => $context,
        );
    }

    // ---- 3. generation: prompt (PromptBuilder) and parsing (ContentParser) happen inside the AI client ----

    private function ai() {
        if ($this->ai === null) {
            $this->ai = new \WILA_AvalAI_Client();
        }
        return $this->ai;
    }

    /**
     * The curated sheet values win over model suggestions.
     */
    private function merge_sheet_values(array $row, array $result) {
        $result['seo_title']        = $row['seo_title'] !== '' ? $row['seo_title'] : $result['seo_title'];
        $result['meta_description'] = $row['meta_description'] !== '' ? $row['meta_description'] : $result['meta_description'];
        $result['keyword_synonyms'] = $row['related_keywords'] !== '' ? $row['related_keywords'] : $result['keyword_synonyms'];
        return $result;
    }

    // ---- 4. persistence: archive log, content and SEO meta ----

    private function save(array $params, array $result, $product_id, $mode, $inject_seo) {
        $this->database->insert_content_history($params, $result);

        return $this->targets->apply(Settings::KIND_PRODUCT, $product_id, array(
            'content'          => $result['content'],
            'mode'             => $mode,
            'seo_title'        => $inject_seo ? $result['seo_title'] : '',
            'meta_description' => $inject_seo ? $result['meta_description'] : '',
            'focus_keyword'    => $inject_seo ? $params['focus_keyword'] : '',
            'keyword_synonyms' => $inject_seo ? $result['keyword_synonyms'] : '',
        ));
    }

    private function mark_generated($product_id, $mode) {
        $generated = array('at' => current_time('mysql'), 'model' => \WILA_AvalAI_Client::MODEL, 'mode' => $mode);
        update_post_meta($product_id, ProductMatcher::GENERATED_META, wp_json_encode($generated));
        return $generated;
    }

    private function build_response($product_id, $mode, $created, array $context, array $applied, array $result, array $generated) {
        return array(
            'product_id'          => $product_id,
            'mode'                => $mode,
            'created'             => $created,
            'title'               => $context['title'],
            'url'                 => $context['url'],
            'edit_url'            => $applied['edit_url'],
            'internal_link_count' => $result['internal_link_count'],
            'seo_title'           => $result['seo_title'],
            'generated'           => $generated,
        );
    }
}
