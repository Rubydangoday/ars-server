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
    if (!$c['n']) $todo[] = [t('Lớp {c} chưa có danh sách học sinh', ['c' => '<b>' . h($c['name']) . '</b>']), 'class.php?id=' . $c['id'], t('Nhập danh sách')];
    $l = q1('SELECT id, no FROM lessons WHERE class_id=? AND file_store IS NULL ORDER BY no LIMIT 1', [$c['id']]);
    if ($l) $todo[] = [t('{c} · Buổi {n} chưa có file bài giảng', ['c' => '<b>' . h($c['name']) . '</b>', 'n' => (int)$l['no']]), 'lesson.php?id=' . $l['id'], t('Tải bài giảng')];
}
foreach (qall('SELECT s.id, c.name, qs.title FROM sessions s JOIN classes c ON c.id=s.class_id JOIN question_sets qs ON qs.id=s.set_id '
            . 'WHERE c.teacher_id=? AND s.kind="test" AND s.status="ended" AND s.released=0 ORDER BY s.id DESC LIMIT 3', [$tid]) as $s)
    $todo[] = [t('Bài kiểm tra {t} ({c}) chưa công bố cho học sinh xem lại', ['t' => '<b>' . h($s['title']) . '</b>', 'c' => h($s['name'])]), 'results.php?id=' . $s['id'], t('Xem kết quả')];
if (setting('ai_provider', 'offline') === 'offline')
    $todo[] = [h(t('Trợ lý AI đang chạy chế độ cơ bản (không cần Internet). Thêm API key để AI tự soạn câu hỏi theo chủ đề')), 'settings.php#ai', t('Cài đặt AI')];
$ai_month = (int)qv('SELECT COUNT(*) FROM ai_log WHERE teacher_id=? AND created_at > NOW() - INTERVAL 30 DAY AND error IS NULL', [$tid]);

$hour = (int)date('G');
page_head(t('Tổng quan'), 'dashboard.php');
$tile = fn(string $href, string $ico, string $bg, string $title, string $desc) =>
    '<a class="launch-tile" href="' . $href . '" style="--tile-bg:' . $bg . '"><span class="ico">' . $ico . '</span><b>' . te($title) . '</b><span>' . te($desc) . '</span></a>';
?>
<div class="page-head">
  <div><h1><?= te($hour < 11 ? 'Chào buổi sáng' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối')) ?>, <?= h($t['full_name']) ?> 👋</h1>
    <p class="muted"><?= h(t_date(time())) ?></p></div>
</div>

<?php foreach ($running as $r): ?>
  <div class="banner-live">
    <div><span class="dot-live"></span><?= t('{c} đang học — {t} · điểm danh {n}/{all} · bắt đầu {h}', ['c' => '<b>' . h($r['name']) . '</b>', 't' => h($r['title']), 'n' => (int)$r['n_in'], 'all' => (int)$r['n_all'], 'h' => h(date('H:i', strtotime($r['started_at'])))]) ?></div>
    <div class="row" style="margin:0"><a class="btn primary" href="session.php?id=<?= $r['id'] ?>"><?= te('Mở bảng điều khiển') ?></a><a class="btn" href="projector.php?id=<?= $r['id'] ?>" target="_blank"><?= te('Màn chiếu') ?></a></div>
  </div>
<?php endforeach; ?>

<div class="launch-grid">
  <a class="launch-tile hero" href="classes.php"><span class="ico">📡</span><b><?= te('Bắt đầu buổi học bằng Remote') ?></b><span><?= te('Chọn lớp, chọn bộ câu hỏi — cả lớp bấm Remote, kết quả hiện ngay trên màn chiếu.') ?></span>
    <svg class="hero-art" viewBox="0 0 200 130" aria-hidden="true"><rect x="8" y="6" width="184" height="118" rx="14" fill="rgba(255,255,255,.18)"/><?php foreach ([[30, .5], [72, 1], [114, .35], [156, .2]] as $i => [$x, $hh]): ?><rect class="b" x="<?= $x ?>" y="30" width="26" height="78" rx="5" style="--h:<?= $hh ?>;transform:scaleY(<?= $hh ?>);animation-delay:<?= .15 * $i ?>s"/><?php endforeach; ?></svg></a>
  <?= $tile('compose.php', '✨', '#f1ecff', 'Soạn câu hỏi với AI', 'Tạo câu trắc nghiệm từ bài giảng, nhập Word / Excel / PDF.') ?>
  <?= $tile('homework.php', '📝', '#e9f7ef', 'Giao bài tập về nhà', 'Trắc nghiệm, tự luận, vẽ, ghi âm — AI gợi ý chấm.') ?>
  <?= $tile('analytics.php', '📊', '#e8f1ff', 'Báo cáo & ma trận kiến thức', 'Xem học sinh yếu phần nào, xuất Excel / PDF.') ?>
  <?= $tile('rewards.php', '🏆', '#fff4e0', 'Thi đua & đổi quà', 'Bảng xếp hạng tuần / tháng, cửa hàng quà của lớp.') ?>
</div>

<div class="stat-row">
  <div class="stat"><span><?= te('Lớp học') ?></span><b><?= (int)$k['classes'] ?></b><small><?= te('{n} học sinh', ['n' => (int)$k['students']]) ?></small></div>
  <div class="stat"><span><?= te('Ngân hàng câu hỏi') ?></span><b><?= (int)$k['questions'] ?></b><small><?= te('{n} câu do AI gợi ý', ['n' => (int)$k['ai_questions']]) ?></small></div>
  <div class="stat"><span><?= te('Phiên trả lời') ?></span><b><?= (int)$k['sessions'] ?></b><small><?= te('tổng số lần dạy bằng clicker') ?></small></div>
  <div class="stat"><span><?= te('Tỉ lệ đúng 30 ngày') ?></span><b><?= $acc['n'] ? round($acc['ok'] * 100 / $acc['n']) . ' %' : '—' ?></b><small><?= te('{n} lượt trả lời', ['n' => (int)$acc['n']]) ?></small></div>
  <div class="stat"><span><?= te('Bài giảng đã tải') ?></span><b><?= (int)$k['lectures'] ?>/<?= (int)$k['lessons'] ?></b><small><?= te('buổi có file bài giảng') ?></small></div>
  <div class="stat"><span><?= te('Trợ lý AI (30 ngày)') ?></span><b><?= $ai_month ?></b><small><?= te('lượt soạn / tóm tắt') ?></small></div>
</div>

<div class="cols">
<section class="card">
  <h2><?= te('Phiên gần đây') ?></h2>
  <?php if (!$recent): ?><p class="muted"><?= t('Chưa có phiên nào. Vào {a}một lớp{/a} và bấm {b}Bắt đầu{/b}.', ['a' => '<a href="classes.php">', '/a' => '</a>', 'b' => '<b>', '/b' => '</b>']) ?></p><?php else: ?>
  <table class="grid compact">
    <thead><tr><th><?= te('Thời gian') ?></th><th><?= te('Lớp · nội dung') ?></th><th><?= te('Có mặt') ?></th><th><?= te('Đúng') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($recent as $r): ?>
      <tr><td class="small"><?= h(date('d/m H:i', strtotime($r['started_at']))) ?></td>
        <td><b><?= h($r['class_name']) ?></b><?= $r['lesson_no'] ? ' · ' . te('Buổi {n}', ['n' => (int)$r['lesson_no']]) : '' ?><br><span class="small muted"><?= h($r['title']) ?></span>
          <?= $r['kind'] === 'test' ? '<span class="tag">' . te('kiểm tra') . '</span>' : '' ?><?= $r['status'] === 'running' ? ' <span class="tag live">' . te('đang học') . '</span>' : '' ?></td>
        <td><?= (int)$r['n_in'] ?>/<?= (int)$r['n_all'] ?></td>
        <td><?= $r['pct'] !== null ? (int)$r['pct'] . ' %' : '—' ?></td>
        <td class="right"><a href="results.php?id=<?= $r['id'] ?>"><?= te('Kết quả') ?></a></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <p class="small"><a href="sessions.php"><?= te('Xem toàn bộ lịch sử →') ?></a></p>
  <?php endif; ?>
</section>

<section class="card">
  <h2><?= te('Việc cần làm') ?></h2>
  <?php if (!$todo): ?><p class="muted"><?= te('Không có việc tồn đọng.') ?></p><?php endif; ?>
  <?php foreach (array_slice($todo, 0, 8) as [$txt, $href, $cta]): ?>
    <div class="todo"><div class="small"><?= $txt ?></div><a class="btn small" href="<?= h($href) ?>"><?= h($cta) ?></a></div>
  <?php endforeach; ?>
  <h2 style="margin-top:18px">🔗 <?= te('Link cho học sinh') ?></h2>
  <p class="muted small" style="margin:0 0 6px"><?= t('Gửi cho học sinh. Bấm {b}QR{/b} để chiếu cho cả lớp quét. Link khác ở {a}Cài đặt → Đường dẫn{/a}.', ['b' => '<b>', '/b' => '</b>', 'a' => '<a href="settings.php#links">', '/a' => '</a>']) ?></p>
  <?= link_box(link_hs(), t('Học sinh')) ?>
  <?= link_box(link_gv(), t('Giáo viên')) ?>
</section>
</div>
<?php page_foot();
