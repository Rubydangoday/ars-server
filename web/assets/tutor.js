// Trợ lý học tập "Cú Học" (cổng học sinh)
(function () {
  'use strict';
  const T = window.TUTOR;
  const CSRF = document.querySelector('meta[name=csrf]').content;
  const $ = (s, el) => (el || document).querySelector(s);
  const log = $('#t-log'), text = $('#t-text'), form = $('#t-form'), sendBtn = $('#t-send');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const LET = 'ABCDEFGHIJ';
  let mode = 'ask', busy = false;

  // trình độ nhớ trên máy
  try { const lv = localStorage.getItem('ars_level'); if (lv) $('#t-level').value = lv; } catch (e) {}
  $('#t-level').addEventListener('change', () => { try { localStorage.setItem('ars_level', $('#t-level').value); } catch (e) {} });

  async function api(fields) {
    const fd = new FormData(); fd.append('csrf', CSRF);
    for (const [k, v] of Object.entries(fields)) if (v !== undefined && v !== null) fd.append(k, v);
    let r;
    try { r = await fetch('api_tutor.php', { method: 'POST', body: fd, credentials: 'same-origin' }); } catch (e) { throw new Error('Mất kết nối tới máy chủ lớp học.'); }
    const t = await r.text(); let j;
    try { j = JSON.parse(t); } catch (e) { throw new Error(t.slice(0, 160) || 'Lỗi ' + r.status); }
    if (!r.ok || j.error) throw new Error(j.error || 'Lỗi ' + r.status);
    return j;
  }

  // markdown rút gọn: ###, **đậm**, *nghiêng*, `mã`, danh sách, đoạn
  function md(src) {
    const lines = esc(src).split('\n'); let out = '', list = null;
    const inline = (s) => s.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/(^|[^*])\*(?!\s)(.+?)\*/g, '$1<i>$2</i>').replace(/_\((.+?)\)_/g, '<small class="muted">($1)</small>').replace(/`(.+?)`/g, '<code>$1</code>');
    const close = () => { if (list) { out += `</${list}>`; list = null; } };
    for (const raw of lines) {
      const l = raw.trimEnd();
      let m;
      if ((m = l.match(/^#{1,4}\s+(.*)/))) { close(); out += `<h4>${inline(m[1])}</h4>`; }
      else if ((m = l.match(/^\s*[-*•]\s+(.*)/))) { if (list !== 'ul') { close(); out += '<ul>'; list = 'ul'; } out += `<li>${inline(m[1])}</li>`; }
      else if ((m = l.match(/^\s*\d+[.)]\s+(.*)/))) { if (list !== 'ol') { close(); out += '<ol>'; list = 'ol'; } out += `<li>${inline(m[1])}</li>`; }
      else if (!l.trim()) { close(); }
      else { close(); out += `<p>${inline(l)}</p>`; }
    }
    close();
    return out;
  }

  function stats(s) {
    if (!s) return;
    const xp = document.querySelector('.b-xp'), fire = document.querySelector('.b-fire'), lv = document.querySelector('.b-lv');
    if (xp) { const old = parseInt(xp.textContent.replace(/\D/g, '')) || 0; xp.textContent = '⭐ ' + s.xp; if (s.xp > old) { xp.classList.remove('pop'); void xp.offsetWidth; xp.classList.add('pop'); } }
    if (fire) { fire.textContent = '🔥 ' + s.streak; fire.classList.toggle('on', s.today); }
    if (lv) lv.textContent = 'Cấp ' + s.level;
  }

  function quizHtml(m) {
    return '<div class="t-quiz">' + m.quiz.map((q, i) => {
      const a = q.answered;
      return `<div class="tq ${a ? (a.ok ? 'ok' : 'ng') : ''}" data-mid="${m.id}" data-i="${i}">
        <div class="tq-head"><span class="tq-no">Câu ${i + 1}</span>${q.topic ? `<span class="chip">${esc(q.topic)}</span>` : ''}${q.difficulty === 'kho' ? '<span class="chip hot">Nâng cao</span>' : ''}</div>
        <div class="tq-text">${esc(q.content)}</div>
        <div class="tq-opts">${q.options.map((o, k) => {
          const L = LET[k]; let cls = '';
          if (a) { if (L === a.correct) cls = 'right'; else if (L === a.answer) cls = 'wrong'; }
          return `<button type="button" class="opt o${k % 4} ${cls}" data-l="${L}" ${a ? 'disabled' : ''}><b>${L}</b><span>${esc(o)}</span></button>`;
        }).join('')}</div>
        <div class="tq-fb">${a ? fb(a) : ''}</div></div>`;
    }).join('') + '</div>';
  }
  const fb = (a) => `<div class="fb ${a.ok ? 'good' : 'bad'}">${a.ok ? '🎉 Chính xác! +5 ⭐' : '💪 Chưa đúng — đáp án là ' + a.correct + '.'}</div>${a.explanation ? `<div class="ex">${md(a.explanation)}</div>` : ''}`;

  function render(m, animate) {
    $('#t-welcome')?.remove();
    const d = document.createElement('div');
    d.className = 'msg ' + (m.role === 'user' ? 'me' : 'bot') + (animate ? ' in' : '');
    d.innerHTML = m.role === 'user'
      ? `<div class="bubble">${esc(m.content).replace(/\n/g, '<br>')}</div>`
      : `<div class="ava">🦉</div><div class="bubble">${md(m.content)}${m.quiz ? quizHtml(m) : ''}
          <div class="acts">
          <button type="button" data-follow="quiz">🎯 Cho mình câu hỏi ôn tập</button><button type="button" data-follow="explain">📖 Giải thích kỹ hơn</button>
          ${m.quiz ? '<button type="button" data-follow="more">➕ Thêm câu khác</button>' : ''}</div></div>`;
    log.appendChild(d);
    return d;
  }
  const scrollEnd = () => log.scrollTo({ top: log.scrollHeight, behavior: 'smooth' });

  // chấm câu hỏi
  log.addEventListener('click', async (e) => {
    const o = e.target.closest('button.opt');
    if (o) {
      const box = o.closest('.tq'); if (box.classList.contains('ok') || box.classList.contains('ng')) return;
      box.querySelectorAll('button.opt').forEach((b) => { b.disabled = true; });
      o.classList.add('picked');
      try {
        const j = await api({ action: 'answer', message_id: box.dataset.mid, q_index: box.dataset.i, answer: o.dataset.l });
        box.classList.add(j.ok ? 'ok' : 'ng');
        box.querySelectorAll('button.opt').forEach((b) => { if (b.dataset.l === j.correct) b.classList.add('right'); else if (b.dataset.l === j.answer) b.classList.add('wrong'); });
        box.querySelector('.tq-fb').innerHTML = fb({ ok: j.ok, correct: j.correct, explanation: j.explanation });
        if (j.ok) confetti(o);
        stats(j.stats);
        const all = box.parentElement.querySelectorAll('.tq'), done = box.parentElement.querySelectorAll('.tq.ok, .tq.ng');
        if (all.length === done.length) {
          const ok = box.parentElement.querySelectorAll('.tq.ok').length;
          box.parentElement.insertAdjacentHTML('beforeend', `<div class="t-score">${ok === all.length ? '🏆' : ok >= all.length / 2 ? '👍' : '📚'} Bạn đúng <b>${ok}/${all.length}</b> câu. ${ok === all.length ? 'Tuyệt vời!' : 'Xem lại giải thích và thử thêm câu khác nhé!'}</div>`);
        }
      } catch (err) { box.querySelectorAll('button.opt').forEach((b) => { b.disabled = false; }); alert(err.message); }
      return;
    }
    const f = e.target.closest('[data-follow]');
    if (f) {
      const t = f.dataset.follow;
      if (t === 'more') send({ mode: 'quiz', text: 'Cho mình thêm các câu khác với các câu vừa rồi.' });
      else send({ mode: t, text: t === 'explain' ? 'Giải thích kỹ hơn nội dung vừa rồi giúp mình.' : '' });
    }
  });

  function confetti(el) {
    const r = el.getBoundingClientRect();
    for (let i = 0; i < 14; i++) {
      const s = document.createElement('span'); s.className = 'confetti';
      s.textContent = ['⭐', '✨', '🎉', '💖', '💙'][i % 5];
      s.style.left = (r.left + r.width / 2) + 'px'; s.style.top = (r.top + window.scrollY) + 'px';
      s.style.setProperty('--dx', (Math.random() * 200 - 100) + 'px'); s.style.setProperty('--dy', (-60 - Math.random() * 120) + 'px');
      document.body.appendChild(s); setTimeout(() => s.remove(), 1000);
    }
  }

  // chế độ
  function setMode(m) {
    mode = m;
    document.querySelectorAll('.modes .mode').forEach((b) => b.classList.toggle('on', b.dataset.mode === m));
    const [ico, label] = T.modes[m];
    $('#t-mode-pill').innerHTML = `<span class="m-${m}">${ico} ${label}</span>`;
    text.placeholder = { ask: 'Hỏi Cú Học bất cứ điều gì về bài học…', explain: 'Phần nào bạn muốn được giảng lại chi tiết? (hoặc để trống để dùng chủ đề đã chọn)',
      quiz: 'Muốn ôn phần nào? Bấm Gửi để nhận câu hỏi', advanced: 'Muốn thử sức phần nào? Bấm Gửi để nhận bài nâng cao',
      summary: 'Chọn buổi học ở bên trái rồi bấm Gửi', plan: 'Bạn còn bao nhiêu ngày tới bài kiểm tra? (không bắt buộc)' }[m];
  }
  document.querySelectorAll('.modes .mode').forEach((b) => b.addEventListener('click', () => { setMode(b.dataset.mode); text.focus(); }));
  document.querySelectorAll('.weak').forEach((b) => b.addEventListener('click', () => {
    $('#t-topic').value = b.dataset.topic; if (mode === 'ask') setMode('explain'); text.focus();
  }));

  async function send(o) {
    if (busy) return;
    const m = o.mode || mode;
    const fields = { action: 'send', mode: m, text: o.text ?? text.value.trim(), topic: o.topic ?? $('#t-topic').value.trim(),
      lesson_id: o.lesson ?? $('#t-lesson').value, level: $('#t-level').value, n: $('#t-n').value, question_id: o.qid || '' };
    if (m === 'ask' && !fields.text && !fields.question_id) { text.focus(); return; }
    busy = true; sendBtn.disabled = true;
    const preview = { role: 'user', content: fields.text || `${T.modes[m][0]} ${T.modes[m][1]}${fields.topic ? ' · ' + fields.topic : ''}` };
    render(preview, true);
    text.value = ''; autosize();
    const wait = document.createElement('div');
    wait.className = 'msg bot in'; wait.innerHTML = '<div class="ava">🦉</div><div class="bubble typing"><span></span><span></span><span></span></div>';
    log.appendChild(wait); scrollEnd();
    try {
      const j = await api(fields);
      wait.remove();
      render(j.message, true); stats(j.stats); scrollEnd();
    } catch (e) {
      wait.querySelector('.bubble').className = 'bubble err'; wait.querySelector('.bubble').textContent = e.message;
    }
    busy = false; sendBtn.disabled = false;
  }
  form.addEventListener('submit', (e) => { e.preventDefault(); send({}); });
  text.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send({}); } });
  function autosize() { text.style.height = 'auto'; text.style.height = Math.min(160, text.scrollHeight) + 'px'; }
  text.addEventListener('input', autosize);
  log.addEventListener('click', (e) => {
    const b = e.target.closest('.sugg button'); if (!b) return;
    const q = JSON.parse(b.dataset.q);
    if (q.topic) $('#t-topic').value = q.topic;
    if (q.lesson) $('#t-lesson').value = q.lesson;
    setMode(q.mode);
    send({ mode: q.mode, topic: q.topic, lesson: q.lesson, qid: q.qid, text: q.qid ? 'Vì sao đáp án của mình sai ở câu này? Giảng lại giúp mình phần kiến thức liên quan.' : '' });
  });
  $('#t-clear').addEventListener('click', async () => {
    if (!confirm('Xoá toàn bộ lịch sử trò chuyện? (Điểm thưởng vẫn được giữ)')) return;
    await api({ action: 'clear' }); location.href = 'tutor.php';
  });

  // tải lịch sử
  setMode(T.modes[T.pre.mode] ? T.pre.mode : 'ask');
  api({ action: 'history' }).then((j) => {
    j.messages.forEach((m) => render(m, false));
    log.scrollTop = log.scrollHeight;
    if (T.pre.qid) send({ mode: T.pre.mode || 'explain', qid: T.pre.qid, text: 'Vì sao đáp án của mình sai ở câu này? Giảng lại giúp mình phần kiến thức liên quan.' });
    else if (T.pre.mode && T.pre.mode !== 'ask' && (T.pre.topic || T.pre.lesson)) send({ mode: T.pre.mode });
  }).catch(() => {});
})();
