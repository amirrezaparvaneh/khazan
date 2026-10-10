<?php
/**
 * کلاینت AvalAI با مدل ثابت DeepSeek Chat و قابلیت تزریق کلمات کلیدی صفحه اصلی
 *
 * @package WP_Internal_Link_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WILA_AvalAI_Client {

    private $provider;
    private $api_key;
    private $model;
    private $homepage_keywords;

    public function __construct() {
        $this->provider = 'avalai';
        $this->homepage_keywords = trim(get_option('wila_homepage_keywords', ''));
        
        $this->api_key = trim(get_option('wila_avalai_api_key', ''));
        $this->model   = 'deepseek-chat';
    }

    public function is_configured() {
        return !empty($this->api_key);
    }

    public function get_active_model() {
        return $this->model;
    }

    private function resolve_key($override_key = null) {
        $saved_key = trim(get_option('wila_avalai_api_key', ''));

        $key = !empty($override_key) ? trim(sanitize_text_field($override_key)) : $saved_key;
        if (strpos($key, '...') !== false || empty($key)) $key = $saved_key;
        return $key;
    }

    public function test_connection($override_key = null) {
        try {
            $key = $this->resolve_key($override_key);
            $model = $this->model;

            if (empty($key)) {
                return new WP_Error('missing_api_key', 'کلید API وارد نشده است.');
            }

            $start_time = microtime(true);

            $url = 'https://api.avalai.ir/v1/chat/completions';
            $body = array(
                'model' => 'deepseek-chat',
                'messages' => array(array('role' => 'user', 'content' => 'Ping')),
                'max_tokens' => 5,
            );
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $key,
                ),
                'body' => wp_json_encode($body),
                'timeout' => 20,
            ));

            $latency = round((microtime(true) - $start_time) * 1000);

            if (is_wp_error($response)) {
                return new WP_Error('connection_failed', 'خطا در ارتباط: ' . $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);
            $data = json_decode($raw_body, true);

            if ($code === 200) {
                return array('success' => true, 'model' => $model, 'latency_ms' => $latency, 'message' => 'ارتباط با موفقیت برقرار شد!');
            }
            
            if ($code === 503 || $code === 429) {
                return new WP_Error('api_error', 'سرور هوش مصنوعی در حال حاضر ترافیک بالایی دارد. لطفاً مجدداً تلاش کنید.');
            }

            $err_msg = isset($data['error']['message']) ? sanitize_text_field($data['error']['message']) : '';
            if (empty($err_msg)) {
                $err_msg = 'کد وضعیت سرور: ' . $code;
            } else {
                $err_msg = 'کد وضعیت سرور: ' . $code . ' (' . $err_msg . ')';
            }
            return new WP_Error('api_error', $err_msg, array('status' => $code));
        } catch (Exception $e) {
            return new WP_Error('exception_error', 'خطای سیستمی: ' . $e->getMessage());
        }
    }

    private function send_ai_request($prompt, $suggestions_request = false, $json_response = true) {
        if (!$this->is_configured()) return new WP_Error('missing_api_key', 'کلید API ثبت نشده است.');

        $url = 'https://api.avalai.ir/v1/chat/completions';
        $body = array(
            'model' => $this->model,
            'messages' => array(array('role' => 'user', 'content' => $prompt)),
            'temperature' => 0.2,
            'max_tokens' => $suggestions_request ? 2048 : 12000,
        );
        if ($json_response) {
            $body['response_format'] = array('type' => 'json_object');
        }

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body'    => wp_json_encode($body),
            'timeout' => $suggestions_request ? 35 : 180,
        ));

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'خطای سرور AvalAI';
            return new WP_Error('ai_error', $msg);
        }
        if (!isset($data['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', 'پاسخ نامعتبر از AvalAI.');
        }
        return $data['choices'][0]['message']['content'];
    }

    public function generate_content_blueprint($params) {
        try {
            $candidates = $this->getAllSiteEntities();
            if (empty($candidates)) return new WP_Error('no_candidates', 'هیچ محتوایی در سایت برای لینک‌دهی یافت نشد.');

            $prompt = $this->build_blueprint_prompt($params, $candidates);
            $json_text = $this->send_ai_request($prompt);
            
            if (is_wp_error($json_text)) {
                return new WP_Error('suggestions_unavailable', 'دریافت پیشنهاد از سرویس هوش مصنوعی ناموفق بود. لطفاً کمی بعد دوباره تلاش کنید. ' . $json_text->get_error_message());
            }

            $json_text = preg_replace('/^```json\s*/i', '', trim($json_text));$json_text = preg_replace('/```$/', '', trim($json_text));

            $parsed = json_decode(trim($json_text), true);
            return $parsed ? $parsed : array();
        } catch (Exception $e) {
            return new WP_Error('blueprint_exception', 'خطا در پردازش استراتژی: ' . $e->getMessage());
        }
    }

    public function generate_link_suggestions($target_id, $target_type) {
        try {
            $target_info = $this->get_entity_info($target_id, $target_type);
            if (!$target_info) return new WP_Error('invalid_target', 'اطلاعات صفحه مقصد یافت نشد.');

            $candidates = array_values(array_filter($this->getAllSiteEntities(), function ($candidate) use ($target_id, $target_type) {
                return !is_wp_error($candidate['url']) && !((int) $candidate['id'] === (int) $target_id && $candidate['type'] === $target_type);
            }));
            if (empty($candidates)) return new WP_Error('no_candidates', 'هیچ محتوایی برای پیشنهاد پیدا نشد.');

            $prompt = $this->build_analysis_prompt($target_info, $candidates);
            $cache_key = 'wila_suggest_v2_' . md5($this->provider . $this->model . $prompt);
            $cached = get_transient($cache_key);
            if (false !== $cached) return $cached;

            $json_text = $this->send_ai_request($prompt, true);
            if (is_wp_error($json_text)) return $json_text;
            $json_text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($json_text));
            $parsed = json_decode($json_text, true);
            if (!is_array($parsed) || !isset($parsed['suggestions']) || !is_array($parsed['suggestions'])) {
                return new WP_Error('invalid_ai_response', 'پاسخ هوش مصنوعی ساختار معتبری ندارد. لطفاً دوباره تلاش کنید.');
            }

            $sources = array();
            foreach ($candidates as $candidate) {
                $sources[$candidate['type'] . ':' . $candidate['id']] = $candidate;
            }
            $suggestions = array();
            $seen = array();
            foreach ($parsed['suggestions'] as $item) {
                if (!is_array($item) || !isset($item['source_id'], $item['source_type']) || !is_scalar($item['source_id']) || !is_string($item['source_type'])) continue;
                $key = $item['source_type'] . ':' . (int) $item['source_id'];
                if (!isset($sources[$key]) || isset($seen[$key])) continue;
                if (!isset($item['recommended_anchor_texts']) || !is_array($item['recommended_anchor_texts'])) continue;
                $anchors = array();
                foreach ($item['recommended_anchor_texts'] as $anchor) {
                    if (is_string($anchor) && trim($anchor) !== '') $anchors[] = sanitize_text_field($anchor);
                }
                if (empty($anchors)) continue;
                $source = $sources[$key];
                $suggestions[] = array(
                    'source_id' => $source['id'],
                    'source_type' => $source['type'],
                    'source_title' => $source['title'],
                    'recommended_anchor_texts' => array_slice($anchors, 0, 3),
                    'link_frequency' => 1,
                    'placement_guide' => isset($item['placement_guide']) && is_string($item['placement_guide']) ? sanitize_text_field($item['placement_guide']) : '',
                    'reasoning' => isset($item['reasoning']) && is_string($item['reasoning']) ? sanitize_text_field($item['reasoning']) : '',
                );
                $seen[$key] = true;
                if (count($suggestions) >= 5) break;
            }
            if (!empty($parsed['suggestions']) && empty($suggestions)) {
                return new WP_Error('invalid_ai_response', 'پیشنهادهای دریافتی معتبر نیستند. لطفاً دوباره تلاش کنید.');
            }
            set_transient($cache_key, $suggestions, HOUR_IN_SECONDS);
            return $suggestions;
        } catch (Exception $e) {
            return new WP_Error('suggestions_exception', 'خطا: ' . $e->getMessage());
        }
    }

    private function get_entity_info($id, $type) {
        $post_types = get_post_types(array('public' => true), 'names');
        if (in_array($type, $post_types)) {
            $post = get_post($id);
            if (!$post) return null;
            return array('id' => $id, 'type' => $type, 'title' => $post->post_title, 'url' => get_permalink($id), 'excerpt' => wp_strip_all_tags(wp_trim_words($post->post_content, 50)));
        }
        $term = get_term($id, $type);
        if (!$term || is_wp_error($term)) return null;
        return array('id' => $id, 'type' => $type, 'title' => $term->name, 'url' => get_term_link($term), 'excerpt' => !empty($term->description) ? wp_strip_all_tags($term->description) : 'بدون توضیح');
    }

    private function getAllSiteEntities() {
        $candidates = array();
        $post_types = get_post_types(array('public' => true), 'names');
        unset($post_types['attachment']);
        
        $posts = get_posts(array('post_type' => array_values($post_types), 'post_status' => 'publish', 'posts_per_page' => 40));
        foreach ($posts as $p) {
            $candidates[] = array('id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'url' => get_permalink($p->ID));
        }

        $taxonomies = get_taxonomies(array('public' => true), 'names');
        unset($taxonomies['post_tag'], $taxonomies['nav_menu']);
        $terms = empty($taxonomies) ? array() : get_terms(array('taxonomy' => array_values($taxonomies), 'hide_empty' => false, 'number' => 25));
        
        if (!is_wp_error($terms)) {
            foreach ($terms as $t) {
                $candidates[] = array('id' => $t->term_id, 'type' => $t->taxonomy, 'title' => $t->name, 'url' => get_term_link($t));
            }
        }
        return $candidates;
    }

    private function build_anchor_rules_prompt() {
        return "[قواعد الزامی انکرتکست؛ مقدم بر تعداد و تنوع پیشنهادها]\n" .
               "۱. انکرتکست باید منحصراً و ۱۰۰٪ توصیف‌کننده ماهیت صفحه مقصد (Target) باشد. مبدا (Source) فقط زمینه و محل طبیعی درج لینک را تعیین می‌کند، نه موضوع انکر را.\n" .
               "۲. ابتدا از عنوان، نوع، آدرس و توضیحات موجودِ مقصد، ماهیت و هدف جستجوی آن را مشخص کن؛ سپس انکر بساز. اطلاعات ناموجود را حدس نزن. نوع product_cat دسته‌بندی محصول است، نه مقاله آموزشی؛ دسته‌بندی محتوایی را نیز فروشگاه فرض نکن.\n" .
               "۳. برای مقصد محصول/دسته‌بندی محصول، نام دقیق محصول/دسته یا عبارت طبیعی خرید، مشاهده یا بررسی همان محصولات را به کار ببر؛ فقط وقتی با کارکرد واقعی مقصد سازگار است. برای مقصد آموزشی، انکر آموزشیِ منطبق با موضوع همان مقصد مجاز است.\n" .
               "[ممنوعیت‌ها / Negative Constraints]\n" .
               "- کلمات کلیدی یا مشکل مطرح‌شده در مبدا را صرفاً به دلیل ارتباط موضوعی به عنوان انکر مقصد پیشنهاد نده. اشتراک یک کلمه بین دو صفحه، تطابق نیت جستجو نیست.\n" .
               "- برای مقصد تجاری، انکرهایی مانند «روش رفع مشکل»، «آموزش تعمیر» یا «چگونه ...» ممنوع‌اند، مگر اینکه خود مقصد واقعاً همان آموزش باشد. افزودن نام محصول به یک عبارت آموزشی این عدم تطابق را برطرف نمی‌کند.\n" .
               "- وعده آموزش، مقایسه، تخفیف، ویژگی یا خدماتی که در داده‌های مقصد تأیید نشده ممنوع است. انکر مبهم مانند «اینجا کلیک کنید»، تکرار مصنوعی کلمات کلیدی و لینک‌سازی اجباری ممنوع است.\n" .
               "[نمونه صرفاً آموزشی؛ شناسه و عبارت آن را در خروجی کپی نکن]\n" .
               "مبدا: «روش‌های رفع سفتی کفش چرم»؛ مقصد با نوع product_cat: «بوت چرم مردانه». انکر درست: «بوت چرم مردانه» یا «مشاهده مدل‌های بوت چرم مردانه». انکر غلط: «رفع سفتی بوت جدید»، «روش نرم کردن بوت چرم مردانه»؛ این‌ها وعده آموزش می‌دهند، نه دسته‌بندی محصول.\n" .
               "موضوع رفع سفتی می‌تواند در جمله پیرامون لینک بیاید، اما فقط عبارت توصیف‌کننده مقصد باید لینک شود.\n" .
               "[کنترل نهایی پیش از خروجی؛ بدون نمایش مراحل فکر]\n" .
               "هر انکر را مستقل از متن مبدا بخوان: آیا کاربر با دیدن آن، ماهیت و محتوای واقعی مقصد را انتظار دارد؟ اگر نه، انکر را اصلاح کن یا پیشنهاد را حذف کن. اگر اطلاعات مقصد کافی نیست یا ارتباط طبیعی وجود ندارد، پیشنهاد نده؛ تعداد کمتر یا فهرست خالی بهتر از انکر گمراه‌کننده است.\n";
    }

    private function build_analysis_prompt($target, $candidates) {
        $target_json = wp_json_encode($target, JSON_UNESCAPED_UNICODE);
        $cands_json = wp_json_encode($candidates, JSON_UNESCAPED_UNICODE);
        
        return "شما یک متخصص ارشد سئو تکنیکال هستید.\n" .
               "صفحه مقصد: " . $target_json . "\n" .
               "صفحات کاندیدا مبدا: " . $cands_json . "\n\n" .
               $this->build_anchor_rules_prompt() . "\n" .
               "فقط برای صفحه مقصد مشخص‌شده، حداکثر ۵ پیشنهاد از کاندیداهای مبدا بدهید. شناسه و نوع را دقیقاً از کاندیداها انتخاب کنید. توضیحات کوتاه باشند. اگر مورد مرتبطی نیست suggestions را خالی برگردانید. خروجی فقط یک شیء JSON با ساختار زیر باشد:\n" .
               '{"suggestions":[{"source_id":123,"source_type":"post","recommended_anchor_texts":["انکر۱","انکر۲"],"placement_guide":"محل پیشنهادی","reasoning":"دلیل کوتاه"}]}';
    }

    public function generate_content_outline($params) {
        $prompt = $this->build_content_system_prompt($params) . "\nفقط طرح ساختاری را در JSON معتبر برگردان: {\"search_intent\":\"...\",\"strategy\":\"...\",\"headings\":[{\"level\":\"h2\",\"text\":\"...\",\"purpose\":\"...\"},{\"level\":\"h3\",\"text\":\"...\",\"purpose\":\"...\"}],\"image_positions\":[\"پس از بخش ...\"]}.";
        return $this->decode_ai_json($this->send_ai_request($prompt));
    }

    public function generate_full_content($params, $outline) {
        $prompt = $this->build_content_system_prompt($params) . "\nطرح تاییدشده:\n" . wp_json_encode($outline, JSON_UNESCAPED_UNICODE) . "\n" .
            "توضیحات و الزامات اختصاصی کاربر را نیز دقیق اجرا کن: " . (!empty($params['description']) ? $params['description'] : 'مورد خاصی ثبت نشده است.') . "\n" .
            "برای سئوی این محتوا این داده‌ها را نیز تولید کن: یک عنوان سئو فارسی ۵۰ تا ۶۰ کاراکتر، توضیحات متای فارسی ۱۴۰ تا ۱۶۰ کاراکتر، و ۳ تا ۶ مترادف/عبارت کلیدی مرتبط که با ویرگول جدا شوند. این موارد باید دقیقاً بر اساس موضوع و کلیدواژه کانونی باشند.\n" .
            "در متن، با توجه به طول محتوا ۱ لینک خارجی برای مقاله زیر ۱۰۰۰ کلمه و حداکثر ۲ لینک برای محتوای طولانی‌تر قرار بده. فقط منابع معتبر اولیه/رسمی، دانشگاهی یا سازمان‌های استاندارد مرتبط را انتخاب کن. URL را واقعی و دقیق استفاده کن؛ منبع یا URL نامطمئن/ساختگی ایجاد نکن، در صورت اطمینان نداشتن لینک خارجی نگذار.\n" .
            "محتوای کامل HTML را تولید کن. H1 نساز و همه هدینگ‌های تاییدشده را رعایت کن. پاسخ خود را دقیقاً با این بخش‌ها و برچسب‌ها برگردان:\n" .
            "===WILA_CONTENT_START===\n[HTML کامل بدنه محتوا]\n===WILA_CONTENT_END===\n" .
            "===WILA_FEATURED_IMAGE_PROMPT_START===\n[یک پرامپت انگلیسی دقیق برای تصویر شاخص با عنوان فارسی مقاله در مرکز، فونت Vazirmatn/Vazir، حاشیه امن چهارطرفه و نسبت 16:9]\n===WILA_FEATURED_IMAGE_PROMPT_END===\n" .
            "===WILA_SLUG_START===\n[یک اسلاگ انگلیسی کوتاه و دقیق، فقط با حروف کوچک لاتین و خط تیره، بدون تاریخ/کلمات زائد؛ مرتبط با موضوع و کلیدواژه اصلی]\n===WILA_SLUG_END===\n" .
            "===WILA_SEO_TITLE_START===\n[عنوان سئو فارسی با طول ۵۰ تا ۶۰ کاراکتر]\n===WILA_SEO_TITLE_END===\n" .
            "===WILA_META_DESCRIPTION_START===\n[توضیحات متا فارسی با طول ۱۴۰ تا ۱۶۰ کاراکتر]\n===WILA_META_DESCRIPTION_END===\n" .
            "===WILA_KEYWORD_SYNONYMS_START===\n[۳ تا ۶ مترادف مرتبط با ویرگول]\n===WILA_KEYWORD_SYNONYMS_END===";
        $raw = $this->send_ai_request($prompt, false, false);
        if (is_wp_error($raw)) return $raw;

        $result = $this->parse_full_content_payload($raw, $params);
        if (is_wp_error($result)) return $result;
        if ($result['slug'] === '') {
            $result['slug'] = sanitize_title(remove_accents($params['h1_title']));
        }
        if ($result['slug'] === '') {
            $result['slug'] = sanitize_title(remove_accents($params['focus_keyword']));
        }
        return $result;
    }

    private function parse_full_content_payload($raw, $params) {
        $raw = trim((string) $raw);
        $content = '';
        $featured_prompt = '';
        $slug = '';
        $seo_title = '';
        $meta_description = '';
        $keyword_synonyms = '';

        if (preg_match('/===WILA_CONTENT_START===\s*(.*?)\s*===WILA_CONTENT_END===/is', $raw, $m)) {
            $content = trim($m[1]);
        } elseif (preg_match('/===WILA_CONTENT_START===\s*(.*)/is', $raw, $m)) {
            $content = trim($m[1]);
            // اگر برچسب تصویر شاخص بعد از آن آمده باشد، آن را جدا کن
            if (preg_match('/(.*?)(?:===WILA_FEATURED_IMAGE_PROMPT_START===|$)/is', $content, $cm)) {
                $content = trim($cm[1]);
            }
        }

        if (preg_match('/===WILA_FEATURED_IMAGE_PROMPT_START===\s*(.*?)\s*===WILA_FEATURED_IMAGE_PROMPT_END===/is', $raw, $m)) {
            $featured_prompt = trim($m[1]);
        } elseif (preg_match('/===WILA_FEATURED_IMAGE_PROMPT_START===\s*(.*)/is', $raw, $m)) {
            $featured_prompt = trim($m[1]);
            if (preg_match('/(.*?)(?:===WILA_CONTENT_START===|$)/is', $featured_prompt, $fm)) {
                $featured_prompt = trim($fm[1]);
            }
        }

        if (preg_match('/===WILA_SLUG_START===\s*(.*?)\s*===WILA_SLUG_END===/is', $raw, $m)) $slug = trim($m[1]);
        if (preg_match('/===WILA_SEO_TITLE_START===\s*(.*?)\s*===WILA_SEO_TITLE_END===/is', $raw, $m)) $seo_title = trim($m[1]);
        if (preg_match('/===WILA_META_DESCRIPTION_START===\s*(.*?)\s*===WILA_META_DESCRIPTION_END===/is', $raw, $m)) $meta_description = trim($m[1]);
        if (preg_match('/===WILA_KEYWORD_SYNONYMS_START===\s*(.*?)\s*===WILA_KEYWORD_SYNONYMS_END===/is', $raw, $m)) $keyword_synonyms = trim($m[1]);

        // پشتیبانی فال‌بک در صورتی که مدل خروجی JSON داده باشد
        if (empty($content)) {
            $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);
            $json = json_decode(trim($clean), true);
            if (is_array($json) && !empty($json['content'])) {
                $content = trim($json['content']);
                if (!empty($json['featured_image_prompt'])) {
                    $featured_prompt = trim($json['featured_image_prompt']);
                }
                if (!empty($json['slug'])) $slug = trim($json['slug']);
                if (!empty($json['seo_title'])) $seo_title = trim($json['seo_title']);
                if (!empty($json['meta_description'])) $meta_description = trim($json['meta_description']);
                if (!empty($json['keyword_synonyms'])) $keyword_synonyms = trim($json['keyword_synonyms']);
            }
        }

        // پشتیبانی فال‌بک در صورتی که مدل مستقیماً HTML بازگردانده باشد
        if (empty($content)) {
            $clean = preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $raw);
            if (strpos($clean, '<p') !== false || strpos($clean, '<h') !== false || strpos($clean, '<div') !== false) {
                $content = trim($clean);
            }
        }

        if (empty($content)) {
            return new WP_Error('invalid_content_response', 'محتوای HTML از AvalAI دریافت نشد. لطفاً مجدداً امتحان کنید.');
        }

        $slug = sanitize_title($slug);
        if ($slug === '') {
            $slug = sanitize_title(remove_accents($params['h1_title']));
        }
        if ($slug === '') {
            $slug = sanitize_title(remove_accents($params['focus_keyword']));
        }

        $external_links = $this->extract_external_links($content);
        return array(
            'content' => $content,
            'featured_image_prompt' => sanitize_textarea_field($featured_prompt),
            'slug' => $slug,
            'seo_title' => sanitize_text_field($seo_title ?: $params['h1_title']),
            'meta_description' => sanitize_textarea_field($meta_description),
            'keyword_synonyms' => sanitize_text_field($keyword_synonyms),
            'internal_link_count' => $this->count_content_links($content, true),
            'external_link_count' => count($external_links),
            'external_links' => $external_links,
        );
    }

    private function parse_content_anchors($content) {
        $anchors = array();
        if (!preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', $content, $matches, PREG_SET_ORDER)) return $anchors;
        foreach ($matches as $match) {
            $anchors[] = array('url' => html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'), 'anchor' => sanitize_text_field(wp_strip_all_tags($match[3])));
        }
        return $anchors;
    }

    private function count_content_links($content, $internal) {
        $site_host = strtolower(wp_parse_url(home_url('/'), PHP_URL_HOST));
        $count = 0;
        foreach ($this->parse_content_anchors($content) as $link) {
            $host = strtolower(wp_parse_url($link['url'], PHP_URL_HOST) ?: $site_host);
            if (($host === $site_host) === $internal) $count++;
        }
        return $count;
    }

    private function extract_external_links($content) {
        $links = array();
        $site_host = strtolower(wp_parse_url(home_url('/'), PHP_URL_HOST));
        foreach ($this->parse_content_anchors($content) as $link) {
            $host = strtolower(wp_parse_url($link['url'], PHP_URL_HOST) ?: '');
            if ($host && $host !== $site_host && in_array(wp_parse_url($link['url'], PHP_URL_SCHEME), array('http', 'https'), true)) {
                $links[] = array('url' => esc_url_raw($link['url']), 'anchor' => $link['anchor']);
            }
        }
        return $links;
    }

    private function decode_ai_json($response) {
        if (is_wp_error($response)) return $response;

        $response = trim((string) $response);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```$/', '', $response);
        $parsed = json_decode(trim($response), true);
        if (is_array($parsed)) return $parsed;

        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $slice = substr($response, $start, $end - $start + 1);
            $parsed = json_decode($slice, true);
            if (is_array($parsed)) return $parsed;

            $cleaned = preg_replace('/,\s*([\}\]])/', '$1', $slice);
            $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $cleaned);
            $parsed = json_decode($cleaned, true);
            if (is_array($parsed)) return $parsed;
        }

        return new WP_Error('invalid_ai_response', 'پاسخ هوش مصنوعی JSON معتبر نیست. دوباره تلاش کنید.');
    }

    private function build_content_system_prompt($params) {
        $data = wp_json_encode($params, JSON_UNESCAPED_UNICODE);
        return "شما نویسنده ارشد محتوای فارسی و متخصص سئو در سال ۲۰۲۶ هستید. مشخصات محتوا: " . $data . "\n" .
            "الزامات قطعی: E-E-A-T را با تجربه دست‌اول قابل‌باور، تخصص و اعتبار پیاده کن؛ موجودیت‌های مهم و روابط معنایی آن‌ها را کامل پوشش بده؛ پاسخ مستقیم نیت جستجو را در پاراگراف‌های نخست بده؛ برای AEO پاسخ‌های کوتاه، روشن و قابل‌استخراج بنویس. از ادعاهای بی‌منبع و keyword stuffing پرهیز کن.\n" .
            "هدینگ‌های H2 و H3 را مطابق سفر مشتری و FAQ منطقی بچین. فارسی روان، پاراگراف کوتاه، فهرست و جدول فقط در صورت مفیدبودن بنویس. اگر homepage_keywords در مشخصات موجود است، در محتوای مناسب و طبیعی یک یا چند کلیدواژه اصلی صفحه اول را برای لینک داخلی به URL صفحه اول سایت به‌کار ببر؛ از تکرار اجباری و keyword stuffing پرهیز کن.\n" .
            "نقشه لینک داخلی داده‌شده را دقیقاً با HTML <a href=\"URL\">انکرتکست</a> و فقط در جمله طبیعی وارد کن. از انکرتکست عمومی پرهیز کن و حداکثر یک لینک داخلی به ازای هر ۲۵۰ تا ۳۵۰ کلمه بگذار.\n" .
            "برای تقویت اعتبار و استناد، متناسب با طول مقاله ۱ لینک خارجی معتبر (محتوای کوتاه) یا حداکثر ۲ لینک خارجی (محتوای بلند) از منابع رسمی، دانشگاهی، دولتی یا سازمان‌های شناخته‌شده اضافه کن. فقط URL واقعی و شناخته‌شده‌ای را لینک بده که به آن اطمینان داری؛ لینک ساختگی یا دامنه/صفحه حدسی نساز. در صورت نبود منبع قابل اتکا، لینک خارجی نده. از لینک‌های خارجی با rel=\"nofollow\" استفاده نکن مگر مقصد تجاری یا تبلیغاتی باشد.\n" .
            "اگر use_orphan_links فعال است، فهرست internal_links شامل همه صفحات یتیم افزونه و به‌ترتیب اولویت است: دسته‌بندی محصول، محصول، مقاله بلاگ، برگه عمومی. از همه نامزدهای مرتبط با موضوع و نیت محتوا، به‌ترتیب اولویت استفاده کن تا یتیمی صفحات به‌تدریج رفع شود؛ فقط موارد نامرتبط را حذف کن. برای هر مورد منتخب، انکرتکست دقیق و توصیف‌کننده مقصد بساز، URL همان مورد را استفاده کن و لینک را طبیعی در متن بگذار. سقف یک لینک داخلی به ازای هر ۲۵۰ تا ۳۵۰ کلمه قطعی است؛ اگر نامزدهای مرتبط بیشترند، برای محتواهای بعدی نگه‌شان دار.\n" .
            "در جای لازم برای تصویر، دقیقاً این HTML را درج کن: <div class=\"wila-image-placeholder\"><strong>[پیشنهاد تصویر سئو]</strong><p><strong>Image Prompt (English):</strong> prompt</p><p><strong>Alt Text (فارسی):</strong> alt</p><p><strong>File Name (English):</strong> seo-content-strategy.webp</p></div>. پرامپت انگلیسی باید دقیق و دارای سبک بصری باشد.\n" .
            "الزام لینک خارجی: متناسب با حجم محتوا، حتماً ۱ الی ۲ لینک خارجی بسیار معتبر و متنوع (مانند مراجع علمی، استانداردهای رسمی، ویکی‌پدیا، یا منابع تراز اول حوزه) به صورت طبیعی در متن با تگ <a href=\"URL\" target=\"_blank\" rel=\"noopener noreferrer\"> قرار بده. از ارجاع به رقبا یا لینک‌های اسپم و نامعتبر پرهیز کن.";
    }

    private function build_blueprint_prompt($params, $candidates) {
        $params_json = wp_json_encode($params, JSON_UNESCAPED_UNICODE);
        $cands_json = wp_json_encode($candidates, JSON_UNESCAPED_UNICODE);
        
        $homepage_instruction = !empty($this->homepage_keywords) 
            ? "\n[دستورالعمل ویژه سئو صفحه اول]: حتماً در بخش لینک‌های خروجی، صفحه اصلی سایت با آدرس (" . home_url() . ") را نیز به عنوان یکی از مقاصد لینک‌دهی با انکرتکست‌های مرتبط از میان کلمات کلیدی صفحه اصلی یعنی [" . $this->homepage_keywords . "] پیشنهاد دهید."
            : "";

        return "شما یک استراتژیست ارشد سئو و معماری لینک‌سازی داخلی هستید.\n" .
               "مشخصات مقاله جدیدی که قرار است نوشته شود:\n" . $params_json . "\n\n" .
               "لیست صفحات موجود در سایت برای لینک‌دهی:\n" . $cands_json . "\n\n" .
               $homepage_instruction . "\n\n" .
               $this->build_anchor_rules_prompt() . "\n" .
               "در این نقشه‌راه، مقاله جدید نقش صفحه مبدا را دارد و هر صفحه موجود/صفحه اصلی که در outgoing_links می‌آید نقش مقصد را دارد. برای هر لینک، anchor_text را فقط از ماهیت، نوع و هدف جستجوی همان target بساز؛ موضوع مقاله جدید فقط باید placement و طبیعی‌بودن لینک را تعیین کند. اگر یک صفحه موجود با مقاله جدید ارتباط طبیعی ندارد، آن را پیشنهاد نده. در مورد صفحه اصلی نیز فقط از کلیدواژه‌هایی استفاده کن که واقعاً خدمات/موضوع صفحه اصلی را توصیف می‌کنند.\n" .
               "دستورالعمل: بر اساس تعداد کلمات و مشخصات این مقاله، یک نقشه راه استراتژیک لینک‌‌سازی به صورت یک شیء JSON معتبر با ساختار زیر تولید کنید:\n" .
               "{\n" .
               '  "total_recommended_links": 5,' . "\n" .
               '  "strategy_notes": "توضیح کوتاه استراتژیک درباره توزیع لینک‌ها بر اساس نوع محتوا",' . "\n" .
               '  "outgoing_links": [' . "\n" .
               '    {' . "\n" .
               '      "target_id": 123,' . "\n" .
               '      "target_title": "عنوان صفحه یا صفحه اصلی سایت",' . "\n" .
               '      "target_url": "آدرس صفحه",' . "\n" .
               '      "anchor_text": "انکرتکست پیشنهادی",' . "\n" .
               '      "placement": "محل دقیق قرارگیری در متن"' . "\n" .
               '    }' . "\n" .
               '  ],' . "\n" .
               '  "incoming_link_suggestions": [' . "\n" .
               '    {' . "\n" .
               '      "old_page_title": "عنوان یک مقاله قدیمی مرتبط",' . "\n" .
               '      "action": "پس از انتشار این مقاله، به مقاله قدیمی مراجعه کرده و لینک بدهید."\n' .
               '    }' . "\n" .
               '  ]' . "\n" .
               "}";

    }
}