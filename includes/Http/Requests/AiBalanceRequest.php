<?php
/**
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Http\Requests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Query of GET /settings/ai-balance. Only `refresh` is accepted: API keys are never read from the URL
 * (the saved key is used server-side).
 */
final class AiBalanceRequest {

    /** @var \WP_REST_Request */
    private $request;

    public function __construct($request) {
        $this->request = $request;
    }

    public function refresh() {
        return (bool) absint($this->request->get_param('refresh'));
    }
}
