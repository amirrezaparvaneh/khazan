<?php
/**
 * Tolerant JSON decoding of model replies (code fences, trailing commas, stray text).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Ai;

if (!defined('ABSPATH')) {
    exit;
}

final class AiResponseDecoder {

    /**
     * @param string|\WP_Error $response
     * @return array|\WP_Error
     */
    public static function decode_json($response) {
        if (is_wp_error($response)) {
            return $response;
        }

        $response = trim((string) $response);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```$/', '', $response);
        $parsed   = json_decode(trim($response), true);
        if (is_array($parsed)) {
            return $parsed;
        }

        $start = strpos($response, '{');
        $end   = strrpos($response, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $slice  = substr($response, $start, $end - $start + 1);
            $parsed = json_decode($slice, true);
            if (is_array($parsed)) {
                return $parsed;
            }

            $cleaned = preg_replace('/,\s*([\}\]])/', '$1', $slice);
            $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $cleaned);
            $parsed  = json_decode($cleaned, true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        return new \WP_Error('invalid_ai_response', 'پاسخ هوش مصنوعی JSON معتبر نیست. دوباره تلاش کنید.');
    }
}
