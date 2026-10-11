/* Selective repairs: preserve markup and reject stale editor snapshots. */
(function () {
  'use strict';
  const escapeText = text => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
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
    if (wilaRepair.kind === 'post' && window.wp && wp.data) {
      const store = wp.data.select('core/block-editor');
      if (store && document.querySelector('.block-editor')) {
        const flatten = blocks => blocks.flatMap(block => [block, ...flatten(block.innerBlocks || [])]);
        return {
          read: () => flatten(store.getBlocks()).filter(b => ['core/paragraph', 'core/heading'].includes(b.name) && typeof b.attributes.content === 'string').map(b => ({ id: b.clientId, html: b.attributes.content })),
          write: rows => rows.forEach(row => wp.data.dispatch('core/block-editor').updateBlockAttributes(row.id, { content: row.html }))
        };
      }
    }
    const id = wilaRepair.kind === 'term' ? 'description' : 'content';
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

  function init() {
    const panel = document.getElementById('wila-repair-panel');
    if (!panel || panel.dataset.ready) return;
    panel.dataset.ready = '1';
    const analyze = panel.querySelector('#wila-repair-analyze');
    const apply = panel.querySelector('#wila-repair-apply');
    const undo = panel.querySelector('#wila-repair-undo');
    const results = panel.querySelector('#wila-repair-results');
    const status = panel.querySelector('#wila-repair-status');
    let snapshot, mapping, patches = [], backup, applied;
    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
    analyze.addEventListener('click', async () => {
      analyze.disabled = true; apply.disabled = true; results.replaceChildren();
      status.textContent = 'در حال بررسی؛ متن اصلی هنوز تغییر نکرده است.';
      try {
        snapshot = editor().read();
        mapping = snapshot.flatMap((row, rowIndex) => segments(row.html).map(part => ({ ...part, rowIndex })));
        if (!mapping.length) throw new Error('متن قابل بررسی پیدا نشد. در ویرایشگر بلوکی فقط پاراگراف و هدینگ استاندارد پشتیبانی می‌شوند.');
        if (mapping.length > 300) throw new Error('متن بیش از ۳۰۰ قطعه دارد؛ ابتدا آن را کوتاه‌تر کنید.');
        const response = await fetch(wilaRepair.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wilaRepair.nonce }, body: JSON.stringify({ id: wilaRepair.id, kind: wilaRepair.kind, instructions: panel.querySelector('textarea').value, segments: mapping.map(p => p.text) }) });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'بررسی ناموفق بود.');
        if (!same(snapshot, editor().read())) throw new Error('متن هنگام بررسی تغییر کرد. دوباره بررسی کنید.');
        patches = Array.isArray(data.patches) ? data.patches : [];
        patches.forEach((patch, index) => {
          const label = document.createElement('label');
          label.style.cssText = 'display:block;border:1px solid #ccc;padding:12px;margin:10px 0;white-space:pre-wrap';
          const check = document.createElement('input'); check.type = 'checkbox'; check.value = index;
          label.append(check, document.createTextNode(' ' + patch.reason + '\nقبل: ' + patch.before + '\nبعد: ' + patch.after));
          results.append(label);
        });
        status.textContent = patches.length ? 'پیشنهادها را بررسی و موارد دلخواه را تیک بزنید. صحت ادعاها و حفظ معنا را تأیید کنید.' : 'پیشنهاد قابل اعمالی پیدا نشد؛ این نتیجه به معنای نبود همه خطاهای Yoast نیست.';
      } catch (error) { status.textContent = error.message; }
      finally { analyze.disabled = false; }
    });
    results.addEventListener('change', () => { apply.disabled = !results.querySelector('input:checked'); });
    apply.addEventListener('click', () => {
      try {
        const adapter = editor();
        if (!same(snapshot, adapter.read())) throw new Error('متن تغییر کرده است. برای جلوگیری از حذف ویرایش‌های جدید، دوباره بررسی کنید.');
        const chosen = [...results.querySelectorAll('input:checked')].map(input => patches[Number(input.value)]);
        if (!chosen.length) return;
        const rows = snapshot.map(row => ({ ...row }));
        chosen.sort((a, b) => b.index - a.index).forEach(patch => {
          const part = mapping[patch.index];
          if (!part || part.text !== patch.before) throw new Error('پیشنهاد با متن اصلی مطابقت ندارد.');
          const row = rows[part.rowIndex];
          row.html = row.html.slice(0, part.start) + escapeText(patch.after) + row.html.slice(part.end);
        });
        backup = snapshot;
        adapter.write(rows); applied = adapter.read();
        undo.disabled = false; apply.disabled = true; results.replaceChildren();
        status.textContent = 'فقط موارد انتخاب‌شده اعمال شدند. برای ذخیره، به‌روزرسانی وردپرس را بزنید؛ سپس تحلیل Yoast را دوباره بررسی کنید.';
      } catch (error) { status.textContent = error.message; }
    });
    undo.addEventListener('click', () => {
      try {
        const adapter = editor();
        if (!same(applied, adapter.read())) throw new Error('پس از اصلاح، متن تغییر کرده است؛ برای حفظ تغییرات از تاریخچه ویرایشگر استفاده کنید.');
        adapter.write(backup); undo.disabled = true; apply.disabled = true; results.replaceChildren();
        status.textContent = 'متن قبل از آخرین اصلاح بازگردانده شد؛ هنوز باید ذخیره کنید.';
      } catch (error) { status.textContent = error.message; }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  new MutationObserver(init).observe(document.body, { childList: true, subtree: true });
})();
