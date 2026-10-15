/**
 * Khazan - bulk CSV queue: state, row selection, start/pause/stop, progress and log.
 * Mixed into the dashboard app by wila-admin.js (methods run with `this` === app).
 * Author: امیــــــــررضا پـروانه
 */
(function () {
  'use strict';

  const { toPersianDigits, formatJalali, shortJalali, escapeHtml } = window.KhazanUtils;

  const methods = {
    // ---------- تولید انبوه از شیت (CSV) ----------
    // پارس و تشخیص ستون‌های CSV در assets/js/khazan-csv.js قرار دارد
    parseCsv: function (text) { return window.KhazanCsv.parse(text); },
    mapCsvHeader: function (header) { return window.KhazanCsv.mapHeader(header); },
    findCsvHeaderRow: function (table) { return window.KhazanCsv.findHeaderRow(table); },
    isApprovedValue: function (value) { return window.KhazanCsv.isApproved(value); },

    // دکمه سه‌بعدی؛ متن همیشه داخل لایه .btn-top قرار می‌گیرد
    btn3d: function (id, variant, text, attrs) {
      return `<button type="button" id="${id}" class="khazan-3d-btn ${variant}" ${attrs || ''}><span class="btn-top">${text}</span><span class="btn-bottom"></span><span class="btn-base"></span></button>`;
    },

    formatFileSize: function (bytes) {
      if (!bytes) return '';
      return bytes < 1024 * 1024 ? toPersianDigits((bytes / 1024).toFixed(1)) + ' کیلوبایت' : toPersianDigits((bytes / 1024 / 1024).toFixed(2)) + ' مگابایت';
    },

    loadBulkFile: async function (file) {
      const app = this;
      if (!file) return;
      const b = this.state.bulk;
      // ردیف‌های صف «محصولات سایت» با بارگذاری CSV پاک نمی‌شوند؛ بعد از تطبیق به انتهای جدول برمی‌گردند
      const keepSite = b.rows.map((r, i) => ({ row: r, selected: !!b.selected[i] })).filter(x => x.row.source === 'site');
      try {
        const table = this.parseCsv(await file.text());
        if (table.length < 2) throw new Error('فایل خالی است یا ردیف داده ندارد.');
        const found = this.findCsvHeaderRow(table);
        if (!found) {
          const seen = table.slice(0, 5).map(r => r.map(c => String(c).trim()).filter(Boolean).join(' | ')).filter(Boolean);
          console.log('WILA CSV headers (first rows):', seen);
          throw new Error('ستون «نام محصول» یا «نامک» در فایل پیدا نشد. هدرهای خوانده‌شده: ' + (seen[0] || '—'));
        }
        const map = found.map;
        console.log('WILA CSV header row:', found.index + 1, table[found.index], map);
        b.rows = table.slice(found.index + 1).map(cells => {
          const row = { approved: false, run: '', match: null };
          map.forEach((key, idx) => { if (key) row[key] = (cells[idx] || '').trim(); });
          row.approved = this.isApprovedValue(row.approved);
          return row;
        }).filter(r => r.name || r.slug || r.product_url);
        b.fileName = file.name; b.fileSize = file.size; b.selected = {}; b.log = []; b.filter = 'all'; b.view = 'found'; b.matching = true;
        this.render();
        const res = await this.apiFetch('/bulk/match', { method: 'POST', body: JSON.stringify({ file_name: b.fileName, rows: b.rows.map(r => ({ name: r.name || '', slug: r.slug || r.product_url || '', focus_keyword: r.focus_keyword || '' })) }) });
        if (!res.success) throw new Error(res.message || 'تطبیق انجام نشد.');
        const hasApproval = map.includes('approved');
        res.matches.forEach((m, i) => {
          b.rows[i].match = m;
          // ردیف‌های تولیدشده یا آپلودشده قبلی به‌طور پیش‌فرض انتخاب نمی‌شوند
          if (m.matched && !m.generated && (!hasApproval || b.rows[i].approved)) b.selected[i] = true;
        });
        const already = res.matches.filter(m => m.matched && m.generated).length;
        const uploaded = res.matches.filter(m => m.history).length;
        const fresh = res.matches.filter(m => !m.history && !m.generated).length;
        app.toast(`${toPersianDigits(res.matches.filter(m => m.matched).length)} از ${toPersianDigits(b.rows.length)} ردیف به محصولات سایت متصل شد. ${toPersianDigits(fresh)} ردیف جدید و ${toPersianDigits(uploaded)} ردیف قبلاً آپلود شده است.${already ? ` ${toPersianDigits(already)} مورد قبلاً تولید شده و انتخاب نشد.` : ''}`);
      } catch (e) { b.rows = []; app.toast('خطا: ' + e.message); }
      keepSite.forEach(x => { const idx = b.rows.push(x.row) - 1; if (x.selected) b.selected[idx] = true; });
      b.matching = false;
      this.render();
    },

    // ردیف‌های متعلق به منبع داده‌ی فعلی (CSV یا محصولات سایت)؛ دو صف هرگز با هم قاطی نمی‌شوند
    bulkSourceIndexes: function () {
      const b = this.state.bulk;
      return b.rows.map((r, i) => i).filter(i => (b.rows[i].source === 'site' ? 'site' : 'csv') === b.source);
    },

    bulkVisibleIndexes: function () {
      const b = this.state.bulk;
      // محصولات سایت همگی از قبل در سایت وجود دارند و متصل‌اند؛ تفکیک متصل/یافت‌نشده و فیلتر وضعیت فقط برای CSV معنا دارد
      if (b.source === 'site') return this.bulkSourceIndexes();
      return this.bulkSourceIndexes().filter(i => {
        const m = b.rows[i].match;
        // فیلتر وضعیت فقط روی «محتوا تولید شده» است؛ ردیف‌های صرفاً آپلودشده در انتظار تولید محسوب می‌شوند
        const generated = !!(m && m.generated);
        if (b.status === 'pending' && generated) return false;
        if (b.status === 'generated' && !generated) return false;
        const matched = !!(m && m.matched);
        return b.view === 'missing' ? !matched : matched;
      });
    },

    // ردیف‌های مجاز برای «انتخاب همه»: متصل‌شده و بدون سابقه تولید (موارد قبلاً تولیدشده فقط دستی انتخاب می‌شوند)
    bulkSelectableIndexes: function () {
      const b = this.state.bulk;
      // در فیلتر «قبلاً تولیدشده‌ها» همه ردیف‌های قابل‌مشاهده برای تولید مجدد قابل انتخابند
      return this.bulkVisibleIndexes().filter(i => this.bulkIsTargetable(i) && (b.source === 'site' || b.status === 'generated' || !(b.rows[i].match && b.rows[i].match.generated)));
    },

    // ردیف متصل‌شده، یا ردیف یافت‌نشده وقتی «ایجاد خودکار محصول پیش‌نویس» روشن است
    bulkIsTargetable: function (i) {
      const b = this.state.bulk, row = b.rows[i];
      if (row.match && row.match.matched) return true;
      return b.createMissing && String(row.name || '').trim() !== '';
    },

    bulkRowTitle: function (row) {
      return row.match && row.match.title ? row.match.title : (row.name || row.slug || '');
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

    // زمان باقی‌مانده تقریبی بر پایه میانگین زمان محصولات تمام‌شده
    bulkEtaText: function () {
      const b = this.state.bulk;
      if (!b.running || !b.done || !b.startedAt || b.done >= b.total) return '';
      const seconds = Math.max(1, Math.round(((Date.now() - b.startedAt) / b.done) * (b.total - b.done) / 1000));
      const h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60), sec = seconds % 60;
      const parts = [];
      if (h) parts.push(`${toPersianDigits(h)} ساعت`);
      if (m) parts.push(`${toPersianDigits(m)} دقیقه`);
      if (!h && (sec || !m)) parts.push(`${toPersianDigits(sec)} ثانیه`);
      return `زمان باقی‌مانده تقریبی: ${parts.join(' و ')}`;
    },

    bulkProgressHtml: function () {
      const b = this.state.bulk;
      const pct = b.total ? Math.round((b.done / b.total) * 100) : 0;
      const eta = this.bulkEtaText();
      return b.total ? `<div class="khazan-progress-head"><span>${b.running ? (b.paused ? 'متوقف موقت' : 'در حال پردازش...') : 'پایان / توقف'}${eta ? ` <small class="khazan-progress-eta">· ${eta}</small>` : ''}</span><span class="khazan-progress-count">تکمیل شده: ${toPersianDigits(b.done)} از ${toPersianDigits(b.total)} (${toPersianDigits(pct)}٪)</span></div><div class="wila-progress-bar-bg"><div class="wila-progress-bar-fill" style="width:${pct}%"></div></div>` : '';
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

    // single: فقط یک ردیف (دکمه «تولید این ردیف»)؛ ردیف‌های قبلاً تولیدشده هم دوباره تولید می‌شوند
    startBulk: async function (single) {
      const app = this;
      const b = this.state.bulk;
      if (b.running) return;
      const isSingle = Number.isInteger(single);
      const queue = isSingle
        ? (this.bulkIsTargetable(single) ? [single] : [])
        : this.bulkSourceIndexes().filter(i => b.selected[i] && this.bulkIsTargetable(i) && b.rows[i].run !== 'done');
      if (!queue.length) return app.toast('هیچ ردیف متصل‌شده‌ای برای پردازش انتخاب نشده است.', 'error');
      queue.forEach(i => { b.rows[i].run = ''; b.rows[i].error = ''; });
      if (!isSingle && b.mode === 'replace' && !confirm(`توضیحات فعلی ${toPersianDigits(queue.length)} محصول جایگزین می‌شود (نسخه قبلی در پایگاه‌داده نگه داشته می‌شود). ادامه می‌دهید؟`)) return;
      // شناسه اجرا و فهرست محصولات صف؛ سرور با آن‌ها لینک‌های ورودی را بین محصولات هم‌دسته به‌طور مساوی پخش می‌کند
      const runId = 'run-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
      const queueProductIds = queue.map(i => b.rows[i].match && b.rows[i].match.matched ? b.rows[i].match.product_id : 0).filter(Boolean);
      Object.assign(b, { running: true, paused: false, stopRequested: false, total: queue.length, done: 0, startedAt: Date.now() });
      this.bulkLog('info', `شروع پردازش ${toPersianDigits(queue.length)} محصول.`);
      this.render();
      const sleep = ms => new Promise(r => setTimeout(r, ms));
      // انتظار قابل‌قطع با دکمه توقف
      const wait = async ms => { for (let t = 0; t < ms && !b.stopRequested; t += 250) await sleep(250); };
      const isTemporary = r => r && (r.code === 'ai_rate_limited' || (r.data && [429, 503].includes(Number(r.data.status))));
      for (let n = 0; n < queue.length; n++) {
        const i = queue[n];
        while (b.paused && !b.stopRequested) await sleep(300);
        if (b.stopRequested) break;
        const row = b.rows[i];
        row.run = 'running'; this.bulkLog('info', `در حال تولید: ${this.bulkRowTitle(row)}`); this.bulkRefreshUi();
        for (let attempt = 0; attempt <= 2; attempt++) {
          try {
            const r = await this.apiFetch('/bulk/process', { method: 'POST', body: JSON.stringify({ product_id: row.match && row.match.matched ? row.match.product_id : 0, row, options: { mode: b.mode, inject_seo: b.injectSeo, auto_links: b.autoLinks, create_missing: b.createMissing, run_id: runId, queue_ids: queueProductIds } }) });
            if (!r.success) {
              const err = new Error(r.message || 'تولید انجام نشد.'); err.temporary = isTemporary(r); throw err;
            }
            if (r.created) row.match = { matched: true, via: 'created', product_id: r.product_id, title: r.title, url: r.url };
            row.run = 'done'; row.editUrl = r.edit_url; row.match.generated = r.generated;
            this.bulkLog('ok', `انجام شد: ${this.bulkRowTitle(row)} (لینک داخلی: ${toPersianDigits(r.internal_link_count)})`);
            break;
          } catch (e) {
            const temporary = e.temporary || e instanceof TypeError; // TypeError: خطای شبکه در fetch
            if (temporary && attempt < 2 && !b.stopRequested) {
              const delay = 10000 * Math.pow(2, attempt);
              this.bulkLog('info', `خطای موقت (${e.message}) در ${this.bulkRowTitle(row)}؛ تلاش مجدد ${toPersianDigits(attempt + 1)} از ۲ پس از ${toPersianDigits(delay / 1000)} ثانیه.`);
              this.bulkRefreshUi();
              await wait(delay);
              continue;
            }
            row.run = 'error'; row.error = e instanceof SyntaxError ? 'پاسخ نامعتبر سرور (احتمالاً Timeout)' : e.message;
            this.bulkLog('err', `خطا در ${this.bulkRowTitle(row)}: ${row.error}`);
            break;
          }
        }
        b.done++; this.bulkRefreshUi();
        // فاصله ۳ ثانیه‌ای بین محصولات برای جلوگیری از Rate Limit
        if (n < queue.length - 1 && !b.stopRequested) await wait(3000);
      }
      this.bulkLog('info', b.stopRequested ? 'پردازش متوقف شد.' : 'پردازش صف به پایان رسید.');
      b.running = false; b.paused = false;
      this.render();
      app.toast(b.stopRequested ? 'پردازش متوقف شد.' : 'پردازش انبوه به پایان رسید.');
    },
    // اقدام سریع داشبورد: رفتن به تولید انبوه با منبع «محصولات سایت» و فیلتر محصولات بدون توضیحات
    goBulkEmptyProducts: function () {
      const b = this.state.bulk;
      if (b.running) return;
      this.state.activeTab = 'bulk';
      this.state.activeNav = 'bulk';
      b.source = 'site';
      Object.assign(b.site, { filter: 'empty', category: 0, search: '', collapsed: false });
      this.loadSiteProducts();
    },

    setBulkSource: function (source) {
      const b = this.state.bulk;
      if (b.running || (source !== 'csv' && source !== 'site') || b.source === source) return;
      b.source = source;
      if (source === 'site' && !b.site.loaded) this.loadSiteProducts();
      this.render();
    },

    // کارت آماری وضعیت محتوا: فیلتر را عوض می‌کند و فهرست را بلافاصله از سرور دوباره می‌خواند
    setSiteFilter: function (filter) {
      const b = this.state.bulk;
      if (b.running || b.site.loading || !['all', 'empty', 'has_content'].includes(filter) || b.site.filter === filter) return;
      b.site.filter = filter;
      this.loadSiteProducts();
    },

    // برگشت از بخش انتخاب محصولات به صف (بدون افزودن محصول جدید)
    returnToSiteQueue: function () {
      const b = this.state.bulk;
      if (b.running || !this.bulkSourceIndexes().length) return;
      b.site.collapsed = true;
      this.state.pendingScroll = 'wila-queue-settings';
      this.render();
    },

    // بازکردن دوباره بخش انتخاب محصولات سایت (فهرست و انتخاب‌ها حفظ می‌شوند)
    reopenSiteSelection: function () {
      const b = this.state.bulk;
      if (b.running || !b.site.collapsed) return;
      b.site.collapsed = false;
      this.state.pendingScroll = 'wila-bulk-source';
      this.render();
    },

    // محصولات بارگذاری‌شده از سایت را (بدون تکرار) به جدول صف اضافه می‌کند
    addSiteProductsToQueue: function () {
      const b = this.state.bulk;
      if (b.running) return;
      const known = new Set(b.rows.filter(r => r.match && r.match.matched).map(r => Number(r.match.product_id)));
      let added = 0;
      b.site.items.filter(p => b.site.selected[p.id]).forEach(p => {
        if (known.has(Number(p.id))) return;
        const seo = p.seo || {};
        const idx = b.rows.length;
        b.rows.push({
          name: p.title, slug: p.slug || '', approved: true, run: '', source: 'site',
          focus_keyword: seo.focuskw || '', related_keywords: seo.synonyms || '', seo_title: '', meta_description: '', description: '',
          match: { matched: true, via: 'site', product_id: p.id, title: p.title, url: p.url, status: p.status, generated: p.generated || null, history: null }
        });
        if (!p.generated) b.selected[idx] = true;
        added++;
      });
      b.status = 'pending';
      b.view = 'found';
      this.toast(added ? `${toPersianDigits(added)} محصول به صف اضافه شد.` : 'همه این محصولات قبلاً در صف هستند.', added ? 'success' : 'error');
      if (added) {
        // مرحله بعد: بخش انتخاب جمع می‌شود و صفحه به تنظیمات و جدول صف اسکرول می‌کند
        b.site.collapsed = true;
        b.site.addedCount = added;
        this.state.pendingScroll = 'wila-queue-settings';
      }
      this.render();
    },

    bindQueueEvents: function () {
      const bulk = this.state.bulk;
      const bulkFile = this.root.querySelector('#wila-bulk-file');
      if (bulkFile) bulkFile.addEventListener('change', e => this.loadBulkFile(e.target.files[0]));
      const dropzone = this.root.querySelector('#wila-bulk-dropzone');
      if (dropzone && !this.state.bulk.running) {
        // کلیک روی کل باکس یا دکمه «انتخاب فایل» (و Enter/Space) انتخاب‌گر فایل را باز می‌کند؛ کلیک خودِ input نادیده گرفته می‌شود تا حلقه نشود
        const openPicker = () => { if (bulkFile && !this.state.bulk.running) bulkFile.click(); };
        // کلیک روی لیبل «انتخاب فایل از سیستم» را خود مرورگر مدیریت می‌کند؛ فقط کلیک‌های بیرون از آن باز کردن انتخاب‌گر را صدا می‌زنند
        const insideLabel = t => !!(t && t.closest && t.closest('.custom-file-upload'));
        dropzone.addEventListener('click', e => { if (e.target !== bulkFile && !insideLabel(e.target)) openPicker(); });
        dropzone.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPicker(); } });
        ['dragenter', 'dragover'].forEach(ev => dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.add('is-drag'); }));
        ['dragleave', 'drop'].forEach(ev => dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.remove('is-drag'); }));
        dropzone.addEventListener('drop', e => {
          const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
          if (!f) return;
          if (!/\.csv$/i.test(f.name) && f.type !== 'text/csv') { this.toast('خطا: فقط فایل CSV قابل قبوله.'); return; }
          this.loadBulkFile(f);
        });
      }
      this.root.querySelectorAll('.wila-bulk-source').forEach(btn => btn.addEventListener('click', e => this.setBulkSource(e.currentTarget.getAttribute('data-source'))));
      this.root.querySelectorAll('.khazan-stat-card').forEach(card => card.addEventListener('click', e => this.setSiteFilter(e.currentTarget.getAttribute('data-site-filter'))));
      const siteCategory = this.root.querySelector('#wila-site-category'); if (siteCategory) siteCategory.addEventListener('change', e => { bulk.site.category = Number(e.target.value) || 0; if (!bulk.running) this.loadSiteProducts(); });
      const siteSearch = this.root.querySelector('#wila-site-search');
      if (siteSearch) {
        siteSearch.addEventListener('input', e => { bulk.site.search = e.target.value; });
        siteSearch.addEventListener('keydown', e => { if (e.key === 'Enter' && !bulk.running) { e.preventDefault(); this.loadSiteProducts(); } });
      }
      // نوار بالا، نوار پایین و هدر جدول هر سه یک state را عوض می‌کنند و با رندر دوباره هم‌گام می‌شوند
      this.root.querySelectorAll('.wila-site-select-all').forEach(box => box.addEventListener('change', e => {
        bulk.site.selected = {};
        if (e.target.checked) bulk.site.items.forEach(p => { bulk.site.selected[p.id] = true; });
        this.render();
      }));
      this.root.querySelectorAll('.wila-site-reopen').forEach(btn => btn.addEventListener('click', () => this.reopenSiteSelection()));
      this.root.querySelectorAll('.wila-site-return').forEach(btn => btn.addEventListener('click', () => this.returnToSiteQueue()));
      this.root.querySelectorAll('.wila-site-row').forEach(box => box.addEventListener('change', e => { bulk.site.selected[e.target.dataset.id] = e.target.checked; this.render(); }));
      const siteLoad = this.root.querySelector('#wila-site-load'); if (siteLoad) siteLoad.addEventListener('click', () => this.loadSiteProducts());
      this.root.querySelectorAll('.wila-site-add').forEach(btn => btn.addEventListener('click', () => this.addSiteProductsToQueue()));
      const bulkMode = this.root.querySelector('#wila-bulk-mode'); if (bulkMode) bulkMode.addEventListener('change', e => { bulk.mode = e.target.value; });
      this.root.querySelectorAll('.wila-bulk-view').forEach(btn => btn.addEventListener('click', e => { bulk.view = e.currentTarget.getAttribute('data-view'); this.render(); }));
      const bulkSeo = this.root.querySelector('#wila-bulk-seo'); if (bulkSeo) bulkSeo.addEventListener('change', e => { bulk.injectSeo = e.target.checked; });
      const bulkLinks = this.root.querySelector('#wila-bulk-links'); if (bulkLinks) bulkLinks.addEventListener('change', e => { bulk.autoLinks = e.target.checked; });
      const bulkStatus = this.root.querySelector('#wila-bulk-status');
      if (bulkStatus) bulkStatus.addEventListener('change', e => { bulk.status = e.target.value; this.render(); });
      const bulkCreate = this.root.querySelector('#wila-bulk-create');
      if (bulkCreate) bulkCreate.addEventListener('change', e => {
        bulk.createMissing = e.target.checked;
        // با خاموش‌شدن گزینه، انتخاب ردیف‌های یافت‌نشده هم برداشته می‌شود
        if (!bulk.createMissing) Object.keys(bulk.selected).forEach(i => { if (!(bulk.rows[i].match && bulk.rows[i].match.matched)) delete bulk.selected[i]; });
        this.render();
      });
      this.root.querySelectorAll('.wila-bulk-row').forEach(box => box.addEventListener('change', e => { bulk.selected[e.target.dataset.index] = e.target.checked; this.render(); }));
      this.root.querySelectorAll('.wila-bulk-single').forEach(btn => btn.addEventListener('click', () => this.startBulk(Number(btn.dataset.index))));
      const bulkAll = this.root.querySelector('#wila-bulk-select-all');
      if (bulkAll) {
        const visibleSelected = this.bulkVisibleIndexes().filter(i => bulk.selected[i]).length;
        bulkAll.indeterminate = !bulkAll.checked && visibleSelected > 0;
        bulkAll.addEventListener('change', e => {
          // تیک‌خوردن: انتخاب ردیف‌های مجاز؛ برداشتن تیک: برداشتن تیک همه ردیف‌های قابل‌مشاهده
          if (e.target.checked) this.bulkSelectableIndexes().forEach(i => { bulk.selected[i] = true; });
          else this.bulkVisibleIndexes().forEach(i => { delete bulk.selected[i]; });
          this.render();
        });
      }
      // نوار بالا و پایین هر کدام دکمه‌های خودشان را دارند؛ هر المان دقیقاً یک شنونده می‌گیرد (DOM در هر رندر نو می‌شود)
      this.root.querySelectorAll('.wila-bulk-start').forEach(btn => btn.addEventListener('click', () => this.startBulk()));
      this.root.querySelectorAll('.wila-bulk-pause').forEach(btn => btn.addEventListener('click', () => { bulk.paused = !bulk.paused; this.bulkLog('info', bulk.paused ? 'مکث پس از محصول جاری.' : 'ادامه پردازش.'); this.render(); }));
      this.root.querySelectorAll('.wila-bulk-stop').forEach(btn => btn.addEventListener('click', () => { bulk.stopRequested = true; this.bulkLog('info', 'درخواست توقف ثبت شد؛ پس از محصول جاری متوقف می‌شود.'); this.render(); }));
    },
  };

  window.KhazanQueue = {
    initialState: function () {
      return { source: 'site', site: { filter: 'all', category: 0, search: '', loading: false, loaded: false, error: '', hasMore: false, items: [], categories: [], counts: { total: 0, empty: 0, hasContent: 0 }, selected: {}, collapsed: false, addedCount: 0 }, fileName: '', rows: [], selected: {}, filter: 'all', mode: 'replace', injectSeo: true, autoLinks: true, createMissing: false, status: 'pending', matching: false, view: 'found', running: false, paused: false, stopRequested: false, total: 0, done: 0, startedAt: 0, log: [] };
    },
    methods: methods
  };
})();
