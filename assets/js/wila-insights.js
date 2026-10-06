(function () {
  'use strict';

  const root = document.getElementById('wila-insights-root');
  if (!root) return;

  const state = {
    graph: null,
    quickWins: null,
    loadingGraph: false,
    loadingWins: false,
    error: '',
    selectedNode: null,
    property: '',
    token: ''
  };
  const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fa = (n) => Number(n || 0).toLocaleString('fa-IR');

  async function api(path, options = {}) {
    const response = await fetch(`${wilaData.restUrl}wila/v1${path}`, {
      ...options,
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': wilaData.nonce, 'Content-Type': 'application/json', ...(options.headers || {}) }
    });
    const body = await response.json();
    if (!response.ok || body.code) throw new Error(body.message || 'خطا در دریافت داده.');
    return body;
  }

  function drawGraph() {
    const box = document.getElementById('wila-graph-canvas');
    if (!box || !state.graph) return;
    const nodes = state.graph.nodes;
    const edges = state.graph.edges;
    if (!nodes.length) { box.innerHTML = '<p class="wila-insights-empty">داده‌ای برای نمایش نیست. ابتدا اسکن کامل را اجرا کنید.</p>'; return; }

    const columns = Math.max(4, Math.ceil(Math.sqrt(nodes.length)));
    const rows = Math.ceil(nodes.length / columns);
    const width = Math.max(720, box.clientWidth || 720);
    const height = Math.max(480, 100 + rows * 105);
    const pos = {};
    nodes.forEach((n, i) => {
      const col = i % columns, row = Math.floor(i / columns);
      pos[n.id] = { x: 55 + col * ((width - 110) / Math.max(1, columns - 1)), y: 55 + row * 92 };
    });
    const svg = [`<svg class="wila-graph-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="نقشه ارتباط صفحات">`];
    edges.forEach(e => {
      const a = pos[e.source], b = pos[e.target];
      if (a && b) svg.push(`<line x1="${a.x}" y1="${a.y}" x2="${b.x}" y2="${b.y}" class="wila-graph-edge"/>`);
    });
    nodes.forEach(n => {
      const p = pos[n.id], isolated = n.incoming === 0 && n.outgoing === 0;
      const label = esc(n.title.length > 22 ? n.title.slice(0, 20) + '…' : n.title);
      svg.push(`<g class="wila-graph-node ${isolated ? 'is-isolated' : ''}" data-node-id="${esc(n.id)}" tabindex="0" role="button"><circle cx="${p.x}" cy="${p.y}" r="${isolated ? 17 : 14}"/><title>${esc(n.title)} — ورودی: ${n.incoming}، خروجی: ${n.outgoing}</title><text x="${p.x}" y="${p.y + 32}">${label}</text></g>`);
    });
    svg.push('</svg>');
    box.innerHTML = svg.join('');
    box.querySelectorAll('.wila-graph-node').forEach(el => el.addEventListener('click', () => {
      state.selectedNode = nodes.find(n => n.id === el.dataset.nodeId) || null;
      render();
    }));
  }

  function render() {
    const wins = state.quickWins && Array.isArray(state.quickWins.quick_wins) ? state.quickWins.quick_wins : [];
    const selected = state.selectedNode;
    const selectedHtml = selected ? `<aside class="wila-node-details"><h3>${esc(selected.title)}</h3><a href="${esc(selected.url)}" target="_blank">باز کردن صفحه</a><p>نوع: <strong>${esc(selected.type)}</strong></p><p>لینک ورودی: <strong>${fa(selected.incoming)}</strong></p><p>لینک خروجی: <strong>${fa(selected.outgoing)}</strong></p></aside>` : '<aside class="wila-node-details"><p class="wila-card-desc">برای مشاهده جزئیات یک صفحه، گره آن را انتخاب کنید.</p></aside>';
    root.innerHTML = `
      <div class="wila-insights-page">
        <div class="wila-header"><div class="wila-header-title"><h1>نقشه و بینش‌های آزمایشی</h1><p>مصورسازی لینک‌های داخلی و شناسایی فرصت‌های Quick Win از Google Search Console</p></div></div>
        ${state.error ? `<div class="wila-insights-error">${esc(state.error)}</div>` : ''}
        <section class="wila-card wila-insights-section">
          <div class="wila-card-header"><div><h3>نقشه گراف کلاسترها</h3><p class="wila-card-desc">هر خط یک لینک داخلی اسکن‌شده است. گره قرمز، صفحه بدون هیچ ارتباط داخلی است.</p></div><button id="wila-load-graph" class="wila-btn wila-btn-primary" ${state.loadingGraph ? 'disabled' : ''}>${state.loadingGraph ? 'در حال بارگذاری...' : 'بارگذاری گراف'}</button></div>
          <div class="wila-graph-layout"><div id="wila-graph-canvas" class="wila-graph-canvas">${state.graph ? '' : '<p class="wila-insights-empty">با بارگذاری گراف، ساختار واقعی لینک‌های اسکن‌شده نمایش داده می‌شود.</p>'}</div>${selectedHtml}</div>
        </section>
        <section class="wila-card wila-insights-section">
          <div class="wila-card-header"><div><h3>Quick Winهای Google Search Console</h3><p class="wila-card-desc">صفحات با رتبه میانگین ۱۱ تا ۲۰، مرتب‌شده بر اساس ایمپرشن و کمبود لینک ورودی.</p></div></div>
          <div class="wila-gsc-fields"><input id="wila-gsc-property" class="wila-input" dir="ltr" placeholder="sc-domain:example.com یا https://example.com/" value="${esc(state.property)}"/><input id="wila-gsc-token" type="password" class="wila-input" dir="ltr" placeholder="OAuth Access Token فقط‌خواندنی (ذخیره نمی‌شود)" value="${esc(state.token)}"/><button id="wila-load-wins" class="wila-btn wila-btn-primary" ${state.loadingWins ? 'disabled' : ''}>${state.loadingWins ? 'در حال دریافت...' : 'کشف Quick Winها'}</button></div>
          <p class="wila-card-desc">توکن در دیتابیس ذخیره نمی‌شود و فقط برای این درخواست استفاده می‌شود. داده‌ها مربوط به ۲۸ روز اخیر، با تأخیر سه‌روزه هستند.</p>
          ${state.quickWins ? `<p class="wila-card-desc">بازه: ${esc(state.quickWins.start_date)} تا ${esc(state.quickWins.end_date)} | ${fa(wins.length)} فرصت یافت شد.</p>` : ''}
          ${wins.length ? `<div class="wila-table-container"><table class="wila-table"><thead><tr><th>صفحه</th><th>رتبه</th><th>ایمپرشن</th><th>لینک ورودی</th><th>اولویت</th></tr></thead><tbody>${wins.map(w => `<tr><td><a href="${esc(w.url)}" target="_blank">${esc(w.title)}</a></td><td>${fa(w.position)}</td><td>${fa(w.impressions)}</td><td>${fa(w.incoming)}</td><td><span class="wila-badge wila-badge-success">${fa(w.priority)}</span></td></tr>`).join('')}</tbody></table></div>` : (state.quickWins ? '<p class="wila-insights-empty">فرصتی با رتبه ۱۱ تا ۲۰ که با صفحات اسکن‌شده منطبق باشد یافت نشد.</p>' : '')}
        </section>
      </div>`;
    document.getElementById('wila-load-graph').addEventListener('click', loadGraph);
    document.getElementById('wila-load-wins').addEventListener('click', loadWins);
    if (state.graph) drawGraph();
  }

  async function loadGraph() {
    state.loadingGraph = true; state.error = ''; render();
    try { state.graph = await api('/insights/graph'); } catch (e) { state.error = e.message; } finally { state.loadingGraph = false; render(); }
  }

  async function loadWins() {
    const property = document.getElementById('wila-gsc-property').value.trim();
    const token = document.getElementById('wila-gsc-token').value.trim();
    state.property = property;
    state.token = token;
    if (!property || !token) { state.error = 'Property و Access Token را وارد کنید.'; render(); return; }
    state.loadingWins = true; state.error = ''; render();
    try {
      state.quickWins = await api('/insights/search-console', { method: 'POST', body: JSON.stringify({ property, access_token: token }) });
    } catch (e) {
      state.error = e.message;
    } finally {
      state.token = '';
      state.loadingWins = false;
      render();
    }
  }

  render();
})();
