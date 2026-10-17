<?php
/**
 * Media library access: stores a generated image as an attachment with file name, title, alt and caption.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Repository;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

class MediaRepository {

    const EXTENSIONS = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');

    /**
     * @param array $source  array('url' => ...) or array('b64' => ...)
     * @param array $meta    slug, title, alt, caption
     * @param int   $parent_id post to attach to (0 = unattached, e.g. a category description)
     * @return array|\WP_Error array(id, url)
     */
    public function store(array $source, array $meta, $parent_id = 0) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = $this->materialize($source);
        if (is_wp_error($tmp)) return $tmp;

        $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($tmp) : false;
        if (!$mime || !isset(self::EXTENSIONS[$mime])) {
            @unlink($tmp);
            return new \WP_Error('invalid_image', 'فایل دریافتی تصویر معتبر نیست.');
        }

        $id = media_handle_sideload(
            array('name' => $meta['slug'] . '.' . self::EXTENSIONS[$mime], 'tmp_name' => $tmp),
            absint($parent_id),
            $meta['title'],
            array('post_title' => $meta['title'], 'post_excerpt' => $meta['caption'])
        );
        if (is_wp_error($id)) {
            @unlink($tmp);
            return $id;
        }

        update_post_meta($id, Settings::META_IMAGE_ALT, $meta['alt']);
        update_post_meta($id, Settings::META_GENERATED_IMAGE, Settings::IMAGE_MODEL);
        return array('id' => (int) $id, 'url' => wp_get_attachment_url($id));
    }

    /** True for an attachment this plugin generated that the current user may use. */
    public function is_generated_image($attachment_id) {
        $attachment_id = absint($attachment_id);
        return $attachment_id > 0 && get_post_type($attachment_id) === 'attachment'
            && get_post_meta($attachment_id, Settings::META_GENERATED_IMAGE, true) !== ''
            && current_user_can('edit_post', $attachment_id);
    }

    /**
     * Binds a draft's generated images: featured image + attaches generated, still unattached images (incl. the
     * ones referenced as wp-image-N in the content) to the post.
     */
    public function adopt($post_id, $featured_id, $content) {
        $ids = array();
        if ($this->is_generated_image($featured_id)) {
            set_post_thumbnail($post_id, absint($featured_id));
            $ids[] = absint($featured_id);
        }
        if (preg_match_all('~class="wp-image-(\d+)"~', (string) $content, $m)) {
            $ids = array_merge($ids, array_map('absint', $m[1]));
        }
        foreach (array_unique($ids) as $id) {
            if ($this->is_generated_image($id) && (int) get_post_field('post_parent', $id) === 0) {
                wp_update_post(array('ID' => $id, 'post_parent' => $post_id));
            }
        }
    }

    /** @return string|\WP_Error path of a temp file holding the image bytes */
    private function materialize(array $source) {
        if (!empty($source['url'])) {
            return download_url($source['url'], Settings::TIMEOUT_IMAGE);
        }
        $bytes = !empty($source['b64']) ? base64_decode($source['b64'], true) : false;
        if ($bytes === false || $bytes === '') {
            return new \WP_Error('invalid_image', 'تصویر دریافتی خالی است.');
        }
        $tmp = wp_tempnam('khazan-image');
        return file_put_contents($tmp, $bytes) === false ? new \WP_Error('image_write_failed', 'ذخیره موقت تصویر ناموفق بود.') : $tmp;
    }
}
