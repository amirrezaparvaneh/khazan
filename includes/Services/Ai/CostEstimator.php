<?php
/**
 * Approximate cost of bulk generation rows from per-token prices (estimates only, never billing data).
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Ai;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class CostEstimator {

    /**
     * Cost of one bulk product row.
     *
     * @return array{model:string,input_tokens:int,output_tokens:int,input_per_1k:float,output_per_1k:float,per_row_unit:float}
     */
    public static function per_row($model = Settings::AI_MODEL) {
        $price = Settings::ai_pricing($model);
        $in  = Settings::AI_EST_INPUT_TOKENS_PER_ROW;
        $out = Settings::AI_EST_OUTPUT_TOKENS_PER_ROW;
        return array(
            'model'         => (string) $model,
            'input_tokens'  => $in,
            'output_tokens' => $out,
            'input_per_1k'  => $price['input_per_1k'],
            'output_per_1k' => $price['output_per_1k'],
            'per_row_unit'  => round(($in / 1000) * $price['input_per_1k'] + ($out / 1000) * $price['output_per_1k'], 6),
        );
    }

    /**
     * @return float estimated USD (UNIT) for $rows rows
     */
    public static function estimate($rows, $model = Settings::AI_MODEL) {
        $rows = max(0, (int) $rows);
        $one = self::per_row($model);
        return round($one['per_row_unit'] * $rows, 6);
    }
}
