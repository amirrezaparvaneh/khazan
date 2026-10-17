<?php
/**
 * Fits a title into at most N lines: the largest font size where greedy word-wrapping fits the width;
 * at the smallest size an over-long title is truncated with an ellipsis. Measuring is injected (GD in production).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Image;

if (!defined('ABSPATH')) {
    exit;
}

final class TitleLayout {

    /**
     * @param callable $measure function (string $line, int $size): int|float pixel width
     * @param int[]    $sizes   candidate font sizes, largest first
     * @return array{size:int,lines:string[]}
     */
    public static function fit($title, $max_width, array $sizes, callable $measure, $max_lines = 2) {
        $title = trim(preg_replace('/\s+/u', ' ', (string) $title));
        $words = $title === '' ? array() : explode(' ', $title);
        foreach ($sizes as $size) {
            $lines = self::wrap($words, $max_width, $size, $measure);
            if (count($lines) <= $max_lines) return array('size' => (int) $size, 'lines' => $lines);
        }
        $size  = (int) end($sizes);
        $lines = array_slice(self::wrap($words, $max_width, $size, $measure), 0, $max_lines);
        $last  = (string) end($lines);
        while ($last !== '' && $measure($last . '…', $size) > $max_width) {
            $last = rtrim(mb_substr($last, 0, mb_strlen($last) - 1));
        }
        $lines[count($lines) - 1] = $last . '…';
        return array('size' => $size, 'lines' => $lines);
    }

    private static function wrap(array $words, $max_width, $size, callable $measure) {
        $lines = array();
        $line = '';
        foreach ($words as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $measure($try, $size) > $max_width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines ?: array('');
    }
}
