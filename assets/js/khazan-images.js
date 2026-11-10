/**
 * Khazan - image tools tab: next-gen conversion (WebP/AVIF), AI alt text and safe duplicate cleanup.
 * Every action runs as a loop of small REST batches, so progress is real and a slow host never times out.
 * Mixed into the dashboard app by wila-admin.js (methods run with `this` === app).
 */
(function () {
  'use strict';

  const { escapeHtml, toPersianDigits, formatNumber } = window.KhazanUtils;
  const fa = (n) => toPersianDigits(Math.round(Number(n) || 0));
  const bytes = (b) => {
    b = Number(b) || 0;
    if (b >= 1073741824) return fa(b / 1073741824 * 100 / 100) + ' گیگابایت';
    if (b >= 1048576) return toPersianDigits((b / 1048576).toFixed(1)) + ' مگابایت';
    return toPersianDigits((b / 1024).toFixed(0)) + ' کیلوبایت';
  };

  const methods = {
    loadImages: async function () {
      const im = this.state.images;
      try {
        const r = await this.apiFetch('/images/overview');
        if (!r.success) throw new Error(r.message || 'اطلاعات تصاویر دریافت نشد.');
        im.overview = r; im.error = '';
      } catch (e) { im.error = e.message; }
      this.render();
    },

    imagesLog: function (text) {
      const im = this.state.images;
      im.log.unshift(text);
      im.log = im.log.slice(0, 12);
    },

    // یک حلقه دسته‌ای: تا تمام‌شدن، خطا یا دکمه توقف
    runImagesLoop: async function (kind) {
      const im = this.state.images;
      if (im.busy) return;
      im.busy = kind; im.stop = false; im.error = '';
      const endpoint = { convert: '/images/convert-batch', alt: '/images/alt-batch', hash: '/images/dedupe/hash-batch' }[kind];
      let guard = 0;
      try {
        while (!im.stop && guard++ < 5000) {
          const r = await this.apiFetch(endpoint, { method: 'POST', body: '{}' });
          if (!r.success) throw new Error(r.message || 'خطا در پردازش.');
          if (kind === 'convert') this.imagesLog(`${fa(r.converted)} تصویر تبدیل شد، ${bytes(r.saved_bytes)} صرفه‌جویی` + (Object.keys(r.failed || {}).length ? ` (${fa(Object.keys(r.failed).length)} خطا)` : ''));
          if (kind === 'alt') (r.items || []).forEach(i => this.imagesLog(i.alt ? `متن جایگزین: ${i.alt}` : `ردشد: ${i.error}`));
          if (kind === 'hash') this.imagesLog(`${fa(r.done)} تصویر بررسی شد`);
          if (r.stopped) { im.error = r.stopped; break; }
          const empty = kind === 'hash' ? r.done === 0 : (kind === 'convert' ? r.processed === 0 : (r.items || []).length === 0);
          await this.loadImagesQuiet();
          this.render();
          if (empty || r.remaining === 0) break;
        }
      } catch (e) { im.error = e.message; }
      im.busy = '';
      if (kind === 'hash') await this.loadDuplicates();
      await this.loadImages();
    },

    loadImagesQuiet: async function () {
      try { const r = await this.apiFetch('/images/overview'); if (r.success) this.state.images.overview = r; } catch (e) {}
    },

    loadDuplicates: async function () {
      const im = this.state.images;
      try {
        const r = await this.apiFetch('/images/dedupe/report');
        if (!r.success) throw new Error(r.message || 'گزارش دریافت نشد.');
        im.dupes = r.groups; im.selected = {};
        r.groups.forEach(g => g.members.forEach(m => { if (m.status === 'removable') im.selected[m.id] = true; }));
      } catch (e) { im.error = e.message; }
    },

    removeDuplicates: async function () {
      const im = this.state.images;
      const ids = Object.keys(im.selected || {}).filter(id => im.selected[id]).map(Number);
      if (!ids.length) return this.toast('تصویری انتخاب نشده است.');
      if (!window.confirm(`${ids.length} تصویر تکراری و بدون استفاده حذف شود؟ از هر فایل یک نسخه پشتیبان در پوشه khazan-quarantine ذخیره می‌شود.`)) return;
      im.busy = 'dedupe-remove'; this.render();
      try {
        for (let i = 0; i < ids.length; i += 10) {
          const r = await this.apiFetch('/images/dedupe/remove', { method: 'POST', body: JSON.stringify({ ids: ids.slice(i, i + 10) }) });
          if (!r.success) throw new Error(r.message || 'حذف انجام نشد.');
          this.imagesLog(`${fa(r.removed.length)} تصویر حذف شد، ${bytes(r.bytes)} آزاد شد` + (Object.keys(r.skipped || {}).length ? `، ${fa(Object.keys(r.skipped).length)} مورد رد شد` : ''));
        }
      } catch (e) { im.error = e.message; }
      im.busy = '';
      await this.loadDuplicates();
      await this.loadImages();
    },

    saveImageSettings: async function () {
      const root = this.root;
      const val = (id) => root.querySelector(id);
      const body = {
        convert_on_upload: val('#khz-img-upload').checked,
        format: val('#khz-img-format').value,
        quality: Number(val('#khz-img-quality').value),
        delete_original: val('#khz-img-delete').checked,
        serve_next_gen: val('#khz-img-serve').checked,
        alt_on_upload: val('#khz-img-alt-upload').checked,
        alt_overwrite: val('#khz-img-alt-overwrite').checked,
        alt_model: val('#khz-img-alt-model').value.trim(),
      };
      try {
        const r = await this.apiFetch('/images/settings', { method: 'POST', body: JSON.stringify(body) });
        if (!r.success) throw new Error(r.message || 'ذخیره نشد.');
        this.toast('تنظیمات تصاویر ذخیره شد.');
        await this.loadImages();
      } catch (e) { this.toast('خطا: ' + e.message); }
    },

    viewImages: function () {
      const im = this.state.images;
      const o = im.overview;
      if (!o) return `<div class="wila-card"><div class="wila-skeleton" style="height:14px;width:40%"></div>${im.error ? `<p style="color:#991b1b">${escapeHtml(im.error)}</p>` : ''}</div>`;
      const s = o.settings, st = o.stats, p = o.pending;
      const busy = !!im.busy;
      const btn = (id, label, kind) => `<button class="wila-btn wila-btn-primary" id="${id}" ${busy ? 'disabled' : ''}>${im.busy === kind ? 'در حال پردازش…' : label}</button>`;
      const stop = busy && ['convert', 'alt', 'hash'].includes(im.busy) ? '<button class="wila-btn wila-btn-outline" id="khz-img-stop">توقف</button>' : '';
      const unsupported = !o.support[s.format] ? `<p style="color:#991b1b;margin:6px 0 0">سرور شما از ${s.format.toUpperCase()} پشتیبانی نمی‌کند.</p>` : '';

      let dupes = '';
      if (im.dupes) {
        dupes = im.dupes.length ? im.dupes.map(g => `
          <div style="border:1px solid #e2e8f0;border-radius:8px;padding:10px;margin:8px 0">
            ${g.members.map(m => `<label style="display:flex;gap:8px;align-items:center;margin:4px 0;font-size:0.8rem">
              <input type="checkbox" class="khz-dup-check" data-id="${m.id}" ${m.status === 'removable' ? (im.selected[m.id] ? 'checked' : '') : 'disabled'} />
              <span>${escapeHtml(m.title || ('#' + m.id))} — ${bytes(m.bytes)} —
                ${m.status === 'keep' ? '<b style="color:#047857">نگه‌داشته می‌شود</b>' : (m.status === 'used' ? '<b style="color:#b45309">در حال استفاده (حذف نمی‌شود)</b>' : '<b style="color:#991b1b">تکراری و بدون استفاده</b>')}
                ${m.refs ? ` (${fa(m.refs)} ارجاع)` : ''}</span></label>`).join('')}
          </div>`).join('') + `<button class="wila-btn wila-btn-primary" id="khz-dup-remove" ${busy ? 'disabled' : ''}>حذف موارد انتخاب‌شده</button>`
          : '<p style="color:#047857">تصویر تکراری پیدا نشد.</p>';
      }

      return `
        ${im.error ? `<div class="wila-card" style="border-color:#fecaca;color:#991b1b">${escapeHtml(im.error)}</div>` : ''}
        <div class="wila-card">
          <h3>گزارش</h3>
          <p>تبدیل‌شده: <b>${fa(st.converted)}</b> — صرفه‌جویی: <b>${bytes(st.saved_bytes)}</b> — متن جایگزین نوشته‌شده: <b>${fa(st.alt_written)}</b> — تکراری حذف‌شده: <b>${fa(st.duplicates_removed)}</b> (${bytes(st.duplicate_bytes)})</p>
        </div>

        <div class="wila-card">
          <h3>تنظیمات</h3>
          <label style="display:block;margin:6px 0"><input type="checkbox" id="khz-img-upload" ${s.convert_on_upload ? 'checked' : ''}/> تبدیل خودکار تصاویر جدید هنگام آپلود</label>
          <label style="display:block;margin:6px 0">فرمت: <select id="khz-img-format"><option value="webp" ${s.format === 'webp' ? 'selected' : ''}>WebP</option><option value="avif" ${s.format === 'avif' ? 'selected' : ''}>AVIF</option></select>
            کیفیت: <input type="number" id="khz-img-quality" min="30" max="100" value="${s.quality}" style="width:70px"/></label>
          ${unsupported}
          <label style="display:block;margin:6px 0"><input type="checkbox" id="khz-img-delete" ${s.delete_original ? 'checked' : ''}/> حذف فایل اصلی (فقط برای تصاویر جدید؛ تصاویر قدیمی هیچ‌وقت حذف نمی‌شوند)</label>
          <label style="display:block;margin:6px 0"><input type="checkbox" id="khz-img-serve" ${s.serve_next_gen ? 'checked' : ''}/> نمایش نسخه WebP/AVIF در صفحه‌ها وقتی فایلش وجود دارد</label>
          <label style="display:block;margin:6px 0"><input type="checkbox" id="khz-img-alt-upload" ${s.alt_on_upload ? 'checked' : ''}/> نوشتن خودکار متن جایگزین برای تصویر جدید (از اعتبار AvalAI هزینه دارد)</label>
          <label style="display:block;margin:6px 0"><input type="checkbox" id="khz-img-alt-overwrite" ${s.alt_overwrite ? 'checked' : ''}/> بازنویسی متن جایگزین‌های موجود (پیشنهاد نمی‌شود)</label>
          <label style="display:block;margin:6px 0">مدل بینایی برای متن جایگزین (خالی = مدل اصلی):
            <input type="text" id="khz-img-alt-model" value="${escapeHtml(s.alt_model)}" style="direction:ltr;text-align:left;width:260px"/></label>
          <button class="wila-btn wila-btn-outline" id="khz-img-save">ذخیره تنظیمات</button>
        </div>

        <div class="wila-card">
          <h3>تبدیل تصاویر قبلی</h3>
          <p>در انتظار تبدیل: <b>${fa(p.convert)}</b> تصویر. فایل‌های اصلی دست‌نخورده می‌مانند و ظاهر سایت خراب نمی‌شود.</p>
          ${btn('khz-img-convert', 'شروع تبدیل', 'convert')} ${stop}
        </div>

        <div class="wila-card">
          <h3>متن جایگزین تصاویر</h3>
          <p>بدون متن جایگزین: <b>${fa(p.alt)}</b> تصویر. متن موجود هیچ‌وقت بازنویسی نمی‌شود مگر در تنظیمات فعال کنید.</p>
          ${btn('khz-img-alt', 'شروع نوشتن متن جایگزین', 'alt')} ${stop}
        </div>

        <div class="wila-card">
          <h3>تصاویر تکراری</h3>
          <p>ابتدا همه تصاویر بررسی می‌شوند (${fa(p.hash)} مورد مانده)، سپس گزارش نمایش داده می‌شود. هیچ تصویری که در محتوا، گالری، دسته‌ها یا تنظیمات استفاده شده باشد حذف نمی‌شود.</p>
          ${btn('khz-img-hash', 'بررسی و ساخت گزارش', 'hash')} ${stop}
          ${dupes}
        </div>

        ${im.log.length ? `<div class="wila-card"><h3>گزارش عملیات</h3>${im.log.map(l => `<div style="font-size:0.8rem">${escapeHtml(l)}</div>`).join('')}</div>` : ''}
      `;
    },

    bindImagesEvents: function () {
      const q = (s) => this.root.querySelector(s);
      const on = (s, fn) => { const el = q(s); if (el) el.addEventListener('click', fn); };
      on('#khz-img-save', () => this.saveImageSettings());
      on('#khz-img-convert', () => this.runImagesLoop('convert'));
      on('#khz-img-alt', () => this.runImagesLoop('alt'));
      on('#khz-img-hash', () => this.runImagesLoop('hash'));
      on('#khz-img-stop', () => { this.state.images.stop = true; });
      on('#khz-dup-remove', () => this.removeDuplicates());
      this.root.querySelectorAll('.khz-dup-check').forEach(c => c.addEventListener('change', e => { this.state.images.selected[e.target.getAttribute('data-id')] = e.target.checked; }));
    },
  };

  window.KhazanImages = { methods: methods };
})();
