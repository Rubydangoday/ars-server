'use strict';
// Hiệu ứng nhỏ dùng chung (không thư viện ngoài, chạy offline): pháo giấy canvas, âm thanh tổng hợp WebAudio,
// thanh tiến trình kiểu lò xo, hiệu ứng theo sự kiện server gửi qua <body data-fx="...">.
// Tôn trọng "giảm chuyển động" của hệ điều hành; âm thanh tắt được (lưu theo trình duyệt).
(function () {
  const reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  const store = { get: (k, d) => { try { return localStorage.getItem(k) ?? d; } catch (e) { return d; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} } };
  const portal = document.body.classList.contains('stu') ? 'stu' : document.body.classList.contains('parent-portal') ? 'parent' : 'teacher';
  // Học sinh: âm thanh bật mặc định. Giáo viên / phụ huynh: tắt mặc định (môi trường làm việc).
  const soundOn = () => store.get('ars_sound_' + portal, portal === 'stu' ? '1' : '0') === '1';

  let ctx = null;
  function audio() {
    if (!ctx) { const A = window.AudioContext || window.webkitAudioContext; if (!A) return null; ctx = new A(); }
    if (ctx.state === 'suspended') ctx.resume().catch(() => {});
    return ctx;
  }
  // Một nốt: tần số, bắt đầu (s), độ dài (s), âm lượng, dạng sóng
  function note(a, f, t0, dur, vol, type) {
    const o = a.createOscillator(), g = a.createGain(), t = a.currentTime + t0;
    o.type = type || 'sine'; o.frequency.setValueAtTime(f, t);
    g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(vol, t + 0.015); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g).connect(a.destination); o.start(t); o.stop(t + dur + 0.05);
  }
  const SOUNDS = {
    chime: (a) => [523.25, 659.25, 783.99, 1046.5].forEach((f, i) => { note(a, f, i * 0.09, 0.55, 0.16, 'triangle'); note(a, f * 2, i * 0.09, 0.3, 0.03); }),
    correct: (a) => { note(a, 880, 0, 0.18, 0.14, 'triangle'); note(a, 1318.5, 0.09, 0.32, 0.14, 'triangle'); },
    wrong: (a) => { note(a, 220, 0, 0.22, 0.12, 'sine'); note(a, 185, 0.12, 0.3, 0.1, 'sine'); },
    soft: (a) => note(a, 660, 0, 0.25, 0.07, 'sine'),                  // xác nhận nhẹ (giáo viên)
    tick: (a) => note(a, 1200, 0, 0.06, 0.05, 'square'),
  };
  function play(name, force) {
    if (!force && !soundOn()) return;
    const a = audio(); if (a && SOUNDS[name]) try { SOUNDS[name](a); } catch (e) {}
  }

  // Pháo giấy: canvas toàn màn hình, tự xoá sau ~2,5 s. opts: {count, colors, x, y (0..1), spread}
  function confetti(opts) {
    if (reduce) return;
    opts = Object.assign({ count: 140, x: 0.5, y: 0.35, spread: 1, colors: ['#ec4899', '#8b5cf6', '#3b82f6', '#facc15', '#22c55e', '#f97316'] }, opts || {});
    const c = document.createElement('canvas'), dpr = window.devicePixelRatio || 1;
    c.className = 'fx-confetti'; c.width = innerWidth * dpr; c.height = innerHeight * dpr;
    document.body.appendChild(c);
    const g = c.getContext('2d'); g.scale(dpr, dpr);
    const P = Array.from({ length: opts.count }, () => {
      const ang = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 0.9 * opts.spread, sp = 7 + Math.random() * 9;
      return { x: innerWidth * opts.x, y: innerHeight * opts.y, vx: Math.cos(ang) * sp, vy: Math.sin(ang) * sp, r: Math.random() * Math.PI, vr: (Math.random() - 0.5) * 0.4,
               w: 6 + Math.random() * 6, h: 8 + Math.random() * 8, col: opts.colors[(Math.random() * opts.colors.length) | 0], round: Math.random() < 0.3 };
    });
    const t0 = performance.now();
    (function frame(t) {
      const age = (t - t0) / 1000;
      g.clearRect(0, 0, innerWidth, innerHeight);
      for (const p of P) {
        p.vy += 0.32; p.vx *= 0.985; p.vy *= 0.985; p.x += p.vx; p.y += p.vy; p.r += p.vr;
        g.save(); g.globalAlpha = Math.max(0, 1 - Math.max(0, age - 1.6)); g.translate(p.x, p.y); g.rotate(p.r); g.fillStyle = p.col;
        if (p.round) { g.beginPath(); g.arc(0, 0, p.w / 2, 0, 7); g.fill(); } else g.fillRect(-p.w / 2, -p.h / 2, p.w, p.h * Math.abs(Math.cos(p.r * 2)) + 1);
        g.restore();
      }
      if (age < 2.6) requestAnimationFrame(frame); else c.remove();
    })(t0);
  }

  // Thanh tiến trình .progress[data-key]: chạy từ giá trị lần trước (lưu theo phiên trình duyệt) tới giá trị mới.
  function springProgress(root) {
    root.querySelectorAll('.progress[data-key] > span').forEach((bar) => {
      const key = 'ars_prog_' + bar.parentElement.dataset.key, to = bar.style.width;
      let from = null; try { from = sessionStorage.getItem(key); sessionStorage.setItem(key, to); } catch (e) {}
      if (from === null || from === to || reduce) return;
      bar.style.transition = 'none'; bar.style.width = from;
      requestAnimationFrame(() => requestAnimationFrame(() => { bar.style.transition = ''; bar.style.width = to; }));
    });
  }

  // Số đếm tăng dần (thống kê phụ huynh / giáo viên): <b data-count="42">
  function countUp(root) {
    if (reduce) return;
    root.querySelectorAll('.kpis b').forEach((el) => { if (/^\d+$/.test(el.textContent.trim()) && !el.dataset.count) el.dataset.count = el.textContent.trim(); });
    root.querySelectorAll('progress[data-animate]').forEach((el) => {      // thanh tiến độ chạy từ 0
      const to = +el.value, t0 = performance.now(); el.value = 0;
      (function f(t) { const k = Math.min(1, (t - t0) / 900), e = 1 - Math.pow(1 - k, 3); el.value = to * e; if (k < 1) requestAnimationFrame(f); })(t0);
    });
    root.querySelectorAll('[data-count]').forEach((el) => {
      const to = parseFloat(el.dataset.count); if (!isFinite(to)) return;
      const dec = (el.dataset.count.split('.')[1] || '').length, t0 = performance.now(), dur = 700;
      (function f(t) { const k = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - k, 3); el.textContent = (to * e).toFixed(dec); if (k < 1) requestAnimationFrame(f); })(t0);
    });
  }

  // Sự kiện một lần do server đặt (fx_once trong PHP)
  const EVENTS = {
    celebrate: () => { confetti(); play('chime'); },
    correct: () => { confetti({ count: 60, y: 0.3, spread: 0.6 }); play('correct'); },
    wrong: () => { play('wrong'); document.querySelector('.feedback.bad')?.classList.add('fx-shake'); },
    success: () => { play('soft'); },                                   // giáo viên / phụ huynh: không pháo giấy
  };

  // Bài tập về nhà: đếm câu đã làm (chọn / viết / tệp đính kèm), thanh tiến trình lò xo + dấu ✓ nảy lên.
  function trackHomework() {
    const form = document.querySelector('form.lms-homework'), box = document.querySelector('.hw-progress');
    if (!form || !box) return;
    const qs = [...form.querySelectorAll('.hw-q')], bar = box.querySelector('.progress span'), label = box.querySelector('.hw-count');
    const done = (q) => !!q.querySelector('input[type=radio]:checked') || [...q.querySelectorAll('textarea')].some((t) => t.value.trim() !== '')
      || [...q.querySelectorAll('input[type=file]')].some((f) => f.files && f.files.length) || q.dataset.hasFile === '1';
    let last = -1;
    const update = () => {
      let n = 0;
      qs.forEach((q) => { const d = done(q); if (d && !q.classList.contains('done')) { q.classList.add('done', 'fx-pop'); setTimeout(() => q.classList.remove('fx-pop'), 450); } if (!d) q.classList.remove('done'); n += d; });
      bar.style.width = (qs.length ? n * 100 / qs.length : 0) + '%';
      label.textContent = n === qs.length ? `🎯 Xong ${n}/${qs.length} câu — sẵn sàng nộp!` : `${n}/${qs.length} câu`;
      if (last >= 0 && n > last) play('tick');
      box.classList.toggle('complete', n === qs.length);
      last = n;
    };
    form.addEventListener('input', update); form.addEventListener('change', update); update();
  }

  window.ARSFX = { confetti, play, soundOn, setSound: (on) => store.set('ars_sound_' + portal, on ? '1' : '0'), springProgress, countUp, reduce };
  document.addEventListener('DOMContentLoaded', () => {
    springProgress(document); countUp(document); trackHomework();
    const ev = document.body.dataset.fx; if (ev && EVENTS[ev]) setTimeout(EVENTS[ev], 150);
    // Nút bật/tắt âm thanh: <button data-sound-toggle>
    document.querySelectorAll('[data-sound-toggle]').forEach((b) => {
      const sync = () => { b.textContent = soundOn() ? '🔊 Âm thanh: bật' : '🔈 Âm thanh: tắt'; b.setAttribute('aria-pressed', String(soundOn())); };
      sync(); b.addEventListener('click', (e) => { e.preventDefault(); ARSFX.setSound(!soundOn()); sync(); if (soundOn()) play('correct'); });
    });
  });
})();

// Menu thả xuống (<details>) ở thanh trên: bấm ra ngoài hoặc Esc thì đóng
document.addEventListener('click', (e) => {
  document.querySelectorAll('header details[open]').forEach((d) => { if (!d.contains(e.target)) d.removeAttribute('open'); });
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') document.querySelectorAll('header details[open]').forEach((d) => d.removeAttribute('open'));
});

// Ô lọc bảng: <input data-filter="#id-bang"> ẩn các dòng không chứa chữ đã gõ (không phân biệt dấu)
(function () {
  const fold = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
  document.addEventListener('input', (e) => {
    const f = e.target.closest('[data-filter]'); if (!f) return;
    const q = fold(f.value.trim());
    document.querySelectorAll(f.dataset.filter + ' tbody tr').forEach((tr) => { tr.hidden = q !== '' && !fold(tr.textContent).includes(q); });
  });
})();
