(function () {
  'use strict';

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
    const restUrl = (window.wilaData && window.wilaData.restUrl) ? window.wilaData.restUrl : '/wp-json/';
    const nonce = (window.wilaData && window.wilaData.nonce) ? window.wilaData.nonce : '';

    const response = await fetch(`${restUrl}wila/v1${path}`, {
      ...options,
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': nonce,
        'Content-Type': 'application/json',
        ...(options.headers || {})
      }
    });
    const body = await response.json();
    if (!response.ok || body.code) throw new Error(body.message || 'خطا در دریافت داده.');
    return body;
  }

  function drawGraph() {
    const box = document.getElementById('wila-graph-canvas');
    if (!box || !state.graph) return;
    const nodes = state.graph.nodes || [];
    const edges = state.graph.edges || [];
    if (!nodes.length) {
      box.innerHTML = '<p class="wila-insights-empty">داده‌ای برای نمایش نیست. ابتدا اسکن کامل را از منوی داشبورد اجرا کنید.</p>';
      return;
    }

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
      const isSelected = state.selectedNode && state.selectedNode.id === n.id;
      const label = esc(n.title.length > 22 ? n.title.slice(0, 20) + '…' : n.title);
      svg.push(`<g class="wila-graph-node ${isolated ? 'is-isolated' : ''} ${isSelected ? 'is-selected' : ''}" data-node-id="${esc(n.id)}" tabindex="0" role="button"><circle cx="${p.x}" cy="${p.y}" r="${isSelected ? 18 : (isolated ? 16 : 13)}"/><title>${esc(n.title)} — ورودی: ${n.incoming}، خروجی: ${n.outgoing}</title><text x="${p.x}" y="${p.y + 30}">${label}</text></g>`);
    });
    svg.push('</svg>');
    box.innerHTML = svg.join('');
    box.querySelectorAll('.wila-graph-node').forEach(el => el.addEventListener('click', () => {
      state.selectedNode = nodes.find(n => n.id === el.dataset.nodeId) || null;
      render();
    }));
  }

  function render() {
    const root = document.getElementById('wila-insights-root');
    if (!root) return;

    const wins = state.quickWins && Array.isArray(state.quickWins.quick_wins) ? state.quickWins.quick_wins : [];
    const selected = state.selectedNode;
    const selectedHtml = selected
      ? `<aside class="wila-node-details"><h3>${esc(selected.title)}</h3><a href="${esc(selected.url)}" target="_blank" rel="noopener noreferrer">مشاهده صفحه ↗</a><p>نوع: <strong>${esc(selected.type)}</strong></p><p>لینک ورودی: <strong>${fa(selected.incoming)}</strong></p><p>لینک خروجی: <strong>${fa(selected.outgoing)}</strong></p></aside>`
      : '<aside class="wila-node-details"><p class="wila-card-desc">برای مشاهده جزئیات هر صفحه، روی گره مربوطه در نقشه کلیک کنید.</p></aside>';

    root.innerHTML = `
      <div class="wila-insights-page">
        <div class="wila-header">
          <div class="wila-header-title">
            <h1>نقشه و بینش‌های هوشمند لینکر</h1>
            <p>مصورسازی ارتباط لینک‌های داخلی و شناسایی فرصت‌های صعود به صفحه اول (Quick Win)</p>
          </div>
        </div>
        ${state.error ? `<div class="wila-insights-error">${esc(state.error)}</div>` : ''}
        <section class="wila-card wila-insights-section">
          <div class="wila-card-header">
            <div>
              <h3>نقشه تعاملی گراف صفحات (Topic Cluster Graph)</h3>
              <p class="wila-card-desc">خطوط نشان‌دهنده لینک‌های داخلی ثبت‌شده هستند. گره‌های آبی صفحات متصل و گره‌های قرمز جزیره‌های منزوی (بدون هیچ لینک داخلی) هستند.</p>
            </div>
            <button id="wila-load-graph" class="wila-btn wila-btn-primary" ${state.loadingGraph ? 'disabled' : ''}>
              ${state.loadingGraph ? 'در حال دریافت نقشه...' : 'تازه‌سازی نقشه'}
            </button>
          </div>
          <div class="wila-graph-layout">
            <div id="wila-graph-canvas" class="wila-graph-canvas">
              ${state.loadingGraph ? '<p class="wila-insights-empty">در حال استخراج ساختار لینک‌ها...</p>' : (state.graph ? '' : '<p class="wila-insights-empty">در حال آماده‌سازی نقشه گراف...</p>')}
            </div>
            ${selectedHtml}
          </div>
        </section>
        <section class="wila-card wila-insights-section">
          <div class="wila-card-header">
            <div>
              <h3>فرصت‌های جهش رتبه از Google Search Console (Quick Wins)</h3>
              <p class="wila-card-desc">صفحاتی با رتبه میانگین ۱۱ تا ۲۰ که با دریافت چند لینک داخلی هدفمند، شانس بالایی برای ورود به صفحه اول نتایج گوگل دارند.</p>
            </div>
          </div>
          <div class="wila-gsc-fields">
            <input id="wila-gsc-property" class="wila-input" dir="ltr" placeholder="sc-domain:example.com یا https://example.com/" value="${esc(state.property)}"/>
            <input id="wila-gsc-token" type="password" class="wila-input" dir="ltr" placeholder="OAuth Access Token فقط‌خواندنی (ذخیره نمی‌شود)" value="${esc(state.token)}"/>
            <button id="wila-load-wins" class="wila-btn wila-btn-primary" ${state.loadingWins ? 'disabled' : ''}>
              ${state.loadingWins ? 'در حال واکشی...' : 'کشف Quick Winها'}
            </button>
          </div>
          <p class="wila-card-desc">توکن دسترسی در پایگاه‌داده ذخیره نمی‌شود و فقط برای همین فراخوانی ارسال می‌گردد. داده‌های رتبه‌بندی مربوط به ۲۸ روز اخیر هستند.</p>
          ${state.quickWins ? `<p class="wila-card-desc">بازه زمانی: ${esc(state.quickWins.start_date)} تا ${esc(state.quickWins.end_date)} | ${fa(wins.length)} فرصت مستعد ارتقا شناسایی شد.</p>` : ''}
          ${wins.length ? `<div class="wila-table-container"><table class="wila-table"><thead><tr><th>صفحه هدف</th><th>رتبه میانگین</th><th>ایمپرشن</th><th>لینک ورودی</th><th>امتیاز اولویت</th></tr></thead><tbody>${wins.map(w => `<tr><td><a href="${esc(w.url)}" target="_blank" rel="noopener noreferrer">${esc(w.title)}</a></td><td>${fa(w.position)}</td><td>${fa(w.impressions)}</td><td>${fa(w.incoming)}</td><td><span class="wila-badge wila-badge-success">${fa(w.priority)}</span></td></tr>`).join('')}</tbody></table></div>` : (state.quickWins ? '<p class="wila-insights-empty">صفحه‌ای با موقعیت ۱۱ تا ۲۰ که در پایگاه‌داده اسکن شده باشد یافت نشد.</p>' : '')}
        </section>
      </div>`;

    const btnGraph = document.getElementById('wila-load-graph');
    if (btnGraph) btnGraph.addEventListener('click', loadGraph);

    const btnWins = document.getElementById('wila-load-wins');
    if (btnWins) btnWins.addEventListener('click', loadWins);

    if (state.graph) drawGraph();
  }

  async function loadGraph() {
    state.loadingGraph = true;
    state.error = '';
    render();
    try {
      state.graph = await api('/insights/graph');
    } catch (e) {
      state.error = e.message;
    } finally {
      state.loadingGraph = false;
      render();
    }
  }

  async function loadWins() {
    const propInput = document.getElementById('wila-gsc-property');
    const tokenInput = document.getElementById('wila-gsc-token');
    const property = propInput ? propInput.value.trim() : state.property;
    const token = tokenInput ? tokenInput.value.trim() : state.token;

    state.property = property;
    state.token = token;

    if (!property || !token) {
      state.error = 'شناسه Property و Access Token را وارد کنید.';
      render();
      return;
    }

    state.loadingWins = true;
    state.error = '';
    render();

    try {
      state.quickWins = await api('/insights/search-console', {
        method: 'POST',
        body: JSON.stringify({ property, access_token: token })
      });
    } catch (e) {
      state.error = e.message;
    } finally {
      state.token = '';
      state.loadingWins = false;
      render();
    }
  }

  function init() {
    const root = document.getElementById('wila-insights-root');
    if (!root) return;
    render();
    loadGraph();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
