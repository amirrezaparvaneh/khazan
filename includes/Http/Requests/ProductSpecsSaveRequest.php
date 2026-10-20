<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

use Khazan\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates the payload of POST /product-specs/save: the quick AJAX save of one product's
 * technical specs (only the keys declared in Settings::PRODUCT_SPEC_FIELDS are accepted).
 */
final class ProductSpecsSaveRequest {

    /** @var array */
    private $input;

    public function __construct($input) {
        $this->input = is_array($input) ? $input : array();
    }

    /**
     * @return array|\WP_Error array(product_id, specs)
     */
    public function validated() {
        $product_id = isset($this->input['product_id']) ? absint($this->input['product_id']) : 0;
        if (!$product_id) return new \WP_Error('missing_product_id', 'شناسه محصول الزامی است.', array('status' => 400));

        $raw = isset($this->input['specs']) && is_array($this->input['specs']) ? $this->input['specs'] : array();
        $specs = array();
        foreach (Settings::PRODUCT_SPEC_FIELDS as $key => $label) {
            if (isset($raw[$key])) $specs[$key] = sanitize_text_field((string) $raw[$key]);
        }
        return array('product_id' => $product_id, 'specs' => $specs);
    }
}
