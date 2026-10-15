/**
 * Khazan - shared pure helpers (digits, Jalali dates, HTML escaping).
 * Author: امیــــــــررضا پـروانه
 */
(function () {
  'use strict';

  function toPersianDigits(n) {
    if (n === null || n === undefined) return '';
    const farsiDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return n.toString().replace(/\d/g, (x) => farsiDigits[x]);
  }

  // تاریخ شمسی با ارقام فارسی؛ مقدار ورودی زمان محلی سایت (Y-m-d H:i:s) است و بدون تبدیل منطقه زمانی نمایش داده می‌شود.
  function formatJalali(value) {
    const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!m) return toPersianDigits(value || '');
    try {
      return new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'UTC' })
        .format(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]));
    } catch (e) { return toPersianDigits(value); }
  }

  // تاریخ کوتاه شمسی (مثلاً «۲۲ مهر») برای بج‌های فشرده
  function shortJalali(value) {
    const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return '';
    try {
      return new Intl.DateTimeFormat('fa-IR-u-ca-persian', { month: 'long', day: 'numeric', timeZone: 'UTC' }).format(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    } catch (e) { return ''; }
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (char) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[char]));
  }

  // عدد با جداکننده هزارگان فارسی (۱۲۳٬۴۵۰)
  function formatNumber(value) {
    const n = Math.round(Number(value) || 0);
    return toPersianDigits(n.toLocaleString('en-US')).replace(/,/g, '٬');
  }

  // مبلغ دلاری با اعشار فارسی: ≥ ۱ دلار ۲ رقم، زیر ۱ دلار ۳ رقم، زیر یک سنت ۴ رقم (≈ $۴٫۲۰ / $۰٫۳۱۵ / $۰٫۰۰۳۱)
  function formatUsd(value) {
    const n = Number(value) || 0;
    const digits = n === 0 || n >= 1 ? 2 : (n < 0.01 ? 4 : 3);
    return '$' + toPersianDigits(n.toFixed(digits)).replace('.', '٫');
  }

  window.KhazanUtils = {
    formatNumber: formatNumber,
    formatUsd: formatUsd,
    toPersianDigits: toPersianDigits,
    formatJalali: formatJalali,
    shortJalali: shortJalali,
    escapeHtml: escapeHtml
  };
})();
