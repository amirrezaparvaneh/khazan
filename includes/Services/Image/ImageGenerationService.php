<?php
/**
 * Automatic in-article image: brief (English prompt, slug, Persian alt/caption) -> flux.2-pro -> media library -> HTML.
 * Never fails the content job: any error leaves the content unchanged and is reported in the returned status.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Image;

use Khazan\Core\Settings;
use Khazan\Repository\MediaRepository;
use Khazan\Services\Ai\AvalaiHttpClient;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageGenerationService {

    /** @var object Exposes chat($messages, $suggestions, $json, $max_tokens) and generate_image($prompt, $model, $size). */
    private $http;

    /** @var MediaRepository */
    private $media;

    public function __construct($http = null, MediaRepository $media = null) {
        $this->http  = $http ?: new AvalaiHttpClient(Settings::avalai_api_key(), Settings::AI_MODEL);
        $this->media = $media ?: new MediaRepository();
    }

    /**
     * Adds the images to a generated result (key "content") and reports them in "image":
     * article = featured image (kept out of the HTML, returned as featured_id) + one mid-content image;
     * product / category = one mid-content image. status: skipped | added | partial | failed.
     */
    public function embed(array $params, array $result, $force = false) {
        $result['image'] = array('status' => 'skipped');
        if ((!$force && !Settings::auto_image_enabled()) || empty($result['content'])) {
            return $result;
        }
        if (function_exists('set_time_limit')) @set_time_limit(360);

        $kind   = isset($params['content_kind']) ? $params['content_kind'] : '';
        $done   = array();
        $errors = array();

        if ($kind === Settings::KIND_ARTICLE) {
            $featured = $this->create($params, 'featured', ContentImageInjector::intro_text($result['content']), '');
            if (is_wp_error($featured)) $errors[] = $featured->get_error_message();
            else {
                $done['featured_id']  = $featured['id'];
                $done['featured_url'] = $featured['url'];
            }
        }

        if (!ContentImageInjector::has_image($result['content']) && ContentImageInjector::insert_offset($result['content']) >= 0) {
            $inline = $this->create($params, 'inline', ContentImageInjector::section_text($result['content']), isset($done['featured_id']) ? 'detail' : '');
            if (is_wp_error($inline)) $errors[] = $inline->get_error_message();
            else {
                $result['content'] = ContentImageInjector::inject($result['content'], $inline);
                $done['inline_id'] = $inline['id'];
                $done['url']       = $inline['url'];
            }
        }

        $result['image'] = $done + array('status' => empty($done) ? (empty($errors) ? 'skipped' : 'failed') : (empty($errors) ? 'added' : 'partial'));
        if ($errors) $result['image']['message'] = implode(' | ', $errors);
        return $result;
    }

    /** @return array|\WP_Error array(id, url, alt, caption) */
    private function create(array $params, $role, $text, $slug_suffix) {
        $raw = $this->http->chat(ImageBrief::messages($params, $text, $role), true, true, 1500);
        // A failed brief call is not fatal: the parser falls back to a generic studio prompt and neutral names.
        $brief = ImageBrief::parse(is_wp_error($raw) ? '' : $raw, $params, $role);
        if ($slug_suffix !== '') $brief['slug'] = ImageBrief::slug($brief['slug'] . '-' . $slug_suffix);

        $source = $this->render($brief['prompt']);
        if (is_wp_error($source)) return $source;

        $parent = (isset($params['content_kind']) && $params['content_kind'] === Settings::KIND_PRODUCT) ? absint(isset($params['target_id']) ? $params['target_id'] : 0) : 0;
        $stored = $this->media->store($source, $brief, $parent);
        if (is_wp_error($stored)) return $stored;

        return array('id' => $stored['id'], 'url' => $stored['url'], 'alt' => $brief['alt'], 'caption' => $brief['caption']);
    }

    /** Landscape first; if the API rejects the size, retry once with the safe square size. */
    private function render($prompt) {
        $size   = apply_filters(Settings::FILTER_IMAGE_SIZE, Settings::IMAGE_SIZE);
        $source = $this->http->generate_image($prompt, Settings::IMAGE_MODEL, $size);
        if (is_wp_error($source) && $source->get_error_code() === 'image_api_error' && $size !== Settings::IMAGE_SIZE_SAFE) {
            $source = $this->http->generate_image($prompt, Settings::IMAGE_MODEL, Settings::IMAGE_SIZE_SAFE);
        }
        return $source;
    }
}
