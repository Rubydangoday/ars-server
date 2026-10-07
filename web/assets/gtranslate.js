'use strict';
// Dịch máy mọi ngôn ngữ Google Dịch hỗ trợ (Google Website Translator), bổ sung cho bản dịch tay VI / EN.
// Riêng tư: script Google CHỈ được tải khi người dùng bấm "Ngôn ngữ khác" hoặc đã chọn một ngôn ngữ trước đó
// (cookie googtrans). Danh sách ngôn ngữ lấy thẳng từ Google nên luôn đủ và mới. Cần Internet.
(function () {
  const cfg = document.currentScript ? document.currentScript.dataset : {};
  const base = cfg.base || document.documentElement.lang || 'vi';             // ngôn ngữ gốc của trang (vi | en)
  const T = (s) => (window.ARS_I18N || {})[s] || s;
  const cookie = () => (document.cookie.match(/(?:^|;\s*)googtrans=([^;]+)/) || [])[1] || '';
  const target = () => decodeURIComponent(cookie()).split('/')[2] || '';
  let loading = null;

  function load() {
    if (loading) return loading;
    loading = new Promise((resolve, reject) => {
      window.arsGoogleTranslateInit = () => {
        try {
          // eslint-disable-next-line no-undef
          new google.translate.TranslateElement({ pageLanguage: base, autoDisplay: false }, 'gt-el');
          resolve();
        } catch (e) { reject(e); }
      };
      const s = document.createElement('script');
      s.src = 'https://translate.google.com/translate_a/element.js?cb=arsGoogleTranslateInit';
      s.async = true;
      s.onerror = () => reject(new Error('offline'));
      document.head.appendChild(s);
      setTimeout(() => reject(new Error('timeout')), 12000);
    });
    return loading;
  }

  function clearCookie() {
    const host = location.hostname, parts = host.split('.');
    const doms = ['', host, '.' + host];
    if (parts.length > 2) doms.push('.' + parts.slice(-2).join('.'));
    doms.forEach((d) => { document.cookie = 'googtrans=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + (d ? '; domain=' + d : ''); });
  }

  document.addEventListener('DOMContentLoaded', () => {
    const box = document.querySelector('[data-gt-box]');
    const status = box && box.querySelector('[data-gt-status]');
    const say = (m) => { if (status) status.textContent = m; };
    // Đã chọn một ngôn ngữ Google ở trang trước → tải lại để tiếp tục dịch trang này.
    const cur = target();
    if (cur && cur !== base) {
      document.documentElement.classList.add('gt-on');
      load().catch(() => say(T('Không kết nối được Google Dịch (cần Internet).')));
      document.querySelectorAll('[data-gt-current]').forEach((el) => { el.textContent = cur.toUpperCase(); });
    }
    document.querySelectorAll('[data-gt-open]').forEach((b) => b.addEventListener('click', (e) => {
      e.preventDefault();
      if (!box) return;
      box.hidden = false;
      say(T('Đang tải danh sách ngôn ngữ của Google…'));
      load().then(() => say('')).catch(() => say(T('Không kết nối được Google Dịch (cần Internet).')));
    }));
    // Chọn lại bản dịch tay (VI / EN): tắt dịch máy trước khi chuyển trang
    document.querySelectorAll('[data-gt-clear]').forEach((a) => a.addEventListener('click', clearCookie));
    document.querySelectorAll('[data-gt-off]').forEach((b) => b.addEventListener('click', (e) => {
      e.preventDefault(); clearCookie(); location.reload();
    }));
  });
})();
