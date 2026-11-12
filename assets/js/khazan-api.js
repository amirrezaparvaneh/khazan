/**
 * Khazan - REST API layer: apiFetch plus every method that talks to wila/v1 endpoints
 * (fetchers, generators, SEO fix, settings, scan). Mixed into the dashboard app by
 * wila-admin.js (methods run with `this` === app).
 * Author: امیــــــــررضا پـروانه
 */
(function () {
  'use strict';

  const { toPersianDigits, formatJalali, shortJalali, escapeHtml } = window.KhazanUtils;

  const methods = {
    apiFetch: async function (endpoint, options = {}) {
      const url = `${wilaData.restUrl}wila/v1${endpoint}`;
      const defaultHeaders = { 'X-WP-Nonce': wilaData.nonce, 'Content-Type': 'application/json' };
      let response;
      try {
        response = await fetch(url, { ...options, headers: { credentials: 'include', ...defaultHeaders, ...options.headers } });
      } catch (e) {
        throw new TypeError('ارتباط با سرور سایت برقرار نشد. اینترنت خود را بررسی کنید و دوباره تلاش کنید.');
      }
      try {
        return await response.json();
      } catch (e) {
        const st = response.status;
        const why = st === 403 ? 'دسترسی شما مجاز نیست یا نشست ورود تمام شده؛ صفحه را تازه کنید و دوباره وارد شوید.'
          : st === 404 ? 'نشانی درخواستی روی سرور پیدا نشد؛ افزونه را به‌روزرسانی یا پیوندهای یکتا را یک بار ذخیره کنید.'
          : (st === 502 || st === 503 || st === 504 || st === 524) ? 'سرور سایت دیر پاسخ داد یا شلوغ است (احتمالاً کمبود زمان اجرا)؛ کمی بعد دوباره تلاش کنید.'
          : st >= 500 ? 'سرور سایت با خطا روبه‌رو شد؛ گزارش خطای PHP هاست را بررسی کنید.'
          : 'پاسخ سرور قابل‌خواندن نبود.';
        throw new SyntaxError(why);
      }
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
          aiReasoning: data.ai_reasoning || 'low',
          aiBaseUrl: data.ai_base_url || '',
          aiDefaultBaseUrl: data.ai_default_base_url || '',
          homepageKeywords: data.homepage_keywords || '',
          linkPlan: data.link_plan || null,
          contentWrapperClass: data.content_wrapper_class || '',
          autoImageEnabled: !!data.auto_image_enabled,
          productPostType: data.product_post_type || 'product',
          productCategoryTaxonomy: data.product_category_taxonomy || 'product_cat',
        };
        this.state.aiPricing = data.ai_pricing || null;
        try { const u = await this.apiFetch('/settings/ai-usage'); this.state.aiUsage = (u && u.items) || []; } catch (e) { this.state.aiUsage = []; }
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

    showContentHistoryItem: async function (id) {
      const app = this;
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
        };
        this.render();
      } catch (e) { app.toast('خطا: ' + e.message); }
    },

    deleteHistoryItem: async function (id) {
      const app = this;
      if (!confirm('این محتوا برای همیشه از آرشیو حذف شود؟ (صفحه منتشرشده تغییری نمی‌کند)')) return;
      try {
        const r = await this.apiFetch(`/content/history/${id}`, { method: 'DELETE' });
        if (!r.success) throw new Error(r.message || 'حذف انجام نشد.');
        this.state.contentHistory = this.state.contentHistory.filter(item => String(item.id) !== String(id));
        this.render();
        app.toast('از آرشیو حذف شد.');
      } catch (e) { app.toast('خطا: ' + e.message); }
    },

    // اعتبار زنده حساب API (کش ۶۰ ثانیه‌ای سمت سرور؛ force=true کش را دور می‌زند). خطا هرگز صفحه را نمی‌شکند
    getAiBalance: async function (force) {
      const st = this.state;
      st.aiBalanceLoading = true;
      if (force) this.render();
      try {
        const r = await this.apiFetch('/settings/ai-balance' + (force ? '?refresh=1' : ''));
        if (!r || !r.success || !r.balance) { const err = new Error((r && r.message) || 'استعلام اعتبار ناموفق بود.'); err.code = (r && r.code) || 'invalid_response'; throw err; }
        st.aiBalance = r; st.aiBalanceError = ''; st.aiBalanceErrorCode = '';
        if (r.estimate) st.aiPricing = r.estimate;
      } catch (e) {
        st.aiBalance = null;
        const network = e && e.name === 'TypeError', badJson = e && e.name === 'SyntaxError';
        st.aiBalanceErrorCode = (e && e.code) || (network ? 'connection_failed' : 'request_failed');
        st.aiBalanceError = network ? ((e && e.message) || 'ارتباط با سرور برقرار نشد.') : (badJson ? ((e && e.message) || 'پاسخ نامعتبر از سرور.') : ((e && e.message) || 'استعلام اعتبار ناموفق بود.'));
      }
      st.aiBalanceLoading = false;
      this.render();
    },

    // آمار تحلیلی داشبورد (کش ۵ دقیقه‌ای سمت سرور؛ force=true کش را دور می‌زند)
    fetchSiteStats: async function (force) {
      const st = this.state;
      st.siteStatsLoading = true; st.siteStatsError = '';
      if (force) this.render();
      try {
        const r = await this.apiFetch('/report/site-stats' + (force ? '?refresh=1' : ''));
        if (!r.success) throw new Error(r.message || 'آمار سایت دریافت نشد.');
        st.siteStats = r;
      } catch (e) { st.siteStatsError = e.message || 'آمار سایت دریافت نشد.'; }
      st.siteStatsLoading = false;
      this.render();
    },

    // فهرست محصولات سایت برای صف تولید انبوه (بدون CSV)
    loadSiteProducts: async function () {
      const b = this.state.bulk, site = b.site;
      site.loading = true; site.error = '';
      this.render();
      try {
        const q = `filter=${encodeURIComponent(site.filter)}&category=${encodeURIComponent(site.category || 0)}&search=${encodeURIComponent(site.search || '')}`;
        const r = await this.apiFetch(`/bulk/site-products?${q}`);
        if (!r.success) throw new Error(r.message || 'فهرست محصولات دریافت نشد.');
        site.items = r.items || []; site.categories = r.categories || []; site.hasMore = !!r.has_more; site.loaded = true;
        site.counts = { total: Number(r.total_count || 0), empty: Number(r.empty_count || 0), hasContent: Number(r.has_content_count || 0) };
        site.selected = {}; site.items.forEach(p => { site.selected[p.id] = true; });
      } catch (e) { site.items = []; site.selected = {}; site.error = e.message; site.loaded = true; }
      site.loading = false;
      this.render();
    },

    // محتوای آرشیوشده را برای ویرایش/انتشار به بخش «تولید محتوای تکی» برمی‌گرداند
    restoreHistoryItem: async function (id) {
      const app = this;
      try {
        const response = await this.apiFetch(`/content/history/${id}`);
        if (!response.success || !response.item) throw new Error(response.message || 'محتوا یافت نشد.');
        const item = response.item, st = this.state;
        let links = [];
        try { links = JSON.parse(item.external_links || '[]') || []; } catch (e) {}
        st.generatorForm.contentKind = item.content_kind || 'article';
        st.generatorForm.targetId = Number(item.target_id) || 0;
        st.generatorForm.h1Title = item.title || '';
        st.generatorForm.focusKeyword = item.focus_keyword || '';
        st.contentOutline = null;
        st.generatedContent = item.content || ''; st.featuredImageId = 0; st.featuredImageUrl = ''; st.generatedSlug = item.slug || '';
        st.generatedSeoTitle = item.seo_title || ''; st.generatedMetaDescription = item.meta_description || ''; st.generatedKeywordSynonyms = item.keyword_synonyms || '';
        st.generatedInternalLinkCount = Number(item.internal_link_count || 0); st.generatedExternalLinkCount = Number(item.external_link_count || 0); st.generatedExternalLinks = Array.isArray(links) ? links : [];
        st.activeTab = 'generator'; st.activeNav = 'generator';
        app.toast('محتوا برای ویرایش و انتشار در «تولید محتوای تکی» بازیابی شد.');
        this.render();
      } catch (e) { app.toast('خطا: ' + e.message); }
    },

    startBatchScan: async function () {
      const app = this;
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

    generateOutline: async function () {
      const app = this;
      const p = this.getGeneratorPayload();
      if (p.content_kind !== 'article' && !p.target_id) return app.toast(`ابتدا ${this.kindLabel(p.content_kind)} مورد نظر را از فهرست انتخاب کنید.`);
      if (!p.h1_title.trim() || !p.focus_keyword.trim()) return app.toast('عنوان و کلیدواژه کانونی الزامی هستند.');
      this.state.isGeneratingContent = true; this.resetGenerated(); this.render();
      try { const r = await this.apiFetch('/content/outline', { method: 'POST', body: JSON.stringify(p) }); if (!r.success) throw new Error(r.message || 'طرح تولید نشد.'); this.state.contentOutline = r.outline; this.state.generatedSlug = r.outline.slug || ''; this.state.generatedSeoTitle = r.outline.seo_title || ''; this.state.generatedMetaDescription = r.outline.meta_description || ''; this.state.generatedKeywordSynonyms = r.outline.keyword_synonyms || ''; }
      catch (e) { app.toast('خطا: ' + e.message); } finally { this.state.isGeneratingContent = false; this.render(); }
    },

    generateFullContent: async function () {
      const app = this;
      this.state.isGeneratingContent = true; this.render();
      try { const r = await this.apiFetch('/content/generate', { method: 'POST', body: JSON.stringify({ ...this.getGeneratorPayload(), outline: this.state.contentOutline }) }); if (!r.success) throw new Error(r.message || 'محتوا تولید نشد.'); this.state.generatedContent = r.content; this.state.featuredImageId = Number(r.featured_image_id || 0); this.state.featuredImageUrl = r.featured_image_url || ''; this.state.generatedSlug = r.slug || ''; this.state.generatedSeoTitle = r.seo_title || ''; this.state.generatedMetaDescription = r.meta_description || ''; this.state.generatedKeywordSynonyms = r.keyword_synonyms || ''; this.state.generatedInternalLinkCount = Number(r.internal_link_count || 0); this.state.generatedExternalLinkCount = Number(r.external_link_count || 0); this.state.generatedExternalLinks = Array.isArray(r.external_links) ? r.external_links : []; }
      catch (e) { app.toast('خطا: ' + e.message); } finally { this.state.isGeneratingContent = false; this.render(); }
    },

    createDraft: async function () {
      const app = this;
      const contentField = this.root.querySelector('#wila-generated-content');
      const content = contentField ? contentField.value : this.state.generatedContent;
      try { const r = await this.apiFetch('/content/draft', { method: 'POST', body: JSON.stringify({ title: this.state.generatorForm.h1Title, content, featured_image_id: this.state.featuredImageId || 0, slug: (this.root.querySelector('#wila-generated-slug') || {}).value || this.state.generatedSlug, focus_keyword: this.state.generatorForm.focusKeyword, keyword_synonyms: (this.root.querySelector('#wila-keyword-synonyms') || {}).value || this.state.generatedKeywordSynonyms, seo_title: (this.root.querySelector('#wila-seo-title') || {}).value || this.state.generatedSeoTitle, meta_description: (this.root.querySelector('#wila-meta-description') || {}).value || this.state.generatedMetaDescription }) }); if (!r.success) throw new Error(r.message || 'پیش‌نویس وبلاگ ایجاد نشد.'); window.open(r.edit_url, '_blank'); }
      catch (e) { app.toast('خطا: ' + e.message); }
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

    loadSeoFixCurrent: async function () {
      const app = this;
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
      const app = this;
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
      const app = this;
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
      const app = this;
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
      const app = this;
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
      const app = this;
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
      const app = this;
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

    fetchAiModels: async function (refresh) {
      const st = this.state;
      // مقدارهای تایپ‌شده را پیش از رندر مجدد نگه می‌داریم
      const typedModel = document.getElementById('wila-ai-model-input');
      const typedBase = document.getElementById('wila-ai-base-url-input');
      if (typedModel) st.aiSettings.avalAIModel = typedModel.value.trim();
      if (typedBase) st.aiSettings.aiBaseUrl = typedBase.value.trim();
      const typedReasoning = document.getElementById('wila-ai-reasoning-input');
      if (typedReasoning) st.aiSettings.aiReasoning = typedReasoning.value;
      st.aiModels = { loading: true, list: (st.aiModels && st.aiModels.list) || [], error: '' };
      this.render();
      try {
        const res = await this.apiFetch('/settings/ai-models' + (refresh ? '?refresh=1' : ''));
        if (!res.success) throw new Error(res.message || 'دریافت فهرست مدل‌ها ناموفق بود.');
        st.aiModels = { loading: false, list: res.models || [], error: '' };
      } catch (e) {
        st.aiModels = { loading: false, list: [], error: e.message };
      }
      this.render();
    },

    saveSettings: async function () {
      const app = this;
      const avalaiKey = document.getElementById('wila-avalai-key-input')?.value.trim() || '';
      const homepageKeywords = document.getElementById('wila-homepage-keywords-input')?.value.trim() || '';
      const wrapperInput = document.getElementById('wila-wrapper-class-input');
      const autoImageInput = document.getElementById('wila-auto-image-input');
      const modelInput = document.getElementById('wila-ai-model-input');
      const baseUrlInput = document.getElementById('wila-ai-base-url-input');
      const reasoningInput = document.getElementById('wila-ai-reasoning-input');

      try {
        await this.apiFetch('/settings', {
          method: 'POST',
          body: JSON.stringify({
            provider: 'avalai',
            avalai_api_key: avalaiKey,
            homepage_keywords: homepageKeywords,
            ...(wrapperInput ? { content_wrapper_class: wrapperInput.value.trim() } : {}),
            ...(modelInput ? { ai_model: modelInput.value.trim() } : {}),
            ...(reasoningInput ? { ai_reasoning: reasoningInput.value } : {}),
            ...(baseUrlInput ? { ai_base_url: baseUrlInput.value.trim() } : {}),
            ...(autoImageInput ? { auto_image_enabled: autoImageInput.checked } : {})
          })
        });
        app.toast('تنظیمات با موفقیت ذخیره شد.');
        await this.fetchSettings();
      } catch (e) {
        app.toast('خطا در ذخیره تنظیمات.');
      }
    },
  };

  window.KhazanApi = { methods: methods };
})();
