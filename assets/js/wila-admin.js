/**
 * هسته کلاینت داشبورد تحلیل لینک‌های داخلی
 * نسخه اولویت‌بندی شده بر اساس معماری سئو (Silo Structure)
 */
(function () {
  'use strict';

  function toPersianDigits(n) {
    if (n === null || n === undefined) return '';
    const farsiDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return n.toString().replace(/\d/g, (x) => farsiDigits[x]);
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
      hasApiKey: false,
      maskedKey: '',
      activeModel: 'gemini-3.8-flash',
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
        this.state.hasApiKey = data.has_api_key;
        this.state.maskedKey = data.masked_key || '';
        this.state.activeModel = data.active_model || 'gemini-3.8-flash';
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
          } catch (itemError) {
            console.warn(`خطا در اسکن آیتم رخ داد، عبور به آیتم بعدی...`);
          }
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
      if (!this.state.hasApiKey) {
        alert('لطفاً ابتدا کلید API را وارد کنید.');
        return;
      }

      this.state.modal.isOpen = true;
      this.state.modal.title = `پیشنهاد هوشمند برای: ${title}`;
      this.state.modal.contentHtml = `<div style="text-align:center; padding: 30px; font-weight:600;">در حال پردازش محتوا توسط مدل ${this.state.activeModel}...</div>`;
      this.render();

      try {
        const res = await this.apiFetch('/ai/suggest', { method: 'POST', body: JSON.stringify({ id, type }) });
        if (!res.success) throw new Error(res.message || 'خطا در تولید پاسخ.');

        if (!res.suggestions || res.suggestions.length === 0) {
            this.state.modal.contentHtml = `<p style="text-align:center; padding:20px;">هوش مصنوعی هیچ صفحه مرتبطی پیدا نکرد.</p>`;
            this.render();
            return;
        }

        let html = '';
        res.suggestions.forEach((s, idx) => {
          html += `
            <div class="wila-ai-card">
              <div class="wila-ai-card-title">${toPersianDigits(idx + 1)}. مبدا: ${s.source_title} <span class="wila-badge wila-badge-neutral">${s.type_label || s.source_type}</span></div>
              <div style="margin: 6px 0; font-size:0.825rem;"><strong>انکر تکست‌ها:</strong> ${s.recommended_anchor_texts.map(a => `<span class="wila-badge wila-badge-success" style="margin-left:4px;">${a}</span>`).join('')}</div>
              <div style="margin: 6px 0; font-size:0.825rem;"><strong>محل قرارگیری:</strong> ${s.placement_guide}</div>
              <div style="margin-top: 8px; font-size:0.8rem; color:#64748b; background:#ffffff; padding:8px; border-radius:4px; border:1px dashed #cbd5e1;"><strong>تحلیل:</strong> ${s.reasoning}</div>
            </div>`;
        });
        this.state.modal.contentHtml = html;
        this.render();
      } catch (err) {
        this.state.modal.contentHtml = `<p style="color:#ef4444; padding:20px; line-height: 1.6;">${err.message}</p>`;
        this.render();
      }
    },

    testConnection: async function () {
      const input = document.getElementById('wila-api-key-input');
      const modelInput = document.getElementById('wila-model-input');
      const key = input ? input.value.trim() : '';
      const model = modelInput ? modelInput.value.trim() : 'gemini-3.8-flash';
      const resultBox = document.getElementById('wila-test-result');
      const testBtn = document.getElementById('wila-test-api-btn');

      if (!key && !this.state.hasApiKey) {
        alert('لطفاً کلید API را وارد کنید.');
        return;
      }

      if (testBtn) { testBtn.disabled = true; testBtn.innerText = 'در حال بررسی...'; }
      if (resultBox) { resultBox.style.display = 'block'; resultBox.innerHTML = `<div style="background:#f8fafc; padding:12px; font-size:0.825rem;">در حال ارسال تست به ${model}...</div>`; }

      try {
        const res = await this.apiFetch('/test-connection', { method: 'POST', body: JSON.stringify({ api_key: key, model: model }) });
        if (!res.success) throw new Error(res.message);

        if (resultBox) {
          resultBox.innerHTML = `<div style="background:#ecfdf5; padding:14px; font-size:0.825rem; color:#065f46;">
            <div style="font-weight:600;">✅ اتصال موفقیت‌آمیز بود!</div>
            <div>${res.message}</div>
            <div>مدل: <code>${res.model}</code> | پاسخ: <strong>${toPersianDigits(res.latency_ms)} میلی‌‌ثانیه</strong></div>
          </div>`;
        }
      } catch (err) {
        if (resultBox) {
          resultBox.innerHTML = `<div style="background:#fef2f2; padding:14px; font-size:0.825rem; color:#991b1b; line-height:1.6;">
            <div style="font-weight:600;">❌ خطا در برقراری اتصال:</div><div>${err.message}</div>
          </div>`;
        }
      } finally {
        if (testBtn) { testBtn.disabled = false; testBtn.innerText = 'تست اتصال API'; }
      }
    },

    saveSettings: async function () {
      const input = document.getElementById('wila-api-key-input');
      const modelInput = document.getElementById('wila-model-input');
      const key = input ? input.value.trim() : '';
      const model = modelInput ? modelInput.value.trim() : 'gemini-3.8-flash';

      if (!key && !this.state.hasApiKey) {
        alert('لطفاً ابتدا کلید API را وارد کنید.');
        return;
      }

      try {
        await this.apiFetch('/settings', { method: 'POST', body: JSON.stringify({ api_key: key, model: model }) });
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
      const { activeTab, summary, isScanning, scanProgress, scanTotal, scanCurrent, modal, hasApiKey, maskedKey, activeModel } = this.state;
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
        
        // منطق گروه‌بندی اولویت‌دار سئو
        const priorityGroups = [
          { id: 'product_cat', title: 'اولویت ۱: دسته‌بندی محصولات', desc: 'هاب‌های اصلی توزیع اعتبار و هدایت مشتری (ستون‌های درآمدزا).', items: [] },
          { id: 'product', title: 'اولویت ۲: محصولات', desc: 'صفحات تبدیل (Conversion) که برای کسب رتبه نیاز به لینک ورودی دارند.', items: [] },
          { id: 'category', title: 'اولویت ۳: دسته‌بندی مقالات', desc: 'هاب‌های محتوایی (Pillar Pages) سایت.', items: [] },
          { id: 'posts', title: 'اولویت ۴: مقالات و محتواهای سفارشی', desc: 'تقویت‌کننده کلمات کلیدی طولانی (Long-tail).', items: [] },
          { id: 'page', title: 'اولویت ۵: برگه‌های عمومی', desc: 'صفحاتی که نیاز کمتری به دریافت لینک متنی درون‌مقاله‌ای دارند.', items: [] }
        ];

        this.state.orphans.forEach(item => {
          if (item.type === 'product_cat') priorityGroups[0].items.push(item);
          else if (item.type === 'product') priorityGroups[1].items.push(item);
          else if (item.type === 'category') priorityGroups[2].items.push(item);
          else if (item.type === 'page') priorityGroups[4].items.push(item);
          else priorityGroups[3].items.push(item); // post و سایر محتواهای سفارشی
        });

        let orphansHtml = '';
        if (this.state.orphans.length === 0) {
          orphansHtml = '<div style="text-align:center; padding:40px; color:#64748b;">هیچ صفحه یتیمی یافت نشد. سایت شما از نظر لینک‌سازی داخلی عالی است!</div>';
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
                          <td><span class="wila-badge wila-badge-neutral">${item.type_label || item.type}</span></td>
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
        contentHtml = `
          <div class="wila-card" style="max-width:640px;">
            <div class="wila-card-header"><h3 style="font-weight:600;">تنظیمات هوش مصنوعی Google Gemini</h3></div>
            <div style="margin-bottom:16px;">
              <label style="display:block; font-size:0.825rem; font-weight:500; margin-bottom:6px;">کلید API: ${hasApiKey ? `<span class="wila-badge wila-badge-success">فعال</span>` : ''}</label>
              <input type="text" id="wila-api-key-input" class="wila-input" style="width:100%; text-align:left; direction:ltr;" value="${hasApiKey ? maskedKey : ''}" placeholder="AIzaSy..." />
            </div>
            <div style="margin-bottom:24px;">
              <label style="display:block; font-size:0.825rem; font-weight:500; margin-bottom:6px;">نام دقیق مدل:</label>
              <input type="text" id="wila-model-input" class="wila-input" style="width:100%; text-align:left; direction:ltr;" value="${activeModel}" />
            </div>
            <div style="display:flex; gap:10px;">
              <button class="wila-btn wila-btn-primary" id="wila-save-settings-btn">ذخیره تنظیمات</button>
              <button class="wila-btn wila-btn-outline" id="wila-test-api-btn">تست اتصال</button>
            </div>
            <div id="wila-test-result" style="margin-top:16px; display:none;"></div>
          </div>`;
      }

      this.root.innerHTML = `
        <div class="wila-header"><div class="wila-header-title"><h1>لینکر | تحلیل پیوند یکتا و لینک‌سازی</h1></div></div>
        <div class="wila-tabs">
          <button class="wila-tab-btn ${activeTab === 'overview' ? 'active' : ''}" data-tab="overview">پیشخوان آماری</button>
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
        this.state.activeTab = e.target.getAttribute('data-tab');
        if (this.state.activeTab === 'items') this.fetchItems();
        if (this.state.activeTab === 'orphans') this.fetchOrphans();
        this.render();
      }));
      const scanBtn = this.root.querySelector('#wila-start-scan-btn');
      if (scanBtn) scanBtn.addEventListener('click', () => this.startBatchScan());
      
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