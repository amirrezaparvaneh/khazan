/**
 * Khazan - tab navigation: sidebar/topbar shell, per-tab views and tab events.
 * Mixed into the dashboard app by wila-admin.js (methods run with `this` === app).
 * Author: امیــــــــررضا پـروانه
 */
(function () {
  'use strict';

  const { toPersianDigits, formatJalali, shortJalali, escapeHtml, formatNumber, formatUsd } = window.KhazanUtils;

  const KHAZAN_ICONS = {
    home: '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
    edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/>',
    sheet: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/>',
    compass: '<circle cx="12" cy="12" r="9"/><path d="M15.5 8.5l-2 5-5 2 2-5z"/>',
    wrench: '<path d="M14.7 6.3a4 4 0 005 5L21 13l-8 8a2.1 2.1 0 01-3-3l8-8z"/><path d="M14.7 6.3L12 3.6"/>',
    book: '<path d="M4 4.5A2.5 2.5 0 016.5 2H20v17H6.5A2.5 2.5 0 004 21.5z"/><path d="M4 21.5V4.5M8 7h8"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
    chart: '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
    target: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
    link: '<path d="M10 14a5 5 0 007 0l3-3a5 5 0 00-7-7l-1 1"/><path d="M14 10a5 5 0 00-7 0l-3 3a5 5 0 007 7l1-1"/>'
  };

  function icon(name, size) {
    const sz = size || 20;
    return `<svg class="khazan-icon" width="${sz}" height="${sz}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${KHAZAN_ICONS[name] || ''}</svg>`;
  }

  const KHAZAN_NAV = [
    { key: 'home', tab: 'home', icon: 'home', label: 'خانه', title: 'خانه' },
    { key: 'generator', tab: 'generator', icon: 'edit', label: 'تولید محتوای تکی', title: 'تولید محتوای تکی' },
    { key: 'bulk', tab: 'bulk', icon: 'sheet', label: 'تولید انبوه محتوا', title: 'تولید انبوه محتوا' },
    { key: 'orphans', tab: 'orphans', icon: 'compass', label: 'صفحات یتیم و لینک‌سازی', title: 'صفحات یتیم و لینک‌سازی' },
    { key: 'seofix', tab: 'seofix', icon: 'wrench', label: 'رفع خطای هوشمند سئو', title: 'رفع خطای هوشمند سئو' },
    { key: 'archive', tab: 'archive', icon: 'book', label: 'آرشیو محتوا', title: 'آرشیو محتوا' },
    { key: 'settings', tab: 'settings', icon: 'gear', label: 'تنظیمات هوش مصنوعی', title: 'تنظیمات هوش مصنوعی' }
  ];
  const KHAZAN_NAV_EXTRA = [
    { key: 'overview', tab: 'overview', icon: 'chart', label: 'پیشخوان آماری و اسکن', title: 'پیشخوان آماری و اسکن' },
    { key: 'planner', tab: 'planner', icon: 'target', label: 'برنامه‌ریز لینک', title: 'برنامه‌ریز لینک' },
    { key: 'items', tab: 'items', icon: 'link', label: 'کاوشگر پیوندها', title: 'کاوشگر پیوندها' }
  ];

  const methods = {
    viewNames: { home: 'viewHome', overview: 'viewOverview', generator: 'viewGenerator', archive: 'viewArchive', seofix: 'viewSeoFix', bulk: 'viewBulk', planner: 'viewPlanner', items: 'viewItems', orphans: 'viewOrphans', settings: 'viewSettings' },

    renderView: function () {
      const name = this.viewNames[this.state.activeTab];
      return name ? this[name]() : '';
    },

    shellHtml: function (contentHtml) {
      const { activeTab } = this.state;
      const activeNav = this.state.activeNav;
      const isNavActive = (n) => n.tab === activeTab && (n.key === 'archive' ? activeNav === 'archive' : activeNav !== 'archive');
      const navBtn = (n) => `<button type="button" class="wila-tab-btn khazan-nav-item ${isNavActive(n) ? 'active' : ''}" data-tab="${n.tab}" data-nav="${n.key}" ${n.scroll ? `data-scroll="${n.scroll}"` : ''}>${icon(n.icon, 20)}<span>${n.label}</span></button>`;
      const current = KHAZAN_NAV.concat(KHAZAN_NAV_EXTRA).find(isNavActive) || KHAZAN_NAV[0];
      const leavesOn = window.KhazanLeaves && window.KhazanLeaves.isEnabled();

      const navOpen = !!this.state.navOpen;
      return `
        <div class="khazan-backdrop ${navOpen ? 'open' : ''}" id="khazan-backdrop"></div>
        <aside class="khazan-sidebar ${navOpen ? 'open' : ''}" id="khazan-sidebar" aria-label="منوی خزان">
          <button type="button" class="khazan-drawer-close" id="khazan-drawer-close" aria-label="بستن منو">✕</button>
          <div class="khazan-brand">
            <img class="khazan-brand-logo" src="${escapeHtml(wilaData.logoUrl || '')}" alt="خـزان">
            <h1 class="khazan-sr-only">خـزان</h1>
            <span class="khazan-version">نسخه : ${toPersianDigits(wilaData.version || '')}</span>
          </div>
          <p class="khazan-tagline">دستیار هوشمند تولید محتوا و مهندسی سئو</p>
          <nav class="khazan-nav">
            ${KHAZAN_NAV.map(navBtn).join('')}
            <div class="khazan-nav-label">تحلیل لینک</div>
            ${KHAZAN_NAV_EXTRA.map(navBtn).join('')}
          </nav>
          <img class="khazan-watermark" src="${escapeHtml(wilaData.watermarkUrl || '')}" alt="" aria-hidden="true">
          <div class="khazan-signature">طراحی و توسعه: امیــــــــررضا پـروانه</div>
        </aside>
        <main class="khazan-main">
          <header class="khazan-topbar">
            <button type="button" class="khazan-menu-toggle" id="khazan-menu-toggle" aria-label="باز یا بسته کردن منو" aria-controls="khazan-sidebar" aria-expanded="${navOpen}"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
            <h2>${current.title}</h2>
            ${this.aiBalanceBadge('compact')}
            <button type="button" class="khazan-pill-btn khazan-leaf-toggle ${leavesOn ? 'on' : ''}" id="khazan-leaf-toggle">🍁 افکت خزان: ${leavesOn ? 'فعال' : 'غیرفعال'}</button>
          </header>
          <div class="khazan-panel">
        <div class="wila-content">${contentHtml}</div>
          </div>
        </main>
        `;
    },

    // باز/بسته کردن کشوی منو بدون رندر مجدد (تا انیمیشن CSS اجرا شود)؛ state برای رندرهای بعدی حفظ می‌شود
    setNavOpen: function (open) {
      const isOpen = !!open;
      this.state.navOpen = isOpen;
      const sidebar = this.root.querySelector('#khazan-sidebar');
      if (sidebar) sidebar.classList.toggle('open', isOpen);
      const backdrop = this.root.querySelector('#khazan-backdrop');
      if (backdrop) backdrop.classList.toggle('open', isOpen);
      const toggle = this.root.querySelector('#khazan-menu-toggle');
      if (toggle) toggle.setAttribute('aria-expanded', String(isOpen));
    },

    // بج زنده «اعتبار حساب API» با دکمه استعلام مجدد؛ variant: compact (نوار بالا) | full (تنظیمات)
    aiBalanceBadge: function (variant) {
      const st = this.state, data = st.aiBalance && st.aiBalance.balance ? st.aiBalance : null;
      const refresh = `<button type="button" class="khazan-balance-refresh" aria-label="استعلام مجدد اعتبار" title="استعلام مجدد اعتبار" ${st.aiBalanceLoading ? 'disabled' : ''}><svg class="khazan-spin ${st.aiBalanceLoading ? 'spinning' : ''}" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 11-3-6.7"/><path d="M21 3v6h-6"/></svg></button>`;
      let tone = 'ok', body = '', title = '';
      if (data) {
        const b = data.balance, status = data.status || 'ok';
        tone = status;
        const toman = b.remaining_irt != null ? `${formatNumber(b.remaining_irt)} تومان` : '';
        const usd = b.remaining_unit != null ? formatUsd(b.remaining_unit) : '';
        const warn = status === 'empty' ? 'اعتبار تمام شده است' : status === 'critical' ? 'اعتبار رو به اتمام است' : status === 'low' ? 'اعتبار کم است' : '';
        title = [b.total_irt != null ? `کل: ${formatNumber(b.total_irt)} تومان` : '', b.used_irt != null ? `مصرف‌شده: ${formatNumber(b.used_irt)} تومان` : '', toman ? `باقی‌مانده: ${toman}` : ''].filter(Boolean).join(' | ');
        body = `<span class="khazan-balance-label">💳 اعتبار API</span><strong class="khazan-balance-value">${toman || usd}</strong>${toman && usd ? `<span class="khazan-balance-usd">≈ ${usd}</span>` : ''}${warn ? `<span class="khazan-balance-warn">⚠️ ${warn}</span>` : ''}`;
        if (variant === 'full') {
          const row = (label, irt, unit) => irt != null ? `<div class="khazan-balance-cell"><small>${label}</small><strong>${formatNumber(irt)} تومان</strong>${unit != null ? `<span>≈ ${formatUsd(unit)}</span>` : ''}</div>` : '';
          body += `<div class="khazan-balance-grid">${row('اعتبار کل', b.total_irt, b.total_unit)}${row('مصرف‌شده', b.used_irt, b.used_unit)}${row('باقی‌مانده', b.remaining_irt, b.remaining_unit)}</div>`;
        }
      } else if (st.aiBalanceLoading) {
        tone = 'loading'; body = '<span class="khazan-balance-label">⏳ در حال استعلام اعتبار...</span>';
      } else if (st.aiBalanceErrorCode === 'missing_api_key') {
        tone = 'error'; body = '<span class="khazan-balance-label">🔑 کلید API ثبت نشده</span>'; title = st.aiBalanceError;
      } else if (st.aiBalanceErrorCode === 'invalid_api_key') {
        tone = 'error'; body = '<span class="khazan-balance-label">⛔ کلید API نامعتبر است</span>'; title = st.aiBalanceError;
      } else if (st.aiBalanceError) {
        tone = 'error'; body = '<span class="khazan-balance-label">⚠️ اعتبار نامشخص</span>'; title = st.aiBalanceError;
      } else {
        tone = 'loading'; body = '<span class="khazan-balance-label">💳 اعتبار API</span><strong class="khazan-balance-value">—</strong>';
      }
      return `<div class="khazan-balance is-${tone} khazan-balance-${variant || 'compact'}" role="status" aria-live="polite" ${title ? `title="${escapeHtml(title)}"` : ''}><div class="khazan-balance-main">${body}</div>${refresh}</div>`;
    },

    // برآورد تقریبی هزینه تولید برای تعداد ردیف انتخاب‌شده (نرخ‌های Core\Settings؛ فقط تخمین است)
    costEstimateHtml: function (rows) {
      const pr = this.state.aiPricing;
      if (!pr || rows == null) return '';
      const unit = pr.per_row_unit * rows;
      const bal = this.state.aiBalance && this.state.aiBalance.balance;
      const rate = bal && bal.exchange_rate;
      const insufficient = bal && bal.remaining_unit != null && unit > bal.remaining_unit;
      const amount = rate ? `${formatNumber(unit * rate)} تومان (≈ ${formatUsd(unit)})` : formatUsd(unit);
      const tip = `برآورد بر پایه حدود ${formatNumber(pr.input_tokens)} توکن ورودی و ${formatNumber(pr.output_tokens)} توکن خروجی برای هر محصول؛ هزینه واقعی ممکن است فرق کند.`;
      return `<span class="khazan-status-pill khazan-cost-estimate ${insufficient ? 'is-warn' : ''}" title="${escapeHtml(tip)}">💰 هزینه تخمینی این پردازش: ~${amount}${insufficient ? ' — اعتبار فعلی کافی نیست' : ''}</span>`;
    },

    viewHome: function () {
      const { activeTab, summary, isScanning, scanProgress, scanTotal, scanCurrent, modal, aiSettings, plannerForm, plannerResult, isPlanning, isGeneratingContent } = this.state;
      let contentHtml = '';
        const histCount = this.state.historyLoading ? '…' : toPersianDigits(this.state.contentHistory.length);
        const cards = [
          { go: 'generator', icon: 'edit', title: 'تولید محتوای تکی', desc: 'تولید مقاله، توضیحات محصول و دسته‌بندی با رعایت قوانین سئو و لینک‌سازی هوشمند.', btn: 'باز کردن ماژول' },
          { go: 'bulk', icon: 'sheet', title: 'تولید انبوه محتوا', desc: 'انتخاب محصولات سایت یا بارگذاری فایل شیت، تطبیق خودکار و تولید صف‌بندی‌شده محتوا.', btn: 'باز کردن ماژول' },
          { go: 'orphans', icon: 'compass', title: 'صفحات یتیم و لینک‌سازی', desc: 'شناسایی صفحاتی که هیچ لینک ورودی ندارند و ساخت لینک داخلی برای آن‌ها.', btn: 'باز کردن ماژول' },
          { go: 'seofix', icon: 'wrench', title: 'رفع خطای هوشمند سئو', desc: 'بررسی خطاهای خوانایی و سئو و اصلاح انتخابی متن با هوش مصنوعی.', btn: 'باز کردن ماژول' },
          { go: 'settings', icon: 'gear', title: 'تنظیمات API', desc: 'ثبت کلید AvalAI، تست اتصال و مدیریت تنظیمات هوش مصنوعی.', btn: 'تنظیمات' },
          { go: 'archive', icon: 'book', title: 'آمار محتواهای تولیدشده', desc: 'تعداد کل محتواهای ذخیره‌شده در آرشیو: <strong class="khazan-stat">' + histCount + '</strong> محتوا', btn: 'مشاهده آرشیو' }
        ];
        contentHtml = `<div class="khazan-home-grid">${cards.map(c => `
          <div class="khazan-card">
            <div class="khazan-card-icon">${icon(c.icon, 34)}</div>
            <div class="khazan-card-body">
              <h3>${c.title}</h3>
              <p>${c.desc}</p>
              <div class="khazan-card-actions"><button type="button" class="khazan-pill-btn khazan-go" data-go="${c.go}">${c.btn}</button></div>
            </div>
          </div>`).join('')}</div>${this.viewDashboard()}`;
      return contentHtml;
    },

    // «وضعیت و سلامت محتوایی سایت»: KPI، حلقه‌های پوشش محتوا و نوار تفکیک دسته‌بندی‌ها (SVG/CSS خالص)
    viewDashboard: function () {
      const raw = this.state.siteStats;
      // پاسخ ناقص سرور نباید داشبورد را بشکند
      const valid = raw && raw.products && raw.articles && raw.categories && raw.coverage && raw.khazan;
      const st = valid ? raw : null;
      const fa = toPersianDigits;
      const header = `<div class="khazan-health-head"><h3>وضعیت و سلامت محتوایی سایت</h3><button type="button" class="khazan-pill-btn khazan-stats-refresh" ${this.state.siteStatsLoading ? 'disabled' : ''}>${this.state.siteStatsLoading ? 'در حال به‌روزرسانی...' : '↻ به‌روزرسانی آمار'}</button></div>`;
      if (!st) {
        if (this.state.siteStatsError || raw) return `<section class="khazan-health" id="khazan-health">${header}<div class="khazan-health-error">⚠️ ${escapeHtml(this.state.siteStatsError || 'پاسخ آمار سایت نامعتبر است.')}</div></section>`;
        return `<section class="khazan-health" id="khazan-health">${header}<div class="khazan-kpi-grid">${'<div class="khazan-kpi"><div class="wila-skeleton" style="height:12px;width:50%"></div><div class="wila-skeleton" style="height:28px;width:40%;margin-top:12px"></div></div>'.repeat(5)}</div><div class="khazan-health-grid">${'<div class="khazan-health-panel"><div class="wila-skeleton" style="height:120px"></div></div>'.repeat(3)}</div></section>`;
      }
      const CIRC = 2 * Math.PI * 52;
      const ring = (pct, label, sub, tone) => `<div class="khazan-ring-wrap khazan-ring-${tone}">
          <svg class="khazan-ring" viewBox="0 0 120 120" role="img" aria-label="${escapeHtml(label)}: ${fa(pct)}٪">
            <circle class="khazan-ring-bg" cx="60" cy="60" r="52"></circle>
            <circle class="khazan-ring-fg" cx="60" cy="60" r="52" style="--ring-total:${CIRC.toFixed(2)};--ring-target:${(CIRC * (1 - pct / 100)).toFixed(2)}"></circle>
          </svg>
          <div class="khazan-ring-label"><strong>${fa(pct)}٪</strong><small>${escapeHtml(sub)}</small></div>
        </div>`;
      const bar = (title, has, empty, hasLabel, emptyLabel) => {
        const total = has + empty, hp = total ? (has / total) * 100 : 0;
        return `<div class="khazan-split">
          <div class="khazan-split-head"><strong>${title}</strong><span>${fa(total)} مورد</span></div>
          <div class="khazan-split-bar" role="img" aria-label="${escapeHtml(title)}: ${fa(has)} ${hasLabel}، ${fa(empty)} ${emptyLabel}"><span class="khazan-split-has" style="width:${hp.toFixed(2)}%"></span><span class="khazan-split-empty" style="width:${(total ? 100 - hp : 0).toFixed(2)}%"></span></div>
          <div class="khazan-split-legend"><span><i class="khazan-dot khazan-dot-has"></i>${hasLabel}: ${fa(has)}</span><span><i class="khazan-dot khazan-dot-empty"></i>${emptyLabel}: ${fa(empty)}</span></div>
        </div>`;
      };
      const p = st.products, a = st.articles, c = st.categories;
      const kpis = [
        ['📰', 'مقالات', a.total], ['🛍️', 'محصولات', p.total], ['🗂️', 'دسته‌بندی‌ها', c.total],
        ['🔗', 'لینک‌های داخلی فعال', st.khazan.internal_links], ['🍁', 'محتواهای تولیدشده با خزان', st.khazan.generated_contents]
      ];
      return `<section class="khazan-health" id="khazan-health">${header}
        <div class="khazan-kpi-grid">${kpis.map(([ico, title, value]) => `<div class="khazan-kpi"><span class="khazan-kpi-icon" aria-hidden="true">${ico}</span><span class="khazan-kpi-title">${title}</span><span class="khazan-kpi-value">${fa(value)}</span></div>`).join('')}</div>
        <div class="khazan-health-grid">
          <div class="khazan-health-panel">
            <h4>پوشش محتوای محصولات</h4>
            ${ring(p.coverage_percent, 'پوشش محتوای محصولات', 'دارای توضیحات', 'products')}
            <p class="khazan-health-note"><strong>${fa(p.has_content)}</strong> محصول دارای توضیحات از <strong>${fa(p.total)}</strong>؛ <strong>${fa(p.empty_content)}</strong> محصول نیازمند محتوا</p>
            <button type="button" class="khazan-pill-btn khazan-quick-empty" ${p.empty_content > 0 && !this.state.bulk.running ? '' : 'disabled'}>⚡ تولید محتوا برای ${fa(p.empty_content)} محصول بدون توضیحات</button>
          </div>
          <div class="khazan-health-panel">
            <h4>شاخص پوشش محتوایی کل سایت</h4>
            ${ring(st.coverage.score, 'شاخص پوشش محتوایی', 'کل سایت', 'site')}
            <p class="khazan-health-note"><strong>${fa(st.coverage.covered)}</strong> صفحه دارای محتوا از <strong>${fa(st.coverage.total)}</strong> صفحه (محصولات، مقالات و دسته‌بندی‌ها)</p>
          </div>
          <div class="khazan-health-panel khazan-health-wide">
            <h4>تفکیک وضعیت محتوا</h4>
            ${bar('دسته‌بندی‌ها (توضیحات سئو)', c.with_description, c.empty, 'دارای توضیحات', 'بدون توضیحات')}
            ${bar('محصولات', p.has_content, p.empty_content, 'دارای توضیحات', 'خالی')}
            ${bar('مقالات و نوشته‌ها', a.has_content, a.empty_content, 'دارای متن', 'بدون محتوا')}
          </div>
        </div>
      </section>`;
    },

    viewOverview: function () {
      const { activeTab, summary, isScanning, scanProgress, scanTotal, scanCurrent, modal, aiSettings, plannerForm, plannerResult, isPlanning, isGeneratingContent } = this.state;
      let contentHtml = '';
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
      return contentHtml;
    },

    viewGenerator: function () {
      const { activeTab, summary, isScanning, scanProgress, scanTotal, scanCurrent, modal, aiSettings, plannerForm, plannerResult, isPlanning, isGeneratingContent } = this.state;
      let contentHtml = '';
        const f = this.state.generatorForm;
        const esc = escapeHtml;
        const isArticle = f.contentKind === 'article';
        const kindLabel = this.kindLabel(f.contentKind);
        const seoLen = [...(this.state.generatedSeoTitle || '')].length;
        const field = (key, label, type = 'text') => `<label style="display:block;margin:10px 0 4px;font-weight:600">${label}</label><input data-generator-field="${key}" type="${type}" class="wila-input" style="width:100%" value="${esc(f[key])}">`;
        contentHtml = `<div class="wila-card" style="max-width:900px"><h3>تولیدکننده محتوای هوشمند سئو</h3><p>طرح را تایید کنید، سپس محتوای HTML تولید و به پیش‌نویس وردپرس منتقل کنید.</p>
          <label style="display:block;margin:10px 0 4px;font-weight:600">نوع محتوا</label>${this.radioSwitch('content_kind', [['article', 'مقاله 📝'], ['product', 'محصول 🛍️'], ['product_cat', 'دسته‌بندی محصول 🗂️']], f.contentKind, 'wila-kind-btn', 'data-kind')}
          ${isArticle ? field('h1Title','عنوان اصلی / H1') : `<label style="display:block;margin:14px 0 4px;font-weight:600">انتخاب ${kindLabel} از فهرست</label><input id="wila-target-search" type="search" class="wila-input" style="width:100%;margin-bottom:6px" placeholder="جستجو در ${kindLabel}ها..."><select id="wila-target-select" class="wila-select" size="8" style="width:100%;height:auto"></select><p style="margin:5px 0;color:#64748b;font-size:.8rem">${f.targetId ? `انتخاب‌شده: <strong>${esc(f.h1Title)}</strong>` : `یک ${kindLabel} را انتخاب کنید؛ اطلاعات واقعی آن به هوش مصنوعی داده می‌شود.`}</p>`}${field('focusKeyword','کلیدواژه کانونی')}${field('longTail','کلیدواژه‌های لانگ‌تیل')}${field('wordCount','تعداد کلمات','number')}
          <label style="display:block;margin:10px 0 4px;font-weight:600">توضیحات و الزامات اختصاصی محتوا</label><textarea data-generator-field="description" class="wila-input" rows="5" style="width:100%" placeholder="نکات، منابع، محصولات یا بخش‌هایی که باید حتماً در محتوا پوشش داده شوند...">${esc(f.description)}</textarea>
          ${this.state.generatedContent ? `<div style="margin-top:20px"><div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px"><span class="wila-badge wila-badge-success">لینک داخلی: ${toPersianDigits(this.state.generatedInternalLinkCount)}</span><span class="wila-badge wila-badge-neutral">لینک خارجی: ${toPersianDigits(this.state.generatedExternalLinkCount)}</span></div>${isArticle ? `<label style="display:block;margin:0 0 6px;font-weight:600">اسلاگ انگلیسی و سئو</label><input id="wila-generated-slug" class="wila-input" style="width:100%;direction:ltr;text-align:left" value="${esc(this.state.generatedSlug)}">` : ''}<label style="display:block;margin:16px 0 6px;font-weight:600">عنوان سئو (Yoast) <span id="wila-seo-title-len" style="font-weight:400;font-size:.8rem;color:${seoLen >= 50 && seoLen <= 60 ? '#16a34a' : '#d97706'}">${toPersianDigits(seoLen)} کاراکتر (ایده‌آل ۵۰ تا ۶۰)</span></label><input id="wila-seo-title" class="wila-input" style="width:100%" value="${esc(this.state.generatedSeoTitle)}"><label style="display:block;margin:16px 0 6px;font-weight:600">توضیحات متا (Yoast)</label><textarea id="wila-meta-description" class="wila-input" rows="3" style="width:100%">${esc(this.state.generatedMetaDescription)}</textarea><label style="display:block;margin:16px 0 6px;font-weight:600">مترادف‌ها و عبارات کلیدی (Yoast)</label><input id="wila-keyword-synonyms" class="wila-input" style="width:100%" value="${esc(this.state.generatedKeywordSynonyms)}"><label style="display:block;margin:16px 0 6px;font-weight:600">محتوای نهایی HTML</label><textarea id="wila-generated-content" class="wila-input" rows="18" style="width:100%;direction:rtl">${esc(this.state.generatedContent)}</textarea>${this.state.generatedExternalLinks.length ? `<p style="margin:12px 0 4px;font-weight:600">منابع خارجی پیشنهادی</p><ul>${this.state.generatedExternalLinks.map(link => `<li><a href="${esc(link.url)}" target="_blank" rel="noopener">${esc(link.anchor || link.url)}</a></li>`).join('')}</ul>` : ''}${this.state.featuredImagePrompt ? `<label style="display:block;margin:16px 0 6px;font-weight:600">پرامپت تصویر شاخص</label><textarea id="wila-featured-image-prompt" class="wila-input" rows="5" style="width:100%;direction:ltr;text-align:left">${esc(this.state.featuredImagePrompt)}</textarea>` : ''}<div style="margin-top:10px"><button id="wila-copy-content" class="wila-btn wila-btn-outline">کپی تمام محتوا</button> ${isArticle ? '<button id="wila-create-draft" class="wila-btn wila-btn-primary">ایجاد پیش‌نویس در وبلاگ</button>' : `<button id="wila-apply-target" class="wila-btn wila-btn-primary" ${this.state.isApplying ? 'disabled' : ''}>${this.state.isApplying ? 'در حال انتشار...' : `انتشار در صفحه ${kindLabel}`}</button>`}</div></div>` : ''}
          <label style="display:block;margin:10px 0 4px;font-weight:600">فاز قیف</label><select data-generator-field="funnelStage" class="wila-select" style="width:100%">${['TOFU (آگاهی)','MOFU (بررسی)','BOFU (خرید/تبدیل)','تشخیص خودکار'].map(x=>`<option ${f.funnelStage===x?'selected':''}>${x}</option>`).join('')}</select>
          <label style="display:block;margin:10px 0 4px;font-weight:600">نقشه لینک داخلی</label><textarea data-generator-field="internalLinks" class="wila-input" rows="4" style="width:100%" placeholder="انکرتکست: https://example.com/page\nیا با ویرگول جدا کنید">${esc(f.internalLinks)}</textarea>
          <label class="khazan-toggle" style="margin-top:10px"><span class="khazan-switch"><input type="checkbox" id="wila-use-orphan-links" ${f.useOrphanLinks ? 'checked' : ''}><span class="khazan-slider"></span></span><span class="khazan-toggle-text"><strong>اگر نقشه لینک خالی است، صفحات یتیم مرتبط را خودکار و استراتژیک لینک‌سازی کن</strong></span></label><p style="margin:5px 0;color:#64748b;font-size:.8rem">افزونه فقط صفحه‌های یتیم مرتبط را انتخاب می‌کند و انکرتکست را بر اساس مقصد می‌سازد.</p>
          <div style="margin-top:16px">${this.btn3d('wila-generate-outline', 'btn-primary', isGeneratingContent ? 'در حال تولید...' : '۱. تولید طرح ساختاری', isGeneratingContent ? 'disabled' : '')}</div>
          ${this.state.contentOutline ? `<div style="margin-top:20px"><h4>طرح تاییدشده</h4><p><strong>نیت جستجو:</strong> ${esc(this.state.contentOutline.search_intent)}</p><ul>${(this.state.contentOutline.headings||[]).map(h=>`<li><strong>${esc(h.level)}:</strong> ${esc(h.text)} — ${esc(h.purpose)}</li>`).join('')}</ul>${this.btn3d('wila-generate-full', 'btn-primary', '۲. تایید طرح و نگارش کامل', isGeneratingContent ? 'disabled' : '')}</div>`:''}
          ${this.state.contentOutline ? `<details><summary>پیشنهاد سئو طرح</summary><p>عنوان: ${esc(this.state.contentOutline.seo_title)}</p><p>متا: ${esc(this.state.contentOutline.meta_description)}</p><p>مترادف‌ها: ${esc(this.state.contentOutline.keyword_synonyms)}</p>${isArticle ? `<p>نامک: ${esc(this.state.contentOutline.slug)}</p>` : ''}<p>انتیتی‌ها: ${esc((Array.isArray(this.state.contentOutline.entities) ? this.state.contentOutline.entities : []).join('، '))}</p><p>شواهد لازم: ${esc((Array.isArray(this.state.contentOutline.evidence_needed) ? this.state.contentOutline.evidence_needed : []).join('، '))}</p></details>` : ''}
          ${this.state.generatedContent ? `<div id="wila-quality-report">${this.contentQualityReport(this.state.generatedContent, f.focusKeyword)}</div><p>${isArticle ? 'اطلاعات سئو همراه پیش‌نویس ذخیره می‌شوند و هنگام انتشار در ویرایشگر باقی می‌مانند. نامک Yoast همان نامک وردپرس است.' : `با «انتشار»، محتوا و اطلاعات سئو مستقیم در صفحه ${kindLabel} جایگذاری می‌شود و به ویرایشگر همان صفحه منتقل می‌شوید.`} نمایش مترادف‌ها به نسخه Premium یوست وابسته است.</p>` : ''}
        </div>`;
      return contentHtml;
    },

    viewArchive: function () {
      const esc = escapeHtml;
      return `<div class="wila-card">
          <div id="wila-archive"><h3 style="margin-top:0">آرشیو محتوای تولیدشده</h3><p style="color:#64748b;font-size:.85rem">۱۰۰ محتوای آخر با تاریخ شمسی نگهداری می‌شود. «مشاهده و کپی» همه اطلاعات را نشان می‌دهد و «بازیابی» محتوا را برای ویرایش یا انتشار به بخش تولید محتوا برمی‌گرداند.</p><div class="wila-table-scroll"><table class="wila-table"><thead><tr><th>عنوان</th><th>نوع</th><th>کلیدواژه</th><th>عنوان سئو</th><th>لینک‌ها</th><th>تاریخ</th><th>عملیات</th></tr></thead><tbody>${this.state.historyLoading ? [1, 2, 3].map(() => '<tr>' + '<td><div class="wila-skeleton" style="height:14px"></div></td>'.repeat(7) + '</tr>').join('') : this.state.contentHistory.length ? this.state.contentHistory.map(item => `<tr><td style="font-weight:600">${esc(item.title)}</td><td><span class="wila-badge wila-badge-neutral">${this.kindLabel(item.content_kind)}</span></td><td>${esc(item.focus_keyword)}</td><td style="max-width:260px">${esc(item.seo_title || '')}</td><td style="white-space:nowrap">${toPersianDigits(item.internal_link_count)} داخلی / ${toPersianDigits(item.external_link_count)} خارجی</td><td style="white-space:nowrap">${formatJalali(item.created_at)}</td><td style="white-space:nowrap"><button class="wila-btn wila-btn-outline wila-btn-sm wila-history-view" data-id="${item.id}">مشاهده و کپی</button> <button class="wila-btn wila-btn-outline wila-btn-sm wila-history-restore" data-id="${item.id}">بازیابی</button> <button class="wila-btn wila-btn-outline wila-btn-sm wila-history-delete" data-id="${item.id}" style="color:#dc2626">حذف</button></td></tr>`).join('') : '<tr><td colspan="7" style="text-align:center;padding:20px;color:#64748b">هنوز محتوایی تولید نشده است.</td></tr>'}</tbody></table></div></div>
        </div>`;
    },

    viewSeoFix: function () {
      const { activeTab, summary, isScanning, scanProgress, scanTotal, scanCurrent, modal, aiSettings, plannerForm, plannerResult, isPlanning, isGeneratingContent } = this.state;
      let contentHtml = '';
        const sf = this.state.seoFix;
        const cur = sf.current, res = sf.result;
        const busy = sf.running || sf.applying;
        const kindLabel = this.kindLabel(sf.kind);
        contentHtml = `<div class="wila-card" style="max-width:900px">
          <h3 style="margin-top:0">🛠 رفع خطای هوشمند سئو با هوش مصنوعی</h3>
          <p style="color:#64748b;font-size:.85rem">برای محتوایی که از قبل روی صفحه است، خطاهای Yoast یا RankMath را اینجا بچسبانید. هوش مصنوعی فقط همان موارد را روی متن فعلی اصلاح می‌کند و بقیه متن دست‌نخورده می‌ماند. پیش از جایگزینی، نتیجه را بررسی کنید.</p>
          <label style="display:block;margin:10px 0 4px;font-weight:600">نوع صفحه</label>
          ${this.radioSwitch('seofix_kind', [['product', 'محصول 🛍️'], ['product_cat', 'دسته‌بندی محصول 🗂️']], sf.kind, 'wila-seofix-kind', 'data-kind', busy)}
          <label style="display:block;margin:14px 0 4px;font-weight:600">انتخاب ${kindLabel}</label>
          <input id="wila-seofix-search" type="search" class="wila-input" style="width:100%;margin-bottom:6px" placeholder="جستجو در ${kindLabel}ها..." value="${escapeHtml(sf.search || '')}">
          <select id="wila-seofix-select" class="wila-select" size="6" style="width:100%;height:auto" ${busy ? 'disabled' : ''}></select>
          ${sf.currentLoading ? '<div class="wila-skeleton" style="height:14px;margin-top:14px"></div><div class="wila-skeleton" style="height:14px;margin-top:8px"></div>' : cur ? `
            <div style="margin-top:16px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:.85rem;line-height:1.9">
              <div><strong>صفحه:</strong> <a href="${escapeHtml(cur.url)}" target="_blank" rel="noopener">${escapeHtml(cur.title)}</a></div>
              <div><strong>عنوان سئو:</strong> ${escapeHtml(cur.seo.title || '—')}</div>
              <div><strong>متا:</strong> ${escapeHtml(cur.seo.desc || '—')}</div>
              <div><strong>کلیدواژه کانونی:</strong> ${escapeHtml(cur.seo.focuskw || '—')}${cur.seo.synonyms ? ` — <strong>مترادف‌ها:</strong> ${escapeHtml(cur.seo.synonyms)}` : ''}</div>
            </div>
            <details style="margin-top:10px"><summary>نمایش متن فعلی</summary><textarea readonly class="wila-input" rows="10" style="width:100%;direction:ltr;text-align:left;margin-top:8px">${escapeHtml(cur.content)}</textarea></details>` : ''}
          ${cur ? `<label style="display:block;margin:16px 0 4px;font-weight:600">خطاهای گزارش‌شده توسط افزونه سئو</label>
          <textarea id="wila-seofix-errors" class="wila-input" rows="5" style="width:100%" placeholder="مثلاً: کلیدواژه در مقدمه نیست / متای طولانی است / تعداد لینک خارجی کم است" ${busy ? 'disabled' : ''}>${escapeHtml(sf.errors)}</textarea>
          <button id="wila-seofix-run" class="wila-btn wila-btn-primary" style="margin-top:12px" ${!sf.errors.trim() || busy ? 'disabled' : ''}>${sf.running ? 'در حال رفع خطا...' : 'رفع خطاهای سئو'}</button>` : ''}
          ${res ? `<div style="margin-top:20px">
            <div style="margin-bottom:10px"><span class="wila-badge ${res.changed ? 'wila-badge-success' : 'wila-badge-neutral'}">${res.changed ? 'تغییرات آماده بررسی است' : 'تغییری پیشنهاد نشد'}</span></div>
            ${res.notes ? `<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;font-size:.85rem;white-space:pre-line;margin-bottom:12px">${escapeHtml(res.notes)}</div>` : ''}
            <label style="display:block;margin:10px 0 4px;font-weight:600">عنوان سئو پیشنهادی (خالی یعنی بدون تغییر)</label>
            <input id="wila-seofix-title" class="wila-input" style="width:100%" value="${escapeHtml(res.seo_title || '')}">
            <label style="display:block;margin:10px 0 4px;font-weight:600">متای پیشنهادی (خالی یعنی بدون تغییر)</label>
            <textarea id="wila-seofix-meta" class="wila-input" rows="2" style="width:100%">${escapeHtml(res.meta_description || '')}</textarea>
            <label style="display:block;margin:10px 0 4px;font-weight:600">متن اصلاح‌شده (HTML)</label>
            <textarea id="wila-seofix-result" class="wila-input" rows="16" style="width:100%;direction:rtl">${escapeHtml(res.content)}</textarea>
            <button id="wila-seofix-apply" class="wila-btn wila-btn-primary" style="margin-top:12px" ${busy ? 'disabled' : ''}>${sf.applying ? 'در حال جایگزینی...' : 'جایگزینی متن در صفحه'}</button>
          </div>` : ''}
        </div>`;
      return contentHtml;
    },

    viewBulk: function () {
      const { activeTab, summary, isScanning, scanProgress, scanTotal, scanCurrent, modal, aiSettings, plannerForm, plannerResult, isPlanning, isGeneratingContent } = this.state;
      let contentHtml = '';
        const b = this.state.bulk;
        const visible = this.bulkVisibleIndexes();
        // فقط ردیف‌های منبع فعلی؛ در حالت محصولات سایت همه ردیف‌ها متصل‌اند و بخش «انتخاب» و «صف» هرگز هم‌زمان نمایش داده نمی‌شوند
        const srcIdx = this.bulkSourceIndexes();
        const matchedCount = srcIdx.filter(i => b.rows[i].match && b.rows[i].match.matched).length;
        const selectedCount = srcIdx.filter(i => b.selected[i]).length;
        const siteMode = b.source === 'site';
        const queueVisible = srcIdx.length > 0 && !b.matching && (!siteMode || b.site.collapsed);
        const viewFound = siteMode || b.view === 'found';
        const selectable = this.bulkSelectableIndexes();
        const allVisibleSelected = selectable.length > 0 && selectable.every(i => b.selected[i]);
        const locked = b.running ? 'disabled' : '';
        const siteSelected = b.site.items.filter(p => b.site.selected[p.id]).length;
        const siteExpanded = b.source === 'site' && !b.site.collapsed;
        // کنترل‌های صف (شروع/مکث/توقف) فقط بالای جدول صف و کنار نوار پیشرفت قرار دارند
        const queueControls = () => `<div class="khazan-queue-controls khazan-queue-controls-top">
            ${this.btn3d('wila-bulk-start', 'btn-primary wila-bulk-start', b.running ? 'در حال پردازش...' : 'شروع تولید محتوا', b.running || !selectedCount ? 'disabled' : '')}
            ${this.btn3d('wila-bulk-pause', 'btn-warning wila-bulk-pause', b.paused ? 'ادامه' : 'مکث موقت', !b.running || b.stopRequested ? 'disabled' : '')}
            ${this.btn3d('wila-bulk-stop', 'btn-danger wila-bulk-stop', b.stopRequested && b.running ? 'در حال توقف...' : 'توقف کامل', !b.running || b.stopRequested ? 'disabled' : '')}
          </div>`;
        // نوار عملیات بالا و پایین جدول پیش‌نمایش؛ هر دو همیشه هم‌گام‌اند (از یک state رندر می‌شوند)
        const siteBar = (pos) => `<div class="khazan-site-bar khazan-site-bar-${pos}">
            <label class="khazan-site-bar-all"><input type="checkbox" class="khazan-checkbox wila-site-select-all" ${siteSelected === b.site.items.length ? 'checked' : ''} ${b.running ? 'disabled' : ''}> انتخاب همه</label>
            <span class="khazan-status-pill khazan-site-counter">${toPersianDigits(siteSelected)} از ${toPersianDigits(b.site.items.length)} محصول انتخاب شده</span>
            ${this.costEstimateHtml(siteSelected)}
            ${this.btn3d(pos === 'top' ? 'wila-site-add' : 'wila-site-add-bottom', 'btn-warning wila-site-add', `افزودن ${toPersianDigits(siteSelected)} محصول به صف تولید انبوه`, b.running || !siteSelected ? 'disabled' : '')}
          </div>`;
        contentHtml = `<div class="wila-card">
          <h3 style="margin-top:0">تولید انبوه محتوا</h3>
          <div id="wila-bulk-source">${this.radioSwitch('source_mode', [['site', 'محصولات سایت 🛍️', 'site_products'], ['csv', 'بارگذاری فایل CSV 📄', 'csv_upload']], b.source, 'wila-bulk-source', 'data-source', !!locked)}</div>
          ${siteMode && b.site.collapsed ? `<div class="khazan-site-summary" id="wila-site-summary"><span>✅ تعداد ${toPersianDigits(srcIdx.length)} محصول به صف اضافه شد</span><span class="khazan-site-summary-sep">—</span><button type="button" class="khazan-link-btn wila-site-reopen" ${b.running ? 'disabled' : ''}>تغییر یا بازگشت به انتخاب محصولات</button></div>` : ''}
          ${siteMode && !b.site.collapsed && srcIdx.length ? `<div class="khazan-site-summary khazan-site-queue-note" id="wila-site-queue-note"><span>🗂️ ${toPersianDigits(srcIdx.length)} محصول در صف تولید است</span><span class="khazan-site-summary-sep">—</span><button type="button" class="khazan-link-btn wila-site-return">بازگشت به صف</button></div>` : ''}
          ${b.source === 'csv' ? `<p style="color:#64748b;font-size:.85rem">فایل CSV با کدگذاری UTF-8 را بارگذاری کنید. ستون‌ها: تایید سئو، نام محصول، نامک یا لینک محصول (اختیاری)، توضیحات، عنوان سئو، توضیحات متا، کلیدواژه کانونی، عبارات مترادف و فرعی، یادداشت، ویژگی‌های زیره، رویه، آستر و کفی و ویژگی‌های کلی. ردیف هدر در ۵ ردیف نخست به‌طور خودکار پیدا می‌شود. هر ردیف ابتدا با نام محصول و سپس با نامک به محصولات سایت متصل می‌شود.</p>` : '<p style="color:#64748b;font-size:.85rem">محصولات سایت را بر اساس دسته‌بندی یا وضعیت محتوا فیلتر کنید و بدون فایل CSV به صف تولید اضافه کنید. تعداد لینک ورودی محصولات هم‌دسته به‌طور مساوی بین آن‌ها پخش می‌شود.</p>'}
          <div class="khazan-toolbar">
            ${b.source === 'csv' ? `
            <div class="khazan-field khazan-field-file"><label>فایل CSV</label>
              <div class="khazan-dropzone khazan-upload-box ${b.fileName ? 'has-file' : ''} ${locked ? 'is-locked' : ''}" id="wila-bulk-dropzone" role="button" tabindex="0" aria-label="انتخاب یا رها کردن فایل CSV">
                ${b.fileName ? `
                  <input type="file" id="wila-bulk-file" accept=".csv,text/csv" class="khazan-file-hidden" ${locked}>
                  <span class="khazan-dz-icon khazan-dz-ok"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12.5l2.7 2.7L16 9.5"/></svg></span>
                  <span class="khazan-dz-text"><strong dir="ltr">${escapeHtml(b.fileName)}</strong><small>${b.fileSize ? 'حجم فایل: ' + this.formatFileSize(b.fileSize) : ''}</small></span>
                  <span class="khazan-pill-btn">تغییر فایل</span>
                ` : `
                  <div class="khazan-folder-container">
                    <div class="folder">
                      <div class="front-side">
                        <div class="tip"></div>
                        <div class="cover"></div>
                      </div>
                      <div class="back-side cover"></div>
                    </div>
                    <label class="custom-file-upload">
                      <input id="wila-bulk-file" class="title" type="file" accept=".csv,text/csv" ${locked}>
                      انتخاب فایل از سیستم
                    </label>
                  </div>
                  <span class="khazan-dz-text"><strong>بارگذاری فایل اکسل / شیت محصولات</strong><small>فایل CSV را اینجا رها کنید یا دکمه بالا را بزنید</small></span>
                `}
              </div></div>
            ` : b.site.collapsed ? '' : `
            <div class="khazan-field"><label>دسته‌بندی</label>
              <select id="wila-site-category" class="wila-select" ${locked}>
                <option value="0">همه دسته‌بندی‌ها</option>
                ${b.site.categories.map(c => `<option value="${c.id}" ${Number(b.site.category) === Number(c.id) ? 'selected' : ''}>${escapeHtml(c.name)} (${toPersianDigits(c.count)})</option>`).join('')}
              </select></div>
            <div class="khazan-field"><label>جستجوی نام محصول</label>
              <input id="wila-site-search" type="search" class="wila-input" value="${escapeHtml(b.site.search)}" placeholder="بخشی از نام محصول..." ${locked}></div>
            <div class="khazan-field khazan-field-actions">
              ${this.btn3d('wila-site-load', 'btn-primary', b.site.loading ? 'در حال بارگذاری...' : 'بارگذاری محصولات', b.site.loading || b.running ? 'disabled' : '')}
            </div>
            `}
            ${queueVisible ? `${siteMode ? '' : `<div class="khazan-field"><label>کدوم محصولات نشون داده بشن؟</label>
              <select id="wila-bulk-status" class="wila-select">
                <option value="all" ${b.status === 'all' ? 'selected' : ''}>همه محصولات شیت</option>
                <option value="pending" ${b.status === 'pending' ? 'selected' : ''}>فقط محصولاتی که هنوز براشون محتوا ساخته نشده (در انتظار تولید)</option>
                <option value="generated" ${b.status === 'generated' ? 'selected' : ''}>محصولاتی که قبلاً براشون محتوا ساختی</option>
              </select></div>`}
            <div class="khazan-field"><label>حالت درج محتوا</label>
              <select id="wila-bulk-mode" class="wila-select" ${locked}>
                <option value="replace" ${b.mode === 'replace' ? 'selected' : ''}>جایگزینی کامل توضیحات محصول</option>
                <option value="append" ${b.mode === 'append' ? 'selected' : ''}>افزودن به انتهای توضیحات فعلی</option>
                <option value="draft" ${b.mode === 'draft' ? 'selected' : ''}>ذخیره به‌عنوان پیش‌نویس بازبینی (بدون تغییر صفحه زنده)</option>
              </select></div>` : ''}
          </div>
${siteExpanded ? `
          <div class="khazan-stat-cards" role="radiogroup" aria-label="وضعیت محتوای محصولات">
            ${[
              ['all', 'همه محصولات', b.site.counts.total, 'مناسب بازتولید و بالانس سراسری لینک‌ها'],
              ['empty', 'بدون توضیحات و خالی', b.site.counts.empty, 'فقط محصولات بدون متن در سایت'],
              ['has_content', 'دارای توضیحات قبلی', b.site.counts.hasContent, 'محصولات دارای متن جهت بازنویسی']
            ].map(([key, title, count, hint]) => `
              <button type="button" class="khazan-stat-card ${b.site.filter === key ? 'active' : ''} ${b.site.loading ? 'is-loading' : ''}" data-site-filter="${key}" role="radio" aria-checked="${b.site.filter === key}" ${b.running ? 'disabled' : ''}>
                <span class="khazan-stat-check" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
                <span class="khazan-stat-title">${title}</span>
                <span class="khazan-stat-value">${b.site.loaded ? toPersianDigits(count) : '—'}</span>
                <span class="khazan-stat-hint">${hint}</span>
              </button>`).join('')}
          </div>` : ''}
          ${siteExpanded && (b.site.loaded || b.site.error) ? `<div class="khazan-statusbar"><span class="khazan-status-pill">${b.site.error ? '⚠️ ' + escapeHtml(b.site.error) : `🛍️ ${toPersianDigits(b.site.items.length)} محصول از سایت بارگذاری شد${b.site.hasMore ? ' (سقف نمایش؛ فیلتر را دقیق‌تر کنید)' : ''}`}</span></div>` : ''}
          ${siteExpanded && b.site.loaded && b.site.items.length ? `
          ${siteBar('top')}
          <div class="wila-table-scroll khazan-site-scroll"><table class="wila-table khazan-site-table"><thead><tr>
            <th><input type="checkbox" class="khazan-checkbox wila-site-select-all" id="wila-site-select-all" ${siteSelected === b.site.items.length ? 'checked' : ''} ${b.running ? 'disabled' : ''}></th><th>نام محصول</th><th>دسته‌بندی</th><th>وضعیت محتوا</th>
          </tr></thead><tbody>${b.site.items.map(p => `<tr><td><input type="checkbox" class="khazan-checkbox wila-site-row" data-id="${p.id}" ${b.site.selected[p.id] ? 'checked' : ''} ${b.running ? 'disabled' : ''}></td><td style="font-weight:600">${escapeHtml(p.title)}</td><td>${escapeHtml((p.categories || []).map(c => c.name).join('، ') || '—')}</td><td>${p.has_description ? '<span class="khazan-badge khazan-badge-ok">📝 دارای توضیحات</span>' : '<span class="khazan-badge khazan-badge-warn">⚪ خالی</span>'}${p.generated ? ` <span class="khazan-badge khazan-badge-done" title="${escapeHtml('تولید شده در ' + formatJalali(p.generated.at))}">🕒 ${escapeHtml(shortJalali(p.generated.at))}</span>` : ''}</td></tr>`).join('')}</tbody></table></div>
          ${siteBar('bottom')}` : ''}
          ${b.matching ? '<div class="wila-skeleton" style="height:14px;margin-top:14px"></div><div class="wila-skeleton" style="height:14px;margin-top:8px"></div>' : ''}
          ${queueVisible ? `
            <div class="khazan-toggle-grid" id="wila-queue-settings">
              <label class="khazan-toggle"><span class="khazan-switch"><input type="checkbox" id="wila-bulk-seo" ${b.injectSeo ? 'checked' : ''} ${locked}><span class="khazan-slider"></span></span><span class="khazan-toggle-text"><strong>🔎 درج متای سئو</strong><small>ذخیره عنوان سئو، توضیحات متا و کلیدواژه در Yoast و Rank Math</small></span></label>
              <label class="khazan-toggle"><span class="khazan-switch"><input type="checkbox" id="wila-bulk-links" ${b.autoLinks ? 'checked' : ''} ${locked}><span class="khazan-slider"></span></span><span class="khazan-toggle-text"><strong>🔗 لینک‌سازی خودکار</strong><small>لینک به دسته‌بندی محصول و ۲ محصول هم‌دسته</small></span></label>
              ${siteMode ? '' : `<label class="khazan-toggle"><span class="khazan-switch"><input type="checkbox" id="wila-bulk-create" ${b.createMissing ? 'checked' : ''} ${locked}><span class="khazan-slider"></span></span><span class="khazan-toggle-text"><strong>➕ ساخت پیش‌نویس محصول</strong><small>ایجاد خودکار محصول پیش‌نویس برای موارد یافت‌نشده</small></span></label>`}
            </div>
            ${siteMode ? `<div class="khazan-statusbar"><span class="khazan-status-pill">🛍️ ${toPersianDigits(srcIdx.length)} محصول در صف (${toPersianDigits(selectedCount)} مورد برای تولید انتخاب شده)</span>${this.costEstimateHtml(selectedCount)}</div>` : `            <div class="khazan-statusbar"><span class="khazan-status-pill">🔗 از بین ${toPersianDigits(b.rows.length)} محصول، ${toPersianDigits(matchedCount)} تا به سایت متصل شدن و آماده تولید هستن (${toPersianDigits(selectedCount)} مورد انتخاب شده)</span>${this.costEstimateHtml(selectedCount)}</div>
            <div class="khazan-subtabs">
              <button type="button" class="khazan-subtab wila-bulk-view ${b.view === 'found' ? 'active' : ''}" data-view="found">🟢 محصولات موجود در سایت (متصل‌شده) <em>${toPersianDigits(matchedCount)}</em></button>
              <button type="button" class="khazan-subtab wila-bulk-view ${b.view === 'missing' ? 'active' : ''}" data-view="missing">⚠️ محصولات جدید / یافت‌نشده <em>${toPersianDigits(srcIdx.length - matchedCount)}</em></button>
            </div>`}
            ${queueControls()}
            <div id="wila-bulk-progress" class="khazan-queue-progress">${this.bulkProgressHtml()}</div>
            <div class="wila-table-scroll"><table class="wila-table"><thead><tr>
              <th><input type="checkbox" class="khazan-checkbox" id="wila-bulk-select-all" ${allVisibleSelected ? 'checked' : ''} ${selectable.length && !b.running ? '' : 'disabled'}></th><th>#</th><th>نام محصول (شیت)</th><th>وضعیت</th>${viewFound ? '<th>محصول سایت</th>' : ''}<th>وضعیت پردازش</th><th>عملیات</th>
            </tr></thead><tbody>${visible.length ? visible.map(i => {
              const r = b.rows[i], m = r.match;
              const viaLabel = m && m.via === 'site' ? 'انتخاب از محصولات سایت' : m && m.via === 'created' ? 'ساخته شد' : m && m.via === 'slug' ? 'تطبیق با نامک' : m && m.via === 'name_ambiguous' ? 'تطبیق با نام (چند مورد مشابه)' : 'تطبیق با نام';
              let badge;
              if (!m) badge = '<span class="khazan-badge khazan-badge-muted">—</span>';
              else if (m.generated) badge = `<span class="khazan-badge khazan-badge-done" title="${escapeHtml('تولید شده در ' + formatJalali(m.generated.at))}">🕒 تولید شده: ${escapeHtml(shortJalali(m.generated.at))}</span>`;
              else if (m.history) badge = `<span class="khazan-badge khazan-badge-done" title="${escapeHtml('آپلود شده در ' + formatJalali(m.history.first_seen) + (m.history.file ? '، فایل ' + m.history.file : ''))}">🕒 آپلود شده: ${escapeHtml(shortJalali(m.history.first_seen))}</span>`;
              else if (m.matched) badge = `<span class="khazan-badge khazan-badge-ok" title="${escapeHtml(viaLabel)}">🟢 متصل شد</span>`;
              else badge = b.createMissing ? '<span class="khazan-badge khazan-badge-warn" title="پس از شروع، محصول پیش‌نویس ساخته می‌شود">➕ ساخت پیش‌نویس</span>' : '<span class="khazan-badge khazan-badge-miss">❌ پیدا نشد</span>';
              return `<tr><td><input type="checkbox" class="khazan-checkbox wila-bulk-row" data-index="${i}" ${b.selected[i] ? 'checked' : ''} ${this.bulkIsTargetable(i) && !b.running ? '' : 'disabled'}></td><td>${toPersianDigits(i + 1)}</td><td style="font-weight:600">${escapeHtml(r.name || r.slug || '')}</td><td>${badge}</td>${viewFound ? `<td>${m && m.matched ? `<a href="${escapeHtml(m.url)}" target="_blank" rel="noopener">${escapeHtml(m.title)}</a>` : '—'}</td>` : ''}<td data-bulk-status="${i}">${this.bulkStatusHtml(r)}</td><td><button class="wila-btn wila-btn-outline wila-btn-sm wila-bulk-single" data-index="${i}" ${this.bulkIsTargetable(i) && !b.running ? '' : 'disabled'}>${m && (m.generated || r.run === 'done') ? '⚙️ تولید مجدد' : '▶ تولید این ردیف'}</button></td></tr>`;
            }).join('') : `<tr><td colspan="7" style="text-align:center;padding:20px;color:#78716c">${viewFound ? 'محصول متصل‌شده‌ای برای نمایش وجود ندارد.' : 'همه ردیف‌ها به محصولات سایت متصل شده‌اند.'}</td></tr>`}</tbody></table></div>
            <div id="wila-bulk-log" class="khazan-selectable" style="margin-top:10px;max-height:220px;overflow:auto;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:.8rem;line-height:1.9">${this.bulkLogHtml()}</div>
          ` : ''}
        </div>`;
      return contentHtml;
    },

    viewPlanner: function () {
      const { activeTab, summary, isScanning, scanProgress, scanTotal, scanCurrent, modal, aiSettings, plannerForm, plannerResult, isPlanning, isGeneratingContent } = this.state;
      let contentHtml = '';
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
      return contentHtml;
    },

    viewItems: function () {
      const { activeTab, summary, isScanning, scanProgress, scanTotal, scanCurrent, modal, aiSettings, plannerForm, plannerResult, isPlanning, isGeneratingContent } = this.state;
      let contentHtml = '';
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
      return contentHtml;
    },

    viewOrphans: function () {
      const { activeTab, summary, isScanning, scanProgress, scanTotal, scanCurrent, modal, aiSettings, plannerForm, plannerResult, isPlanning, isGeneratingContent } = this.state;
      let contentHtml = '';
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
      return contentHtml;
    },

    viewSettings: function () {
      const { activeTab, summary, isScanning, scanProgress, scanTotal, scanCurrent, modal, aiSettings, plannerForm, plannerResult, isPlanning, isGeneratingContent } = this.state;
      let contentHtml = '';
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
              ${this.aiBalanceBadge('full')}
              <p style="font-size:0.75rem; color:#64748b; margin-bottom:0;">مدل ثابت: <code>gpt-5.4-mini</code></p>
            </div>


            <div style="display:flex; gap:10px;">
              <button class="wila-btn wila-btn-primary" id="wila-save-settings-btn">ذخیره تنظیمات</button>
              <button class="wila-btn wila-btn-outline" id="wila-test-api-btn">تست اتصال</button>
            </div>
            <div id="wila-test-result" style="margin-top:16px; display:none;"></div>
          `;
      return contentHtml;
    },

    // ورود به تب تولید انبوه: منبع پیش‌فرض «محصولات سایت» است (مگر ردیف CSV بارگذاری شده یا صف در حال اجرا باشد)
    // کلید انتخابی سه‌بعدی (رادیو): name مشترک، value اختصاصی هر گزینه؛ رویداد change با کلاس cls و attr بایند می‌شود
    radioSwitch: function (name, items, current, cls, attr, disabled) {
      return `<div class="khazan-radio-inputs" role="radiogroup">${items.map(([key, label, value]) => `<label class="radio"><input type="radio" name="${name}" value="${escapeHtml(value || key)}" class="${cls}" ${attr}="${key}" ${current === key ? 'checked' : ''} ${disabled ? 'disabled' : ''}><span class="name">${label}</span></label>`).join('')}</div>`;
    },

    enterBulkTab: function () {
      const b = this.state.bulk;
      if (!b.running && !b.rows.some(r => r.source !== 'site')) b.source = 'site';
      if (b.source === 'site' && !b.site.loaded && !b.site.loading) this.loadSiteProducts();
    },

    bindTabEvents: function () {
      this.root.querySelectorAll('.wila-tab-btn').forEach(btn => btn.addEventListener('click', e => {
        this.state.activeTab = e.currentTarget.getAttribute('data-tab');
        if (!this.state.activeTab) return;
        this.state.navOpen = false;
        this.state.activeNav = e.currentTarget.getAttribute('data-nav') || this.state.activeTab;
        this.state.pendingScroll = e.currentTarget.getAttribute('data-scroll') || '';
        if (this.state.activeTab === 'bulk') this.enterBulkTab();
        if (this.state.activeTab === 'archive') { this.state.historyLoading = true; this.fetchContentHistory(); }
        if (this.state.activeTab === 'home' && !this.state.siteStats && !this.state.siteStatsLoading) this.fetchSiteStats();
        if (this.state.activeTab === 'items') this.fetchItems();
        if (this.state.activeTab === 'orphans') this.fetchOrphans();
        if (this.state.activeTab === 'seofix' && !this.state.seoFix.targets.length) this.fetchSeoFixTargets(this.state.seoFix.search || '');
        this.render();
      }));

      this.root.querySelectorAll('.khazan-go').forEach(btn => btn.addEventListener('click', e => {
        const target = KHAZAN_NAV.concat(KHAZAN_NAV_EXTRA).find(n => n.key === e.currentTarget.getAttribute('data-go'));
        if (!target) return;
        this.state.navOpen = false;
        this.state.activeTab = target.tab;
        this.state.activeNav = target.key;
        this.state.pendingScroll = target.scroll || '';
        if (target.tab === 'bulk') this.enterBulkTab();
        if (target.tab === 'archive') { this.state.historyLoading = true; this.fetchContentHistory(); }
        if (target.tab === 'orphans') this.fetchOrphans();
        if (target.tab === 'seofix' && !this.state.seoFix.targets.length) this.fetchSeoFixTargets(this.state.seoFix.search || '');
        this.render();
      }));

      // منوی همبرگری (موبایل/تبلت): باز و بسته با دکمه، بستن با پس‌زمینه تاریک یا دکمه ✕
      const menuToggle = this.root.querySelector('#khazan-menu-toggle');
      if (menuToggle) menuToggle.addEventListener('click', () => this.setNavOpen(!this.state.navOpen));
      const navBackdrop = this.root.querySelector('#khazan-backdrop');
      if (navBackdrop) navBackdrop.addEventListener('click', () => this.setNavOpen(false));
      const drawerClose = this.root.querySelector('#khazan-drawer-close');
      if (drawerClose) drawerClose.addEventListener('click', () => this.setNavOpen(false));
      this.root.querySelectorAll('.khazan-balance-refresh').forEach(btn => btn.addEventListener('click', () => this.getAiBalance(true)));
      this.root.querySelectorAll('.khazan-stats-refresh').forEach(btn => btn.addEventListener('click', () => this.fetchSiteStats(true)));
      this.root.querySelectorAll('.khazan-quick-empty').forEach(btn => btn.addEventListener('click', () => this.goBulkEmptyProducts()));
      const leafToggle = this.root.querySelector('#khazan-leaf-toggle');
      if (leafToggle && window.KhazanLeaves) leafToggle.addEventListener('click', () => {
        const on = window.KhazanLeaves.toggle();
        leafToggle.classList.toggle('on', on);
        leafToggle.textContent = '🍁 افکت خزان: ' + (on ? 'فعال' : 'غیرفعال');
      });

      if (this.state.pendingScroll) {
        const el = document.getElementById(this.state.pendingScroll);
        this.state.pendingScroll = '';
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    },
  };

  window.KhazanTabs = {
    NAV: KHAZAN_NAV,
    NAV_EXTRA: KHAZAN_NAV_EXTRA,
    icon: icon,
    methods: methods
  };
})();
