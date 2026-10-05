<?php
// Trợ lý học tập "Cú Học": hỏi đáp, giải thích chi tiết, câu hỏi ôn tập, bài tập nâng cao, tóm tắt, lộ trình.
require __DIR__ . '/../lib/lms.php';
require __DIR__ . '/../lib/tutor.php';
$me = require_student();
$code = $me['code'];
$weak = tutor_weak($code);
$lessons = tutor_lessons($code);
$c = ai_conf();
$lock = tutor_exam_lock($code);
$left = $c['provider'] !== 'offline' && tutor_limit() ? max(0, tutor_limit() - tutor_used_today($code)) : null;
$topics = array_slice($weak['topics'], 0, 8);
$weakest = $topics && $topics[0]['rate'] < 0.8 ? $topics[0]['topic'] : '';
$last_wrong = $weak['wrong'][0] ?? null;
$last_lesson = $lessons ? end($lessons) : null;
$pre = ['mode' => $_GET['mode'] ?? '', 'topic' => $_GET['topic'] ?? '', 'lesson' => (int)($_GET['lesson'] ?? 0), 'qid' => (int)($_GET['qid'] ?? 0)];

student_head('Trợ lý AI', 'tutor.php');
?>
<div class="tutor">
  <aside class="t-side">
    <div class="t-card t-hello">
      <div class="mascot big">🦉</div>
      <div><b>Cú Học</b><span>Trợ lý học tập của bạn</span>
        <small><?= $c['provider'] === 'offline' ? 'Chế độ cơ bản · dùng bài giảng của lớp' : 'AI đã sẵn sàng' . ($left !== null ? ' · còn ' . $left . ' lượt hôm nay' : '') ?></small></div>
    </div>

    <div class="t-card">
      <h3>Bạn muốn làm gì?</h3>
      <div class="modes">
        <?php foreach (TUTOR_MODES as $k => [$ico, $label]): ?>
          <button type="button" class="mode m-<?= $k ?>" data-mode="<?= $k ?>"><i><?= $ico ?></i><?= $label ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="t-card">
      <h3>Học phần nào?</h3>
      <label>Buổi học
        <select id="t-lesson"><option value="">— Tất cả bài đã học —</option>
          <?php foreach ($lessons as $l): ?><option value="<?= $l['id'] ?>" <?= $pre['lesson'] === (int)$l['id'] ? 'selected' : '' ?>>Buổi <?= (int)$l['no'] ?><?= $l['title'] !== '' ? ' · ' . h($l['title']) : '' ?></option><?php endforeach; ?>
        </select></label>
      <label>Chủ đề <input id="t-topic" list="t-topics" value="<?= h($pre['topic']) ?>" placeholder="VD: QoS MQTT"></label>
      <datalist id="t-topics"><?php foreach ($topics as $t): ?><option value="<?= h($t['topic']) ?>"><?php endforeach; ?></datalist>
      <div class="row2">
        <label>Trình độ <select id="t-level"><?php foreach (TUTOR_LEVELS as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'thpt' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
        <label>Số câu <select id="t-n"><option>3</option><option selected>5</option><option>10</option></select></label>
      </div>
    </div>

    <?php if ($topics): ?>
    <div class="t-card">
      <h3>Phần cần củng cố</h3>
      <?php foreach (array_slice($topics, 0, 5) as $t): $pct = round($t['rate'] * 100); ?>
        <button type="button" class="weak" data-topic="<?= h($t['topic']) ?>">
          <span><?= h($t['topic']) ?></span><em class="<?= $pct < 50 ? 'bad' : ($pct < 80 ? 'mid' : 'good') ?>"><?= $pct ?>%</em>
          <span class="bar"><span style="width:<?= $pct ?>%"></span></span></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </aside>

  <section class="t-chat">
    <div class="t-log" id="t-log">
      <div class="t-welcome" id="t-welcome">
        <div class="mascot big">🦉</div>
        <h2>Xin chào, <?= h(trim(strrchr(' ' . $me['full_name'], ' '))) ?>! Hôm nay mình học gì nào?</h2>
        <p class="muted">Chọn một gợi ý bên dưới hoặc gõ câu hỏi của bạn.</p>
        <div class="sugg">
          <?php if ($weakest): ?>
            <button type="button" data-q='<?= h(json_encode(['mode' => 'explain', 'topic' => $weakest], JSON_UNESCAPED_UNICODE)) ?>'>📖 Giảng lại chi tiết phần <b><?= h($weakest) ?></b></button>
            <button type="button" data-q='<?= h(json_encode(['mode' => 'quiz', 'topic' => $weakest], JSON_UNESCAPED_UNICODE)) ?>'>🎯 5 câu ôn tập phần <b><?= h($weakest) ?></b></button>
          <?php endif; ?>
          <?php if ($last_wrong): ?>
            <button type="button" data-q='<?= h(json_encode(['mode' => 'explain', 'qid' => (int)$last_wrong['question_id']], JSON_UNESCAPED_UNICODE)) ?>'>❓ Vì sao mình sai câu “<?= h(mb_strimwidth($last_wrong['content'], 0, 50, '…')) ?>”</button>
          <?php endif; ?>
          <?php if ($last_lesson): ?>
            <button type="button" data-q='<?= h(json_encode(['mode' => 'summary', 'lesson' => (int)$last_lesson['id']], JSON_UNESCAPED_UNICODE)) ?>'>📝 Tóm tắt Buổi <?= (int)$last_lesson['no'] ?><?= $last_lesson['title'] !== '' ? ': ' . h($last_lesson['title']) : '' ?></button>
          <?php endif; ?>
          <button type="button" data-q='<?= h(json_encode(['mode' => 'advanced'], JSON_UNESCAPED_UNICODE)) ?>'>🚀 Thử bài tập nâng cao</button>
          <button type="button" data-q='<?= h(json_encode(['mode' => 'plan'], JSON_UNESCAPED_UNICODE)) ?>'>🗺️ Lập lộ trình ôn tập 7 ngày</button>
        </div>
      </div>
    </div>
    <?php if ($lock): ?><div class="flash warn">Bạn đang làm bài kiểm tra “<?= h($lock) ?>”. Trợ lý tạm khoá đến khi bạn nộp bài.</div><?php endif; ?>
    <form class="t-compose" id="t-form">
      <div class="t-mode-pill" id="t-mode-pill"></div>
      <div class="t-input">
        <textarea id="t-text" rows="1" placeholder="Hỏi Cú Học bất cứ điều gì về bài học…" <?= $lock ? 'disabled' : '' ?>></textarea>
        <button class="send" id="t-send" <?= $lock ? 'disabled' : '' ?> title="Gửi (Enter)">➤</button>
      </div>
      <div class="t-foot"><span>Trợ lý có thể nhầm — hãy đối chiếu với bài giảng và hỏi giáo viên khi chưa chắc.</span><button type="button" class="link" id="t-clear">Xoá lịch sử trò chuyện</button></div>
    </form>
  </section>
</div>
<script>window.TUTOR = <?= json_encode(['modes' => TUTOR_MODES, 'pre' => $pre], JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="../assets/tutor.js"></script>
<?php student_foot();
