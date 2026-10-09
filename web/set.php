<?php
// Một bộ câu hỏi: soạn câu (2–10 lựa chọn), sắp thứ tự, nhập từ Excel/CSV.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sheet.php';
$t = require_login();
$set = own_set((int)($_GET['id'] ?? 0));
$sid = (int)$set['id'];
$act = $_POST['action'] ?? '';
$used = (bool)q1('SELECT 1 FROM sessions WHERE set_id=?', [$sid]) || (bool)q1('SELECT 1 FROM homework_questions hq JOIN questions q ON q.id=hq.question_id WHERE q.set_id=?',[$sid]);

function renumber(int $sid): void {
    // đánh lại 1..n theo thứ tự hiện tại (đi qua số tạm để không vướng UNIQUE)
    $ids = array_column(qall('SELECT id FROM questions WHERE set_id=? ORDER BY position, id', [$sid]), 'id');
    foreach ($ids as $i => $id) q('UPDATE questions SET position=? WHERE id=?', [150 + $i, $id]);
    foreach ($ids as $i => $id) q('UPDATE questions SET position=? WHERE id=?', [$i + 1, $id]);
}

function validate_q(array $in): array {
    $errs = [];
    $content = trim($in['content'] ?? '');
    $n = (int)($in['n_options'] ?? 0);
    $opts = [];
    if ($content === '') $errs[] = 'thiếu nội dung';
    if ($n < 2 || $n > 10) $errs[] = 'số lựa chọn phải 2–10';
    for ($i = 0; $i < 10; $i++) {
        $v = trim($in['opt'][$i] ?? '');
        $opts[$i] = ($i < $n) ? $v : '';
        if ($i < $n && $v === '') $errs[] = 'thiếu nội dung phương án ' . LETTERS[$i];
    }
    $correct = strtoupper(trim($in['correct'] ?? ''));
    if ($correct !== '' && (strlen($correct) !== 1 || strpos(LETTERS, $correct) === false || strpos(LETTERS, $correct) >= $n)) $errs[] = 'đáp án đúng phải là một chữ trong ' . substr(LETTERS, 0, max(2, min(10, $n)));
    $topic = mb_substr(trim($in['topic'] ?? ''), 0, 100);
    $expl = trim($in['explanation'] ?? '');
    $tl = trim((string)($in['time_limit'] ?? ''));
    if ($tl !== '' && (!ctype_digit($tl) || (int)$tl < 5 || (int)$tl > 3600)) $errs[] = 'thời gian 5–3600 giây hoặc để trống';
    return [$errs, ['content' => $content, 'n_options' => $n, 'opts' => $opts, 'correct' => $correct ?: null, 'time_limit' => $tl === '' ? null : (int)$tl,
                    'topic' => $topic === '' ? null : $topic, 'explanation' => $expl === '' ? null : $expl]];
}

function save_q(int $sid, array $v, ?int $id = null, ?int $pos = null): void {
    set_unfinalize($sid);
    $cols = [$v['content'], $v['n_options']];
    foreach ($v['opts'] as $o) $cols[] = $o === '' ? null : $o;
    $cols[] = $v['correct']; $cols[] = $v['time_limit']; $cols[] = $v['topic']; $cols[] = $v['explanation'];
    if ($id) {
        q('UPDATE questions SET content=?, n_options=?, opt_a=?,opt_b=?,opt_c=?,opt_d=?,opt_e=?,opt_f=?,opt_g=?,opt_h=?,opt_i=?,opt_j=?, correct=?, time_limit=?, topic=?, explanation=? WHERE id=? AND set_id=?', array_merge($cols, [$id, $sid]));
    } else {
        $pos = $pos ?? (int)qv('SELECT COALESCE(MAX(position),0)+1 FROM questions WHERE set_id=?', [$sid]);
        q('INSERT INTO questions (content, n_options, opt_a,opt_b,opt_c,opt_d,opt_e,opt_f,opt_g,opt_h,opt_i,opt_j, correct, time_limit, topic, explanation, set_id, position) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($cols, [$sid, $pos]));
    }
}

if ($act === 'finalize') {                        // giáo viên xác nhận bộ câu hỏi đã đủ
    $n = (int)qv('SELECT COUNT(*) FROM questions WHERE set_id=?', [$sid]);
    $tn = trim((string)($_POST['target_n'] ?? ''));
    $tn = $tn === '' ? null : max(1, min(255, (int)$tn));
    $noans = (int)qv('SELECT COUNT(*) FROM questions WHERE set_id=? AND (correct IS NULL OR correct="")', [$sid]);
    if ($n === 0) flash('Bộ chưa có câu hỏi nào.', 'err');
    elseif ($tn !== null && $n < $tn) flash(t('Mới có {n}/{t} câu — chưa đủ số câu dự kiến.', ['n' => $n, 't' => $tn]), 'err');
    elseif ($set['purpose'] === 'practice' && $noans) flash(t('Bài ôn tập cần đáp án để chấm: còn {n} câu chưa có đáp án đúng.', ['n' => $noans]), 'err');
    else { q('UPDATE question_sets SET finalized_at=NOW(), target_n=? WHERE id=?', [$tn, $sid]); flash(t('✅ Đã xác nhận hoàn tất bộ câu hỏi ({n} câu).', ['n' => $n])); fx_once('success'); }
    if ($tn !== null) q('UPDATE question_sets SET target_n=? WHERE id=?', [$tn, $sid]);
    redirect("set.php?id=$sid#finalize");
}
if ($act === 'reopen') { set_unfinalize($sid); flash('Đã mở lại bộ câu hỏi để chỉnh sửa.'); redirect("set.php?id=$sid#finalize"); }
if ($act === 'rename') {
    $title = trim($_POST['title'] ?? '');
    if ($title !== '') q('UPDATE question_sets SET title=? WHERE id=?', [$title, $sid]);
    redirect("set.php?id=$sid");
}
if ($act === 'save_q') {
    if(!empty($_POST['id']) && qv('SELECT 1 FROM homework_questions WHERE question_id=?',[(int)$_POST['id']])){flash('Câu đã dùng cho bài tập. Sao chép đề mới để sửa.','err');redirect("set.php?id=$sid");}
    [$errs, $v] = validate_q($_POST);
    if ($set['purpose'] === 'test' && (int)qv('SELECT COUNT(*) FROM questions WHERE set_id=?', [$sid]) >= 64 && empty($_POST['id'])) $errs[] = 'tối đa 64 câu mỗi bộ kiểm tra (giới hạn mặt nạ câu mở trong beacon)';
    if ($errs) { flash('Chưa lưu: ' . implode('; ', $errs) . '.', 'err'); $_SESSION['q_draft'] = $_POST; redirect("set.php?id=$sid" . (!empty($_POST['id']) ? '&edit=' . (int)$_POST['id'] : '') . '#form-q'); }
    save_q($sid, $v, !empty($_POST['id']) ? (int)$_POST['id'] : null);
    flash('Đã lưu câu hỏi.');
    redirect("set.php?id=$sid");
}
if ($act === 'delete_q') {
    $qid = (int)$_POST['id'];
    if (q1('SELECT 1 FROM session_questions WHERE question_id=?', [$qid]) || qv('SELECT 1 FROM homework_questions WHERE question_id=?',[$qid])) flash('Câu này đã được dùng trong buổi học, không xoá được.', 'err');
    else { q('DELETE FROM questions WHERE id=? AND set_id=?', [$qid, $sid]); renumber($sid); set_unfinalize($sid); }
    redirect("set.php?id=$sid");
}
if ($act === 'move') {
    if ($used) { flash('Bộ đã dùng trong buổi học: không đổi thứ tự để số câu trên clicker khớp với kết quả cũ.', 'err'); redirect("set.php?id=$sid"); }
    $qs = array_column(qall('SELECT id FROM questions WHERE set_id=? ORDER BY position', [$sid]), 'id');
    $i = array_search((int)$_POST['id'], array_map('intval', $qs), true);
    $j = $i + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
    if ($i !== false && $j >= 0 && $j < count($qs)) {
        [$qs[$i], $qs[$j]] = [$qs[$j], $qs[$i]]; set_unfinalize($sid);
        foreach ($qs as $k => $id) q('UPDATE questions SET position=? WHERE id=?', [100 + $k, $id]);
        foreach ($qs as $k => $id) q('UPDATE questions SET position=? WHERE id=?', [$k + 1, $id]);
    }
    redirect("set.php?id=$sid");
}

$preview = null;
if ($act === 'import_upload' && !empty($_FILES['file']['tmp_name'])) {
    try {
        $sheets = sheet_read($_FILES['file']['tmp_name'], $_FILES['file']['name']);
        $rows = $sheets['Cau hoi'] ?? $sheets['Câu hỏi'] ?? reset($sheets);
        $preview = [];
        foreach (data_rows($rows) as $line => $r) {
            $in = ['content' => $r[1] ?? '', 'n_options' => $r[2] ?? '', 'opt' => array_slice(array_pad($r, 15, ''), 3, 10),
                   'correct' => $r[13] ?? '', 'time_limit' => $r[14] ?? '', 'topic' => $r[15] ?? '', 'explanation' => $r[16] ?? ''];
            [$errs, $v] = validate_q($in);
            $preview[] = ['line' => $line, 'v' => $v, 'errs' => $errs];
        }
        $_SESSION['import_q'][$sid] = $preview;
    } catch (Throwable $e) { $preview = null; flash('Không đọc được file: ' . $e->getMessage(), 'err'); }
}
if ($act === 'import_confirm') {
    $rows = array_filter($_SESSION['import_q'][$sid] ?? [], fn($r) => !$r['errs']);
    $replace = isset($_POST['replace']) && !$used;
    $existing = $replace ? 0 : (int)qv('SELECT COUNT(*) FROM questions WHERE set_id=?', [$sid]);
    if ($set['purpose'] === 'test' && $existing + count($rows) > 64) { flash('Tối đa 64 câu mỗi bộ kiểm tra.', 'err'); redirect("set.php?id=$sid"); }
    db()->beginTransaction();
    if ($replace) { q('DELETE FROM questions WHERE set_id=?', [$sid]); set_unfinalize($sid); }
    foreach ($rows as $r) save_q($sid, $r['v']);
    db()->commit();
    unset($_SESSION['import_q'][$sid]);
    flash('Đã nhập ' . count($rows) . ' câu hỏi.');
    redirect("set.php?id=$sid");
}

$questions = qall('SELECT * FROM questions WHERE set_id=? ORDER BY position', [$sid]);
$edit = isset($_GET['edit']) ? q1('SELECT * FROM questions WHERE id=? AND set_id=?', [(int)$_GET['edit'], $sid]) : null;
$draft = $_SESSION['q_draft'] ?? null; unset($_SESSION['q_draft']);
$f = [
    'id' => $edit['id'] ?? ($draft['id'] ?? ''),
    'content' => $draft['content'] ?? ($edit['content'] ?? ''),
    'n_options' => (int)($draft['n_options'] ?? ($edit['n_options'] ?? 4)),
    'correct' => $draft['correct'] ?? ($edit['correct'] ?? ''),
    'time_limit' => $draft['time_limit'] ?? ($edit['time_limit'] ?? ''),
    'topic' => $draft['topic'] ?? ($edit['topic'] ?? ''),
    'explanation' => $draft['explanation'] ?? ($edit['explanation'] ?? ''),
    'opt' => $draft['opt'] ?? array_map(fn($L) => $edit['opt_' . strtolower($L)] ?? '', str_split(LETTERS)),
];

page_head($set['title'], 'sets.php');
?>
<p class="crumbs"><a href="sets.php">Bộ câu hỏi</a> › <?= h($set['title']) ?> <span class="tag <?= $set['purpose'] === 'test' ? 'blue' : 'live' ?>"><?= PURPOSE[$set['purpose']] ?></span></p>
<form method="post" class="row title-edit"><?= csrf_field() ?><input type="hidden" name="action" value="rename">
  <input name="title" value="<?= h($set['title']) ?>" class="h1"><button>Đổi tên</button></form>
<?php
$nq = count($questions);
$tn = (int)($set['target_n'] ?? 0);
$fin = !empty($set['finalized_at']);
$c_noans = count(array_filter($questions, fn($q) => !$q['correct']));
$c_noexp = count(array_filter($questions, fn($q) => trim((string)$q['explanation']) === ''));
$c_notopic = count(array_filter($questions, fn($q) => trim((string)$q['topic']) === ''));
$seen = []; $c_dup = 0; foreach ($questions as $q) { $k = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $q['content']))); if (isset($seen[$k])) $c_dup++; $seen[$k] = 1; }
$checks = [   // [ok?, nặng (chặn) hay nhẹ (nhắc), nội dung]
    [$nq > 0 && (!$tn || $nq >= $tn), true, $tn ? t('Số câu: {n}/{t} câu dự kiến', ['n' => $nq, 't' => $tn]) : t('Số câu: {n}', ['n' => $nq])],
    [$c_noans === 0, $set['purpose'] === 'practice', $c_noans ? t('{n} câu chưa có đáp án đúng', ['n' => $c_noans]) . ($set['purpose'] === 'test' ? ' ' . t('(sẽ là câu khảo sát ý kiến)') : '') : t('Mọi câu đều có đáp án đúng')],
    [$c_dup === 0, false, $c_dup ? t('{n} câu trùng nội dung', ['n' => $c_dup]) : t('Không có câu trùng nhau')],
    [$c_noexp === 0, false, $c_noexp ? t('{n} câu chưa có giải thích (học sinh xem khi ôn tập)', ['n' => $c_noexp]) : t('Mọi câu đều có giải thích')],
    [$c_notopic === 0, false, $c_notopic ? t('{n} câu chưa gắn chủ đề (báo cáo theo chủ đề sẽ thiếu)', ['n' => $c_notopic]) : t('Mọi câu đều có chủ đề')],
];
$blocked = (bool)array_filter($checks, fn($c) => !$c[0] && $c[1]);
?>
<section class="card fin-card <?= $fin ? 'done' : '' ?>" id="finalize">
  <div class="fin-head">
    <span class="fin-ico"><?= $fin ? '✅' : '📝' ?></span>
    <div><h2><?= $fin ? 'Đã hoàn tất bộ câu hỏi' : 'Đang soạn' ?></h2>
      <p class="muted"><?= $fin ? t('Xác nhận lúc {d} · {n} câu. Thêm, sửa hoặc xoá câu sẽ đưa bộ về trạng thái “Đang soạn”.', ['d' => date('d/m/Y H:i', strtotime($set['finalized_at'])), 'n' => $nq])
                            : 'Thêm đủ câu hỏi, xem lại bảng kiểm tra rồi bấm xác nhận hoàn tất.' ?></p></div>
    <?php if ($fin): ?>
      <form method="post" class="fin-act"><?= csrf_field() ?><input type="hidden" name="action" value="reopen"><button>✏️ Mở lại để sửa</button></form>
    <?php endif; ?>
  </div>
  <ul class="fin-checks">
    <?php foreach ($checks as [$ok, $hard, $txt]): ?><li class="<?= $ok ? 'ok' : ($hard ? 'bad' : 'warn') ?>"><i><?= $ok ? '✓' : ($hard ? '✕' : '!') ?></i><?= h($txt) ?></li><?php endforeach; ?>
  </ul>
  <?php if (!$fin): ?>
  <form method="post" class="fin-form" onsubmit="return confirm(<?= h(json_encode(t('Xác nhận bộ “{s}” đã đủ {n} câu và sẵn sàng dùng?', ['s' => $set['title'], 'n' => $nq]), JSON_UNESCAPED_UNICODE)) ?>)">
    <?= csrf_field() ?><input type="hidden" name="action" value="finalize">
    <label>Số câu dự kiến <input type="number" name="target_n" min="1" max="255" value="<?= $tn ?: '' ?>" placeholder="<?= $nq ?>"></label>
    <button class="primary" <?= $blocked ? 'disabled' : '' ?>>✓ Xác nhận hoàn tất bộ câu hỏi (<?= $nq ?> câu)</button>
    <?php if ($blocked): ?><span class="small" style="color:#b42318">Sửa các mục ✕ trước khi xác nhận.</span><?php endif; ?>
  </form>
  <?php endif; ?>
</section>
<?php if ($used): ?><div class="flash warn">Bộ câu hỏi đã dùng trong buổi học. Sửa nội dung vẫn được, nhưng kết quả cũ sẽ hiển thị theo nội dung mới.</div><?php endif; ?>

<?php if ($preview !== null): ?>
<section class="card">
  <h2>Xem trước câu hỏi nhập</h2>
  <table class="grid compact">
    <thead><tr><th>Dòng</th><th>Chủ đề</th><th>Nội dung</th><th>Lựa chọn</th><th>Đáp án</th><th>Thời gian</th><th>Lỗi</th></tr></thead>
    <tbody><?php foreach ($preview as $r): $v = $r['v']; ?>
      <tr class="<?= $r['errs'] ? 'bad' : '' ?>"><td><?= $r['line'] ?></td><td><?= h($v['topic'] ?? '') ?></td><td><?= h($v['content']) ?></td>
        <td><?php for ($i = 0; $i < $v['n_options'] && $i < 10; $i++) echo '<b>' . LETTERS[$i] . '.</b> ' . h($v['opts'][$i]) . ' '; ?></td>
        <td><?= h($v['correct'] ?? '—') ?></td><td><?= $v['time_limit'] ? (int)$v['time_limit'] . ' s' : '—' ?></td><td><?= h(implode('; ', $r['errs'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="import_confirm">
    <?php if (!$used): ?><label class="check"><input type="checkbox" name="replace"> Xoá các câu hiện có trước khi nhập</label><?php endif; ?>
    <button class="primary">Xác nhận nhập <?= count(array_filter($preview, fn($r) => !$r['errs'])) ?> câu hợp lệ</button>
    <a href="set.php?id=<?= $sid ?>">Huỷ</a></form>
</section>
<?php endif; ?>

<section class="card">
  <h2>Câu hỏi (<?= count($questions) ?>)</h2>
  <div class="row">
    <form method="post" enctype="multipart/form-data" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="import_upload">
      <input type="file" name="file" accept=".xlsx,.csv" required><button>Nhập từ Excel/CSV</button></form>
    <a class="btn" href="template.php">Tải file mẫu</a>
    <span class="spacer"></span>
    <a class="btn" href="compose.php?set=<?= $sid ?>">⇪ Nhập từ Word</a>
    <a class="btn ai" href="compose.php?set=<?= $sid ?>">✨ Gợi ý thêm câu bằng AI</a>
  </div>
  <?php foreach ($questions as $i => $qq): ?>
    <div class="qitem">
      <div class="qhead"><span class="qno">Câu <?= (int)$qq['position'] ?></span>
        <?php if ($qq['time_limit']): ?><span class="tag"><?= (int)$qq['time_limit'] ?> s</span><?php endif; ?>
        <?php if (!$qq['correct']): ?><span class="tag">khảo sát</span><?php endif; ?>
        <?php if ($qq['topic']): ?><span class="tag topic"><?= h($qq['topic']) ?></span><?php endif; ?>
        <?php if ($qq['difficulty']): ?><span class="tag"><?= DIFFICULTY[$qq['difficulty']] ?></span><?php endif; ?>
        <?php if ($qq['origin'] === 'ai'): ?><span class="tag ai">AI</span><?php endif; ?>
        <span class="spacer"></span>
        <?php if (!$used): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= $qq['id'] ?>"><input type="hidden" name="dir" value="up"><button class="link" <?= $i === 0 ? 'disabled' : '' ?>>▲</button></form>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= $qq['id'] ?>"><input type="hidden" name="dir" value="down"><button class="link" <?= $i === count($questions) - 1 ? 'disabled' : '' ?>>▼</button></form>
        <?php endif; ?>
        <a href="set.php?id=<?= $sid ?>&edit=<?= $qq['id'] ?>#form-q">Sửa</a>
        <form method="post" class="inline" onsubmit="return confirm('Xoá câu này?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_q"><input type="hidden" name="id" value="<?= $qq['id'] ?>"><button class="link danger">Xoá</button></form>
      </div>
      <div class="qtext"><?= nl2br(h($qq['content'])) ?></div>
      <ol class="opts"><?php foreach (options_of($qq) as $L => $o): ?>
        <li class="<?= $L === $qq['correct'] ? 'correct' : '' ?>"><b><?= $L ?>.</b> <?= h($o) ?></li>
      <?php endforeach; ?></ol>
      <?php if ($qq['explanation']): ?><div class="expl"><b>Giải thích:</b> <?= nl2br(h($qq['explanation'])) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>

<section class="card" id="form-q">
  <h2><?= $f['id'] ? 'Sửa câu hỏi' : 'Thêm câu hỏi' ?></h2>
  <form method="post" class="stack qform"><?= csrf_field() ?><input type="hidden" name="action" value="save_q"><input type="hidden" name="id" value="<?= h($f['id']) ?>">
    <label>Chủ đề / chương <input name="topic" value="<?= h($f['topic']) ?>" list="topics" placeholder="VD: MQTT"></label>
    <datalist id="topics"><?php foreach (array_unique(array_filter(array_column($questions, 'topic'))) as $tp): ?><option value="<?= h($tp) ?>"><?php endforeach; ?></datalist>
    <label>Nội dung <textarea name="content" rows="3" required><?= h($f['content']) ?></textarea></label>
    <div class="row">
      <label>Số lựa chọn <select name="n_options" id="n_options"><?php for ($n = 2; $n <= 10; $n++): ?><option <?= $n === $f['n_options'] ? 'selected' : '' ?>><?= $n ?></option><?php endfor; ?></select></label>
      <label>Đáp án đúng <select name="correct"><option value="">— (khảo sát)</option><?php for ($i = 0; $i < 10; $i++): ?><option class="optsel" data-i="<?= $i ?>" <?= LETTERS[$i] === $f['correct'] ? 'selected' : '' ?>><?= LETTERS[$i] ?></option><?php endfor; ?></select></label>
      <label>Thời gian (giây) <input name="time_limit" type="number" min="5" max="3600" value="<?= h($f['time_limit']) ?>" placeholder="không giới hạn" class="w8"></label>
    </div>
    <?php for ($i = 0; $i < 10; $i++): ?>
      <label class="opt" data-i="<?= $i ?>"><?= LETTERS[$i] ?>. <input name="opt[<?= $i ?>]" value="<?= h($f['opt'][$i] ?? '') ?>"></label>
    <?php endfor; ?>
    <label>Giải thích (học sinh thấy khi xem lại và khi ôn tập) <textarea name="explanation" rows="2"><?= h($f['explanation']) ?></textarea></label>
    <div class="row"><button class="primary">Lưu câu hỏi</button><?php if ($f['id']): ?><a href="set.php?id=<?= $sid ?>">Huỷ sửa</a><?php endif; ?></div>
  </form>
  <p class="muted small">Trên clicker: phím 1–9 là A–I, phím 0 là J. Số câu hiện trên LED 7 đoạn là số thứ tự câu trong bộ.</p>
</section>
<?php echo '<p><a class="btn" href="media.php?set='.(int)$set['id'].'">Hình / Audio / Video</a> <a class="btn" href="variants.php?id='.(int)$set['id'].'">Tạo mã đề</a> <a class="btn" href="set_export.php?id='.(int)$set['id'].'">Xuất đề Excel</a></p>';
echo '<p><a class="btn" href="set_docx.php?id='.$sid.'">Xuất Word không đáp án</a> <a class="btn" href="set_docx.php?id='.$sid.'&key=1">Word kèm đáp án (giáo viên)</a> <a class="btn" href="set_print.php?id='.$sid.'">In / PDF đề</a></p>';
page_foot('set.js');
