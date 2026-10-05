// Nút "Chép" và "QR" cho các đường dẫn (link_box trong bootstrap.php); ô [data-qr-inline] vẽ QR ngay.
(function () {
  function svg(url, cell) {
    const q = qrcode(0, 'M'); q.addData(url); q.make();
    return q.createSvgTag({ cellSize: cell || 6, margin: 2, scalable: true });
  }
  function show(url) {
    const d = document.createElement('div');
    d.className = 'qr-modal';
    d.innerHTML = '<div class="qr-box"><div class="qr-img">' + svg(url, 10) + '</div><code></code>'
      + '<p class="small muted">Mở camera điện thoại, quét mã để vào. Bấm ra ngoài để đóng.</p></div>';
    d.querySelector('code').textContent = url;
    d.addEventListener('click', e => { if (e.target === d) d.remove(); });
    document.addEventListener('keydown', function esc(e) { if (e.key === 'Escape') { d.remove(); document.removeEventListener('keydown', esc); } });
    document.body.appendChild(d);
  }
  function copy(text, btn) {
    const done = () => { const o = btn.textContent; btn.textContent = 'Đã chép ✓'; setTimeout(() => btn.textContent = o, 1500); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done);
    else { const t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done(); }
  }
  document.addEventListener('click', e => {
    const c = e.target.closest('[data-copy]'); if (c) { e.preventDefault(); copy(c.dataset.copy, c); return; }
    const q = e.target.closest('[data-qr]'); if (q && typeof qrcode === 'function') { e.preventDefault(); show(q.dataset.qr); }
  });
  document.querySelectorAll('[data-qr-inline]').forEach(el => { if (typeof qrcode === 'function') el.innerHTML = svg(el.dataset.qrInline, 5); });
})();
