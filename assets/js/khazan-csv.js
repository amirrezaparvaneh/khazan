/**
 * Khazan - CSV sheet parsing (pure functions, no DOM / no app state).
 * Author: امیــــــــررضا پـروانه
 */
(function () {
  'use strict';

  function parse(text) {
      text = String(text).replace(/^﻿/, '');
      // جداکننده از چند خط نخست تشخیص داده می‌شود تا ردیف اول خالی یا بی‌هدر گمراه‌کننده نباشد
      const sample = text.split(/\r?\n/).slice(0, 8).join('\n');
      const count = d => (sample.match(new RegExp(d === '\t' ? '\t' : '\\' + d, 'g')) || []).length;
      const delim = [',', ';', '\t'].sort((a, b) => count(b) - count(a))[0];
      const rows = []; let row = [], cell = '', quoted = false;
      for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (quoted) {
          if (ch === '"' && text[i + 1] === '"') { cell += '"'; i++; }
          else if (ch === '"') quoted = false;
          else cell += ch;
        } else if (ch === '"') quoted = true;
        else if (ch === delim) { row.push(cell); cell = ''; }
        else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text[i + 1] === '\n') i++; row.push(cell); rows.push(row); row = []; cell = ''; }
        else cell += ch;
      }
      if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
      return rows.filter(r => r.some(c => String(c).trim() !== ''));
  }

  function normalizeHeader(header) {
      return String(header == null ? '' : header)
        .replace(/[\u{1F300}-\u{1FAFF}☀-➿️]/gu, '')
        .replace(/[‌‏‎]/g, ' ')
        .replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[()（）\[\]]/g, ' ')
        .replace(/\s+/g, ' ').trim().toLowerCase();
  }

    // تطبیق زیررشته‌ای هدر؛ ترتیب قاعده‌ها مهم است (مثلاً «توضیحات متا» پیش از «توضیحات»)
  function mapHeader(header) {
      const h = normalizeHeader(header);
      if (!h) return null;
      const has = (...words) => words.some(w => h.includes(w));
      if (has('عنوان سئو')) return 'seo_title';
      if (has('توضیحات متا')) return 'meta_description';
      if (has('تایید', 'تأیید', 'approved')) return 'approved';
      if (has('نام محصول')) return 'name';
      if (has('لینک محصول')) return 'product_url';
      if (has('نامک', 'slug')) return 'slug';
      if (has('کلیدواژه کانونی', 'focus')) return 'focus_keyword';
      if (has('مترادف', 'related')) return 'related_keywords';
      if (has('یادداشت')) return 'notes';
      if (has('زیره')) return 'sole';
      if (has('رویه')) return 'upper'; // ستون ترکیبی «رویه، آستر و کفی» هم همین‌جا می‌آید
      if (has('آستر', 'کفی')) return 'lining';
      if (has('ویژگی های کلی', 'ویژگی کلی')) return 'general';
      if (has('توضیحات')) return 'description';
      return null;
  }

    // ردیف هدر را در ۵ ردیف نخست پیدا می‌کند (ردیف‌های خالی قبلاً حذف شده‌اند)
  function findHeaderRow(table) {
      for (let i = 0; i < Math.min(5, table.length); i++) {
        const map = table[i].map(h => mapHeader(h));
        if (map.includes('name') || map.includes('slug') || map.includes('product_url')) return { index: i, map };
      }
      return null;
  }

  function isApproved(value) {
      const v = String(value || '').trim().toLowerCase();
      return v !== '' && (/✅|✔|✓|☑/.test(v) || ['true', '1', 'yes', 'y', 'x', 'بله', 'تایید', 'تأیید', 'تایید شده'].includes(v));
  }

  window.KhazanCsv = {
    parse: parse,
    normalizeHeader: normalizeHeader,
    mapHeader: mapHeader,
    findHeaderRow: findHeaderRow,
    isApproved: isApproved
  };
})();
