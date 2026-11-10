/**
 * Khazan - Google Search Console tab: service-account connection and a view of the synced queries and pages.
 * Mixed into the dashboard app by wila-admin.js (methods run with `this` === app).
 */
(function () {
  'use strict';

  const { escapeHtml, toPersianDigits } = window.KhazanUtils;
  const fa = (n) => toPersianDigits(Math.round(Number(n) || 0));
  const pct = (n) => toPersianDigits((Number(n) * 100).toFixed(1)) + '٪';
  const pos = (n) => toPersianDigits(Number(n).toFixed(1));

  const methods = {
    loadSearch: async function () {
      const s = this.state.search;
      s.busy = 'load'; this.render();
      try {
        const r = await this.apiFetch('/search/data');
        if (!r.success) throw new Error(r.message || 'اطلاعات دریافت نشد.');
        s.data = r; s.error = '';
      } catch (e) { s.error = e.message; }
      s.busy = ''; this.render();
    },

    connectSearch: async function () {
      const s = this.state.search;
      const key = (this.root.querySelector('#khz-gsc-key') || {}).value || s.keyDraft;
      const site = (this.root.querySelector('#khz-gsc-site') || {}).value || '';
      if (!key && !(s.data && s.data.has_key)) return this.toast('محتوای فایل JSON را بچسبانید.');
      s.keyDraft = key || ''; s.busy = 'connect'; s.error = ''; this.render();
      try {
        const r = await this.apiFetch('/search/connect', { method: 'POST', body: JSON.stringify({ key: s.keyDraft, site }) });
        if (!r.success) throw new Error(r.message || 'اتصال انجام نشد.');
        s.sites = r.sites || []; s.message = r.message || '';
        if (r.connected) { s.keyDraft = ''; await this.loadSearch(); this.toast('به سرچ کنسول وصل شد.'); return; }
      } catch (e) { s.error = e.message; }
      s.busy = ''; this.render();
    },

    syncSearch: async function () {
      const s = this.state.search;
      s.busy = 'sync'; s.error = ''; this.render();
      try {
        const r = await this.apiFetch('/search/sync', { method: 'POST', body: JSON.stringify({ days: 90 }) });
        if (!r.success) throw new Error(r.message || 'دریافت داده انجام نشد.');
        this.toast('داده‌های ۹۰ روز اخیر دریافت شد.');
      } catch (e) { s.error = e.message; }
      await this.loadSearch();
    },

    disconnectSearch: async function () {
      if (!window.confirm('اتصال و داده‌های ذخیره‌شده‌ی سرچ کنسول پاک شود؟')) return;
      const s = this.state.search;
      try { await this.apiFetch('/search/disconnect', { method: 'POST', body: '{}' }); s.sites = []; s.message = ''; s.error = ''; }
      catch (e) { s.error = e.message; }
      await this.loadSearch();
    },

    viewSearch: function () {
      const s = this.state.search;
      const d = s.data || {};
      const busy = !!s.busy;
      const btn = (id, label, kind, cls) => `<button class="wila-btn ${cls || 'wila-btn-primary'}" id="${id}" ${busy ? 'disabled' : ''}>${s.busy === kind ? 'در حال انجام…' : label}</button>`;
      const table = (rows, name, title) => `
        <h4 style="margin:16px 0 6px">${title}</h4>
        <div style="overflow-x:auto"><table class="wila-table" style="width:100%;font-size:0.82rem"><thead><tr><th>${name === 'query' ? 'عبارت' : 'صفحه'}</th><th>کلیک</th><th>نمایش</th><th>CTR</th><th>جایگاه</th></tr></thead><tbody>
        ${(rows || []).slice(0, 20).map(r => `<tr><td style="max-width:360px;overflow:hidden;text-overflow:ellipsis" ${name === 'page' ? 'dir="ltr"' : ''}>${escapeHtml(r[name])}</td><td>${fa(r.clicks)}</td><td>${fa(r.impressions)}</td><td>${pct(r.ctr)}</td><td>${pos(r.position)}</td></tr>`).join('') || '<tr><td colspan="5">هنوز داده‌ای دریافت نشده است.</td></tr>'}
        </tbody></table></div>`;
      const sites = (s.sites || []).length && !d.connected ? `
        <label style="display:block;margin:10px 0 4px">سایت را انتخاب کنید</label>
        <select id="khz-gsc-site" class="wila-select" style="max-width:420px" dir="ltr">${s.sites.map(x => `<option value="${escapeHtml(x.url)}">${escapeHtml(x.url)}</option>`).join('')}</select>` : '';
      return `
        ${s.error ? `<div class="wila-card" style="border-color:#fecaca;color:#991b1b">${escapeHtml(s.error)}</div>` : ''}
        <div class="wila-card">
          <h3>اتصال به Google Search Console</h3>
          ${d.connected ? `
            <p>وصل است به <b dir="ltr">${escapeHtml(d.site)}</b> با حساب <span dir="ltr">${escapeHtml(d.email)}</span>.
              ${d.synced_at ? `آخرین دریافت: ${toPersianDigits(escapeHtml(d.synced_at))} (از ${toPersianDigits(d.range.start)} تا ${toPersianDigits(d.range.end)}؛ ${fa(d.counts.queries)} عبارت و ${fa(d.counts.pages)} صفحه).` : 'هنوز داده‌ای دریافت نشده است.'}</p>
            ${btn('khz-gsc-sync', 'دریافت داده‌های ۹۰ روز اخیر', 'sync')} ${btn('khz-gsc-disconnect', 'قطع اتصال', 'disconnect', 'wila-btn-outline')}
          ` : `
            <ol style="font-size:0.85rem;line-height:2">
              <li>در Google Cloud یک پروژه بسازید و «Google Search Console API» را فعال کنید.</li>
              <li>یک Service Account بسازید و کلید JSON آن را دانلود کنید.</li>
              <li>در سرچ کنسول، بخش Settings ← Users and permissions، ایمیل Service Account را با دسترسی Restricted (فقط‌خواندنی) اضافه کنید.</li>
              <li>محتوای فایل JSON را اینجا بچسبانید.</li>
            </ol>
            <textarea id="khz-gsc-key" class="wila-input" rows="6" dir="ltr" style="width:100%" placeholder='{"type":"service_account", ...}'>${escapeHtml(s.keyDraft || '')}</textarea>
            ${sites}
            ${s.message ? `<p style="color:#b45309">${escapeHtml(s.message)}</p>` : ''}
            <div style="margin-top:10px">${btn('khz-gsc-connect', 'اتصال', 'connect')}</div>
          `}
          <p style="font-size:0.8rem;color:#6b7280;margin-top:10px">دسترسی فقط‌خواندنی است. کلید روی سرور خودِ سایت ذخیره می‌شود و برای هیچ سرویسی جز گوگل فرستاده نمی‌شود.</p>
        </div>
        ${d.connected ? `<div class="wila-card">${table(d.queries, 'query', 'پرنمایش‌ترین عبارت‌ها')}${table(d.pages, 'page', 'پرنمایش‌ترین صفحه‌ها')}</div>` : ''}
      `;
    },

    bindSearchEvents: function () {
      const on = (s, fn) => { const el = this.root.querySelector(s); if (el) el.addEventListener('click', fn); };
      on('#khz-gsc-connect', () => this.connectSearch());
      on('#khz-gsc-sync', () => this.syncSearch());
      on('#khz-gsc-disconnect', () => this.disconnectSearch());
      const ta = this.root.querySelector('#khz-gsc-key');
      if (ta) ta.addEventListener('input', e => { this.state.search.keyDraft = e.target.value; });
    },
  };

  window.KhazanSearch = { methods: methods };
})();
