// Soạn câu hỏi: tách câu từ văn bản/file (trái) + trợ lý AI (phải); mỗi câu có nút xác nhận đưa vào bộ kiểm tra / ôn tập.
(function () {
  'use strict';
  const C = window.ARS_COMPOSE;
  const L = C.letters;
  const CSRF = document.querySelector('meta[name=csrf]').content;
  const $ = (s, el) => (el || document).querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ORIGIN = { ai: 'AI gợi ý', word: 'Word', excel: 'Excel', text: 'Văn bản', manual: 'Soạn tay' };
  const PURPOSE = { test: 'bài kiểm tra', practice: 'bài ôn tập' };
  const _ = (x) => (window.ARS_T ? window.ARS_T(x) : x);

  async function api(url, fields, file) {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    for (const [k, v] of Object.entries(fields || {})) fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
    if (file) fd.append('file', file);
    let r, j;
    try { r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }); }
    catch (e) { throw new Error('Không kết nối được máy chủ.'); }
    const txt = await r.text();
    try { j = JSON.parse(txt); } catch (e) { throw new Error(r.status === 413 ? 'File quá lớn.' : (txt.slice(0, 200) || 'Lỗi máy chủ ' + r.status)); }
    if (!r.ok || j.error) throw new Error(j.error || 'Lỗi ' + r.status);
    return j;
  }

  // ------------------------------------------------------------ bộ câu hỏi đích
  const dst = { test: $('#dst-test'), practice: $('#dst-practice') };
  function fillTargets() {
    for (const p of ['test', 'practice']) {
      const sel = dst[p], keep = sel.value || String(C.pre[p] || '');
      const mine = C.sets.filter((s) => s.purpose === p);
      sel.innerHTML = '<option value="">— chọn bộ ' + PURPOSE[p] + ' —</option>'
        + mine.map((s) => `<option value="${s.id}">${esc(s.title)} (${s.n} ${_('câu')}${s.fin ? ' ✓' : ' · ' + _('đang soạn')})</option>`).join('')
        + `<option value="new">＋ Tạo bộ ${PURPOSE[p]} mới…</option>`;
      sel.value = mine.some((s) => String(s.id) === keep) ? keep : (mine[0] ? String(mine[0].id) : '');
      sel.dataset.prev = sel.value;
    }
    finLinks();
  }
  // nút "Xem & xác nhận bộ": mở trang bộ câu hỏi ở khung kiểm tra / xác nhận hoàn tất
  function finLinks() {
    document.querySelectorAll('.fin-link').forEach((a) => {
      const v = dst[a.dataset.for].value, s = C.sets.find((x) => String(x.id) === v);
      a.hidden = !s; if (!s) return;
      a.href = 'set.php?id=' + s.id + '#finalize';
      a.textContent = s.fin ? '✓ ' + _('Đã hoàn tất') : '✓ ' + _('Xem & xác nhận bộ') + ` (${s.n})`;
      a.classList.toggle('primary', !s.fin && s.n > 0);
    });
  }
  async function createSet(p) {
    const name = prompt('Tên bộ ' + PURPOSE[p] + ' mới:', p === 'test' ? 'Kiểm tra — ' : 'Ôn tập — ');
    if (!name || !name.trim()) return null;
    const j = await api('api/qadd.php', { set_id: 'new', new_title: name.trim(), purpose: p });
    C.sets.unshift({ id: j.set.id, title: j.set.title, purpose: p, n: 0 });
    return j.set.id;
  }
  for (const p of ['test', 'practice']) {
    dst[p].addEventListener('change', async () => {
      const sel = dst[p];
      if (sel.value !== 'new') { sel.dataset.prev = sel.value; finLinks(); return; }
      try {
        const id = await createSet(p);
        fillTargets();
        sel.value = id ? String(id) : sel.dataset.prev;
        sel.dataset.prev = sel.value;
      } catch (e) { alert(e.message); sel.value = sel.dataset.prev; }
    });
  }
  function setCount(id, n) { const s = C.sets.find((x) => x.id === id); if (s) { s.n = n; s.fin = false; const o = document.querySelector(`#dst-${s.purpose} option[value="${id}"]`); if (o) o.textContent = `${s.title} (${n} ${_('câu')} · ${_('đang soạn')})`; finLinks(); } }
  function needTarget(p) {
    const v = dst[p].value;
    if (!v || v === 'new') { dst[p].focus(); dst[p].closest('label').animate([{ background: '#fff3d6' }, { background: 'transparent' }], 1200); alert('Chọn bộ ' + PURPOSE[p] + ' ở thanh phía trên trước.'); return null; }
    return v;
  }

  // ------------------------------------------------------------ thẻ câu hỏi
  function issuesOf(q) {
    const out = (q.issues || []).filter((x) => !/đáp án|phương án/.test(x));
    if (q.options.filter((o) => o.trim()).length < 2) out.push('cần ít nhất 2 phương án');
    if (!q.correct) out.push('chưa có đáp án đúng');
    return out;
  }
  function viewHtml(q, no) {
    const iss = issuesOf(q);
    return `<div class="view">
      <div class="top"><span class="n">Câu ${no}</span>
        ${q.topic ? `<span class="tag blue">${esc(q.topic)}</span>` : ''}
        ${q.difficulty ? `<span class="tag">${esc(C.difficulty[q.difficulty])}</span>` : ''}
        <span class="tag ${q.origin === 'ai' ? 'ai' : ''}">${esc(ORIGIN[q.origin] || q.origin)}</span><span class="sp"></span></div>
      <div class="content">${esc(q.content)}</div>
      <ul class="o">${q.options.map((o, i) => `<li class="${L[i] === q.correct ? 'ok' : ''}"><b>${L[i]}</b><span>${esc(o)}</span>${L[i] === q.correct ? '<span class="pill-ok">✓ đúng</span>' : ''}</li>`).join('')}</ul>
      ${q.explanation ? `<div class="ex"><b>Giải thích:</b> ${esc(q.explanation)}</div>` : ''}
      ${iss.length ? `<div class="issues">⚠ ${esc(iss.join(' · '))}</div>` : ''}
    </div>`;
  }
  function editHtml(q, uid) {
    return `<div class="edit">
      <label>Nội dung câu hỏi <textarea rows="3" data-f="content">${esc(q.content)}</textarea></label>
      <div class="opts-edit">${q.options.map((o, i) => optRow(uid, i, o, L[i] === q.correct)).join('')}</div>
      <div class="row" style="margin:0"><button type="button" class="small" data-act="addopt">＋ Phương án</button><button type="button" class="small" data-act="delopt">− Bớt phương án cuối</button></div>
      <div class="grid3">
        <label>Chủ đề <input type="text" data-f="topic" value="${esc(q.topic)}"></label>
        <label>Mức độ <select data-f="difficulty"><option value="">—</option>${Object.entries(C.difficulty).map(([k, v]) => `<option value="${k}" ${q.difficulty === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
        <label>Thời gian (giây) <input type="number" min="5" max="3600" data-f="time_limit" value="${q.time_limit || ''}" placeholder="không giới hạn"></label>
      </div>
      <label>Giải thích <textarea rows="2" data-f="explanation">${esc(q.explanation)}</textarea></label>
      <div class="row" style="margin:0"><button type="button" class="primary small" data-act="done">Xong</button><span class="muted small">Chọn nút tròn ở phương án đúng.</span></div>
    </div>`;
  }
  const optRow = (uid, i, o, on) => `<div class="optrow"><input type="radio" name="c${uid}" value="${L[i]}" ${on ? 'checked' : ''} title="Đáp án đúng"><b>${L[i]}</b><input type="text" value="${esc(o)}" data-opt="${i}"></div>`;

  let UID = 0;
  function makeCard(q, no) {
    const uid = ++UID;
    q.options = (q.options || []).slice();
    const el = document.createElement('article');
    el.className = 'qc';
    el.q = q; el.no = no; el.added = {};
    el.innerHTML = viewHtml(q, no) + `<div class="edit-wrap"></div>
      <div class="foot">
        <button type="button" class="small primary" data-add="test">✓ Thêm vào bài kiểm tra</button>
        <button type="button" class="small" data-add="practice">✓ Thêm vào bài ôn tập</button>
        <button type="button" class="small ghost" data-act="edit">Sửa</button>
        <button type="button" class="small ghost" data-act="drop">Bỏ</button>
        <span class="done"></span>
      </div>`;
    el.addEventListener('click', async (ev) => {
      const b = ev.target.closest('button'); if (!b) return;
      if (b.dataset.add) return addCards([el], b.dataset.add);
      const act = b.dataset.act;
      if (act === 'edit') { $('.edit-wrap', el).innerHTML = editHtml(el.q, uid); el.classList.add('editing'); }
      if (act === 'done') { readEdit(el); el.classList.remove('editing'); $('.view', el).outerHTML = viewHtml(el.q, el.no); $('.edit-wrap', el).innerHTML = ''; window.ARSRenderMath?.(el); }
      if (act === 'addopt') { const box = $('.opts-edit', el); const n = box.children.length; if (n < 10) box.insertAdjacentHTML('beforeend', optRow(uid, n, '', false)); }
      if (act === 'delopt') { const box = $('.opts-edit', el); if (box.children.length > 2) box.lastElementChild.remove(); }
      if (act === 'drop') { el.classList.toggle('removed'); b.textContent = el.classList.contains('removed') ? 'Khôi phục' : 'Bỏ'; el.querySelectorAll('[data-add]').forEach((x) => { x.disabled = el.classList.contains('removed'); }); }
    });
    return el;
  }
  function readEdit(el) {
    const q = el.q;
    q.content = $('[data-f=content]', el).value.trim();
    q.options = [...el.querySelectorAll('[data-opt]')].map((i) => i.value.trim());
    const r = el.querySelector('.opts-edit input[type=radio]:checked');
    q.correct = r && L.indexOf(r.value) < q.options.length ? r.value : '';
    q.topic = $('[data-f=topic]', el).value.trim();
    q.difficulty = $('[data-f=difficulty]', el).value;
    q.explanation = $('[data-f=explanation]', el).value.trim();
    q.time_limit = +$('[data-f=time_limit]', el).value || null;
    q.issues = [];
  }

  async function addCards(cards, p) {
    cards = cards.filter((c) => !c.classList.contains('removed') && !c.added[p]);
    if (!cards.length) return;
    cards.forEach((c) => { if (c.classList.contains('editing')) $('[data-act=done]', c).click(); });
    const set = needTarget(p); if (!set) return;
    const noAns = cards.filter((c) => !c.q.correct);
    if (noAns.length) {
      if (p === 'practice') { alert(`Có ${noAns.length} câu chưa có đáp án đúng. Bài ôn tập cần đáp án để chấm: bấm Sửa để chọn đáp án.`); return; }
      if (!confirm(`${noAns.length} câu chưa có đáp án đúng (sẽ thành câu khảo sát ý kiến). Vẫn thêm?`)) return;
    }
    const btns = cards.map((c) => c.querySelector(`[data-add=${p}]`));
    btns.forEach((b) => { b.disabled = true; b.dataset.label = b.textContent; b.innerHTML = '<span class="spinner"></span>Đang thêm…'; });
    try {
      const j = await api('api/qadd.php', { set_id: set, questions: cards.map((c) => c.q) });
      setCount(j.set.id, j.set.n);
      j.results.forEach((r, i) => {
        const c = cards[i], b = btns[i];
        b.textContent = b.dataset.label;
        if (r.ok) {
          c.added[p] = true; c.classList.add('added'); b.disabled = true; b.textContent = '✓ Đã thêm vào ' + PURPOSE[p];
          const d = $('.done', c);
          d.innerHTML += `<span>→ <a href="set.php?id=${j.set.id}" target="_blank">${esc(j.set.title)}</a> · câu ${r.position}</span> `;
        } else { b.disabled = false; alert('Câu ' + c.no + ': ' + r.error); }
      });
    } catch (e) {
      btns.forEach((b) => { b.disabled = false; b.textContent = b.dataset.label; });
      alert(e.message);
    }
  }

  function renderGroup(container, questions, warnings, head) {
    const g = document.createElement('div');
    g.className = 'group';
    g.innerHTML = (warnings && warnings.length ? `<ul class="warn-list">${warnings.map((w) => `<li>${esc(w)}</li>`).join('')}</ul>` : '')
      + (questions.length ? `<div class="bulk"><span class="small"><b>${questions.length}</b> câu${head ? ' · ' + esc(head) : ''}</span>
          <span class="row" style="margin:0"><span class="small muted">Thêm tất cả vào:</span>
          <button type="button" class="small" data-bulk="test">Bài kiểm tra</button><button type="button" class="small" data-bulk="practice">Bài ôn tập</button></span></div>` : '');
    const list = document.createElement('div');
    list.className = 'stack'; list.style.maxWidth = 'none';
    questions.forEach((q, i) => list.appendChild(makeCard(q, q.no || i + 1)));
    g.appendChild(list);
    g.addEventListener('click', (ev) => { const b = ev.target.closest('[data-bulk]'); if (b) addCards([...list.children], b.dataset.bulk); });
    container.appendChild(g);
    return g;
  }

  // ------------------------------------------------------------ TRÁI: nhập câu hỏi
  document.querySelectorAll('#p-import .seg button').forEach((b) => b.addEventListener('click', () => {
    document.querySelectorAll('#p-import .seg button').forEach((x) => x.classList.toggle('on', x === b));
    document.querySelectorAll('#p-import [data-pane]').forEach((p) => { p.hidden = p.dataset.pane !== b.dataset.tab; });
  }));
  const impRes = $('#imp-results');
  async function runParse(fields, file, btn) {
    impRes.innerHTML = '<div class="placeholder"><span class="spinner"></span>Đang tách câu hỏi…</div>';
    if (btn) btn.disabled = true;
    try {
      const j = await api('api/qparse.php', fields, file);
      impRes.innerHTML = '';
      renderGroup(impRes, j.questions, j.warnings, 'từ ' + j.source);
      window.ARSRenderMath?.(impRes);
    } catch (e) { impRes.innerHTML = `<ul class="warn-list"><li>${esc(e.message)}</li></ul>`; }
    if (btn) btn.disabled = false;
  }
  $('#imp-parse').addEventListener('click', (e) => runParse({ text: $('#imp-text').value }, null, e.target));
  $('#imp-clear').addEventListener('click', () => { $('#imp-text').value = ''; impRes.innerHTML = ''; });
  const drop = $('#imp-drop'), fileIn = $('#imp-file');
  fileIn.addEventListener('change', () => { if (fileIn.files[0]) runParse({}, fileIn.files[0]); fileIn.value = ''; });
  ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', (e) => { const f = e.dataTransfer.files[0]; if (f) runParse({}, f); });

  // ------------------------------------------------------------ PHẢI: trợ lý AI (soạn câu hỏi + hỏi đáp giảng dạy)
  const chat = $('#ai-chat'), form = $('#ai-form'), promptEl = $('#ai-prompt'), sendBtn = $('#ai-go');
  const welcome = $('#ai-empty').outerHTML;
  let mode = 'gen', busy = false, asked = [], turns = [];
  const PH = { gen: _('Mô tả câu hỏi cần soạn, ví dụ: 5 câu về các mức QoS của MQTT, có 1 câu tình huống'),
               chat: _('Hỏi trợ lý giảng dạy: giáo án, cách giảng phần khó, rubric chấm, hoạt động trên lớp…') };
  function setMode(m) {
    mode = m;
    document.querySelectorAll('.ai-mode button').forEach((b) => b.classList.toggle('on', b.dataset.mode === m));
    $('#ai-opts').classList.toggle('chat-mode', m === 'chat');
    promptEl.placeholder = PH[m];
  }
  document.querySelectorAll('.ai-mode button').forEach((b) => b.addEventListener('click', () => { setMode(b.dataset.mode); promptEl.focus(); }));
  $('#ai-reset').addEventListener('click', () => { if (busy) return; asked = []; turns = []; chat.innerHTML = welcome; bindCards(); promptEl.focus(); });
  function bindCards() {
    chat.querySelectorAll('.aw-card').forEach((c) => c.addEventListener('click', () => {
      const q = JSON.parse(c.dataset.card);
      setMode(q.mode);
      if (q.n) $('#ai-n').value = String(q.n);
      if (q.diff) $('#ai-diff').value = q.diff;
      if (q.src) $('#ai-src').value = q.src;
      submit(q.prompt);
    }));
  }
  bindCards();
  const autosize = () => { promptEl.style.height = 'auto'; promptEl.style.height = Math.min(160, promptEl.scrollHeight) + 'px'; };
  promptEl.addEventListener('input', autosize);
  promptEl.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); } });

  // markdown rút gọn cho câu trả lời hỏi đáp (### tiêu đề, **đậm**, *nghiêng*, `mã`, danh sách, đoạn)
  function md(src) {
    const inline = (x) => x.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/(^|[^*])\*(?!\s)(.+?)\*/g, '$1<i>$2</i>').replace(/`(.+?)`/g, '<code>$1</code>');
    let out = '', list = null;
    const close = () => { if (list) { out += `</${list}>`; list = null; } };
    for (const raw of esc(src).split('\n')) {
      const l = raw.trimEnd(); let m;
      if ((m = l.match(/^#{1,4}\s+(.*)/))) { close(); out += `<h4>${inline(m[1])}</h4>`; }
      else if ((m = l.match(/^\s*[-*•]\s+(.*)/))) { if (list !== 'ul') { close(); out += '<ul>'; list = 'ul'; } out += `<li>${inline(m[1])}</li>`; }
      else if ((m = l.match(/^\s*\d+[.)]\s+(.*)/))) { if (list !== 'ol') { close(); out += '<ol>'; list = 'ol'; } out += `<li>${inline(m[1])}</li>`; }
      else if (/^\s*\|.*\|\s*$/.test(l)) { close(); out += `<div class="md-row">${inline(l)}</div>`; }
      else if (!l.trim()) close();
      else { close(); out += `<p>${inline(l)}</p>`; }
    }
    close(); return out;
  }
  const math = (el) => { if (window.renderMathInElement) try { renderMathInElement(el, { delimiters: [{ left: '\\[', right: '\\]', display: true }, { left: '\\(', right: '\\)', display: false }, { left: '$$', right: '$$', display: true }], throwOnError: false, strict: 'ignore' }); } catch (e) {} };
  function msg(role, html) {
    $('#ai-empty')?.remove();
    const d = document.createElement('div');
    d.className = 'am ' + role;
    d.innerHTML = role === 'me' ? `<div class="am-b">${html}</div>` : `<div class="am-ava">✨</div><div class="am-body">${html}</div>`;
    chat.appendChild(d); chat.scrollTop = chat.scrollHeight;
    return d;
  }
  const typing = () => msg('bot', `<div class="am-typing"><span></span><span></span><span></span><em>${mode === 'gen' ? _('Đang soạn câu hỏi… (có thể mất 10–60 giây)') : _('Đang suy nghĩ…')}</em></div>`);
  function srcFields(src) {
    const f = {};
    if (src === 'left') f.source_text = $('#imp-text').value;
    else if (src && src.startsWith('lesson:')) f.lesson_id = src.slice(7);
    return f;
  }

  const FOLLOW = [
    ['harder', '🔥 ' + _('Khó hơn'), 'Khó hơn lượt trước: vận dụng cao, nhiều bước suy luận', 'kho'],
    ['easier', '🌱 ' + _('Dễ hơn'), 'Dễ hơn lượt trước: kiểm tra hiểu khái niệm cơ bản', 'de'],
    ['scenario', '🎭 ' + _('Thêm tình huống'), 'Chuyển thành câu hỏi tình huống thực tế gắn với đời sống / công việc'],
    ['distract', '🎯 ' + _('Phương án nhiễu tốt hơn'), 'Phương án nhiễu hợp lý hơn, dựa trên lỗi hiểu sai thường gặp, độ dài tương đương đáp án'],
    ['more', '↻ ' + _('Câu khác'), 'Tạo các câu khác hẳn những câu đã gợi ý'],
  ];
  async function generate(req) {
    busy = true; sendBtn.disabled = true;
    const srcSel = $('#ai-src');
    const srcName = req.src ? [...srcSel.options].find((o) => o.value === req.src)?.text || '' : _('không dùng tài liệu');
    const diffName = [...$('#ai-diff').options].find((o) => o.value === req.difficulty)?.text || '';
    msg('me', esc(req.prompt || _('(không có yêu cầu thêm)')) + `<small>${req.n} ${_('câu')} · ${req.n_options} ${_('lựa chọn')} · ${esc(diffName)} · ${esc(srcName)}</small>`);
    const wait = typing(); const t0 = performance.now();
    try {
      const j = await api('api/ai.php', { task: 'generate', prompt: req.prompt, n: req.n, n_options: req.n_options, difficulty: req.difficulty, history: asked,
        avoid_sets: [dst.test.value, dst.practice.value].filter((v) => /^\d+$/.test(v)).join(','), avoid_text: allStems(), ...srcFields(req.src) });
      if (req.prompt) asked.push(req.prompt);
      const body = wait.querySelector('.am-body');
      body.innerHTML = `<div class="am-meta">${_('Đã soạn')} <b>${j.questions.length}</b> ${_('câu')} · ${esc(j.provider)} · ${((performance.now() - t0) / 1000).toFixed(1)} s</div>`
        + (j.note ? `<div class="am-note">${esc(j.note)}</div>` : '') + `<div class="am-tip">${_('Xem lại, bấm Sửa nếu cần, rồi xác nhận từng câu.')}</div>`;
      const g = renderGroup(body, j.questions.map((q) => ({ ...q, no: undefined })), [], _('AI gợi ý'));
      g.querySelectorAll('.qc').forEach((c) => math(c));
      const f = document.createElement('div'); f.className = 'am-follow';
      f.innerHTML = FOLLOW.map(([k, label]) => `<button type="button" class="chip" data-f="${k}">${label}</button>`).join('');
      f.addEventListener('click', (e) => {
        const b = e.target.closest('[data-f]'); if (!b || busy) return;
        const [, label, text, diff] = FOLLOW.find((x) => x[0] === b.dataset.f);
        generate({ ...req, prompt: text, difficulty: diff || req.difficulty, label });
      });
      body.appendChild(f);
    } catch (e) {
      wait.querySelector('.am-body').innerHTML = `<div class="am-err">⚠ ${esc(e.message)}</div>`;
    }
    chat.scrollTop = chat.scrollHeight;
    busy = false; sendBtn.disabled = false;
  }
  async function ask(text, src) {
    busy = true; sendBtn.disabled = true;
    msg('me', esc(text).replace(/\n/g, '<br>'));
    const wait = typing();
    try {
      const j = await api('api/ai.php', { task: 'chat', prompt: text, turns, ...srcFields(src) });
      turns.push({ role: 'user', content: text }, { role: 'assistant', content: j.text });
      turns = turns.slice(-12);
      const body = wait.querySelector('.am-body');
      body.innerHTML = `<div class="am-md">${md(j.text)}</div><div class="am-acts"><button type="button" class="chip" data-a="copy">📋 ${_('Sao chép')}</button>`
        + `<button type="button" class="chip" data-a="again">↻ ${_('Trả lời khác')}</button><button type="button" class="chip" data-a="toq">🧩 ${_('Soạn câu hỏi từ nội dung này')}</button></div>`;
      math(body);
      body.querySelector('.am-acts').addEventListener('click', async (e) => {
        const b = e.target.closest('[data-a]'); if (!b || busy) return;
        if (b.dataset.a === 'copy') { try { await navigator.clipboard.writeText(j.text); b.textContent = '✅'; } catch (er) { alert(er.message); } }
        if (b.dataset.a === 'again') ask(_('Trả lời lại theo cách khác, cụ thể và thực tế hơn.'), src);
        if (b.dataset.a === 'toq') { setMode('gen'); generate({ prompt: 'Soạn câu hỏi trắc nghiệm kiểm tra các ý trong nội dung sau:\n' + j.text.slice(0, 3000), n: +$('#ai-n').value, n_options: +$('#ai-nopt').value, difficulty: $('#ai-diff').value, src: '' }); }
      });
    } catch (e) {
      wait.querySelector('.am-body').innerHTML = `<div class="am-err">⚠ ${esc(e.message)}</div>`;
    }
    chat.scrollTop = chat.scrollHeight;
    busy = false; sendBtn.disabled = false;
  }
  function submit(text) {
    if (busy) return;
    text = (text ?? promptEl.value).trim();
    const src = $('#ai-src').value;
    if (mode === 'chat') { if (!text) { promptEl.focus(); return; } ask(text, src); }
    else {
      if (!text && !src) { promptEl.focus(); promptEl.placeholder = _('Nhập yêu cầu hoặc chọn nguồn tài liệu…'); return; }
      generate({ prompt: text, n: +$('#ai-n').value, n_options: +$('#ai-nopt').value, difficulty: $('#ai-diff').value, src });
    }
    promptEl.value = ''; autosize();
  }
  form.addEventListener('submit', (e) => { e.preventDefault(); submit(); });
  function allStems() { return [...document.querySelectorAll('#ai-chat .qc')].map((c) => c.q.content).slice(-40); }

  // nút "Thêm tất cả vào bài kiểm tra": mọi câu AI gợi ý trong khung chat chưa thêm, chưa bỏ
  const addAll = $('#ai-addall');
  const pending = () => [...chat.querySelectorAll('.qc')].filter((c) => !c.classList.contains('removed') && !c.added.test);
  function addAllCount() {
    const n = pending().length;
    $('#ai-addall-n').textContent = n; addAll.disabled = n === 0 || busy;
    addAll.classList.toggle('primary', n > 0);
  }
  addAll.addEventListener('click', async () => {
    const cards = pending(); if (!cards.length) return;
    const s = C.sets.find((x) => String(x.id) === dst.test.value);
    if (s && !confirm(_('Thêm {n} câu vào bài kiểm tra “{s}”?').replace('{n}', cards.length).replace('{s}', s.title))) return;
    addAll.disabled = true;
    await addCards(cards, 'test');
    addAllCount();
  });
  new MutationObserver(addAllCount).observe(chat, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });

  // khung AI vừa khít phần màn hình còn lại (ô nhập + nút luôn thấy), giãn dần khi cuộn rồi dính ở đầu trang
  const aiPanel = $('#p-ai');
  function fitPanel() {
    if (innerWidth <= 1100) { aiPanel.style.height = ''; return; }
    const top = Math.max(76, aiPanel.getBoundingClientRect().top);
    aiPanel.style.height = Math.max(480, innerHeight - top - 12) + 'px';
  }
  let fitQ = 0;
  const fitSoon = () => { if (!fitQ) fitQ = requestAnimationFrame(() => { fitQ = 0; fitPanel(); }); };
  addEventListener('scroll', fitSoon, { passive: true }); addEventListener('resize', fitSoon); fitPanel();

  setMode('gen');
  fillTargets();
  // mở từ trang buổi học ("Soạn bằng AI từ bài giảng"): tự tạo 5 câu củng cố
  if (new URLSearchParams(location.search).get('auto') === '1' && $('#ai-src').value) {
    window.history.replaceState(null, '', location.pathname + '?lesson=' + C.pre.lesson);
    generate({ prompt: 'Câu hỏi củng cố cuối buổi: ngắn gọn, bám sát kiến thức trọng tâm của buổi học', n: 5, n_options: 4, difficulty: 'mix', src: $('#ai-src').value });
  }
})();
