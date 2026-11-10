<?php
/**
 * AI alt text for media (vision model through the same OpenAI-compatible provider as the content engine).
 *
 * Rules: an existing alt text is never overwritten unless the setting allows it; the prompt carries the real context
 * (the product / post the image belongs to) so the alt names the product instead of describing a generic photo; the
 * answer is cleaned (no «تصویر/عکس» lead-in, no trailing punctuation, length-capped); the count shown in the UI and the
 * batch use ONE query (missing_query), so progress always finishes.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Modules\Images;

use Khazan\Core\Settings;
use Khazan\Services\Ai\AvalaiHttpClient;

if (!defined('ABSPATH')) {
    exit;
}

final class AltText {

    const META_DONE = '_khazan_alt_at';
    const ALT_MAX_CHARS = 125;

    public static function register() {
        add_action('add_attachment', array(__CLASS__, 'schedule_on_upload'));
        add_action('khazan_generate_alt', array(__CLASS__, 'generate_and_save'), 10, 1);
    }

    /** Never blocks the upload: the work is queued as a single cron event. */
    public static function schedule_on_upload($attachment_id) {
        if (!ImageSettings::get('alt_on_upload') || strpos((string) get_post_mime_type($attachment_id), 'image/') !== 0) return;
        if (!wp_next_scheduled('khazan_generate_alt', array((int) $attachment_id))) wp_schedule_single_event(time() + 30, 'khazan_generate_alt', array((int) $attachment_id));
    }

    /** Pure: cleans a model answer into an alt text. */
    public static function clean($text) {
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $text)));
        $text = preg_replace('/^["\'«»`\s]+|["\'«»`\s]+$/u', '', $text);
        if (preg_match('/^Alt\s*:\s*(.+)$/imu', $text, $m)) $text = trim($m[1]);
        $text = preg_replace('/^(یک\s+)?(تصویر|عکس|لوگوی|نمایی|تصویری)(\s+(از|برای))?\s+/u', '', $text);
        $text = preg_replace('/[\s.،؛:!؟?\-–—]+$/u', '', $text);
        if (mb_strlen($text) > self::ALT_MAX_CHARS) {
            $text = mb_substr($text, 0, self::ALT_MAX_CHARS);
            $space = mb_strrpos($text, ' ');
            if ($space !== false && $space > 60) $text = mb_substr($text, 0, $space);
            $text = preg_replace('/[\s.،؛:\-]+$/u', '', $text);
        }
        return $text;
    }

    /** Pure: the prompt, with the context the image is used in. */
    public static function prompt(array $context) {
        $lines = array(
            'برای این تصویر یک متن جایگزین (Alt Text) فارسی بنویس: توصیفی، دقیق و کوتاه (۳ تا ۱۲ کلمه).',
            'مستقیماً خود سوژه را توصیف کن؛ از کلمات «تصویر»، «عکس» و «نمایی از» استفاده نکن و در پایان نقطه نگذار.',
            'فقط چیزی را بنویس که در تصویر دیده می‌شود. رنگ، شکل و نوع کالا را از روی تصویر بگو؛ جنس، برند یا ویژگی نادیده را حدس نزن.',
        );
        if (!empty($context['title'])) $lines[] = 'این تصویر در صفحه یا محصول «' . $context['title'] . '» استفاده شده است؛ اگر با سوژه تصویر هم‌خوان است، نوع کالا یا نام آن را طبیعی در متن بیاور.';
        if (!empty($context['category'])) $lines[] = 'دسته‌بندی: ' . $context['category'] . '.';
        $lines[] = 'فقط خود متن جایگزین را بنویس، بدون توضیح اضافه.';
        return implode("\n", $lines);
    }

    /** Context of an attachment: parent post title and its first product category. */
    public static function context($attachment_id) {
        $parent = (int) wp_get_post_parent_id($attachment_id);
        $context = array('title' => '', 'category' => '');
        if ($parent) {
            $context['title'] = trim(get_the_title($parent));
            $terms = get_the_terms($parent, Settings::category_taxonomy());
            if ($terms && !is_wp_error($terms)) $context['category'] = $terms[0]->name;
        }
        return $context;
    }

    /** Attachments that need an alt text. The count and the batch use this one query. */
    public static function missing_query($limit) {
        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => array('image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'),
            'posts_per_page' => (int) $limit,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        );
        $args['meta_query'] = ImageSettings::get('alt_overwrite')
            ? array(array('key' => self::META_DONE, 'compare' => 'NOT EXISTS'))
            : array('relation' => 'OR', array('key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'), array('key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='));
        return new \WP_Query($args);
    }

    /** @return string|\WP_Error the saved alt text */
    public static function generate_and_save($attachment_id) {
        $attachment_id = (int) $attachment_id;
        $existing = trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
        if ($existing !== '' && !ImageSettings::get('alt_overwrite')) return new \WP_Error('has_alt', 'این تصویر از قبل متن جایگزین دارد.');

        $image = self::image_payload($attachment_id);
        if (is_wp_error($image)) return $image;
        $model = ImageSettings::get('alt_model');
        $client = new AvalaiHttpClient(Settings::avalai_api_key(), $model !== '' ? $model : Settings::ai_model());
        $messages = array(array('role' => 'user', 'content' => array(
            array('type' => 'text', 'text' => self::prompt(self::context($attachment_id))),
            array('type' => 'image_url', 'image_url' => array('url' => 'data:' . $image['mime'] . ';base64,' . $image['data'])),
        )));
        $answer = $client->chat($messages, true, false, 1000); // headroom for reasoning models
        if (is_wp_error($answer)) return $answer;
        $alt = self::clean($answer);
        if ($alt === '') return new \WP_Error('empty_alt', 'مدل متن جایگزین قابل استفاده‌ای برنگرداند.');

        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
        update_post_meta($attachment_id, self::META_DONE, current_time('mysql'));
        ImageStats::add_alt();
        return $alt;
    }

    /** Small JPEG/PNG for the model: an existing medium size, else the original when small, else a resized copy. */
    private static function image_payload($attachment_id) {
        $path = get_attached_file($attachment_id);
        if (!$path || !is_readable($path)) return new \WP_Error('missing_file', 'فایل تصویر یافت نشد.');
        $mime = get_post_mime_type($attachment_id);
        if ($mime === 'image/gif' || $mime === 'image/svg+xml') return new \WP_Error('unsupported_image', 'این نوع تصویر برای تحلیل پشتیبانی نمی‌شود.');
        foreach (array('medium_large', 'large', 'medium') as $size) {
            $info = image_get_intermediate_size($attachment_id, $size);
            if ($info && !empty($info['file'])) {
                $candidate = path_join(dirname($path), $info['file']);
                if (is_readable($candidate) && filesize($candidate) <= 1024 * 1024) return array('mime' => $info['mime-type'] ?: $mime, 'data' => base64_encode((string) file_get_contents($candidate)));
            }
        }
        if (filesize($path) <= 400 * 1024) return array('mime' => $mime, 'data' => base64_encode((string) file_get_contents($path)));
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) return $editor;
        $editor->resize(1000, 1000, false);
        $editor->set_quality(80);
        $tmp = wp_tempnam('khazan-alt');
        $saved = $editor->save($tmp, 'image/jpeg');
        if (is_wp_error($saved)) { @unlink($tmp); return $saved; }
        $data = base64_encode((string) file_get_contents($saved['path']));
        @unlink($saved['path']);
        @unlink($tmp);
        return array('mime' => 'image/jpeg', 'data' => $data);
    }
}
