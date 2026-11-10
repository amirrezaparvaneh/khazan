/**
 * Khazan - internal linking tab: accurate orphan audit, links to new articles from older ones, log with undo.
 * Every suggestion shows the exact sentence and anchor before anything is written.
 * Mixed into the dashboard app by wila-admin.js (methods run with `this` === app).
 */
(function () {
  'use strict';

  const { escapeHtml, toPersianDigits } = window.KhazanUtils;
  const fa = (n) => toPersianDigits(Math.round(Number(n) || 0));
  const short = (u) => { try { return decodeURIComponent(String(u || '').replace(/^https?:\/\/[^/]+/, '')) || '/'; } catch (e) { return String(u || ''); } };
  const STATUS = {
    orphan: { label: 'یتیم', tone: 'red', hint: 'هیچ لینکی، نه در محتوا و نه در منو' },
    menu_only: { label: 'فقط از منو', tone: 'amber', hint: 'فقط منو یا ابزارک به آن لینک داده؛ گوگل لینک داخل متن را بسیار مهم‌تر می‌داند' }
  };
  const REASONS = {
    already_linked: 'از قبل به این صفحه لینک داده‌اند',
    full: 'سقف لینک داخلی‌شان پر است',
    no_phrase: 'عبارت مناسبی برای انکر در متنشان نبود',
    builder: 'با صفحه‌ساز (المنتور) ساخته شده‌اند و متنشان قابل ویرایش امن نیست'
  };

  const methods = {
    loadLinking: async function () {
      const l = this.state.linking;
      l.busy = l.busy || 'load'; this.render();
      try {
        const r = await this.apiFetch('/linking/overview');
        if (!r.success) throw new Error(r.message || 'اطلاعات دریافت نشد.');
        l.data = r; l.error = '';
      } catch (e) { l.error = e.message; }
      if (l.busy === 'load') l.busy = '';
      this.render();
    },

    runOrphanAudit: async function () {
      const l = this.state.linking;
      l.busy = 'audit'; l.error = ''; this.render();
      try {
        const r = await this.apiFetch('/linking/audit', { method: 'POST', body: '{}' });
        if (!r.success) throw new Error(r.message || 'بررسی انجام نشد.');
        l.data = Object.assign({}, l.data || {}, { audit: r.audit });
        const t = r.audit.totals;
        this.toast(`بررسی تمام شد: ${fa(t.orphan)} صفحه‌ی یتیم و ${fa(t.menu_only)} صفحه که فقط از منو لینک دارد.`);
      } catch (e) { l.error = e.message; }
      l.busy = ''; this.render();
    },

    toggleSuggest: async function (type, id) {
      const l = this.state.linking;
      const key = type + '_' + id;
      if (l.open[key] && !l.open[key].loading) { delete l.open[key]; this.render(); return; }
      l.open[key] = { loading: true };
      this.render();
      try {
        const r = await this.apiFetch(`/linking/suggest?type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`);
        if (!r.success) throw new Error(r.message || 'پیشنهادی دریافت نشد.');
        const sel = {};
        r.suggestions.forEach(s => { sel[s.source_id] = true; });
        l.open[key] = { result: r, sel };
      } catch (e) { l.open[key] = { error: e.message }; }
      this.render();
    },

    suggestAi: async function (type, id) {
      const l = this.state.linking;
      const key = type + '_' + id;
      l.open[key] = { loading: true, ai: true };
      this.render();
      try {
        const r = await this.apiFetch('/linking/suggest-ai', { method: 'POST', body: JSON.stringify({ type, id }) });
        if (!r.success) throw new Error(r.message || 'هوش مصنوعی پیشنهادی نداد.');
        const sel = {};
        r.suggestions.forEach(s => { sel[s.source_id] = true; });
        l.open[key] = { result: r, sel, ai: true };
      } catch (e) { l.open[key] = { error: e.message, ai: true }; }
      this.render();
    },

    applySuggest: async function (type, id) {
      const l = this.state.linking;
      const key = type + '_' + id;
      const box = l.open[key];
      if (!box || !box.result) return;
      const items = box.result.suggestions.filter(s => box.sel[s.source_id]).map(s => ({ source_id: s.source_id, spot: s.spot, hash: s.hash }));
      if (!items.length) return this.toast('موردی انتخاب نشده است.', 'error');
      box.applying = true; this.render();
      try {
        const r = await this.apiFetch('/linking/apply', { method: 'POST', body: JSON.stringify({ type, id, items }) });
        if (!r.success) throw new Error(r.message || 'لینک‌ها اضافه نشد.');
        box.done = r.results;
        const failed = r.results.filter(x => !x.ok);
        this.toast(failed.length ? `${fa(r.applied)} لینک اضافه شد؛ ${fa(failed.length)} مورد انجام نشد.` : `${fa(r.applied)} لینک اضافه شد.`, failed.length && !r.applied ? 'error' : 'success');
        if (r.applied) await this.loadLinking();
      } catch (e) { this.toast(e.message, 'error'); }
      box.applying = false; this.render();
    },

    undoLink: async function (logId) {
      if (!window.confirm('این لینک از متن مقاله برداشته شود؟ متن انکر سر جایش می‌ماند.')) return;
      try {
        const r = await this.apiFetch('/linking/undo', { method: 'POST', body: JSON.stringify({ log_id: logId }) });
        if (!r.success) throw new Error(r.message || 'برگرداندن انجام نشد.');
        this.toast('لینک برداشته شد.');
        await this.loadLinking();
      } catch (e) { this.toast(e.message, 'error'); }
    },

    saveLinkingSettings: async function () {
      const q = (s) => this.root.querySelector(s);
      const body = { auto_suggest: q('#khz-ln-auto-suggest').checked, auto_apply: q('#khz-ln-auto-apply').checked, per_target: Number(q('#khz-ln-per').value) || 5 };
      try {
        const r = await this.apiFetch('/linking/settings', { method: 'POST', body: JSON.stringify(body) });
        if (!r.success) throw new Error(r.message || 'ذخیره نشد.');
        this.state.linking.data.settings = r.settings;
        this.toast('تنظیمات لینک‌سازی ذخیره شد.');
      } catch (e) { this.toast(e.message, 'error'); }
      this.render();
    },

    // ---- views ----

    suggestPanelHtml: function (type, id) {
      const box = this.state.linking.open[type + '_' + id];
      if (!box) return '';
      const aiBtn = `<button type="button" class="wila-btn wila-btn-primary wila-btn-sm" data-ln-ai="${escapeHtml(type)}" data-id="${id}">پیشنهاد جای لینک با هوش مصنوعی</button>`;
      if (box.loading) return `<div class="khz-ln-panel" aria-busy="true"><div class="khz-ln-skeleton"></div><div class="khz-ln-skeleton"></div><p class="khz-ln-muted" role="status">${box.ai ? 'هوش مصنوعی متن کامل مقاله‌های مرتبط را می‌خواند و جای مناسب را انتخاب می‌کند؛ ممکن است تا یک دقیقه طول بکشد…' : 'متن مقاله‌های مرتبط در حال خواندن است…'}</p></div>`;
      if (box.error) return `<div class="khz-ln-panel"><p class="khz-ln-error" role="alert">${escapeHtml(box.error)}</p>${box.ai ? `<div class="khz-ln-actions">${aiBtn.replace('پیشنهاد جای لینک با هوش مصنوعی', 'تلاش دوباره')}</div>` : ''}</div>`;
      const r = box.result;
      const done = {};
      (box.done || []).forEach(d => { done[d.source_id] = d; });
      const skipped = Object.keys(r.skipped || {}).filter(k => REASONS[k]).map(k => `<li>${fa(r.skipped[k])} مقاله ${REASONS[k]}</li>`).join('');
      if (!r.suggestions.length) {
        return `<div class="khz-ln-panel">
          <div class="khz-ln-empty">
            <b>جای طبیعی برای لینک پیدا نشد.</b>
            <p>${r.target.phrases.length ? `${fa(r.checked)} مقاله با عبارت‌های «${r.target.phrases.slice(0, 3).map(escapeHtml).join('»، «')}» بررسی شد.` : 'برای این صفحه کلیدواژه‌ی کانونی یا عبارت جست‌وجوی مشخصی ثبت نشده است؛ ابتدا کلیدواژه‌ی کانونی آن را در Yoast یا Rank Math تعیین کنید.'}</p>
            ${skipped ? `<ul>${skipped}</ul>` : ''}
            ${box.ai
              ? '<p class="khz-ln-muted">هوش مصنوعی هم در مقاله‌های مرتبط جای طبیعی‌ای پیدا نکرد. بهتر است یک مقاله‌ی تازه درباره‌ی همین موضوع بنویسید و از آن به این صفحه لینک بدهید.</p>'
              : `<p class="khz-ln-muted">هیچ مقاله‌ای عبارت‌های این صفحه را عیناً ندارد. هوش مصنوعی می‌تواند متن کامل مقاله‌های هم‌موضوع را بخواند و پاراگراف و انکرتکست مناسب را از دل متن خودشان پیشنهاد بدهد (از اعتبار AvalAI استفاده می‌کند).</p><div class="khz-ln-actions">${aiBtn}</div>`}
          </div></div>`;
      }
      const selected = r.suggestions.filter(s => box.sel[s.source_id] && !(done[s.source_id] && done[s.source_id].ok)).length;
      return `<div class="khz-ln-panel">
        <p class="khz-ln-muted">${box.ai ? '<span class="khz-ln-chip tone-ai">پیشنهاد هوش مصنوعی</span> ' : ''}${fa(r.checked)} مقاله‌ی مرتبط خوانده شد. این‌ها طبیعی‌ترین جاها برای لینک به «${escapeHtml(r.target.title)}» هستند؛ متن مقاله‌ها تغییر نمی‌کند و فقط همان عبارت لینک می‌شود.</p>
        <ul class="khz-ln-sugs">
          ${r.suggestions.map(s => {
            const d = done[s.source_id];
            return `<li class="khz-ln-sug ${d ? (d.ok ? 'is-done' : 'is-failed') : ''}">
              <label class="khz-ln-check">
                <input type="checkbox" class="khz-ln-sel" data-key="${escapeHtml(type + '_' + id)}" data-src="${s.source_id}" ${box.sel[s.source_id] ? 'checked' : ''} ${d ? 'disabled' : ''} aria-label="انتخاب ${escapeHtml(s.source_title)}">
              </label>
              <div class="khz-ln-sug-body">
                <div class="khz-ln-sug-head"><a href="${escapeHtml(s.source_url)}" target="_blank" rel="noopener">${escapeHtml(s.source_title)}</a>
                  ${d ? `<span class="khz-ln-chip ${d.ok ? 'tone-green' : 'tone-red'}">${d.ok ? 'لینک اضافه شد' : escapeHtml(d.message)}</span>` : `<span class="khz-ln-chip">انکر: ${escapeHtml(s.anchor)}</span>`}</div>
                <p class="khz-ln-quote">${escapeHtml(s.context.before)} <mark>${escapeHtml(s.context.anchor)}</mark> ${escapeHtml(s.context.after)}</p>
              </div>
            </li>`;
          }).join('')}
        </ul>
        <div class="khz-ln-actions">
          <button class="wila-btn wila-btn-primary" data-ln-apply="${escapeHtml(type)}" data-id="${id}" ${box.applying || !selected ? 'disabled' : ''}>${box.applying ? 'در حال افزودن…' : `افزودن ${fa(selected)} لینک`}</button>
          <span class="khz-ln-muted">هر تغییر یک نسخه‌ی قبلی در وردپرس می‌سازد و از «گزارش لینک‌ها» قابل برگشت است.</span>
        </div>
      </div>`;
    },

    viewLinking: function () {
      const l = this.state.linking;
      const d = l.data || {};
      const a = d.audit || {};
      const t = a.totals || null;
      const busy = !!l.busy;
      const sub = l.sub || 'orphans';
      const tab = (k, label, n) => `<button type="button" role="tab" aria-selected="${sub === k}" class="khz-ln-tab ${sub === k ? 'is-active' : ''}" data-ln-sub="${k}">${label}${n ? `<span class="khz-ln-count">${fa(n)}</span>` : ''}</button>`;
      const recent = d.recent || [];
      const needLinks = recent.filter(r => r.inbound === 0).length;

      let body = '';
      if (sub === 'orphans') {
        const filter = l.filter || 'all';
        const q = (l.q || '').trim();
        const items = (a.items || []).filter(i => (filter === 'all' || i.status === filter) && (!q || i.title.indexOf(q) !== -1 || short(i.url).indexOf(q) !== -1));
        const chip = (k, label, n) => `<button type="button" class="khz-ln-filter ${filter === k ? 'is-active' : ''}" data-ln-filter="${k}">${label} <span>${fa(n)}</span></button>`;
        body = !t ? `
          <div class="khz-ln-empty khz-ln-empty-lg">
            <b>هنوز بررسی دقیق انجام نشده است.</b>
            <p>بررسی دقیق متن نوشته‌ها، توضیح کوتاه محصولات، توضیح و متای دسته‌ها، صفحه‌سازها، منوها و ابزارک‌ها را می‌خواند و یتیم‌های واقعی را از یتیم‌های اشتباهی جدا می‌کند.</p>
            <button class="wila-btn wila-btn-primary" data-ln-audit ${busy ? 'disabled' : ''}>${l.busy === 'audit' ? 'در حال بررسی…' : 'شروع بررسی دقیق'}</button>
          </div>` : `
          <div class="khz-ln-toolbar">
            <div class="khz-ln-filters">${chip('all', 'همه', (a.items || []).length)}${chip('orphan', 'یتیم', t.orphan)}${chip('menu_only', 'فقط از منو', t.menu_only)}</div>
            <input type="search" class="wila-input khz-ln-search" id="khz-ln-q" placeholder="جست‌وجو در عنوان یا آدرس" value="${escapeHtml(l.q || '')}" aria-label="جست‌وجو">
          </div>
          ${items.length ? `<ul class="khz-ln-list">${items.map(i => {
            const st = STATUS[i.status];
            const open = !!l.open[i.type + '_' + i.id];
            return `<li class="khz-ln-row ${open ? 'is-open' : ''}">
              <div class="khz-ln-row-main">
                <span class="khz-ln-chip tone-${st.tone}" title="${escapeHtml(st.hint)}">${st.label}</span>
                <div class="khz-ln-row-text"><a href="${escapeHtml(i.url)}" target="_blank" rel="noopener" class="khz-ln-title">${escapeHtml(i.title)}</a><span class="khz-ln-url" dir="ltr">${escapeHtml(short(i.url))}</span></div>
                <span class="khz-ln-type">${escapeHtml(i.type_label)}</span>
                <button class="wila-btn ${open ? 'wila-btn-outline' : 'wila-btn-primary'} khz-ln-find" data-ln-find="${escapeHtml(i.type)}" data-id="${i.id}" aria-expanded="${open}">${open ? 'بستن' : 'پیدا کردن لینک'}</button>
              </div>
              ${this.suggestPanelHtml(i.type, i.id)}
            </li>`;
          }).join('')}</ul>` : `<div class="khz-ln-empty"><b>${q ? 'نتیجه‌ای برای این جست‌وجو نیست.' : 'در این گروه صفحه‌ای نیست. عالی است!'}</b></div>`}`;
      } else if (sub === 'recent') {
        body = recent.length ? `
          <p class="khz-ln-muted">مقاله‌های ۶۰ روز اخیر. هر مقاله‌ی تازه باید از مقاله‌های قبلیِ مرتبط لینک بگیرد تا گوگل زودتر پیدایش کند و اعتبار بگیرد.</p>
          <ul class="khz-ln-list">${recent.map(r => {
            const open = !!l.open[r.type + '_' + r.id];
            return `<li class="khz-ln-row ${open ? 'is-open' : ''}">
              <div class="khz-ln-row-main">
                <span class="khz-ln-chip ${r.inbound ? 'tone-green' : 'tone-red'}">${r.inbound ? `${fa(r.inbound)} لینک ورودی` : 'بدون لینک ورودی'}</span>
                <div class="khz-ln-row-text"><a href="${escapeHtml(r.url)}" target="_blank" rel="noopener" class="khz-ln-title">${escapeHtml(r.title)}</a><span class="khz-ln-url">${toPersianDigits(r.date)}${r.pending ? ` · <b class="khz-ln-pending">${fa(r.pending)} پیشنهاد آماده</b>` : ''}</span></div>
                <button class="wila-btn ${open ? 'wila-btn-outline' : 'wila-btn-primary'} khz-ln-find" data-ln-find="${escapeHtml(r.type)}" data-id="${r.id}" aria-expanded="${open}">${open ? 'بستن' : 'لینک از مقاله‌های قبلی'}</button>
              </div>
              ${this.suggestPanelHtml(r.type, r.id)}
            </li>`;
          }).join('')}</ul>` : '<div class="khz-ln-empty"><b>در ۶۰ روز اخیر مقاله‌ای منتشر نشده است.</b></div>';
      } else if (sub === 'log') {
        const log = d.log || [];
        body = log.length ? `<ul class="khz-ln-list">${log.map(e => `
          <li class="khz-ln-row"><div class="khz-ln-row-main">
            <span class="khz-ln-chip ${e.by === 'auto' ? 'tone-blue' : ''}">${e.by === 'auto' ? 'خودکار' : 'دستی'}</span>
            <div class="khz-ln-row-text"><span class="khz-ln-title">${escapeHtml(e.source_title)} ← ${escapeHtml(e.target_title)}</span><span class="khz-ln-url">انکر «${escapeHtml(e.anchor)}» · ${toPersianDigits(escapeHtml(e.at))}</span></div>
            <button class="wila-btn wila-btn-outline" data-ln-undo="${escapeHtml(e.id)}">برگرداندن</button>
          </div></li>`).join('')}</ul>` : '<div class="khz-ln-empty"><b>هنوز لینکی از این بخش اضافه نشده است.</b></div>';
      } else {
        const s = d.settings || {};
        body = `<div class="khz-ln-settings">
          <label class="khazan-toggle"><span class="khazan-switch"><input type="checkbox" id="khz-ln-auto-suggest" ${s.auto_suggest ? 'checked' : ''}><span class="khazan-slider"></span></span><span class="khazan-toggle-text"><strong>پیشنهاد خودکار برای مقاله‌ی تازه</strong><br><small>یک دقیقه بعد از انتشار، جای لینک در مقاله‌های قبلی پیدا و آماده می‌شود.</small></span></label>
          <label class="khazan-toggle"><span class="khazan-switch"><input type="checkbox" id="khz-ln-auto-apply" ${s.auto_apply ? 'checked' : ''}><span class="khazan-slider"></span></span><span class="khazan-toggle-text"><strong>افزودن خودکار بدون تأیید</strong><br><small>پیشنهادها بدون بازبینی در مقاله‌های منتشرشده اعمال می‌شوند. همه‌ی موارد در گزارش قابل برگشت‌اند، ولی پیشنهاد ما خاموش ماندن است.</small></span></label>
          <label class="khz-ln-field">حداکثر مقاله‌ی لینک‌دهنده به هر صفحه
            <input type="number" min="1" max="10" id="khz-ln-per" class="wila-input" value="${Number(s.per_target) || 5}"></label>
          <button class="wila-btn wila-btn-primary" id="khz-ln-save">ذخیره‌ی تنظیمات</button>
        </div>`;
      }

      return `
        ${l.error ? `<div class="wila-card khz-ln-error-card" role="alert">${escapeHtml(l.error)}</div>` : ''}
        <div class="wila-card">
          <div class="khz-ln-header">
            <div><h3>لینک‌سازی داخلی و صفحات یتیم</h3>
              <p class="khz-ln-muted">${a.at ? `آخرین بررسی دقیق: ${toPersianDigits(escapeHtml(a.at))}` : 'برای دیدن یتیم‌های واقعی، یک بار بررسی دقیق را اجرا کنید.'}</p></div>
            ${t ? `<button class="wila-btn wila-btn-outline" data-ln-audit ${busy ? 'disabled' : ''}>${l.busy === 'audit' ? 'در حال بررسی…' : 'بررسی دوباره'}</button>` : ''}
          </div>
          ${t ? `<div class="khz-ln-stats">
            <div class="khz-ln-stat"><span class="khz-ln-stat-n tone-red">${fa(t.orphan)}</span><span class="khz-ln-stat-l">واقعاً یتیم</span></div>
            <div class="khz-ln-stat"><span class="khz-ln-stat-n tone-amber">${fa(t.menu_only)}</span><span class="khz-ln-stat-l">فقط از منو</span></div>
            <div class="khz-ln-stat"><span class="khz-ln-stat-n tone-green">${fa(t.linked)}</span><span class="khz-ln-stat-l">لینک‌دار از ${fa(t.pages)} صفحه</span></div>
            <div class="khz-ln-stat" title="صفحه‌هایی که فهرست قبلی یتیم می‌دانست ولی در واقع لینک دارند"><span class="khz-ln-stat-n">${fa(t.false_orphans)}</span><span class="khz-ln-stat-l">یتیمِ اشتباهی در فهرست قبلی</span></div>
          </div>` : ''}
          <div class="khz-ln-tabs" role="tablist">
            ${tab('orphans', 'صفحات یتیم', t ? t.orphan + t.menu_only : 0)}
            ${tab('recent', 'مقاله‌های جدید', needLinks)}
            ${tab('log', 'گزارش لینک‌ها', 0)}
            ${tab('settings', 'تنظیمات', 0)}
          </div>
          <div class="khz-ln-body">${l.busy === 'load' && !l.data ? '<div class="khz-ln-skeleton"></div><div class="khz-ln-skeleton"></div><div class="khz-ln-skeleton"></div>' : body}</div>
        </div>`;
    },

    bindLinkingEvents: function () {
      const root = this.root;
      const l = this.state.linking;
      root.querySelectorAll('[data-ln-audit]').forEach(b => b.addEventListener('click', () => this.runOrphanAudit()));
      root.querySelectorAll('[data-ln-sub]').forEach(b => b.addEventListener('click', () => { l.sub = b.getAttribute('data-ln-sub'); this.render(); }));
      root.querySelectorAll('[data-ln-filter]').forEach(b => b.addEventListener('click', () => { l.filter = b.getAttribute('data-ln-filter'); this.render(); }));
      root.querySelectorAll('[data-ln-find]').forEach(b => b.addEventListener('click', () => this.toggleSuggest(b.getAttribute('data-ln-find'), Number(b.getAttribute('data-id')))));
      root.querySelectorAll('[data-ln-ai]').forEach(b => b.addEventListener('click', () => this.suggestAi(b.getAttribute('data-ln-ai'), Number(b.getAttribute('data-id')))));
      root.querySelectorAll('[data-ln-apply]').forEach(b => b.addEventListener('click', () => this.applySuggest(b.getAttribute('data-ln-apply'), Number(b.getAttribute('data-id')))));
      root.querySelectorAll('[data-ln-undo]').forEach(b => b.addEventListener('click', () => this.undoLink(b.getAttribute('data-ln-undo'))));
      root.querySelectorAll('.khz-ln-sel').forEach(c => c.addEventListener('change', () => {
        const box = l.open[c.getAttribute('data-key')];
        if (box) { box.sel[c.getAttribute('data-src')] = c.checked; this.render(); }
      }));
      const qi = root.querySelector('#khz-ln-q');
      if (qi) qi.addEventListener('input', () => {
        l.q = qi.value;
        const pos = qi.selectionStart;
        this.render();
        const again = this.root.querySelector('#khz-ln-q');
        if (again) { again.focus(); try { again.setSelectionRange(pos, pos); } catch (e) {} }
      });
      const save = root.querySelector('#khz-ln-save');
      if (save) save.addEventListener('click', () => this.saveLinkingSettings());
      const autoApply = root.querySelector('#khz-ln-auto-apply');
      if (autoApply) autoApply.addEventListener('change', () => {
        if (autoApply.checked && !window.confirm('با این گزینه لینک‌ها بدون بازبینی در مقاله‌های منتشرشده اضافه می‌شوند. مطمئن هستید؟')) autoApply.checked = false;
      });
    },
  };

  window.KhazanLinking = { methods: methods };
})();
