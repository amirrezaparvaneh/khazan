<?php
/**
 * The article pipeline never asks for, returns or shows an image prompt.
 *
 *   php tests/Unit/Services/Ai/PromptFreeOutputTest.php
 */
define('ABSPATH', '/'); define('WILA_PLUGIN_DIR', dirname(__DIR__, 4) . '/');
function sanitize_key($s){return strtolower(preg_replace('~[^a-z0-9_\-]~i','',$s));} function post_type_exists($t){return true;} function wp_unslash($s){return $s;}
function apply_filters($t,$v){return $v;} function get_option($k,$d=''){return $d;} function home_url($p=''){return 'https://a.test'.$p;} function get_bloginfo($k){return 'S';}
function wp_strip_all_tags($s){return strip_tags($s);} function esc_html($s){return $s;} function esc_url($s){return $s;} function untrailingslashit($s){return rtrim($s,'/');} function wp_parse_url($u,$c=-1){return parse_url($u,$c);}
function sanitize_text_field($s){return trim(strip_tags($s));} function sanitize_textarea_field($s){return trim(strip_tags($s));} function sanitize_title($s){return strtolower(trim(preg_replace('~[^A-Za-z0-9]+~','-',$s),'-'));} function remove_accents($s){return $s;} function wp_json_encode($v,$f=0){return json_encode($v,$f);} function wp_kses_post($s){return $s;}
require WILA_PLUGIN_DIR.'includes/Core/Autoloader.php'; Khazan\Core\Autoloader::register('Khazan\\', WILA_PLUGIN_DIR.'includes/');
$pb = new Khazan\Services\Ai\PromptBuilder('');
$params = array('content_kind'=>'article','h1_title'=>'راهنمای کفش چرم','focus_keyword'=>'کفش چرم','description'=>'','internal_links'=>array(),'word_count'=>800,'content_type'=>'مقاله','funnel_stage'=>'TOFU','long_tail'=>'','seo_synonyms'=>'','use_orphan_links'=>false,'target'=>array());
$p = $pb->full_content($params, array('headings'=>array(array('level'=>'h2','text'=>'الف'))));
$o = $pb->outline($params);
$passed = 0; $failed = 0;
function check($c, $m) { global $passed, $failed; if ($c) { $passed++; echo "  ok: $m\n"; } else { $failed++; echo "  FAIL: $m\n"; } }
$all = $p . $o . $pb->content_system($params);
foreach (array('IMAGE_PROMPT', 'Image Prompt', 'wila-image-placeholder', 'image_positions') as $needle) check(stripos($all, $needle) === false, "AI instructions do not contain $needle");
$raw = "===WILA_CONTENT_START===\n<h2>الف</h2><p>متن</p><div class=\"wila-image-placeholder\"><p>Image Prompt (English): x</p></div>\n===WILA_CONTENT_END===\n===WILA_FEATURED_IMAGE_PROMPT_START===\nA photo of shoes\n===WILA_FEATURED_IMAGE_PROMPT_END===\n===WILA_SLUG_START===\nleather-shoe-guide\n===WILA_SLUG_END===";
$r = Khazan\Services\Ai\ContentParser::parse_full_content_payload($raw, $params);
check(stripos($r['content'], 'Image Prompt') === false && stripos($r['content'], 'photo of shoes') === false, 'parsed article has no prompt text');
check($r['featured_image_prompt'] === '' && $r['slug'] === 'leather-shoe-guide', 'prompt field empty, slug kept');
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
