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
$open = conv_history($code, (int)($_GET['c'] ?? 0));   // mở sẵn cuộc trò chuyện ?c= (không phải chờ tải)
$pre = ['mode' => $_GET['mode'] ?? '', 'topic' => $_GET['topic'] ?? '', 'lesson' => (int)($_GET['lesson'] ?? 0), 'qid' => (int)($_GET['qid'] ?? 0)];

student_head('Trợ lý AI', 'tutor.php');
$first = trim(strrchr(' ' . $me['full_name'], ' '));
$sugg = [];   // thẻ gợi ý trên màn chào: [biểu tượng, tiêu đề, mô tả, tham số gửi]
if ($weakest) {
    $sugg[] = ['📖', t('Giảng lại phần {t}', ['t' => $weakest]), t('Phần bạn đang yếu nhất'), ['mode' => 'explain', 'topic' => $weakest]];
    $sugg[] = ['🎯', t('5 câu ôn tập'), t('Chủ đề {t}', ['t' => $weakest]), ['mode' => 'quiz', 'topic' => $weakest]];
}
if ($last_wrong) $sugg[] = ['❓', t('Vì sao mình sai?'), mb_strimwidth($last_wrong['content'], 0, 60, '…'), ['mode' => 'explain', 'qid' => (int)$last_wrong['question_id']]];
if ($last_lesson) $sugg[] = ['📝', t('Tóm tắt Buổi {n}', ['n' => (int)$last_lesson['no']]), $last_lesson['title'] !== '' ? $last_lesson['title'] : t('Bài học gần nhất'), ['mode' => 'summary', 'lesson' => (int)$last_lesson['id']]];
$sugg[] = ['🚀', 'Bài tập nâng cao', 'Thử sức với câu khó hơn', ['mode' => 'advanced']];
$sugg[] = ['🗺️', 'Lộ trình ôn 7 ngày', 'Kế hoạch học theo phần yếu', ['mode' => 'plan']];
$sugg[] = ['💡', 'Mẹo học nhanh nhớ lâu', 'Hỏi đáp tự do với Cú Học', ['mode' => 'ask', 'text' => 'Cho mình vài mẹo học nhanh nhớ lâu phù hợp với lứa tuổi của mình.']];
?>
<div class="ct" id="ct">
  <aside class="ct-side" id="ct-side">
    <div class="ct-brand"><span class="ct-owl">🦉</span><div><b>Cú Học</b><small><?= $c['provider'] === 'offline' ? 'Chế độ cơ bản · bài giảng của lớp' : h(ai_label($c)) ?></small></div>
      <button type="button" class="ct-x" data-side-close aria-label="Đóng">✕</button></div>
    <button type="button" class="ct-new" id="ct-new">＋ Trò chuyện mới</button>
    <div class="ct-search"><input id="ct-find" placeholder="🔎 Tìm cuộc trò chuyện"></div>
    <nav class="ct-convs" id="ct-convs" aria-label="Lịch sử trò chuyện"></nav>
    <?php if ($topics): ?>
    <div class="ct-weak"><h4>Phần cần củng cố</h4>
      <?php foreach (array_slice($topics, 0, 5) as $t): $pct = round($t['rate'] * 100); ?>
        <button type="button" class="weak" data-topic="<?= h($t['topic']) ?>"><span><?= h($t['topic']) ?></span><em class="<?= $pct < 50 ? 'bad' : ($pct < 80 ? 'mid' : 'good') ?>"><?= $pct ?>%</em><span class="bar"><span style="width:<?= $pct ?>%"></span></span></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="ct-quota" id="ct-quota"><?= $left !== null ? '⚡ ' . str_replace('{n}', '<b>' . $left . '</b>', te('Còn {n} lượt hỏi hôm nay')) : '⚡ Không giới hạn lượt hỏi' ?></div>
  </aside>
  <div class="ct-scrim" data-side-close></div>

  <section class="ct-main">
    <header class="ct-top">
      <button type="button" class="ct-menu" data-side-open aria-label="Lịch sử trò chuyện">☰</button>
      <b class="ct-title" id="ct-title">Trò chuyện mới</b>
      <span class="ct-ctx-sum" id="ct-ctx-sum"></span>
    </header>
    <div class="ct-log" id="ct-log">
      <div class="ct-welcome" id="ct-welcome">
        <div class="ct-owl big">🦉</div>
        <h1><?= te('Chào {name}, hôm nay mình học gì nào?', ['name' => $first]) ?></h1>
        <p class="muted">Hỏi bất cứ điều gì về bài học — Cú Học giải thích, ra câu hỏi ôn tập và lập kế hoạch cho bạn.</p>
        <div class="ct-cards">
          <?php foreach ($sugg as [$ico, $t, $d, $q]): ?>
            <button type="button" class="ct-card" data-q='<?= h(json_encode($q, JSON_UNESCAPED_UNICODE)) ?>'><i><?= $ico ?></i><b><?= h($t) ?></b><span><?= h($d) ?></span></button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php if ($lock): ?><div class="flash warn">Bạn đang làm bài kiểm tra “<?= h($lock) ?>”. Trợ lý tạm khoá đến khi bạn nộp bài.</div><?php endif; ?>
    <form class="ct-compose" id="ct-form">
      <div class="ct-tools" role="radiogroup" aria-label="Công cụ">
        <?php foreach (TUTOR_MODES as $k => [$ico, $label]): ?>
          <button type="button" class="ct-tool" data-mode="<?= $k ?>" role="radio"><?= $ico ?> <?= h($label) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="ct-ctx" id="ct-ctx" hidden>
        <label>Buổi học <select id="t-lesson"><option value="">Tất cả bài đã học</option>
          <?php foreach ($lessons as $l): ?><option value="<?= $l['id'] ?>" <?= $pre['lesson'] === (int)$l['id'] ? 'selected' : '' ?>>Buổi <?= (int)$l['no'] ?><?= $l['title'] !== '' ? ' · ' . h($l['title']) : '' ?></option><?php endforeach; ?></select></label>
        <label>Chủ đề <input id="t-topic" list="t-topics" value="<?= h($pre['topic']) ?>" placeholder="VD: Phân số"></label>
        <datalist id="t-topics"><?php foreach ($topics as $t): ?><option value="<?= h($t['topic']) ?>"><?php endforeach; ?></datalist>
        <label>Trình độ <select id="t-level"><?php foreach (TUTOR_LEVELS as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'thpt' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
        <label>Số câu <select id="t-n"><option>3</option><option selected>5</option><option>10</option></select></label>
      </div>
      <div class="ct-box">
        <button type="button" class="ct-ic" id="ct-ctx-btn" title="Ngữ cảnh: buổi học, chủ đề, trình độ" aria-expanded="false">⚙</button>
        <textarea id="t-text" rows="1" placeholder="Hỏi Cú Học bất cứ điều gì về bài học…" <?= $lock ? 'disabled' : '' ?>></textarea>
        <button type="button" class="ct-ic" id="ct-mic" title="Nói thay vì gõ" hidden>🎤</button>
        <button class="ct-send" id="t-send" <?= $lock ? 'disabled' : '' ?> title="Gửi (Enter)" aria-label="Gửi">➤</button>
      </div>
      <div class="ct-foot">Cú Học có thể nhầm — hãy đối chiếu với bài giảng và hỏi thầy cô khi chưa chắc. <button type="button" class="link" id="t-clear">Xoá toàn bộ lịch sử</button></div>
    </form>
  </section>
</div>
<script>window.TUTOR = <?= json_encode(['modes' => TUTOR_MODES, 'pre' => $pre, 'name' => $first, 'open' => $open['conversation'] ? $open : null, 'convs' => convs($code)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="../assets/<?= av('tutor.js') ?>"></script>
<?php student_foot();
