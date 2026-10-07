'use strict';
// Dịch nội dung do JavaScript chèn vào trang (thông báo, nút, alert/confirm…) khi giao diện không phải tiếng Việt.
// Cùng từ điển (window.ARS_I18N, từ lang/en.php) và cùng quy tắc tra như i18n_lookup() phía máy chủ:
// nguyên văn → bỏ biểu tượng / dấu câu đầu-cuối → số thay bằng {n} → dịch từng mảnh của chuỗi ghép "A · B — C".
// Không dịch nội dung trò chuyện với trợ lý, ô nhập liệu và phần đánh dấu translate="no".
(function () {
  const D = window.ARS_I18N;
  if (!D) return;
  const VI = /[À-ỹĐđ]/;
  const NUM = /\d+(?:[.,]\d+)*/g;

  function look(s, split) {
    if (D[s] !== undefined) return D[s];
    if (split) {
      const r = look(s, false);
      if (r !== null) return r;
      const ps = s.split(/(\s+[·—›|]\s+|:\s+)/);
      if (ps.length < 2) return null;
      let hit = false;
      for (let i = 0; i < ps.length; i += 2) {
        if (!ps[i]) continue;
        const x = look(ps[i], false);
        if (x !== null) { ps[i] = x; hit = true; }
      }
      return hit ? ps.join('') : null;
    }
    let core = s, pre = '', post = '';
    const m = s.match(/^([^\p{L}\p{N}]*)(.*?)([\s:：.…!?·,;)]*)$/su);
    if (m && m[2] && (m[1] || m[3])) {
      if (D[m[2]] !== undefined) return m[1] + D[m[2]] + m[3];
      core = m[2]; pre = m[1]; post = m[3];
    }
    const nums = core.match(NUM);
    if (nums) {
      const k = core.replace(NUM, '{n}');
      if (D[k] !== undefined) { let i = 0; return pre + D[k].replace(/\{n\}/g, () => nums[i++] ?? '') + post; }
    }
    return null;
  }
  function tr(s) {
    if (!s || !VI.test(s)) return s;
    const r = look(s.trim().replace(/\s+/g, ' '), true);
    if (r === null) return s;
    return s.match(/^\s*/)[0] + r + s.match(/\s*$/)[0];
  }
  window.ARS_T = tr;

  const SKIP = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'PRE', 'CODE', 'INPUT']);
  const KEEP = '[translate=no],.ct-content,.ct-bubble,.notranslate';
  function walk(n) {
    if (n.nodeType === 3) {
      const p = n.parentElement;
      if (p && !SKIP.has(p.nodeName) && !p.closest(KEEP)) { const v = tr(n.nodeValue); if (v !== n.nodeValue) n.nodeValue = v; }
      return;
    }
    if (n.nodeType !== 1 || SKIP.has(n.nodeName) || n.closest(KEEP)) return;
    for (const a of ['placeholder', 'title', 'aria-label']) {
      const v = n.getAttribute(a);
      if (v) { const t = tr(v); if (t !== v) n.setAttribute(a, t); }
    }
    if (n.nodeName === 'TEXTAREA' || n.nodeName === 'INPUT') return;
    for (const c of n.childNodes) walk(c);
  }
  new MutationObserver((ms) => {
    for (const m of ms) {
      if (m.type === 'characterData') walk(m.target);
      else if (m.type === 'attributes') walk(m.target);
      else m.addedNodes.forEach(walk);
    }
  }).observe(document.documentElement, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['placeholder', 'title'] });
  // ô nhập: placeholder đặt bằng JS
  document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[placeholder]').forEach(walk));

  const A = window.alert.bind(window), C = window.confirm.bind(window), P = window.prompt.bind(window);
  window.alert = (m) => A(tr(String(m ?? '')));
  window.confirm = (m) => C(tr(String(m ?? '')));
  window.prompt = (m, d) => P(tr(String(m ?? '')), d);
})();
