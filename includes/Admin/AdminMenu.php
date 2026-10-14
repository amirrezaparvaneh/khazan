<?php
/**
 * Registers the Khazan admin menu page and renders its mount container.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Admin;

use Khazan\Core\View;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminMenu {

    const SLUG        = 'wila-dashboard';
    const HOOK_SUFFIX = 'toplevel_page_wila-dashboard';
    const CAPABILITY  = 'manage_options';

    public function register() {
        add_action('admin_menu', array($this, 'add_menu_page'));
    }

    public function add_menu_page() {
        add_menu_page(
            'خـزان - دستیار هوشمند تولید محتوا و مهندسی سئو',
            'خـزان',
            self::CAPABILITY,
            self::SLUG,
            array($this, 'render'),
            $this->menu_icon(),
            30
        );
    }

    public function render() {
        View::render('admin/app-root');
    }

    private function menu_icon() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="#a7aaad"><path d="M50 4l9 20 14-6-4 22 22-6-11 18 14 8-25 8 4 14-21-6v20h-8V78l-21 6 4-14-25-8 14-8-11-18 22 6-4-22 14 6z"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
