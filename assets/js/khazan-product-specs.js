/**
 * Khazan - quick product attributes manager: searchable/paginated product table with the
 * technical-spec fields (sole, upper leather, lining, stitching) the AI prompt must treat as
 * ground truth and is forbidden from guessing. Per-row AJAX save without a page reload.
 * Mixed into the dashboard app by wila-admin.js (methods run with `this` === app).
 * Author: امیــــــــررضا پـروانه
 */
(function () {
  'use strict';

  const { escapeHtml, toPersianDigits } = window.KhazanUtils;

  const FIELD_ORDER = ['sole_material', 'upper_material', 'lining_material', 'stitching_type', 'other_features'];
  const FIELD_PLACEHOLDERS = {
    sole_material: 'مثلاً: پی‌یو (PU) استاندارد',
    upper_material: 'مثلاً: چرم طبیعی گاوی',
    lining_material: 'مثلاً: آستر پارچه‌ای تنفس‌پذیر',
    stitching_type: 'مثلاً: دوخت دستی',
    other_features: 'مثلاً: سبک و ضدلغزش'
  };

  const methods = {
    // کپی محلی مقادیر فعلی برای ویرایش ردیف‌ها بدون دستکاری مستقیم state دریافتی از سرور
    syncProductSpecsDrafts: function () {
      const ps = this.state.productSpecs;
      ps.items.forEach(item => {
        if (!ps.drafts[item.id]) ps.drafts[item.id] = {};
        FIELD_ORDER.forEach(key => {
          if (ps.drafts[item.id][key] === undefined) ps.drafts[item.id][key] = item.specs[key] || '';
        });
      });
    },

    loadProductSpecs: async function () {
      const ps = this.state.productSpecs;
      ps.loading = true; ps.error = '';
      this.render();
      try {
        const q = `search=${encodeURIComponent(ps.search || '')}&page=${ps.page}&per_page=${ps.perPage}`;
        const r = await this.apiFetch(`/product-specs?${q}`);
        if (!r.success) throw new Error(r.message || 'فهرست محصولات دریافت نشد.');
        ps.items = r.items || [];
        ps.total = Number(r.total || 0);
        ps.hasMore = !!r.has_more;
        ps.fields = r.fields || ps.fields;
        ps.loaded = true;
        this.syncProductSpecsDrafts();
        if (!ps.sheetEmail) { try { const st = await this.apiFetch('/product-specs/sheet/status'); if (st.success) ps.sheetEmail = st.email || ''; } catch (e) { /* only a hint */ } }
      } catch (e) { ps.items = []; ps.error = e.message; ps.loaded = true; }
      ps.loading = false;
      this.render();
    },

    searchProductSpecs: function (term) {
      const ps = this.state.productSpecs;
      ps.search = term; ps.page = 1;
      this.loadProductSpecs();
    },

    changeProductSpecsPage: function (delta) {
      const ps = this.state.productSpecs;
      if (ps.loading) return;
      const next = ps.page + delta;
      if (next < 1 || (delta > 0 && !ps.hasMore)) return;
      ps.page = next;
      this.loadProductSpecs();
    },

    saveProductSpecsRow: async function (productId) {
      const app = this;
      const ps = this.state.productSpecs;
      if (ps.saving[productId]) return;
      ps.saving[productId] = true;
      this.render();
      try {
        const r = await this.apiFetch('/product-specs/save', { method: 'POST', body: JSON.stringify({ product_id: productId, specs: ps.drafts[productId] || {} }) });
        if (!r.success) throw new Error(r.message || 'ذخیره مشخصات انجام نشد.');
        const item = ps.items.find(i => i.id === productId);
        if (item) item.specs = r.specs || {};
        app.toast('مشخصات محصول ذخیره شد.');
      } catch (e) { app.toast('خطا: ' + e.message); }
      ps.saving[productId] = false;
      this.render();
    },


    // ---- import from a Google Sheet / CSV ----

    sheetReset: function () {
      this.state.productSpecs.sheet = { open: true, link: '', csvText: '', csvName: '', step: 'source', busy: '', error: '', data: null, mapping: null, fillSimilar: true, preview: null, applied: null };
    },

    sheetLoad: async function () {
      const sh = this.state.productSpecs.sheet;
      if (sh.busy) return;
      if (!sh.link.trim() && !sh.csvText) { sh.error = 'لینک گوگل‌شیت را بنویسید یا فایل CSV را انتخاب کنید.'; return this.render(); }
      sh.busy = 'load'; sh.error = ''; this.render();
      try {
        const r = await this.apiFetch('/product-specs/sheet/load', { method: 'POST', body: JSON.stringify({ link: sh.link.trim(), csv: sh.csvText }) });
        if (!r.success) throw new Error(r.message || 'شیت خوانده نشد.');
        sh.data = r; sh.mapping = JSON.parse(JSON.stringify(r.mapping)); sh.step = 'mapping';
      } catch (e) { sh.error = e.message; }
      sh.busy = ''; this.render();
    },

    sheetPreview: async function () {
      const sh = this.state.productSpecs.sheet;
      if (sh.busy) return;
      sh.busy = 'preview'; sh.error = ''; this.render();
      try {
        const r = await this.apiFetch('/product-specs/sheet/preview', { method: 'POST', body: JSON.stringify({ sheet_id: sh.data.sheet_id, mapping: sh.mapping, fill_similar: sh.fillSimilar }) });
        if (!r.success) throw new Error(r.message || 'پیش‌نمایش ساخته نشد.');
        sh.preview = r; sh.step = 'preview';
      } catch (e) { sh.error = e.message; }
      sh.busy = ''; this.render();
    },

    sheetApply: async function () {
      const sh = this.state.productSpecs.sheet;
      if (sh.busy || !sh.preview) return;
      if (!window.confirm('ویژگی‌ها روی محصولات ذخیره شود؟ مقدار قبلی هر محصول برای بازگشت نگه داشته می‌شود.')) return;
      sh.busy = 'apply'; sh.error = ''; this.render();
      try {
        const r = await this.apiFetch('/product-specs/sheet/apply', { method: 'POST', body: JSON.stringify({ plan_id: sh.preview.plan_id }) });
        if (!r.success) throw new Error(r.message || 'ذخیره انجام نشد.');
        sh.applied = r; sh.step = 'done';
        this.toast(`ویژگی ${toPersianDigits(r.written)} محصول ذخیره شد.`);
        this.state.productSpecs.drafts = {};
        await this.loadProductSpecs();
      } catch (e) { sh.error = e.message; }
      sh.busy = ''; this.render();
    },

    sheetCardHtml: function () {
      const ps = this.state.productSpecs;
      const esc = escapeHtml;
      const fa = (n) => toPersianDigits(Number(n) || 0);
      const sh = ps.sheet;
      if (!sh || !sh.open) {
        return `<div class="wila-card khz-sh-card"><div class="khz-sh-head"><div><h3 style="margin:0">وارد کردن ویژگی‌ها از گوگل‌شیت</h3><p class="khz-sh-sub">ویژگی محصولات را یک‌جا از شیت یا فایل CSV بخوانید و برای محصولاتی که در شیت نیستند، از محصولات مشابه هم‌دسته کمک بگیرید.</p></div><button type="button" class="wila-btn wila-btn-primary" id="khz-sh-open">شروع</button></div></div>`;
      }
      const stepIdx = { source: 1, mapping: 2, preview: 3, done: 3 }[sh.step];
      const steps = ['خواندن شیت', 'انتخاب ستون‌ها', 'پیش‌نمایش و ذخیره'].map((t, i) => `<li class="${i + 1 === stepIdx ? 'is-on' : (i + 1 < stepIdx ? 'is-done' : '')}"><span>${fa(i + 1)}</span>${t}</li>`).join('');
      let body = '';
      if (sh.busy) {
        const msg = { load: 'شیت خوانده می‌شود…', preview: 'ردیف‌ها با محصولات تطبیق داده می‌شوند…', apply: 'ویژگی‌ها ذخیره می‌شود…' }[sh.busy];
        body = `<div class="khz-sh-busy" role="status"><span class="khz-dr-spin" aria-hidden="true"></span><p>${msg}</p></div>`;
      } else if (sh.step === 'source') {
        body = `
          <label class="khz-sh-lbl" for="khz-sh-link">لینک گوگل‌شیت</label>
          <input id="khz-sh-link" class="wila-input" dir="ltr" type="url" placeholder="https://docs.google.com/spreadsheets/d/…" value="${esc(sh.link)}">
          <p class="khz-sh-hint">شیت را با ایمیل <b dir="ltr">${esc(ps.sheetEmail || 'سرویس‌اکانت سرچ کنسول')}</b> به‌صورت Viewer به اشتراک بگذارید و در Google Cloud «Google Sheets API» را فعال کنید.</p>
          <div class="khz-sh-or">یا</div>
          <label class="khz-sh-lbl" for="khz-sh-file">فایل CSV</label>
          <input id="khz-sh-file" type="file" accept=".csv,text/csv">
          ${sh.csvName ? `<p class="khz-sh-hint">فایل انتخاب‌شده: <b>${esc(sh.csvName)}</b></p>` : '<p class="khz-sh-hint">در گوگل‌شیت: File ← Download ← Comma-separated values.</p>'}`;
      } else if (sh.step === 'mapping') {
        const d = sh.data;
        const opts = (sel, none) => `${none ? '<option value="">— ندارد —</option>' : ''}${d.header.map((h, i) => `<option value="${i}" ${String(sel) === String(i) ? 'selected' : ''}>${esc(h || ('ستون ' + (i + 1)))}</option>`).join('')}`;
        body = `
          <p class="khz-sh-hint">${fa(d.rows)} ردیف از «${esc(d.title)}» خوانده شد. ستون‌ها را تأیید کنید.</p>
          <div class="khz-sh-map">
            <label>ستون نام یا کد محصول<select class="wila-select khz-sh-key">${opts(sh.mapping.key, false)}</select></label>
            ${FIELD_ORDER.map(f => `<label>${esc(this.productSpecsFieldLabel(f))}<select class="wila-select khz-sh-field" data-field="${f}">${opts(sh.mapping.fields[f], true)}</select></label>`).join('')}
          </div>
          <div class="wila-table-scroll khz-sh-sample"><table class="wila-table"><thead><tr>${d.header.map(h => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${d.sample.map(r => `<tr>${d.header.map((h, i) => `<td>${esc(r[i] || '')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>
          <label class="khz-dr-check"><input type="checkbox" id="khz-sh-fill" ${sh.fillSimilar ? 'checked' : ''}> برای محصولاتی که در شیت نیستند یا ویژگی ندارند، از محصولات مشابه هم‌دسته کمک بگیر</label>`;
      } else if (sh.step === 'preview') {
        const p = sh.preview;
        const specLine = (o) => FIELD_ORDER.filter(f => o && o[f]).map(f => `<span class="khz-sh-pill"><i>${esc(this.productSpecsFieldLabel(f))}</i> ${esc(o[f])}</span>`).join('');
        body = `
          <div class="khz-dr-stats">
            <div><b>${fa(p.matched)}</b><span>ردیف شیت با محصول تطبیق خورد</span></div>
            <div><b>${fa(p.to_change)}</b><span>محصول تغییر می‌کند</span></div>
            <div><b>${fa(p.infer_count)}</b><span>محصول از مشابه‌ها پر می‌شود</span></div>
          </div>
          ${p.to_change ? `<h4 class="khz-sh-h">تغییر ویژگی‌ها${p.to_change > p.changes.length ? ` (${fa(p.changes.length)} مورد اول)` : ''}</h4><ul class="khz-sh-list">${p.changes.map(c => `<li><b>${esc(c.title)}</b><div>${specLine(c.after)}</div></li>`).join('')}</ul>` : '<p class="khz-sh-hint">هیچ محصولی با شیت تغییر نمی‌کند.</p>'}
          ${p.infer_count ? `<h4 class="khz-sh-h">از محصولات مشابه هم‌دسته${p.infer_count > p.infer.length ? ` (${fa(p.infer.length)} مورد اول)` : ''}</h4><p class="khz-sh-hint">این‌ها حدسی‌اند و به‌عنوان «مشخصات محصولات مشابه» در تولید محتوا استفاده می‌شوند، نه مشخصات قطعی. هر وقت خودتان مقدار واقعی را در جدول ثبت کنید، جایگزین می‌شود.</p><ul class="khz-sh-list">${p.infer.map(c => `<li><b>${esc(c.title)}</b><div>${specLine(c.specs)}</div><small>مشابه: ${esc(c.from.join('، '))}</small></li>`).join('')}</ul>` : ''}
          ${p.unmatched_count ? `<h4 class="khz-sh-h">محصولی برایشان پیدا نشد (${fa(p.unmatched_count)})</h4><p class="khz-sh-hint">${p.unmatched.map(esc).join('، ')}</p>` : ''}
          ${p.ambiguous_count ? `<h4 class="khz-sh-h">چند محصول با یک نام (${fa(p.ambiguous_count)})؛ رد شد</h4><p class="khz-sh-hint">${p.ambiguous.map(esc).join('، ')}. در شیت از کد یا نام کامل‌تر استفاده کنید.</p>` : ''}
          ${p.still_empty ? `<p class="khz-sh-hint">${fa(p.still_empty)} محصول هنوز بدون ویژگی می‌ماند و برایشان عبارت عمومی نوشته می‌شود.</p>` : ''}`;
      } else {
        const a = sh.applied;
        body = `<div class="khz-dr-ok" role="status">ویژگی ${fa(a.written)} محصول ذخیره شد${a.inferred ? ` و برای ${fa(a.inferred)} محصول از مشابه‌ها استفاده شد` : ''}. از حالا هنگام تولید محتوا استفاده می‌شود.</div>`;
      }
      const foot = sh.busy ? '' : (sh.step === 'source' ? `<button type="button" class="wila-btn wila-btn-primary" id="khz-sh-go">خواندن شیت</button>`
        : sh.step === 'mapping' ? `<button type="button" class="wila-btn wila-btn-primary" id="khz-sh-prev">پیش‌نمایش تغییرات</button><button type="button" class="wila-btn wila-btn-outline" id="khz-sh-back">بازگشت</button>`
        : sh.step === 'preview' ? `<button type="button" class="wila-btn wila-btn-primary" id="khz-sh-apply" ${sh.preview.to_change || sh.preview.infer_count ? '' : 'disabled'}>ذخیره روی محصولات</button><button type="button" class="wila-btn wila-btn-outline" id="khz-sh-back2">بازگشت</button>`
        : `<button type="button" class="wila-btn wila-btn-outline" id="khz-sh-close">بستن</button>`);
      return `<div class="wila-card khz-sh-card">
        <div class="khz-sh-head"><h3 style="margin:0">وارد کردن ویژگی‌ها از گوگل‌شیت</h3>${sh.step !== 'done' ? '<button type="button" class="wila-btn wila-btn-outline wila-btn-sm" id="khz-sh-close">بستن</button>' : ''}</div>
        <ol class="khz-sh-steps">${steps}</ol>
        ${sh.error ? `<div class="khz-dr-err" role="alert">${esc(sh.error)}</div>` : ''}
        ${body}
        ${foot ? `<div class="khz-sh-foot">${foot}</div>` : ''}
      </div>`;
    },

    bindSheetEvents: function () {
      const ps = this.state.productSpecs;
      const sh = ps.sheet;
      const on = (sel, fn) => { const el = this.root.querySelector(sel); if (el) el.addEventListener('click', fn); };
      on('#khz-sh-open', () => { this.sheetReset(); this.render(); });
      if (!sh || !sh.open) return;
      on('#khz-sh-close', () => { ps.sheet = null; this.render(); });
      on('#khz-sh-go', () => this.sheetLoad());
      on('#khz-sh-prev', () => this.sheetPreview());
      on('#khz-sh-back', () => { sh.step = 'source'; sh.error = ''; this.render(); });
      on('#khz-sh-back2', () => { sh.step = 'mapping'; sh.error = ''; this.render(); });
      on('#khz-sh-apply', () => this.sheetApply());
      const link = this.root.querySelector('#khz-sh-link');
      if (link) link.addEventListener('input', e => { sh.link = e.target.value; });
      const file = this.root.querySelector('#khz-sh-file');
      if (file) file.addEventListener('change', e => {
        const f = e.target.files && e.target.files[0];
        if (!f) return;
        const reader = new FileReader();
        reader.onload = () => { sh.csvText = String(reader.result || ''); sh.csvName = f.name; this.render(); };
        reader.readAsText(f, 'UTF-8');
      });
      const key = this.root.querySelector('.khz-sh-key');
      if (key) key.addEventListener('change', e => { sh.mapping.key = e.target.value === '' ? null : Number(e.target.value); });
      this.root.querySelectorAll('.khz-sh-field').forEach(sel => sel.addEventListener('change', e => { sh.mapping.fields[e.target.getAttribute('data-field')] = e.target.value === '' ? null : Number(e.target.value); }));
      const fill = this.root.querySelector('#khz-sh-fill');
      if (fill) fill.addEventListener('change', e => { sh.fillSimilar = e.target.checked; });
    },

    productSpecsFieldLabel: function (key) {
      const ps = this.state.productSpecs;
      return (ps.fields && ps.fields[key]) || key;
    },

    viewProductSpecs: function () {
      const ps = this.state.productSpecs;
      const esc = escapeHtml;
      const totalPages = ps.perPage ? Math.max(1, Math.ceil(ps.total / ps.perPage)) : 1;
      const rows = ps.items.map(item => {
        const draft = ps.drafts[item.id] || {};
        const saving = !!ps.saving[item.id];
        const fieldsHtml = FIELD_ORDER.map(key => `
          <div class="khazan-field">
            <label>${esc(this.productSpecsFieldLabel(key))}</label>
            <input type="text" class="wila-input khazan-specs-field" data-product-id="${item.id}" data-field="${key}" value="${esc(draft[key] || '')}" placeholder="${esc(FIELD_PLACEHOLDERS[key] || '')}" ${saving ? 'disabled' : ''}>
          </div>`).join('');
        return `<tr class="khazan-specs-row">
          <td style="width:64px">${item.thumbnail ? `<img src="${esc(item.thumbnail)}" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:6px">` : '<span style="color:#94a3b8">—</span>'}</td>
          <td style="font-weight:600">${esc(item.title)}</td>
          <td>${esc((item.categories || []).join('، ') || '—')}</td>
          <td><div class="khazan-specs-grid">${fieldsHtml}</div></td>
          <td style="white-space:nowrap"><button type="button" class="wila-btn wila-btn-primary wila-btn-sm khazan-specs-save" data-product-id="${item.id}" ${saving ? 'disabled' : ''}>${saving ? 'در حال ذخیره...' : 'ذخیره'}</button></td>
        </tr>`;
      }).join('');

      return `${this.sheetCardHtml()}<div class="wila-card">
        <h3 style="margin-top:0">مدیریت سریع ویژگی‌های محصولات</h3>
        <p style="color:#64748b;font-size:.85rem">مشخصات فنی قطعی هر محصول (جنس زیره، نوع چرم رویه، جنس آستر و نوع دوخت) را اینجا ثبت کنید. هوش مصنوعی هنگام تولید محتوا فقط از همین مقادیر استفاده می‌کند و هرگز جنس زیره یا رویه را حدس نمی‌زند؛ برای محصولی بدون مشخصات ثبت‌شده، از عبارت عمومی «زیره استاندارد و باکیفیت» استفاده می‌شود.</p>
        <div class="khazan-toolbar">
          <div class="khazan-field"><label>جستجوی نام محصول</label>
            <input id="wila-specs-search" type="search" class="wila-input" value="${esc(ps.search)}" placeholder="بخشی از نام محصول...">
          </div>
        </div>
        ${ps.error ? `<div class="khazan-statusbar"><span class="khazan-status-pill">⚠️ ${esc(ps.error)}</span></div>` : ''}
        ${ps.loading ? '<div class="wila-skeleton" style="height:14px;margin-top:14px"></div><div class="wila-skeleton" style="height:14px;margin-top:8px"></div>' : ''}
        ${!ps.loading && ps.loaded && !ps.items.length ? '<p style="text-align:center;padding:20px;color:#78716c">محصولی برای نمایش یافت نشد.</p>' : ''}
        ${ps.items.length ? `
          <div class="wila-table-scroll"><table class="wila-table khazan-specs-table"><thead><tr>
            <th></th><th>نام محصول</th><th>دسته‌بندی</th><th>مشخصات فنی</th><th>عملیات</th>
          </tr></thead><tbody>${rows}</tbody></table></div>
          <div class="khazan-statusbar" style="justify-content:space-between;display:flex;align-items:center">
            <span class="khazan-status-pill">صفحه ${toPersianDigits(ps.page)} از ${toPersianDigits(totalPages)} — ${toPersianDigits(ps.total)} محصول</span>
            <span>
              <button type="button" class="wila-btn wila-btn-outline wila-btn-sm khazan-specs-prev" ${ps.page <= 1 || ps.loading ? 'disabled' : ''}>قبلی</button>
              <button type="button" class="wila-btn wila-btn-outline wila-btn-sm khazan-specs-next" ${!ps.hasMore || ps.loading ? 'disabled' : ''}>بعدی</button>
            </span>
          </div>
        ` : ''}
      </div>`;
    },

    bindProductSpecsEvents: function () {
      if (this.state.activeTab !== 'specs') return;
      const ps = this.state.productSpecs;
      this.bindSheetEvents();
      const search = this.root.querySelector('#wila-specs-search');
      if (search) {
        search.addEventListener('input', e => { ps.search = e.target.value; });
        search.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); this.searchProductSpecs(search.value); } });
      }
      this.root.querySelectorAll('.khazan-specs-field').forEach(input => input.addEventListener('input', e => {
        const id = Number(e.target.getAttribute('data-product-id'));
        const field = e.target.getAttribute('data-field');
        if (!ps.drafts[id]) ps.drafts[id] = {};
        ps.drafts[id][field] = e.target.value;
      }));
      this.root.querySelectorAll('.khazan-specs-save').forEach(btn => btn.addEventListener('click', e => this.saveProductSpecsRow(Number(e.currentTarget.getAttribute('data-product-id')))));
      const prev = this.root.querySelector('.khazan-specs-prev'); if (prev) prev.addEventListener('click', () => this.changeProductSpecsPage(-1));
      const next = this.root.querySelector('.khazan-specs-next'); if (next) next.addEventListener('click', () => this.changeProductSpecsPage(1));
    }
  };

  window.KhazanProductSpecs = {
    initialState: function () {
      return { search: '', page: 1, perPage: 20, loading: false, loaded: false, error: '', items: [], total: 0, hasMore: false, fields: {}, drafts: {}, saving: {}, sheet: null, sheetEmail: '' };
    },
    methods: methods
  };
})();
