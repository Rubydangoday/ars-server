// Form soạn câu hỏi: chỉ hiện đủ số ô phương án theo "Số lựa chọn".
(function () {
  const sel = document.getElementById('n_options');
  if (!sel) return;
  function upd() {
    const n = +sel.value;
    document.querySelectorAll('label.opt').forEach((l) => {
      const on = +l.dataset.i < n;
      l.hidden = !on;
      l.querySelector('input').required = on;
    });
    document.querySelectorAll('option.optsel').forEach((o) => { o.hidden = o.disabled = +o.dataset.i >= n; });
  }
  sel.addEventListener('change', upd);
  upd();
})();
