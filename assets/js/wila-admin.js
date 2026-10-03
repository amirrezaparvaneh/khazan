/**
 * هسته کلاینت داشبورد تحلیل لینک‌های داخلی
 * طراحی مینیمال بر پایه shadcn/ui با پشتیبانی از اعداد فارسی و تست زنده API
 */

(function () {
  'use strict';

  // تابع تبدیل اعداد انگلیسی به فارسی
  function toPersianDigits(n) {
    if (n === null || n === undefined) return '';
    const farsiDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return n.toString().replace(/\d/g, (x) => farsiDigits[x]);
  }

  // ترجمه فارسی نوع پست یا دسته‌بندی
  function translateType(type) {
    switch (type) {
      case 'post': return 'مقاله وبلاگ';
      case 'product_cat': return 'دسته محصول ووکامرس';
      case 'category': return 'دسته وبلاگ';
      default: return 'سایر';
    }
  }

  const app = {
    state: {
      activeTab: 'overview',
      summary: null,
      items: [],
      orphans: [],
      filterType: 'all',
      searchQuery: '',
      isScanning: false,
      scanProgress: 0,
      scanTotal: 0,
      scanCurrent: 0,
      apiKey: '',
      hasApiKey: false,
      modal: {
        isOpen: false,
        title: '',
        contentHtml: '',
      },
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
      const defaultHeaders = {
        'X-WP-Nonce': wilaData.nonce,
        'Content-Type': 'application/json',
      };
      const response = await fetch(url, {
        ...options,
        headers: { credentials: 'include', ...defaultHeaders, ...options.headers },
      });
      return await response.json();
    },

    fetchSummary: async function () {
      try {
        const data = await this.apiFetch('/summary');
        this.state.summary = data;
        this.render();
      } catch (e) {
        console.error('Error fetching summary:', e);
      }
    },

    fetchSettings: async function () {
      try {
        const data = await this.apiFetch('/settings');
        this.state.hasApiKey = data.has_api_key;
        this.state.apiKey = data.api_key;
      } catch (e) {
        console.error('Error fetching settings:', e);
      }
    },

    fetchItems: async function () {
      try {
        const type = this.state.filterType;
        const data = await this.apiFetch(`/items?type=${type}`);
        this.state.items = data;
        this.render();
      } catch (e) {
        console.error('Error fetching items:', e);
      }
    },

    fetchOrphans: async function () {
      try {
        const data = await this.apiFetch('/orphans');
        this.state.orphans = data;
        this.render();
      } catch (e) {
        console.error('Error fetching orphans:', e);
      }
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
        this.state.scanCurrent = 0;

        for (let i = 0; i < items.length; i++) {
          const item = items[i];
          await this.apiFetch('/scan-item', {
            method: 'POST',
            body: JSON.stringify({ id: item.id, type: item.type }),
          });

          this.state.scanCurrent = i + 1;
          this.state.scanProgress = Math.round(((i + 1) / items.length) * 100);
          this.render();
        }

        this.state.isScanning = false;
        await this.fetchSummary();
        alert('اسکن کامل لینک‌های سایت با موفقیت انجام شد!');
      } catch (err) {
        alert('خطا در فرآیند اسکن: ' + err.message);
        this.state.isScanning = false;
        this.render();
      }
    },

    showItemDetails: async function (id, type, title) {
      this.state.modal.isOpen = true;
      this.state.modal.title = `جزئیات پیوندهای: ${title}`;
      this.state.modal.contentHtml = '<p style="text-align:center; padding: 20px;">در حال واکشی اطلاعات روابط لینک‌ها...</p>';
      this.render();

      try {
        const details = await this.apiFetch(`/item-details?id=${id}&type=${type}`);
        let html = `
          <div style="margin-bottom: 20px;">
            <h4 style="font-weight:600; margin-bottom:8px; color:#0f172a;">
              لینک‌های خروجی (${toPersianDigits(details.outgoing_count)} پیوند)
            </h4>
            <p style="color:#64748b; font-size:0.8rem; margin-bottom:10px;">لینک‌هایی که در محتوای این صفحه قرار دارند:</p>
            ${details.outgoing_links.length === 0 ? '<p style="color:#94a3b8;">هیچ پیوند خروجی در متن ثبت نشده است.</p>' : `
              <table class="wila-table" style="font-size:0.8rem;">
                <thead>
                  <tr>
                    <th>انکر تکست (Anchor)</th>
                    <th>آدرس مقصد</th>
                    <th>نوع رابطه</th>
                  </tr>
                </thead>
                <tbody>
                  ${details.outgoing_links.map(l => `
                    <tr>
                      <td style="font-weight:600;">${l.anchor_text}</td>
                      <td><a href="${l.target_url}" target="_blank" style="color:#2563eb; text-decoration:none;">${l.target_url}</a></td>
                      <td><span class="wila-badge wila-badge-neutral">${l.rel_attribute || 'dofollow'}</span></td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            `}
          </div>

          <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
            <h4 style="font-weight:600; margin-bottom:8px; color:#0f172a;">
              لینک‌های ورودی (${toPersianDigits(details.incoming_count)} بک‌لینک داخلی)
            </h4>
            <p style="color:#64748b; font-size:0.8rem; margin-bottom:10px;">صفحاتی که به این صفحه لینک داده‌اند:</p>
            ${details.incoming_links.length === 0 ? '<p style="color:#ef4444; font-weight:500;">هیچ صفحه‌ای از سایت به این آدرس لینک نداده است (صفحه یتیم).</p>' : `
              <table class="wila-table" style="font-size:0.8rem;">
                <thead>
                  <tr>
                    <th>صفحه مبدا</th>
                    <th>نوع منبع</th>
                    <th>انکر تکست کار شده</th>
                  </tr>
                </thead>
                <tbody>
                  ${details.incoming_links.map(l => `
                    <tr>
                      <td><a href="${l.source_url}" target="_blank" style="color:#2563eb; text-decoration:none; font-weight:500;">${l.source_title}</a></td>
                      <td><span class="wila-badge wila-badge-neutral">${translateType(l.source_type)}</span></td>
                      <td style="font-weight:600;">${l.anchor_text}</td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            `}
          </div>
        `;

        this.state.modal.contentHtml = html;
        this.render();
      } catch (e) {
        this.state.modal.contentHtml = '<p style="color:#ef4444;">خطا در دریافت اطلاعات لینک‌ها.</p>';
        this.render();
      }
    },

    requestAiSuggestions: async function (id, type, title) {
      if (!this.state.hasApiKey) {
        alert('لطفاً ابتدا از تب تنظیمات، کلید Gemini API را وارد و ذخیره کنید.');
        return;
      }

      this.state.modal.isOpen = true;
      this.state.modal.title = `پیشنهادات هوشمند لینک‌سازی با Gemini برای: ${title}`;
      this.state.modal.contentHtml = `
        <div style="text-align:center; padding: 30px;">
          <div style="font-size:1.1rem; font-weight:600; margin-bottom:8px;">در حال پردازش محتوا توسط جمینای...</div>
          <p style="color:#64748b;">هوش مصنوعی در حال تحلیل مقالات و دسته‌بندی‌های سایت برای انتخاب بهترین استراتژی انکرتکست و مبدا است.</p>
        </div>
      `;
      this.render();

      try {
        const res = await this.apiFetch('/ai/suggest', {
          method: 'POST',
          body: JSON.stringify({ id, type }),
        });

        if (!res.success || !res.suggestions) {
          throw new Error(res.message || 'خطا در تولید پاسخ توسط هوش مصنوعی.');
        }

        const suggestions = res.suggestions;
        let html = `
          <p style="margin-bottom:16px; color:#475569; font-size:0.875rem;">
            جمینای صفحات زیر را برای تقویت لینک‌سازی این صفحه پیشنهاد داده است:
          </p>
        `;

        suggestions.forEach((s, idx) => {
          html += `
            <div class="wila-ai-card">
              <div class="wila-ai-card-title">
                ${toPersianDigits(idx + 1)}. مبدا پیشنهادی: ${s.source_title} 
                <span class="wila-badge wila-badge-neutral" style="margin-right:6px;">${translateType(s.source_type)}</span>
              </div>
              <div style="margin: 6px 0; font-size:0.825rem;">
                <strong>انکر تکست‌های پیشنهادی:</strong> 
                ${s.recommended_anchor_texts.map(a => `<span class="wila-badge wila-badge-success" style="margin-left:4px;">${a}</span>`).join('')}
              </div>
              <div style="margin: 6px 0; font-size:0.825rem;">
                <strong>تعداد تکرار در این محتوا:</strong> ${toPersianDigits(s.link_frequency)} بار
              </div>
              <div style="margin: 6px 0; font-size:0.825rem; color:#334155;">
                <strong>محل قرارگیری در متن:</strong> ${s.placement_guide}
              </div>
              <div style="margin-top: 8px; font-size:0.8rem; color:#64748b; background:#ffffff; padding:8px; border-radius:4px; border:1px dashed #cbd5e1;">
                <strong>تحلیل فنی:</strong> ${s.reasoning}
              </div>
            </div>
          `;
        });

        this.state.modal.contentHtml = html;
        this.render();
      } catch (err) {
        this.state.modal.contentHtml = `<p style="color:#ef4444; padding:20px;">خطا: ${err.message}</p>`;
        this.render();
      }
    },

    testConnection: async function () {
      const input = document.getElementById('wila-api-key-input');
      const key = input ? input.value.trim() : '';
      const resultBox = document.getElementById('wila-test-result');
      const testBtn = document.getElementById('wila-test-api-btn');

      if (!key && !this.state.hasApiKey) {
        alert('لطفاً ابتدا کلید API را وارد کنید.');
        return;
      }

      if (testBtn) {
        testBtn.disabled = true;
        testBtn.innerText = 'در حال بررسی اتصال...';
      }

      if (resultBox) {
        resultBox.style.display = 'block';
        resultBox.innerHTML = `
          <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px; border-radius:6px; font-size:0.825rem; color:#475569;">
            در حال ارسال درخواست تست به Google Gemini API...
          </div>
        `;
      }

      try {
        const res = await this.apiFetch('/test-connection', {
          method: 'POST',
          body: JSON.stringify({ api_key: key }),
        });

        if (!res.success) {
          throw new Error(res.message || 'پاسخ نامعتبر از سرور.');
        }

        if (resultBox) {
          resultBox.innerHTML = `
            <div style="background:#ecfdf5; border:1px solid #a7f3d0; padding:14px; border-radius:6px; font-size:0.825rem; color:#065f46;">
              <div style="font-weight:600; margin-bottom:4px;">✅ اتصال موفقیت‌آمیز بود!</div>
              <div>${res.message}</div>
              <div style="margin-top:6px; font-size:0.775rem; color:#047857;">
                مدل فعال: <code>${res.model}</code> | زمان پاسخ: <strong>${toPersianDigits(res.latency_ms)} میلی‌ثانیه</strong>
              </div>
            </div>
          `;
        }
      } catch (err) {
        if (resultBox) {
          resultBox.innerHTML = `
            <div style="background:#fef2f2; border:1px solid #fecaca; padding:14px; border-radius:6px; font-size:0.825rem; color:#991b1b;">
              <div style="font-weight:600; margin-bottom:4px;">❌ خطا در برقراری اتصال:</div>
              <div style="line-height:1.6;">${err.message}</div>
            </div>
          `;
        }
      } finally {
        if (testBtn) {
          testBtn.disabled = false;
          testBtn.innerText = 'تست اتصال API';
        }
      }
    },

    closeModal: function () {
      this.state.modal.isOpen = false;
      this.render();
    },

    saveSettings: async function () {
      const input = document.getElementById('wila-api-key-input');
      if (!input) return;
      const key = input.value.trim();

      try {
        await this.apiFetch('/settings', {
          method: 'POST',
          body: JSON.stringify({ api_key: key }),
        });
        alert('تنظیمات کلید Gemini API با موفقیت ذخیره شد.');
        await this.fetchSettings();
        this.render();
      } catch (e) {
        alert('خطا در ذخیره تنظیمات.');
      }
    },

    render: function () {
      const { activeTab, summary, isScanning, scanProgress, scanTotal, scanCurrent, modal } = this.state;

      let contentHtml = '';

      if (activeTab === 'overview') {
        contentHtml = `
          <div class="wila-progress-wrapper">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <div>
                <h3 style="margin:0 0 4px 0; font-size:1rem; font-weight:600;">پویش و تحلیل ساختار لینک‌های داخلی</h3>
                <p style="margin:0; font-size:0.8rem; color:#64748b;">
                  ${isScanning 
                    ? `در حال آنالیز آیتم ${toPersianDigits(scanCurrent)} از ${toPersianDigits(scanTotal)} (${toPersianDigits(scanProgress)}٪)` 
                    : 'برای به‌روزرسانی اطلاعات لینک‌ها، موتور اسکنر را اجرا کنید.'}
                </p>
              </div>
              <button class="wila-btn wila-btn-primary" id="wila-start-scan-btn" ${isScanning ? 'disabled' : ''}>
                ${isScanning ? 'در حال اسکن...' : 'شروع اسکن کامل لینک‌ها'}
              </button>
            </div>
            ${isScanning ? `
              <div class="wila-progress-bar-bg">
                <div class="wila-progress-bar-fill" style="width: ${scanProgress}%;"></div>
              </div>
            ` : ''}
          </div>

          <div class="wila-grid">
            <div class="wila-card">
              <div class="wila-card-header">
                <h3>کل پیوندهای داخلی ایندکس‌شده</h3>
              </div>
              <div class="wila-card-value">${summary ? toPersianDigits(summary.total_links) : '---'}</div>
              <div class="wila-card-desc">تعداد کل تگ‌های لینک یافت‌شده در متن‌ها</div>
            </div>

            <div class="wila-card">
              <div class="wila-card-header">
                <h3>دسته‌بندی‌های محصولات</h3>
              </div>
              <div class="wila-card-value">${summary ? toPersianDigits(summary.total_product_cats) : '---'}</div>
              <div class="wila-card-desc">تعداد دسته‌های فروشگاه ووکامرس</div>
            </div>

            <div class="wila-card">
              <div class="wila-card-header">
                <h3>مجموع مقالات وبلاگ</h3>
              </div>
              <div class="wila-card-value">${summary ? toPersianDigits(summary.total_posts) : '---'}</div>
              <div class="wila-card-desc">پست‌های منتشر شده وبلاگ</div>
            </div>

            <div class="wila-card" style="border-right: 4px solid #ef4444;">
              <div class="wila-card-header">
                <h3 style="color:#ef4444;">صفحات یتیم (بدون ورودی)</h3>
              </div>
              <div class="wila-card-value" style="color:#ef4444;">${summary ? toPersianDigits(summary.orphan_count) : '---'}</div>
              <div class="wila-card-desc">شامل ${summary ? toPersianDigits(summary.orphan_cats) : '۰'} دسته و ${summary ? toPersianDigits(summary.orphan_posts) : '۰'} مقاله</div>
            </div>
          </div>
        `;
      } else if (activeTab === 'items') {
        contentHtml = `
          <div class="wila-table-container">
            <div class="wila-table-toolbar">
              <div style="display:flex; gap:10px; align-items:center;">
                <select class="wila-select" id="wila-filter-type">
                  <option value="all" ${this.state.filterType === 'all' ? 'selected' : ''}>همه بخش‌ها</option>
                  <option value="product_cat" ${this.state.filterType === 'product_cat' ? 'selected' : ''}>دسته‌بندی‌های محصولات</option>
                  <option value="post" ${this.state.filterType === 'post' ? 'selected' : ''}>مقالات وبلاگ</option>
                  <option value="category" ${this.state.filterType === 'category' ? 'selected' : ''}>دسته‌بندی‌های وبلاگ</option>
                </select>
              </div>
            </div>
            <table class="wila-table">
              <thead>
                <tr>
                  <th>عنوان صفحه یا دسته</th>
                  <th>نوع</th>
                  <th>لینک‌های ورودی</th>
                  <th>لینک‌های خروجی</th>
                  <th>وضعیت</th>
                  <th>عملیات</th>
                </tr>
              </thead>
              <tbody>
                ${this.state.items.length === 0 ? '<tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">داده‌ای یافت نشد. ابتدا دکمه اسکن را در تب پیشخوان اجرا کنید.</td></tr>' : 
                  this.state.items.map(item => `
                    <tr>
                      <td style="font-weight:600;"><a href="${item.url}" target="_blank" style="color:inherit; text-decoration:none;">${item.title}</a></td>
                      <td><span class="wila-badge wila-badge-neutral">${translateType(item.type)}</span></td>
                      <td><strong style="color: ${item.in_links === 0 ? '#ef4444' : '#0f172a'};">${toPersianDigits(item.in_links)}</strong></td>
                      <td>${toPersianDigits(item.out_links)}</td>
                      <td>
                        ${item.is_orphan 
                          ? '<span class="wila-badge wila-badge-warning">صفحه یتیم</span>' 
                          : '<span class="wila-badge wila-badge-success">لینک‌دار</span>'}
                      </td>
                      <td>
                        <button class="wila-btn wila-btn-outline wila-btn-sm wila-inspect-btn" data-id="${item.id}" data-type="${item.type}" data-title="${item.title}">
                          مشاهده لینک‌ها
                        </button>
                      </td>
                    </tr>
                  `).join('')
                }
              </tbody>
            </table>
          </div>
        `;
      } else if (activeTab === 'orphans') {
        contentHtml = `
          <div class="wila-table-container">
            <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; background:#fef2f2;">
              <h3 style="margin:0 0 4px 0; color:#991b1b; font-size:0.95rem;">فرصت‌های طلایی بهینه‌سازی لینک‌سازی (Orphan Pages)</h3>
              <p style="margin:0; font-size:0.8rem; color:#b91c1c;">صفحات و دسته‌بندی‌های زیر از هیچ صفحه‌ای در سایت لینک داخلی دریافت نکرده‌اند. با دکمه پیشنهاد هوشمند جمینای بهترین فرصت‌های لینک‌سازی را استخراج کنید.</p>
            </div>
            <table class="wila-table">
              <thead>
                <tr>
                  <th>عنوان صفحه یا دسته‌بندی</th>
                  <th>نوع منبع</th>
                  <th>لینک‌های ورودی</th>
                  <th>تحلیل و استراتژی هوش مصنوعی</th>
                </tr>
              </thead>
              <tbody>
                ${this.state.orphans.length === 0 ? '<tr><td colspan="4" style="text-align:center; padding:30px; color:#94a3b8;">هیچ صفحه یتیمی یافت نشد یا هنوز اسکن اجرا نشده است.</td></tr>' : 
                  this.state.orphans.map(item => `
                    <tr>
                      <td style="font-weight:600;"><a href="${item.url}" target="_blank" style="color:inherit; text-decoration:none;">${item.title}</a></td>
                      <td><span class="wila-badge wila-badge-neutral">${translateType(item.type)}</span></td>
                      <td><span class="wila-badge wila-badge-warning">${toPersianDigits(0)} ورودی</span></td>
                      <td>
                        <button class="wila-btn wila-btn-primary wila-btn-sm wila-ai-btn" data-id="${item.id}" data-type="${item.type}" data-title="${item.title}">
                          ✨ پیشنهاد انکر و لینک با Gemini
                        </button>
                      </td>
                    </tr>
                  `).join('')
                }
              </tbody>
            </table>
          </div>
        `;
      } else if (activeTab === 'settings') {
        contentHtml = `
          <div class="wila-card" style="max-width:640px;">
            <div class="wila-card-header">
              <h3 style="font-weight:600; color:#0f172a; font-size:1rem;">تنظیمات هوش مصنوعی Google Gemini</h3>
            </div>
            <p style="font-size:0.875rem; color:#64748b; margin-bottom:16px;">
              برای فعال‌سازی بخش پیشنهادات انکرتکست و آنالیز محتوا، کلید API سرویس Gemini را وارد کنید.
            </p>
            <div style="margin-bottom:16px;">
              <label style="display:block; font-size:0.825rem; font-weight:500; margin-bottom:6px;">کلید API جمینای:</label>
              <input type="password" id="wila-api-key-input" class="wila-input" style="width:100%;" placeholder="AIzaSy..." value="${this.state.apiKey}" />
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
              <button class="wila-btn wila-btn-primary" id="wila-save-settings-btn">ذخیره تنظیمات</button>
              <button class="wila-btn wila-btn-outline" id="wila-test-api-btn">تست اتصال API</button>
            </div>
            <div id="wila-test-result" style="margin-top:16px; display:none;"></div>
          </div>
        `;
      }

      this.root.innerHTML = `
        <div class="wila-header">
          <div class="wila-header-title">
            <h1>لینکر | تحلیل پیوند یکتا و لینک‌سازی داخلی</h1>
            <p>داشبورد آنالیز ارتباطات پیوندی مقالات، دسته‌بندی‌های محصول و پیشنهادات هوشمند سئو</p>
          </div>
        </div>

        <div class="wila-tabs">
          <button class="wila-tab-btn ${activeTab === 'overview' ? 'active' : ''}" data-tab="overview">پیشخوان آماری</button>
          <button class="wila-tab-btn ${activeTab === 'items' ? 'active' : ''}" data-tab="items">کاوشگر پیوندها</button>
          <button class="wila-tab-btn ${activeTab === 'orphans' ? 'active' : ''}" data-tab="orphans">صفحات بدون لینک (یتیم)</button>
          <button class="wila-tab-btn ${activeTab === 'settings' ? 'active' : ''}" data-tab="settings">تنظیمات API</button>
        </div>

        <div class="wila-content">
          ${contentHtml}
        </div>

        ${modal.isOpen ? `
          <div class="wila-modal-backdrop" id="wila-modal-backdrop">
            <div class="wila-modal">
              <div class="wila-modal-header">
                <h3>${modal.title}</h3>
                <button class="wila-btn wila-btn-outline wila-btn-sm" id="wila-close-modal-btn">✕</button>
              </div>
              <div class="wila-modal-body">
                ${modal.contentHtml}
              </div>
              <div class="wila-modal-footer">
                <button class="wila-btn wila-btn-outline" id="wila-modal-footer-close">بستن</button>
              </div>
            </div>
          </div>
        ` : ''}

        <div class="wila-footer">
          <div>توسعه یافته با ❤️ توســـط امیــــــــررضا پـروانه</div>
          <div>نسخه ۱.۰.۰</div>
        </div>
      `;

      this.bindEvents();
    },

    bindEvents: function () {
      const tabBtns = this.root.querySelectorAll('.wila-tab-btn');
      tabBtns.forEach((btn) => {
        btn.addEventListener('click', (e) => {
          const tab = e.target.getAttribute('data-tab');
          this.state.activeTab = tab;
          if (tab === 'items') this.fetchItems();
          if (tab === 'orphans') this.fetchOrphans();
          this.render();
        });
      });

      const scanBtn = this.root.querySelector('#wila-start-scan-btn');
      if (scanBtn) {
        scanBtn.addEventListener('click', () => this.startBatchScan());
      }

      const filterSelect = this.root.querySelector('#wila-filter-type');
      if (filterSelect) {
        filterSelect.addEventListener('change', (e) => {
          this.state.filterType = e.target.value;
          this.fetchItems();
        });
      }

      const inspectBtns = this.root.querySelectorAll('.wila-inspect-btn');
      inspectBtns.forEach((btn) => {
        btn.addEventListener('click', (e) => {
          const id = e.currentTarget.getAttribute('data-id');
          const type = e.currentTarget.getAttribute('data-type');
          const title = e.currentTarget.getAttribute('data-title');
          this.showItemDetails(id, type, title);
        });
      });

      const aiBtns = this.root.querySelectorAll('.wila-ai-btn');
      aiBtns.forEach((btn) => {
        btn.addEventListener('click', (e) => {
          const id = e.currentTarget.getAttribute('data-id');
          const type = e.currentTarget.getAttribute('data-type');
          const title = e.currentTarget.getAttribute('data-title');
          this.requestAiSuggestions(id, type, title);
        });
      });

      const saveSettingsBtn = this.root.querySelector('#wila-save-settings-btn');
      if (saveSettingsBtn) {
        saveSettingsBtn.addEventListener('click', () => this.saveSettings());
      }

      const testApiBtn = this.root.querySelector('#wila-test-api-btn');
      if (testApiBtn) {
        testApiBtn.addEventListener('click', () => this.testConnection());
      }

      const closeBtn1 = this.root.querySelector('#wila-close-modal-btn');
      const closeBtn2 = this.root.querySelector('#wila-modal-footer-close');
      if (closeBtn1) closeBtn1.addEventListener('click', () => this.closeModal());
      if (closeBtn2) closeBtn2.addEventListener('click', () => this.closeModal());
    },
  };

  document.addEventListener('DOMContentLoaded', () => {
    app.init();
  });
})();