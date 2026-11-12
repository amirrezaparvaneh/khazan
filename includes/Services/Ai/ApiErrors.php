<?php
/**
 * Turns every failure of the AI gateway (HTTP status, provider message, transport error) into a clear Persian message
 * that says what happened and what to do. English provider text is never shown to the user.
 *
 * @package WP_Internal_Link_Analyzer
 */

namespace Khazan\Services\Ai;

if (!defined('ABSPATH')) {
    exit;
}

final class ApiErrors {

    const BILLING_URL = 'https://ava.al/billing';

    /** Codes after which the rest of a bulk run is pointless (the next request fails the same way). */
    const FATAL = array('ai_no_credit', 'ai_invalid_key', 'missing_api_key', 'ai_forbidden');

    /**
     * @param int    $status   HTTP status (0 when unknown)
     * @param string $provider message the provider returned (any language, may be empty)
     * @return array{code:string,message:string}
     */
    public static function from_response($status, $provider = '') {
        $status = (int) $status;
        $text = strtolower((string) $provider);

        if ($status === 402 || ($status !== 429 && preg_match('/credit|balance|insufficient|exhaust|quota|billing|top.?up|payment/', $text))) {
            return array('code' => 'ai_no_credit', 'message' => 'اعتبار حساب AvalAI شما تمام شده است و درخواست انجام نشد. برای ادامه، حساب را از نشانی ' . self::BILLING_URL . ' شارژ کنید و دوباره تلاش کنید. تولید گروهی هم تا شارژ حساب متوقف می‌ماند.');
        }
        if ($status === 401 || preg_match('/invalid.*(api.?)?key|incorrect api key|unauthorized|authentication/', $text)) {
            return array('code' => 'ai_invalid_key', 'message' => 'کلید API نامعتبر است یا منقضی شده. در بخش «تنظیمات» کلید را از پنل AvalAI دوباره کپی و ذخیره کنید.');
        }
        if ($status === 403) {
            return array('code' => 'ai_forbidden', 'message' => 'این کلید اجازه استفاده از مدل انتخاب‌شده را ندارد. در «تنظیمات» مدل دیگری انتخاب کنید یا دسترسی کلید را در پنل AvalAI بررسی کنید.');
        }
        if ($status === 404 || preg_match('/model.*(not found|does not exist|not exist|unknown)|no such model/', $text)) {
            return array('code' => 'ai_model_missing', 'message' => 'مدل انتخاب‌شده در AvalAI پیدا نشد. در «تنظیمات» فهرست مدل‌ها را بروزرسانی و یک مدل معتبر انتخاب کنید.');
        }
        if ($status === 429 || preg_match('/rate.?limit|too many requests/', $text)) {
            return array('code' => 'ai_rate_limited', 'message' => 'تعداد درخواست‌ها به هوش مصنوعی از حد مجاز بیشتر شد. چند دقیقه صبر کنید و دوباره تلاش کنید؛ در تولید گروهی افزونه خودش دوباره تلاش می‌کند.');
        }
        if ($status === 408 || $status === 504 || preg_match('/timed? ?out|timeout/', $text)) {
            return array('code' => 'ai_timeout', 'message' => 'پاسخ هوش مصنوعی دیر رسید و درخواست قطع شد. کمی بعد دوباره تلاش کنید؛ اگر تکرار شد، مدل سریع‌تری انتخاب کنید یا «میزان تفکر مدل» را در تنظیمات کم کنید.');
        }
        if ($status === 413 || preg_match('/context.?length|maximum context|too long|too many tokens|max.*tokens/', $text)) {
            return array('code' => 'ai_too_long', 'message' => 'متن یا درخواست برای این مدل بیش از حد طولانی است. محتوای کوتاه‌تری انتخاب کنید یا مدلی با ظرفیت بیشتر را در تنظیمات برگزینید.');
        }
        if (preg_match('/content.?policy|safety|moderation|flagged|violat/', $text)) {
            return array('code' => 'ai_blocked', 'message' => 'سرویس هوش مصنوعی به‌خاطر سیاست‌های محتوایی این درخواست را نپذیرفت. عنوان یا توضیحات را کمی تغییر دهید و دوباره تلاش کنید.');
        }
        if ($status === 503 || $status === 502 || $status === 500 || $status >= 500) {
            return array('code' => 'ai_unavailable', 'message' => 'سرویس هوش مصنوعی اکنون در دسترس نیست یا شلوغ است. چند دقیقه بعد دوباره تلاش کنید.');
        }
        if ($status === 400 || $status === 422) {
            return array('code' => 'ai_error', 'message' => 'سرویس هوش مصنوعی درخواست را نپذیرفت. یک بار دیگر تلاش کنید؛ اگر تکرار شد، مدل را در «تنظیمات» تغییر دهید.');
        }
        return array('code' => 'ai_error', 'message' => 'ارتباط با سرویس هوش مصنوعی ناموفق بود' . ($status ? ' (کد ' . $status . ')' : '') . '. کمی بعد دوباره تلاش کنید.');
    }

    /** A WP_Error built from a response; 429/503 keep their status so the bulk queue backs off. */
    public static function wp_error($status, $provider = '') {
        $e = self::from_response($status, $provider);
        $data = in_array((int) $status, array(429, 503), true) ? array('status' => (int) $status) : array();
        if (in_array($e['code'], array('ai_unavailable', 'ai_timeout'), true)) $data['status'] = 503;   // temporary: the bulk queue retries
        if (in_array($e['code'], self::FATAL, true)) $data['fatal'] = true;
        return new \WP_Error($e['code'], $e['message'], $data);
    }

    /** Transport failure (cURL / DNS / timeout) of wp_remote_*: the WordPress message is English and technical. */
    public static function transport($error) {
        $raw = strtolower(is_object($error) && method_exists($error, 'get_error_message') ? (string) $error->get_error_message() : (string) $error);
        if (preg_match('/timed out|timeout|error 28/', $raw)) {
            $msg = 'پاسخ سرویس به‌موقع نرسید و ارتباط قطع شد. اگر سرور شما کند است یا مدل سنگینی انتخاب کرده‌اید، دوباره تلاش کنید یا مدل سریع‌تری برگزینید.';
        } elseif (preg_match('/resolve host|name or service|error 6\b|getaddrinfo/', $raw)) {
            $msg = 'نشانی سرویس هوش مصنوعی پیدا نشد؛ اینترنت یا DNS سرور سایت شما مشکل دارد. با پشتیبانی هاست تماس بگیرید.';
        } elseif (preg_match('/ssl|certificate|error 35|error 60/', $raw)) {
            $msg = 'برقراری ارتباط امن با سرویس هوش مصنوعی ممکن نشد (مشکل گواهی SSL سرور). با پشتیبانی هاست تماس بگیرید.';
        } elseif (preg_match('/refused|error 7\b|unreachable|blocked|firewall/', $raw)) {
            $msg = 'سرور سایت شما نتوانست به سرویس هوش مصنوعی وصل شود؛ احتمالاً فایروال یا هاست ارتباط خروجی را بسته است. با پشتیبانی هاست تماس بگیرید.';
        } else {
            $msg = 'ارتباط با سرویس برقرار نشد. اتصال اینترنت سرور را بررسی و کمی بعد دوباره تلاش کنید.';
        }
        return new \WP_Error('connection_failed', $msg, array('status' => 502));
    }
}
