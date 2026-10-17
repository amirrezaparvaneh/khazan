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
     * Adds the image to a generated result array (key "content"). Returns the result plus "image" status:
     * array(status => skipped|added|failed, message?, id?, url?).
     */
    public function embed(array $params, array $result, $force = false) {
        $result['image'] = array('status' => 'skipped');
        if ((!$force && !Settings::auto_image_enabled()) || empty($result['content']) || ContentImageInjector::has_image($result['content'])) {
            return $result;
        }
        if (ContentImageInjector::insert_offset($result['content']) < 0) {
            return $result;
        }

        $image = $this->create($params, $result['content']);
        if (is_wp_error($image)) {
            $result['image'] = array('status' => 'failed', 'message' => $image->get_error_message());
            return $result;
        }
        $result['content'] = ContentImageInjector::inject($result['content'], $image);
        $result['image']   = array('status' => 'added', 'id' => $image['id'], 'url' => $image['url']);
        return $result;
    }

    /** @return array|\WP_Error array(id, url, alt, caption) */
    private function create(array $params, $content) {
        $raw = $this->http->chat(ImageBrief::messages($params, ContentImageInjector::section_text($content)), true, true, 1500);
        // A failed brief call is not fatal: the parser falls back to a generic studio prompt and neutral names.
        $brief = ImageBrief::parse(is_wp_error($raw) ? '' : $raw, $params);

        $size = apply_filters(Settings::FILTER_IMAGE_SIZE, Settings::IMAGE_SIZE);
        $source = $this->http->generate_image($brief['prompt'], Settings::IMAGE_MODEL, $size);
        if (is_wp_error($source)) return $source;

        $parent = (isset($params['content_kind']) && $params['content_kind'] === Settings::KIND_PRODUCT) ? absint(isset($params['target_id']) ? $params['target_id'] : 0) : 0;
        $stored = $this->media->store($source, $brief, $parent);
        if (is_wp_error($stored)) return $stored;

        return array('id' => $stored['id'], 'url' => $stored['url'], 'alt' => $brief['alt'], 'caption' => $brief['caption']);
    }
}
