<?php
/**
 * Builds the (hidden) image brief request and parses the reply: English studio prompt, English file slug,
 * Persian alt text and Persian caption. Pure logic - no HTTP, no WordPress state.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Image;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageBrief {

    /** Always appended: the commercial-photography standard, regardless of what the model wrote. */
    const PROMPT_STANDARD = 'Commercial product photography, soft diffused studio lighting, minimal luxury background, sharp focus, clearly visible natural leather grain texture, high-end e-commerce catalog style. No watermark, no text, no letters, no logo, no people.';

    /** Articles are editorial pieces of a leather goods blog, not product shots. */
    const ARTICLE_STANDARD = 'Editorial lifestyle photography, soft natural light, clean elegant composition, shallow depth of field, premium handcrafted leather goods mood, high-end magazine style. No watermark, no text, no letters, no logo.';

    const FALLBACK_SLUG = 'leather-product';

    /**
     * @return array chat messages asking for a JSON brief
     */
    public static function messages(array $params, $section_text, $role = 'inline') {
        $focus = self::focus($params);
        $system = 'You are an art director for a Persian leather goods e-commerce store. Reply with one JSON object only.';
        $what = $role === 'featured' ? 'the wide featured (cover) photo of the page, representing the whole topic' : 'an in-article photo illustrating the middle section';
        $user = "Create an image brief for {$what}.\n"
            . "Page title (Persian): " . (isset($params['h1_title']) ? $params['h1_title'] : '') . "\n"
            . "Focus keyword (Persian): {$focus}\n"
            . "Relevant excerpt (Persian): " . self::excerpt($section_text, 500) . "\n\n"
            . "JSON keys:\n"
            . "- \"prompt\": English scene description for an image model (subject, material, angle, composition). Photography only; do not mention text, logos or watermarks.\n"
            . "- \"slug\": 3-6 lowercase English words describing the subject, hyphen separated, e.g. womens-leather-boots-alfredo (translate/transliterate the focus keyword).\n"
            . "- \"alt\": Persian alt text, 8-16 words, descriptive, must contain the exact focus keyword.\n"
            . "- \"caption\": one short, attractive Persian sentence about the product.";
        return array(array('role' => 'system', 'content' => $system), array('role' => 'user', 'content' => $user));
    }

    /**
     * @return array{prompt:string,slug:string,alt:string,caption:string,title:string}
     */
    public static function parse($raw, array $params, $role = 'inline') {
        $focus = self::focus($params);
        $data  = json_decode(self::json_text((string) $raw), true);
        $data  = is_array($data) ? $data : array();

        $prompt = isset($data['prompt']) ? trim(wp_strip_all_tags((string) $data['prompt'])) : '';
        $article = isset($params['content_kind']) && $params['content_kind'] === \Khazan\Core\Settings::KIND_ARTICLE;
        $prompt = ($prompt !== '' ? rtrim($prompt, '. ') . '. ' : 'Elegant natural leather goods. ') . ($article ? self::ARTICLE_STANDARD : self::PROMPT_STANDARD);

        $alt = isset($data['alt']) ? trim(wp_strip_all_tags((string) $data['alt'])) : '';
        if ($alt === '') {
            $alt = 'نمای نزدیک ' . $focus . ' با بافت چرم طبیعی';
        } elseif ($focus !== '' && mb_strpos($alt, $focus) === false) {
            $alt = $focus . ': ' . $alt;
        }

        $caption = isset($data['caption']) ? trim(wp_strip_all_tags((string) $data['caption'])) : '';
        if ($caption === '') {
            $caption = $focus . ' با چرم طبیعی و دوخت دقیق';
        }

        return array(
            'prompt'  => $prompt,
            'slug'    => self::slug(isset($data['slug']) ? (string) $data['slug'] : ''),
            'alt'     => $alt,
            'caption' => $caption,
            'title'   => $focus,
        );
    }

    /** Clean ASCII slug (max 6 words / 60 chars); falls back to a neutral name when the model gave none. */
    public static function slug($value) {
        $value = strtolower(str_replace(array("'", "’"), '', trim($value)));
        $value = trim(preg_replace('~[^a-z0-9]+~', '-', $value), '-');
        $words = array_slice(array_filter(explode('-', $value)), 0, 6);
        $value = substr(implode('-', $words), 0, 60);
        return trim($value, '-') !== '' ? trim($value, '-') : self::FALLBACK_SLUG;
    }

    private static function focus(array $params) {
        foreach (array('focus_keyword', 'h1_title') as $key) {
            if (!empty($params[$key])) return trim((string) $params[$key]);
        }
        return '';
    }

    private static function excerpt($text, $max) {
        $text = trim(preg_replace('~\s+~u', ' ', wp_strip_all_tags((string) $text)));
        return mb_substr($text, 0, $max);
    }

    private static function json_text($raw) {
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        return ($start !== false && $end !== false && $end > $start) ? substr($raw, $start, $end - $start + 1) : $raw;
    }
}
