<?php
// Làm bài ôn tập, mỗi màn hình một câu.
//  - Luyện tập: báo đúng/sai và giải thích ngay; câu sai được xếp lại cuối lượt, hỏi lại tới khi đúng (tối đa 4 vòng).
//  - Như kiểm tra: không báo đúng/sai, có thể giới hạn thời gian; chấm khi nộp hoặc hết giờ.
require __DIR__ . '/../lib/student.php';
$me = require_student();
$a = my_attempt($me['code'], (int)($_GET['a'] ?? $_POST['a'] ?? 0));
$aid = (int)$a['id'];
$exam = $a['mode'] === 'exam';
if ($a['finished_at']) redirect("review.php?a=$aid");
if ($exam && $a['expired']) { finish_attempt($aid); flash('Đã hết thời gian làm bài. Bài làm đã được nộp tự động.', 'warn'); redirect("review.php?a=$aid"); }

/** Kết thúc lượt; đạt từ 80 % đúng ngay lần đầu thì trang kết quả bắn pháo giấy. */
function finish_and_review(int $aid): void {
    finish_attempt($aid);
    $r = q1('SELECT n_questions, n_first_ok FROM practice_attempts WHERE id=?', [$aid]);
    if ($r['n_questions'] && $r['n_first_ok'] / $r['n_questions'] >= 0.8) fx_once('celebrate');
    redirect("review.php?a=$aid");
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'submit') finish_and_review($aid);
    $it = q1('SELECT i.*, q.correct FROM practice_items i JOIN questions q ON q.id=i.question_id WHERE i.id=? AND i.attempt_id=? AND i.answer IS NULL', [(int)$_POST['item'], $aid]);
    $ans = strtoupper(substr((string)($_POST['ans'] ?? ''), 0, 1));
    if ($it && $ans !== '' && strpos(LETTERS, $ans) !== false) {
        $ok = $ans === $it['correct'] ? 1 : 0;
        q('UPDATE practice_items SET answer=?, is_correct=?, answered_at=NOW() WHERE id=?', [$ans, $ok, $it['id']]);
        if (!$ok && !$exam && $it['round'] < 4) {
            q('INSERT INTO practice_items (attempt_id, question_id, ord, round) SELECT ?, ?, MAX(ord)+1, ? FROM practice_items WHERE attempt_id=?',
              [$aid, $it['question_id'], $it['round'] + 1, $aid]);
        }
        if (!$exam) fx_once($ok ? 'correct' : 'wrong');         // kiểm tra: không lộ đúng/sai trước khi nộp
        redirect("quiz.php?a=$aid" . ($exam ? '' : '&fb=' . $it['id']));
    }
    redirect("quiz.php?a=$aid");
}

$n = (int)$a['n_questions'];
$first_done = (int)qv('SELECT COUNT(*) FROM practice_items WHERE attempt_id=? AND round=1 AND answer IS NOT NULL', [$aid]);
$first_ok = (int)qv('SELECT COUNT(*) FROM practice_items WHERE attempt_id=? AND round=1 AND is_correct=1', [$aid]);
$retry_left = (int)qv('SELECT COUNT(*) FROM practice_items WHERE attempt_id=? AND round>1 AND answer IS NULL', [$aid]);

student_head($a['title'], 'practice.php');
echo '<div class="quiz-head"><a href="practice.php" class="muted small">← Thoát (lượt này được lưu)</a><h1>' . h($a['title']) . '</h1>';
echo '<div class="progress" data-key="quiz-' . $aid . '"><span style="width:' . ($n ? round($first_done * 100 / $n) : 0) . '%"></span></div>';
echo '<div class="muted small">' . $first_done . '/' . $n . ' câu' . (!$exam ? ' · đúng ngay lần đầu ' . $first_ok . ($retry_left ? ' · còn ' . $retry_left . ' câu làm lại' : '') : '')
   . ($exam && $a['left_s'] !== null ? ' · <span id="timer" data-left="' . (int)$a['left_s'] . '"></span>' : '') . '</div></div>';

// ---- màn phản hồi sau mỗi câu (chế độ luyện tập)
if (!$exam && isset($_GET['fb'])) {
    $it = q1('SELECT i.*, q.* , i.id AS item_id FROM practice_items i JOIN questions q ON q.id=i.question_id WHERE i.id=? AND i.attempt_id=?', [(int)$_GET['fb'], $aid]);
    if ($it && $it['answer'] !== null) {
        $ok = (int)$it['is_correct'] === 1;
        echo '<div class="feedback ' . ($ok ? 'good' : 'bad') . '">' . ($ok ? ($it['round'] > 1 ? 'Chính xác! Bạn đã trả lời đúng câu này.' : 'Chính xác!') : 'Chưa chính xác. Câu này sẽ được hỏi lại ở cuối lượt.') . '</div>';
        echo question_card($it, $it['answer'], ['open_expl' => true, 'meta' => $it['topic'] ? '<span class="tag topic">' . h($it['topic']) . '</span>' : '']);
        echo '<a class="btn primary big" href="quiz.php?a=' . $aid . '" autofocus id="next">Tiếp tục →</a>';
        echo '<script>document.addEventListener("keydown",e=>{if(e.key==="Enter"||e.key===" "){e.preventDefault();location.href=document.getElementById("next").href}})</script>';
        student_foot();
        exit;
    }
}

// ---- câu tiếp theo
$it = q1('SELECT i.id AS item_id, i.round, q.* FROM practice_items i JOIN questions q ON q.id=i.question_id WHERE i.attempt_id=? AND i.answer IS NULL ORDER BY i.ord LIMIT 1', [$aid]);
if (!$it) finish_and_review($aid);
?>
<article class="qcard ask">
  <div class="qmeta"><?= $it['round'] > 1 ? '<span class="tag off">làm lại</span> ' : '' ?><?= $it['topic'] ? '<span class="tag topic">' . h($it['topic']) . '</span>' : '' ?></div>
  <div class="qtext"><?= nl2br(h($it['content'])) ?></div>
  <?= question_media_html((int)$it['id'], '../') ?>
  <form method="post" class="answers"><?= csrf_field() ?><input type="hidden" name="a" value="<?= $aid ?>"><input type="hidden" name="item" value="<?= (int)$it['item_id'] ?>">
    <?php foreach (options_of($it) as $L => $text): ?>
      <button name="ans" value="<?= $L ?>" class="choice"><b><?= $L ?></b><span><?= h($text) ?></span></button>
    <?php endforeach; ?>
  </form>
  <p class="muted small">Phím tắt: 1–9 = A–I, 0 = J (giống clicker).</p>
</article>
<?php if ($exam): ?>
<form method="post" onsubmit="return confirm('Nộp bài? Câu chưa làm tính là sai.')"><?= csrf_field() ?><input type="hidden" name="a" value="<?= $aid ?>"><input type="hidden" name="action" value="submit"><button>Nộp bài</button></form>
<?php endif; ?>
<script>
document.addEventListener('keydown', (e) => {
  const k = e.key === '0' ? 9 : parseInt(e.key, 10) - 1;
  const b = document.querySelectorAll('button.choice')[k];
  if (!isNaN(k) && b) b.click();
});
const t = document.getElementById('timer');
if (t) {
  let left = +t.dataset.left;
  const tick = () => { if (left <= 0) { location.reload(); return; } t.textContent = 'còn ' + Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0'); left--; };
  tick(); setInterval(tick, 1000);
}
</script>
<?php student_foot();
