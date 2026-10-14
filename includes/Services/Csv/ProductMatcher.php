<?php
/**
 * Matches parsed sheet rows to site products (name first, then slug) and
 * attaches generation / upload history.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Csv;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductMatcher {

    const GENERATED_META = '_wila_csv_generated_at';

    /**
     * تطبیق دومرحله‌ای: ۱) نام محصول ۲) نامک
     *
     * @return array|\WP_Error
     */
    public static function match_rows($rows, $file_name = '') {
        if (!post_type_exists(\WILA_Content_Targets::product_post_type())) {
            return new \WP_Error('product_type_unavailable', 'نوع محتوای محصول در این سایت یافت نشد. آن را در تنظیمات مشخص کنید.', array('status' => 400));
        }
        list($by_title, $by_slug) = self::build_index();
        $matches      = array();
        $history_keys = array();
        foreach ($rows as $row) {
            $name = TextNormalizer::normalize(isset($row['name']) ? $row['name'] : '');
            $slug = TextNormalizer::normalize_slug(isset($row['slug']) ? $row['slug'] : '');
            $history_keys[] = array('name_key' => $name, 'slug_key' => $slug);
            $found = null;
            $via   = '';
            if ($name !== '' && isset($by_title[$name])) {
                $found = $by_title[$name][0];
                $via   = count($by_title[$name]) > 1 ? 'name_ambiguous' : 'name';
            } elseif ($slug !== '' && isset($by_slug[$slug])) {
                $found = $by_slug[$slug];
                $via   = 'slug';
            }
            // پیش‌ثبت کلیدواژه کانونی شیت تا لینک‌های محصولات دیگر به این محصول از ابتدا انکر درست داشته باشند.
            if ($found && !empty($row['focus_keyword'])) {
                \WILA_Content_Targets::remember_csv_focus($found['id'], $row['focus_keyword']);
            }
            $matches[] = $found
                ? array('matched' => true, 'via' => $via, 'product_id' => $found['id'], 'title' => $found['title'], 'url' => $found['url'], 'status' => $found['status'], 'generated' => self::generated_info($found['id']))
                : array('matched' => false);
        }
        // سابقه آپلود قبلی برای هر ردیف؛ ردیف‌های تازه همین حالا ثبت می‌شوند
        $history = (new \Khazan\Repository\BulkHistoryRepository())->sync($history_keys, $file_name);
        foreach ($matches as $i => $match) {
            $matches[$i]['history'] = isset($history[$i]) ? $history[$i] : null;
        }
        return $matches;
    }

    /**
     * سابقه تولید از طریق CSV برای یک محصول (یا null)
     */
    public static function generated_info($product_id) {
        $info = json_decode((string) get_post_meta($product_id, self::GENERATED_META, true), true);
        return is_array($info) && !empty($info['at']) ? $info : null;
    }

    /**
     * ایندکس محصولات سایت برای تطبیق: عنوان نرمال‌شده و نامک
     */
    private static function build_index() {
        $by_title = array();
        $by_slug  = array();
        $posts = get_posts(array(
            'post_type'      => \WILA_Content_Targets::product_post_type(),
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ));
        foreach ($posts as $post) {
            $entry = array('id' => (int) $post->ID, 'title' => get_the_title($post), 'url' => get_permalink($post), 'status' => $post->post_status);
            $by_title[TextNormalizer::normalize($post->post_title)][] = $entry;
            $by_slug[mb_strtolower(rawurldecode($post->post_name))]   = $entry;
        }
        return array($by_title, $by_slug);
    }
}
