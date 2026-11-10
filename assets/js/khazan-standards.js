/**
 * Khazan - standards tab: the owner-confirmed facts and verified external sources, the SEO standards the writer and
 * the audit follow, the keyword map import, and the analysis of the site's existing links and content.
 * Mixed into the dashboard app by wila-admin.js (methods run with `this` === app).
 */
(function () {
  'use strict';

  const { escapeHtml, toPersianDigits } = window.KhazanUtils;
  const fa = (n) => toPersianDigits(Math.round(Number(n) || 0));

  const RANGES = [
    ['product_words', 'طول متن محصول (کلمه)'], ['article_words', 'طول متن مقاله (کلمه)'],
    ['links_category', 'لینک داخلی دسته‌بندی'], ['links_product', 'لینک داخلی محصول'],
    ['links_article', 'لینک داخلی مقاله'], ['links_external', 'لینک خارجی'],
  ];
  const NUMBERS = [
    ['body_words_min', 'حداقل طول متن دسته‌بندی'], ['body_words_max', 'حداکثر طول متن دسته‌بندی'],
    ['density_min', 'حداقل تراکم کلمه اصلی (٪)'], ['density_max', 'حداکثر تراکم کلمه اصلی (٪)'],
    ['h2_min', 'حداقل H2'], ['h2_keyword_max', 'حداکثر H2 دارای کلمه اصلی'], ['section_words_max', 'حداکثر کلمه بین دو زیرعنوان'],
    ['title_min', 'حداقل طول عنوان سئو'], ['title_max', 'حداکثر طول عنوان سئو'], ['meta_min', 'حداقل طول متا'], ['meta_max', 'حداکثر طول متا'],
    ['links_per_paragraph', 'حداکثر لینک در هر پاراگراف'],
  ];

  const methods = {
    loadStandards: async function () {
      const st = this.state.standards;
      try {
        const r = await this.apiFetch('/standards');
        if (!r.success) throw new Error(r.message || 'تنظیمات دریافت نشد.');
        st.data = r; st.error = '';
      } catch (e) { st.error = e.message; }
      this.render();
    },

    saveStandards: async function () {
      const root = this.root, st = this.state.standards;
      const v = (id) => root.querySelector(id);
      const seo = {};
      NUMBERS.forEach(([k]) => { const el = v('#khz-seo-' + k); if (el && el.value !== '') seo[k] = Number(el.value); });
      RANGES.forEach(([k]) => { const a = v('#khz-seo-' + k + '-min'), b = v('#khz-seo-' + k + '-max'); if (a && b && a.value !== '' && b.value !== '') seo[k] = [Number(a.value), Number(b.value)]; });
      const facts = {
        free_shipping: v('#khz-fact-shipping').checked,
        return_days: Number(v('#khz-fact-return').value),
        installments: v('#khz-fact-installments').value.trim(),
        hardware: v('#khz-fact-hardware').value.trim(),
        plain_leather_slugs: v('#khz-fact-plain').value,
        tabriz_keyword_slugs: v('#khz-fact-tabriz').value,
        verified_sources_text: v('#khz-fact-sources').value,
      };
      try {
        const r = await this.apiFetch('/standards', { method: 'POST', body: JSON.stringify({ facts: facts, seo: seo }) });
        if (!r.success) throw new Error(r.message || 'ذخیره نشد.');
        st.data = r; this.toast('تنظیمات ذخیره شد.');
        this.render();
      } catch (e) { this.toast('خطا: ' + e.message); }
    },

    resetSeoStandards: async function () {
      if (!window.confirm('همه استانداردهای سئو به مقدار پیش‌فرض برگردد؟')) return;
      try {
        const r = await this.apiFetch('/standards', { method: 'POST', body: JSON.stringify({ reset_seo: true }) });
        if (!r.success) throw new Error(r.message || 'انجام نشد.');
        this.state.standards.data = r; this.render();
      } catch (e) { this.toast('خطا: ' + e.message); }
    },

    importKeywordMap: async function () {
      const st = this.state.standards;
      const csv = this.root.querySelector('#khz-map-csv').value;
      try {
        const r = await this.apiFetch('/standards/keyword-map', { method: 'POST', body: JSON.stringify({ csv: csv }) });
        if (!r.success) throw new Error(r.message || 'وارد نشد.');
        this.toast(`${fa(r.imported)} صفحه وارد شد (جمعاً ${fa(r.pages)}).`);
        await this.loadStandards();
      } catch (e) { st.error = e.message; this.render(); }
    },

    runLinkAnalysis: async function (refresh) {
      const st = this.state.standards;
      st.analysisBusy = true; st.error = ''; this.render();
      try {
        const r = await this.apiFetch('/report/link-analysis' + (refresh ? '?refresh=1' : ''));
        if (r.code && !r.success) throw new Error(r.message || 'تحلیل انجام نشد.');
        st.analysis = r;
      } catch (e) { st.error = e.message; }
      st.analysisBusy = false; this.render();
    },

    analysisHtml: function () {
      const a = this.state.standards.analysis;
      if (!a || !a.summary) return '';
      const s = a.summary;
      const list = (rows, cols) => rows && rows.length ? `<table style="width:100%;font-size:0.78rem;border-collapse:collapse">${rows.map(r => `<tr>${cols.map(c => `<td style="padding:3px 6px;border-bottom:1px solid #e2e8f0">${c(r)}</td>`).join('')}</tr>`).join('')}</table>` : '<p style="color:#047857;font-size:0.8rem">مشکلی پیدا نشد.</p>';
      const link = (r) => `<a href="${escapeHtml(r.url || '#')}" target="_blank" rel="noopener">${escapeHtml(r.title || r.name || r.url || '')}</a>`;
      return `
        <div class="wila-card"><h3>خلاصه تحلیل</h3>
          <p>صفحه با لینک داخلی کمتر از حد استاندارد: <b>${fa(s.pages_with_too_few_links)}</b> — بیشتر از حد: <b>${fa(s.pages_with_too_many_links)}</b><br>
          استفاده از انکر عمومی (اینجا، کلیک کنید…): <b>${fa(s.generic_anchor_uses)}</b> — یک انکر برای چند مقصد: <b>${fa(s.cannibalizing_anchors)}</b> — مقصد با انکر تکراری: <b>${fa(s.flooded_targets)}</b><br>
          صفحه بدون هیچ لینک ورودی: <b>${fa(s.pages_without_inbound)}</b> — دسته‌بندی با متن کوتاه: <b>${fa(s.thin_categories)}</b></p>
          <p style="font-size:0.75rem;color:#64748b">${a.cached ? 'نتیجه ذخیره‌شده' : 'تازه'} — ${escapeHtml(a.generated_at || '')}</p></div>
        <div class="wila-card"><h3>صفحه‌های با لینک داخلی کم</h3>${list(a.links_per_page.few, [link, r => `${fa(r.links)} از حداقل ${fa(r.min)}`])}</div>
        <div class="wila-card"><h3>صفحه‌های با لینک داخلی زیاد</h3>${list(a.links_per_page.many, [link, r => `${fa(r.links)} از حداکثر ${fa(r.max)}`])}</div>
        <div class="wila-card"><h3>انکرهای عمومی</h3>${list(a.anchors.generic, [r => escapeHtml(r.anchor), r => `${fa(r.uses)} بار`])}</div>
        <div class="wila-card"><h3>یک انکر برای چند صفحه مختلف (رقابت داخلی)</h3>${list(a.anchors.shared, [r => escapeHtml(r.anchor), r => `${fa(r.targets)} مقصد`, r => `${fa(r.uses)} بار`])}</div>
        <div class="wila-card"><h3>مقصدهایی که بیش‌ازحد با یک انکر لینک شده‌اند</h3>${list(a.anchors.flooded, [r => escapeHtml(r.anchor), r => `<a href="${escapeHtml(r.url)}" target="_blank" rel="noopener">صفحه</a>`, r => `${fa(r.share)}٪ از ${fa(r.total)} لینک`])}</div>
        <div class="wila-card"><h3>صفحه‌های بدون لینک ورودی</h3>${list(a.inbound.orphans, [link])}</div>
        <div class="wila-card"><h3>دسته‌بندی‌هایی که متن‌شان کوتاه‌تر از استاندارد است (اولویت تولید محتوا)</h3>${list(a.content_gaps, [link, r => `${fa(r.products)} محصول`, r => `${fa(r.words)} کلمه، ${fa(r.missing)} کم`])}</div>`;
    },

    viewStandards: function () {
      const st = this.state.standards;
      if (!st.data) return `<div class="wila-card"><div class="wila-skeleton" style="height:14px;width:40%"></div>${st.error ? `<p style="color:#991b1b">${escapeHtml(st.error)}</p>` : ''}</div>`;
      const seo = st.data.seo, f = st.data.facts;
      const num = (k, label) => `<label style="display:inline-block;margin:4px 8px">${label}: <input type="number" step="any" id="khz-seo-${k}" value="${escapeHtml(String(seo[k]))}" style="width:80px"/></label>`;
      const range = (k, label) => `<label style="display:inline-block;margin:4px 8px">${label}: <input type="number" id="khz-seo-${k}-min" value="${seo[k][0]}" style="width:64px"/> تا <input type="number" id="khz-seo-${k}-max" value="${seo[k][1]}" style="width:64px"/></label>`;
      return `
        ${st.error ? `<div class="wila-card" style="border-color:#fecaca;color:#991b1b">${escapeHtml(st.error)}</div>` : ''}
        <div class="wila-card">
          <h3>واقعیت‌های تأییدشده فروشگاه</h3>
          <p style="font-size:0.8rem;color:#64748b">نویسنده فقط همین‌ها را می‌تواند درباره فروشگاه بنویسد. هر چیز دیگری (قیمت، هدیه، گارانتی، نوع چرم و…) ممنوع است.</p>
          <label style="display:block;margin:6px 0"><input type="checkbox" id="khz-fact-shipping" ${f.free_shipping ? 'checked' : ''}/> ارسال برای همه خریدها رایگان است (در عنوان سئو نوشته نمی‌شود)</label>
          <label style="display:block;margin:6px 0">ضمانت بازگشت کالا (روز): <input type="number" id="khz-fact-return" min="0" max="60" value="${f.return_days}" style="width:70px"/></label>
          <label style="display:block;margin:6px 0">خرید اقساطی: <input type="text" id="khz-fact-installments" value="${escapeHtml(f.installments)}" style="width:100%"/></label>
          <label style="display:block;margin:6px 0">یراق‌آلات: <input type="text" id="khz-fact-hardware" value="${escapeHtml(f.hardware)}" style="width:100%"/></label>
          <label style="display:block;margin:6px 0">اسلاگ دسته‌هایی که فقط «چرم» نامیده می‌شوند (با فاصله یا ویرگول):<textarea id="khz-fact-plain" rows="2" style="width:100%;direction:ltr">${escapeHtml((f.plain_leather_slugs || []).join(' '))}</textarea></label>
          <label style="display:block;margin:6px 0">اسلاگ دسته‌هایی که «تبریز» جزو کلمه کلیدی است:<textarea id="khz-fact-tabriz" rows="2" style="width:100%;direction:ltr">${escapeHtml((f.tabriz_keyword_slugs || []).join(' '))}</textarea></label>
          <label style="display:block;margin:6px 0">منابع خارجی تأییدشده (هر خط: <span dir="ltr">https://… | عنوان</span>). تا منبعی ثبت نشود، لینک خارجی ساخته نمی‌شود:
            <textarea id="khz-fact-sources" rows="4" style="width:100%;direction:ltr">${escapeHtml(f.verified_sources_text || '')}</textarea></label>
        </div>
        <div class="wila-card">
          <h3>استانداردهای سئو</h3>
          <p style="font-size:0.8rem;color:#64748b">تعداد لینک‌ها و طول متن از همین‌جا خوانده می‌شود؛ عدد ثابتی در افزونه نیست.</p>
          ${RANGES.map(([k, l]) => range(k, l)).join('')}<br>${NUMBERS.map(([k, l]) => num(k, l)).join('')}
          <div style="margin-top:8px"><button class="wila-btn wila-btn-primary" id="khz-std-save">ذخیره</button> <button class="wila-btn wila-btn-outline" id="khz-std-reset">بازگشت استانداردهای سئو به پیش‌فرض</button></div>
        </div>
        <div class="wila-card">
          <h3>نقشه کلیدواژه‌ها</h3>
          <p>صفحه ثبت‌شده: <b>${fa(st.data.keyword_map.pages)}</b>. فایل <span dir="ltr">ownership.csv</span> را انتخاب کنید یا محتوایش را بچسبانید (ستون‌های <span dir="ltr">keyword_or_group, owner_url, owner_type</span>).</p>
          <input type="file" id="khz-map-file" accept=".csv,text/csv"/>
          <textarea id="khz-map-csv" rows="4" style="width:100%;direction:ltr"></textarea>
          <button class="wila-btn wila-btn-outline" id="khz-map-import">وارد کردن نقشه کلیدواژه</button>
        </div>
        <div class="wila-card">
          <h3>تحلیل لینک‌ها و محتوای موجود سایت</h3>
          <p>لینک‌های ساخته‌شده قبلی، انکرها، صفحه‌های بدون لینک ورودی و دسته‌بندی‌های کم‌محتوا را بر اساس همین استانداردها بررسی می‌کند. تغییری در سایت نمی‌دهد. (نیاز به اسکن سایت در پیشخوان)</p>
          <button class="wila-btn wila-btn-primary" id="khz-analysis-run" ${st.analysisBusy ? 'disabled' : ''}>${st.analysisBusy ? 'در حال تحلیل…' : (st.analysis ? 'تحلیل دوباره' : 'شروع تحلیل')}</button>
        </div>
        ${this.analysisHtml()}`;
    },

    bindStandardsEvents: function () {
      const q = (s) => this.root.querySelector(s);
      const on = (s, fn) => { const el = q(s); if (el) el.addEventListener('click', fn); };
      on('#khz-std-save', () => this.saveStandards());
      on('#khz-std-reset', () => this.resetSeoStandards());
      on('#khz-map-import', () => this.importKeywordMap());
      on('#khz-analysis-run', () => this.runLinkAnalysis(!!this.state.standards.analysis));
      const file = q('#khz-map-file');
      if (file) file.addEventListener('change', e => {
        const f = e.target.files && e.target.files[0];
        if (!f) return;
        const rd = new FileReader();
        rd.onload = () => { q('#khz-map-csv').value = String(rd.result || ''); };
        rd.readAsText(f, 'utf-8');
      });
    },
  };

  window.KhazanStandards = { methods: methods };
})();
