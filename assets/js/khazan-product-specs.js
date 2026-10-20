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

  const FIELD_ORDER = ['sole_material', 'upper_material', 'lining_material', 'stitching_type'];
  const FIELD_PLACEHOLDERS = {
    sole_material: 'مثلاً: پی‌یو (PU) استاندارد',
    upper_material: 'مثلاً: چرم طبیعی گاوی',
    lining_material: 'مثلاً: آستر پارچه‌ای تنفس‌پذیر',
    stitching_type: 'مثلاً: دوخت دستی'
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

      return `<div class="wila-card">
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
      return { search: '', page: 1, perPage: 20, loading: false, loaded: false, error: '', items: [], total: 0, hasMore: false, fields: {}, drafts: {}, saving: {} };
    },
    methods: methods
  };
})();
