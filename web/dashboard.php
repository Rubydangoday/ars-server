<?php
// Tổng quan cho giáo viên: số liệu chính, phiên đang chạy, việc cần làm, phiên gần đây.
require __DIR__ . '/lib/bootstrap.php';
$t = require_login();
$tid = $t['id'];

$k = q1('SELECT '
      . '(SELECT COUNT(*) FROM classes WHERE teacher_id=?) AS classes, '
      . '(SELECT COUNT(*) FROM students s JOIN classes c ON c.id=s.class_id WHERE c.teacher_id=?) AS students, '
      . '(SELECT COUNT(*) FROM questions q JOIN question_sets qs ON qs.id=q.set_id WHERE qs.teacher_id=?) AS questions, '
      . '(SELECT COUNT(*) FROM questions q JOIN question_sets qs ON qs.id=q.set_id WHERE qs.teacher_id=? AND q.origin="ai") AS ai_questions, '
      . '(SELECT COUNT(*) FROM sessions s JOIN classes c ON c.id=s.class_id WHERE c.teacher_id=?) AS sessions, '
      . '(SELECT COUNT(*) FROM lessons l JOIN classes c ON c.id=l.class_id WHERE c.teacher_id=? AND l.file_store IS NOT NULL) AS lectures, '
      . '(SELECT COUNT(*) FROM lessons l JOIN classes c ON c.id=l.class_id WHERE c.teacher_id=?) AS lessons',
        array_fill(0, 7, $tid));
$acc = q1('SELECT COUNT(*) n, SUM(f.answer=q.correct) ok FROM final_answers f '
        . 'JOIN session_questions sq ON sq.id=f.session_q_id JOIN questions q ON q.id=sq.question_id '
        . 'JOIN sessions s ON s.id=sq.session_id JOIN classes c ON c.id=s.class_id '
        . 'WHERE c.teacher_id=? AND q.correct IS NOT NULL AND s.started_at > NOW() - INTERVAL 30 DAY', [$tid]);
$running = qall('SELECT s.id, c.name, qs.title, s.started_at, (SELECT COUNT(*) FROM session_logins l WHERE l.session_id=s.id) n_in, '
              . '(SELECT COUNT(*) FROM students st WHERE st.class_id=c.id) n_all '
              . 'FROM sessions s JOIN classes c ON c.id=s.class_id JOIN question_sets qs ON qs.id=s.set_id '
              . 'WHERE c.teacher_id=? AND s.status="running" ORDER BY s.id DESC', [$tid]);
$recent = qall('SELECT s.id, s.kind, s.started_at, s.status, s.released, c.name AS class_name, qs.title, l.no AS lesson_no, '
             . '(SELECT COUNT(*) FROM session_logins x WHERE x.session_id=s.id) n_in, '
             . '(SELECT COUNT(*) FROM students st WHERE st.class_id=c.id) n_all, '
             . '(SELECT ROUND(100*AVG(f.answer=q.correct)) FROM final_answers f JOIN session_questions sq ON sq.id=f.session_q_id '
             . '   JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=s.id AND q.correct IS NOT NULL) pct '
             . 'FROM sessions s JOIN classes c ON c.id=s.class_id JOIN question_sets qs ON qs.id=s.set_id '
             . 'LEFT JOIN lessons l ON l.id=s.lesson_id WHERE c.teacher_id=? ORDER BY s.id DESC LIMIT 8', [$tid]);
// việc cần làm
$todo = [];
foreach (qall('SELECT c.id, c.name, (SELECT COUNT(*) FROM students s WHERE s.class_id=c.id) n FROM classes c WHERE c.teacher_id=?', [$tid]) as $c) {
    if (!$c['n']) $todo[] = ['Lớp <b>' . h($c['name']) . '</b> chưa có danh sách học sinh', 'class.php?id=' . $c['id'], 'Nhập danh sách'];
    $l = q1('SELECT id, no FROM lessons WHERE class_id=? AND file_store IS NULL ORDER BY no LIMIT 1', [$c['id']]);
    if ($l) $todo[] = ['<b>' . h($c['name']) . '</b> · Buổi ' . (int)$l['no'] . ' chưa có file bài giảng', 'lesson.php?id=' . $l['id'], 'Tải bài giảng'];
}
foreach (qall('SELECT s.id, c.name, qs.title FROM sessions s JOIN classes c ON c.id=s.class_id JOIN question_sets qs ON qs.id=s.set_id '
            . 'WHERE c.teacher_id=? AND s.kind="test" AND s.status="ended" AND s.released=0 ORDER BY s.id DESC LIMIT 3', [$tid]) as $s)
    $todo[] = ['Bài kiểm tra <b>' . h($s['title']) . '</b> (' . h($s['name']) . ') chưa công bố cho học sinh xem lại', 'results.php?id=' . $s['id'], 'Xem kết quả'];
if (setting('ai_provider', 'offline') === 'offline')
    $todo[] = ['Trợ lý AI đang chạy chế độ cơ bản (không cần Internet). Thêm API key để AI tự soạn câu hỏi theo chủ đề', 'settings.php#ai', 'Cài đặt AI'];
$ai_month = (int)qv('SELECT COUNT(*) FROM ai_log WHERE teacher_id=? AND created_at > NOW() - INTERVAL 30 DAY AND error IS NULL', [$tid]);

$hour = (int)date('G');
page_head('Tổng quan', 'dashboard.php');
?>
<div class="page-head">
  <div><h1><?= $hour < 11 ? 'Chào buổi sáng' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối') ?>, <?= h($t['full_name']) ?></h1>
    <p class="muted"><?= h(['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'][(int)date('w')]) ?>, <?= date('d/m/Y') ?></p></div>
  <div class="quick" style="margin:0">
    <a class="btn primary" href="classes.php">▶ Bắt đầu buổi học</a>
    <a class="btn ai" href="compose.php">✨ Soạn câu hỏi với AI</a>
    <a class="btn" href="lessons.php">📚 Bài giảng</a>
  </div>
</div>

<?php foreach ($running as $r): ?>
  <div class="banner-live">
    <div><span class="dot-live"></span><b><?= h($r['name']) ?></b> đang học — <?= h($r['title']) ?> · điểm danh <?= (int)$r['n_in'] ?>/<?= (int)$r['n_all'] ?> · bắt đầu <?= h(date('H:i', strtotime($r['started_at']))) ?></div>
    <div class="row" style="margin:0"><a class="btn primary" href="session.php?id=<?= $r['id'] ?>">Mở bảng điều khiển</a><a class="btn" href="projector.php?id=<?= $r['id'] ?>" target="_blank">Màn chiếu</a></div>
  </div>
<?php endforeach; ?>

<section class="card">
  <div class="links-grid">
    <div>
      <h2 style="margin-bottom:4px">🔗 Link cố định</h2>
      <p class="muted small" style="margin:0 0 6px">Gửi cho học sinh và giáo viên khác. Bấm <b>QR</b> để chiếu cho cả lớp quét. Các link khác (trong LAN, qua Internet) ở <a href="settings.php#links">Cài đặt → Đường dẫn</a>.</p>
      <?= link_box(link_hs(), 'Học sinh') ?>
      <?= link_box(link_gv(), 'Giáo viên') ?>
    </div>
    <div class="qr-inline" data-qr-inline="<?= h(link_hs()) ?>" title="QR vào cổng học sinh"></div>
  </div>
</section>

<div class="stat-row">
  <div class="stat"><span>Lớp học</span><b><?= (int)$k['classes'] ?></b><small><?= (int)$k['students'] ?> học sinh</small></div>
  <div class="stat"><span>Ngân hàng câu hỏi</span><b><?= (int)$k['questions'] ?></b><small><?= (int)$k['ai_questions'] ?> câu do AI gợi ý</small></div>
  <div class="stat"><span>Phiên trả lời</span><b><?= (int)$k['sessions'] ?></b><small>tổng số lần dạy bằng clicker</small></div>
  <div class="stat"><span>Tỉ lệ đúng 30 ngày</span><b><?= $acc['n'] ? round($acc['ok'] * 100 / $acc['n']) . ' %' : '—' ?></b><small><?= (int)$acc['n'] ?> lượt trả lời</small></div>
  <div class="stat"><span>Bài giảng đã tải</span><b><?= (int)$k['lectures'] ?>/<?= (int)$k['lessons'] ?></b><small>buổi có file bài giảng</small></div>
  <div class="stat"><span>Trợ lý AI (30 ngày)</span><b><?= $ai_month ?></b><small>lượt soạn / tóm tắt</small></div>
</div>

<div class="cols">
<section class="card">
  <h2>Phiên gần đây</h2>
  <?php if (!$recent): ?><p class="muted">Chưa có phiên nào. Vào <a href="classes.php">một lớp</a> và bấm <b>Bắt đầu</b>.</p><?php else: ?>
  <table class="grid compact">
    <thead><tr><th>Thời gian</th><th>Lớp · nội dung</th><th>Có mặt</th><th>Đúng</th><th></th></tr></thead>
    <tbody><?php foreach ($recent as $r): ?>
      <tr><td class="small"><?= h(date('d/m H:i', strtotime($r['started_at']))) ?></td>
        <td><b><?= h($r['class_name']) ?></b><?= $r['lesson_no'] ? ' · Buổi ' . (int)$r['lesson_no'] : '' ?><br><span class="small muted"><?= h($r['title']) ?></span>
          <?= $r['kind'] === 'test' ? '<span class="tag">kiểm tra</span>' : '' ?><?= $r['status'] === 'running' ? ' <span class="tag live">đang học</span>' : '' ?></td>
        <td><?= (int)$r['n_in'] ?>/<?= (int)$r['n_all'] ?></td>
        <td><?= $r['pct'] !== null ? (int)$r['pct'] . ' %' : '—' ?></td>
        <td class="right"><a href="results.php?id=<?= $r['id'] ?>">Kết quả</a></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <p class="small"><a href="sessions.php">Xem toàn bộ lịch sử →</a></p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Việc cần làm</h2>
  <?php if (!$todo): ?><p class="muted">Không có việc tồn đọng.</p><?php endif; ?>
  <?php foreach (array_slice($todo, 0, 8) as [$txt, $href, $cta]): ?>
    <div class="todo"><div class="small"><?= $txt ?></div><a class="btn small" href="<?= h($href) ?>"><?= h($cta) ?></a></div>
  <?php endforeach; ?>
</section>
</div>
<?php page_foot();
