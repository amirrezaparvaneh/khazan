<?php
/**
 * Prepares Persian text for GD's imagettftext, which neither joins Arabic-script letters nor reorders right-to-left text:
 * letters become their joined presentation forms (isolated / final / initial / medial, lam-alef ligatures) and the
 * line is reversed to visual order, keeping digit and Latin runs readable. Pure logic.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Image;

if (!defined('ABSPATH')) {
    exit;
}

final class PersianShaper {

    const ZWNJ = 0x200C;

    /** letter => [isolated, final, initial, medial]; right-joining letters have only the first two. */
    private static $forms = array(
        0x0627 => array(0xFE8D, 0xFE8E), 0x0622 => array(0xFE81, 0xFE82), 0x0623 => array(0xFE83, 0xFE84), 0x0625 => array(0xFE87, 0xFE88),
        0x0628 => array(0xFE8F, 0xFE90, 0xFE91, 0xFE92), 0x067E => array(0xFB56, 0xFB57, 0xFB58, 0xFB59),
        0x062A => array(0xFE95, 0xFE96, 0xFE97, 0xFE98), 0x062B => array(0xFE99, 0xFE9A, 0xFE9B, 0xFE9C),
        0x062C => array(0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0), 0x0686 => array(0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D),
        0x062D => array(0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4), 0x062E => array(0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8),
        0x062F => array(0xFEA9, 0xFEAA), 0x0630 => array(0xFEAB, 0xFEAC), 0x0631 => array(0xFEAD, 0xFEAE), 0x0632 => array(0xFEAF, 0xFEB0),
        0x0698 => array(0xFB8A, 0xFB8B),
        0x0633 => array(0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4), 0x0634 => array(0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8),
        0x0635 => array(0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC), 0x0636 => array(0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0),
        0x0637 => array(0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4), 0x0638 => array(0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8),
        0x0639 => array(0xFEC9, 0xFECA, 0xFECB, 0xFECC), 0x063A => array(0xFECD, 0xFECE, 0xFECF, 0xFED0),
        0x0641 => array(0xFED1, 0xFED2, 0xFED3, 0xFED4), 0x0642 => array(0xFED5, 0xFED6, 0xFED7, 0xFED8),
        0x06A9 => array(0xFB8E, 0xFB8F, 0xFB90, 0xFB91), 0x06AF => array(0xFB92, 0xFB93, 0xFB94, 0xFB95),
        0x0644 => array(0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0), 0x0645 => array(0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4),
        0x0646 => array(0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8), 0x0647 => array(0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC),
        0x0648 => array(0xFEED, 0xFEEE), 0x0624 => array(0xFE85, 0xFE86), 0x0629 => array(0xFE93, 0xFE94),
        0x06CC => array(0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF), 0x0626 => array(0xFE89, 0xFE8A, 0xFE8B, 0xFE8C),
    );

    /** Arabic variants normalised to the Persian letter. */
    private static $alias = array(0x0643 => 0x06A9, 0x064A => 0x06CC, 0x0649 => 0x06CC);

    /** lam + alef-kind => [isolated, final] ligature. */
    private static $lam_alef = array(
        0x0627 => array(0xFEFB, 0xFEFC), 0x0622 => array(0xFEF5, 0xFEF6), 0x0623 => array(0xFEF7, 0xFEF8), 0x0625 => array(0xFEF9, 0xFEFA),
    );

    private static $mirror = array('(' => ')', ')' => '(', '«' => '»', '»' => '«', '[' => ']', ']' => '[');

    /** @return string visual-order text for imagettftext (left-to-right drawing of a right-to-left line) */
    public static function visual($text) {
        $cps = array();
        foreach (preg_split('//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp >= 0x064B && $cp <= 0x0652) continue; // harakat do not take part in joining
            $cps[] = isset(self::$alias[$cp]) ? self::$alias[$cp] : $cp;
        }
        $glyphs = self::join($cps);

        // runs: digits / Latin keep their internal order, everything else is one-char runs; the run list is reversed
        $runs = array();
        $buffer = '';
        foreach ($glyphs as $g) {
            if (self::is_ltr($g)) { $buffer .= $g; continue; }
            if ($buffer !== '') { $runs[] = $buffer; $buffer = ''; }
            $runs[] = isset(self::$mirror[$g]) ? self::$mirror[$g] : $g;
        }
        if ($buffer !== '') $runs[] = $buffer;
        return implode('', array_reverse($runs));
    }

    /** @return string[] one glyph (UTF-8 char) per output position, in logical order */
    private static function join(array $cps) {
        $out = array();
        $count = count($cps);
        for ($i = 0; $i < $count; $i++) {
            $cp = $cps[$i];
            if ($cp === self::ZWNJ) continue;
            if (!isset(self::$forms[$cp])) { $out[] = mb_chr($cp, 'UTF-8'); continue; }

            $joins_prev = $i > 0 && self::joins_forward($cps[$i - 1]);
            // lam + alef ligature
            if ($cp === 0x0644 && $i + 1 < $count && isset(self::$lam_alef[$cps[$i + 1]])) {
                $out[] = mb_chr(self::$lam_alef[$cps[$i + 1]][$joins_prev ? 1 : 0], 'UTF-8');
                $i++;
                continue;
            }
            $dual = count(self::$forms[$cp]) === 4;
            $joins_next = $dual && $i + 1 < $count && self::joins_backward($cps[$i + 1]);
            if ($joins_prev && $joins_next) $form = 3;
            elseif ($joins_prev) $form = 1;
            elseif ($joins_next) $form = 2;
            else $form = 0;
            $out[] = mb_chr(self::$forms[$cp][$form], 'UTF-8');
        }
        return $out;
    }

    /** Can the letter connect to the one that FOLLOWS it? (dual-joining letters only) */
    private static function joins_forward($cp) {
        return isset(self::$forms[$cp]) && count(self::$forms[$cp]) === 4;
    }

    /** Can the letter connect to the one that PRECEDES it? (any joining letter) */
    private static function joins_backward($cp) {
        return isset(self::$forms[$cp]);
    }

    private static function is_ltr($glyph) {
        return (bool) preg_match('/^[0-9A-Za-z\x{06F0}-\x{06F9}\x{0660}-\x{0669}]$/u', $glyph);
    }
}
