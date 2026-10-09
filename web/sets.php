<?php
// Ngân hàng câu hỏi: danh sách bộ câu hỏi.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__.'/lib/qbank.php';require __DIR__.'/lib/variants.php';
$t = require_login();
$act = $_POST['action'] ?? '';

if ($act === 'create') {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') { flash('Nhập tên bộ câu hỏi.', 'err'); redirect('sets.php'); }
    q('INSERT INTO question_sets (teacher_id, title, purpose) VALUES (?,?,?)', [$t['id'], $title, ($_POST['purpose'] ?? '') === 'practice' ? 'practice' : 'test']);
    redirect('set.php?id=' . db()->lastInsertId());
}
if ($act === 'copy') {
    $s = own_set((int)$_POST['id']);
    $new=clone_set($s,$t['id'],mb_substr($s['title'].' (bản sao)',0,200),false,false,null);
    redirect("set.php?id=$new");
}
if ($act === 'purpose') {
    $s = own_set((int)$_POST['id']);
    q('UPDATE question_sets SET purpose=? WHERE id=?', [$s['purpose'] === 'test' ? 'practice' : 'test', $s['id']]);
    redirect('sets.php' . (isset($_GET['p']) ? '?p=' . urlencode($_GET['p']) : ''));
}
if ($act === 'delete') {
    $s = own_set((int)$_POST['id']);
    if (q1('SELECT 1 FROM sessions WHERE set_id=?', [$s['id']]) || qv('SELECT 1 FROM homework_questions hq JOIN questions q ON q.id=hq.question_id WHERE q.set_id=?',[$s['id']])) flash('Bộ câu hỏi đã dùng trong buổi học nên không xoá được (kết quả cần giữ). Hãy tạo bản sao nếu muốn sửa.', 'err');
    else { q('DELETE FROM question_sets WHERE id=?', [$s['id']]); flash('Đã xoá.'); }
    redirect('sets.php');
}

$filter = $_GET['p'] ?? '';
$search = trim($_GET['q'] ?? '');
$sets = qall('SELECT qs.*, (SELECT COUNT(*) FROM questions q WHERE q.set_id=qs.id) n, '
           . '(SELECT COUNT(*) FROM questions q WHERE q.set_id=qs.id AND q.origin="ai") n_ai, '
           . '(SELECT COUNT(*) FROM sessions s WHERE s.set_id=qs.id) used, '
           . '(SELECT COUNT(*) FROM practice_assignments a WHERE a.set_id=qs.id) assigned, '
           . '(SELECT GROUP_CONCAT(CONCAT("Buổi ", l.no) SEPARATOR ", ") FROM lessons l WHERE l.quiz_set_id=qs.id) lesson '
           . 'FROM question_sets qs WHERE teacher_id=? ' . (isset(PURPOSE[$filter]) ? 'AND purpose=' . db()->quote($filter) : '') . ' ORDER BY id DESC', [$t['id']]);
$found = $search !== '' ? qall('SELECT q.id, q.position, q.content, q.topic, qs.id AS set_id, qs.title FROM questions q JOIN question_sets qs ON qs.id=q.set_id '
    . 'WHERE qs.teacher_id=? AND (q.content LIKE ? OR q.topic LIKE ? OR q.opt_a LIKE ? OR q.opt_b LIKE ? OR q.opt_c LIKE ? OR q.opt_d LIKE ?) ORDER BY q.id DESC LIMIT 50',
    array_merge([$t['id']], array_fill(0, 6, '%' . $search . '%'))) : [];
$total = (int)qv('SELECT COUNT(*) FROM question_sets WHERE teacher_id=?', [$t['id']]);
page_head('Bộ câu hỏi', 'sets.php');
?>
<div class="page-head">
  <div><h1>Câu hỏi</h1><p class="muted">Bộ <b>kiểm tra</b> dùng để chiếu lên lớp với clicker (tối đa 64 câu); bộ <b>ôn tập</b> để giao cho học sinh làm trên web.</p></div>
  <form method="get" class="row" style="margin:0"><input name="q" value="<?= h($search) ?>" placeholder="Tìm câu hỏi trong ngân hàng…" style="min-width:260px"><?php if (isset(PURPOSE[$filter])): ?><input type="hidden" name="p" value="<?= h($filter) ?>"><?php endif; ?><button>Tìm</button></form>
</div>
<nav class="subtabs"><a href="compose.php">✍ Soạn câu hỏi</a><a class="on" href="sets.php">🗂 Bộ câu hỏi (<?= $total ?>)</a></nav>

<?php if ($search !== ''): ?>
<section class="card">
  <h2>Kết quả tìm “<?= h($search) ?>” (<?= count($found) ?>)</h2>
  <?php if (!$found): ?><p class="muted">Không có câu nào khớp.</p><?php endif; ?>
  <?php foreach ($found as $f): ?>
    <div class="todo"><div><b><?= h(mb_strimwidth($f['content'], 0, 160, '…')) ?></b><br><span class="small muted"><?= h($f['title']) ?> · câu <?= (int)$f['position'] ?><?= $f['topic'] ? ' · ' . h($f['topic']) : '' ?></span></div>
      <a class="btn small" href="set.php?id=<?= $f['set_id'] ?>&edit=<?= $f['id'] ?>#form-q">Mở</a></div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="filters">
  <a class="btn small <?= $filter === '' ? 'primary' : '' ?>" href="sets.php">Tất cả</a>
  <a class="btn small <?= $filter === 'test' ? 'primary' : '' ?>" href="sets.php?p=test">Kiểm tra</a>
  <a class="btn small <?= $filter === 'practice' ? 'primary' : '' ?>" href="sets.php?p=practice">Ôn tập</a>
  <span class="spacer"></span>
  <a class="btn ai small" href="compose.php">✨ Soạn thêm câu hỏi với AI</a>
</div>
<?php if ($sets): ?>
<table class="grid">
  <thead><tr><th>Bộ câu hỏi</th><th>Loại</th><th>Số câu</th><th>Sử dụng</th><th></th></tr></thead>
  <tbody><?php foreach ($sets as $s): ?>
    <tr><td><a href="set.php?id=<?= $s['id'] ?>"><b><?= h($s['title']) ?></b></a><?= !empty($s['finalized_at']) ? ' <span class="tag ok">✓ Hoàn tất</span>' : ' <a class="tag warn" href="set.php?id=' . (int)$s['id'] . '#finalize" title="Bấm để kiểm tra và xác nhận">📝 Đang soạn</a>' ?><?= $s['lesson'] ? '<br><span class="small muted">Câu hỏi củng cố · ' . h($s['lesson']) . '</span>' : '' ?></td>
      <td><form method="post" class="inline" title="Đổi loại"><?= csrf_field() ?><input type="hidden" name="action" value="purpose"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button class="link"><span class="tag <?= $s['purpose'] === 'test' ? 'blue' : 'live' ?>"><?= PURPOSE[$s['purpose']] ?> ⇄</span></button></form></td>
      <td><?= (int)$s['n'] ?><?= $s['n_ai'] ? ' <span class="tag ai">' . (int)$s['n_ai'] . ' AI</span>' : '' ?></td>
      <td class="small"><?= (int)$s['used'] ?> phiên · <?= (int)$s['assigned'] ?> lần giao</td>
      <td class="right">
        <a href="compose.php?set=<?= $s['id'] ?>">Thêm câu</a> ·
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="copy"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="link">Sao chép</button></form>
        <?php if (!$s['used']): ?>
        <form method="post" class="inline" onsubmit="return confirm('Xoá bộ câu hỏi này?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="link danger">Xoá</button></form>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?></tbody>
</table>
<?php else: ?><div class="empty">Chưa có bộ câu hỏi nào<?= isset(PURPOSE[$filter]) ? ' loại này' : '' ?>. Tạo bộ mới bên dưới hoặc vào <a href="compose.php">Soạn câu hỏi</a>.</div><?php endif; ?>

<section class="card" style="margin-top:16px">
<h2>Tạo bộ câu hỏi</h2>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label>Tên <input name="title" required placeholder="Chương 3 — MQTT"></label>
  <label>Loại <select name="purpose"><option value="test">Kiểm tra (clicker)</option><option value="practice">Ôn tập (web)</option></select></label>
  <button class="primary">Tạo</button>
</form>
<p class="muted small">Soạn trực tiếp trên web, nhập file <a href="template.php">Excel mẫu</a> / <a href="template_docx.php">Word mẫu</a>, hoặc để trợ lý AI gợi ý ở trang <a href="compose.php">Soạn câu hỏi</a>.</p>
</section>
<?php page_foot();
