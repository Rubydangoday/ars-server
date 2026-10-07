// Màn chiếu: đọc api/state.php?view=projector mỗi giây. Đồng hồ đếm ngược chạy mượt giữa hai lần đọc.
(function () {
  const root = document.getElementById('pj');
  const SID = root.dataset.session;
  const $ = (id) => document.getElementById(id);
  const T = (s, v) => { let r = (window.ARS_I18N || {})[s] || s; if (v) for (const k in v) r = r.split('{' + k + '}').join(v[k]); return r; };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const setHTML = (el, html) => { if (el._h !== html) { el._h = html; el.innerHTML = html; delete el.dataset.mathDone; } };
  let deadlineAt = null, sound=false, audioContext=null, lastBeep='', lastQuestion=null, revealed='', primed=false;
  $('pj-full').onclick=()=>document.documentElement.requestFullscreen?.();
  $('pj-sound').onclick=()=>{sound=!sound;$('pj-sound').textContent=sound?T('Tắt âm đếm ngược'):T('Bật âm đếm ngược');$('pj-sound').setAttribute('aria-pressed',String(sound));if(sound)audioContext??=new (window.AudioContext||window.webkitAudioContext)();};

  function render(s) {
    const c = s.current;
    $('pj-wait').hidden = !!c; $('pj-q').hidden = !c;
    if (!c) { $('pj-no').textContent = ''; deadlineAt = null; return; }
    $('pj-no').textContent = T('Câu {n}/{all}', { n: c.position, all: s.n_questions });
    setHTML($('pj-text'), esc(c.content).replace(/\n/g, '<br>'));
    setHTML($('pj-media'),(c.media||[]).map(m=>{const url='question_file.php?id='+Number(m.id);return m.kind==='image'?`<img src="${url}" alt="${esc(m.name)}" style="max-width:100%;max-height:35vh">`:`<${m.kind} controls preload="metadata" src="${url}" style="max-width:100%;max-height:35vh"></${m.kind}>`;}).join(''));
    window.ARSRenderMath?.($('pj-q'));lastQuestion=c.id;
    const reveal = c.show_result && c.dist; const showBars=!!c.dist;
    // Lần đầu hiện đáp án của câu này: chuông (nếu bật âm) và pháo giấy khi từ 80 % lớp đúng.
    if (reveal && revealed !== String(c.id)) {
      const tot = Object.values(c.dist).reduce((a, b) => a + b, 0), ok = c.dist[c.correct] || 0;
      if (primed) { if (sound) window.ARSFX?.play('chime', true); if (tot && ok / tot >= 0.8) window.ARSFX?.confetti({ count: 180 }); }   // không bắn khi vừa mở màn chiếu
      revealed = String(c.id);
    } else if (!reveal && revealed === String(c.id)) revealed = '';
    primed = true;
    setHTML($('pj-opts'), Object.entries(c.options).map(([k, v]) =>
      `<li class="${reveal && k === c.correct ? 'correct' : ''}"><b translate="no">${k}</b><span>${esc(v)}</span></li>`).join(''));
    if (showBars) {
      const keys = Object.keys(c.dist), max = Math.max(1, ...keys.map((k) => c.dist[k]));
      const tot = Math.max(1, keys.reduce((a, k) => a + c.dist[k], 0));
      setHTML($('pj-bars'), '<div class="hbars big">' + keys.map((k) =>
        `<div class="hb${k === c.correct ? ' ok' : ''}"><span class="k" translate="no">${k}</span><span class="track"><span class="fill" style="width:${c.dist[k] / max * 100}%"></span></span><span class="v">${Math.round(c.dist[k] * 100 / tot)}%</span></div>`).join('') + '</div>');
    } else setHTML($('pj-bars'), '');
    $('pj-count').textContent = c.n_answered;
    $('pj-logged').textContent = s.n_logged;
    $('pj-fill').style.width = (s.n_logged ? Math.min(100, c.n_answered * 100 / s.n_logged) : 0) + '%';
    $('pj-state').textContent = c.status === 'open' ? T('đang nhận đáp án') : c.status === 'closed' ? T('đã đóng') : T('chưa mở');
    deadlineAt = c.status === 'open' && c.deadline_left !== null ? performance.now() + c.deadline_left * 1000 : null;
  }

  function tick() {
    const t = $('pj-timer');
    if (deadlineAt === null) { t.textContent = ''; t.className = 'timer'; return; }
    const left = Math.max(0, Math.ceil((deadlineAt - performance.now()) / 1000));
    t.textContent = `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
    t.className = 'timer' + (left <= 10 ? ' hot' : '');
    if(sound&&left<=5&&left>0&&lastBeep!==lastQuestion+':'+left){lastBeep=lastQuestion+':'+left;audioContext.resume();const o=audioContext.createOscillator(),g=audioContext.createGain();o.connect(g);g.connect(audioContext.destination);g.gain.value=.04;o.frequency.value=660;o.start();o.stop(audioContext.currentTime+.1);}
  }

  async function refresh() {
    try {
      const r = await fetch(`api/state.php?id=${SID}&view=projector`, { cache: 'no-store' });
      if (r.status === 401) { location.href = 'index.php'; return; }
      render(await r.json());
    } catch (e) { /* giữ nguyên màn hình khi rớt mạng */ }
  }
  refresh();
  setInterval(refresh, 1000);
  setInterval(tick, 200);
})();
