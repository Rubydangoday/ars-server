<?php
// Một buổi học: file bài giảng (xem trước PDF), kiến thức trọng tâm, câu hỏi củng cố, dạy bằng clicker.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/extract.php';
$t = require_login();
$l = own_lesson((int)($_GET['id'] ?? 0));
$lid = (int)$l['id']; $cid = (int)$l['class_id'];
$act = $_POST['action'] ?? '';
const MAX_LECTURE_MB = 50;

function quiz_set(array $l): int {
    if ($l['quiz_set_id'] && q1('SELECT 1 FROM question_sets WHERE id=?', [$l['quiz_set_id']])) return (int)$l['quiz_set_id'];
    q('INSERT INTO question_sets (teacher_id, title, purpose, description) VALUES (?,?,?,?)',
      [me()['id'], 'Buổi ' . $l['no'] . ($l['title'] !== '' ? ' · ' . $l['title'] : '') . ' — Câu hỏi củng cố (' . $l['class_name'] . ')', 'practice', 'Câu hỏi cuối buổi cho học sinh tự kiểm tra']);
    $sid = (int)db()->lastInsertId();
    q('UPDATE lessons SET quiz_set_id=? WHERE id=?', [$sid, $l['id']]);
    return $sid;
}

if ($act === 'info') {
    $d = trim($_POST['taught_on'] ?? '');
    q('UPDATE lessons SET title=?, taught_on=?, published=? WHERE id=?',
      [mb_substr(trim($_POST['title'] ?? ''), 0, 200), preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null, isset($_POST['published']) ? 1 : 0, $lid]);
    flash('Đã lưu thông tin buổi học.');
    redirect("lesson.php?id=$lid");
}
if ($act === 'upload') {
    $f = $_FILES['file'] ?? null;
    $ext = $f ? strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) : '';
    if (account_limited()) flash('Tài khoản chưa xác nhận: nhập mã mời giáo viên để tải tệp lên.', 'err');
    elseif (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) flash('Chọn file bài giảng.', 'err');
    elseif (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > MAX_LECTURE_MB * 1048576) flash('File lớn hơn ' . MAX_LECTURE_MB . ' MB.', 'err');
    elseif ($f['error'] !== UPLOAD_ERR_OK) flash('Tải file lỗi (mã ' . $f['error'] . ').', 'err');
    elseif (!in_array($ext, LECTURE_EXT, true)) flash('Chỉ nhận PDF, PowerPoint (.pptx), Word (.docx) hoặc văn bản.', 'err');
    else {
        $store = 'L' . $lid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], uploads_dir() . '/' . $store)) flash('Không lưu được file vào ' . uploads_dir() . ' (quyền ghi).', 'err');
        else {
            if ($l['file_store']) @unlink(uploads_dir() . '/' . $l['file_store']);
            $text = extract_text(uploads_dir() . '/' . $store, $f['name']);
            $title = $l['title'] !== '' ? $l['title'] : mb_substr(trim(preg_replace('/[_\-]+/', ' ', pathinfo($f['name'], PATHINFO_FILENAME))), 0, 200);
            q('UPDATE lessons SET file_name=?, file_store=?, file_size=?, lecture_text=?, title=? WHERE id=?',
              [mb_substr($f['name'], 0, 255), $store, (int)$f['size'], $text !== '' ? $text : null, $title, $lid]);
            flash('Đã tải bài giảng' . ($text !== '' ? ' — đọc được ' . number_format(mb_strlen($text), 0, ',', '.') . ' ký tự để AI tóm tắt và soạn câu hỏi.' : '. (Không đọc được chữ trong file: AI chỉ dùng được phần kiến thức trọng tâm.)'));
        }
    }
    redirect("lesson.php?id=$lid");
}
if ($act === 'delete_file' && $l['file_store']) {
    @unlink(uploads_dir() . '/' . $l['file_store']);
    q('UPDATE lessons SET file_name=NULL, file_store=NULL, file_size=NULL, lecture_text=NULL WHERE id=?', [$lid]);
    flash('Đã xoá file bài giảng.');
    redirect("lesson.php?id=$lid");
}
if ($act === 'keypoints') {
    q('UPDATE lessons SET key_points=? WHERE id=?', [trim($_POST['key_points'] ?? '') ?: null, $lid]);
    flash('Đã lưu kiến thức trọng tâm.');
    redirect("lesson.php?id=$lid#kp");
}
if ($act === 'quiz_ai') { quiz_set($l); redirect("compose.php?lesson=$lid&auto=1"); }
if ($act === 'quiz_open') { redirect('set.php?id=' . quiz_set($l)); }

$l = own_lesson($lid);
$all = qall('SELECT id, no, title, file_store FROM lessons WHERE class_id=? ORDER BY no', [$cid]);
$quiz = $l['quiz_set_id'] ? qall('SELECT * FROM questions WHERE set_id=? ORDER BY position', [$l['quiz_set_id']]) : [];
$sess = qall('SELECT s.*, qs.title FROM sessions s JOIN question_sets qs ON qs.id=s.set_id WHERE s.lesson_id=? ORDER BY s.id DESC', [$lid]);
$sets = qall('SELECT id, title, purpose, finalized_at, (SELECT COUNT(*) FROM questions q WHERE q.set_id=qs.id) n FROM question_sets qs WHERE teacher_id=? ORDER BY (id=?) DESC, purpose, id DESC', [$t['id'], (int)$l['quiz_set_id']]);
$n_students = (int)qv('SELECT COUNT(*) FROM students WHERE class_id=?', [$cid]);
$done = qall('SELECT a.student_code, MAX(a.n_first_ok) best, MAX(a.n_questions) nq FROM practice_attempts a WHERE a.lesson_id=? AND a.finished_at IS NOT NULL GROUP BY a.student_code', [$lid]);
$ext = $l['file_name'] ? strtolower(pathinfo($l['file_name'], PATHINFO_EXTENSION)) : '';
$idx = array_search($lid, array_map('intval', array_column($all, 'id')), true);

page_head(lesson_label($l) . ' — ' . $l['class_name'], 'lessons.php');
?>
<p class="crumbs"><a href="lessons.php?class=<?= $cid ?>">Buổi học</a> › <a href="class.php?id=<?= $cid ?>"><?= h($l['class_name']) ?></a> › Buổi <?= (int)$l['no'] ?></p>
<div class="page-head">
  <h1><?= h(lesson_label($l)) ?> <?= $l['published'] ? '<span class="tag live">Học sinh xem được</span>' : '<span class="tag">Chưa công bố</span>' ?></h1>
  <div class="quick" style="margin:0">
    <?php if ($idx > 0): ?><a class="btn" href="lesson.php?id=<?= $all[$idx - 1]['id'] ?>">← Buổi <?= (int)$all[$idx - 1]['no'] ?></a><?php endif; ?>
    <?php if ($idx < count($all) - 1): ?><a class="btn" href="lesson.php?id=<?= $all[$idx + 1]['id'] ?>">Buổi <?= (int)$all[$idx + 1]['no'] ?> →</a><?php endif; ?>
  </div>
</div>
<nav class="stepper"><?php foreach ($all as $x): ?><a href="lesson.php?id=<?= $x['id'] ?>" class="<?= (int)$x['id'] === $lid ? 'on' : '' ?> <?= $x['file_store'] ? 'has' : '' ?>" title="<?= h(lesson_label($x)) ?>"><?= (int)$x['no'] ?></a><?php endforeach; ?></nav>

<div class="lesson-grid">
  <div>
    <section class="card">
      <h2>📄 Bài giảng</h2>
      <?php if ($l['file_store']): ?>
        <div class="filebox"><span class="ext <?= h($ext) ?>"><?= h(strtoupper($ext)) ?></span>
          <div class="info"><b><?= h($l['file_name']) ?></b><span class="small muted"><?= human_size((int)$l['file_size']) ?> · cập nhật <?= h(date('d/m/Y H:i', strtotime($l['updated_at']))) ?>
            · <?= $l['lecture_text'] ? 'đọc được ' . number_format(mb_strlen($l['lecture_text']), 0, ',', '.') . ' ký tự' : 'không đọc được chữ' ?></span></div>
          <a class="btn small" href="file.php?lesson=<?= $lid ?>" download>Tải xuống</a>
          <form method="post" class="inline" onsubmit="return confirm('Xoá file bài giảng?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_file"><button class="small danger">Xoá</button></form>
        </div>
        <?php if ($ext === 'pdf'): ?><iframe class="pdf" src="file.php?lesson=<?= $lid ?>&inline=1" title="Xem trước bài giảng"></iframe>
        <?php elseif ($l['lecture_text']): ?><details open><summary class="small" style="margin:10px 0 6px;cursor:pointer">Nội dung đọc được từ file</summary><div class="outline"><?= h(mb_strimwidth($l['lecture_text'], 0, 20000, '…')) ?></div></details><?php endif; ?>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
        <label class="drop" id="lec-drop"><input type="file" name="file" accept=".pdf,.pptx,.docx,.ppt,.doc,.txt,.md" onchange="this.form.submit()">
          <div style="font-size:26px">⇪</div><b><?= $l['file_store'] ? 'Thay file bài giảng' : 'Tải file bài giảng lên' ?></b><br>
          <span class="small">PDF, PowerPoint (.pptx), Word (.docx) · tối đa <?= MAX_LECTURE_MB ?> MB · kéo thả hoặc bấm để chọn</span></label>
      </form>
    </section>

    <section class="card" id="kp">
      <div class="row" style="justify-content:space-between;margin:0 0 8px"><h2 style="margin:0">💡 Kiến thức trọng tâm</h2>
        <button type="button" class="ai small" id="kp-ai" <?= $l['lecture_text'] ? '' : 'disabled title="Tải bài giảng có chữ trước"' ?>>✨ Tóm tắt bằng AI</button></div>
      <form method="post" class="stack" style="max-width:none"><?= csrf_field() ?><input type="hidden" name="action" value="keypoints">
        <textarea name="key_points" id="kp-text" rows="8" placeholder="- Mỗi ý một dòng, bắt đầu bằng dấu -&#10;- Học sinh thấy phần này ở cuối bài giảng"><?= h($l['key_points']) ?></textarea>
        <div class="row" style="margin:0"><button class="primary">Lưu kiến thức</button><span class="small muted" id="kp-out"></span></div>
      </form>
    </section>

    <section class="card" id="quiz">
      <div class="row" style="justify-content:space-between;margin:0 0 8px"><h2 style="margin:0">❓ Câu hỏi củng cố (<?= count($quiz) ?>)</h2>
        <div class="row" style="margin:0">
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="quiz_ai"><button class="ai small">✨ Soạn bằng AI từ bài giảng</button></form>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="quiz_open"><button class="small"><?= $quiz ? 'Sửa' : 'Soạn tay' ?></button></form>
        </div></div>
      <?php if (!$quiz): ?><p class="muted small">Chưa có câu hỏi. Vài câu ngắn (3–5 câu) cuối buổi giúp học sinh tự kiểm tra; khi buổi được công bố, học sinh làm ở tab “Bài giảng” trên điện thoại.</p><?php endif; ?>
      <?php foreach ($quiz as $qq): ?>
        <div class="mini-q"><b>Câu <?= (int)$qq['position'] ?>.</b> <?= h($qq['content']) ?>
          <div class="small muted"><?php foreach (options_of($qq) as $L => $o): ?><span style="margin-right:14px;<?= $L === $qq['correct'] ? 'color:var(--ok);font-weight:600' : '' ?>"><?= $L ?>. <?= h($o) ?></span><?php endforeach; ?></div></div>
      <?php endforeach; ?>
      <?php if ($done): ?><p class="small" style="margin-top:10px"><b><?= count($done) ?>/<?= $n_students ?></b> học sinh đã làm · điểm cao nhất trung bình
        <?= round(array_sum(array_map(fn($d) => $d['nq'] ? $d['best'] / $d['nq'] : 0, $done)) * 100 / count($done)) ?> %</p><?php endif; ?>
    </section>
  </div>

  <aside>
    <section class="card">
      <h2>Thông tin buổi</h2>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="info">
        <label>Tên bài <input name="title" value="<?= h($l['title']) ?>" placeholder="VD: Giao thức MQTT"></label>
        <label>Ngày dạy <input name="taught_on" type="date" value="<?= h($l['taught_on']) ?>"></label>
        <label class="check"><input type="checkbox" name="published" <?= $l['published'] ? 'checked' : '' ?>> Công bố cho học sinh (xem bài giảng, kiến thức, làm câu hỏi củng cố)</label>
        <button class="primary">Lưu</button>
      </form>
    </section>

    <section class="card">
      <h2>▶ Dạy buổi này bằng clicker</h2>
      <?php if (!$n_students): ?><p class="muted small">Lớp chưa có học sinh. <a href="class.php?id=<?= $cid ?>">Nhập danh sách</a>.</p>
      <?php elseif (!$sets): ?><p class="muted small">Chưa có bộ câu hỏi. <a href="compose.php?lesson=<?= $lid ?>">Soạn câu hỏi</a>.</p>
      <?php else: ?>
      <form method="post" action="class.php?id=<?= $cid ?>" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="start_session"><input type="hidden" name="lesson_id" value="<?= $lid ?>">
        <label>Bộ câu hỏi <select name="set_id"><?php foreach ($sets as $s): if (!$s['n']) continue; ?><option value="<?= $s['id'] ?>"><?= h($s['title']) ?> (<?= (int)$s['n'] ?> câu<?= !empty($s['finalized_at']) ? ' ✓' : ' · ' . te('đang soạn') ?>)</option><?php endforeach; ?></select></label>
        <label>Loại <select name="kind"><option value="class">Buổi học</option><option value="test">Bài kiểm tra</option></select></label>
        <label class="check"><input type="checkbox" name="allow_change" checked> Cho đổi đáp án khi câu còn mở</label>
        <button class="primary">Bắt đầu phiên trả lời</button>
      </form>
      <?php endif; ?>
      <?php if ($sess): ?><h3 style="margin-top:14px">Các phiên của buổi này</h3>
        <?php foreach ($sess as $s): ?><div class="todo small"><div><?= h(date('d/m H:i', strtotime($s['started_at']))) ?> · <?= h($s['title']) ?></div><a href="results.php?id=<?= $s['id'] ?>">Kết quả</a></div><?php endforeach; ?>
      <?php endif; ?>
    </section>
  </aside>
</div>
<script>
(function () {
  const drop = document.getElementById('lec-drop');
  ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', (e) => { const inp = drop.querySelector('input'); inp.files = e.dataTransfer.files; inp.form.submit(); });
  const b = document.getElementById('kp-ai');
  b.addEventListener('click', async () => {
    const out = document.getElementById('kp-out'), ta = document.getElementById('kp-text');
    if (ta.value.trim() && !confirm('Thay nội dung hiện tại bằng bản tóm tắt của AI?')) return;
    b.disabled = true; out.innerHTML = '<span class="spinner"></span>AI đang đọc bài giảng…';
    const fd = new FormData(); fd.append('csrf', document.querySelector('meta[name=csrf]').content); fd.append('task', 'summary'); fd.append('lesson_id', '<?= $lid ?>');
    try {
      const r = await fetch('api/ai.php', { method: 'POST', body: fd }); const j = await r.json();
      if (j.error) throw new Error(j.error);
      ta.value = j.text; out.textContent = 'Bản tóm tắt của ' + j.provider + ' — xem lại rồi bấm Lưu.';
    } catch (e) { out.textContent = '✗ ' + e.message; out.style.color = 'var(--bad)'; }
    b.disabled = false;
  });
})();
</script>
<?php page_foot();
