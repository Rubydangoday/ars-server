// Trợ lý học tập "Cú Học" (cổng học sinh) — giao diện kiểu ứng dụng chat AI:
// nhiều cuộc trò chuyện, chữ hiện dần, công thức (KaTeX), sao chép / đọc to / 👍👎, nhập bằng giọng nói, câu hỏi ôn tập chấm ngay.
(function () {
  'use strict';
  const T = window.TUTOR;
  const _ = (s) => (window.ARS_I18N || {})[s] || s;            // dịch giao diện (lang/en.php)
  const EN = document.documentElement.lang === 'en', VOICE = EN ? 'en-US' : 'vi-VN';
  const CSRF = document.querySelector('meta[name=csrf]').content;
  const $ = (s, el) => (el || document).querySelector(s);
  const log = $('#ct-log'), text = $('#t-text'), form = $('#ct-form'), sendBtn = $('#t-send');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const LET = 'ABCDEFGHIJ';
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const welcomeHTML = $('#ct-welcome').outerHTML;
  let mode = 'ask', busy = false, conv = 0, convs = [];

  // trình độ nhớ trên máy
  try { const lv = localStorage.getItem('ars_level'); if (lv) $('#t-level').value = lv; } catch (e) {}
  $('#t-level').addEventListener('change', () => { try { localStorage.setItem('ars_level', $('#t-level').value); } catch (e) {} ctxSum(); });

  async function api(fields) {
    const fd = new FormData(); fd.append('csrf', CSRF);
    for (const [k, v] of Object.entries(fields)) if (v !== undefined && v !== null) fd.append(k, v);
    let r;
    try { r = await fetch('api_tutor.php', { method: 'POST', body: fd, credentials: 'same-origin' }); } catch (e) { throw new Error(_('Mất kết nối tới máy chủ lớp học.')); }
    const t = await r.text(); let j;
    try { j = JSON.parse(t); } catch (e) { throw new Error(t.slice(0, 160) || _('Lỗi') + ' ' + r.status); }
    if (!r.ok || j.error) throw new Error(j.error || _('Lỗi') + ' ' + r.status);
    return j;
  }

  // ------------------------------------------------------------------ markdown rút gọn
  // ### tiêu đề, **đậm**, *nghiêng*, `mã`, ```khối mã```, danh sách, > trích, ---, đoạn. Công thức $…$ \( \) giữ nguyên cho KaTeX.
  function md(src) {
    const parts = String(src).split(/```(\w*)\n?([\s\S]*?)(?:```|$)/);
    let out = '';
    for (let p = 0; p < parts.length; p += 3) {
      out += mdText(parts[p]);
      if (p + 2 < parts.length) out += `<pre class="ct-code"><code>${esc(parts[p + 2].replace(/\n$/, ''))}</code></pre>`;
    }
    return out;
  }
  function mdText(src) {
    const lines = esc(src).split('\n'); let out = '', list = null;
    const inline = (s) => s.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/(^|[^*])\*(?!\s)(.+?)\*/g, '$1<i>$2</i>')
      .replace(/_\((.+?)\)_/g, '<small class="muted">($1)</small>').replace(/`(.+?)`/g, '<code>$1</code>');
    const close = () => { if (list) { out += `</${list}>`; list = null; } };
    for (const raw of lines) {
      const l = raw.trimEnd(); let m;
      if ((m = l.match(/^#{1,4}\s+(.*)/))) { close(); out += `<h4>${inline(m[1])}</h4>`; }
      else if (/^\s*(-{3,}|\*{3,})\s*$/.test(l)) { close(); out += '<hr>'; }
      else if ((m = l.match(/^&gt;\s?(.*)/))) { close(); out += `<blockquote>${inline(m[1])}</blockquote>`; }
      else if ((m = l.match(/^\s*[-*•]\s+(.*)/))) { if (list !== 'ul') { close(); out += '<ul>'; list = 'ul'; } out += `<li>${inline(m[1])}</li>`; }
      else if ((m = l.match(/^\s*\d+[.)]\s+(.*)/))) { if (list !== 'ol') { close(); out += '<ol>'; list = 'ol'; } out += `<li>${inline(m[1])}</li>`; }
      else if (!l.trim()) close();
      else { close(); out += `<p>${inline(l)}</p>`; }
    }
    close();
    return out;
  }
  const math = (el) => { if (window.renderMathInElement) try { renderMathInElement(el, { delimiters: [{ left: '$$', right: '$$', display: true }, { left: '\\[', right: '\\]', display: true }, { left: '\\(', right: '\\)', display: false }], throwOnError: false, trust: false }); } catch (e) {} };
  const plain = (s) => String(s).replace(/```[\s\S]*?```/g, ' ').replace(/[#*_`>]/g, '').replace(/\$+/g, '').replace(/\s+/g, ' ').trim();

  function stats(s) {
    if (!s) return;
    const xp = document.querySelector('.b-xp'), fire = document.querySelector('.b-fire'), lv = document.querySelector('.b-lv');
    if (xp) { const old = parseInt(xp.textContent.replace(/\D/g, ''), 10) || 0; xp.textContent = '⭐ ' + s.xp; if (s.xp > old) { xp.classList.remove('pop'); void xp.offsetWidth; xp.classList.add('pop'); } }
    if (fire) { fire.textContent = '🔥 ' + s.streak; fire.classList.toggle('on', s.today); }
    if (lv) lv.textContent = _('Cấp') + ' ' + s.level;
  }

  // ------------------------------------------------------------------ câu hỏi ôn tập trong tin nhắn
  const fb = (a) => `<div class="fb ${a.ok ? 'good' : 'bad'}">${a.ok ? '🎉 ' + _('Chính xác! +5 ⭐') : '💪 ' + _('Chưa đúng — đáp án là') + ' ' + a.correct + '.'}</div>${a.explanation ? `<div class="ex">${esc(a.explanation)}</div>` : ''}`;
  function quizHtml(m) {
    return '<div class="t-quiz">' + m.quiz.map((q, i) => {
      const a = q.answered;
      return `<div class="tq ${a ? (a.ok ? 'ok' : 'ng') : ''}" data-mid="${m.id}" data-i="${i}">
        <div class="tq-head"><span class="tq-no">${_('Câu')} ${i + 1}</span>${q.topic ? `<span class="chip">${esc(q.topic)}</span>` : ''}${q.difficulty === 'kho' ? `<span class="chip hot">${_('Nâng cao')}</span>` : ''}</div>
        <div class="tq-text">${esc(q.content)}</div>
        <div class="tq-opts">${q.options.map((o, k) => {
          const L = LET[k]; let cls = '';
          if (a) { if (L === a.correct) cls = 'right'; else if (L === a.answer) cls = 'wrong'; }
          return `<button type="button" class="opt o${k % 4} ${cls}" data-l="${L}" ${a ? 'disabled' : ''}><b translate="no">${L}</b><span>${esc(o)}</span></button>`;
        }).join('')}</div>
        <div class="tq-fb">${a ? fb(a) : ''}</div></div>`;
    }).join('') + '</div>';
  }

  // ------------------------------------------------------------------ tin nhắn
  function actions(m) {
    return `<div class="ct-acts">
      <button type="button" data-act="copy" title="${_('Sao chép')}">📋</button>
      <button type="button" data-act="speak" title="${_('Đọc to')}">🔊</button>
      <button type="button" data-act="up" title="${_('Hữu ích')}" class="${m.feedback === 1 ? 'on' : ''}">👍</button>
      <button type="button" data-act="down" title="${_('Chưa tốt')}" class="${m.feedback === -1 ? 'on' : ''}">👎</button>
      <span class="sep"></span>
      <button type="button" data-follow="again" class="pill">🔄 ${_('Giải thích cách khác')}</button>
      <button type="button" data-follow="quiz" class="pill">🎯 ${_('Câu hỏi ôn tập')}</button>
      ${m.quiz ? '<button type="button" data-follow="more" class="pill">➕ ' + _('Thêm câu khác') + '</button>' : '<button type="button" data-follow="explain" class="pill">📖 ' + _('Kỹ hơn') + '</button>'}</div>`;
  }
  function render(m, animate) {
    $('#ct-welcome')?.remove();
    const d = document.createElement('div');
    d.className = 'ct-msg ' + (m.role === 'user' ? 'me' : 'bot');
    d.dataset.id = m.id || '';
    if (m.role === 'user') {
      d.innerHTML = `<div class="ct-bubble">${esc(m.content).replace(/\n/g, '<br>')}</div>`;
      log.appendChild(d); return d;
    }
    d.innerHTML = `<div class="ct-ava">🦉</div><div class="ct-body"><div class="ct-name">Cú Học</div><div class="ct-content"></div><div class="ct-quizbox"></div><div class="ct-actbox"></div></div>`;
    d._raw = m.content; d._msg = m;
    log.appendChild(d);
    const box = $('.ct-content', d);
    const finish = () => {
      box.innerHTML = md(m.content); math(box);
      if (m.quiz) $('.ct-quizbox', d).innerHTML = quizHtml(m);
      $('.ct-actbox', d).innerHTML = actions(m);
      scrollEnd();
    };
    if (!animate || reduce || m.content.length > 5000) { finish(); return d; }
    // chữ hiện dần (hiệu ứng "đang gõ")
    let n = 0; const step = Math.max(3, Math.round(m.content.length / 90));
    (function tick() {
      n = Math.min(m.content.length, n + step);
      box.innerHTML = md(m.content.slice(0, n)) + '<span class="ct-caret"></span>';
      if (n % (step * 6) < step) scrollEnd(true);
      if (n < m.content.length) setTimeout(tick, 16); else finish();
    })();
    return d;
  }
  const scrollEnd = (instant) => log.scrollTo({ top: log.scrollHeight, behavior: instant ? 'auto' : 'smooth' });

  log.addEventListener('click', async (e) => {
    // chấm câu hỏi
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
        if (j.ok) { const r = o.getBoundingClientRect(); window.ARSFX?.confetti({ count: 50, x: (r.left + r.width / 2) / innerWidth, y: r.top / innerHeight, spread: .6 }); window.ARSFX?.play('correct'); }
        else window.ARSFX?.play('wrong');
        stats(j.stats);
        const quiz = box.parentElement, all = quiz.querySelectorAll('.tq'), done = quiz.querySelectorAll('.tq.ok, .tq.ng');
        if (all.length === done.length) {
          const ok = quiz.querySelectorAll('.tq.ok').length;
          quiz.insertAdjacentHTML('beforeend', `<div class="t-score">${ok === all.length ? '🏆' : ok >= all.length / 2 ? '👍' : '📚'} ${_('Bạn đúng')} <b>${ok}/${all.length}</b>. ${ok === all.length ? _('Xuất sắc!') : _('Xem lại giải thích các câu sai nhé.')}</div>`);
          if (ok === all.length) window.ARSFX?.confetti();
        }
      } catch (err) { box.querySelectorAll('button.opt').forEach((b) => { b.disabled = false; }); alert(err.message); }
      return;
    }
    const msg = e.target.closest('.ct-msg.bot');
    const a = e.target.closest('[data-act]');
    if (a && msg) {
      const act = a.dataset.act;
      if (act === 'copy') {
        try { await navigator.clipboard.writeText(msg._raw); } catch (err) { const t = document.createElement('textarea'); t.value = msg._raw; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); }
        a.textContent = '✅'; setTimeout(() => { a.textContent = '📋'; }, 1200);
      } else if (act === 'speak') {
        if (!('speechSynthesis' in window)) return alert(_('Trình duyệt chưa hỗ trợ đọc to.'));
        if (speechSynthesis.speaking) { speechSynthesis.cancel(); a.classList.remove('on'); return; }
        const u = new SpeechSynthesisUtterance(plain(msg._raw)); u.lang = VOICE; u.rate = 1;
        const v = speechSynthesis.getVoices().find((x) => x.lang && x.lang.toLowerCase().startsWith(EN ? 'en' : 'vi')); if (v) u.voice = v;
        u.onend = () => a.classList.remove('on'); a.classList.add('on'); speechSynthesis.speak(u);
      } else if (act === 'up' || act === 'down') {
        const on = !a.classList.contains('on');
        msg.querySelectorAll('[data-act=up],[data-act=down]').forEach((b) => b.classList.remove('on'));
        if (on) a.classList.add('on');
        api({ action: 'feedback', message_id: msg.dataset.id, value: on ? (act === 'up' ? 1 : -1) : 0 }).catch(() => {});
      }
      return;
    }
    const f = e.target.closest('[data-follow]');
    if (f) {
      const t = f.dataset.follow;
      if (t === 'more') send({ mode: 'quiz', text: _('Cho mình thêm các câu khác với các câu vừa rồi.') });
      else if (t === 'again') send({ mode: 'ask', text: _('Bạn giải thích lại theo cách khác, đơn giản hơn và có ví dụ gần gũi giúp mình nhé.') });
      else send({ mode: t, text: t === 'explain' ? _('Giải thích kỹ hơn nội dung vừa rồi giúp mình.') : '' });
      return;
    }
    const card = e.target.closest('.ct-card');
    if (card) {
      const q = JSON.parse(card.dataset.q);
      if (q.topic) $('#t-topic').value = q.topic;
      if (q.lesson) $('#t-lesson').value = q.lesson;
      setMode(q.mode); ctxSum();
      send({ mode: q.mode, topic: q.topic, lesson: q.lesson, qid: q.qid,
             text: q.text || (q.qid ? _('Vì sao đáp án của mình sai ở câu này? Giảng lại giúp mình phần kiến thức liên quan.') : '') });
    }
  });

  // ------------------------------------------------------------------ công cụ (chế độ) và ngữ cảnh
  const PH = { ask: 'Hỏi Cú Học bất cứ điều gì về bài học…', explain: 'Phần nào bạn muốn được giảng lại? (để trống = chủ đề đang chọn)',
    quiz: 'Muốn ôn phần nào? Bấm ➤ để nhận câu hỏi', advanced: 'Muốn thử sức phần nào? Bấm ➤ để nhận bài nâng cao',
    summary: 'Chọn buổi học ở ⚙ rồi bấm ➤', plan: 'Còn bao nhiêu ngày tới bài kiểm tra? (không bắt buộc)' };
  function setMode(m) {
    mode = T.modes[m] ? m : 'ask';
    document.querySelectorAll('.ct-tool').forEach((b) => { const on = b.dataset.mode === mode; b.classList.toggle('on', on); b.setAttribute('aria-checked', on); });
    text.placeholder = _(PH[mode]);
  }
  document.querySelectorAll('.ct-tool').forEach((b) => b.addEventListener('click', () => { setMode(b.dataset.mode === mode ? 'ask' : b.dataset.mode); text.focus(); }));
  const ctxBox = $('#ct-ctx'), ctxBtn = $('#ct-ctx-btn');
  ctxBtn.addEventListener('click', () => { ctxBox.hidden = !ctxBox.hidden; ctxBtn.setAttribute('aria-expanded', String(!ctxBox.hidden)); ctxBtn.classList.toggle('on', !ctxBox.hidden); });
  function ctxSum() {
    const l = $('#t-lesson'), parts = [];
    if (l.value) parts.push('📚 ' + l.options[l.selectedIndex].text);
    if ($('#t-topic').value.trim()) parts.push('🏷 ' + $('#t-topic').value.trim());
    parts.push('🎓 ' + $('#t-level').options[$('#t-level').selectedIndex].text);
    $('#ct-ctx-sum').innerHTML = parts.map((p) => `<span>${esc(p)}</span>`).join('');
  }
  ['#t-lesson', '#t-topic'].forEach((s) => $(s).addEventListener('change', ctxSum));
  document.querySelectorAll('.weak').forEach((b) => b.addEventListener('click', () => {
    $('#t-topic').value = b.dataset.topic; ctxSum(); if (mode === 'ask') setMode('explain'); closeSide(); text.focus();
  }));

  // ------------------------------------------------------------------ gửi
  async function send(o) {
    if (busy) return;
    const m = o.mode || mode;
    const fields = { action: 'send', mode: m, conversation_id: conv, text: o.text ?? text.value.trim(), topic: o.topic ?? $('#t-topic').value.trim(),
      lesson_id: o.lesson ?? $('#t-lesson').value, level: $('#t-level').value, n: $('#t-n').value, question_id: o.qid || '' };
    if (m === 'ask' && !fields.text && !fields.question_id) { text.focus(); return; }
    busy = true; sendBtn.disabled = true;
    render({ role: 'user', content: fields.text || `${T.modes[m][0]} ${_(T.modes[m][1])}${fields.topic ? ' · ' + fields.topic : ''}` }, true);
    text.value = ''; autosize();
    const wait = document.createElement('div');
    wait.className = 'ct-msg bot'; wait.innerHTML = '<div class="ct-ava think">🦉</div><div class="ct-body"><div class="ct-name">Cú Học</div><div class="ct-typing"><span></span><span></span><span></span><em>' + _('đang suy nghĩ…') + '</em></div></div>';
    log.appendChild(wait); scrollEnd();
    try {
      const j = await api(fields);
      wait.remove();
      render(j.message, true); stats(j.stats);
      if (j.conversation && j.conversation.id !== conv) { conv = j.conversation.id; setUrl(); }
      if (j.conversation) $('#ct-title').textContent = j.conversation.title;
      if (j.left !== undefined && j.left !== null) $('#ct-quota').innerHTML = '⚡ ' + _('Còn {n} lượt hỏi hôm nay').replace('{n}', '<b>' + j.left + '</b>');
      loadConvs();
    } catch (e) {
      wait.querySelector('.ct-typing').outerHTML = `<div class="ct-err">⚠️ ${esc(e.message)}</div>`;
    }
    busy = false; sendBtn.disabled = false;
  }
  form.addEventListener('submit', (e) => { e.preventDefault(); send({}); });
  text.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send({}); } });
  function autosize() { text.style.height = 'auto'; text.style.height = Math.min(180, text.scrollHeight) + 'px'; }
  text.addEventListener('input', autosize);

  // ------------------------------------------------------------------ nhập bằng giọng nói (Chrome / Edge)
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (SR) {
    const mic = $('#ct-mic'); mic.hidden = false; let rec = null;
    mic.addEventListener('click', () => {
      if (rec) { rec.stop(); return; }
      rec = new SR(); rec.lang = VOICE; rec.interimResults = true;
      const before = text.value ? text.value + ' ' : '';
      rec.onresult = (ev) => { text.value = before + Array.from(ev.results).map((r) => r[0].transcript).join(''); autosize(); };
      rec.onend = () => { rec = null; mic.classList.remove('rec'); text.focus(); };
      rec.onerror = () => { rec = null; mic.classList.remove('rec'); };
      mic.classList.add('rec'); rec.start();
    });
  }

  // ------------------------------------------------------------------ danh sách cuộc trò chuyện
  function setUrl() { const u = new URL(location.href); if (conv) u.searchParams.set('c', conv); else u.searchParams.delete('c'); ['mode', 'qid', 'topic', 'lesson'].forEach((k) => u.searchParams.delete(k)); history.replaceState(null, '', u); }
  function group(d) {
    const t = new Date(d.replace(' ', 'T')), now = new Date(), day = 864e5;
    const diff = (new Date(now.toDateString()) - new Date(t.toDateString())) / day;
    return _(diff <= 0 ? 'Hôm nay' : diff <= 1 ? 'Hôm qua' : diff <= 7 ? '7 ngày qua' : 'Cũ hơn');
  }
  function drawConvs() {
    const q = $('#ct-find').value.trim().toLowerCase();
    let html = '', last = '';
    convs.filter((c) => !q || c.title.toLowerCase().includes(q)).forEach((c) => {
      const g = group(c.updated_at);
      if (g !== last) { html += `<h5>${g}</h5>`; last = g; }
      html += `<div class="ct-conv${c.id === conv ? ' on' : ''}" data-id="${c.id}"><button type="button" class="t">${esc(c.title)}</button>
        <button type="button" class="ed" data-ren title="${_('Đổi tên')}">✏️</button><button type="button" class="ed" data-del title="${_('Xoá')}">🗑</button></div>`;
    });
    $('#ct-convs').innerHTML = html || '<p class="muted small">' + _('Chưa có cuộc trò chuyện nào.') + '</p>';
  }
  async function loadConvs() { try { convs = (await api({ action: 'conversations' })).conversations; drawConvs(); } catch (e) {} }
  $('#ct-find').addEventListener('input', drawConvs);
  $('#ct-convs').addEventListener('click', async (e) => {
    const row = e.target.closest('.ct-conv'); if (!row) return;
    const id = +row.dataset.id;
    if (e.target.closest('[data-del]')) {
      if (!confirm(_('Xoá cuộc trò chuyện này? (Điểm thưởng vẫn được giữ)'))) return;
      convs = (await api({ action: 'delete', conversation_id: id })).conversations;
      if (id === conv) newChat(); else drawConvs();
    } else if (e.target.closest('[data-ren]')) {
      const c = convs.find((x) => x.id === id); const t = prompt(_('Đổi tên cuộc trò chuyện'), c ? c.title : '');
      if (t && t.trim()) { convs = (await api({ action: 'rename', conversation_id: id, title: t.trim() })).conversations; drawConvs(); if (id === conv) $('#ct-title').textContent = t.trim(); }
    } else openConv(id);
  });
  function showConv(j) {
    log.innerHTML = '';
    if (!j.conversation) { newChat(); return; }
    $('#ct-title').textContent = j.conversation.title;
    j.messages.forEach((m) => render(m, false));
    log.scrollTop = log.scrollHeight;
  }
  async function openConv(id, pre) {
    if (busy) return;
    conv = id; setUrl(); drawConvs(); closeSide();
    if (pre) { showConv(pre); return; }
    log.innerHTML = '<div class="ct-loading">' + _('Đang tải…') + '</div>';
    try { showConv(await api({ action: 'history', conversation_id: id })); }
    catch (e) { log.innerHTML = `<div class="ct-err">⚠️ ${esc(e.message)}</div>`; }
  }
  function newChat() {
    conv = 0; setUrl(); drawConvs(); closeSide();
    log.innerHTML = welcomeHTML; $('#ct-title').textContent = _('Trò chuyện mới'); setMode('ask'); text.focus();
  }
  $('#ct-new').addEventListener('click', newChat);
  $('#t-clear').addEventListener('click', async () => {
    if (!confirm(_('Xoá toàn bộ lịch sử trò chuyện? (Điểm thưởng vẫn được giữ)'))) return;
    await api({ action: 'clear' }); convs = []; newChat();
  });

  // thanh bên trên điện thoại
  const root = $('#ct');
  function closeSide() { root.classList.remove('side-open'); }
  document.querySelectorAll('[data-side-open]').forEach((b) => b.addEventListener('click', () => root.classList.add('side-open')));
  document.querySelectorAll('[data-side-close]').forEach((b) => b.addEventListener('click', closeSide));

  // ------------------------------------------------------------------ khởi động
  // khung chat vừa khít phần còn lại của màn hình (thanh trên có thể có thông báo / huy hiệu)
  function fit() { const top = root.getBoundingClientRect().top + scrollY; root.style.height = Math.max(480, innerHeight - top - 12) + 'px'; }
  fit(); addEventListener('resize', fit);
  addEventListener('load', () => { fit(); math($('#ct-welcome') || log); });
  setMode(T.modes[T.pre.mode] ? T.pre.mode : 'ask'); ctxSum();
  convs = T.convs || []; drawConvs();
  if (T.open) openConv(T.open.conversation.id, T.open);
  loadConvs().then(() => {
    if (T.pre.qid) send({ mode: T.pre.mode || 'explain', qid: T.pre.qid, text: _('Vì sao đáp án của mình sai ở câu này? Giảng lại giúp mình phần kiến thức liên quan.') });
    else if (T.pre.mode && T.pre.mode !== 'ask' && (T.pre.topic || T.pre.lesson)) send({ mode: T.pre.mode });
  });
})();
