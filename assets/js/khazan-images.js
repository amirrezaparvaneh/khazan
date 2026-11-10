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

    // ---- پنل پیشرفت زنده (نوار درصد، تعداد، حجم، زمان سپری‌شده و زمان تقریبی باقی‌مانده) ----
    fmtDuration: function (sec) {
      sec = Math.max(0, Math.round(sec));
      if (sec < 60) return fa(sec) + ' ثانیه';
      const m = Math.floor(sec / 60), r = sec % 60;
      if (m < 60) return fa(m) + ' دقیقه' + (r ? ' و ' + fa(r) + ' ثانیه' : '');
      const h = Math.floor(m / 60);
      return fa(h) + ' ساعت و ' + fa(m % 60) + ' دقیقه';
    },

    progressParts: function () {
      const pr = this.state.images.progress;
      if (!pr) return { pct: 0, text: '' };
      const now = pr.end || Date.now();
      const elapsed = (now - pr.start) / 1000;
      const total = Math.max(pr.total, pr.done, 1);
      const pct = pr.finished ? 100 : Math.min(99, Math.round(pr.done / total * 100));
      let text = `${fa(pr.done)} از ${fa(pr.total)}` + (pr.bytes ? ` — ${bytes(pr.bytes)} آزاد/صرفه‌جویی شد` : '') + ` — زمان سپری‌شده: ${this.fmtDuration(elapsed)}`;
      if (!pr.finished && pr.done > 0 && elapsed >= 3 && pr.total > pr.done) {
        const rate = pr.done / elapsed;
        text += ` — زمان باقی‌مانده: حدود ${this.fmtDuration((pr.total - pr.done) / rate)}`;
      }
      return { pct, text };
    },

    startProgress: function (kind, label, total) {
      const im = this.state.images;
      im.progress = { kind, label, total: Number(total) || 0, done: 0, bytes: 0, start: Date.now(), end: 0, finished: false, summary: '' };
      if (this._khzTimer) clearInterval(this._khzTimer);
      this._khzTimer = setInterval(() => this.updateProgressDom(), 1000);
    },

    tickProgress: function (done, bytesDelta) {
      const pr = this.state.images.progress;
      if (!pr) return;
      pr.done += Number(done) || 0; pr.bytes += Number(bytesDelta) || 0;
      if (pr.done > pr.total) pr.total = pr.done;
      this.updateProgressDom();
    },

    finishProgress: function (summary) {
      const pr = this.state.images.progress;
      if (this._khzTimer) { clearInterval(this._khzTimer); this._khzTimer = null; }
      if (!pr) return;
      pr.finished = true; pr.end = Date.now(); pr.summary = summary;
    },

    updateProgressDom: function () {
      const fill = document.getElementById('khz-prog-fill'), txt = document.getElementById('khz-prog-text'), pc = document.getElementById('khz-prog-pct');
      if (!fill || !txt) return;
      const p = this.progressParts();
      fill.style.width = p.pct + '%'; txt.textContent = p.text; if (pc) pc.textContent = fa(p.pct) + '٪';
    },

    progressHtml: function () {
      const im = this.state.images, pr = im.progress;
      if (!pr) return '';
      const p = this.progressParts();
      const running = !pr.finished;
      return `<div class="wila-card khz-progress">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
          <h3 style="margin:0">${escapeHtml(pr.label)}${running ? '' : ' — ' + (pr.summary ? 'پایان' : '')}</h3>
          <b id="khz-prog-pct">${fa(p.pct)}٪</b>
        </div>
        <div class="wila-progress-bar-bg"><div class="wila-progress-bar-fill" id="khz-prog-fill" style="width:${p.pct}%"></div></div>
        <p id="khz-prog-text" style="font-size:0.85rem;margin:10px 0 0">${escapeHtml(p.text)}</p>
        ${running ? '<p style="font-size:0.75rem;margin:4px 0 0;opacity:.75">زمان باقی‌مانده تخمینی است و با پیشرفتِ کار دقیق‌تر می‌شود. صفحه را نبندید.</p><button class="wila-btn wila-btn-outline" id="khz-img-stop" style="margin-top:10px">توقف</button>'
          : `<p style="margin:10px 0 0;font-weight:600">${escapeHtml(pr.summary)}</p><button class="wila-btn wila-btn-outline" id="khz-prog-close" style="margin-top:10px">بستن</button>`}
      </div>`;
    },

    // یک حلقه دسته‌ای: تا تمام‌شدن، خطا یا دکمه توقف
    runImagesLoop: async function (kind) {
      const im = this.state.images;
      if (im.busy) return;
      im.busy = kind; im.stop = false; im.error = '';
      if (kind === 'hash') im.autoDone = false;
      const endpoint = { convert: '/images/convert-batch', shrink: '/images/shrink-batch', alt: '/images/alt-batch', hash: '/images/dedupe/hash-batch' }[kind];
      let guard = 0;
      const labels = { convert: 'تبدیل تصاویر', shrink: 'کاهش حجم تصاویر سنگین', alt: 'نوشتن متن جایگزین', hash: 'بررسی تصاویر برای یافتن تکراری‌ها' };
      this.startProgress(kind, labels[kind], (im.overview && im.overview.pending && im.overview.pending[kind]) || 0);
      this.render();
      try {
        while (!im.stop && guard++ < 5000) {
          const r = await this.apiFetch(endpoint, { method: 'POST', body: '{}' });
          if (!r.success) throw new Error(r.message || 'خطا در پردازش.');
          if (kind === 'convert') this.imagesLog(`${fa(r.converted)} تصویر تبدیل شد، ${bytes(r.saved_bytes)} صرفه‌جویی` + (Object.keys(r.failed || {}).length ? ` (${fa(Object.keys(r.failed).length)} خطا)` : ''));
          if (kind === 'shrink') this.imagesLog(`${fa(r.processed)} تصویر بررسی شد، ${fa(r.shrunk)} تصویر فشرده شد، ${bytes(r.saved_bytes)} صرفه‌جویی` + ((r.reasons || []).length ? ' — فایل‌های ردشده: ' + r.reasons.map(x => `${x.message} (${fa(x.count)})`).join('، ') : ''));
          if (kind === 'alt') (r.items || []).forEach(i => this.imagesLog(i.alt ? `متن جایگزین: ${i.alt}` : `ردشد: ${i.error}`));
          if (kind === 'hash') this.imagesLog(`${fa(r.done)} تصویر بررسی شد`);
          this.tickProgress(kind === 'hash' ? r.done : (kind === 'alt' ? (r.items || []).length : r.processed), (kind === 'convert' || kind === 'shrink') ? r.saved_bytes : 0);
          if (r.stopped) { im.error = r.stopped; break; }
          const empty = kind === 'hash' ? r.done === 0 : (kind === 'convert' || kind === 'shrink' ? r.processed === 0 : (r.items || []).length === 0);
          await this.loadImagesQuiet();
          this.render();
          if (empty || r.remaining === 0) break;
        }
      } catch (e) { im.error = e.message; }
      const pr = im.progress;
      this.finishProgress(pr ? `${im.stop ? 'متوقف شد' : (im.error ? 'با خطا متوقف شد' : 'تمام شد')}: ${fa(pr.done)} تصویر پردازش شد` + (pr.bytes ? `، ${bytes(pr.bytes)} صرفه‌جویی` : '') + `، زمان کل ${this.fmtDuration((Date.now() - pr.start) / 1000)}.` : '');
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
        im.dupes = r.groups; im.dupeSummary = r.summary || null; im.selected = {};
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

    // حذف خودکار: دسته‌دسته گزارش می‌گیرد و فقط «تکراری و بدون استفاده» را حذف می‌کند (بررسی کامل سمت سرور برای هر تصویر)
    removeAllDuplicates: async function () {
      const im = this.state.images;
      if (im.busy) return;
      if (!window.confirm('همه‌ی تصاویر «تکراری و بدون استفاده» به‌صورت خودکار حذف شوند؟ هر تصویر قبل از حذف دوباره بررسی می‌شود و نسخه‌ی پشتیبان در khazan-quarantine می‌ماند. با «توقف» می‌توانید هر لحظه متوقفش کنید.')) return;
      im.busy = 'dedupe-auto'; im.stop = false; im.error = ''; im.autoDone = false;
      let offset = 0, total = 0, bytesTotal = 0, guard = 0, started = false;
      this.render();
      try {
        while (!im.stop && guard++ < 2000) {
          const r = await this.apiFetch('/images/dedupe/report?' + (started ? 'summary=0&' : '') + 'offset=' + offset);
          if (!r.success) throw new Error(r.message || 'گزارش دریافت نشد.');
          if (!started) { started = true; this.startProgress('dedupe-auto', 'حذف خودکار تصاویر تکراری', r.summary ? r.summary.copies : 0); this.render(); }
          if (!r.groups.length) break;
          const ids = [];
          r.groups.forEach(g => g.members.forEach(m => { if (m.status === 'removable') ids.push(m.id); }));
          if (!ids.length) { offset += r.groups.length; continue; }
          let removedNow = 0;
          for (let i = 0; i < ids.length && !im.stop; i += 10) {
            const d = await this.apiFetch('/images/dedupe/remove', { method: 'POST', body: JSON.stringify({ ids: ids.slice(i, i + 10) }) });
            if (!d.success) throw new Error(d.message || 'حذف انجام نشد.');
            removedNow += d.removed.length; total += d.removed.length; bytesTotal += d.bytes || 0;
            this.tickProgress(d.removed.length, d.bytes || 0);
          }
          this.imagesLog(`تا الان ${fa(total)} تصویر حذف شد، ${bytes(bytesTotal)} آزاد شد`);
          if (!removedNow) offset += r.groups.length;
          this.render();
        }
      } catch (e) { im.error = e.message; }
      const pr = im.progress;
      im.autoDone = !im.stop && !im.error;
      this.finishProgress(pr ? `${im.stop ? 'متوقف شد' : (im.error ? 'با خطا متوقف شد' : 'حذف خودکار تمام شد')}: ${fa(total)} تصویر تکراری حذف شد و ${bytes(bytesTotal)} آزاد شد (نسخه‌ی پشتیبان در quarantine است)، زمان کل ${this.fmtDuration((Date.now() - pr.start) / 1000)}. موارد «در حال استفاده» دست نخورده‌اند.` : '');
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
        shrink_quality: Number(val('#khz-img-shrink-quality').value),
        shrink_min_kb: Number(val('#khz-img-shrink-min').value),
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
      const btn = (id, label, kind) => `<button class="wila-btn wila-btn-primary ${im.busy === kind ? 'is-busy' : ''}" id="${id}" ${busy ? 'disabled' : ''}>${im.busy === kind ? 'در حال پردازش…' : label}</button>`;
      const stop = '';
      const unsupported = !o.support[s.format] ? `<p style="color:#991b1b;margin:6px 0 0">سرور شما از ${s.format.toUpperCase()} پشتیبانی نمی‌کند.</p>` : '';

      let dupes = '';
      if (im.dupes) {
        const sm = im.dupeSummary;
        const note = im.autoDone && sm && sm.copies ? `<p style="color:#047857"><b>حذف خودکار انجام شده است:</b> هیچ تصویر «تکراری و بدون استفاده»ای باقی نمانده. ${fa(sm.copies)} نسخه‌ی باقی‌مانده در سایت (محتوا، گالری، دسته‌ها یا تنظیمات) استفاده شده‌اند و عمداً حذف نمی‌شوند.</p>` : '';
        const head = note + (sm && sm.groups ? `<p><b>${fa(sm.groups)}</b> گروه فایل هم‌محتوا (<b>${fa(sm.copies)}</b> نسخه‌ی اضافه، حدود <b>${bytes(sm.bytes)}</b>). این عدد فقط هم‌محتوا بودن را می‌شمارد؛ نسخه‌ای که در سایت استفاده شده باشد حذف نمی‌شود و فقط موارد «تکراری و بدون استفاده» (دارای تیک) پاک می‌شوند. برای سرعت، هر بار چند گروه اول نمایش داده می‌شود.</p>` : '');
        const removableCount = im.dupes.reduce((n, g) => n + g.members.filter(m => m.status === 'removable').length, 0);
        dupes = head + (im.dupes.length ? im.dupes.map(g => `
          <div style="border:1px solid #e2e8f0;border-radius:8px;padding:10px;margin:8px 0">
            ${g.members.map(m => `<label style="display:flex;gap:8px;align-items:center;margin:4px 0;font-size:0.8rem">
              <input type="checkbox" class="khz-dup-check" data-id="${m.id}" ${m.status === 'removable' ? (im.selected[m.id] ? 'checked' : '') : 'disabled'} />
              <span>${escapeHtml(m.title || ('#' + m.id))} — ${bytes(m.bytes)} —
                ${m.status === 'keep' ? '<b style="color:#047857">نگه‌داشته می‌شود</b>' : (m.status === 'used' ? '<b style="color:#b45309">در حال استفاده (حذف نمی‌شود)</b>' : '<b style="color:#991b1b">تکراری و بدون استفاده</b>')}
                ${m.refs ? ` (${fa(m.refs)} ارجاع)` : ''}</span></label>`).join('')}
          </div>`).join('') + (removableCount ? `<button class="wila-btn wila-btn-primary" id="khz-dup-remove" ${busy ? 'disabled' : ''}>حذف موارد انتخاب‌شده</button>` : '<p style="color:#047857">در گروه‌های نمایش‌داده‌شده هیچ مورد «تکراری و بدون استفاده»ای نیست؛ چیزی برای حذف باقی نمانده.</p>')
          : '<p style="color:#047857">تصویر تکراری پیدا نشد.</p>');
      }

      return `
        ${im.error ? `<div class="wila-card" style="border-color:#fecaca;color:#991b1b">${escapeHtml(im.error)}</div>` : ''}
        ${this.progressHtml()}
        <div class="wila-card">
          <h3>گزارش</h3>
          <p>تبدیل‌شده: <b>${fa(st.converted)}</b> — صرفه‌جویی: <b>${bytes(st.saved_bytes)}</b> — متن جایگزین نوشته‌شده: <b>${fa(st.alt_written)}</b> — تکراری حذف‌شده: <b>${fa(st.duplicates_removed)}</b> (${bytes(st.duplicate_bytes)}) — فشرده‌شده: <b>${fa(st.shrunk)}</b> (${bytes(st.shrunk_bytes)} صرفه‌جویی)</p>
        </div>

        <div class="wila-card">
          <h3>تنظیمات</h3>
          <label style="display:block;margin:6px 0"><input type="checkbox" id="khz-img-upload" ${s.convert_on_upload ? 'checked' : ''}/> تبدیل خودکار تصاویر جدید هنگام آپلود</label>
          <label style="display:block;margin:6px 0">فرمت: <select id="khz-img-format"><option value="webp" ${s.format === 'webp' ? 'selected' : ''}>WebP</option><option value="avif" ${s.format === 'avif' ? 'selected' : ''}>AVIF</option></select>
            کیفیت: <input type="number" id="khz-img-quality" min="30" max="100" value="${s.quality}" style="width:70px"/></label>
          ${unsupported}
          <label style="display:block;margin:6px 0"><input type="checkbox" id="khz-img-delete" ${s.delete_original ? 'checked' : ''}/> حذف فایل اصلی (فقط برای تصاویر جدید؛ تصاویر قدیمی هیچ‌وقت حذف نمی‌شوند)</label>
          <label style="display:block;margin:6px 0"><input type="checkbox" id="khz-img-serve" ${s.serve_next_gen ? 'checked' : ''}/> نمایش نسخه WebP/AVIF در صفحه‌ها وقتی فایلش وجود دارد</label>
          <div class="khz-fields">
            <label class="khz-field">کیفیت فشرده‌سازی تصاویر سنگین (۵۰ تا ۹۵)<input type="number" id="khz-img-shrink-quality" min="50" max="95" value="${s.shrink_quality}"/></label>
            <label class="khz-field">فقط فایل‌های سنگین‌تر از (کیلوبایت)<input type="number" id="khz-img-shrink-min" min="50" max="20000" value="${s.shrink_min_kb}"/></label>
          </div>
          <label style="display:block;margin:6px 0"><input type="checkbox" id="khz-img-alt-upload" ${s.alt_on_upload ? 'checked' : ''}/> نوشتن خودکار متن جایگزین برای تصویر جدید (از اعتبار AvalAI هزینه دارد)</label>
          <label style="display:block;margin:6px 0"><input type="checkbox" id="khz-img-alt-overwrite" ${s.alt_overwrite ? 'checked' : ''}/> بازنویسی متن جایگزین‌های موجود (پیشنهاد نمی‌شود)</label>
          <label style="display:block;margin:6px 0">مدل بینایی برای متن جایگزین (خالی = مدل اصلی):
            <input type="text" id="khz-img-alt-model" value="${escapeHtml(s.alt_model)}" class="khz-ltr" style="max-width:320px"/></label>
          <button class="wila-btn wila-btn-outline" id="khz-img-save">ذخیره تنظیمات</button>
        </div>

        <div class="wila-card">
          <h3>تبدیل تصاویر قبلی</h3>
          <p>در انتظار تبدیل: <b>${fa(p.convert)}</b> تصویر. فایل‌های اصلی دست‌نخورده می‌مانند و ظاهر سایت خراب نمی‌شود.</p>
          ${btn('khz-img-convert', 'شروع تبدیل', 'convert')} ${stop}
        </div>

        <div class="wila-card">
          <h3>کاهش حجم تصاویر سنگین</h3>
          <p>در انتظار بررسی: <b>${fa(p.shrink)}</b> تصویر. هر تصویر سنگین (و همه‌ی اندازه‌های ساخته‌شده‌اش) با همان نام، همان فرمت و همان ابعاد دوباره فشرده می‌شود، پس هیچ لینکی خراب نمی‌شود. فقط وقتی نتیجه حداقل ۱۰٪ کوچک‌تر باشد جایگزین می‌شود. <b>نسخه‌ی اصلی نگه داشته نمی‌شود</b> (برای همین فضا آزاد می‌شود). هر تصویر فقط یک بار فشرده می‌شود. ابتدا روی چند تصویر امتحان کنید و با «توقف» متوقفش کنید.</p>
          ${p.shrink_crashed ? `<p style="color:#b45309"><b>${fa(p.shrink_crashed)}</b> تصویر وسط کار با خطا (معمولاً کمبود حافظه‌ی هاست) مواجه شد و برای جلوگیری از تکرار خطا رد شد؛ آن‌ها را دستی یا با کم‌کردن ابعاد بهینه کنید.</p>` : ''}
          ${btn('khz-img-shrink', 'شروع کاهش حجم', 'shrink')} ${stop}
        </div>

        <div class="wila-card">
          <h3>متن جایگزین تصاویر</h3>
          <p>بدون متن جایگزین: <b>${fa(p.alt)}</b> تصویر. متن موجود هیچ‌وقت بازنویسی نمی‌شود مگر در تنظیمات فعال کنید.</p>
          ${btn('khz-img-alt', 'شروع نوشتن متن جایگزین', 'alt')} ${stop}
        </div>

        <div class="wila-card">
          <h3>تصاویر تکراری</h3>
          <p>ابتدا همه تصاویر بررسی می‌شوند (${fa(p.hash)} مورد مانده)، سپس گزارش نمایش داده می‌شود. هیچ تصویری که در محتوا، گالری، دسته‌ها یا تنظیمات استفاده شده باشد حذف نمی‌شود.</p>
          ${btn('khz-img-hash', 'بررسی و ساخت گزارش', 'hash')} ${btn('khz-dup-auto', 'حذف خودکار همه تکراری‌های بدون استفاده', 'dedupe-auto')} ${stop}
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
      on('#khz-img-shrink', () => { if (window.confirm('تصاویر سنگین دوباره فشرده می‌شوند و نسخه‌ی اصلی نگه داشته نمی‌شود. ادامه می‌دهید؟')) this.runImagesLoop('shrink'); });
      on('#khz-img-alt', () => this.runImagesLoop('alt'));
      on('#khz-img-hash', () => this.runImagesLoop('hash'));
      on('#khz-img-stop', () => { this.state.images.stop = true; });
      on('#khz-prog-close', () => { this.state.images.progress = null; this.render(); });
      on('#khz-dup-remove', () => this.removeDuplicates());
      on('#khz-dup-auto', () => this.removeAllDuplicates());
      this.root.querySelectorAll('.khz-dup-check').forEach(c => c.addEventListener('change', e => { this.state.images.selected[e.target.getAttribute('data-id')] = e.target.checked; }));
    },
  };

  window.KhazanImages = { methods: methods };
})();
