<?php
/**
 * In-article generated image.
 *
 * @var string $url
 * @var string $alt
 * @var int    $id
 * @var string $caption
 * @var string $css_class
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<figure class="wp-block-image size-large <?php echo esc_attr($css_class); ?>">
  <img src="<?php echo esc_url($url); ?>" alt="<?php echo esc_attr($alt); ?>" class="wp-image-<?php echo (int) $id; ?>" loading="lazy" />
  <figcaption><?php echo esc_html($caption); ?></figcaption>
</figure>
