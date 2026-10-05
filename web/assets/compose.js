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
        + mine.map((s) => `<option value="${s.id}">${esc(s.title)} (${s.n} câu)</option>`).join('')
        + `<option value="new">＋ Tạo bộ ${PURPOSE[p]} mới…</option>`;
      sel.value = mine.some((s) => String(s.id) === keep) ? keep : (mine[0] ? String(mine[0].id) : '');
      sel.dataset.prev = sel.value;
    }
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
      if (sel.value !== 'new') { sel.dataset.prev = sel.value; return; }
      try {
        const id = await createSet(p);
        fillTargets();
        sel.value = id ? String(id) : sel.dataset.prev;
        sel.dataset.prev = sel.value;
      } catch (e) { alert(e.message); sel.value = sel.dataset.prev; }
    });
  }
  function setCount(id, n) { const s = C.sets.find((x) => x.id === id); if (s) { s.n = n; const o = document.querySelector(`#dst-${s.purpose} option[value="${id}"]`); if (o) o.textContent = `${s.title} (${n} câu)`; } }
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
      if (act === 'done') { readEdit(el); el.classList.remove('editing'); $('.view', el).outerHTML = viewHtml(el.q, el.no); $('.edit-wrap', el).innerHTML = ''; }
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

  // ------------------------------------------------------------ PHẢI: trợ lý AI
  const chat = $('#ai-chat'), form = $('#ai-form'), promptEl = $('#ai-prompt');
  document.querySelectorAll('#ai-form .chips button').forEach((b) => b.addEventListener('click', () => {
    promptEl.value = (promptEl.value.trim() ? promptEl.value.trim() + '. ' : '') + b.dataset.p; promptEl.focus();
  }));
  promptEl.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) form.requestSubmit(); });
  const bubble = (html, cls) => { const d = document.createElement('div'); d.className = 'bubble ' + (cls || ''); d.innerHTML = html; chat.appendChild(d); d.scrollIntoView({ behavior: 'smooth', block: 'end' }); return d; };
  function allStems() { return [...document.querySelectorAll('#ai-chat .qc')].map((c) => c.q.content).slice(-40); }

  async function generate(req) {
    $('#ai-empty')?.remove();
    const srcSel = $('#ai-src');
    const srcName = srcSel.value ? srcSel.options[srcSel.selectedIndex].text : 'không dùng tài liệu';
    bubble(esc(req.prompt || '(không có yêu cầu thêm)') + `<small>${req.n} câu · ${req.n_options} lựa chọn · ${esc($('#ai-diff').options[$('#ai-diff').selectedIndex].text)} · nguồn: ${esc(srcName)}</small>`);
    const wait = bubble('<span class="spinner"></span>Đang soạn câu hỏi… (có thể mất 10–60 giây)', 'bot');
    const btn = $('#ai-go'); btn.disabled = true;
    const t0 = performance.now();
    try {
      const fields = { task: 'generate', prompt: req.prompt, n: req.n, n_options: req.n_options, difficulty: req.difficulty,
        avoid_sets: [dst.test.value, dst.practice.value].filter((v) => /^\d+$/.test(v)).join(','), avoid_text: allStems() };
      if (req.src === 'left') fields.source_text = $('#imp-text').value;
      else if (req.src.startsWith('lesson:')) fields.lesson_id = req.src.slice(7);
      const j = await api('api/ai.php', fields);
      wait.innerHTML = `Đã soạn <b>${j.questions.length}</b> câu · ${esc(j.provider)} · ${((performance.now() - t0) / 1000).toFixed(1)} s`
        + (j.note ? `<small>${esc(j.note)}</small>` : '') + '<small>Xem lại, bấm Sửa nếu cần, rồi xác nhận từng câu.</small>';
      const g = renderGroup(chat, j.questions.map((q) => ({ ...q, no: undefined })), [], 'AI gợi ý');
      const more = document.createElement('div');
      more.className = 'row';
      more.innerHTML = '<button type="button" class="small ghost">↻ Tạo thêm câu khác</button>';
      more.firstChild.addEventListener('click', () => generate({ ...req, prompt: req.prompt + (req.prompt ? '. ' : '') + 'Tạo các câu khác với những câu đã gợi ý.' }));
      g.appendChild(more);
    } catch (e) {
      wait.className = 'bubble bot err'; wait.textContent = e.message;
    }
    btn.disabled = false;
  }
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const req = { prompt: promptEl.value.trim(), n: +$('#ai-n').value, n_options: +$('#ai-nopt').value, difficulty: $('#ai-diff').value, src: $('#ai-src').value };
    if (!req.prompt && !req.src) { promptEl.focus(); promptEl.placeholder = 'Nhập yêu cầu hoặc chọn nguồn tài liệu…'; return; }
    promptEl.value = '';
    generate(req);
  });

  fillTargets();
  // mở từ trang buổi học ("Soạn bằng AI từ bài giảng"): tự tạo 5 câu củng cố
  if (new URLSearchParams(location.search).get('auto') === '1' && $('#ai-src').value) {
    history.replaceState(null, '', location.pathname + '?lesson=' + C.pre.lesson);
    generate({ prompt: 'Câu hỏi củng cố cuối buổi: ngắn gọn, bám sát kiến thức trọng tâm của buổi học', n: 5, n_options: 4, difficulty: 'mix', src: $('#ai-src').value });
  }
})();
