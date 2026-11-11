<?php
/**
 * Local log of every AI request the plugin makes (what asked, which model, tokens used), so the user can match the
 * provider's balance drops with real calls. Keeps the last entries only; no prompt text is stored.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Ai;

if (!defined('ABSPATH')) {
    exit;
}

final class UsageLog {

    const OPTION = 'khazan_ai_usage';
    const KEEP = 60;

    /** Human label of the feature that made the call (pure). */
    public static function label($class) {
        $map = array(
            'PageAnalyzer' => 'تحلیل صفحه (سرچ کنسول)',
            'ContentUpdater' => 'پیش‌نویس به‌روزرسانی محتوا',
            'LinkSuggester' => 'پیشنهاد لینک داخلی',
            'AltText' => 'متن جایگزین تصویر',
            'AiService' => 'تولید محتوا / سئو',
            'ContentController' => 'تولید محتوا / سئو',
            'BulkRowProcessor' => 'تولید انبوه محصول',
            'CategoryBulkGenerator' => 'تولید انبوه دسته',
            'SeoRepairController' => 'رفع ایراد سئو',
        );
        $short = substr((string) strrchr('\\' . (string) $class, '\\'), 1);
        return isset($map[$short]) ? $map[$short] : ($short !== '' ? $short : 'نامشخص');
    }

    /** Normalised usage entry from an API response's `usage` block (pure). */
    public static function entry($model, $usage, $label, $at) {
        $usage = is_array($usage) ? $usage : array();
        $details = isset($usage['completion_tokens_details']) && is_array($usage['completion_tokens_details']) ? $usage['completion_tokens_details'] : array();
        return array(
            'at' => (string) $at, 'model' => (string) $model, 'what' => (string) $label,
            'prompt' => (int) (isset($usage['prompt_tokens']) ? $usage['prompt_tokens'] : 0),
            'completion' => (int) (isset($usage['completion_tokens']) ? $usage['completion_tokens'] : 0),
            'reasoning' => (int) (isset($details['reasoning_tokens']) ? $details['reasoning_tokens'] : 0),
        );
    }

    public static function record($model, $usage) {
        if (!function_exists('update_option')) return;
        $label = 'نامشخص';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $f) {
            if (empty($f['class']) || $f['class'] === __CLASS__ || $f['class'] === AvalaiHttpClient::class) continue;
            $label = self::label($f['class']);
            break;
        }
        $log = get_option(self::OPTION, array());
        if (!is_array($log)) $log = array();
        $log[] = self::entry($model, $usage, $label, current_time('mysql'));
        update_option(self::OPTION, array_slice($log, -self::KEEP), false);
    }

    public static function all() {
        $log = get_option(self::OPTION, array());
        return is_array($log) ? array_reverse($log) : array();
    }
}
