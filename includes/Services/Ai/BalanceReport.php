<?php
/**
 * Normalizes the provider credit response into the plugin format and classifies the balance.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Ai;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class BalanceReport {

    const STATUS_OK       = 'ok';
    const STATUS_LOW      = 'low';       // yellow
    const STATUS_CRITICAL = 'critical';  // red
    const STATUS_EMPTY    = 'empty';     // red

    /**
     * @param array $data Decoded GET /credit response (limit, remaining_irt, remaining_unit, total_unit, exchange_rate, account_tier).
     * @return array|\WP_Error
     */
    public static function from_api($data) {
        if (!is_array($data) || (!isset($data['remaining_unit']) && !isset($data['remaining_irt']))) {
            return new \WP_Error('invalid_response', 'پاسخ استعلام اعتبار معتبر نیست.', array('status' => 502));
        }

        $rate = self::num($data, 'exchange_rate');
        $remaining_irt  = self::num($data, 'remaining_irt');
        $remaining_unit = self::num($data, 'remaining_unit');
        $total_irt  = self::num($data, 'limit');
        $total_unit = self::num($data, 'total_unit');

        // Fill the missing currency side from the exchange rate when possible.
        if ($remaining_unit === null && $remaining_irt !== null && $rate) $remaining_unit = $remaining_irt / $rate;
        if ($remaining_irt === null && $remaining_unit !== null && $rate) $remaining_irt = $remaining_unit * $rate;
        if ($total_unit === null && $total_irt !== null && $rate) $total_unit = $total_irt / $rate;
        if ($total_irt === null && $total_unit !== null && $rate) $total_irt = $total_unit * $rate;

        $remaining_irt  = $remaining_irt === null ? null : max(0.0, $remaining_irt);
        $remaining_unit = $remaining_unit === null ? null : max(0.0, $remaining_unit);

        return array(
            'total_irt'      => $total_irt,
            'used_irt'       => ($total_irt !== null && $remaining_irt !== null) ? max(0.0, $total_irt - $remaining_irt) : null,
            'remaining_irt'  => $remaining_irt,
            'total_unit'     => $total_unit,
            'used_unit'      => ($total_unit !== null && $remaining_unit !== null) ? max(0.0, $total_unit - $remaining_unit) : null,
            'remaining_unit' => $remaining_unit,
            'exchange_rate'  => $rate,
            'account_tier'   => isset($data['account_tier']) ? (int) $data['account_tier'] : null,
        );
    }

    /**
     * ok / low (yellow) / critical (red) / empty (red) from the remaining credit in USD (UNIT).
     */
    public static function status($remaining_unit) {
        if ($remaining_unit === null) return self::STATUS_OK;
        if ($remaining_unit <= 0) return self::STATUS_EMPTY;
        if ($remaining_unit < Settings::AI_BALANCE_CRITICAL_UNIT) return self::STATUS_CRITICAL;
        if ($remaining_unit < Settings::AI_BALANCE_WARN_UNIT) return self::STATUS_LOW;
        return self::STATUS_OK;
    }

    private static function num(array $data, $key) {
        return (isset($data[$key]) && is_numeric($data[$key])) ? (float) $data[$key] : null;
    }
}
