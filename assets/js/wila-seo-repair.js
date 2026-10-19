/* Selective repairs: text patches plus SEO title / meta description suggestions as checkable cards. */
(function () {
  'use strict';
  const cfg = window.wilaRepair || {};
  const escapeText = text => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

  function segments(html) {
    const parts = [];
    // Treat tags, comments, shortcodes and executable/embedded regions as immutable.
    const tokens = /<!--[\s\S]*?-->|<(script|style|pre|code|a|textarea|svg)\b[^>]*>[\s\S]*?<\/\1\s*>|\[[^\]]*\]|<[^>]*>|([^<\[]+)/gi;
    let match;
    while ((match = tokens.exec(html))) {
      if (!match[2] || !match[2].trim()) continue;
      const decoder = document.createElement('textarea');
      decoder.innerHTML = match[2];
      if (decoder.value.trim().length < 15) continue;
      parts.push({ start: match.index, end: tokens.lastIndex, text: decoder.value });
    }
    return parts;
  }

  function editor() {
    if (cfg.kind === 'post' && window.wp && wp.data) {
      const store = wp.data.select('core/block-editor');
      if (store && document.querySelector('.block-editor')) {
        const flatten = blocks => blocks.flatMap(block => [block, ...flatten(block.innerBlocks || [])]);
        return {
          read: () => flatten(store.getBlocks()).filter(b => ['core/paragraph', 'core/heading'].includes(b.name) && typeof b.attributes.content === 'string').map(b => ({ id: b.clientId, html: b.attributes.content })),
          write: rows => rows.forEach(row => wp.data.dispatch('core/block-editor').updateBlockAttributes(row.id, { content: row.html }))
        };
      }
    }
    const id = cfg.kind === 'term' ? 'description' : 'content';
    const field = document.getElementById(id);
    if (!field) throw new Error('ویرایشگر پشتیبانی‌شده پیدا نشد. در محصول از ویرایشگر کلاسیک استفاده کنید.');
    const visual = () => window.tinymce && tinymce.get(id) && !tinymce.get(id).isHidden() ? tinymce.get(id) : null;
    return {
      read: () => [{ id, html: visual() ? visual().getContent() : field.value }],
      write: rows => {
        if (visual()) { visual().undoManager.transact(() => visual().setContent(rows[0].html)); visual().save(); visual().fire('change'); }
        else { field.value = rows[0].html; field.dispatchEvent(new Event('input', { bubbles: true })); field.dispatchEvent(new Event('change', { bubbles: true })); }
      }
    };
  }

  // SEO metadata fields: Yoast hidden inputs (post / term) with Rank Math as a fallback.
  const META_IDS = {
    title: cfg.kind === 'term' ? ['hidden_wpseo_title', 'wpseo_title'] : ['yoast_wpseo_title', 'rank_math_title'],
    desc: cfg.kind === 'term' ? ['hidden_wpseo_desc', 'wpseo_desc'] : ['yoast_wpseo_metadesc', 'rank_math_description'],
    focuskw: cfg.kind === 'term' ? ['hidden_wpseo_focuskw', 'wpseo_focuskw'] : ['yoast_wpseo_focuskw', 'yoast_wpseo_focuskw_text_input', 'rank_math_focus_keyword']
  };
  const metaFields = key => META_IDS[key].map(id => document.getElementById(id)).filter(Boolean);
  const readMeta = () => {
    const meta = {};
    Object.keys(META_IDS).forEach(key => { const f = metaFields(key)[0]; meta[key] = f ? String(f.value || '').trim() : ''; });
    return meta;
  };
  function writeMeta(key, value) {
    metaFields(key).forEach(f => { f.value = value; f.dispatchEvent(new Event('input', { bubbles: true })); f.dispatchEvent(new Event('change', { bubbles: true })); });
    try { // Keep the Yoast React store (snippet editor) in sync when present.
      const store = window.wp && wp.data && wp.data.dispatch('yoast-seo/editor');
      if (store) {
        if (key === 'title' && store.setSeoTitle) store.setSeoTitle(value);
        if (key === 'desc' && store.setMetaDescription) store.setMetaDescription(value);
      }
    } catch (e) { /* hidden inputs already updated */ }
  }

  const state = { snapshot: null, mapping: [], patches: [], metaPatches: [], backup: null, applied: null, metaBackup: null };
  const panel = () => document.getElementById('wila-repair-panel');
  const q = sel => panel().querySelector(sel);

  function setStatus(text) { const s = q('#wila-repair-status'); s.textContent = text || ''; }
  function showError(message) {
    const results = q('#wila-repair-results');
    results.replaceChildren();
    const box = document.createElement('div'); box.className = 'wila-r-error'; box.setAttribute('role', 'alert'); box.textContent = message;
    results.append(box); setStatus('');
  }
  function setLoading(on) {
    const p = panel(); p.classList.toggle('is-loading', on);
    q('#wila-repair-analyze').disabled = on;
    q('#wila-repair-analyze .wila-r-label').textContent = on ? 'در حال تحلیل با هوش مصنوعی...' : 'شروع بررسی و پیشنهاد اصلاح';
    if (on) q('#wila-repair-apply').disabled = true;
  }
  function card(title, reason, before, after, kind, index, isMeta) {
    const label = document.createElement('label'); label.className = 'wila-r-card' + (isMeta ? ' is-meta' : '');
    const check = document.createElement('input'); check.type = 'checkbox'; check.dataset.kind = kind; check.value = index;
    const head = document.createElement('strong'); head.append(check, document.createTextNode(' ' + title));
    const b = document.createElement('div'); b.className = 'wila-r-before'; b.textContent = before || '(خالی)';
    const a = document.createElement('div'); a.className = 'wila-r-after'; a.textContent = after;
    const r = document.createElement('div'); r.className = 'wila-r-reason'; r.textContent = reason || '';
    label.append(head, b, a, r);
    return label;
  }
  const friendlyError = (data, status) => {
    if (data && data.message) return data.message;
    if (status === 401 || status === 403) return 'نشست شما منقضی شده یا دسترسی ندارید؛ صفحه را تازه‌سازی کنید.';
    if (status === 402) return 'اعتبار حساب هوش مصنوعی کافی نیست.';
    return 'بررسی ناموفق بود.';
  };

  async function analyze() {
    const results = q('#wila-repair-results');
    results.replaceChildren(); setLoading(true);
    setStatus('در حال تحلیل؛ متن و متای اصلی هنوز تغییر نکرده است.');
    try {
      state.snapshot = editor().read();
      state.mapping = state.snapshot.flatMap((row, rowIndex) => segments(row.html).map(part => ({ ...part, rowIndex })));
      if (state.mapping.length > 300) throw new Error('متن بیش از ۳۰۰ قطعه دارد؛ ابتدا آن را کوتاه‌تر کنید.');
      const meta = readMeta();
      if (!state.mapping.length && !meta.title && !meta.desc) throw new Error('متن یا متای قابل بررسی پیدا نشد. در ویرایشگر بلوکی فقط پاراگراف و هدینگ استاندارد پشتیبانی می‌شوند.');
      let response;
      try {
        response = await fetch(cfg.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }, body: JSON.stringify({ id: cfg.id, kind: cfg.kind, instructions: q('textarea').value, meta, segments: state.mapping.map(p => p.text) }) });
      } catch (e) { throw new Error('اتصال به سرور برقرار نشد؛ اینترنت را بررسی و دوباره تلاش کنید.'); }
      let data = null;
      try { data = await response.json(); } catch (e) { /* non-JSON error body */ }
      if (!response.ok || !data) throw new Error(friendlyError(data, response.status));
      if (!same(state.snapshot, editor().read())) throw new Error('متن هنگام بررسی تغییر کرد. دوباره بررسی کنید.');
      state.patches = Array.isArray(data.patches) ? data.patches : [];
      state.metaPatches = Array.isArray(data.meta_patches) ? data.meta_patches : [];
      if (Array.isArray(data.issues) && data.issues.length) {
        const box = document.createElement('div'); box.className = 'wila-r-issues';
        const h = document.createElement('strong'); h.textContent = 'مشکلات یافت‌شده در اسکن خودکار:';
        const ul = document.createElement('ul'); data.issues.forEach(t => { const li = document.createElement('li'); li.textContent = t; ul.append(li); });
        box.append(h, ul); results.append(box);
      }
      const names = { title: 'اصلاح عنوان سئو', desc: 'اصلاح توضیحات متا' };
      state.metaPatches.forEach((p, i) => results.append(card(names[p.field] || 'اصلاح متا', p.reason, p.before, p.after, 'meta', i, true)));
      state.patches.forEach((p, i) => results.append(card('اصلاح متن (قطعه ' + (p.index + 1) + ')', p.reason, p.before, p.after, 'text', i, false)));
      const total = state.patches.length + state.metaPatches.length;
      setStatus(total ? 'پیشنهادها را بررسی و موارد دلخواه را تیک بزنید. صحت ادعاها و حفظ معنا را تأیید کنید.' : 'پیشنهاد قابل اعمالی پیدا نشد؛ این نتیجه به معنای نبود همه خطاهای Yoast نیست.');
    } catch (error) { showError(error.message || 'خطای ناشناخته'); }
    finally { setLoading(false); }
  }

  function applyChecked() {
    try {
      const adapter = editor();
      if (!same(state.snapshot, adapter.read())) throw new Error('متن تغییر کرده است. برای جلوگیری از حذف ویرایش‌های جدید، دوباره بررسی کنید.');
      const checked = [...q('#wila-repair-results').querySelectorAll('input:checked')];
      const text = checked.filter(i => i.dataset.kind === 'text').map(i => state.patches[Number(i.value)]);
      const metas = checked.filter(i => i.dataset.kind === 'meta').map(i => state.metaPatches[Number(i.value)]);
      if (!text.length && !metas.length) return;
      if (text.length) {
        const rows = state.snapshot.map(row => ({ ...row }));
        text.sort((a, b) => b.index - a.index).forEach(patch => {
          const part = state.mapping[patch.index];
          if (!part || part.text !== patch.before) throw new Error('پیشنهاد با متن اصلی مطابقت ندارد.');
          const row = rows[part.rowIndex];
          row.html = row.html.slice(0, part.start) + escapeText(patch.after) + row.html.slice(part.end);
        });
        state.backup = state.snapshot;
        adapter.write(rows); state.applied = adapter.read();
      } else { state.backup = null; }
      const before = readMeta();
      state.metaBackup = metas.length ? before : null;
      metas.forEach(p => writeMeta(p.field, p.after));
      q('#wila-repair-undo').disabled = false; q('#wila-repair-apply').disabled = true; q('#wila-repair-results').replaceChildren();
      setStatus('فقط موارد انتخاب‌شده اعمال شدند. برای ذخیره، به‌روزرسانی وردپرس را بزنید؛ سپس تحلیل Yoast را دوباره بررسی کنید.');
    } catch (error) { showError(error.message); }
  }

  function undo() {
    try {
      if (state.backup) {
        const adapter = editor();
        if (!same(state.applied, adapter.read())) throw new Error('پس از اصلاح، متن تغییر کرده است؛ برای حفظ تغییرات از تاریخچه ویرایشگر استفاده کنید.');
        adapter.write(state.backup);
      }
      if (state.metaBackup) ['title', 'desc'].forEach(k => writeMeta(k, state.metaBackup[k] || ''));
      q('#wila-repair-undo').disabled = true; q('#wila-repair-apply').disabled = true; q('#wila-repair-results').replaceChildren();
      setStatus('مقادیر قبل از آخرین اصلاح بازگردانده شد؛ هنوز باید ذخیره کنید.');
    } catch (error) { showError(error.message); }
  }

  // Delegated on document: survives Gutenberg re-parenting / re-rendering the metabox DOM.
  document.addEventListener('click', event => {
    if (!event.target.closest) return;
    if (event.target.closest('#wila-repair-analyze')) { event.preventDefault(); analyze(); }
    else if (event.target.closest('#wila-repair-apply')) { event.preventDefault(); applyChecked(); }
    else if (event.target.closest('#wila-repair-undo')) { event.preventDefault(); undo(); }
  });
  document.addEventListener('change', event => {
    if (!panel() || !event.target.closest || !event.target.closest('#wila-repair-results')) return;
    q('#wila-repair-apply').disabled = !q('#wila-repair-results input:checked');
  });
})();
