<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="wila-repair-panel"><p>بررسی مستقل بر پایه توصیه‌های خوانایی Yoast؛ نه خواندن مستقیم خطاهای آن. فقط متن اصلاح می‌شود، نه لینک، تصویر، نامک یا متادیتا.</p>
<p>با شروع بررسی، متن فعلی برای پردازش به AvalAI ارسال می‌شود. پیشنهادها را بازبینی و تیک بزنید؛ ذخیره نهایی با به‌روزرسانی وردپرس است.</p>
<label>موارد موردنظر برای اصلاح (اختیاری)<textarea id="wila-repair-instructions" rows="3" style="width:100%" maxlength="2000" placeholder="مثلاً فقط جمله‌های بلند مقدمه کوتاه شوند."></textarea></label>
<p><button type="button" class="button" id="wila-repair-analyze">شروع بررسی و پیشنهاد اصلاح</button> <button type="button" class="button button-primary" id="wila-repair-apply" disabled>اعمال موارد تیک‌خورده</button> <button type="button" class="button" id="wila-repair-undo" disabled>بازگردانی</button></p>
<p id="wila-repair-status" role="status" aria-live="polite"></p><div id="wila-repair-results"></div></div>
