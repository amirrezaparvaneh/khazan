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
      generatorForm: { h1Title: '', focusKeyword: '', longTail: '', wordCount: 1200, contentType: 'پست وبلاگ', funnelStage: 'تشخیص خودکار', internalLinks: '', useOrphanLinks: true },
      contentOutline: null,
      generatedContent: '',
      isGeneratingContent: false,
      modal: { isOpen: false, title: '', contentHtml: '' },
    },

    init: function () {
      this.root = document.getElementById('wila-app-root');
      if (!this.root) return;
      this.fetchSummary();
      this.fetchSettings();
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
          homepageKeywords: data.homepage_keywords || ''
        };
        this.render();
      } catch (e) {}
    },

    fetchItems: async function () {
      try { this.state.items = await this.apiFetch(`/items?type=${this.state.filterType}`); this.render(); } catch (e) {}
    },

    fetchOrphans: async function () {
      try { this.state.orphans = await this.apiFetch('/orphans'); this.render(); } catch (e) {}
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
        alert('اسکن سایت کامل شد!');
      } catch (err) {
        alert('خطا در فرآیند اسکن: ' + err.message);
        this.state.isScanning = false;
        this.render();
      }
    },

    updatePlannerField: function (field, value) {
      this.state.plannerForm[field] = value;
    },

    getGeneratorPayload: function () {
      const f = this.state.generatorForm;
      const internalLinks = f.internalLinks.split(/\n|,/).map(item => item.trim()).filter(Boolean).map(item => {
        const parts = item.split(/\s*:\s*/);
        return { anchor: parts.shift() || '', url: parts.join(':') || '' };
      }).filter(link => link.anchor && /^https?:\/\//i.test(link.url));
      return { h1_title: f.h1Title, focus_keyword: f.focusKeyword, long_tail: f.longTail, word_count: f.wordCount, content_type: f.contentType, funnel_stage: f.funnelStage, internal_links: internalLinks, use_orphan_links: f.useOrphanLinks && !internalLinks.length };
    },

    generateOutline: async function () {
      const p = this.getGeneratorPayload();
      if (!p.h1_title.trim() || !p.focus_keyword.trim()) return alert('عنوان و کلیدواژه کانونی الزامی هستند.');
      this.state.isGeneratingContent = true; this.state.contentOutline = null; this.state.generatedContent = ''; this.render();
      try { const r = await this.apiFetch('/content/outline', { method: 'POST', body: JSON.stringify(p) }); if (!r.success) throw new Error(r.message || 'طرح تولید نشد.'); this.state.contentOutline = r.outline; }
      catch (e) { alert('خطا: ' + e.message); } finally { this.state.isGeneratingContent = false; this.render(); }
    },

    generateFullContent: async function () {
      this.state.isGeneratingContent = true; this.render();
      try { const r = await this.apiFetch('/content/generate', { method: 'POST', body: JSON.stringify({ ...this.getGeneratorPayload(), outline: this.state.contentOutline }) }); if (!r.success) throw new Error(r.message || 'محتوا تولید نشد.'); this.state.generatedContent = r.content; }
      catch (e) { alert('خطا: ' + e.message); } finally { this.state.isGeneratingContent = false; this.render(); }
    },

    copyGeneratedContent: async function () { try { await navigator.clipboard.writeText(this.state.generatedContent); alert('محتوا کپی شد.'); } catch (e) { alert('کپی محتوا ناموفق بود.'); } },

    createDraft: async function () {
      try { const r = await this.apiFetch('/content/draft', { method: 'POST', body: JSON.stringify({ title: this.state.generatorForm.h1Title, content: this.state.generatedContent }) }); if (!r.success) throw new Error(r.message || 'پیش‌نویس ایجاد نشد.'); window.open(r.edit_url, '_blank'); }
      catch (e) { alert('خطا: ' + e.message); }
    },

    runLinkPlanner: async function () {
      const pf = this.state.plannerForm;

      if (!pf.h1Title.trim() || !pf.focusKeyword.trim()) {
        alert('لطفاً عنوان مقاله و کلیدواژه کانونی را وارد کنید.');
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
        alert('خطا: ' + err.message);
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
        alert('لطفاً ابتدا کلید API را در بخش تنظیمات وارد کنید.');
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

    testConnection: async function () {
      const avalaiKey = document.getElementById('wila-avalai-key-input')?.value.trim() || '';
      const resultBox = document.getElementById('wila-test-result');
      const testBtn = document.getElementById('wila-test-api-btn');
      if (!avalaiKey && !this.state.aiSettings.hasAvalAIKey) {
        alert('لطفاً کلید API سرویس AvalAI را وارد کنید.');
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
        alert('تنظیمات با موفقیت ذخیره شد.');
        await this.fetchSettings();
      } catch (e) {
        alert('خطا در ذخیره تنظیمات.');
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
        const field = (key, label, type = 'text') => `<label style="display:block;margin:10px 0 4px;font-weight:600">${label}</label><input data-generator-field="${key}" type="${type}" class="wila-input" style="width:100%" value="${esc(f[key])}">`;
        contentHtml = `<div class="wila-card" style="max-width:900px"><h3>تولیدکننده محتوای هوشمند سئو</h3><p>طرح را تایید کنید، سپس محتوای HTML تولید و به پیش‌نویس وردپرس منتقل کنید.</p>
          ${field('h1Title','عنوان اصلی / H1')}${field('focusKeyword','کلیدواژه کانونی')}${field('longTail','کلیدواژه‌های لانگ‌تیل')}${field('wordCount','تعداد کلمات','number')}
          <label style="display:block;margin:10px 0 4px;font-weight:600">نوع محتوا</label><select data-generator-field="contentType" class="wila-select" style="width:100%">${['پست وبلاگ','صفحه دسته‌بندی','صفحه محصول','صفحه فرود/لندینگ','تشخیص خودکار بر پایه اینتنت'].map(x=>`<option ${f.contentType===x?'selected':''}>${x}</option>`).join('')}</select>
          <label style="display:block;margin:10px 0 4px;font-weight:600">فاز قیف</label><select data-generator-field="funnelStage" class="wila-select" style="width:100%">${['TOFU (آگاهی)','MOFU (بررسی)','BOFU (خرید/تبدیل)','تشخیص خودکار'].map(x=>`<option ${f.funnelStage===x?'selected':''}>${x}</option>`).join('')}</select>
          <label style="display:block;margin:10px 0 4px;font-weight:600">نقشه لینک داخلی</label><textarea data-generator-field="internalLinks" class="wila-input" rows="4" style="width:100%" placeholder="انکرتکست: https://example.com/page\nیا با ویرگول جدا کنید">${esc(f.internalLinks)}</textarea>
          <label style="display:flex;gap:8px;align-items:center;margin-top:10px;cursor:pointer"><input type="checkbox" id="wila-use-orphan-links" ${f.useOrphanLinks ? 'checked' : ''}> اگر نقشه لینک خالی است، صفحات یتیم مرتبط را خودکار و استراتژیک لینک‌سازی کن</label><p style="margin:5px 0;color:#64748b;font-size:.8rem">افزونه فقط صفحه‌های یتیم مرتبط را انتخاب می‌کند و انکرتکست را بر اساس مقصد می‌سازد.</p>
          <button id="wila-generate-outline" class="wila-btn wila-btn-primary" style="margin-top:16px" ${isGeneratingContent?'disabled':''}>${isGeneratingContent?'در حال تولید...':'۱. تولید طرح ساختاری'}</button>
          ${this.state.contentOutline ? `<div style="margin-top:20px"><h4>طرح تاییدشده</h4><p><strong>نیت جستجو:</strong> ${esc(this.state.contentOutline.search_intent)}</p><ul>${(this.state.contentOutline.headings||[]).map(h=>`<li><strong>${esc(h.level)}:</strong> ${esc(h.text)} — ${esc(h.purpose)}</li>`).join('')}</ul><button id="wila-generate-full" class="wila-btn wila-btn-primary" ${isGeneratingContent?'disabled':''}>۲. تایید طرح و نگارش کامل</button></div>`:''}
          ${this.state.generatedContent ? `<div style="margin-top:20px"><textarea id="wila-generated-content" class="wila-input" rows="18" style="width:100%;direction:rtl">${esc(this.state.generatedContent)}</textarea><div style="margin-top:10px"><button id="wila-copy-content" class="wila-btn wila-btn-outline">کپی مستقیم</button> <button id="wila-create-draft" class="wila-btn wila-btn-primary">ایجاد پیش‌نویس پست در وردپرس</button></div></div>`:''}
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
            <div class="wila-card-header"><h3 style="font-weight:600;">تنظیمات هوش مصنوعی و سئو</h3></div>
            
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
      this.root.querySelectorAll('select[data-generator-field]').forEach(el => el.addEventListener('change', e => { this.state.generatorForm[e.target.dataset.generatorField] = e.target.value; }));
      const useOrphanLinks = this.root.querySelector('#wila-use-orphan-links');
      if (useOrphanLinks) useOrphanLinks.addEventListener('change', e => { this.state.generatorForm.useOrphanLinks = e.target.checked; });
      const outlineBtn = this.root.querySelector('#wila-generate-outline');
      if (outlineBtn) outlineBtn.addEventListener('click', () => this.generateOutline());
      const fullBtn = this.root.querySelector('#wila-generate-full');
      if (fullBtn) fullBtn.addEventListener('click', () => this.generateFullContent());
      const copyBtn = this.root.querySelector('#wila-copy-content');
      if (copyBtn) copyBtn.addEventListener('click', () => this.copyGeneratedContent());
      const draftBtn = this.root.querySelector('#wila-create-draft');
      if (draftBtn) draftBtn.addEventListener('click', () => this.createDraft());

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


      const closeBtn = this.root.querySelector('#wila-close-modal-btn');
      if (closeBtn) closeBtn.addEventListener('click', () => this.closeModal());
    },
  };

  document.addEventListener('DOMContentLoaded', () => app.init());
})();