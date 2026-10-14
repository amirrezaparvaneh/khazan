/**
 * هسته کلاینت داشبورد تحلیل لینک‌های داخلی (با قابلیت حفظ پایداری فرم برنامه‌ریز هنگام بروز خطا)
 */
(function () {
  'use strict';

  const app = {
    state: {
      activeTab: 'home',
      activeNav: 'home',
      pendingScroll: '',
      summary: null,
      items: [],
      orphans: [],
      filterType: 'all',
      isScanning: false,
      scanProgress: 0,
      scanTotal: 0,
      scanCurrent: 0,
      postTypes: [],
      taxonomies: [],
      aiSettings: {
        provider: 'avalai',
        hasAvalAIKey: false,
        avalAIMaskedKey: '',
        avalAIModel: 'gpt-5.4-mini',
        homepageKeywords: ''
      },
      plannerForm: {
        h1Title: '',
        focusKeyword: '',
        longTail: '',
        contentType: 'پیلار پیج (Pillar)',
        funnelStage: 'TOFU (آگاهی / بالاي قیف)',
        wordCount: 1200
      },
      plannerResult: null,
      isPlanning: false,
      generatorForm: { contentKind: 'article', targetId: 0, h1Title: '', focusKeyword: '', longTail: '', wordCount: 1200, funnelStage: 'تشخیص خودکار', internalLinks: '', description: '', useOrphanLinks: true },
      seoFix: { kind: 'product', search: '', targetId: 0, targets: [], targetsLoading: false, targetsError: '', current: null, currentLoading: false, errors: '', result: null, running: false, applying: false },
      bulk: window.KhazanQueue.initialState(),
      targets: [],
      targetsLoading: false,
      targetsError: '',
      isApplying: false,
      contentOutline: null,
      generatedContent: '',
      featuredImagePrompt: '',
      generatedSlug: '',
      generatedSeoTitle: '',
      generatedMetaDescription: '',
      generatedKeywordSynonyms: '',
      generatedInternalLinkCount: 0,
      generatedExternalLinkCount: 0,
      generatedExternalLinks: [],
      contentHistory: [],
      historyLoading: true,
      isGeneratingContent: false,
      modal: { isOpen: false, title: '', contentHtml: '' },
    },

    toast: function (message, type) {
      let box = document.getElementById('wila-toast-root');
      if (!box) { box = document.createElement('div'); box.id = 'wila-toast-root'; document.body.appendChild(box); }
      const kind = type || (/^خطا|ناموفق|نشد|نامعتبر/.test(message) ? 'error' : 'success');
      const el = document.createElement('div');
      el.className = `wila-toast wila-toast-${kind}`;
      el.textContent = message;
      box.appendChild(el);
      setTimeout(() => { el.classList.add('wila-toast-hide'); setTimeout(() => el.remove(), 300); }, 3500);
    },

    init: function () {
      this.root = document.getElementById('wila-app-root');
      if (!this.root) return;
      this.fetchSummary();
      this.fetchSettings();
      this.fetchContentHistory();
      this.render();
    },

























    // ---- رفع خطای هدفمند سئو: فقط ایرادهای گزارش‌شده اصلاح می‌شوند ----














    closeModal: function () {
      this.state.modal.isOpen = false;
      this.render();
    },

    render: function () {
      this.root.innerHTML = this.shellHtml(this.renderView()) + this.modalHtml();
      this.bindEvents();
    },

    modalHtml: function () {
      const { modal } = this.state;
      return `${modal.isOpen ? `
          <div class="wila-modal-backdrop" id="wila-modal-backdrop">
            <div class="wila-modal">
              <div class="wila-modal-header"><h3>${modal.title}</h3><button class="wila-btn wila-btn-outline wila-btn-sm" id="wila-close-modal-btn">✕</button></div>
              <div class="wila-modal-body">${modal.contentHtml}</div>
            </div>
          </div>
        ` : ''}
      `;
    },

    bindEvents: function () {
      this.bindTabEvents();
      this.bindQueueEvents();
      this.bindFormEvents();

      const closeBtn = this.root.querySelector('#wila-close-modal-btn');
      if (closeBtn) closeBtn.addEventListener('click', () => this.closeModal());
    },
  };

  // ماژول‌های API، فرم‌ها، صف و تب‌ها روی app سوار می‌شوند (متدها با this === app اجرا می‌شوند)
  Object.assign(app, window.KhazanApi.methods, window.KhazanForms.methods, window.KhazanQueue.methods, window.KhazanTabs.methods);

  document.addEventListener('DOMContentLoaded', () => app.init());
})();