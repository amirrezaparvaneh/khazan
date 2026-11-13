/**
 * Khazan - bulk product-category queue: category list with content / SEO status, selection,
 * start/pause/stop, progress and live log. Shares the run state (running, paused, progress, log) with the product queue.
 * Mixed into the dashboard app by wila-admin.js (methods run with `this` === app).
 * Author: امیــــــــررضا پـروانه
 */
(function () {
  'use strict';

  const { toPersianDigits, formatJalali, shortJalali, escapeHtml } = window.KhazanUtils;

  const FILTERS = [
    ['all', 'همه دسته‌بندی‌ها', 'total', 'مناسب بازتولید و بالانس سراسری لینک‌ها'],
    ['empty', 'بدون توضیحات و خالی', 'empty', 'فقط دسته‌بندی‌های بدون متن'],
    ['has_content', 'دارای توضیحات قبلی', 'hasContent', 'دسته‌بندی‌های دارای متن جهت بازنویسی']
  ];

  const methods = {
    categoryLinkCaption: function () {
      const p = (this.state.aiSettings || {}).linkPlan;
      const n = p ? `${toPersianDigits(p.category_min)} تا ${toPersianDigits(p.category_max)} لینک داخلی` : 'چند لینک داخلی';
      return `${n}: دسته‌ی مادر، زیردسته‌ها، ۳ تا ۴ محصول همان دسته و یک مقاله‌ی مرتبط. تعداد طبق استانداردها و طول متن تعیین می‌شود.`;
    },

    // سوییچ «محصولات / دسته‌بندی‌های محصولات» بالای صفحه تولید انبوه
    bulkTargetSwitchHtml: function () {
      const b = this.state.bulk;
      return `<div id="wila-bulk-target">${this.radioSwitch('bulk_target', [['products', 'محصولات 🛍️', 'products'], ['categories', 'دسته‌بندی‌های محصولات 🗂️', 'product_cat']], b.target, 'wila-bulk-target-radio', 'data-target', b.running)}</div>`;
    },

    setBulkTarget: function (target) {
      const b = this.state.bulk, c = this.state.bulkCat;
      if (b.running || !['products', 'categories'].includes(target) || b.target === target) return;
      b.target = target;
      if (target === 'categories' && !c.loaded && !c.loading) this.loadSiteCategories();
      else if (target === 'products') this.enterBulkTab();
      this.render();
    },

    // فهرست دسته‌بندی‌های ووکامرس با وضعیت محتوا و کلیدواژه سئو
    loadSiteCategories: async function () {
      const c = this.state.bulkCat;
      c.loading = true; c.error = '';
      this.render();
      try {
        const r = await this.apiFetch(`/bulk/site-categories?filter=${encodeURIComponent(c.filter)}&search=${encodeURIComponent(c.search || '')}`);
        if (!r.success) throw new Error(r.message || 'فهرست دسته‌بندی‌ها دریافت نشد.');
        c.items = r.items || []; c.hasMore = !!r.has_more; c.loaded = true;
        c.counts = { total: Number(r.total_count || 0), empty: Number(r.empty_count || 0), hasContent: Number(r.has_content_count || 0) };
        c.selected = {}; c.run = {};
      } catch (e) { c.items = []; c.selected = {}; c.error = e.message; c.loaded = true; }
      c.loading = false;
      this.render();
    },

    setCategoryFilter: function (filter) {
      const c = this.state.bulkCat;
      if (this.state.bulk.running || c.loading || !['all', 'empty', 'has_content'].includes(filter) || c.filter === filter) return;
      c.filter = filter;
      this.loadSiteCategories();
    },

    categoryStatusHtml: function (item) {
      const r = this.state.bulkCat.run[item.id];
      if (!r) return '—';
      if (r.state === 'running') return '⏳ در حال تولید...';
      if (r.state === 'done') return `✅ انجام شد${r.editUrl ? ` — <a href="${escapeHtml(r.editUrl)}" target="_blank" rel="noopener">ویرایش</a>` : ''}`;
      return `❌ ${escapeHtml(r.error || 'خطا')}`;
    },

    categoryRefreshUi: function () {
      const c = this.state.bulkCat;
      this.bulkRefreshUi();
      this.root.querySelectorAll('[data-cat-status]').forEach(cell => {
        const item = c.items.find(i => String(i.id) === cell.getAttribute('data-cat-status'));
        if (item) cell.innerHTML = this.categoryStatusHtml(item);
      });
    },

    // single: فقط یک دسته (دکمه «تولید این ردیف»)؛ دسته‌های دارای محتوا هم دوباره تولید می‌شوند
    startCategoryBulk: async function (single) {
      const app = this;
      const b = this.state.bulk, c = this.state.bulkCat;
      if (b.running) return;
      const isSingle = Number.isInteger(single);
      const queue = (isSingle ? c.items.filter(i => i.id === single) : c.items.filter(i => c.selected[i.id]));
      if (!queue.length) return app.toast('هیچ دسته‌بندی‌ای برای پردازش انتخاب نشده است.', 'error');
      const overwrite = queue.filter(i => i.has_description).length;
      if (overwrite && !confirm(`توضیحات فعلی ${toPersianDigits(overwrite)} دسته‌بندی جایگزین می‌شود (نسخه قبلی نگه داشته می‌شود). ادامه می‌دهید؟`)) return;
      queue.forEach(i => { delete c.run[i.id]; });
      // شناسه اجرا و فهرست دسته‌های صف؛ سرور با آن‌ها لینک‌های ورودی را بین همه دسته‌ها به‌طور مساوی پخش می‌کند
      const runId = 'run-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
      const queueIds = queue.map(i => i.id);
      Object.assign(b, { running: true, paused: false, stopRequested: false, total: queue.length, done: 0, startedAt: Date.now() });
      this.bulkLog('info', `شروع پردازش ${toPersianDigits(queue.length)} دسته‌بندی.`);
      this.render();
      const sleep = ms => new Promise(r => setTimeout(r, ms));
      const wait = async ms => { for (let t = 0; t < ms && !b.stopRequested; t += 250) await sleep(250); };
      const isFatal = r => r && ['ai_no_credit', 'ai_invalid_key', 'ai_forbidden', 'missing_api_key'].includes(r.code);
      const isTemporary = r => r && (r.code === 'ai_rate_limited' || (r.data && [429, 503].includes(Number(r.data.status))));
      let cursor = 0;
      const workerCount = Math.max(1, Math.min(5, Number(c.workers) || 3));
      const worker = async () => {
      while (true) {
        const n = cursor++;
        if (n >= queue.length) break;
        const item = queue[n];
        while (b.paused && !b.stopRequested) await sleep(300);
        if (b.stopRequested) break;
        c.run[item.id] = { state: 'running' };
        this.bulkLog('info', `در حال تولید: ${item.title}`); this.categoryRefreshUi();
        for (let attempt = 0; attempt <= 2; attempt++) {
          try {
            const r = await this.apiFetch('/bulk/process-category', { method: 'POST', body: JSON.stringify({ term_id: item.id, options: { inject_seo: c.injectSeo, auto_links: c.autoLinks, run_id: runId, queue_ids: queueIds } }) });
            if (!r.success) { const err = new Error(r.message || 'تولید انجام نشد.'); err.temporary = isTemporary(r); err.fatal = isFatal(r); throw err; }
            c.run[item.id] = { state: 'done', editUrl: r.edit_url };
            Object.assign(item, { has_description: true, generated: r.generated, word_count: Number(r.word_count || item.word_count) });
            if (c.injectSeo) { item.keyword_status = 'ok'; item.seo = Object.assign({}, item.seo, { focuskw: item.title }); }
            this.bulkLog('ok', `انجام شد: ${item.title} (لینک داخلی: ${toPersianDigits(r.internal_link_count)})`);
            break;
          } catch (e) {
            const temporary = e.temporary || e instanceof TypeError; // TypeError: خطای شبکه در fetch
            if (temporary && attempt < 2 && !b.stopRequested) {
              const delay = 10000 * Math.pow(2, attempt);
              this.bulkLog('info', `خطای موقت (${e.message}) در ${item.title}؛ تلاش مجدد ${toPersianDigits(attempt + 1)} از ۲ پس از ${toPersianDigits(delay / 1000)} ثانیه.`);
              this.categoryRefreshUi();
              await wait(delay);
              continue;
            }
            c.run[item.id] = { state: 'error', error: e.message };
            this.bulkLog('err', `خطا در ${item.title}: ${c.run[item.id].error}`);
            if (e.fatal) { b.stopRequested = true; this.bulkLog('err', 'ادامه فایده‌ای ندارد؛ پردازش گروهی متوقف شد. پس از رفع مشکل بالا، موارد باقی‌مانده را دوباره اجرا کنید.'); }
            break;
          }
        }
        b.done++; this.categoryRefreshUi();
        if (cursor < queue.length && !b.stopRequested) await wait(workerCount > 1 ? 1000 : 3000);
      }
      };
      await Promise.all(Array.from({ length: workerCount }, (_, k) => sleep(k * 1500).then(worker)));
      this.bulkLog('info', b.stopRequested ? 'پردازش متوقف شد.' : 'پردازش صف به پایان رسید.');
      b.running = false; b.paused = false;
      this.render();
      app.toast(b.stopRequested ? 'پردازش متوقف شد.' : 'پردازش انبوه دسته‌بندی‌ها به پایان رسید.');
    },

    categoryKeywordBadge: function (item) {
      if (item.keyword_status === 'ok') return '<span class="khazan-badge khazan-badge-ok" title="کلیدواژه کانونی همان نام دسته است">🟢 کلیدواژه = نام دسته</span>';
      if (item.keyword_status === 'different') return `<span class="khazan-badge khazan-badge-miss" title="با تولید، کلیدواژه به نام دسته تغییر می‌کند">⚠️ ${escapeHtml((item.seo && item.seo.focuskw) || '')}</span>`;
      return '<span class="khazan-badge khazan-badge-warn">⚪ بدون کلیدواژه</span>';
    },

    viewBulkCategories: function () {
      const b = this.state.bulk, c = this.state.bulkCat;
      const locked = b.running ? 'disabled' : '';
      const selectedCount = c.items.filter(i => c.selected[i.id]).length;
      const allSelected = c.items.length > 0 && selectedCount === c.items.length;
      const queueControls = `<div class="khazan-queue-controls khazan-queue-controls-top">
          ${this.btn3d('wila-cat-start', 'btn-primary wila-cat-start', b.running ? 'در حال پردازش...' : 'شروع تولید محتوا', b.running || !selectedCount ? 'disabled' : '')}
          ${this.btn3d('wila-cat-pause', 'btn-warning wila-cat-pause', b.paused ? 'ادامه' : 'مکث موقت', !b.running || b.stopRequested ? 'disabled' : '')}
          ${this.btn3d('wila-cat-stop', 'btn-danger wila-cat-stop', b.stopRequested && b.running ? 'در حال توقف...' : 'توقف کامل', !b.running || b.stopRequested ? 'disabled' : '')}
        </div>`;
      const rows = c.items.map((item, idx) => {
        const regenerate = item.has_description || item.generated || (c.run[item.id] && c.run[item.id].state === 'done');
        const desc = item.has_description ? `<span class="khazan-badge khazan-badge-ok">📝 دارای توضیحات</span>` : '<span class="khazan-badge khazan-badge-warn">⚪ خالی</span>';
        const generated = item.generated ? ` <span class="khazan-badge khazan-badge-done" title="${escapeHtml('تولید شده در ' + formatJalali(item.generated.at))}">🕒 ${escapeHtml(shortJalali(item.generated.at))}</span>` : '';
        return `<tr>
          <td><input type="checkbox" class="khazan-checkbox wila-cat-row" data-id="${item.id}" ${c.selected[item.id] ? 'checked' : ''} ${b.running ? 'disabled' : ''}></td>
          <td>${toPersianDigits(idx + 1)}</td>
          <td style="font-weight:600">${item.url ? `<a href="${escapeHtml(item.url)}" target="_blank" rel="noopener">${escapeHtml(item.title)}</a>` : escapeHtml(item.title)}</td>
          <td>${toPersianDigits(item.product_count)}</td>
          <td>${desc}${generated}</td>
          <td>${toPersianDigits(item.word_count || 0)}</td>
          <td>${this.categoryKeywordBadge(item)}</td>
          <td data-cat-status="${item.id}">${this.categoryStatusHtml(item)}</td>
          <td><button class="wila-btn wila-btn-outline wila-btn-sm wila-cat-single" data-id="${item.id}" ${b.running ? 'disabled' : ''}>${regenerate ? '⚙️ تولید مجدد' : '▶ تولید این ردیف'}</button></td>
        </tr>`;
      }).join('');

      return `<div class="wila-card">
        <h3 style="margin-top:0">تولید انبوه محتوا</h3>
        ${this.bulkTargetSwitchHtml()}
        <p style="color:#64748b;font-size:.85rem">برای هر دسته‌بندی، توضیحات خریدمحور با کلیدواژه کانونی برابر نام دسته، عنوان و متای سئو (Yoast / Rank Math) و لینک‌سازی عنکبوتی تولید می‌شود: ۱ لینک به صفحه اصلی و ۲ تا ۳ لینک به دسته‌بندی‌های دیگر، به‌طوری که تعداد لینک ورودی همه دسته‌ها برابر بماند.</p>
        <div class="khazan-toolbar">
          <div class="khazan-field"><label>جستجوی نام دسته‌بندی</label>
            <input id="wila-cat-search" type="search" class="wila-input" value="${escapeHtml(c.search)}" placeholder="بخشی از نام دسته..." ${locked}></div>
          <div class="khazan-field khazan-field-actions">
            ${this.btn3d('wila-cat-load', 'btn-primary', c.loading ? 'در حال بارگذاری...' : 'بارگذاری دسته‌بندی‌ها', c.loading || b.running ? 'disabled' : '')}
          </div>
        </div>
        <div class="khazan-stat-cards" role="radiogroup" aria-label="وضعیت محتوای دسته‌بندی‌ها">
          ${FILTERS.map(([key, title, countKey, hint]) => `
            <button type="button" class="khazan-stat-card wila-cat-filter ${c.filter === key ? 'active' : ''} ${c.loading ? 'is-loading' : ''}" data-cat-filter="${key}" role="radio" aria-checked="${c.filter === key}" ${b.running ? 'disabled' : ''}>
              <span class="khazan-stat-check" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
              <span class="khazan-stat-title">${title}</span>
              <span class="khazan-stat-value">${c.loaded ? toPersianDigits(c.counts[countKey]) : '—'}</span>
              <span class="khazan-stat-hint">${hint}</span>
            </button>`).join('')}
        </div>
        ${c.loaded || c.error ? `<div class="khazan-statusbar"><span class="khazan-status-pill">${c.error ? '⚠️ ' + escapeHtml(c.error) : `🗂️ ${toPersianDigits(c.items.length)} دسته‌بندی بارگذاری شد${c.hasMore ? ' (سقف نمایش؛ جستجو را دقیق‌تر کنید)' : ''}`}</span></div>` : ''}
        ${c.loading ? '<div class="wila-skeleton" style="height:14px;margin-top:14px"></div><div class="wila-skeleton" style="height:14px;margin-top:8px"></div>' : ''}
        ${c.loaded && c.items.length ? `
          <div class="khazan-field" style="margin-bottom:10px"><label for="wila-cat-workers">سرعت پردازش (تعداد محصول هم‌زمان)</label>
              <select id="wila-cat-workers" class="wila-select" ${locked}>
                ${[[1, 'یکی‌یکی؛ کندترین و امن‌ترین'], [2, 'دو تا هم‌زمان'], [3, 'سه تا هم‌زمان؛ پیشنهادی'], [4, 'چهار تا هم‌زمان'], [5, 'پنج تا هم‌زمان؛ سریع‌ترین']].map(([n, t]) => `<option value="${n}" ${Number(c.workers || 3) === n ? 'selected' : ''}>${t}</option>`).join('')}
              </select><small class="khazan-hint">اگر خطای «سقف درخواست» یا کندی سرور دیدید، عدد را کم کنید.</small></div>
          <div class="khazan-toggle-grid" id="wila-queue-settings">
            <label class="khazan-toggle"><span class="khazan-switch"><input type="checkbox" id="wila-cat-seo" ${c.injectSeo ? 'checked' : ''} ${locked}><span class="khazan-slider"></span></span><span class="khazan-toggle-text"><strong>🔎 درج متای سئو</strong><small>ذخیره کلیدواژه، عنوان و توضیحات متا در ساختار دسته‌بندی Yoast و Rank Math</small></span></label>
            <label class="khazan-toggle"><span class="khazan-switch"><input type="checkbox" id="wila-cat-links" ${c.autoLinks ? 'checked' : ''} ${locked}><span class="khazan-slider"></span></span><span class="khazan-toggle-text"><strong>🕸️ لینک‌سازی عنکبوتی</strong><small>${this.categoryLinkCaption()}</small></span></label>
          </div>
          <div class="khazan-statusbar"><span class="khazan-status-pill">🗂️ ${toPersianDigits(selectedCount)} از ${toPersianDigits(c.items.length)} دسته‌بندی انتخاب شده (جایگزینی کامل توضیحات؛ نسخه قبلی نگه داشته می‌شود)</span>${this.costEstimateHtml(selectedCount, 'دسته‌بندی')}</div>
          ${queueControls}
          <div id="wila-bulk-progress" class="khazan-queue-progress">${this.bulkProgressHtml()}</div>
          <div class="wila-table-scroll"><table class="wila-table"><thead><tr>
            <th><input type="checkbox" class="khazan-checkbox" id="wila-cat-select-all" ${allSelected ? 'checked' : ''} ${b.running ? 'disabled' : ''}></th><th>#</th><th>نام دسته‌بندی</th><th>محصولات</th><th>وضعیت توضیحات</th><th>تعداد کلمات</th><th>کلیدواژه سئو</th><th>وضعیت پردازش</th><th>عملیات</th>
          </tr></thead><tbody>${rows}</tbody></table></div>
          <div id="wila-bulk-log" class="khazan-selectable" style="margin-top:10px;max-height:220px;overflow:auto;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:.8rem;line-height:1.9">${this.bulkLogHtml()}</div>
        ` : (c.loaded && !c.error ? '<p style="text-align:center;padding:20px;color:#78716c">دسته‌بندی‌ای برای نمایش وجود ندارد.</p>' : '')}
      </div>`;
    },

    bindCategoryEvents: function () {
      const b = this.state.bulk, c = this.state.bulkCat;
      this.root.querySelectorAll('.wila-bulk-target-radio').forEach(el => el.addEventListener('change', e => this.setBulkTarget(e.currentTarget.getAttribute('data-target'))));
      if (b.target !== 'categories') return;
      this.root.querySelectorAll('.wila-cat-filter').forEach(card => card.addEventListener('click', e => this.setCategoryFilter(e.currentTarget.getAttribute('data-cat-filter'))));
      const search = this.root.querySelector('#wila-cat-search');
      if (search) {
        search.addEventListener('input', e => { c.search = e.target.value; });
        search.addEventListener('keydown', e => { if (e.key === 'Enter' && !b.running) { e.preventDefault(); this.loadSiteCategories(); } });
      }
      const load = this.root.querySelector('#wila-cat-load'); if (load) load.addEventListener('click', () => this.loadSiteCategories());
      const wk = this.root.querySelector('#wila-cat-workers'); if (wk) wk.addEventListener('change', e => { c.workers = Number(e.target.value) || 3; });
      const seo = this.root.querySelector('#wila-cat-seo'); if (seo) seo.addEventListener('change', e => { c.injectSeo = e.target.checked; });
      const links = this.root.querySelector('#wila-cat-links'); if (links) links.addEventListener('change', e => { c.autoLinks = e.target.checked; });
      this.root.querySelectorAll('.wila-cat-row').forEach(box => box.addEventListener('change', e => { c.selected[e.target.dataset.id] = e.target.checked; this.render(); }));
      const all = this.root.querySelector('#wila-cat-select-all');
      if (all) {
        all.indeterminate = !all.checked && c.items.some(i => c.selected[i.id]);
        all.addEventListener('change', e => { c.selected = {}; if (e.target.checked) c.items.forEach(i => { c.selected[i.id] = true; }); this.render(); });
      }
      this.root.querySelectorAll('.wila-cat-single').forEach(btn => btn.addEventListener('click', () => this.startCategoryBulk(Number(btn.dataset.id))));
      const start = this.root.querySelector('.wila-cat-start'); if (start) start.addEventListener('click', () => this.startCategoryBulk());
      const pause = this.root.querySelector('.wila-cat-pause'); if (pause) pause.addEventListener('click', () => { b.paused = !b.paused; this.bulkLog('info', b.paused ? 'مکث پس از دسته جاری.' : 'ادامه پردازش.'); this.render(); });
      const stop = this.root.querySelector('.wila-cat-stop'); if (stop) stop.addEventListener('click', () => { b.stopRequested = true; this.bulkLog('info', 'درخواست توقف ثبت شد؛ پس از دسته جاری متوقف می‌شود.'); this.render(); });
    },
  };

  window.KhazanCategories = {
    initialState: function () {
      return { filter: 'all', search: '', loading: false, loaded: false, error: '', hasMore: false, items: [], counts: { total: 0, empty: 0, hasContent: 0 }, selected: {}, injectSeo: true, autoLinks: true, workers: 3, run: {} };
    },
    methods: methods
  };
})();
