/**
 * هسته کلاینت داشبورد تحلیل لینک‌های داخلی (با قابلیت حفظ پایداری فرم برنامه‌ریز هنگام بروز خطا)
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

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (char) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[char]));
  }

  const app = {
    state: {
      activeTab: 'overview',
      summary: null,
      items: [],
      orphans: [],
      filterType: 'all',
      isScanning: false,
      scanProgress: 0,
      scanTotal: 0,
      scanCurrent: 0,
      postTypes: [],
      taxonomies: [],
      aiSettings: {
        provider: 'avalai',
        hasAvalAIKey: false,
        avalAIMaskedKey: '',
        avalAIModel: 'deepseek-chat',
        homepageKeywords: ''
      },
      plannerForm: {
        h1Title: '',
        focusKeyword: '',
        longTail: '',
        contentType: 'پیلار پیج (Pillar)',
        funnelStage: 'TOFU (آگاهی / بالاي قیف)',
        wordCount: 1200
      },
      plannerResult: null,
      isPlanning: false,
      generatorForm: { contentKind: 'article', targetId: 0, h1Title: '', focusKeyword: '', longTail: '', wordCount: 1200, funnelStage: 'تشخیص خودکار', internalLinks: '', description: '', useOrphanLinks: true },
      bulk: { fileName: '', rows: [], selected: {}, filter: 'all', mode: 'draft', injectSeo: true, autoLinks: true, matching: false, running: false, paused: false, stopRequested: false, total: 0, done: 0, log: [] },
      targets: [],
      targetsLoading: false,
      targetsError: '',
      isApplying: false,
      contentOutline: null,
      generatedContent: '',
      featuredImagePrompt: '',
      generatedSlug: '',
      generatedSeoTitle: '',
      generatedMetaDescription: '',
      generatedKeywordSynonyms: '',
      generatedInternalLinkCount: 0,
      generatedExternalLinkCount: 0,
      generatedExternalLinks: [],
      contentHistory: [],
      historyLoading: true,
      isGeneratingContent: false,
      modal: { isOpen: false, title: '', contentHtml: '' },
    },

    toast: function (message, type) {
      let box = document.getElementById('wila-toast-root');
      if (!box) { box = document.createElement('div'); box.id = 'wila-toast-root'; document.body.appendChild(box); }
      const kind = type || (/^خطا|ناموفق|نشد|نامعتبر/.test(message) ? 'error' : 'success');
      const el = document.createElement('div');
      el.className = `wila-toast wila-toast-${kind}`;
      el.textContent = message;
      box.appendChild(el);
      setTimeout(() => { el.classList.add('wila-toast-hide'); setTimeout(() => el.remove(), 300); }, 3500);
    },

    init: function () {
      this.root = document.getElementById('wila-app-root');
      if (!this.root) return;
      this.fetchSummary();
      this.fetchSettings();
      this.fetchContentHistory();
      this.render();
    },

    apiFetch: async function (endpoint, options = {}) {
      const url = `${wilaData.restUrl}wila/v1${endpoint}`;
      const defaultHeaders = { 'X-WP-Nonce': wilaData.nonce, 'Content-Type': 'application/json' };
      const response = await fetch(url, { ...options, headers: { credentials: 'include', ...defaultHeaders, ...options.headers } });
      return await response.json();
    },

    fetchSummary: async function () {
      try { this.state.summary = await this.apiFetch('/summary'); this.render(); } catch (e) {}
    },

    fetchSettings: async function () {
      try {
        const data = await this.apiFetch('/settings');
      this.state.aiSettings = {
          provider: 'avalai',
          hasAvalAIKey: data.has_avalai_key,
          avalAIMaskedKey: data.avalai_masked_key || '',
          avalAIModel: data.avalai_model || 'deepseek-chat',
          homepageKeywords: data.homepage_keywords || '',
          productPostType: data.product_post_type || 'product',
          productCategoryTaxonomy: data.product_category_taxonomy || 'product_cat',
        };
        const pts = await this.apiFetch('/post-types');
        this.state.postTypes = pts.items || [];
        if (this.state.aiSettings.productPostType && this.state.postTypes.length) {
          const taxes = await this.apiFetch(`/taxonomies/${this.state.aiSettings.productPostType}`);
          this.state.taxonomies = taxes.items || [];
        }
        this.render();
      } catch (e) {}
    },

    fetchItems: async function () {
      try { this.state.items = await this.apiFetch(`/items?type=${this.state.filterType}`); this.render(); } catch (e) {}
    },

    fetchOrphans: async function () {
      try { this.state.orphans = await this.apiFetch('/orphans'); this.render(); } catch (e) {}
    },

    fetchContentHistory: async function () {
      try {
        const response = await this.apiFetch('/content/history');
        this.state.contentHistory = response.items || [];
      } catch (e) {}
      this.state.historyLoading = false;
      this.render();
    },

    copyField: function (id, label, value, opts) {
      const o = opts || {};
      if (value === null || value === undefined || String(value).trim() === '') return '';
      return `<div style="margin:0 0 14px"><div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px"><label style="font-weight:600">${label}</label><button type="button" class="wila-btn wila-btn-outline wila-btn-sm wila-copy-btn" data-copy="${id}">کپی</button></div>${o.rows ? `<textarea id="${id}" class="wila-input" rows="${o.rows}" readonly style="width:100%;${o.ltr ? 'direction:ltr;text-align:left' : 'direction:rtl'}">${escapeHtml(value)}</textarea>` : `<input id="${id}" class="wila-input" readonly style="width:100%;${o.ltr ? 'direction:ltr;text-align:left' : ''}" value="${escapeHtml(value)}">`}</div>`;
    },

    showContentHistoryItem: async function (id) {
      try {
        const response = await this.apiFetch(`/content/history/${id}`);
        if (!response.success || !response.item) throw new Error(response.message || 'محتوا یافت نشد.');
        const item = response.item;
        let links = [];
        try { links = JSON.parse(item.external_links || '[]') || []; } catch (e) {}
        const summary = [`عنوان: ${item.title}`, `نوع: ${this.kindLabel(item.content_kind)}`, `کلیدواژه کانونی: ${item.focus_keyword}`, item.slug ? `نامک: ${item.slug}` : '', `عنوان سئو: ${item.seo_title}`, `توضیحات متا: ${item.meta_description}`, `مترادف‌ها: ${item.keyword_synonyms}`].filter(Boolean).join('\n');
        this.state.modal = {
          isOpen: true,
          title: escapeHtml(item.title),
          contentHtml: `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px"><span class="wila-badge wila-badge-neutral">${this.kindLabel(item.content_kind)}</span><span class="wila-badge wila-badge-neutral">${formatJalali(item.created_at)}</span><span class="wila-badge wila-badge-success">لینک داخلی: ${toPersianDigits(item.internal_link_count)}</span><span class="wila-badge wila-badge-neutral">لینک خارجی: ${toPersianDigits(item.external_link_count)}</span></div>`
            + this.copyField('wila-h-summary', 'خلاصه اطلاعات (برای کپی یکجا)', summary, { rows: 6 })
            + this.copyField('wila-h-keyword', 'کلیدواژه کانونی', item.focus_keyword)
            + this.copyField('wila-h-slug', 'نامک (اسلاگ)', item.slug, { ltr: true })
            + this.copyField('wila-h-seo-title', 'عنوان سئو', item.seo_title)
            + this.copyField('wila-h-meta', 'توضیحات متا', item.meta_description, { rows: 3 })
            + this.copyField('wila-h-synonyms', 'مترادف‌ها و عبارات کلیدی', item.keyword_synonyms)
            + (links.length ? this.copyField('wila-h-links', 'لینک‌های خارجی', links.map(l => `${l.anchor || ''} ${l.url}`.trim()).join('\n'), { rows: Math.min(6, links.length + 1), ltr: true }) : '')
            + this.copyField('wila-h-content', 'محتوای HTML', item.content, { rows: 16 })
            + this.copyField('wila-h-image', 'پرامپت تصویر شاخص', item.featured_image_prompt, { rows: 4, ltr: true })
        };
        this.render();
      } catch (e) { app.toast('خطا: ' + e.message); }
    },

    copyText: async function (text) {
      try { await navigator.clipboard.writeText(text); app.toast('کپی شد.'); }
      catch (e) {
        const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); app.toast('کپی شد.'); } catch (err) { app.toast('کپی ناموفق بود.'); }
        ta.remove();
      }
    },

    deleteHistoryItem: async function (id) {
      if (!confirm('این محتوا برای همیشه از آرشیو حذف شود؟ (صفحه منتشرشده تغییری نمی‌کند)')) return;
      try {
        const r = await this.apiFetch(`/content/history/${id}`, { method: 'DELETE' });
        if (!r.success) throw new Error(r.message || 'حذف انجام نشد.');
        this.state.contentHistory = this.state.contentHistory.filter(item => String(item.id) !== String(id));
        this.render();
        app.toast('از آرشیو حذف شد.');
      } catch (e) { app.toast('خطا: ' + e.message); }
    },

    startBatchScan: async function () {
      if (this.state.isScanning) return;
      this.state.isScanning = true;
      this.state.scanProgress = 0;
      this.render();

      try {
        const queueRes = await this.apiFetch('/queue');
        if (!queueRes.success || !queueRes.items) throw new Error('صف دریافت نشد.');

        const items = queueRes.items;
        this.state.scanTotal = items.length;

        for (let i = 0; i < items.length; i++) {
          try {
            await this.apiFetch('/scan-item', { method: 'POST', body: JSON.stringify({ id: items[i].id, type: items[i].type }) });
          } catch (itemError) {}
          this.state.scanCurrent = i + 1;
          this.state.scanProgress = Math.round(((i + 1) / items.length) * 100);
          this.render();
        }

        this.state.isScanning = false;
        await this.fetchSummary();
        app.toast('اسکن سایت کامل شد!');
      } catch (err) {
        app.toast('خطا در فرآیند اسکن: ' + err.message);
        this.state.isScanning = false;
        this.render();
      }
    },

    updatePlannerField: function (field, value) {
      this.state.plannerForm[field] = value;
    },

    getGeneratorPayload: function () {
      const f = this.state.generatorForm;
      const isArticle = f.contentKind === 'article';
      const internalLinks = f.internalLinks.split(/\n|,/).map(item => item.trim()).filter(Boolean).map(item => {
        const parts = item.split(/\s*:\s*/);
        return { anchor: parts.shift() || '', url: parts.join(':') || '' };
      }).filter(link => link.anchor && /^https?:\/\//i.test(link.url));
      return { content_kind: f.contentKind, target_id: isArticle ? 0 : f.targetId, h1_title: f.h1Title, focus_keyword: f.focusKeyword, long_tail: f.longTail, word_count: f.wordCount, funnel_stage: f.funnelStage, internal_links: internalLinks, description: f.description, use_orphan_links: f.useOrphanLinks && !internalLinks.length };
    },

    resetGenerated: function () {
      const st = this.state;
      st.contentOutline = null; st.generatedContent = ''; st.featuredImagePrompt = ''; st.generatedSlug = ''; st.generatedSeoTitle = '';
      st.generatedMetaDescription = ''; st.generatedKeywordSynonyms = ''; st.generatedInternalLinkCount = 0; st.generatedExternalLinkCount = 0; st.generatedExternalLinks = [];
    },

    kindLabel: function (kind) {
      return { article: 'مقاله', product: 'محصول', product_cat: 'دسته‌بندی محصول' }[kind] || 'مقاله';
    },

    setContentKind: function (kind) {
      const f = this.state.generatorForm;
      if (f.contentKind === kind) return;
      f.contentKind = kind; f.targetId = 0; f.h1Title = ''; f.focusKeyword = '';
      this.state.targets = []; this.state.targetsError = '';
      this.resetGenerated();
      if (kind !== 'article') this.fetchTargets('');
      this.render();
    },

    fetchTargets: async function (search) {
      const kind = this.state.generatorForm.contentKind;
      if (kind === 'article') return;
      this.state.targetsLoading = true; this.state.targetsError = '';
      this.updateTargetOptions();
      try {
        const r = await this.apiFetch(`/content/targets?kind=${encodeURIComponent(kind)}&search=${encodeURIComponent(search || '')}`);
        if (kind !== this.state.generatorForm.contentKind) return;
        if (!r.success) throw new Error(r.message || 'فهرست دریافت نشد.');
        this.state.targets = r.items || [];
      } catch (e) { this.state.targets = []; this.state.targetsError = e.message; }
      this.state.targetsLoading = false;
      this.updateTargetOptions();
    },

    updateTargetOptions: function () {
      const list = this.root && this.root.querySelector('#wila-target-select');
      if (!list) return;
      const { targets, targetsLoading, targetsError, generatorForm } = this.state;
      if (targetsLoading) list.innerHTML = '<option disabled>در حال بارگذاری...</option>';
      else if (targetsError) list.innerHTML = `<option disabled>${escapeHtml(targetsError)}</option>`;
      else if (!targets.length) list.innerHTML = '<option disabled>موردی یافت نشد.</option>';
      else list.innerHTML = targets.map(t => `<option value="${t.id}" ${Number(generatorForm.targetId) === Number(t.id) ? 'selected' : ''}>${escapeHtml(t.title)}${t.status && t.status !== 'publish' ? ' (پیش‌نویس)' : ''}</option>`).join('');
    },

    selectTarget: function (id) {
      const f = this.state.generatorForm;
      const target = this.state.targets.find(t => Number(t.id) === Number(id));
      if (!target) return;
      f.targetId = target.id;
      f.h1Title = target.title.split(' › ').pop();
      f.focusKeyword = f.h1Title;
      this.resetGenerated();
      this.render();
    },

    generateOutline: async function () {
      const p = this.getGeneratorPayload();
      if (p.content_kind !== 'article' && !p.target_id) return app.toast(`ابتدا ${this.kindLabel(p.content_kind)} مورد نظر را از فهرست انتخاب کنید.`);
      if (!p.h1_title.trim() || !p.focus_keyword.trim()) return app.toast('عنوان و کلیدواژه کانونی الزامی هستند.');
      this.state.isGeneratingContent = true; this.resetGenerated(); this.render();
      try { const r = await this.apiFetch('/content/outline', { method: 'POST', body: JSON.stringify(p) }); if (!r.success) throw new Error(r.message || 'طرح تولید نشد.'); this.state.contentOutline = r.outline; this.state.generatedSlug = r.outline.slug || ''; this.state.generatedSeoTitle = r.outline.seo_title || ''; this.state.generatedMetaDescription = r.outline.meta_description || ''; this.state.generatedKeywordSynonyms = r.outline.keyword_synonyms || ''; }
      catch (e) { app.toast('خطا: ' + e.message); } finally { this.state.isGeneratingContent = false; this.render(); }
    },

    generateFullContent: async function () {
      this.state.isGeneratingContent = true; this.render();
      try { const r = await this.apiFetch('/content/generate', { method: 'POST', body: JSON.stringify({ ...this.getGeneratorPayload(), outline: this.state.contentOutline }) }); if (!r.success) throw new Error(r.message || 'محتوا تولید نشد.'); this.state.generatedContent = r.content; this.state.featuredImagePrompt = r.featured_image_prompt || ''; this.state.generatedSlug = r.slug || ''; this.state.generatedSeoTitle = r.seo_title || ''; this.state.generatedMetaDescription = r.meta_description || ''; this.state.generatedKeywordSynonyms = r.keyword_synonyms || ''; this.state.generatedInternalLinkCount = Number(r.internal_link_count || 0); this.state.generatedExternalLinkCount = Number(r.external_link_count || 0); this.state.generatedExternalLinks = Array.isArray(r.external_links) ? r.external_links : []; }
      catch (e) { app.toast('خطا: ' + e.message); } finally { this.state.isGeneratingContent = false; this.render(); }
    },

    contentQualityReport: function (html, keyword) {
      const doc = new DOMParser().parseFromString(html, 'text/html');
      doc.querySelectorAll('script,style,.wila-image-placeholder').forEach(node => node.remove());
      const normalize = text => text.replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/\s+/g, ' ').trim();
      const words = text => normalize(text).match(/[\p{L}\p{N}]+(?:\u200c[\p{L}\p{N}]+)*/gu) || [];
      let section = 0, maxSection = 0;
      const walk = node => {
        if (node.nodeType === 3) { section += words(node.textContent).length; return; }
        if (/^H[23]$/.test(node.nodeName)) { maxSection = Math.max(maxSection, section); section = 0; return; }
        node.childNodes.forEach(walk);
      };
      walk(doc.body); maxSection = Math.max(maxSection, section);
      doc.querySelectorAll('p,li,div,h2,h3,br,tr').forEach(node => node.appendChild(doc.createTextNode('\n')));
      const text = doc.body.textContent;
      const sentences = text.split(/[.!?؟؛\n]+/u).map(words).filter(w => w.length);
      const count = words(text).length;
      const long = sentences.filter(w => w.length > 25).length;
      const percent = sentences.length ? Math.round(100 * long / sentences.length) : 0;
      const repeated = sentences.some((w, i) => i > 1 && w[0] === sentences[i - 1][0] && w[0] === sentences[i - 2][0]);
      const tokens = words(text), key = words(keyword);
      let occurrences = 0;
      if (key.length) for (let i = 0; i <= tokens.length - key.length; i++) {
        if (key.every((word, j) => word === tokens[i + j])) { occurrences++; i += key.length - 1; }
      }
      return `<p><strong>گزارش تقریبی خوانایی، مستقل از Yoast</strong></p><ul><li>تعداد واژه: ${count}؛ جمله‌های بیش از ۲۵ واژه: ${percent}٪ ${percent > 25 ? '— نیازمند کوتاه‌سازی' : ''}</li><li>آغاز مشابه سه جمله متوالی: ${repeated ? 'نیازمند اصلاح' : 'یافت نشد'}</li><li>طولانی‌ترین بخش بدون زیرعنوان: ${maxSection} واژه ${maxSection > 300 ? '— زیرعنوان اضافه کنید' : ''}</li><li>تکرار عبارت دقیق: ${occurrences}؛ هدف راهنمای متناسب با طول: ${Math.max(1, Math.ceil(count / 100))} (بدون تکرار اجباری)</li></ul><p>ساختار مجهول فارسی (هدف حداکثر ۱۰٪)، صحت منابع، انتیتی و E-E-A-T باید دستی بررسی شوند. این گزارش امتیاز Yoast یا تضمین رتبه نیست.</p>`;
    },

    copyGeneratedContent: async function () {
      const contentField = this.root.querySelector('#wila-generated-content');
      const content = contentField ? contentField.value : this.state.generatedContent;
      try { await navigator.clipboard.writeText(content); app.toast('تمام محتوای HTML کپی شد.'); }
      catch (e) {
        if (contentField) { contentField.select(); document.execCommand('copy'); app.toast('تمام محتوای HTML کپی شد.'); }
        else app.toast('کپی محتوا ناموفق بود.');
      }
    },

    createDraft: async function () {
      const contentField = this.root.querySelector('#wila-generated-content');
      const content = contentField ? contentField.value : this.state.generatedContent;
      try { const r = await this.apiFetch('/content/draft', { method: 'POST', body: JSON.stringify({ title: this.state.generatorForm.h1Title, content, slug: (this.root.querySelector('#wila-generated-slug') || {}).value || this.state.generatedSlug, focus_keyword: this.state.generatorForm.focusKeyword, keyword_synonyms: (this.root.querySelector('#wila-keyword-synonyms') || {}).value || this.state.generatedKeywordSynonyms, seo_title: (this.root.querySelector('#wila-seo-title') || {}).value || this.state.generatedSeoTitle, meta_description: (this.root.querySelector('#wila-meta-description') || {}).value || this.state.generatedMetaDescription }) }); if (!r.success) throw new Error(r.message || 'پیش‌نویس وبلاگ ایجاد نشد.'); window.open(r.edit_url, '_blank'); }
      catch (e) { app.toast('خطا: ' + e.message); }
    },

    applyToTarget: async function () {
      const f = this.state.generatorForm;
      const val = id => (this.root.querySelector('#' + id) || {}).value;
      const label = this.kindLabel(f.contentKind);
      if (!f.targetId || this.state.isApplying) return;
      if (!confirm(`محتوای فعلی ${label} «${f.h1Title}» با این محتوا جایگزین می‌شود (نسخه قبلی در پایگاه‌داده نگه داشته می‌شود). ادامه می‌دهید؟`)) return;
      this.state.isApplying = true; this.render();
      try {
        const r = await this.apiFetch('/content/apply', { method: 'POST', body: JSON.stringify({
          content_kind: f.contentKind, target_id: f.targetId, content: val('wila-generated-content') ?? this.state.generatedContent,
          focus_keyword: f.focusKeyword, keyword_synonyms: val('wila-keyword-synonyms') ?? this.state.generatedKeywordSynonyms,
          seo_title: val('wila-seo-title') ?? this.state.generatedSeoTitle, meta_description: val('wila-meta-description') ?? this.state.generatedMetaDescription
        }) });
        if (!r.success) throw new Error(r.message || 'انتشار انجام نشد.');
        window.location.assign(r.edit_url);
      } catch (e) { app.toast('خطا: ' + e.message); this.state.isApplying = false; this.render(); }
    },

    // ---------- تولید انبوه از شیت (CSV) ----------
    parseCsv: function (text) {
      text = String(text).replace(/^﻿/, '');
      const first = text.split(/\r?\n/)[0] || '';
      const count = d => (first.match(new RegExp(d === '\t' ? '\t' : '\\' + d, 'g')) || []).length;
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
    },

    mapCsvHeader: function (header) {
      const h = String(header).replace(/‌/g, ' ').replace(/[\u{1F300}-\u{1FAFF}☀-➿️]/gu, '').replace(/[ي]/g, 'ی').replace(/[ك]/g, 'ک').trim();
      const rules = [
        ['approved', /تایید|تأیید|approved/i], ['seo_title', /عنوان\s*سئو|seo\s*title/i], ['meta_description', /متا|meta/i],
        ['focus_keyword', /کانونی|focus/i], ['related_keywords', /مترادف|فرعی|related/i], ['slug', /نامک|slug|لینک/i],
        ['name', /نام\s*محصول|name|product/i], ['notes', /یادداشت|note/i], ['sole', /زیره|sole/i],
        ['upper', /رویه|آستر|کفی|upper/i], ['general', /کلی|general/i], ['description', /توضیحات|description/i]
      ];
      const hit = rules.find(([, re]) => re.test(h));
      return hit ? hit[0] : null;
    },

    isApprovedValue: function (value) {
      const v = String(value || '').trim().toLowerCase();
      return v !== '' && (/✅|✔|✓|☑/.test(v) || ['true', '1', 'yes', 'y', 'x', 'بله', 'تایید', 'تأیید', 'تایید شده'].includes(v));
    },

    loadBulkFile: async function (file) {
      if (!file) return;
      const b = this.state.bulk;
      try {
        const table = this.parseCsv(await file.text());
        if (table.length < 2) throw new Error('فایل خالی است یا ردیف داده ندارد.');
        const map = table[0].map(h => this.mapCsvHeader(h));
        if (!map.includes('name') && !map.includes('slug')) throw new Error('ستون «نام محصول» یا «نامک» در فایل پیدا نشد.');
        b.rows = table.slice(1).map(cells => {
          const row = { approved: false, run: '', match: null };
          map.forEach((key, idx) => { if (key) row[key] = (cells[idx] || '').trim(); });
          row.approved = this.isApprovedValue(row.approved);
          return row;
        }).filter(r => r.name || r.slug);
        b.fileName = file.name; b.selected = {}; b.log = []; b.filter = 'all'; b.matching = true;
        this.render();
        const res = await this.apiFetch('/bulk/match', { method: 'POST', body: JSON.stringify({ rows: b.rows.map(r => ({ name: r.name || '', slug: r.slug || '' })) }) });
        if (!res.success) throw new Error(res.message || 'تطبیق انجام نشد.');
        const hasApproval = map.includes('approved');
        res.matches.forEach((m, i) => {
          b.rows[i].match = m;
          if (m.matched && (!hasApproval || b.rows[i].approved)) b.selected[i] = true;
        });
        app.toast(`${toPersianDigits(res.matches.filter(m => m.matched).length)} از ${toPersianDigits(b.rows.length)} ردیف به محصولات سایت متصل شد.`);
      } catch (e) { b.rows = []; app.toast('خطا: ' + e.message); }
      b.matching = false;
      this.render();
    },

    bulkVisibleIndexes: function () {
      const b = this.state.bulk;
      return b.rows.map((r, i) => i).filter(i => b.filter === 'all' || (b.filter === 'approved' ? b.rows[i].approved : !b.rows[i].approved));
    },

    bulkLog: function (type, text) {
      const b = this.state.bulk;
      b.log.push({ type, text, time: new Date().toLocaleTimeString('fa-IR') });
      if (b.log.length > 300) b.log.shift();
    },

    bulkStatusHtml: function (row) {
      if (row.run === 'running') return '⏳ در حال تولید...';
      if (row.run === 'done') return `✅ انجام شد${row.editUrl ? ` — <a href="${escapeHtml(row.editUrl)}" target="_blank" rel="noopener">ویرایش</a>` : ''}`;
      if (row.run === 'error') return `❌ ${escapeHtml(row.error || 'خطا')}`;
      return '—';
    },

    bulkProgressHtml: function () {
      const b = this.state.bulk;
      const pct = b.total ? Math.round((b.done / b.total) * 100) : 0;
      return b.total ? `<div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:6px"><span>${b.running ? (b.paused ? 'متوقف موقت' : 'در حال پردازش...') : 'پایان / توقف'}</span><span>${toPersianDigits(b.done)} از ${toPersianDigits(b.total)} (${toPersianDigits(pct)}٪)</span></div><div class="wila-progress-bar-bg"><div class="wila-progress-bar-fill" style="width:${pct}%"></div></div>` : '';
    },

    bulkLogHtml: function () {
      const colors = { ok: '#047857', err: '#b91c1c', info: '#475569' };
      return this.state.bulk.log.map(l => `<div style="color:${colors[l.type] || colors.info}">[${escapeHtml(l.time)}] ${escapeHtml(l.text)}</div>`).join('');
    },

    bulkRefreshUi: function () {
      const b = this.state.bulk;
      const progress = this.root.querySelector('#wila-bulk-progress'); if (progress) progress.innerHTML = this.bulkProgressHtml();
      const log = this.root.querySelector('#wila-bulk-log'); if (log) { log.innerHTML = this.bulkLogHtml(); log.scrollTop = log.scrollHeight; }
      this.root.querySelectorAll('[data-bulk-status]').forEach(cell => { cell.innerHTML = this.bulkStatusHtml(b.rows[cell.getAttribute('data-bulk-status')]); });
    },

    startBulk: async function () {
      const b = this.state.bulk;
      if (b.running) return;
      const queue = b.rows.map((r, i) => i).filter(i => b.selected[i] && b.rows[i].match && b.rows[i].match.matched && b.rows[i].run !== 'done');
      if (!queue.length) return app.toast('هیچ ردیف متصل‌شده‌ای برای پردازش انتخاب نشده است.', 'error');
      if (b.mode === 'replace' && !confirm(`توضیحات فعلی ${toPersianDigits(queue.length)} محصول جایگزین می‌شود (نسخه قبلی در پایگاه‌داده نگه داشته می‌شود). ادامه می‌دهید؟`)) return;
      Object.assign(b, { running: true, paused: false, stopRequested: false, total: queue.length, done: 0 });
      this.bulkLog('info', `شروع پردازش ${toPersianDigits(queue.length)} محصول.`);
      this.render();
      const sleep = ms => new Promise(r => setTimeout(r, ms));
      for (const i of queue) {
        while (b.paused && !b.stopRequested) await sleep(300);
        if (b.stopRequested) break;
        const row = b.rows[i];
        row.run = 'running'; this.bulkLog('info', `در حال تولید: ${row.match.title}`); this.bulkRefreshUi();
        try {
          const r = await this.apiFetch('/bulk/process', { method: 'POST', body: JSON.stringify({ product_id: row.match.product_id, row, options: { mode: b.mode, inject_seo: b.injectSeo, auto_links: b.autoLinks } }) });
          if (!r.success) throw new Error(r.message || 'تولید انجام نشد.');
          row.run = 'done'; row.editUrl = r.edit_url;
          this.bulkLog('ok', `انجام شد: ${row.match.title} (لینک داخلی: ${toPersianDigits(r.internal_link_count)})`);
        } catch (e) {
          row.run = 'error'; row.error = e instanceof SyntaxError ? 'پاسخ نامعتبر سرور (احتمالاً Timeout)' : e.message;
          this.bulkLog('err', `خطا در ${row.match.title}: ${row.error}`);
        }
        b.done++; this.bulkRefreshUi();
      }
      this.bulkLog('info', b.stopRequested ? 'پردازش متوقف شد.' : 'پردازش صف به پایان رسید.');
      b.running = false; b.paused = false;
      this.render();
      app.toast(b.stopRequested ? 'پردازش متوقف شد.' : 'پردازش انبوه به پایان رسید.');
    },

    runLinkPlanner: async function () {
      const pf = this.state.plannerForm;

      if (!pf.h1Title.trim() || !pf.focusKeyword.trim()) {
        app.toast('لطفاً عنوان مقاله و کلیدواژه کانونی را وارد کنید.');
        return;
      }

      this.state.isPlanning = true;
      this.state.plannerResult = null;
      this.render();

      try {
        const res = await this.apiFetch('/ai/planner', {
          method: 'POST',
          body: JSON.stringify({
            h1_title: pf.h1Title,
            focus_keyword: pf.focusKeyword,
            long_tail: pf.longTail,
            content_type: pf.contentType,
            funnel_stage: pf.funnelStage,
            word_count: pf.wordCount
          })
        });

        if (!res.success || !res.blueprint) throw new Error(res.message || 'خطا در دریافت استراتژی.');

        this.state.plannerResult = res.blueprint;
      } catch (err) {
        app.toast('خطا: ' + err.message);
      } finally {
        this.state.isPlanning = false;
        this.render();
      }
    },

    showItemDetails: async function (id, type, title) {
      this.state.modal.isOpen = true;
      this.state.modal.title = `جزئیات پیوندهای: ${title}`;
      this.state.modal.contentHtml = '<p style="text-align:center; padding: 20px;">در حال واکشی اطلاعات...</p>';
      this.render();

      try {
        const details = await this.apiFetch(`/item-details?id=${id}&type=${type}`);
        let html = `
          <div style="margin-bottom: 20px;">
            <h4 style="font-weight:600; margin-bottom:8px; color:#0f172a;">لینک‌های خروجی (${toPersianDigits(details.outgoing_count)})</h4>
            ${details.outgoing_links.length === 0 ? '<p style="color:#94a3b8;">خروجی ندارد.</p>' : `
              <table class="wila-table" style="font-size:0.8rem;">
                <thead><tr><th>انکر تکست</th><th>آدرس مقصد</th></tr></thead>
                <tbody>${details.outgoing_links.map(l => `<tr><td style="font-weight:600;">${l.anchor_text}</td><td style="direction:ltr; text-align:left;">${l.target_url}</td></tr>`).join('')}</tbody>
              </table>
            `}
          </div>
          <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
            <h4 style="font-weight:600; margin-bottom:8px; color:#0f172a;">لینک‌های ورودی (${toPersianDigits(details.incoming_count)})</h4>
            ${details.incoming_links.length === 0 ? '<p style="color:#ef4444;">صفحه یتیم است.</p>' : `
              <table class="wila-table" style="font-size:0.8rem;">
                <thead><tr><th>صفحه مبدا</th><th>نوع</th><th>انکر تکست</th></tr></thead>
                <tbody>${details.incoming_links.map(l => `<tr><td>${l.source_title}</td><td><span class="wila-badge wila-badge-neutral">${l.type_label}</span></td><td style="font-weight:600;">${l.anchor_text}</td></tr>`).join('')}</tbody>
              </table>
            `}
          </div>
        `;
        this.state.modal.contentHtml = html;
        this.render();
      } catch (e) {
        this.state.modal.contentHtml = '<p style="color:#ef4444;">خطا در دریافت اطلاعات.</p>';
        this.render();
      }
    },

    requestAiSuggestions: async function (id, type, title) {
      if (this.suggestionsPending) return;
      const s = this.state.aiSettings;
      if (!s.hasAvalAIKey) {
        app.toast('لطفاً ابتدا کلید API را در بخش تنظیمات وارد کنید.');
        return;
      }

      this.suggestionsPending = true;
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 55000);
      this.state.modal.isOpen = true;
      this.state.modal.title = `پیشنهاد هوشمند برای: ${title}`;
      this.state.modal.contentHtml = `<div style="text-align:center; padding: 30px; font-weight:600;">در حال پردازش هوش مصنوعی...</div>`;
      this.render();

      try {
        const res = await this.apiFetch('/ai/suggest', { method: 'POST', body: JSON.stringify({ id, type }), signal: controller.signal });
        if (!res.success) throw new Error(res.message || 'خطا در تولید پاسخ.');

        if (!Array.isArray(res.suggestions)) throw new Error('ساختار پاسخ پیشنهادها معتبر نیست.');
        if (res.suggestions.length === 0) {
            this.state.modal.contentHtml = `<p style="text-align:center; padding:20px;">هوش مصنوعی هیچ صفحه مرتبطی پیدا نکرد.</p>`;
            this.render();
            return;
        }

        let html = '';
        res.suggestions.forEach((sugg, idx) => {
          html += `
            <div class="wila-ai-card">
              <div class="wila-ai-card-title">${toPersianDigits(idx + 1)}. مبدا: ${escapeHtml(sugg.source_title)} <span class="wila-badge wila-badge-neutral">${escapeHtml(sugg.type_label || sugg.source_type)}</span></div>
              <div style="margin: 6px 0; font-size:0.825rem;"><strong>انکر تکست‌ها:</strong> ${(Array.isArray(sugg.recommended_anchor_texts) ? sugg.recommended_anchor_texts : []).map(a => `<span class="wila-badge wila-badge-success" style="margin-left:4px;">${escapeHtml(a)}</span>`).join('')}</div>
              <div style="margin: 6px 0; font-size:0.825rem;"><strong>محل قرارگیری:</strong> ${escapeHtml(sugg.placement_guide)}</div>
              <div style="margin-top: 8px; font-size:0.8rem; color:#64748b; background:#ffffff; padding:8px; border-radius:4px; border:1px dashed #cbd5e1;"><strong>تحلیل:</strong> ${escapeHtml(sugg.reasoning)}</div>
            </div>`;
        });
        this.state.modal.contentHtml = html;
        this.render();
      } catch (err) {
        this.state.modal.contentHtml = `<p style="color:#ef4444; padding:20px; line-height: 1.6;">${escapeHtml(err.name === 'AbortError' ? 'زمان دریافت پیشنهاد به پایان رسید. لطفاً کمی بعد دوباره تلاش کنید.' : err.message)}</p>`;
        this.render();
      } finally {
        clearTimeout(timeout);
        this.suggestionsPending = false;
      }
    },

    fetchTaxonomies: async function (postType) {
      try {
        const taxes = await this.apiFetch(`/taxonomies/${encodeURIComponent(postType || '')}`);
        this.state.taxonomies = taxes.items || [];
        this.render();
      } catch (e) {}
    },

    testConnection: async function () {
      const avalaiKey = document.getElementById('wila-avalai-key-input')?.value.trim() || '';
      const resultBox = document.getElementById('wila-test-result');
      const testBtn = document.getElementById('wila-test-api-btn');
      if (!avalaiKey && !this.state.aiSettings.hasAvalAIKey) {
        app.toast('لطفاً کلید API سرویس AvalAI را وارد کنید.');
        return;
      }
      if (testBtn) { testBtn.disabled = true; testBtn.innerText = 'در حال بررسی...'; }
      if (resultBox) { resultBox.style.display = 'block'; resultBox.innerHTML = '<div style="background:#f8fafc; padding:12px; font-size:0.825rem;">در حال تست اتصال به AvalAI...</div>'; }
      try {
        const res = await this.apiFetch('/test-connection', { method: 'POST', body: JSON.stringify({ avalai_api_key: avalaiKey }) });
        if (!res.success) throw new Error(res.message || res.code || 'خطا در برقراری ارتباط');
        if (resultBox) resultBox.innerHTML = `<div style="background:#ecfdf5; padding:14px; color:#065f46;"><strong>اتصال موفقیت‌آمیز بود.</strong><div>${res.message}</div><div>مدل: <code>${res.model}</code></div></div>`;
      } catch (err) {
        if (resultBox) resultBox.innerHTML = `<div style="background:#fef2f2; padding:14px; color:#991b1b;">${escapeHtml(err.message)}</div>`;
      } finally {
        if (testBtn) { testBtn.disabled = false; testBtn.innerText = 'تست اتصال'; }
      }
    },

    saveSettings: async function () {
      const avalaiKey = document.getElementById('wila-avalai-key-input')?.value.trim() || '';
      const homepageKeywords = document.getElementById('wila-homepage-keywords-input')?.value.trim() || '';

      try {
        await this.apiFetch('/settings', {
          method: 'POST',
          body: JSON.stringify({
            provider: 'avalai',
            avalai_api_key: avalaiKey,
            homepage_keywords: homepageKeywords
          })
        });
        app.toast('تنظیمات با موفقیت ذخیره شد.');
        await this.fetchSettings();
      } catch (e) {
        app.toast('خطا در ذخیره تنظیمات.');
      }
    },

    closeModal: function () {
      this.state.modal.isOpen = false;
      this.render();
    },

    render: function () {
      const { activeTab, summary, isScanning, scanProgress, scanTotal, scanCurrent, modal, aiSettings, plannerForm, plannerResult, isPlanning, isGeneratingContent } = this.state;
      let contentHtml = '';

      if (activeTab === 'overview') {
        let cardsHtml = '';
        if (summary && summary.breakdown) {
          Object.keys(summary.breakdown).forEach(key => {
            const b = summary.breakdown[key];
            if (b.count > 0) {
              cardsHtml += `<div class="wila-card"><div class="wila-card-header"><h3>${b.label}</h3></div><div class="wila-card-value">${toPersianDigits(b.count)}</div></div>`;
            }
          });
        }

        if (!summary) cardsHtml = '<div class="wila-card"><div class="wila-skeleton" style="height:14px;width:50%"></div><div class="wila-skeleton" style="height:30px;width:35%;margin-top:14px"></div></div>'.repeat(3);
        contentHtml = `
          <div class="wila-progress-wrapper">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <div>
                <h3 style="margin:0 0 4px 0; font-size:1rem; font-weight:600;">پویش و تحلیل ساختار لینک‌های داخلی</h3>
                <p style="margin:0; font-size:0.8rem; color:#64748b;">${isScanning ? `در حال آنالیز آیتم ${toPersianDigits(scanCurrent)} از ${toPersianDigits(scanTotal)}` : 'برای به‌‌روزرسانی اطلاعات لینک‌ها، موتور اسکنر را اجرا کنید.'}</p>
              </div>
              <button class="wila-btn wila-btn-primary" id="wila-start-scan-btn" ${isScanning ? 'disabled' : ''}>${isScanning ? 'در حال اسکن...' : 'شروع اسکن کامل'}</button>
            </div>
            ${isScanning ? `<div class="wila-progress-bar-bg"><div class="wila-progress-bar-fill" style="width: ${scanProgress}%;"></div></div>` : ''}
          </div>
          
          <div class="wila-grid">
            <div class="wila-card"><div class="wila-card-header"><h3>کل پیوندهای یافت‌شده</h3></div><div class="wila-card-value">${summary ? toPersianDigits(summary.total_links) : '---'}</div></div>
            ${cardsHtml}
            <div class="wila-card" style="border-right: 4px solid #ef4444;"><div class="wila-card-header"><h3 style="color:#ef4444;">صفحات یتیم</h3></div><div class="wila-card-value" style="color:#ef4444;">${summary ? toPersianDigits(summary.orphan_count) : '---'}</div></div>
          </div>`;
      } else if (activeTab === 'generator') {
        const f = this.state.generatorForm;
        const esc = escapeHtml;
        const isArticle = f.contentKind === 'article';
        const kindLabel = this.kindLabel(f.contentKind);
        const seoLen = [...(this.state.generatedSeoTitle || '')].length;
        const field = (key, label, type = 'text') => `<label style="display:block;margin:10px 0 4px;font-weight:600">${label}</label><input data-generator-field="${key}" type="${type}" class="wila-input" style="width:100%" value="${esc(f[key])}">`;
        contentHtml = `<div class="wila-card" style="max-width:900px"><h3>تولیدکننده محتوای هوشمند سئو</h3><p>طرح را تایید کنید، سپس محتوای HTML تولید و به پیش‌نویس وردپرس منتقل کنید.</p>
          <label style="display:block;margin:10px 0 4px;font-weight:600">نوع محتوا</label><div style="display:flex;gap:8px;flex-wrap:wrap">${[['article','مقاله'],['product','محصول'],['product_cat','دسته‌بندی محصول']].map(([k, l]) => `<button type="button" class="wila-btn ${f.contentKind === k ? 'wila-btn-primary' : 'wila-btn-outline'} wila-kind-btn" data-kind="${k}">${l}</button>`).join('')}</div>
          ${isArticle ? field('h1Title','عنوان اصلی / H1') : `<label style="display:block;margin:14px 0 4px;font-weight:600">انتخاب ${kindLabel} از فهرست</label><input id="wila-target-search" type="search" class="wila-input" style="width:100%;margin-bottom:6px" placeholder="جستجو در ${kindLabel}ها..."><select id="wila-target-select" class="wila-select" size="8" style="width:100%;height:auto"></select><p style="margin:5px 0;color:#64748b;font-size:.8rem">${f.targetId ? `انتخاب‌شده: <strong>${esc(f.h1Title)}</strong>` : `یک ${kindLabel} را انتخاب کنید؛ اطلاعات واقعی آن به هوش مصنوعی داده می‌شود.`}</p>`}${field('focusKeyword','کلیدواژه کانونی')}${field('longTail','کلیدواژه‌های لانگ‌تیل')}${field('wordCount','تعداد کلمات','number')}
          <label style="display:block;margin:10px 0 4px;font-weight:600">توضیحات و الزامات اختصاصی محتوا</label><textarea data-generator-field="description" class="wila-input" rows="5" style="width:100%" placeholder="نکات، منابع، محصولات یا بخش‌هایی که باید حتماً در محتوا پوشش داده شوند...">${esc(f.description)}</textarea>
          ${this.state.generatedContent ? `<div style="margin-top:20px"><div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px"><span class="wila-badge wila-badge-success">لینک داخلی: ${toPersianDigits(this.state.generatedInternalLinkCount)}</span><span class="wila-badge wila-badge-neutral">لینک خارجی: ${toPersianDigits(this.state.generatedExternalLinkCount)}</span></div>${isArticle ? `<label style="display:block;margin:0 0 6px;font-weight:600">اسلاگ انگلیسی و سئو</label><input id="wila-generated-slug" class="wila-input" style="width:100%;direction:ltr;text-align:left" value="${esc(this.state.generatedSlug)}">` : ''}<label style="display:block;margin:16px 0 6px;font-weight:600">عنوان سئو (Yoast) <span id="wila-seo-title-len" style="font-weight:400;font-size:.8rem;color:${seoLen >= 50 && seoLen <= 60 ? '#16a34a' : '#d97706'}">${toPersianDigits(seoLen)} کاراکتر (ایده‌آل ۵۰ تا ۶۰)</span></label><input id="wila-seo-title" class="wila-input" style="width:100%" value="${esc(this.state.generatedSeoTitle)}"><label style="display:block;margin:16px 0 6px;font-weight:600">توضیحات متا (Yoast)</label><textarea id="wila-meta-description" class="wila-input" rows="3" style="width:100%">${esc(this.state.generatedMetaDescription)}</textarea><label style="display:block;margin:16px 0 6px;font-weight:600">مترادف‌ها و عبارات کلیدی (Yoast)</label><input id="wila-keyword-synonyms" class="wila-input" style="width:100%" value="${esc(this.state.generatedKeywordSynonyms)}"><label style="display:block;margin:16px 0 6px;font-weight:600">محتوای نهایی HTML</label><textarea id="wila-generated-content" class="wila-input" rows="18" style="width:100%;direction:rtl">${esc(this.state.generatedContent)}</textarea>${this.state.generatedExternalLinks.length ? `<p style="margin:12px 0 4px;font-weight:600">منابع خارجی پیشنهادی</p><ul>${this.state.generatedExternalLinks.map(link => `<li><a href="${esc(link.url)}" target="_blank" rel="noopener">${esc(link.anchor || link.url)}</a></li>`).join('')}</ul>` : ''}${this.state.featuredImagePrompt ? `<label style="display:block;margin:16px 0 6px;font-weight:600">پرامپت تصویر شاخص</label><textarea id="wila-featured-image-prompt" class="wila-input" rows="5" style="width:100%;direction:ltr;text-align:left">${esc(this.state.featuredImagePrompt)}</textarea>` : ''}<div style="margin-top:10px"><button id="wila-copy-content" class="wila-btn wila-btn-outline">کپی تمام محتوا</button> ${isArticle ? '<button id="wila-create-draft" class="wila-btn wila-btn-primary">ایجاد پیش‌نویس در وبلاگ</button>' : `<button id="wila-apply-target" class="wila-btn wila-btn-primary" ${this.state.isApplying ? 'disabled' : ''}>${this.state.isApplying ? 'در حال انتشار...' : `انتشار در صفحه ${kindLabel}`}</button>`}</div></div>` : ''}
          <label style="display:block;margin:10px 0 4px;font-weight:600">فاز قیف</label><select data-generator-field="funnelStage" class="wila-select" style="width:100%">${['TOFU (آگاهی)','MOFU (بررسی)','BOFU (خرید/تبدیل)','تشخیص خودکار'].map(x=>`<option ${f.funnelStage===x?'selected':''}>${x}</option>`).join('')}</select>
          <label style="display:block;margin:10px 0 4px;font-weight:600">نقشه لینک داخلی</label><textarea data-generator-field="internalLinks" class="wila-input" rows="4" style="width:100%" placeholder="انکرتکست: https://example.com/page\nیا با ویرگول جدا کنید">${esc(f.internalLinks)}</textarea>
          <label style="display:flex;gap:8px;align-items:center;margin-top:10px;cursor:pointer"><input type="checkbox" id="wila-use-orphan-links" ${f.useOrphanLinks ? 'checked' : ''}> اگر نقشه لینک خالی است، صفحات یتیم مرتبط را خودکار و استراتژیک لینک‌سازی کن</label><p style="margin:5px 0;color:#64748b;font-size:.8rem">افزونه فقط صفحه‌های یتیم مرتبط را انتخاب می‌کند و انکرتکست را بر اساس مقصد می‌سازد.</p>
          <button id="wila-generate-outline" class="wila-btn wila-btn-primary" style="margin-top:16px" ${isGeneratingContent?'disabled':''}>${isGeneratingContent?'در حال تولید...':'۱. تولید طرح ساختاری'}</button>
          ${this.state.contentOutline ? `<div style="margin-top:20px"><h4>طرح تاییدشده</h4><p><strong>نیت جستجو:</strong> ${esc(this.state.contentOutline.search_intent)}</p><ul>${(this.state.contentOutline.headings||[]).map(h=>`<li><strong>${esc(h.level)}:</strong> ${esc(h.text)} — ${esc(h.purpose)}</li>`).join('')}</ul><button id="wila-generate-full" class="wila-btn wila-btn-primary" ${isGeneratingContent?'disabled':''}>۲. تایید طرح و نگارش کامل</button></div>`:''}
          ${this.state.contentOutline ? `<details><summary>پیشنهاد سئو طرح</summary><p>عنوان: ${esc(this.state.contentOutline.seo_title)}</p><p>متا: ${esc(this.state.contentOutline.meta_description)}</p><p>مترادف‌ها: ${esc(this.state.contentOutline.keyword_synonyms)}</p>${isArticle ? `<p>نامک: ${esc(this.state.contentOutline.slug)}</p>` : ''}<p>انتیتی‌ها: ${esc((Array.isArray(this.state.contentOutline.entities) ? this.state.contentOutline.entities : []).join('، '))}</p><p>شواهد لازم: ${esc((Array.isArray(this.state.contentOutline.evidence_needed) ? this.state.contentOutline.evidence_needed : []).join('، '))}</p></details>` : ''}
          ${this.state.generatedContent ? `<div id="wila-quality-report">${this.contentQualityReport(this.state.generatedContent, f.focusKeyword)}</div><p>${isArticle ? 'اطلاعات سئو همراه پیش‌نویس ذخیره می‌شوند و هنگام انتشار در ویرایشگر باقی می‌مانند. نامک Yoast همان نامک وردپرس است.' : `با «انتشار»، محتوا و اطلاعات سئو مستقیم در صفحه ${kindLabel} جایگذاری می‌شود و به ویرایشگر همان صفحه منتقل می‌شوید.`} نمایش مترادف‌ها به نسخه Premium یوست وابسته است.</p>` : ''}
          <div style="margin-top:28px;border-top:1px solid #e2e8f0;padding-top:20px"><h3 style="margin-top:0">آرشیو محتوای تولیدشده</h3><p style="color:#64748b;font-size:.85rem">۱۰۰ محتوای آخر با تاریخ شمسی نگهداری می‌شود. در «مشاهده» همه اطلاعات قابل کپی است.</p><div class="wila-table-scroll"><table class="wila-table"><thead><tr><th>عنوان</th><th>نوع</th><th>کلیدواژه</th><th>عنوان سئو</th><th>لینک‌ها</th><th>تاریخ</th><th>عملیات</th></tr></thead><tbody>${this.state.historyLoading ? [1, 2, 3].map(() => '<tr>' + '<td><div class="wila-skeleton" style="height:14px"></div></td>'.repeat(7) + '</tr>').join('') : this.state.contentHistory.length ? this.state.contentHistory.map(item => `<tr><td style="font-weight:600">${esc(item.title)}</td><td><span class="wila-badge wila-badge-neutral">${this.kindLabel(item.content_kind)}</span></td><td>${esc(item.focus_keyword)}</td><td style="max-width:260px">${esc(item.seo_title || '')}</td><td style="white-space:nowrap">${toPersianDigits(item.internal_link_count)} داخلی / ${toPersianDigits(item.external_link_count)} خارجی</td><td style="white-space:nowrap">${formatJalali(item.created_at)}</td><td style="white-space:nowrap"><button class="wila-btn wila-btn-outline wila-btn-sm wila-history-view" data-id="${item.id}">مشاهده و کپی</button> <button class="wila-btn wila-btn-outline wila-btn-sm wila-history-delete" data-id="${item.id}" style="color:#dc2626">حذف</button></td></tr>`).join('') : '<tr><td colspan="7" style="text-align:center;padding:20px;color:#64748b">هنوز محتوایی تولید نشده است.</td></tr>'}</tbody></table></div></div>
        </div>`;
      } else if (activeTab === 'bulk') {
        const b = this.state.bulk;
        const visible = this.bulkVisibleIndexes();
        const matchedCount = b.rows.filter(r => r.match && r.match.matched).length;
        const selectedCount = Object.keys(b.selected).filter(i => b.selected[i]).length;
        const allVisibleSelected = visible.length && visible.every(i => b.selected[i]);
        const locked = b.running ? 'disabled' : '';
        contentHtml = `<div class="wila-card">
          <h3 style="margin-top:0">تولید محتوای انبوه از شیت (CSV Bulk Generator)</h3>
          <p style="color:#64748b;font-size:.85rem">فایل CSV با کدگذاری UTF-8 را بارگذاری کنید. ستون‌ها: تایید سئو، نام محصول، نامک (اختیاری)، توضیحات، عنوان سئو، توضیحات متا، کلیدواژه کانونی، عبارات مترادف و فرعی، یادداشت، ویژگی‌های زیره، رویه/آستر/کفی و ویژگی‌های کلی. هر ردیف ابتدا با نام محصول و سپس با نامک به محصولات سایت متصل می‌شود.</p>
          <input type="file" id="wila-bulk-file" accept=".csv,text/csv" class="wila-input" style="width:100%" ${locked}>
          ${b.matching ? '<div class="wila-skeleton" style="height:14px;margin-top:14px"></div><div class="wila-skeleton" style="height:14px;margin-top:8px"></div>' : ''}
          ${b.rows.length && !b.matching ? `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px">
              <div><label style="display:block;margin-bottom:4px;font-weight:600">حالت درج محتوا</label>
                <select id="wila-bulk-mode" class="wila-select" style="width:100%" ${locked}>
                  <option value="replace" ${b.mode === 'replace' ? 'selected' : ''}>جایگزینی کامل توضیحات محصول</option>
                  <option value="append" ${b.mode === 'append' ? 'selected' : ''}>افزودن به انتهای توضیحات فعلی</option>
                  <option value="draft" ${b.mode === 'draft' ? 'selected' : ''}>ذخیره به‌عنوان پیش‌نویس بازبینی (بدون تغییر صفحه زنده)</option>
                </select></div>
              <div><label style="display:block;margin-bottom:4px;font-weight:600">فیلتر ستون «تایید سئو»</label>
                <select id="wila-bulk-filter" class="wila-select" style="width:100%">
                  <option value="all" ${b.filter === 'all' ? 'selected' : ''}>همه ردیف‌ها</option>
                  <option value="approved" ${b.filter === 'approved' ? 'selected' : ''}>فقط تاییدشده ✅</option>
                  <option value="unapproved" ${b.filter === 'unapproved' ? 'selected' : ''}>فقط تاییدنشده</option>
                </select></div>
            </div>
            <label style="display:flex;gap:8px;align-items:center;margin-top:10px;cursor:pointer"><input type="checkbox" id="wila-bulk-seo" ${b.injectSeo ? 'checked' : ''} ${locked}> ذخیره خودکار عنوان سئو، توضیحات متا و کلیدواژه در متافیلدهای Yoast و Rank Math</label>
            <label style="display:flex;gap:8px;align-items:center;margin-top:6px;cursor:pointer"><input type="checkbox" id="wila-bulk-links" ${b.autoLinks ? 'checked' : ''} ${locked}> لینک‌سازی داخلی خودکار (دسته‌بندی محصول + ۲ محصول هم‌دسته)</label>
            <p style="margin:12px 0 6px;font-size:.85rem">${toPersianDigits(matchedCount)} از ${toPersianDigits(b.rows.length)} ردیف متصل شد؛ ${toPersianDigits(selectedCount)} ردیف انتخاب شده است.</p>
            <div class="wila-table-scroll"><table class="wila-table"><thead><tr>
              <th><input type="checkbox" id="wila-bulk-select-all" ${allVisibleSelected ? 'checked' : ''} ${locked}></th><th>#</th><th>نام محصول (شیت)</th><th>تایید سئو</th><th>تطبیق</th><th>محصول سایت</th><th>وضعیت پردازش</th>
            </tr></thead><tbody>${visible.map(i => {
              const r = b.rows[i], m = r.match;
              const matchHtml = !m ? '—' : m.matched ? `✅ متصل شد <span class="wila-badge wila-badge-neutral">${m.via === 'slug' ? 'با نامک' : m.via === 'name_ambiguous' ? 'با نام (چند مورد مشابه)' : 'با نام'}</span>` : '❌ پیدا نشد';
              return `<tr><td><input type="checkbox" class="wila-bulk-row" data-index="${i}" ${b.selected[i] ? 'checked' : ''} ${m && m.matched && !b.running ? '' : 'disabled'}></td><td>${toPersianDigits(i + 1)}</td><td style="font-weight:600">${escapeHtml(r.name || r.slug || '')}</td><td>${r.approved ? '✅' : '—'}</td><td>${matchHtml}</td><td>${m && m.matched ? `<a href="${escapeHtml(m.url)}" target="_blank" rel="noopener">${escapeHtml(m.title)}</a>` : '—'}</td><td data-bulk-status="${i}">${this.bulkStatusHtml(r)}</td></tr>`;
            }).join('')}</tbody></table></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
              <button id="wila-bulk-start" class="wila-btn wila-btn-primary" ${b.running ? 'disabled' : ''}>${b.running ? 'در حال پردازش...' : 'شروع تولید انبوه'}</button>
              ${b.running ? `<button id="wila-bulk-pause" class="wila-btn wila-btn-outline">${b.paused ? 'ادامه' : 'مکث'}</button><button id="wila-bulk-stop" class="wila-btn wila-btn-outline" style="color:#dc2626">توقف</button>` : ''}
            </div>
            <div id="wila-bulk-progress" style="margin-top:14px">${this.bulkProgressHtml()}</div>
            <div id="wila-bulk-log" style="margin-top:10px;max-height:220px;overflow:auto;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:.8rem;line-height:1.9">${this.bulkLogHtml()}</div>
          ` : ''}
        </div>`;
      } else if (activeTab === 'planner') {
        const pf = plannerForm;
        const contentTypes = [
          'پیلار پیج (Pillar)', 'راهنمای خرید', 'کلاستر تجاری', 'بررسی محصول', 
          'کلاستر مقایسه‌ای', 'راهنمای تخصصی', 'کلاستر درمانی', 'راهنمای کاربردی', 
          'پست سریع (Quick Win)', 'مقاله آموزشی عمومی'
        ];
        const funnelStages = [
          'TOFU (آگاهی / بالاي قیف)', 'MOFU (بررسی / میان قیف)', 'BOFU (تصمیم خرید / ته قیف)'
        ];

        contentHtml = `
          <div style="max-width:800px; background:#fff; padding:24px; border-radius:8px; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
            <h3 style="margin-top:0; font-size:1.1rem; color:#0f172a; font-weight:600;">🎯 برنامه‌‌ریز هوشمند لینک‌سازی پیش از نگارش</h3>
            <p style="color:#64748b; font-size:0.875rem; margin-bottom:20px;">مشخصات مقاله جدید را وارد کنید تا هوش مصنوعی نقشه راه انکرتکست‌ها و مقاصد لینک‌دهی را بر اساس تقویم محتوایی به شما تحویل دهد.</p>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:16px;">
              <div>
                <label style="display:block; font-size:0.825rem; font-weight:500; margin-bottom:6px;">عنوان مقاله (H1):</label>
                <input type="text" id="wila-plan-title" class="wila-input" style="width:100%;" value="${pf.h1Title}" placeholder="مثلاً: راهنمای خرید کفش چرم..." />
              </div>
              <div>
                <label style="display:block; font-size:0.825rem; font-weight:500; margin-bottom:6px;">کلیدواژه کانونی (Focus Keyword):</label>
                <input type="text" id="wila-plan-keyword" class="wila-input" style="width:100%;" value="${pf.focusKeyword}" placeholder="مثلاً: کفش چرم مردانه" />
              </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:16px;">
              <div>
                <label style="display:block; font-size:0.825rem; font-weight:500; margin-bottom:6px;">کلمات کلیدی طولانی (Long-Tail):</label>
                <input type="text" id="wila-plan-longtail" class="wila-input" style="width:100%;" value="${pf.longTail}" placeholder="مثلاً: قیمت کفش چرم تبریز..." />
              </div>
              <div>
                <label style="display:block; font-size:0.825rem; font-weight:500; margin-bottom:6px;">تعداد کلمات حدودی:</label>
                <input type="number" id="wila-plan-words" class="wila-input" style="width:100%;" value="${pf.wordCount}" />
              </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:24px;">
              <div>
                <label style="display:block; font-size:0.825rem; font-weight:500; margin-bottom:6px;">نوع محتوا (تقویم محتوایی):</label>
                <select id="wila-plan-type" class="wila-select" style="width:100%;">
                  ${contentTypes.map(ct => `<option value="${ct}" ${pf.contentType === ct ? 'selected' : ''}>${ct}</option>`).join('')}
                </select>
              </div>
              <div>
                <label style="display:block; font-size:0.825rem; font-weight:500; margin-bottom:6px;">مرحله قیف بازاریابی (Funnel Stage):</label>
                <select id="wila-plan-funnel" class="wila-select" style="width:100%;">
                  ${funnelStages.map(fs => `<option value="${fs}" ${pf.funnelStage === fs ? 'selected' : ''}>${fs}</option>`).join('')}
                </select>
              </div>
            </div>

            <button class="wila-btn wila-btn-primary" id="wila-run-planner-btn" ${isPlanning ? 'disabled' : ''}>
              ${isPlanning ? 'در حال تحلیل و تدوین استراتژی (لطفاً صبور باشید)...' : '✨ تولید نقشه راه لینک‌سازی'}
            </button>

            ${plannerResult ? `
              <div style="margin-top:24px; padding:20px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px;">
                <h4 style="margin-top:0; color:#0f172a; font-size:1rem;">📌 استراتژی و تحلیل کلی:</h4>
                <p style="color:#334155; font-size:0.875rem; line-height:1.6;">${plannerResult.strategy_notes}</p>
                <div style="margin-top:10px; font-size:0.85rem; font-weight:600; color:#2563eb;">تعداد کل لینک‌های داخلی پیشنهادی: ${toPersianDigits(plannerResult.total_recommended_links)} لینک</div>

                <h4 style="margin-top:20px; color:#0f172a; font-size:0.95rem;">🔗 لینک‌های خروجی (به صفحات موجود و صفحه اصلی سایت):</h4>
                <table class="wila-table" style="background:#fff; margin-top:8px;">
                  <thead><tr><th>مقصد هدف</th><th>انکرتکست پیشنهادی</th><th>محل قرارگیری</th></tr></thead>
                  <tbody>
                    ${plannerResult.outgoing_links.map(l => `
                      <tr>
                        <td><a href="${l.target_url}" target="_blank" style="color:#2563eb; font-weight:600; text-decoration:none;">${l.target_title}</a></td>
                        <td><span class="wila-badge wila-badge-success">${l.anchor_text}</span></td>
                        <td style="font-size:0.8rem; color:#475569;">${l.placement}</td>
                      </tr>
                    `).join('')}
                  </tbody>
                </table>

                <h4 style="margin-top:20px; color:#0f172a; font-size:0.95rem;">🔗 لینک‌های ورودی معکوس (جلوگیری از یتیم شدن پس از انتشار):</h4>
                <ul style="margin:8px 0 0 20px; padding:0; font-size:0.85rem; color:#334155; line-height:1.8;">
                  ${plannerResult.incoming_link_suggestions.map(inc => `<li><strong>${inc.old_page_title}:</strong> ${inc.action}</li>`).join('')}
                </ul>
              </div>
            ` : ''}
          </div>`;
      } else if (activeTab === 'items') {
        let optionsHtml = `<option value="all" ${this.state.filterType === 'all' ? 'selected' : ''}>همه موارد</option>`;
        if (summary && summary.breakdown) {
          Object.keys(summary.breakdown).forEach(key => {
            const b = summary.breakdown[key];
            if (b.count > 0) {
              optionsHtml += `<option value="${key}" ${this.state.filterType === key ? 'selected' : ''}>${b.label}</option>`;
            }
          });
        }

        contentHtml = `
          <div class="wila-table-container">
            <div class="wila-table-toolbar">
              <select class="wila-select" id="wila-filter-type">
                ${optionsHtml}
              </select>
            </div>
            <table class="wila-table">
              <thead><tr><th>عنوان</th><th>نوع محتوا</th><th>ورودی</th><th>خروجی</th><th>عملیات</th></tr></thead>
              <tbody>
                ${this.state.items.length === 0 ? '<tr><td colspan="5" style="text-align:center; padding:30px;">داده‌ای یافت نشد. اسکنر را اجرا کنید.</td></tr>' : 
                  this.state.items.map(item => `
                    <tr>
                      <td style="font-weight:600;"><a href="${item.url}" target="_blank" style="color:inherit; text-decoration:none;">${item.title}</a></td>
                      <td><span class="wila-badge wila-badge-neutral">${item.type_label || item.type}</span></td>
                      <td><strong style="color: ${item.in_links === 0 ? '#ef4444' : '#0f172a'};">${toPersianDigits(item.in_links)}</strong></td>
                      <td>${toPersianDigits(item.out_links)}</td>
                      <td><button class="wila-btn wila-btn-outline wila-btn-sm wila-inspect-btn" data-id="${item.id}" data-type="${item.type}" data-title="${item.title}">مشاهده</button></td>
                    </tr>
                  `).join('')}
              </tbody>
            </table>
          </div>`;
      } else if (activeTab === 'orphans') {
        const priorityGroups = [
          { id: 'product_cat', title: 'اولویت ۱: دسته‌بندی محصولات', desc: 'هاب‌های تجاری و مهم‌ترین مسیر توزیع اعتبار.', items: [] },
          { id: 'product', title: 'اولویت ۲: محصولات ووکامرس', desc: 'صفحات تبدیل که باید از محتوای مرتبط لینک ورودی بگیرند.', items: [] },
          { id: 'articles', title: 'اولویت ۳: مقالات بلاگ', desc: 'محتوای آموزشی و لانگ‌تیل؛ شامل نوشته‌ها و نوع‌های محتوای سفارشی.', items: [] },
          { id: 'page', title: 'اولویت ۴: برگه‌های عمومی', desc: 'صفحات خدمات و اطلاعات عمومی با نیاز لینک‌سازی متناسب.', items: [] }
        ];

        this.state.orphans.forEach(item => {
          if (item.type === 'product_cat') priorityGroups[0].items.push(item);
          else if (item.type === 'product') priorityGroups[1].items.push(item);
          else if (item.type === 'page') priorityGroups[3].items.push(item);
          else priorityGroups[2].items.push(item);
        });

        let orphansHtml = '';
        if (this.state.orphans.length === 0) {
          orphansHtml = '<div style="text-align:center; padding:40px; color:#64748b;">هیچ صفحه یتیمی یافت نشد. عالی است!</div>';
        } else {
          priorityGroups.forEach(group => {
            if (group.items.length > 0) {
              orphansHtml += `
                <div style="margin-bottom: 24px; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                  <div style="background: #f8fafc; padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                      <h3 style="margin: 0 0 4px 0; color: #0f172a; font-size: 1rem; font-weight: 600;">${group.title}</h3>
                      <p style="margin: 0; color: #64748b; font-size: 0.825rem;">${group.desc}</p>
                    </div>
                    <span class="wila-badge wila-badge-warning">${toPersianDigits(group.items.length)} مورد</span>
                  </div>
                  <table class="wila-table" style="margin:0; border:none; width:100%;">
                    <thead>
                      <tr style="background:transparent;">
                        <th style="padding-left:20px; padding-right:20px;">عنوان محتوا</th>
                        <th>نوع سیستم</th>
                        <th style="text-align:left; padding-left:20px;">عملیات هوش مصنوعی</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${group.items.map(item => `
                        <tr>
                          <td style="font-weight:600; padding-left:20px; padding-right:20px;"><a href="${item.url}" target="_blank" style="color:#2563eb; text-decoration:none;">${item.title}</a></td>
                          <td><span class="wila-badge wila-badge-neutral">${item.type_label}</span></td>
                          <td style="text-align:left; padding-left:20px;">
                            <button class="wila-btn wila-btn-primary wila-btn-sm wila-ai-btn" data-id="${item.id}" data-type="${item.type}" data-title="${item.title}">
                              ✨ پیشنهاد لینک
                            </button>
                          </td>
                        </tr>
                      `).join('')}
                    </tbody>
                  </table>
                </div>
              `;
            }
          });
        }

        contentHtml = `
          <div class="wila-table-container" style="background:transparent; border:none; box-shadow:none; padding:0;">
            <div style="padding:16px 20px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; margin-bottom:24px;">
              <h3 style="margin:0 0 6px 0; color:#991b1b; font-size:1rem;">فرصت‌های طلایی لینک‌‌سازی داخلی</h3>
              <p style="margin:0; color:#b91c1c; font-size:0.85rem;">صفحات زیر هیچ لینک ورودی ندارند. برای بالاترین تاثیر سئو، لینک‌سازی را از باکس <strong>اولویت ۱</strong> آغاز کنید.</p>
            </div>
            ${orphansHtml}
          </div>`;
      } else if (activeTab === 'settings') {
        const s = aiSettings;
        contentHtml = `
          <div class="wila-card" style="max-width:700px;">
            <div class="wila-card-header"><h3 style="font-weight:600;">تنظیمات سامانه</h3></div>
            
            <div style="margin-bottom:20px; background:#fef3c7; padding:16px; border-radius:8px; border:1px solid #fcd34d;">
              <h4 style="margin-top:0; font-size:0.95rem; color:#92400e;">نوع محتوای محصول</h4>
              <p style="margin:0 0 10px 0; font-size:0.8rem; color:#78350f;">اگر سایت شما از نوع محتوای سفارشی برای محصولات استفاده می‌کند (مثلاً نه ووکامرس)، آن را در زیر انتخاب کنید.</p>
              <label style="display:block; font-size:0.825rem; font-weight:500; margin-bottom:4px;">نوع محتوا (Post Type):</label>
              <select id="wila-product-post-type-select" class="wila-select" style="width:100%;margin-bottom:10px;">
                <option value="">انتخاب کنید...</option>
                ${this.state.postTypes.map(pt => `<option value="${pt.value}" ${s.productPostType === pt.value ? 'selected' : ''}>${pt.label}</option>`).join('')}
              </select>
              <label style="display:block; font-size:0.825rem; font-weight:500; margin-bottom:4px;">دسته‌بندی (Taxonomy):</label>
              <select id="wila-product-category-taxonomy-select" class="wila-select" style="width:100%;">
                <option value="">انتخاب کنید...</option>
                ${this.state.taxonomies.map(t => `<option value="${t.value}" ${s.productCategoryTaxonomy === t.value ? 'selected' : ''}>${t.label}</option>`).join('')}
              </select>
            </div>
            
            <div style="background:#f0fdf4; padding:16px; border-radius:8px; border:1px solid #bbf7d0; margin-bottom:20px;">
              <h4 style="margin-top:0; font-size:0.95rem; color:#166534;">🌟 کلمات کلیدی اصلی صفحه اول (Homepage SEO)</h4>
              <p style="margin:0 0 10px 0; font-size:0.8rem; color:#15803d;">کلمات کلیدی اصلی برند یا صفحه اول سایت را با کاما جدا کنید تا هوش مصنوعی در زمان پیشنهاد لینک‌سازی، از این عبارات برای لینک دادن به صفحه اول استفاده کند.</p>
              <textarea id="wila-homepage-keywords-input" class="wila-input" style="width:100%; height:70px; resize:vertical;" placeholder="مثلاً: چرم تیماج، خرید محصولات چرمی، کیف چرم طبیعی">${s.homepageKeywords || ''}</textarea>
            </div>

            <div style="margin-bottom:20px; background:#f8fafc; padding:16px; border-radius:8px; border:1px solid #e2e8f0;">
              <h4 style="margin-top:0; font-size:0.95rem; color:#0f172a;">تنظیمات AvalAI</h4>
              <label style="display:block; font-size:0.825rem; font-weight:500; margin-bottom:4px;">کلید API سرویس AvalAI: ${s.hasAvalAIKey ? `<span class="wila-badge wila-badge-success">فعال</span>` : ''}</label>
              <input type="text" id="wila-avalai-key-input" class="wila-input" style="width:100%; text-align:left; direction:ltr;" value="${s.hasAvalAIKey ? s.avalAIMaskedKey : ''}" placeholder="کلید API AvalAI" />
              <p style="font-size:0.75rem; color:#64748b; margin-bottom:0;">مدل ثابت: <code>deepseek-chat</code></p>
            </div>


            <div style="display:flex; gap:10px;">
              <button class="wila-btn wila-btn-primary" id="wila-save-settings-btn">ذخیره تنظیمات</button>
              <button class="wila-btn wila-btn-outline" id="wila-test-api-btn">تست اتصال</button>
            </div>
            <div id="wila-test-result" style="margin-top:16px; display:none;"></div>
          `;
      }

      this.root.innerHTML = `
        <div class="wila-header"><div class="wila-header-title"><h1>لینکر | تحلیل پیوند یکتا و لینک‌سازی</h1></div></div>
        <div class="wila-tabs">
          <button class="wila-tab-btn ${activeTab === 'overview' ? 'active' : ''}" data-tab="overview">پیشخوان آماری</button>
          <button class="wila-tab-btn ${activeTab === 'planner' ? 'active' : ''}" data-tab="planner">🎯 برنامه‌‌ریز لینک</button>
          <button class="wila-tab-btn ${activeTab === 'generator' ? 'active' : ''}" data-tab="generator">تولیدکننده محتوا</button>
          <button class="wila-tab-btn ${activeTab === 'bulk' ? 'active' : ''}" data-tab="bulk">تولید انبوه (CSV)</button>
          <button class="wila-tab-btn ${activeTab === 'items' ? 'active' : ''}" data-tab="items">کاوشگر پیوندها</button>
          <button class="wila-tab-btn ${activeTab === 'orphans' ? 'active' : ''}" data-tab="orphans">صفحات یتیم</button>
          <button class="wila-tab-btn ${activeTab === 'settings' ? 'active' : ''}" data-tab="settings">تنظیمات</button>
        </div>
        <div class="wila-content">${contentHtml}</div>
        ${modal.isOpen ? `
          <div class="wila-modal-backdrop" id="wila-modal-backdrop">
            <div class="wila-modal">
              <div class="wila-modal-header"><h3>${modal.title}</h3><button class="wila-btn wila-btn-outline wila-btn-sm" id="wila-close-modal-btn">✕</button></div>
              <div class="wila-modal-body">${modal.contentHtml}</div>
            </div>
          </div>
        ` : ''}
      `;
      this.bindEvents();
    },

    bindEvents: function () {
      this.root.querySelectorAll('.wila-tab-btn').forEach(btn => btn.addEventListener('click', e => {
        this.state.activeTab = e.currentTarget.getAttribute('data-tab');
        if (!this.state.activeTab) return;
        if (this.state.activeTab === 'items') this.fetchItems();
        if (this.state.activeTab === 'orphans') this.fetchOrphans();
        this.render();
      }));

      const scanBtn = this.root.querySelector('#wila-start-scan-btn');
      if (scanBtn) scanBtn.addEventListener('click', () => this.startBatchScan());
      
      const plannerBtn = this.root.querySelector('#wila-run-planner-btn');
      if (plannerBtn) plannerBtn.addEventListener('click', () => this.runLinkPlanner());

      this.root.querySelectorAll('[data-generator-field]').forEach(el => el.addEventListener('input', e => { this.state.generatorForm[e.target.dataset.generatorField] = e.target.value; }));
      const generatedSlug = this.root.querySelector('#wila-generated-slug');
      if (generatedSlug) generatedSlug.addEventListener('input', e => { this.state.generatedSlug = e.target.value; });
      this.root.querySelectorAll('select[data-generator-field]').forEach(el => el.addEventListener('change', e => { this.state.generatorForm[e.target.dataset.generatorField] = e.target.value; }));
      const useOrphanLinks = this.root.querySelector('#wila-use-orphan-links');
      if (useOrphanLinks) useOrphanLinks.addEventListener('change', e => { this.state.generatorForm.useOrphanLinks = e.target.checked; });
      this.root.querySelectorAll('.wila-kind-btn').forEach(btn => btn.addEventListener('click', e => this.setContentKind(e.currentTarget.getAttribute('data-kind'))));
      const targetSelect = this.root.querySelector('#wila-target-select');
      if (targetSelect) {
        this.updateTargetOptions();
        targetSelect.addEventListener('change', e => this.selectTarget(e.target.value));
      }
      const targetSearch = this.root.querySelector('#wila-target-search');
      if (targetSearch) targetSearch.addEventListener('input', e => {
        clearTimeout(this.targetSearchTimer);
        const q = e.target.value.trim();
        this.targetSearchTimer = setTimeout(() => this.fetchTargets(q), 350);
      });
      const applyBtn = this.root.querySelector('#wila-apply-target');
      if (applyBtn) applyBtn.addEventListener('click', () => this.applyToTarget());
      const seoTitleInput = this.root.querySelector('#wila-seo-title');
      if (seoTitleInput) seoTitleInput.addEventListener('input', () => {
        const len = [...seoTitleInput.value].length, badge = this.root.querySelector('#wila-seo-title-len');
        if (badge) { badge.textContent = `${toPersianDigits(len)} کاراکتر (ایده‌آل ۵۰ تا ۶۰)`; badge.style.color = len >= 50 && len <= 60 ? '#16a34a' : '#d97706'; }
      });
      const bulkFile = this.root.querySelector('#wila-bulk-file');
      if (bulkFile) bulkFile.addEventListener('change', e => this.loadBulkFile(e.target.files[0]));
      const bulk = this.state.bulk;
      const bulkMode = this.root.querySelector('#wila-bulk-mode'); if (bulkMode) bulkMode.addEventListener('change', e => { bulk.mode = e.target.value; });
      const bulkFilter = this.root.querySelector('#wila-bulk-filter'); if (bulkFilter) bulkFilter.addEventListener('change', e => { bulk.filter = e.target.value; this.render(); });
      const bulkSeo = this.root.querySelector('#wila-bulk-seo'); if (bulkSeo) bulkSeo.addEventListener('change', e => { bulk.injectSeo = e.target.checked; });
      const bulkLinks = this.root.querySelector('#wila-bulk-links'); if (bulkLinks) bulkLinks.addEventListener('change', e => { bulk.autoLinks = e.target.checked; });
      this.root.querySelectorAll('.wila-bulk-row').forEach(box => box.addEventListener('change', e => { bulk.selected[e.target.dataset.index] = e.target.checked; this.render(); }));
      const bulkAll = this.root.querySelector('#wila-bulk-select-all');
      if (bulkAll) bulkAll.addEventListener('change', e => { this.bulkVisibleIndexes().forEach(i => { if (bulk.rows[i].match && bulk.rows[i].match.matched) bulk.selected[i] = e.target.checked; }); this.render(); });
      const bulkStart = this.root.querySelector('#wila-bulk-start'); if (bulkStart) bulkStart.addEventListener('click', () => this.startBulk());
      const bulkPause = this.root.querySelector('#wila-bulk-pause'); if (bulkPause) bulkPause.addEventListener('click', () => { bulk.paused = !bulk.paused; this.bulkLog('info', bulk.paused ? 'مکث پس از محصول جاری.' : 'ادامه پردازش.'); this.render(); });
      const bulkStop = this.root.querySelector('#wila-bulk-stop'); if (bulkStop) bulkStop.addEventListener('click', () => { bulk.stopRequested = true; this.bulkLog('info', 'درخواست توقف ثبت شد؛ پس از محصول جاری متوقف می‌شود.'); this.bulkRefreshUi(); });
      const outlineBtn = this.root.querySelector('#wila-generate-outline');
      if (outlineBtn) outlineBtn.addEventListener('click', () => this.generateOutline());
      const fullBtn = this.root.querySelector('#wila-generate-full');
      if (fullBtn) fullBtn.addEventListener('click', () => this.generateFullContent());
      const generatedFields = { 'wila-generated-content': 'generatedContent', 'wila-generated-slug': 'generatedSlug', 'wila-seo-title': 'generatedSeoTitle', 'wila-meta-description': 'generatedMetaDescription', 'wila-keyword-synonyms': 'generatedKeywordSynonyms' };
      Object.entries(generatedFields).forEach(([id, key]) => {
        const input = this.root.querySelector('#' + id);
        if (input) input.addEventListener('input', () => {
          this.state[key] = input.value;
          const report = this.root.querySelector('#wila-quality-report');
          if (report) report.innerHTML = this.contentQualityReport(this.state.generatedContent, this.state.generatorForm.focusKeyword);
        });
      });
      const copyBtn = this.root.querySelector('#wila-copy-content');
      if (copyBtn) copyBtn.addEventListener('click', () => this.copyGeneratedContent());
      const draftBtn = this.root.querySelector('#wila-create-draft');
      if (draftBtn) draftBtn.addEventListener('click', () => this.createDraft());
      this.root.querySelectorAll('.wila-history-view').forEach(btn => btn.addEventListener('click', e => this.showContentHistoryItem(e.currentTarget.getAttribute('data-id'))));
      this.root.querySelectorAll('.wila-history-delete').forEach(btn => btn.addEventListener('click', e => this.deleteHistoryItem(e.currentTarget.getAttribute('data-id'))));
      this.root.querySelectorAll('.wila-copy-btn').forEach(btn => btn.addEventListener('click', e => {
        const field = this.root.querySelector('#' + e.currentTarget.getAttribute('data-copy'));
        if (field) this.copyText(field.value);
      }));

      // اتصال رویدادها برای حفظ دائمی مقادیر فرم برنامه‌ریز در state
      const planTitle = document.getElementById('wila-plan-title');
      if (planTitle) planTitle.addEventListener('input', e => this.updatePlannerField('h1Title', e.target.value));

      const planKeyword = document.getElementById('wila-plan-keyword');
      if (planKeyword) planKeyword.addEventListener('input', e => this.updatePlannerField('focusKeyword', e.target.value));

      const planLongTail = document.getElementById('wila-plan-longtail');
      if (planLongTail) planLongTail.addEventListener('input', e => this.updatePlannerField('longTail', e.target.value));

      const planWords = document.getElementById('wila-plan-words');
      if (planWords) planWords.addEventListener('input', e => this.updatePlannerField('wordCount', e.target.value));

      const planType = document.getElementById('wila-plan-type');
      if (planType) planType.addEventListener('change', e => this.updatePlannerField('contentType', e.target.value));

      const planFunnel = document.getElementById('wila-plan-funnel');
      if (planFunnel) planFunnel.addEventListener('change', e => this.updatePlannerField('funnelStage', e.target.value));

      const filterSelect = this.root.querySelector('#wila-filter-type');
      if (filterSelect) filterSelect.addEventListener('change', e => { this.state.filterType = e.target.value; this.fetchItems(); });

      this.root.querySelectorAll('.wila-inspect-btn').forEach(btn => btn.addEventListener('click', e => this.showItemDetails(e.currentTarget.getAttribute('data-id'), e.currentTarget.getAttribute('data-type'), e.currentTarget.getAttribute('data-title'))));
      this.root.querySelectorAll('.wila-ai-btn').forEach(btn => btn.addEventListener('click', e => this.requestAiSuggestions(e.currentTarget.getAttribute('data-id'), e.currentTarget.getAttribute('data-type'), e.currentTarget.getAttribute('data-title'))));

      const saveBtn = this.root.querySelector('#wila-save-settings-btn');
      if (saveBtn) saveBtn.addEventListener('click', () => this.saveSettings());

      const testBtn = this.root.querySelector('#wila-test-api-btn');
      if (testBtn) testBtn.addEventListener('click', () => this.testConnection());

      const productTypeSelect = this.root.querySelector('#wila-product-post-type-select');
      if (productTypeSelect) productTypeSelect.addEventListener('change', e => this.fetchTaxonomies(e.target.value));


      const closeBtn = this.root.querySelector('#wila-close-modal-btn');
      if (closeBtn) closeBtn.addEventListener('click', () => this.closeModal());
    },
  };

  document.addEventListener('DOMContentLoaded', () => app.init());
})();