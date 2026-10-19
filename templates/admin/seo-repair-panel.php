<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="wila-repair-panel" class="wila-repair">
<style>
.wila-repair .wila-r-spin{display:none;width:14px;height:14px;margin-inline-end:6px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;vertical-align:-2px;animation:wila-r-rot .8s linear infinite}
.wila-repair.is-loading .wila-r-spin{display:inline-block}
@keyframes wila-r-rot{to{transform:rotate(360deg)}}
.wila-repair .wila-r-error{border:1px solid #d63638;background:#fcf0f1;color:#8a1f20;padding:10px 12px;border-radius:4px;margin:10px 0}
.wila-repair .wila-r-info{color:#50575e}
.wila-repair .wila-r-issues{background:#f6f7f7;border-inline-start:3px solid #dba617;padding:8px 12px;margin:10px 0}
.wila-repair .wila-r-card{display:block;border:1px solid #c3c4c7;border-radius:4px;padding:12px;margin:10px 0;background:#fff}
.wila-repair .wila-r-card.is-meta{border-color:#2271b1}
.wila-repair .wila-r-card strong{display:block;margin-bottom:6px}
.wila-repair .wila-r-before{color:#8a1f20;text-decoration:line-through}
.wila-repair .wila-r-after{color:#1a6b2c}
.wila-repair .wila-r-reason{color:#50575e;font-size:12px;margin-top:4px}
</style>
<p class="wila-r-info">بدون نیاز به تایپ: دکمه بررسی را بزنید تا عنوان سئو، توضیحات متا و متن فعلی به‌طور خودکار اسکن شوند. اگر خطای مشخصی از Yoast دارید (مثلاً «عبارت کلیدی در عنوان سئو...») آن را در کادر زیر بنویسید.</p>
<p class="wila-r-info">با شروع بررسی، متن و متای فعلی برای پردازش به AvalAI ارسال می‌شود. لینک، تصویر و نامک تغییر نمی‌کنند؛ پیشنهادها را بازبینی و تیک بزنید و سپس ذخیره نهایی را با به‌روزرسانی وردپرس انجام دهید.</p>
<label>خطاها یا موارد موردنظر برای اصلاح (اختیاری)<textarea id="wila-repair-instructions" rows="3" style="width:100%" maxlength="2000" placeholder="خالی بگذارید تا خودکار اسکن شود، یا خطای Yoast را اینجا بچسبانید."></textarea></label>
<p><button type="button" class="button button-primary" id="wila-repair-analyze"><span class="wila-r-spin" aria-hidden="true"></span><span class="wila-r-label">شروع بررسی و پیشنهاد اصلاح</span></button> <button type="button" class="button button-primary" id="wila-repair-apply" disabled>اعمال موارد تیک‌خورده</button> <button type="button" class="button" id="wila-repair-undo" disabled>بازگردانی</button></p>
<p id="wila-repair-status" role="status" aria-live="polite"></p><div id="wila-repair-results"></div></div>
