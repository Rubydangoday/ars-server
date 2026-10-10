// Bảng điều khiển buổi học: gọi api/state.php mỗi giây, gửi lệnh qua api/action.php.
(function () {
  const root = document.getElementById('ctl');
  const SID = root.dataset.session, CSRF = root.dataset.csrf;
  const $ = (id) => document.getElementById(id);
  // Dịch: từ điển do page_head nhúng (window.ARS_I18N) khi giao diện tiếng Anh; {k} là biến
  const T = (s, v) => { let r = (window.ARS_I18N || {})[s] || s; if (v) for (const k in v) r = r.split('{' + k + '}').join(v[k]); return r; };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const REASON = {
    no_session: T('Lớp chưa bắt đầu buổi'), unknown_seat: T('ID không có trong lớp'), seat_taken: T('ID đã có máy khác đăng nhập'),
    mac_bound: T('Máy này đã đăng nhập ID khác'), not_logged_in: T('Máy chưa điểm danh'), id_mismatch: T('ID trong gói khác ID đã đăng nhập'),
    q_not_open: T('Câu chưa mở'), q_closed: T('Câu đã đóng'), late: T('Hết giờ'), bad_option: T('Chữ vượt số lựa chọn'),
    no_change: T('Không cho đổi đáp án'), malformed: T('Gói hỏng'), wrong_link: T('Sai kiểu kết nối (lớp đang chọn kiểu khác)'),
    mapping_mismatch: T('Remote không đúng học sinh đã ghép')
  };
  let st = null;
  let prevSeats = null, prevQ = null, prevCount = 0;   // để biết ô nào vừa đổi → nháy 0,3 s
  const head = document.querySelector('.ctl-head');
  addEventListener('scroll', () => head && head.classList.toggle('stuck', scrollY > 40), { passive: true });
  const bump = (el) => { el.classList.remove('fx-bump'); void el.offsetWidth; el.classList.add('fx-bump'); };
  const MATH = { delimiters: [{ left: '$$', right: '$$', display: true }, { left: '\\[', right: '\\]', display: true }, { left: '\\(', right: '\\)', display: false }], throwOnError: false, strict: 'ignore' };
  const setHTML = (el, html) => {                    // tránh vẽ lại khi không đổi (không mất cú bấm); vẽ công thức KaTeX sau mỗi lần đổi
    if (el._h !== html) { el._h = html; el.innerHTML = html; if (window.renderMathInElement) try { renderMathInElement(el, MATH); } catch (e) { /* */ } }
  };   // tránh vẽ lại khi không đổi (không mất cú bấm)

  async function act(action, extra = {}) {
    const body = new URLSearchParams({ csrf: CSRF, session: SID, action, ...extra });
    const r = await fetch('api/action.php', { method: 'POST', body });
    const j = await r.json().catch(() => ({ ok: false, msg: T('Lỗi máy chủ') }));
    if (!j.ok) alert(j.msg || T('Không thực hiện được'));
    else if (j.msg) alert(j.msg);
    refresh();
  }

  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    if (b.dataset.confirm && !confirm(b.dataset.confirm)) return;
    const extra = {};
    if (b.dataset.q) extra.question = b.dataset.q;
    else if (st && st.current) extra.question = st.current.id;
    if (b.dataset.act === 'show_result') extra.on = st && st.current && st.current.show_result ? 0 : 1;
    if (b.dataset.seat) extra.seat = b.dataset.seat;
    act(b.dataset.act, extra);
  });
  $('allow_change').addEventListener('change', (e) => act('allow_change', { on: e.target.checked ? 1 : 0 }));

  function bars(dist, correct) {
    const keys = Object.keys(dist || {});
    const max = Math.max(1, ...keys.map((k) => dist[k]));
    const tot = Math.max(1, keys.reduce((a, k) => a + dist[k], 0));
    return '<div class="hbars">' + keys.map((k) =>
      `<div class="hb${k === correct ? ' ok' : ''}"><span class="k" translate="no">${k}</span><span class="track"><span class="fill" style="width:${(dist[k] / max) * 100}%"></span></span><span class="v">${dist[k]} · ${Math.round(dist[k] * 100 / tot)}%</span></div>`).join('') + '</div>';
  }

  function render(s) {
    st = s;
    const ended = s.session.status !== 'running';
    $('st-status').textContent = ended ? T('đã kết thúc') : T('đang học');
    $('st-status').className = 'tag ' + (ended ? '' : 'live');
    const gw = $('gw');                             // máy chủ thu Remote bằng Bluetooth của laptop (ars-ble) — hoặc gateway USB nếu có
    {
      const bt = !s.gateway.port || String(s.gateway.port).startsWith('bluetooth');
      const where = s.gateway.port === 'webserial' ? T(' (USB máy GV)') : '';
      gw.textContent = bt
        ? (s.gateway.online ? T('Bluetooth laptop: đang nghe ({n} gói/s)', { n: s.gateway.pkt_per_s ?? 0 }) : T('Bluetooth laptop: chưa bật'))
        : (s.gateway.online ? T('Gateway{w}: đang nhận ({n} gói/s)', { w: where, n: s.gateway.pkt_per_s ?? 0 }) : T('Gateway: chưa kết nối'));
      gw.className = 'gw ' + (s.gateway.online ? 'on' : 'off');
    }
    $('allow_change').checked = s.session.allow_change;
    document.querySelectorAll('[data-act]').forEach((b) => { if (b.dataset.act !== 'show_result') b.disabled = ended; });

    setHTML($('qlist'), s.questions.map((q) => {
      const cur = s.current && s.current.id === q.id;
      const badge = q.status === 'open' ? `<span class="tag live">${T('đang mở')}</span>` : q.status === 'closed' ? `<span class="tag">${T('đã đóng')}</span>` : '';
      const btn = q.status === 'open'
        ? `<button class="small" data-act="close" data-q="${q.id}">${T('Đóng')}</button>`
        : `<button class="small primary" data-act="open" data-q="${q.id}">${q.status === 'closed' ? T('Mở lại') : T('Mở')}</button>`;
      const view = cur ? '' : `<button class="small link" data-act="current" data-q="${q.id}">${T('Chiếu')}</button>`;
      return `<li class="${cur ? 'cur' : ''}"><div><b>${T('Câu {n}', { n: q.position })}</b> ${badge}<br><span class="muted small">${esc(q.content)}</span></div>
              <div class="qbtn"><span class="muted small">${q.n_answered ? T('{n} trả lời', { n: q.n_answered }) : ''}</span>${view}${ended ? '' : btn}</div></li>`;
    }).join(''));

    const c = s.current;
    $('cur').hidden = !c; $('cur-empty').hidden = !!c;
    if (c) {
      $('cur-no').textContent = T('Câu {n}/{all}', { n: c.position, all: s.n_questions });
      $('cur-state').textContent = c.status === 'open' ? T('đang mở') : c.status === 'closed' ? T('đã đóng') : T('chưa mở');
      $('cur-state').className = 'tag ' + (c.status === 'open' ? 'live' : '');
      $('cur-timer').textContent = c.status === 'open' && c.deadline_left !== null ? `⏱ ${Math.ceil(c.deadline_left)} s` : '';
      $('cur-count').textContent = c.n_answered;
      if (prevQ === c.id && c.n_answered > prevCount) bump($('cur-count'));
      prevCount = c.n_answered;
      $('cur-logged').textContent = s.n_logged;
      setHTML($('cur-text'), esc(c.content).replace(/\n/g, '<br>') + '<ol class="opts">' +
        Object.entries(c.options).map(([k, v]) => `<li class="${k === c.correct ? 'correct' : ''}"><b translate="no">${k}.</b> ${esc(v)}</li>`).join('') + '</ol>');
      setHTML($('cur-bars'), bars(c.dist, c.correct));
      $('btn-open').hidden = c.status === 'open';
      $('btn-open').textContent = c.status === 'closed' ? T('Mở lại câu') : T('Mở câu');
      $('btn-close').hidden = c.status !== 'open';
      $('btn-show').textContent = c.show_result ? T('Ẩn kết quả trên màn chiếu') : T('Hiện kết quả trên màn chiếu');
    }

    $('n-logged').textContent = s.n_logged; $('n-students').textContent = s.n_students;
    const hl = $('head-live');
    if (hl) hl.innerHTML = c ? `${c.status === 'open' && c.deadline_left !== null ? `<span class="timer">⏱ ${Math.ceil(c.deadline_left)} s</span>` : ''}<span><b>${c.n_answered}</b>/${s.n_logged} ${T('đã trả lời')}</span>` : '';
    // Ô nào vừa có đáp án mới / vừa điểm danh trong lần cập nhật này (cùng câu) thì nháy nhẹ.
    const qid = c ? c.id : null, fresh = new Set();
    if (prevSeats) s.seats.forEach((x) => { const p = prevSeats[x.seat]; if (p && ((qid === prevQ && x.answer && x.answer !== p.answer) || (x.present && !p.present))) fresh.add(x.seat); });
    prevSeats = Object.fromEntries(s.seats.map((x) => [x.seat, { answer: x.answer, present: x.present }])); prevQ = qid;
    setHTML($('seats'), s.seats.map((x) => {
      const cls = (x.answer ? 'ans' : x.present ? 'pres' : 'abs') + (fresh.has(x.seat) ? ' pulse' : '');
      const tip = `${x.seat}. ${x.name}` + (x.present ? `\nMAC ${x.mac}\n${T('Pin')} ${x.batt_mv ?? '?'} mV, RSSI ${x.rssi ?? '?'} dBm` : '\n' + T('chưa điểm danh')) + (x.answer ? '\n' + T('Đã chọn {a}', { a: x.answer }) : '');
      const rel = x.present && !ended ? ` data-act="release" data-seat="${x.seat}" data-confirm="${esc(T('Giải phóng ID {n} ({name})? Máy đang giữ ID này sẽ phải đăng nhập lại.', { n: x.seat, name: x.name }))}"` : '';
      return `<div class="seat ${cls}${x.low_batt ? ' low' : ''}" title="${esc(tip)}"${rel}><b translate="no">${x.seat}</b><span>${esc(x.name.split(' ').slice(-1)[0])}</span>${x.answer ? `<i translate="no">${x.answer}</i>` : ''}</div>`;
    }).join(''));

    setHTML($('warn'), s.warnings.length ? s.warnings.map((w) =>
      `<tr><td>${w.t}</td><td>${esc(REASON[w.reason] || w.reason)}</td><td>${w.seat_no ?? ''}</td><td>${w.qid ?? ''}</td><td>${esc(w.answer ?? '')}</td><td class="mono">${esc(w.mac ?? '')}</td><td>${w.n}</td></tr>`).join('')
      : `<tr><td colspan="7" class="muted">${T('Không có.')}</td></tr>`);
  }

  async function refresh() {
    try {
      const r = await fetch(`api/state.php?id=${SID}&view=control`, { cache: 'no-store' });
      if (r.status === 401) { location.href = 'index.php'; return; }
      render(await r.json());
    } catch (e) { $('gw').textContent = T('Mất kết nối tới máy chủ web'); $('gw').className = 'gw off'; }
  }
  refresh();
  setInterval(refresh, 1000);
})();
