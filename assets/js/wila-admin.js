/**
 * هسته کلاینت داشبورد تحلیل لینک‌های داخلی (با قابلیت حفظ پایداری فرم برنامه‌ریز هنگام بروز خطا)
 */
(function () {
  'use strict';

  const { toPersianDigits, formatJalali, shortJalali, escapeHtml } = window.KhazanUtils;

  const app = {
    state: {
      activeTab: 'home',
      activeNav: 'home',
      pendingScroll: '',
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
        avalAIModel: 'gpt-5.4-mini',
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
      seoFix: { kind: 'product', search: '', targetId: 0, targets: [], targetsLoading: false, targetsError: '', current: null, currentLoading: false, errors: '', result: null, running: false, applying: false },
      bulk: window.KhazanQueue.initialState(),
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
          avalAIModel: data.avalai_model || 'gpt-5.4-mini',
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

    // ---- رفع خطای هدفمند سئو: فقط ایرادهای گزارش‌شده اصلاح می‌شوند ----
    setSeoFixKind: function (kind) {
      const sf = this.state.seoFix;
      if (sf.kind === kind) return;
      Object.assign(sf, { kind, targetId: 0, current: null, result: null, targets: [], targetsError: '', search: '' });
      this.render();
      this.fetchSeoFixTargets('');
    },

    fetchSeoFixTargets: async function (search) {
      const sf = this.state.seoFix, kind = sf.kind;
      sf.targetsLoading = true; sf.targetsError = ''; this.updateSeoFixOptions();
      try {
        const r = await this.apiFetch(`/content/targets?kind=${encodeURIComponent(kind)}&search=${encodeURIComponent(search || '')}`);
        if (kind !== sf.kind) return;
        if (!r.success) throw new Error(r.message || 'فهرست دریافت نشد.');
        sf.targets = r.items || [];
      } catch (e) { sf.targets = []; sf.targetsError = e.message; }
      sf.targetsLoading = false;
      this.updateSeoFixOptions();
    },

    updateSeoFixOptions: function () {
      const list = this.root && this.root.querySelector('#wila-seofix-select');
      if (!list) return;
      const { targets, targetsLoading, targetsError, targetId } = this.state.seoFix;
      if (targetsLoading) list.innerHTML = '<option disabled>در حال بارگذاری...</option>';
      else if (targetsError) list.innerHTML = `<option disabled>${escapeHtml(targetsError)}</option>`;
      else if (!targets.length) list.innerHTML = '<option disabled>موردی یافت نشد.</option>';
      else list.innerHTML = targets.map(t => `<option value="${t.id}" ${Number(targetId) === Number(t.id) ? 'selected' : ''}>${escapeHtml(t.title)}</option>`).join('');
    },

    selectSeoFixTarget: function (id) {
      const sf = this.state.seoFix;
      sf.targetId = Number(id); sf.result = null; sf.current = null;
      this.loadSeoFixCurrent();
    },

    loadSeoFixCurrent: async function () {
      const sf = this.state.seoFix;
      sf.currentLoading = true; this.render();
      try {
        const r = await this.apiFetch(`/content/current?content_kind=${encodeURIComponent(sf.kind)}&target_id=${encodeURIComponent(sf.targetId)}`);
        if (!r.success) throw new Error(r.message || 'محتوا دریافت نشد.');
        sf.current = r;
      } catch (e) { sf.current = null; app.toast('خطا: ' + e.message); }
      sf.currentLoading = false;
      this.render();
    },

    runSeoFix: async function () {
      const sf = this.state.seoFix;
      if (!sf.targetId || !sf.errors.trim() || sf.running) return;
      sf.running = true; sf.result = null; this.render();
      try {
        const r = await this.apiFetch('/content/seo-fix', { method: 'POST', body: JSON.stringify({ content_kind: sf.kind, target_id: sf.targetId, errors: sf.errors }) });
        if (!r.success) throw new Error(r.message || 'رفع خطا انجام نشد.');
        sf.result = r;
        app.toast(r.changed ? 'اصلاح‌ها آماده بررسی است.' : 'هوش مصنوعی تغییری پیشنهاد نکرد.');
      } catch (e) { app.toast('خطا: ' + e.message); }
      sf.running = false;
      this.render();
    },

    applySeoFix: async function () {
      const sf = this.state.seoFix;
      const val = id => (this.root.querySelector('#' + id) || {}).value || '';
      const content = val('wila-seofix-result');
      if (!content.trim() || !sf.targetId || sf.applying) return;
      if (!confirm('متن فعلی این صفحه با نسخه اصلاح‌شده جایگزین می‌شود (نسخه قبلی نگه داشته می‌شود). ادامه می‌دهید؟')) return;
      sf.applying = true; this.render();
      try {
        const r = await this.apiFetch('/content/apply', { method: 'POST', body: JSON.stringify({ content_kind: sf.kind, target_id: sf.targetId, content, mode: 'replace', seo_title: val('wila-seofix-title'), meta_description: val('wila-seofix-meta') }) });
        if (!r.success) throw new Error(r.message || 'جایگزینی انجام نشد.');
        app.toast('اصلاح‌ها در صفحه ذخیره شد.');
        sf.result = null;
        sf.applying = false;
        await this.loadSeoFixCurrent();
        return;
      } catch (e) { app.toast('خطا: ' + e.message); }
      sf.applying = false;
      this.render();
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
      this.root.innerHTML = this.shellHtml(this.renderView()) + this.modalHtml();
      this.bindEvents();
    },

    modalHtml: function () {
      const { modal } = this.state;
      return `${modal.isOpen ? `
          <div class="wila-modal-backdrop" id="wila-modal-backdrop">
            <div class="wila-modal">
              <div class="wila-modal-header"><h3>${modal.title}</h3><button class="wila-btn wila-btn-outline wila-btn-sm" id="wila-close-modal-btn">✕</button></div>
              <div class="wila-modal-body">${modal.contentHtml}</div>
            </div>
          </div>
        ` : ''}
      `;
    },

    bindEvents: function () {
      this.bindTabEvents();
      this.bindQueueEvents();
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
      const sf = this.state.seoFix;
      this.root.querySelectorAll('.wila-seofix-kind').forEach(btn => btn.addEventListener('click', e => this.setSeoFixKind(e.currentTarget.getAttribute('data-kind'))));
      const sfSelect = this.root.querySelector('#wila-seofix-select');
      if (sfSelect) {
        this.updateSeoFixOptions();
        sfSelect.addEventListener('change', e => this.selectSeoFixTarget(e.target.value));
      }
      const sfSearch = this.root.querySelector('#wila-seofix-search');
      if (sfSearch) sfSearch.addEventListener('input', e => {
        clearTimeout(this.seoFixSearchTimer);
        sf.search = e.target.value.trim();
        this.seoFixSearchTimer = setTimeout(() => this.fetchSeoFixTargets(sf.search), 350);
      });
      const sfErrors = this.root.querySelector('#wila-seofix-errors');
      if (sfErrors) sfErrors.addEventListener('input', e => {
        sf.errors = e.target.value;
        const runBtn = this.root.querySelector('#wila-seofix-run');
        if (runBtn) runBtn.disabled = !sf.errors.trim() || sf.running;
      });
      const sfRun = this.root.querySelector('#wila-seofix-run'); if (sfRun) sfRun.addEventListener('click', () => this.runSeoFix());
      const sfApply = this.root.querySelector('#wila-seofix-apply'); if (sfApply) sfApply.addEventListener('click', () => this.applySeoFix());
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

  // ماژول‌های صف و تب‌ها روی app سوار می‌شوند (متدها با this === app اجرا می‌شوند)
  Object.assign(app, window.KhazanQueue.methods, window.KhazanTabs.methods);

  document.addEventListener('DOMContentLoaded', () => app.init());
})();