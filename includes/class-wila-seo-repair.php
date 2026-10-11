<?php
/** Selective, editor-only SEO repairs. */
if (!defined('ABSPATH')) exit;

class WILA_SEO_Repair {
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'meta_boxes'));
        foreach (array('category', 'product_cat') as $taxonomy) {
            add_action($taxonomy . '_edit_form_fields', array($this, 'term_panel'));
        }
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('rest_api_init', array($this, 'routes'));
    }

    public function meta_boxes() {
        foreach (array('post', 'page', 'blog', 'product') as $type) {
            if (post_type_exists($type)) add_meta_box('wila-seo-repair', 'بررسی و اصلاح خوانایی و سئو', array($this, 'panel'), $type, 'normal', 'default', array('__block_editor_compatible_meta_box' => true));
        }
    }

    public function term_panel() {
        echo '<tr class="form-field"><th>بررسی و اصلاح سئو</th><td>';
        $this->panel();
        echo '</td></tr>';
    }

    public function panel() {
        echo '<div id="wila-repair-panel"><p>بررسی مستقل بر پایه توصیه‌های خوانایی Yoast؛ نه خواندن مستقیم خطاهای آن. فقط متن اصلاح می‌شود، نه لینک، تصویر، نامک یا متادیتا.</p>';
        echo '<p>با شروع بررسی، متن فعلی برای پردازش به AvalAI ارسال می‌شود. پیشنهادها را بازبینی و تیک بزنید؛ ذخیره نهایی با به‌روزرسانی وردپرس است.</p>';
        echo '<label>موارد موردنظر برای اصلاح (اختیاری)<textarea id="wila-repair-instructions" rows="3" style="width:100%" maxlength="2000" placeholder="مثلاً فقط جمله‌های بلند مقدمه کوتاه شوند."></textarea></label>';
        echo '<p><button type="button" class="button" id="wila-repair-analyze">شروع بررسی و پیشنهاد اصلاح</button> <button type="button" class="button button-primary" id="wila-repair-apply" disabled>اعمال موارد تیک‌خورده</button> <button type="button" class="button" id="wila-repair-undo" disabled>بازگردانی</button></p>';
        echo '<p id="wila-repair-status" role="status" aria-live="polite"></p><div id="wila-repair-results"></div></div>';
    }

    public function assets() {
        $screen = get_current_screen();
        if (!$screen) return;
        $term = $screen->base === 'term' && in_array($screen->taxonomy, array('category', 'product_cat'), true);
        $post = $screen->base === 'post' && in_array($screen->post_type, array('post', 'page', 'blog', 'product'), true);
        if (!$term && !$post) return;
        global $post;
        $id = $term ? absint(isset($_GET['tag_ID']) ? $_GET['tag_ID'] : 0) : ($post instanceof WP_Post ? $post->ID : 0);
        wp_enqueue_script('wila-seo-repair', WILA_PLUGIN_URL . 'assets/js/wila-seo-repair.js', array('wp-data'), WILA_VERSION . '.' . filemtime(WILA_PLUGIN_DIR . 'assets/js/wila-seo-repair.js'), true);
        wp_localize_script('wila-seo-repair', 'wilaRepair', array('url' => rest_url('wila/v1/seo-repair'), 'nonce' => wp_create_nonce('wp_rest'), 'kind' => $term ? 'term' : 'post', 'id' => (int) $id));
    }

    public function routes() {
        register_rest_route('wila/v1', '/seo-repair', array('methods' => 'POST', 'callback' => array($this, 'analyze'), 'permission_callback' => array($this, 'permission')));
    }

    public function permission($request) {
        $id = absint($request->get_param('id'));
        if ($request->get_param('kind') === 'term') {
            $term = get_term($id);
            return $term && !is_wp_error($term) && in_array($term->taxonomy, array('category', 'product_cat'), true) && current_user_can('edit_term', $id);
        }
        $post = get_post($id);
        return $post && in_array($post->post_type, array('post', 'page', 'blog', 'product'), true) && current_user_can('edit_post', $id);
    }

    public function analyze($request) {
        $segments = $request->get_param('segments');
        $instructions = $request->get_param('instructions');
        if (!is_array($segments) || count($segments) > 300 || strlen(wp_json_encode($segments)) > 80000 || !is_string($instructions) || strlen($instructions) > 8000) {
            return new WP_Error('invalid_repair_input', 'حجم متن بیش از حد مجاز یا ورودی نامعتبر است.', array('status' => 400));
        }
        foreach ($segments as $segment) {
            if (!is_string($segment) || $segment !== wp_strip_all_tags($segment)) return new WP_Error('invalid_segment', 'فقط قطعه متن ساده مجاز است.', array('status' => 400));
        }
        // Bound concurrent requests and API spend per editor.
        $lock = 'wila_repair_' . get_current_user_id();
        if (get_transient($lock)) return new WP_Error('repair_busy', 'بررسی در جریان است؛ کمی بعد تلاش کنید.', array('status' => 429));
        set_transient($lock, 1, 190);
        try {
            $result = (new WILA_AvalAI_Client())->suggest_seo_repairs(array_values($segments), sanitize_textarea_field($instructions));
        } finally {
            delete_transient($lock);
        }
        return is_wp_error($result) ? $result : rest_ensure_response(array('patches' => $result));
    }
}
