/**
 * Khazan - Google Search Console tab: service-account connection and a view of the synced queries and pages.
 * Mixed into the dashboard app by wila-admin.js (methods run with `this` === app).
 */
(function () {
  'use strict';

  const { escapeHtml, toPersianDigits } = window.KhazanUtils;
  const fa = (n) => toPersianDigits(Math.round(Number(n) || 0));
  const pct = (n) => toPersianDigits((Number(n) * 100).toFixed(1)) + '٪';
  const pos = (n) => toPersianDigits(Number(n).toFixed(1));

  const short = (u) => { const p = String(u || '').replace(/^https?:\/\/[^/]+/, '') || '/'; try { return decodeURI(p); } catch (e) { return p; } };
  const KINDS = {
    near_top: { label: 'کمی تا صفحه‌ی اول', tone: 'green', hint: 'جایگاه ۵ تا ۲۰ گوگل است. با کمی کار به صفحه‌ی اول می‌رسد.' },
    low_ctr: { label: 'دیده می‌شود، کلیک نمی‌گیرد', tone: 'amber', hint: 'جایگاهش خوب است ولی کمتر کسی کلیک می‌کند. عنوان و توضیحات را بهتر کنید.' },
    deep: { label: 'خیلی پایین در گوگل', tone: 'blue', hint: 'صفحه‌ی ۲ تا ۸ نتایج است. مردم دنبالش می‌گردند ولی به آن نمی‌رسند.' },
    cannibal: { label: 'دو صفحه با هم رقابت می‌کنند', tone: 'red', hint: 'چند صفحه‌ی خودتان برای یک عبارت با هم رقابت می‌کنند و هیچ‌کدام بالا نمی‌آید.' }
  };


  const pathKey = (u) => String(u || '').replace(/^https?:\/\/(www\.)?/i, '').replace(/[?#].*$/, '').replace(/\/+$/, '').toLowerCase();

  /** What the site itself says about a card's address(es): redirected, gone, or fine. */
  const redirectInfo = (c, redirects) => {
    const pages = c.kind === 'cannibal' ? (c.competing || []).map(p => p.page) : [c.page];
    for (const u of pages) {
      const r = redirects[u];
      if (r && r.state === 'redirect') return { type: 'redirect', from: u, to: r.to, permanent: !!r.permanent, status: r.status };
    }
    for (const u of pages) {
      const r = redirects[u];
      if (r && r.state === 'gone') return { type: 'gone', from: u, status: r.status };
    }
    return null;
  };

  const methods = {
    loadSearch: async function () {
      const s = this.state.search;
      s.busy = 'load'; this.render();
      try {
        const r = await this.apiFetch('/search/data');
        if (!r.success) throw new Error(r.message || 'اطلاعات دریافت نشد.');
        s.data = r; s.error = '';
        if (r.connected && r.synced_at) { const o = await this.apiFetch('/search/opportunities'); if (o.success) s.opps = o; }
      } catch (e) { s.error = e.message; }
      s.busy = ''; this.render();
      this.checkRedirects();
    },

    checkRedirects: async function () {
      const s = this.state.search;
      const cards = (s.opps && s.opps.cards) || [];
      const urls = [];
      cards.forEach(c => { if (c.page) urls.push(c.page); (c.competing || []).forEach(p => urls.push(p.page)); });
      const todo = Array.from(new Set(urls)).filter(u => !(u in s.redirects));
      if (!todo.length || s.redirChecking) return;
      s.redirChecking = true; this.render();
      for (let i = 0; i < todo.length; i += 10) {
        try {
          const r = await this.apiFetch('/search/redirects', { method: 'POST', body: JSON.stringify({ pages: todo.slice(i, i + 10) }) });
          if (r.success) Object.assign(s.redirects, r.results);
        } catch (e) { /* the check is a bonus; cards stay as they are */ }
        todo.slice(i, i + 10).forEach(u => { if (!(u in s.redirects)) s.redirects[u] = { state: 'error' }; });
        this.render();
      }
      s.redirChecking = false; this.render();
    },

    connectSearch: async function () {
      const s = this.state.search;
      const key = (this.root.querySelector('#khz-gsc-key') || {}).value || s.keyDraft;
      const site = (this.root.querySelector('#khz-gsc-site') || {}).value || '';
      if (!key && !(s.data && s.data.has_key)) return this.toast('محتوای فایل JSON را بچسبانید.');
      s.keyDraft = key || ''; s.busy = 'connect'; s.error = ''; this.render();
      try {
        const r = await this.apiFetch('/search/connect', { method: 'POST', body: JSON.stringify({ key: s.keyDraft, site }) });
        if (!r.success) throw new Error(r.message || 'اتصال انجام نشد.');
        s.sites = r.sites || []; s.message = r.message || '';
        if (r.connected) { s.keyDraft = ''; await this.loadSearch(); this.toast('به سرچ کنسول وصل شد.'); return; }
      } catch (e) { s.error = e.message; }
      s.busy = ''; this.render();
    },

    syncSearch: async function () {
      const s = this.state.search;
      s.busy = 'sync'; s.error = ''; this.render();
      try {
        const r = await this.apiFetch('/search/sync', { method: 'POST', body: JSON.stringify({ days: 90 }) });
        if (!r.success) throw new Error(r.message || 'دریافت داده انجام نشد.');
        this.toast('داده‌های ۹۰ روز اخیر دریافت شد.');
      } catch (e) { s.error = e.message; }
      await this.loadSearch();
    },

    disconnectSearch: async function () {
      if (!window.confirm('اتصال و داده‌های ذخیره‌شده‌ی سرچ کنسول پاک شود؟')) return;
      const s = this.state.search;
      try { await this.apiFetch('/search/disconnect', { method: 'POST', body: '{}' }); s.sites = []; s.message = ''; s.error = ''; }
      catch (e) { s.error = e.message; }
      await this.loadSearch();
    },


    // ---- AI analysis and content update (side drawer) ----

    openAnalysis: async function (card) {
      const s = this.state.search;
      s.drawer = { page: card.page, title: card.page_name || card.title, kind: card.kind, diagnosis: card.diagnosis, analyzed: !!card.analyzed, loading: !!card.analyzed, result: null, backup: '', error: '', draft: null, busy: '', withMeta: true, focus: true, scroll: 0 };
      this.render();
      if (!card.analyzed) return;
      try {
        const r = await this.apiFetch('/search/analysis?page=' + encodeURIComponent(card.page));
        if (!r.success) throw new Error(r.message || 'تحلیل ذخیره‌شده خوانده نشد.');
        if (s.drawer && s.drawer.page === card.page) { s.drawer.result = r.result; s.drawer.backup = r.backup || ''; }
      } catch (e) { if (s.drawer) s.drawer.error = e.message; }
      if (s.drawer) s.drawer.loading = false;
      this.render();
    },

    closeAnalysis: function () {
      const s = this.state.search;
      if (!s.drawer || s.drawer.busy) return;
      const page = s.drawer.page;
      s.drawer = null;
      document.body.classList.remove('khz-drawer-lock');
      this.render();
      const back = this.root.querySelector(`[data-opp-ai="${CSS.escape(page)}"]`);
      if (back) back.focus();
    },

    runAnalysis: async function () {
      const s = this.state.search; const d = s.drawer;
      if (!d || d.busy) return;
      d.busy = 'analyze'; d.error = ''; d.draft = null; d.scroll = 0; this.render();
      try {
        const r = await this.apiFetch('/search/analyze', { method: 'POST', body: JSON.stringify({ page: d.page, opportunity: (KINDS[d.kind] || {}).label || '', diagnosis: d.diagnosis || '' }) });
        if (!r.success) throw new Error(r.message || 'تحلیل انجام نشد.');
        d.result = r.result; d.backup = r.backup || ''; d.analyzed = true;
        ((s.opps && s.opps.cards) || []).forEach(c => { if (c.page === d.page) c.analyzed = true; });
        this.toast('تحلیل صفحه آماده شد.');
      } catch (e) { d.error = e.message; }
      d.busy = ''; this.render();
    },

    buildUpdateDraft: async function () {
      const d = this.state.search.drawer;
      if (!d || d.busy) return;
      d.busy = 'draft'; d.error = ''; this.render();
      try {
        const r = await this.apiFetch('/search/update-draft', { method: 'POST', body: JSON.stringify({ page: d.page }) });
        if (!r.success) throw new Error(r.message || 'پیش‌نویس ساخته نشد.');
        d.draft = r.draft; d.withMeta = !!(r.draft.meta && r.draft.meta.title); d.scroll = 0;
      } catch (e) { d.error = e.message; }
      d.busy = ''; this.render();
    },

    applyUpdateDraft: async function () {
      const d = this.state.search.drawer;
      if (!d || d.busy || !d.draft) return;
      if (!window.confirm('محتوای پیشنهادی به صفحه اضافه شود؟ متن فعلی دست نمی‌خورد و یک نسخه‌ی پشتیبان هم ذخیره می‌شود.')) return;
      d.busy = 'apply'; d.error = ''; this.render();
      try {
        const r = await this.apiFetch('/search/update-apply', { method: 'POST', body: JSON.stringify({ draft_id: d.draft.draft_id, with_meta: d.withMeta ? '1' : '0' }) });
        if (!r.success) throw new Error(r.message || 'به‌روزرسانی انجام نشد.');
        d.applied = { words: r.words_after }; d.backup = 'now'; d.draft = null; d.scroll = 0;
        this.toast('صفحه به‌روز شد. نسخه‌ی قبلی برای بازگرداندن ذخیره شده است.');
      } catch (e) { d.error = e.message; }
      d.busy = ''; this.render();
    },

    restoreUpdate: async function () {
      const d = this.state.search.drawer;
      if (!d || d.busy || !d.result) return;
      if (!window.confirm('محتوای صفحه به نسخه‌ی قبل از آخرین به‌روزرسانی برگردد؟')) return;
      d.busy = 'restore'; d.error = ''; this.render();
      try {
        const r = await this.apiFetch('/search/update-restore', { method: 'POST', body: JSON.stringify({ kind: d.result.kind, type: d.result.type, id: d.result.id }) });
        if (!r.success) throw new Error(r.message || 'بازگرداندن انجام نشد.');
        d.backup = ''; d.applied = null;
        this.toast('نسخه‌ی قبلی صفحه برگردانده شد.');
      } catch (e) { d.error = e.message; }
      d.busy = ''; this.render();
    },

    drawerHtml: function () {
      const d = this.state.search.drawer;
      if (!d) return '';
      const a = d.result && d.result.analysis;
      const busyText = { analyze: 'هوش مصنوعی متن صفحه، ساختار و جست‌وجوهای واقعی گوگل را بررسی می‌کند؛ معمولاً کمتر از یک دقیقه…', draft: 'متن بخش‌های تازه طبق قواعد محتوای افزونه نوشته می‌شود؛ ممکن است یک تا دو دقیقه طول بکشد…', apply: 'در حال ذخیره روی صفحه…', restore: 'در حال بازگرداندن نسخه‌ی قبل…' };
      const list = (arr, fn) => arr && arr.length ? `<ul class="khz-dr-list">${arr.map(fn).join('')}</ul>` : '';
      const sec = (title, body, count) => body ? `<section class="khz-dr-sec"><h4>${title}${count ? ` <span class="khz-dr-count">${fa(count)}</span>` : ''}</h4>${body}</section>` : '';
      let body = '';
      let foot = '';

      if (d.loading) {
        body = '<div class="khz-dr-skel" aria-hidden="true"><i></i><i></i><i></i><i></i></div><p class="khz-dr-muted" role="status">تحلیل ذخیره‌شده خوانده می‌شود…</p>';
      } else if (d.busy && busyText[d.busy]) {
        body = `<div class="khz-dr-busy" role="status"><span class="khz-dr-spin" aria-hidden="true"></span><p>${busyText[d.busy]}</p></div>`;
      } else if (d.draft) {
        const r = d.draft; const m = r.meta || {};
        body = `
          <div class="khz-dr-stats">
            <div><b>${fa(r.words_before)}</b><span>کلمه‌ی فعلی</span></div>
            <div><b>${fa(r.words_after)}</b><span>بعد از به‌روزرسانی</span></div>
            <div><b>+${fa(Math.max(0, r.words_after - r.words_before))}</b><span>کلمه‌ی تازه</span></div>
          </div>
          <p class="khz-dr-note">متن فعلی صفحه دست نمی‌خورد. فقط بخش‌های زیر در جای مشخص‌شده اضافه می‌شوند و قبل از ذخیره، یک نسخه‌ی پشتیبان گرفته می‌شود.</p>
          ${sec('بخش‌هایی که اضافه می‌شود', (r.placements || []).map(p => `<article class="khz-dr-place"><div class="khz-dr-where">${escapeHtml(p.where)}</div><div class="khz-dr-preview">${p.html}</div></article>`).join(''), (r.placements || []).length)}
          ${m.title ? sec('عنوان و توضیحات متا', `
            <div class="khz-dr-meta">
              <div><span class="khz-dr-lbl">فعلی</span><b>${escapeHtml(m.current_title || '—')}</b><p>${escapeHtml(m.current_description || '—')}</p></div>
              <div class="is-new"><span class="khz-dr-lbl">پیشنهادی</span><b>${escapeHtml(m.title)}</b><p>${escapeHtml(m.description)}</p></div>
            </div>
            <label class="khz-dr-check"><input type="checkbox" id="khz-dr-meta" ${d.withMeta ? 'checked' : ''}> عنوان و توضیحات متا هم عوض شود</label>`) : ''}`;
        foot = `<button type="button" class="wila-btn wila-btn-primary" id="khz-dr-apply">اعمال روی صفحه</button><button type="button" class="wila-btn wila-btn-outline" id="khz-dr-back">بازگشت به تحلیل</button>`;
      } else if (!a) {
        body = `
          <div class="khz-dr-intro">
            <p>هوش مصنوعی متن کامل این صفحه، ساختار آن (تیترها، تصویر، ویدئو، جدول، پرسش‌های متداول و لینک‌ها) و عبارت‌هایی را که واقعاً در گوگل با آن دیده می‌شود بررسی می‌کند و می‌گوید:</p>
            <ul class="khz-dr-bullets">
              <li>کدام بخش‌ها خوب است و باید بماند</li>
              <li>چه بخش‌ها و پرسش‌هایی کم است و باید اضافه شود</li>
              <li>کجا تصویر، ویدئو، صوت، جدول یا اینفوگرافیک لازم است</li>
              <li>عنوان و توضیحات متا، لینک داخلی و نیاز به بک‌لینک</li>
            </ul>
            <p class="khz-dr-muted">هر تحلیل یک درخواست به AvalAI است و از اعتبار شما کم می‌کند. چیزی روی صفحه ذخیره نمی‌شود.</p>
          </div>`;
        foot = `<button type="button" class="wila-btn wila-btn-primary" id="khz-dr-analyze">شروع تحلیل</button>`;
      } else {
        const f = d.result.facts || {};
        const STATE = { empty: ['بدون محتوا', 'red'], thin: ['محتوای کم', 'amber'], good: ['محتوای خوب', 'green'] };
        const st = STATE[a.content_state] || STATE.thin;
        const MEDIA = { image: 'تصویر', video: 'ویدئو', audio: 'صوت', table: 'جدول', infographic: 'اینفوگرافیک', gallery: 'گالری', comparison: 'مقایسه' };
        const chip = (n, label) => `<span class="khz-dr-fact"><b>${fa(n)}</b> ${label}</span>`;
        body = `
          ${d.applied ? `<div class="khz-dr-ok" role="status">صفحه به‌روز شد و حالا ${fa(d.applied.words)} کلمه دارد. برای نتیجه‌ی دقیق، چند هفته بعد داده‌های سرچ کنسول را دوباره دریافت و صفحه را دوباره تحلیل کنید.</div>` : ''}
          <div class="khz-dr-summary">
            <span class="khz-dr-state tone-${st[1]}">${st[0]}</span>
            <p>${escapeHtml(a.summary)}</p>
          </div>
          <div class="khz-dr-facts">
            ${chip(f.words, 'کلمه')}${chip(f.h2, 'تیتر H2')}${chip(f.images, 'تصویر')}${chip(f.videos, 'ویدئو')}${chip(f.tables, 'جدول')}${chip(f.faq, 'پرسش')}${chip(f.links_internal, 'لینک داخلی')}
          </div>
          ${a.steps.length ? sec('برنامه‌ی کار به ترتیب', `<ol class="khz-dr-steps">${a.steps.map(x => `<li>${escapeHtml(x)}</li>`).join('')}</ol>`) : ''}
          ${sec('بخش‌هایی که باید اضافه شود', list(a.add_sections, x => `<li><b>${escapeHtml(x.heading)}</b><span>${escapeHtml(x.purpose)} · حدود ${fa(x.words)} کلمه</span>${x.covers_queries.length ? `<div class="khz-dr-qs">${x.covers_queries.map(q => `<i>${escapeHtml(q)}</i>`).join('')}</div>` : ''}</li>`), a.add_sections.length)}
          ${sec('پرسش‌های متداول پیشنهادی', list(a.faq, x => `<li><b>${escapeHtml(x.question)}</b>${x.why ? `<span>${escapeHtml(x.why)}</span>` : ''}</li>`), a.faq.length)}
          ${sec('تصویر، ویدئو و رسانه', list(a.media, x => `<li><span class="khz-dr-mtype">${MEDIA[x.type] || escapeHtml(x.type)}</span><b>${escapeHtml(x.what)}</b><span>${escapeHtml(x.where)}${x.alt ? ` · متن جایگزین: «${escapeHtml(x.alt)}»` : ''}</span></li>`), a.media.length)}
          ${sec('بهبود متن فعلی', list(a.improve, x => `<li><b>${escapeHtml(x.where)}</b><span>${escapeHtml(x.what)}</span></li>`), a.improve.length)}
          ${a.meta.title ? sec('عنوان و توضیحات متا', `
            <div class="khz-dr-meta">
              <div><span class="khz-dr-lbl">فعلی</span><b>${escapeHtml(d.result.seo.title || '—')}</b><p>${escapeHtml(d.result.seo.description || '—')}</p></div>
              <div class="is-new"><span class="khz-dr-lbl">پیشنهادی</span><b>${escapeHtml(a.meta.title)}</b><p>${escapeHtml(a.meta.description)}</p></div>
            </div>`) : ''}
          ${sec('لینک داخلی', list(a.internal_links, x => `<li><b>«${escapeHtml(x.anchor)}»</b><span>${escapeHtml(x.to)}</span></li>`), a.internal_links.length)}
          ${a.offpage.needed ? sec('بک‌لینک و رپورتاژ', `<p class="khz-dr-p">${escapeHtml(a.offpage.why)}</p>${a.offpage.anchors.length ? `<div class="khz-dr-qs">${a.offpage.anchors.map(q => `<i>${escapeHtml(q)}</i>`).join('')}</div>` : ''}`) : ''}
          ${sec('بخش‌هایی که باید بماند', list(a.keep, x => `<li><span>${escapeHtml(x)}</span></li>`))}
          <p class="khz-dr-muted">تحلیل در ${toPersianDigits(escapeHtml(d.result.at))} با مدل <span dir="ltr">${escapeHtml(d.result.model)}</span>.</p>`;
        const empty = a.content_state === 'empty';
        foot = `
          <button type="button" class="wila-btn wila-btn-primary" id="khz-dr-draft">${empty ? 'نوشتن محتوای کامل' : 'ساخت پیش‌نویس به‌روزرسانی'}</button>
          <button type="button" class="wila-btn wila-btn-outline" id="khz-dr-analyze">تحلیل دوباره</button>
          ${d.backup ? `<button type="button" class="wila-btn wila-btn-outline khz-dr-restore" id="khz-dr-restore">بازگرداندن نسخه‌ی قبل</button>` : ''}`;
      }
      if (d.busy) foot = foot.replace(/<button /g, '<button disabled ');
      return `
        <div class="khz-dr-backdrop" id="khz-dr-backdrop"></div>
        <aside class="khz-drawer" role="dialog" aria-modal="true" aria-labelledby="khz-dr-title">
          <header class="khz-dr-head">
            <div>
              <span class="khz-dr-kicker">${d.draft ? 'پیش‌نمایش به‌روزرسانی' : 'تحلیل با هوش مصنوعی'}</span>
              <h3 id="khz-dr-title">${escapeHtml((d.result && d.result.title) || d.title)}</h3>
              <a class="khz-dr-url" dir="ltr" href="${escapeHtml(d.page)}" target="_blank" rel="noopener">${escapeHtml(short(d.page))}</a>
            </div>
            <button type="button" class="khz-dr-close" id="khz-dr-close" aria-label="بستن" ${d.busy ? 'disabled' : ''}>✕</button>
          </header>
          <div class="khz-dr-body" id="khz-dr-body">
            ${d.error ? `<div class="khz-dr-err" role="alert">${escapeHtml(d.error)}</div>` : ''}
            ${body}
          </div>
          ${foot ? `<footer class="khz-dr-foot">${foot}</footer>` : ''}
        </aside>`;
    },

    viewSearch: function () {
      const s = this.state.search;
      const d = s.data || {};
      const busy = !!s.busy;
      const btn = (id, label, kind, cls) => `<button class="wila-btn ${cls || 'wila-btn-primary'}" id="${id}" ${busy ? 'disabled' : ''}>${s.busy === kind ? 'در حال انجام…' : label}</button>`;
      const table = (rows, name, title) => `
        <h4 style="margin:16px 0 6px">${title}</h4>
        <div style="overflow-x:auto"><table class="wila-table" style="width:100%;font-size:0.82rem"><thead><tr><th>${name === 'query' ? 'عبارت' : 'صفحه'}</th><th>کلیک</th><th>نمایش</th><th>CTR</th><th>جایگاه</th></tr></thead><tbody>
        ${(rows || []).slice(0, 20).map(r => `<tr><td style="max-width:360px;overflow:hidden;text-overflow:ellipsis" ${name === 'page' ? 'dir="ltr"' : ''}>${name === 'page' ? `<a class="khz-opp-link" href="${escapeHtml(r[name])}" target="_blank" rel="noopener">${escapeHtml(r[name])}</a>` : escapeHtml(r[name])}</td><td>${fa(r.clicks)}</td><td>${fa(r.impressions)}</td><td>${pct(r.ctr)}</td><td>${pos(r.position)}</td></tr>`).join('') || '<tr><td colspan="5">هنوز داده‌ای دریافت نشده است.</td></tr>'}
        </tbody></table></div>`;
      const sites = (s.sites || []).length && !d.connected ? `
        <label style="display:block;margin:10px 0 4px">سایت را انتخاب کنید</label>
        <select id="khz-gsc-site" class="wila-select" style="max-width:420px" dir="ltr">${s.sites.map(x => `<option value="${escapeHtml(x.url)}">${escapeHtml(x.url)}</option>`).join('')}</select>` : '';

      const o = s.opps;
      const ACTIONS = {
        content: { label: 'محتوا', icon: '✎' },
        links: { label: 'لینک‌سازی', icon: '↔' },
        meta: { label: 'عنوان و متا', icon: '⌘' },
        backlink: { label: 'ریپورتاژ و بک‌لینک', icon: '↗' },
        merge: { label: 'ادغام', icon: '⇄' }
      };
      const filter = s.filter || 'all';
      const cards0 = o ? o.cards.filter(c => filter === 'all' || c.kind === filter) : [];
      const resolvedCount = o ? o.cards.filter(c => redirectInfo(c, s.redirects || {}) && redirectInfo(c, s.redirects || {}).type === 'redirect').length : 0;
      const cards = cards0.filter(c => !(redirectInfo(c, s.redirects || {}) && redirectInfo(c, s.redirects || {}).type === 'redirect')).concat(cards0.filter(c => redirectInfo(c, s.redirects || {}) && redirectInfo(c, s.redirects || {}).type === 'redirect'));
      const tile = (key, label, n) => `<button type="button" class="khz-opp-tile ${filter === key ? 'is-active' : ''}" data-opp-filter="${key}"><span class="khz-opp-tile-n">${fa(n)}</span><span class="khz-opp-tile-l">${label}</span></button>`;
      const redirects = s.redirects || {};
      const info = (c) => redirectInfo(c, redirects);
      const isResolved = (c) => { const i = info(c); return !!(i && i.type === 'redirect'); };
      const notice = (c) => {
        const i = info(c);
        if (!i) return '';
        if (i.type === 'gone') return `<div class="khz-opp-note is-warn"><b>این آدرس دیگر وجود ندارد (کد ${fa(i.status)}).</b> اگر صفحه‌ی جایگزین دارد، با ریدایرکت ۳۰۱ به آن بفرستید.</div>`;
        const same = c.kind === 'cannibal' && (c.competing || []).some(p => p.page !== i.from && pathKey(p.page) === pathKey(i.to));
        return `<div class="khz-opp-note ${i.permanent ? 'is-ok' : 'is-warn'}"><b>${i.permanent ? 'این مورد حل‌شده است.' : 'ریدایرکت موقت است.'}</b> آدرس <span dir="ltr">${escapeHtml(short(i.from))}</span> با ریدایرکت ${fa(i.status)} به <span dir="ltr">${escapeHtml(short(i.to))}</span> می‌رود${same ? '، یعنی همان صفحه‌ی دیگر' : ''}. ${i.permanent ? 'سرچ کنسول هنوز آدرس قدیمی را گزارش می‌کند و بعد از چند هفته خودش حذفش می‌کند؛ کاری لازم نیست.' : 'برای گوگل بهتر است ریدایرکت دائمی (۳۰۱) باشد.'}</div>`;
      };
      const card = (c) => {
        const k = KINDS[c.kind];
        const done = isResolved(c);
        return `
        <article class="khz-opp-card khz-tone-${k.tone} ${done ? 'is-resolved' : ''}">
          <header class="khz-opp-head">
            <span class="khz-opp-badge" title="${escapeHtml(k.hint)}">${k.label}</span>
            ${c.potential ? `<span class="khz-opp-gain" title="برآورد کلیک اضافه در ۹۰ روز، اگر برنامه اجرا شود">+${fa(c.potential)} کلیک</span>` : ''}
          </header>
          ${c.kind === 'cannibal' ? `<h4 class="khz-opp-title">${escapeHtml(c.title)}</h4><div class="khz-opp-q">عبارت جست‌وجو</div>` : `
            <h4 class="khz-opp-title"><a class="khz-opp-link" href="${escapeHtml(c.page)}" target="_blank" rel="noopener">${escapeHtml(c.page_name || short(c.title))}<span class="khz-opp-ext" aria-hidden="true">↗</span></a></h4>
            <div class="khz-opp-sub2">${c.page_type ? `<span class="khz-opp-type">${escapeHtml(c.page_type)}</span>` : ''}<span class="khz-opp-url" dir="ltr">${escapeHtml(short(c.page))}</span></div>
            ${c.kind === 'near_top' && c.title && c.title !== c.page ? `<div class="khz-opp-q">عبارت اصلی: <b>${escapeHtml(c.title)}</b></div>` : ''}`}
          <div class="khz-opp-metrics">
            <span><b>${pos(c.position)}</b> جایگاه</span>
            <span><b>${fa(c.impressions)}</b> نمایش</span>
            ${c.kind !== 'cannibal' ? `<span><b>${fa(c.clicks)}</b> کلیک</span><span><b>${pct(c.ctr)}</b> CTR</span>` : ''}
          </div>
          <p class="khz-opp-diag">${escapeHtml(c.diagnosis)}</p>
          ${c.competing ? `<div class="khz-opp-compete">${c.competing.map(p => `<div><a class="khz-opp-link" href="${escapeHtml(p.page)}" target="_blank" rel="noopener" dir="ltr">${escapeHtml(short(p.page))}</a><i>جایگاه ${pos(p.position)}</i></div>`).join('')}</div>` : ''}
          ${notice(c)}
          <ul class="khz-opp-actions" ${done ? 'hidden' : ''}>
            ${c.actions.map(a => `<li><span class="khz-opp-ico khz-act-${a.type}">${ACTIONS[a.type].icon}</span><div><b>${escapeHtml(a.label)}</b><span>${escapeHtml(a.detail)}</span></div></li>`).join('')}
          </ul>
          ${c.page && !done ? `<footer class="khz-opp-foot"><button type="button" class="khz-opp-ai ${c.analyzed ? 'is-done' : ''}" data-opp-ai="${escapeHtml(c.page)}">${c.analyzed ? 'دیدن تحلیل هوش مصنوعی' : 'تحلیل با هوش مصنوعی'}</button></footer>` : ''}
        </article>`;
      };
      const opps = o ? `
        <div class="wila-card">
          <h3>فرصت‌ها و کارهای پیشنهادی</h3>
          <p class="khz-opp-sub">فرصت‌ها بر اساس بیشترین کلیک قابل‌کسب مرتب شده‌اند. برآورد کلیک تقریبی است و فقط برای اولویت‌بندی به کار می‌آید.</p>
          <div class="khz-opp-tiles">
            ${tile('all', 'همه', o.cards.length)}
            ${Object.keys(KINDS).map(k => tile(k, KINDS[k].label, (o.counts || {})[k] || 0)).join('')}
          </div>
          <ul class="khz-opp-legend">${Object.keys(KINDS).map(k => `<li class="khz-tone-${KINDS[k].tone}"><b>${KINDS[k].label}:</b> ${KINDS[k].hint}</li>`).join('')}</ul>
          ${s.redirChecking ? '<p class="khz-opp-check" role="status">در حال بررسی ریدایرکت آدرس‌ها…</p>' : (resolvedCount ? `<p class="khz-opp-check">${fa(resolvedCount)} مورد در واقع ریدایرکت‌شده است و حل‌شده حساب می‌شود؛ پایین فهرست آمده‌اند.</p>` : '')}
          ${o.potential ? `<div class="khz-opp-total">اگر همه‌ی برنامه‌ها اجرا شود، حدود <b>${fa(o.potential)}</b> کلیک اضافه در هر ۹۰ روز</div>` : ''}
          <div class="khz-opp-grid">${cards.length ? cards.map(card).join('') : '<p>موردی در این گروه نیست.</p>'}</div>
        </div>` : '';
      return `
        ${s.error ? `<div class="wila-card" style="border-color:#fecaca;color:#991b1b">${escapeHtml(s.error)}</div>` : ''}
        <div class="wila-card">
          <h3>اتصال به Google Search Console</h3>
          ${d.connected ? `
            <p>وصل است به <b dir="ltr">${escapeHtml(d.site)}</b> با حساب <span dir="ltr">${escapeHtml(d.email)}</span>.
              ${d.synced_at ? `آخرین دریافت: ${toPersianDigits(escapeHtml(d.synced_at))} (از ${toPersianDigits(d.range.start)} تا ${toPersianDigits(d.range.end)}؛ ${fa(d.counts.queries)} عبارت و ${fa(d.counts.pages)} صفحه).` : 'هنوز داده‌ای دریافت نشده است.'}</p>
            ${btn('khz-gsc-sync', 'دریافت داده‌های ۹۰ روز اخیر', 'sync')} ${btn('khz-gsc-disconnect', 'قطع اتصال', 'disconnect', 'wila-btn-outline')}
          ` : `
            <ol style="font-size:0.85rem;line-height:2">
              <li>در Google Cloud یک پروژه بسازید و «Google Search Console API» را فعال کنید.</li>
              <li>یک Service Account بسازید و کلید JSON آن را دانلود کنید.</li>
              <li>در سرچ کنسول، بخش Settings ← Users and permissions، ایمیل Service Account را با دسترسی Restricted (فقط‌خواندنی) اضافه کنید.</li>
              <li>محتوای فایل JSON را اینجا بچسبانید.</li>
            </ol>
            <textarea id="khz-gsc-key" class="wila-input" rows="6" dir="ltr" style="width:100%" placeholder='{"type":"service_account", ...}'>${escapeHtml(s.keyDraft || '')}</textarea>
            ${sites}
            ${s.message ? `<p style="color:#b45309">${escapeHtml(s.message)}</p>` : ''}
            <div style="margin-top:10px">${btn('khz-gsc-connect', 'اتصال', 'connect')}</div>
          `}
          <p style="font-size:0.8rem;color:#6b7280;margin-top:10px">دسترسی فقط‌خواندنی است. کلید روی سرور خودِ سایت ذخیره می‌شود و برای هیچ سرویسی جز گوگل فرستاده نمی‌شود.</p>
        </div>
        ${d.connected ? opps : ''}
        ${d.connected ? `<div class="wila-card">${table(d.queries, 'query', 'پرنمایش‌ترین عبارت‌ها')}${table(d.pages, 'page', 'پرنمایش‌ترین صفحه‌ها')}</div>` : ''}
        ${this.drawerHtml()}
      `;
    },

    bindSearchEvents: function () {
      const on = (s, fn) => { const el = this.root.querySelector(s); if (el) el.addEventListener('click', fn); };
      on('#khz-gsc-connect', () => this.connectSearch());
      on('#khz-gsc-sync', () => this.syncSearch());
      on('#khz-gsc-disconnect', () => this.disconnectSearch());
      this.root.querySelectorAll('[data-opp-filter]').forEach(b => b.addEventListener('click', () => { this.state.search.filter = b.getAttribute('data-opp-filter'); this.render(); }));
      this.root.querySelectorAll('[data-opp-ai]').forEach(b => b.addEventListener('click', () => {
        const page = b.getAttribute('data-opp-ai');
        const card = ((this.state.search.opps && this.state.search.opps.cards) || []).find(c => c.page === page);
        if (card) this.openAnalysis(card);
      }));
      const dr = this.state.search.drawer;
      document.body.classList.toggle('khz-drawer-lock', !!dr);
      if (dr) {
        on('#khz-dr-close', () => this.closeAnalysis());
        on('#khz-dr-backdrop', () => this.closeAnalysis());
        on('#khz-dr-analyze', () => this.runAnalysis());
        on('#khz-dr-draft', () => this.buildUpdateDraft());
        on('#khz-dr-apply', () => this.applyUpdateDraft());
        on('#khz-dr-restore', () => this.restoreUpdate());
        on('#khz-dr-back', () => { dr.draft = null; dr.scroll = 0; this.render(); });
        const meta = this.root.querySelector('#khz-dr-meta');
        if (meta) meta.addEventListener('change', e => { dr.withMeta = e.target.checked; });
        const bodyEl = this.root.querySelector('#khz-dr-body');
        if (bodyEl) { bodyEl.scrollTop = dr.scroll || 0; bodyEl.addEventListener('scroll', () => { dr.scroll = bodyEl.scrollTop; }, { passive: true }); }
        const drawer = this.root.querySelector('.khz-drawer');
        if (dr.focus) { dr.focus = false; const c = this.root.querySelector('#khz-dr-close'); if (c) c.focus(); }
        // keep Tab inside the drawer while it is open
        drawer.addEventListener('keydown', e => {
          if (e.key !== 'Tab') return;
          const f = Array.from(drawer.querySelectorAll('button:not([disabled]), a[href], input:not([disabled])'));
          if (!f.length) return;
          if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
          else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
        });
      }
      if (!this._khzEsc) {
        this._khzEsc = true;
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && this.state.search.drawer) this.closeAnalysis(); });
      }
      const ta = this.root.querySelector('#khz-gsc-key');
      if (ta) ta.addEventListener('input', e => { this.state.search.keyDraft = e.target.value; });
    },
  };

  window.KhazanSearch = { methods: methods };
})();
