/**
 * Khazan - review & repair tab: stored products, categories and articles are checked against the standards and
 * repaired in place (free plugin fixes first, AI text patches only for what is left). Every save keeps a backup.
 * Mixed into the dashboard app by wila-admin.js (methods run with `this` === app).
 */
(function () {
  'use strict';

  const { escapeHtml, toPersianDigits } = window.KhazanUtils;
  const fa = (n) => toPersianDigits(Math.round(Number(n) || 0));
  const KIND_LABELS = { product: 'محصول', product_cat: 'دسته‌بندی', article: 'مقاله' };

  function initialState() {
    return { kind: 'product', page: 1, search: '', data: null, loading: false, error: '',
      preview: null, previewBusy: false, applyBusy: false, useAi: false, instructions: '',
      bulk: { running: false, stop: false, done: 0, total: 0, changed: 0, failed: 0, ai: false, last: '' } };
  }

  const methods = {
    loadFix: async function () {
      const f = this.state.fix;
      f.loading = true; f.error = ''; this.render();
      try {
        const r = await this.apiFetch(`/fix/items?kind=${encodeURIComponent(f.kind)}&page=${f.page}&search=${encodeURIComponent(f.search)}`);
        if (!r.success) throw new Error(r.message || 'فهرست دریافت نشد.');
        f.data = r;
      } catch (e) { f.error = e.message; f.data = null; }
      f.loading = false; this.render();
    },

    previewFix: async function (id, withAi) {
      const f = this.state.fix;
      f.previewBusy = true;
      if (!f.preview || f.preview.id !== id) f.preview = { id, loading: true };
      this.render();
      try {
        const r = await this.apiFetch('/fix/preview', { method: 'POST', body: JSON.stringify({ kind: f.kind, id, ai: !!withAi, instructions: withAi ? f.instructions : '' }) });
        if (!r.success) throw new Error(r.message || 'بررسی انجام نشد.');
        f.preview = r;
        if (withAi && r.ai_error) this.toast('هوش مصنوعی: ' + r.ai_error);
      } catch (e) { f.preview = null; this.toast('خطا: ' + e.message); }
      f.previewBusy = false; this.render();
    },

    applyFix: async function () {
      const f = this.state.fix, p = f.preview;
      if (!p || !p.draft_id || f.applyBusy) return;
      f.applyBusy = true; this.render();
      try {
        const r = await this.apiFetch('/fix/apply', { method: 'POST', body: JSON.stringify({ draft_id: p.draft_id }) });
        if (!r.success) throw new Error(r.message || 'ذخیره انجام نشد.');
        this.toast('اصلاح‌ها ذخیره شد. نسخه قبلی برای بازگردانی نگه داشته شد.');
        f.preview = null;
        f.applyBusy = false;
        return this.loadFix();
      } catch (e) { this.toast('خطا: ' + e.message); }
      f.applyBusy = false; this.render();
    },

    restoreFix: async function (id) {
      const f = this.state.fix;
      if (!confirm('محتوای ذخیره‌شده قبل از آخرین اصلاح برگردانده شود؟')) return;
      try {
        const r = await this.apiFetch('/fix/restore', { method: 'POST', body: JSON.stringify({ kind: f.kind, id }) });
        if (!r.success) throw new Error(r.message || 'بازگردانی انجام نشد.');
        this.toast('نسخه قبلی برگردانده شد.');
        if (f.preview && f.preview.id === id) f.preview = null;
        this.loadFix();
      } catch (e) { this.toast('خطا: ' + e.message); }
    },

    runFixBulk: async function () {
      const f = this.state.fix, b = f.bulk;
      if (b.running) return;
      const label = KIND_LABELS[f.kind];
      const ai = !!f.useAi;
      if (!confirm(`همه ${label}ها بررسی و ایرادهایشان ${ai ? 'با افزونه و هوش مصنوعی (هزینه دارد)' : 'فقط با خود افزونه (بدون هزینه)'} اصلاح و ذخیره می‌شود. برای هر صفحه نسخه پشتیبان نگه داشته می‌شود. ادامه می‌دهید؟`)) return;
      let ids = [];
      try {
        const r = await this.apiFetch(`/fix/ids?kind=${encodeURIComponent(f.kind)}`);
        ids = r.ids || [];
      } catch (e) { return this.toast('خطا: ' + e.message); }
      Object.assign(b, { running: true, stop: false, done: 0, total: ids.length, changed: 0, failed: 0, ai, last: '' });
      this.render();
      for (const id of ids) {
        if (b.stop) break;
        try {
          const r = await this.apiFetch('/fix/run', { method: 'POST', body: JSON.stringify({ kind: f.kind, id, ai }) });
          if (!r.success) throw new Error(r.message || '');
          if (r.changed) b.changed++;
          b.last = r.title || '';
        } catch (e) { b.failed++; }
        b.done++;
        this.renderFixProgress();
      }
      b.running = false;
      this.toast(b.stop ? 'اجرای گروهی متوقف شد.' : `بررسی تمام شد: ${fa(b.changed)} صفحه اصلاح شد.`);
      this.loadFix();
    },

    renderFixProgress: function () {
      const el = this.root && this.root.querySelector('#khz-fix-progress');
      if (el) el.outerHTML = this.fixProgressHtml();
      else this.render();
      const stop = this.root && this.root.querySelector('#khz-fix-stop');
      if (stop) stop.addEventListener('click', () => { this.state.fix.bulk.stop = true; stop.disabled = true; });
    },

    fixProgressHtml: function () {
      const b = this.state.fix.bulk;
      if (!b.running && !b.total) return '<div id="khz-fix-progress"></div>';
      const pct = b.total ? Math.round(b.done * 100 / b.total) : 0;
      return `<div id="khz-fix-progress" role="status" style="margin-top:12px">
        <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden"><div style="height:100%;width:${pct}%;background:#16a34a;transition:width .2s"></div></div>
        <p style="font-size:.82rem;color:#475569;margin:6px 0 0">${fa(b.done)} از ${fa(b.total)} بررسی شد؛ ${fa(b.changed)} صفحه اصلاح شد${b.failed ? `، ${fa(b.failed)} خطا` : ''}${b.running && b.last ? ` — آخرین: ${escapeHtml(b.last)}` : ''}</p>
        ${b.running ? '<button type="button" class="wila-btn wila-btn-outline wila-btn-sm" id="khz-fix-stop" style="margin-top:6px">توقف</button>' : ''}
      </div>`;
    },

    viewFix: function () {
      const f = this.state.fix, d = f.data, p = f.preview, b = f.bulk;
      const label = KIND_LABELS[f.kind];
      const list = (arr, empty) => arr && arr.length ? `<ul style="margin:6px 0 0;padding-inline-start:18px;font-size:.82rem;line-height:1.9">${arr.map(x => `<li>${escapeHtml(x)}</li>`).join('')}</ul>` : `<p style="font-size:.82rem;color:#16a34a;margin:6px 0 0">${empty}</p>`;
      const rows = d && d.items.length ? d.items.map(it => `
        <tr>
          <td><a href="${escapeHtml(it.url)}" target="_blank" rel="noopener">${escapeHtml(it.title)}</a>${it.focus ? `<div style="font-size:.75rem;color:#64748b">${escapeHtml(it.focus)}</div>` : ''}</td>
          <td>${it.empty ? '<span class="wila-badge wila-badge-neutral">بدون محتوا</span>' : it.issues ? `<span class="wila-badge wila-badge-warning" title="${escapeHtml(it.issue_list.join('\n'))}">${fa(it.issues)} ایراد</span>` : '<span class="wila-badge wila-badge-success">سالم</span>'}</td>
          <td style="white-space:nowrap">
            ${it.empty ? '' : `<button type="button" class="wila-btn wila-btn-outline wila-btn-sm" data-fix-preview="${it.id}" ${f.previewBusy || b.running ? 'disabled' : ''}>بررسی و اصلاح</button>`}
            ${it.backup ? `<button type="button" class="wila-btn wila-btn-outline wila-btn-sm" data-fix-restore="${it.id}" ${b.running ? 'disabled' : ''}>بازگردانی</button>` : ''}
          </td>
        </tr>
        ${p && p.id === it.id ? `<tr><td colspan="3">${this.fixPreviewHtml(list)}</td></tr>` : ''}`).join('') : '';
      return `
        <div class="wila-card">
          <h3 style="margin-top:0">بازبینی و رفع ایراد محتوا</h3>
          <p style="color:#64748b;font-size:.85rem;line-height:1.9">محتوای ذخیره‌شده با همان استانداردهای تولید محتوا بررسی می‌شود. اول خود افزونه بدون هزینه لینک‌ها، انکرها، کلمه کلیدی، جمله‌های مجهول و تکرارها را اصلاح می‌کند. اگر ایرادی ماند، فقط همان جمله‌ها به هوش مصنوعی فرستاده می‌شوند، نه کل متن. قبل از هر ذخیره، نسخه فعلی نگه داشته می‌شود.</p>
          ${this.radioSwitch('khz_fix_kind', [['product', 'محصول'], ['product_cat', 'دسته‌بندی محصول'], ['article', 'مقاله']], f.kind, 'khz-fix-kind', 'data-kind', b.running)}
          <div style="margin-top:14px;padding:12px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc">
            <strong style="font-size:.9rem">اصلاح گروهی همه ${label}ها</strong>
            <label style="display:flex;gap:8px;align-items:center;margin:8px 0;font-size:.85rem"><input type="checkbox" id="khz-fix-ai" ${f.useAi ? 'checked' : ''} ${b.running ? 'disabled' : ''}> ایرادهای باقی‌مانده را هم با هوش مصنوعی اصلاح کن (فقط جمله‌های معیوب؛ هزینه دارد)</label>
            <button type="button" class="wila-btn wila-btn-primary" id="khz-fix-bulk" ${b.running ? 'disabled' : ''}>${b.running ? 'در حال اصلاح…' : `اصلاح خودکار همه ${label}ها`}</button>
            ${this.fixProgressHtml()}
          </div>
          <div style="display:flex;gap:8px;margin-top:16px">
            <label for="khz-fix-search" class="khazan-sr-only">جستجو</label>
            <input id="khz-fix-search" type="search" class="wila-input" style="flex:1" placeholder="جستجو در ${label}ها…" value="${escapeHtml(f.search)}">
          </div>
          ${f.error ? `<p style="color:#991b1b">${escapeHtml(f.error)}</p>` : ''}
          ${f.loading && !d ? '<div class="wila-skeleton" style="height:14px;margin-top:14px"></div><div class="wila-skeleton" style="height:14px;margin-top:8px"></div>' : ''}
          ${d ? `<div class="wila-table-scroll" style="margin-top:12px"><table class="wila-table"><thead><tr><th>${label}</th><th>وضعیت</th><th>اقدام</th></tr></thead><tbody>${rows || `<tr><td colspan="3">موردی پیدا نشد.</td></tr>`}</tbody></table></div>
            <div style="display:flex;gap:8px;align-items:center;margin-top:10px;font-size:.82rem">
              <button type="button" class="wila-btn wila-btn-outline wila-btn-sm" id="khz-fix-prev" ${d.page <= 1 || f.loading ? 'disabled' : ''}>قبلی</button>
              <span>صفحه ${fa(d.page)} از ${fa(d.pages)} (${fa(d.total)} ${label})</span>
              <button type="button" class="wila-btn wila-btn-outline wila-btn-sm" id="khz-fix-next" ${d.page >= d.pages || f.loading ? 'disabled' : ''}>بعدی</button>
            </div>` : ''}
        </div>`;
    },

    fixPreviewHtml: function (list) {
      const f = this.state.fix, p = f.preview;
      if (p.loading) return '<p role="status" style="font-size:.85rem">در حال بررسی…</p>';
      return `<div style="padding:10px;border:1px solid #e2e8f0;border-radius:8px;background:#fff">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">
          <div><strong style="font-size:.85rem">ایرادهای فعلی (${fa(p.before.length)})</strong>${list(p.before, 'ایرادی پیدا نشد.')}</div>
          <div><strong style="font-size:.85rem">بعد از اصلاح (${fa(p.after.length)})</strong>${list(p.after, 'همه ایرادها رفع می‌شود.')}</div>
        </div>
        <div style="margin-top:10px"><strong style="font-size:.85rem">تغییرهایی که اعمال می‌شود</strong>${list(p.changes, 'تغییری لازم نیست.')}</div>
        ${p.after.length ? `
          <label for="khz-fix-instr" style="display:block;margin:12px 0 4px;font-size:.82rem;font-weight:600">توضیح اضافه برای هوش مصنوعی (اختیاری)</label>
          <textarea id="khz-fix-instr" class="wila-input" rows="2" style="width:100%" placeholder="مثلاً خطای Yoast را اینجا بچسبانید">${escapeHtml(f.instructions)}</textarea>
          <button type="button" class="wila-btn wila-btn-outline wila-btn-sm" id="khz-fix-ai-run" style="margin-top:6px" ${f.previewBusy ? 'disabled' : ''}>${f.previewBusy ? 'در حال اصلاح…' : 'اصلاح باقی‌مانده با هوش مصنوعی'}</button>` : ''}
        <details style="margin-top:10px"><summary style="font-size:.82rem">نمایش متن اصلاح‌شده</summary><div style="max-height:360px;overflow:auto;border:1px solid #e2e8f0;border-radius:6px;padding:10px;margin-top:6px;font-size:.85rem;line-height:1.9">${p.html}</div></details>
        <div style="display:flex;gap:8px;margin-top:12px">
          <button type="button" class="wila-btn wila-btn-primary" id="khz-fix-apply" ${!p.changed || f.applyBusy ? 'disabled' : ''}>${f.applyBusy ? 'در حال ذخیره…' : p.changed ? 'ذخیره اصلاح‌ها' : 'تغییری برای ذخیره نیست'}</button>
          <button type="button" class="wila-btn wila-btn-outline" id="khz-fix-close">بستن</button>
        </div>
      </div>`;
    },

    enterFixTab: function () {
      if (!this.state.fix.data && !this.state.fix.loading) this.loadFix();
    },

    bindFixEvents: function () {
      if (this.state.activeTab !== 'fix') return;
      const f = this.state.fix;
      const on = (s, fn) => { const el = this.root.querySelector(s); if (el) el.addEventListener('click', fn); };
      this.root.querySelectorAll('.khz-fix-kind').forEach(el => el.addEventListener('change', e => {
        f.kind = e.currentTarget.getAttribute('data-kind'); f.page = 1; f.preview = null; f.data = null;
        f.bulk = initialState().bulk;
        this.loadFix();
      }));
      const search = this.root.querySelector('#khz-fix-search');
      if (search) search.addEventListener('input', e => {
        clearTimeout(this.fixSearchTimer);
        f.search = e.target.value.trim();
        this.fixSearchTimer = setTimeout(() => { f.page = 1; this.loadFix(); }, 400);
      });
      const ai = this.root.querySelector('#khz-fix-ai');
      if (ai) ai.addEventListener('change', e => { f.useAi = e.target.checked; });
      const instr = this.root.querySelector('#khz-fix-instr');
      if (instr) instr.addEventListener('input', e => { f.instructions = e.target.value; });
      on('#khz-fix-bulk', () => this.runFixBulk());
      on('#khz-fix-stop', (e) => { f.bulk.stop = true; e.currentTarget.disabled = true; });
      on('#khz-fix-prev', () => { f.page = Math.max(1, f.page - 1); f.preview = null; this.loadFix(); });
      on('#khz-fix-next', () => { f.page += 1; f.preview = null; this.loadFix(); });
      on('#khz-fix-apply', () => this.applyFix());
      on('#khz-fix-close', () => { f.preview = null; this.render(); });
      on('#khz-fix-ai-run', () => f.preview && this.previewFix(f.preview.id, true));
      this.root.querySelectorAll('[data-fix-preview]').forEach(el => el.addEventListener('click', () => this.previewFix(Number(el.getAttribute('data-fix-preview')), false)));
      this.root.querySelectorAll('[data-fix-restore]').forEach(el => el.addEventListener('click', () => this.restoreFix(Number(el.getAttribute('data-fix-restore')))));
    }
  };

  window.KhazanFix = { methods: methods, initialState: initialState };
})();
