/**
 * Khazan - form layer: input/change handlers that keep form values in app state, field helpers
 * (generator, planner, SEO fix, copy-to-clipboard) and the DOM event wiring for all form controls.
 * Mixed into the dashboard app by wila-admin.js (methods run with `this` === app).
 * Author: امیــــــــررضا پـروانه
 */
(function () {
  'use strict';

  const { toPersianDigits, formatJalali, shortJalali, escapeHtml } = window.KhazanUtils;

  const methods = {
    copyField: function (id, label, value, opts) {
      const o = opts || {};
      if (value === null || value === undefined || String(value).trim() === '') return '';
      return `<div style="margin:0 0 14px"><div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px"><label style="font-weight:600">${label}</label><button type="button" class="wila-btn wila-btn-outline wila-btn-sm wila-copy-btn" data-copy="${id}">کپی</button></div>${o.rows ? `<textarea id="${id}" class="wila-input" rows="${o.rows}" readonly style="width:100%;${o.ltr ? 'direction:ltr;text-align:left' : 'direction:rtl'}">${escapeHtml(value)}</textarea>` : `<input id="${id}" class="wila-input" readonly style="width:100%;${o.ltr ? 'direction:ltr;text-align:left' : ''}" value="${escapeHtml(value)}">`}</div>`;
    },

    copyText: async function (text) {
      const app = this;
      try { await navigator.clipboard.writeText(text); app.toast('کپی شد.'); }
      catch (e) {
        const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); app.toast('کپی شد.'); } catch (err) { app.toast('کپی ناموفق بود.'); }
        ta.remove();
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
      const app = this;
      const contentField = this.root.querySelector('#wila-generated-content');
      const content = contentField ? contentField.value : this.state.generatedContent;
      try { await navigator.clipboard.writeText(content); app.toast('تمام محتوای HTML کپی شد.'); }
      catch (e) {
        if (contentField) { contentField.select(); document.execCommand('copy'); app.toast('تمام محتوای HTML کپی شد.'); }
        else app.toast('کپی محتوا ناموفق بود.');
      }
    },

    setSeoFixKind: function (kind) {
      const sf = this.state.seoFix;
      if (sf.kind === kind) return;
      Object.assign(sf, { kind, targetId: 0, current: null, result: null, targets: [], targetsError: '', search: '' });
      this.render();
      this.fetchSeoFixTargets('');
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

    bindFormEvents: function () {
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
      this.root.querySelectorAll('.wila-kind-btn').forEach(btn => btn.addEventListener('change', e => this.setContentKind(e.currentTarget.getAttribute('data-kind'))));
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
      this.root.querySelectorAll('.wila-seofix-kind').forEach(btn => btn.addEventListener('change', e => this.setSeoFixKind(e.currentTarget.getAttribute('data-kind'))));
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
      this.root.querySelectorAll('.wila-history-restore').forEach(btn => btn.addEventListener('click', e => this.restoreHistoryItem(e.currentTarget.getAttribute('data-id'))));
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
    },
  };

  window.KhazanForms = { methods: methods };
})();
