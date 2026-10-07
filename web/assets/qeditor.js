// Trình soạn câu hỏi kiểu Word cho ô "Gõ / dán văn bản" (compose.php):
// thanh công cụ (đậm / nghiêng / gạch chân / chỉ số / màu — lưu dạng LaTeX nên hiển thị được ở mọi nơi có KaTeX),
// cấu trúc câu hỏi, trình soạn công thức LaTeX có bảng ký hiệu + xem trước, ký hiệu, hoá học (mhchem),
// hoàn tác / làm lại, tìm & thay, xem trước như học sinh thấy, đếm câu.
(function () {
  'use strict';
  const root = document.getElementById('qe');
  if (!root) return;
  const ta = document.getElementById('imp-text');
  const $ = (s) => root.querySelector(s) || document.querySelector(s);
  const _ = (s) => (window.ARS_T ? window.ARS_T(s) : s);

  // ------------------------------------------------------------ hoàn tác / làm lại
  const hist = [], redo = []; let last = ta.value, timer = 0;
  const snap = () => { if (hist.length && hist[hist.length - 1].v === ta.value) return; hist.push({ v: ta.value, s: ta.selectionStart, e: ta.selectionEnd }); if (hist.length > 200) hist.shift(); redo.length = 0; };
  snap();
  ta.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { snap(); status(); }, 350); });
  function restore(st) { ta.value = st.v; ta.setSelectionRange(st.s, st.e); ta.focus(); status(); }
  function undo() { if (hist.length < 2) return; redo.push(hist.pop()); restore(hist[hist.length - 1]); }
  function redoIt() { const st = redo.pop(); if (!st) return; hist.push(st); restore(st); }

  // ------------------------------------------------------------ chèn / bọc vùng chọn
  function put(text, selFrom, selTo) {        // thay vùng chọn bằng text; chọn lại [selFrom, selTo] (tính trong text)
    snap();
    const a = ta.selectionStart, b = ta.selectionEnd;
    ta.setRangeText(text, a, b, 'end');
    if (selFrom !== undefined) ta.setSelectionRange(a + selFrom, a + (selTo ?? selFrom));
    ta.focus(); snap(); status();
  }
  const sel = () => ta.value.slice(ta.selectionStart, ta.selectionEnd);
  function wrapTex(before, after, ph) {        // \(before SEL after\)
    const s = sel() || _(ph);
    const t = '\\(' + before + s + after + '\\)';
    put(t, 2 + before.length, 2 + before.length + s.length);
  }
  function lineStart(pos) { return ta.value.lastIndexOf('\n', pos - 1) + 1; }
  function atNewLine(text) {                   // chèn ở đầu một dòng mới
    const p = ta.selectionStart, ls = lineStart(p);
    const cur = ta.value.slice(ls, p);
    put((cur.trim() ? '\n' : '') + text, (cur.trim() ? 1 : 0) + text.length);
  }
  function nextNo() { const m = ta.value.match(/(?:^|\n)\s*Câu\s*(\d+)/gi); if (!m) return 1; return Math.max(...m.map((x) => +x.replace(/\D/g, ''))) + 1; }

  // ------------------------------------------------------------ lệnh thanh công cụ
  const CMD = {
    undo, redo: redoIt,
    clean() {
      snap();
      ta.value = ta.value.replace(/\r/g, '').replace(/[ \t\u00a0]+/g, ' ').replace(/^[ \t]*[•●○▪■◦–-]\s+/gm, '')
        .replace(/^\s*Question\s*(\d+)/gim, 'Câu $1').replace(/\n{3,}/g, '\n\n').trim();
      snap(); status();
    },
    bold: () => wrapTex('\\textbf{', '}', 'chữ đậm'),
    italic: () => wrapTex('\\textit{', '}', 'chữ nghiêng'),
    underline: () => wrapTex('\\underline{\\text{', '}}', 'gạch chân'),
    sup() { const s = sel() || 'x'; put('\\(' + s + '^{2}\\)', 2 + s.length + 2, 2 + s.length + 3); },
    sub() { const s = sel() || 'x'; put('\\(' + s + '_{1}\\)', 2 + s.length + 2, 2 + s.length + 3); },
    q() { const n = nextNo(); const t = 'Câu ' + n + ': '; const p = ta.selectionStart; const pre = ta.value.slice(0, p).trimEnd(); put((pre ? '\n\n' : '') + t, (pre ? 2 : 0) + t.length); },
    opts() { const t = 'A. \nB. \nC. \nD. '; atNewLine(t); const p = ta.selectionStart - t.length; ta.setSelectionRange(p + 3, p + 3); },
    correct() {
      const lines = ta.value.split('\n');
      const cur = ta.value.slice(0, ta.selectionStart).split('\n').length - 1;
      const OPT = /^(\s*)\*?(\(?[A-Ja-j][.)])/, QST = /^\s*(Câu\s*\d+|\d+\s*[.)])/i;
      if (!OPT.test(lines[cur]) || QST.test(lines[cur])) { alert(_('Đặt con trỏ vào dòng phương án (A. … / B. …) rồi bấm ✔ Đúng.')); return; }
      let top = cur, bot = cur;
      while (top > 0 && !QST.test(lines[top]) && lines[top - 1] !== undefined) top--;
      while (bot < lines.length - 1 && !QST.test(lines[bot + 1])) bot++;
      snap();
      for (let i = top; i <= bot; i++) if (OPT.test(lines[i]) && !QST.test(lines[i])) lines[i] = lines[i].replace(OPT, '$1$2');
      lines[cur] = lines[cur].replace(OPT, '$1*$2');
      const pos = lines.slice(0, cur).join('\n').length + (cur ? 1 : 0);
      ta.value = lines.join('\n'); ta.setSelectionRange(pos, pos); ta.focus(); snap(); status();
    },
    expl: () => atNewLine('Giải thích: '),
    topic: () => atNewLine('Chủ đề: '),
    eq: () => openEq('', 'basic'),
    chem: () => openEq('\\ce{}', 'chem'),
    sym() { const p = $('#qe-sym'); p.hidden = !p.hidden; root.querySelector('[data-cmd=sym]').classList.toggle('on', !p.hidden); },
    find() { const f = $('#qe-find'); f.hidden = !f.hidden; root.querySelector('[data-cmd=find]').classList.toggle('on', !f.hidden); if (!f.hidden) { $('#qe-f').value = sel() || $('#qe-f').value; $('#qe-f').focus(); } },
    findnext() {
      const q = $('#qe-f').value; if (!q) return;
      let i = ta.value.indexOf(q, ta.selectionEnd); if (i < 0) i = ta.value.indexOf(q);
      $('#qe-fmsg').textContent = i < 0 ? _('Không tìm thấy') : '';
      if (i >= 0) { ta.focus(); ta.setSelectionRange(i, i + q.length); }
    },
    replace() { const q = $('#qe-f').value; if (q && sel() === q) put($('#qe-r').value); CMD.findnext(); },
    replaceall() {
      const q = $('#qe-f').value; if (!q) return;
      const n = ta.value.split(q).length - 1; snap(); ta.value = ta.value.split(q).join($('#qe-r').value); snap(); status();
      $('#qe-fmsg').textContent = _('Đã thay') + ' ' + n;
    },
    preview() {
      const pv = $('#qe-preview'), on = pv.hidden;
      pv.hidden = !on; root.querySelector('[data-cmd=preview]').classList.toggle('on', on);
      root.classList.toggle('split', on);
      if (on) renderPreview();
    },
  };
  root.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-cmd]'); if (!b) return;
    e.preventDefault(); CMD[b.dataset.cmd]?.();
  });
  root.querySelector('select[data-cmd=color]').addEventListener('change', (e) => {
    const c = e.target.value; e.target.value = ''; if (!c) return;
    wrapTex('\\color{' + c + '}{\\text{', '}}', 'chữ màu');
  });
  ta.addEventListener('keydown', (e) => {
    if (!(e.ctrlKey || e.metaKey)) return;
    const k = e.key.toLowerCase();
    const map = { z: e.shiftKey ? 'redo' : 'undo', y: 'redo', b: 'bold', i: 'italic', u: 'underline', m: 'eq', h: 'find' };
    if (k === 'enter') { e.preventDefault(); document.getElementById('imp-parse').click(); return; }
    if (map[k]) { e.preventDefault(); CMD[map[k]](); }
  });

  // ------------------------------------------------------------ đếm câu + xem trước
  function status() {
    const n = (ta.value.match(/(?:^|\n)\s*(?:Câu\s*\d+|\d+\s*[.)])/gi) || []).length;
    const ok = (ta.value.match(/(?:^|\n)\s*\*\(?[A-Ja-j][.)]|(?:^|\n)\s*Đáp án\s*:/gi) || []).length;
    $('#qe-count').textContent = `${n} ${_('câu')} · ${ok} ${_('đáp án đã đánh dấu')} · ${ta.value.length} ${_('ký tự')}`;
    if (!$('#qe-preview').hidden) { clearTimeout(status.t); status.t = setTimeout(renderPreview, 250); }
  }
  const esc = (s) => s.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
  function renderPreview() {
    const pv = $('#qe-preview');
    const html = ta.value.split('\n').map((ln) => {
      const t = ln.trim(); if (!t) return '<div class="pv-gap"></div>';
      let m;
      if ((m = t.match(/^(Câu\s*\d+\s*[:.)]?|\d+\s*[.)])\s*(.*)$/i))) return `<div class="pv-q"><b>${esc(m[1])}</b> ${esc(m[2])}</div>`;
      if ((m = t.match(/^(\*?)\(?([A-Ja-j])[.)]\s*(.*)$/))) return `<div class="pv-o${m[1] ? ' ok' : ''}"><b>${m[2].toUpperCase()}</b><span>${esc(m[3])}</span>${m[1] ? '<i>✓</i>' : ''}</div>`;
      if ((m = t.match(/^(Giải thích|Đáp án|Chủ đề)\s*:\s*(.*)$/i))) return `<div class="pv-meta"><b>${esc(m[1])}:</b> ${esc(m[2])}</div>`;
      return `<div>${esc(t)}</div>`;
    }).join('');
    pv.innerHTML = html || `<p class="muted">${_('Chưa có nội dung')}</p>`;
    if (window.renderMathInElement) renderMathInElement(pv, { delimiters: [{ left: '\\[', right: '\\]', display: true }, { left: '\\(', right: '\\)', display: false }, { left: '$$', right: '$$', display: true }], throwOnError: false, strict: 'ignore' });
  }

  // ------------------------------------------------------------ ký hiệu nhanh (chèn ký tự Unicode)
  const SYMS = '≤ ≥ ≠ ≈ ≡ ± ∓ × ÷ · ∞ √ ∛ ∑ ∏ ∫ ∂ ∇ Δ ∆ ∈ ∉ ⊂ ⊆ ∪ ∩ ∅ ∀ ∃ ¬ ∧ ∨ ⇒ ⇔ → ← ↔ ↑ ↓ ° ′ ″ ‰ ∠ ⊥ ∥ △ □ ○ ≅ ∼ α β γ δ ε ζ η θ λ μ ν ξ π ρ σ τ φ χ ψ ω Γ Θ Λ Π Σ Φ Ψ Ω ℝ ℕ ℤ ℚ ℂ ½ ⅓ ¼ ¾ ² ³ ¹ ⁰ ⁴ ⁿ ₀ ₁ ₂ ₃ ₄ € £ ¥ © ® ™ • … — –'.split(' ');
  const symBox = $('#qe-sym');
  symBox.innerHTML = SYMS.map((s) => `<button type="button" data-sym="${s}">${s}</button>`).join('');
  symBox.addEventListener('click', (e) => { const b = e.target.closest('[data-sym]'); if (b) put(b.dataset.sym); });

  // ------------------------------------------------------------ trình soạn công thức
  // mỗi mục: [nhãn hiển thị (LaTeX), mẫu chèn — ▢ = vị trí con trỏ]
  const PAL = {
    basic: ['Cơ bản', [['\\frac{a}{b}', '\\frac{▢}{}'], ['\\dfrac{a}{b}', '\\dfrac{▢}{}'], ['x^{n}', '^{▢}'], ['x_{n}', '_{▢}'], ['\\sqrt{x}', '\\sqrt{▢}'], ['\\sqrt[n]{x}', '\\sqrt[▢]{}'],
      ['\\left( x \\right)', '\\left( ▢ \\right)'], ['\\left[ x \\right]', '\\left[ ▢ \\right]'], ['\\left| x \\right|', '\\left| ▢ \\right|'], ['\\{ x \\}', '\\{ ▢ \\}'],
      ['\\pm', '\\pm '], ['\\times', '\\times '], ['\\div', '\\div '], ['\\cdot', '\\cdot '], ['\\neq', '\\neq '], ['\\approx', '\\approx '], ['\\le', '\\le '], ['\\ge', '\\ge '],
      ['\\infty', '\\infty '], ['\\%', '\\%'], ['^{\\circ}', '^{\\circ}'], ['\\overline{AB}', '\\overline{▢}'], ['\\text{abc}', '\\text{▢}']]],
    greek: ['Hy Lạp', ['alpha beta gamma delta epsilon varepsilon zeta eta theta vartheta iota kappa lambda mu nu xi pi rho sigma tau upsilon phi varphi chi psi omega Gamma Delta Theta Lambda Xi Pi Sigma Phi Psi Omega'
      .split(' ').map((g) => ['\\' + g, '\\' + g + ' '])]],
    rel: ['Quan hệ & tập hợp', [['\\in', '\\in '], ['\\notin', '\\notin '], ['\\subset', '\\subset '], ['\\subseteq', '\\subseteq '], ['\\cup', '\\cup '], ['\\cap', '\\cap '], ['\\emptyset', '\\emptyset '],
      ['\\mathbb{R}', '\\mathbb{R}'], ['\\mathbb{N}', '\\mathbb{N}'], ['\\mathbb{Z}', '\\mathbb{Z}'], ['\\mathbb{Q}', '\\mathbb{Q}'], ['\\forall', '\\forall '], ['\\exists', '\\exists '],
      ['\\Rightarrow', '\\Rightarrow '], ['\\Leftrightarrow', '\\Leftrightarrow '], ['\\to', '\\to '], ['\\equiv', '\\equiv '], ['\\sim', '\\sim '], ['\\cong', '\\cong '], ['\\parallel', '\\parallel '], ['\\perp', '\\perp '],
      ['\\angle', '\\angle '], ['\\triangle', '\\triangle '], ['\\vec{v}', '\\vec{▢}'], ['\\overrightarrow{AB}', '\\overrightarrow{▢}'], ['\\widehat{ABC}', '\\widehat{▢}']]],
    calc: ['Giải tích', [['\\lim_{x \\to a}', '\\lim_{x \\to ▢} '], ['\\sum_{i=1}^{n}', '\\sum_{i=1}^{▢} '], ['\\prod_{i=1}^{n}', '\\prod_{i=1}^{▢} '], ['\\int f(x)\\,dx', '\\int ▢ \\,dx'],
      ['\\int_{a}^{b}', '\\int_{▢}^{} \\,dx'], ['\\iint', '\\iint '], ['\\oint', '\\oint '], ['f\'(x)', "f'(▢)"], ['\\frac{dy}{dx}', '\\frac{d▢}{dx}'], ['\\frac{\\partial f}{\\partial x}', '\\frac{\\partial ▢}{\\partial x}'],
      ['\\log_{a} x', '\\log_{▢} '], ['\\ln x', '\\ln ▢'], ['e^{x}', 'e^{▢}'], ['\\sin x', '\\sin ▢'], ['\\cos x', '\\cos ▢'], ['\\tan x', '\\tan ▢'], ['\\cot x', '\\cot ▢'], ['\\binom{n}{k}', '\\binom{▢}{}'], ['n!', '▢!'],
      ['C_{n}^{k}', 'C_{▢}^{}'], ['A_{n}^{k}', 'A_{▢}^{}']]],
    mat: ['Ma trận & hệ', [['\\begin{cases} a \\\\ b \\end{cases}', '\\begin{cases} ▢ \\\\  \\end{cases}'],
      ['\\begin{pmatrix} a & b \\\\ c & d \\end{pmatrix}', '\\begin{pmatrix} ▢ &  \\\\  &  \\end{pmatrix}'], ['\\begin{bmatrix} a & b \\\\ c & d \\end{bmatrix}', '\\begin{bmatrix} ▢ &  \\\\  &  \\end{bmatrix}'],
      ['\\begin{vmatrix} a & b \\\\ c & d \\end{vmatrix}', '\\begin{vmatrix} ▢ &  \\\\  &  \\end{vmatrix}'], ['\\left[ \\begin{array}{l} a \\\\ b \\end{array} \\right.', '\\left[ \\begin{array}{l} ▢ \\\\  \\end{array} \\right.'],
      ['\\begin{aligned} a &= b \\\\ c &= d \\end{aligned}', '\\begin{aligned} ▢ &=  \\\\  &=  \\end{aligned}']]],
    chem: ['Hoá học', [['\\ce{H2O}', '\\ce{▢}'], ['\\ce{A -> B}', '\\ce{▢ -> }'], ['\\ce{A <=> B}', '\\ce{▢ <=> }'], ['\\ce{->[t^\\circ]}', '\\ce{->[t^\\circ]}'], ['\\ce{SO4^2-}', '\\ce{SO4^2-}'],
      ['\\ce{Fe^3+}', '\\ce{Fe^3+}'], ['\\ce{^{235}_{92}U}', '\\ce{^{▢}_{}}'], ['\\ce{CuSO4.5H2O}', '\\ce{CuSO4.5H2O}'], ['\\ce{v}', '\\ce{v}'], ['\\ce{^}', '\\ce{^}'],
      ['\\ce{2H2 + O2 -> 2H2O}', '\\ce{2H2 + O2 -> 2H2O}'], ['\\ce{CH3COOH}', '\\ce{CH3COOH}']]],
    phys: ['Vật lý', [['\\vec{F} = m\\vec{a}', '\\vec{F} = m\\vec{a}'], ['v = \\frac{s}{t}', 'v = \\frac{s}{t}'], ['E = mc^{2}', 'E = mc^{2}'], ['\\Omega', '\\Omega'], ['\\mu', '\\mu'],
      ['\\text{m/s}^{2}', '\\,\\text{m/s}^{2}'], ['\\text{km/h}', '\\,\\text{km/h}'], ['\\lambda', '\\lambda'], ['\\Delta t', '\\Delta ▢'], ['\\hbar', '\\hbar'], ['10^{-3}', '10^{▢}']]],
  };
  const dlg = document.getElementById('qe-eq'), tex = document.getElementById('qe-tex'), out = document.getElementById('qe-out'), err = document.getElementById('qe-err');
  let tab = 'basic', range = null;
  function drawTabs() {
    document.getElementById('qe-tabs').innerHTML = Object.entries(PAL).map(([k, [n]]) => `<button type="button" data-tab="${k}" class="${k === tab ? 'on' : ''}">${_(n)}</button>`).join('');
    const pal = document.getElementById('qe-pal');
    pal.innerHTML = PAL[tab][1].map(([show, ins], i) => `<button type="button" data-i="${i}" title="${esc(ins.replace('▢', ''))}"><span class="k" data-tex="${esc(show)}"></span></button>`).join('');
    pal.querySelectorAll('.k').forEach((k) => { try { katex.render(k.dataset.tex, k, { throwOnError: false }); } catch (e) { k.textContent = k.dataset.tex; } });
  }
  document.getElementById('qe-tabs').addEventListener('click', (e) => { const b = e.target.closest('[data-tab]'); if (b) { tab = b.dataset.tab; drawTabs(); } });
  document.getElementById('qe-pal').addEventListener('click', (e) => {
    const b = e.target.closest('[data-i]'); if (!b) return;
    const ins = PAL[tab][1][+b.dataset.i][1];
    const a = tex.selectionStart, z = tex.selectionEnd, picked = tex.value.slice(a, z);
    let t = ins, caret = ins.indexOf('▢');
    if (caret >= 0) { t = ins.replace('▢', picked); caret += picked.length; } else caret = t.length;
    tex.setRangeText(t, a, z, 'end'); tex.setSelectionRange(a + caret, a + caret); tex.focus(); live();
  });
  function live() {
    const v = tex.value.trim();
    if (!v) { out.innerHTML = `<span class="muted">${_('Xem trước công thức')}</span>`; err.textContent = ''; return; }
    try { katex.render(v, out, { displayMode: true, throwOnError: true, strict: 'ignore' }); err.textContent = ''; }
    catch (e) { err.textContent = '⚠ ' + String(e.message || e).replace(/^KaTeX parse error: /, ''); }
  }
  tex.addEventListener('input', live);
  function openEq(init, t) {
    if (typeof katex === 'undefined' || !dlg.showModal) { const v = prompt(_('Nhập công thức LaTeX:'), init); if (v) put('\\(' + v + '\\)'); return; }
    range = [ta.selectionStart, ta.selectionEnd];
    // đang chọn sẵn một công thức \( … \) → sửa công thức đó
    const s = sel(); const m = s.match(/^\\\((.*)\\\)$/s) || s.match(/^\\\[(.*)\\\]$/s);
    tex.value = m ? m[1].trim() : (s && !init ? s : init);
    tab = t || tab; drawTabs(); dlg.showModal();
    tex.focus(); const c = tex.value.indexOf('{}'); tex.setSelectionRange(c >= 0 ? c + 1 : tex.value.length, c >= 0 ? c + 1 : tex.value.length); live();
  }
  dlg.addEventListener('close', () => {
    const how = dlg.returnValue, v = tex.value.trim(); dlg.returnValue = '';
    if (!v || (how !== 'inline' && how !== 'block') || !range) return;
    ta.setSelectionRange(range[0], range[1]);
    if (how === 'inline') put('\\(' + v + '\\)');
    else { const p = ta.selectionStart; const before = ta.value.slice(0, p); put((before && !before.endsWith('\n') ? '\n' : '') + '\\[' + v + '\\]\n'); }
  });
  tex.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); dlg.close('inline'); } });

  status();
})();
