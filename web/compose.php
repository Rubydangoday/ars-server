<?php
// Soạn câu hỏi: bên trái nhập/dán/tải file Word–Excel để tự tách câu; bên phải trợ lý AI gợi ý câu hỏi.
// Mỗi câu chỉ được lưu khi giáo viên bấm xác nhận đưa vào bài kiểm tra hoặc bài ôn tập.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/ai.php';
$t = require_login();

$sets = qall('SELECT qs.id, qs.title, qs.purpose, qs.finalized_at, (SELECT COUNT(*) FROM questions q WHERE q.set_id=qs.id) AS n '
           . 'FROM question_sets qs WHERE qs.teacher_id=? ORDER BY qs.id DESC', [$t['id']]);
$lessons = qall('SELECT l.id, l.no, l.title, l.class_id, l.quiz_set_id, c.name AS class_name, '
              . '(l.lecture_text IS NOT NULL AND l.lecture_text<>"") AS has_text, (l.key_points IS NOT NULL AND l.key_points<>"") AS has_kp '
              . 'FROM lessons l JOIN classes c ON c.id=l.class_id WHERE c.teacher_id=? ORDER BY c.name, l.no', [$t['id']]);

// mở từ trang buổi học: nguồn = bài giảng, đích ôn tập = bộ câu hỏi củng cố của buổi
$pre = ['lesson' => 0, 'test' => 0, 'practice' => 0];
if (!empty($_GET['lesson'])) {
    $l = own_lesson((int)$_GET['lesson']);
    $pre['lesson'] = (int)$l['id'];
    if ($l['quiz_set_id']) $pre['practice'] = (int)$l['quiz_set_id'];
}
if (!empty($_GET['set'])) { $s = own_set((int)$_GET['set']); $pre[$s['purpose']] = (int)$s['id']; }
$ai = ai_conf();

page_head('Soạn câu hỏi', 'compose.php');
?>
<div class="page-head">
  <div><h1>Câu hỏi</h1><p class="muted">Nhập câu hỏi có sẵn hoặc để trợ lý AI gợi ý, rồi xác nhận từng câu đưa vào bài kiểm tra hay bài ôn tập.</p></div>
</div>
<nav class="subtabs"><a class="on" href="compose.php">✍ Soạn câu hỏi</a><a href="sets.php">🗂 Bộ câu hỏi (<?= count($sets) ?>)</a></nav>

<div class="target-bar">
  <label>Bài kiểm tra (chiếu lên lớp, dùng clicker)
    <span class="dst-row"><select id="dst-test" data-purpose="test"></select><a class="btn small fin-link" data-for="test" hidden>✓ Xem &amp; xác nhận bộ</a></span></label>
  <label>Bài ôn tập (học sinh làm trên web)
    <span class="dst-row"><select id="dst-practice" data-purpose="practice"></select><a class="btn small fin-link" data-for="practice" hidden>✓ Xem &amp; xác nhận bộ</a></span></label>
  <span class="hint">Câu hỏi chỉ được lưu khi bấm <b>✓ Thêm vào…</b> dưới từng câu (hoặc “Thêm tất cả”). Có thể bấm <b>Sửa</b> để chỉnh trước khi thêm.</span>
</div>

<div class="workspace">
  <!-- ============ TRÁI: nhập câu hỏi ============ -->
  <section class="panel" id="p-import">
    <header><span class="ico">⇪</span><div><h2>Nhập câu hỏi</h2><span class="muted small">Gõ, dán hoặc tải file — hệ thống tự tách từng câu</span></div></header>
    <div class="body">
      <div class="seg" role="tablist"><button type="button" class="on" data-tab="text">Gõ / dán văn bản</button><button type="button" data-tab="file">Tải file Word / Excel</button></div>
      <div data-pane="text" class="stack" style="max-width:none">
        <div class="qe" id="qe">
          <div class="ribbon" role="toolbar" aria-label="Thanh công cụ soạn thảo">
            <div class="rb-group"><div class="rb-btns">
              <button type="button" data-cmd="undo" title="Hoàn tác (Ctrl+Z)">↶</button><button type="button" data-cmd="redo" title="Làm lại (Ctrl+Y)">↷</button>
              <button type="button" data-cmd="clean" title="Dọn văn bản dán từ Word (khoảng trắng, dấu đầu dòng, đánh số tự động)">🧹</button></div><span class="rb-label">Chỉnh sửa</span></div>
            <div class="rb-group"><div class="rb-btns">
              <button type="button" data-cmd="bold" title="In đậm (Ctrl+B)"><b>B</b></button><button type="button" data-cmd="italic" title="In nghiêng (Ctrl+I)"><i>I</i></button>
              <button type="button" data-cmd="underline" title="Gạch chân (Ctrl+U)"><u>U</u></button>
              <button type="button" data-cmd="sup" title="Chỉ số trên">x²</button><button type="button" data-cmd="sub" title="Chỉ số dưới">x₂</button>
              <label class="rb-color" title="Màu chữ"><span>A</span><select data-cmd="color"><option value="">Màu chữ</option><option value="red">Đỏ</option><option value="blue">Xanh dương</option><option value="green">Xanh lá</option><option value="orange">Cam</option><option value="purple">Tím</option></select></label>
            </div><span class="rb-label">Định dạng</span></div>
            <div class="rb-group"><div class="rb-btns">
              <button type="button" data-cmd="q" title="Thêm câu hỏi mới (Ctrl+Enter trong ô = tách câu)">＋ Câu</button>
              <button type="button" data-cmd="opts" title="Thêm 4 phương án A–D">A–D</button>
              <button type="button" data-cmd="correct" title="Đánh dấu dòng phương án đang chọn là đáp án đúng">✔ Đúng</button>
              <button type="button" data-cmd="expl" title="Thêm dòng giải thích">💡</button><button type="button" data-cmd="topic" title="Đặt chủ đề cho các câu phía sau">🏷</button>
            </div><span class="rb-label">Câu hỏi</span></div>
            <div class="rb-group"><div class="rb-btns">
              <button type="button" class="rb-big" data-cmd="eq" title="Trình soạn công thức (Ctrl+M)"><span>∑</span>Công thức</button>
              <button type="button" class="rb-big" data-cmd="sym" title="Chèn ký hiệu"><span>Ω</span>Ký hiệu</button>
              <button type="button" class="rb-big" data-cmd="chem" title="Công thức / phương trình hoá học"><span>⚗</span>Hoá học</button>
            </div><span class="rb-label">Chèn</span></div>
            <div class="rb-group"><div class="rb-btns">
              <button type="button" data-cmd="find" title="Tìm và thay thế (Ctrl+H)">🔍</button>
              <button type="button" data-cmd="preview" class="rb-toggle" title="Xem trước như học sinh thấy">👁 Xem trước</button>
            </div><span class="rb-label">Công cụ</span></div>
          </div>
          <div class="qe-find" id="qe-find" hidden><input id="qe-f" placeholder="Tìm…"><input id="qe-r" placeholder="Thay bằng…">
            <button type="button" class="small" data-cmd="findnext">Tìm tiếp</button><button type="button" class="small" data-cmd="replace">Thay</button><button type="button" class="small" data-cmd="replaceall">Thay tất cả</button><span class="small muted" id="qe-fmsg"></span></div>
          <div class="qe-sym" id="qe-sym" hidden></div>
          <div class="qe-page">
            <textarea id="imp-text" rows="14" spellcheck="false" placeholder="Câu 1: MQTT hoạt động theo mô hình nào?&#10;A. Client/Server&#10;*B. Publish/Subscribe&#10;C. Peer-to-peer&#10;D. Request/Response&#10;Giải thích: Broker trung gian nhận và phân phối bản tin theo topic.&#10;&#10;Câu 2: Nghiệm của phương trình \(x^2 - 5x + 6 = 0\) là?&#10;…"></textarea>
            <div class="qe-preview" id="qe-preview" hidden></div>
          </div>
          <div class="qe-status"><span id="qe-count">0 câu</span><span class="sp"></span><span class="muted">Ctrl+B / I / U · Ctrl+M công thức · Ctrl+Enter tách câu</span></div>
        </div>
        <details class="fmt-help"><summary>Định dạng được nhận</summary>
          <pre>• Câu mới: "Câu 1:", "Câu 1.", "1." hoặc "1)"
• Phương án: "A.", "B)", "(C)" — mỗi dòng một phương án hoặc cùng một dòng
• Đáp án đúng: dấu * trước phương án (*B.), hoặc dòng "Đáp án: B",
  hoặc bảng đáp án cuối đề (1.A 2.C 3.B …)
• Trong Word: phương án gạch chân / tô đỏ / tô sáng được hiểu là đáp án đúng
• Công thức: \( … \) trong dòng, \[ … \] dòng riêng (LaTeX) — dùng nút ∑ Công thức
• Tuỳ chọn: "Giải thích: …", "Chủ đề: …" (áp dụng cho các câu sau đó)</pre></details>
        <div class="row" style="margin:0"><button type="button" class="primary" id="imp-parse">Tách câu hỏi</button><button type="button" class="ghost" id="imp-clear">Xoá</button></div>
      </div>
      <dialog class="qe-eq" id="qe-eq">
        <form method="dialog" class="qe-eq-in">
          <header><b>∑ Trình soạn công thức</b><span class="muted small">Gõ LaTeX hoặc bấm ký hiệu — xem trước ngay bên dưới</span><button value="cancel" class="ghost small" aria-label="Đóng">✕</button></header>
          <nav class="qe-tabs" id="qe-tabs"></nav>
          <div class="qe-pal" id="qe-pal"></div>
          <textarea id="qe-tex" rows="3" spellcheck="false" placeholder="\frac{-b \pm \sqrt{b^2-4ac}}{2a}"></textarea>
          <div class="qe-out" id="qe-out"><span class="muted">Xem trước công thức</span></div>
          <footer><span class="small muted" id="qe-err"></span><span class="sp"></span>
            <button value="inline" class="primary" id="qe-ins">Chèn vào dòng</button><button value="block" id="qe-ins-b">Chèn thành dòng riêng</button></footer>
        </form>
      </dialog>
      <div data-pane="file" hidden>
        <label class="drop" id="imp-drop"><input type="file" id="imp-file" accept=".docx,.xlsx,.csv,.pdf,.txt">
          <div style="font-size:28px">📄</div><b>Kéo thả file vào đây</b> hoặc bấm để chọn<br><span class="small">Word (.docx) · Excel (.xlsx, .csv) · PDF có chữ · tối đa 20 MB</span></label>
        <p class="small muted" style="margin:8px 0 0">Mẫu: <a href="template_docx.php">file Word mẫu</a> · <a href="template.php">file Excel mẫu</a> (sheet “Cau hoi”).</p>
      </div>
    </div>
    <div class="results" id="imp-results"></div>
  </section>

  <!-- ============ PHẢI: trợ lý AI ============ -->
  <section class="panel ai" id="p-ai">
    <header><span class="ico">✨</span><div><h2>Trợ lý AI</h2><a class="ai-status" href="settings.php#ai" title="Đổi trong Cài đặt"><?= h(ai_label($ai)) ?></a></div>
      <span class="sp"></span>
      <div class="seg ai-mode" role="tablist"><button type="button" class="on" data-mode="gen">🧩 Soạn câu hỏi</button><button type="button" data-mode="chat">💬 Hỏi đáp AI</button></div>
      <button type="button" class="ghost small" id="ai-reset" title="Cuộc trò chuyện mới">＋ Mới</button></header>
    <div class="results chat" id="ai-chat">
      <div class="ai-welcome" id="ai-empty">
        <div class="aw-ico">✨</div>
        <h3><?= te('Chào {name}, hôm nay soạn gì?', ['name' => trim(strrchr(' ' . $t['full_name'], ' '))]) ?></h3>
        <p class="muted small">Soạn câu hỏi theo yêu cầu hoặc từ bài giảng, hỏi cách giảng, lập giáo án, rubric chấm…</p>
        <div class="aw-cards">
          <?php
          $last_l = null; foreach ($lessons as $l) if ($l['has_text']) $last_l = $l;
          $cards = [['⚡', 'Kiểm tra đầu giờ', '5 câu nhanh về bài trước', ['mode' => 'gen', 'prompt' => 'Soạn câu hỏi kiểm tra nhanh đầu giờ về nội dung buổi trước', 'n' => 5, 'diff' => 'de']]];
          if ($last_l) $cards[] = ['📚', 'Củng cố từ bài giảng', lesson_label($last_l), ['mode' => 'gen', 'prompt' => 'Câu hỏi củng cố cuối buổi, bám sát kiến thức trọng tâm', 'n' => 5, 'src' => 'lesson:' . $last_l['id']]];
          $cards[] = ['🎭', 'Tình huống thực tế', 'Câu vận dụng, phương án nhiễu là lỗi hay mắc', ['mode' => 'gen', 'prompt' => 'Câu hỏi vận dụng dạng tình huống thực tế, phương án nhiễu là lỗi học sinh hay mắc', 'diff' => 'tb']];
          $cards[] = ['🧮', 'Bài tập có công thức', 'Tính toán ngắn, công thức LaTeX', ['mode' => 'gen', 'prompt' => 'Bài tập tính toán ngắn có số liệu cụ thể, viết công thức bằng LaTeX', 'diff' => 'tb']];
          $cards[] = ['📝', 'Lập giáo án 45 phút', 'Mục tiêu, hoạt động, đánh giá', ['mode' => 'chat', 'prompt' => 'Lập giáo án 45 phút cho bài học này: mục tiêu, khởi động, hoạt động chính, câu hỏi kiểm tra bằng Remote, củng cố.']];
          $cards[] = ['📏', 'Rubric chấm tự luận', 'Tiêu chí và thang điểm rõ ràng', ['mode' => 'chat', 'prompt' => 'Gợi ý rubric chấm một câu tự luận ngắn (thang 10 điểm), có tiêu chí và mô tả từng mức.']];
          $cards[] = ['💡', 'Cách giảng phần khó', 'Ví dụ, phép so sánh dễ hiểu', ['mode' => 'chat', 'prompt' => 'Gợi ý cách giảng một khái niệm khó cho học sinh trung bình: ví dụ đời thường, phép so sánh, lỗi hay gặp.']];
          $cards[] = ['🗣', 'Câu hỏi thảo luận', 'Câu mở cho làm việc nhóm', ['mode' => 'chat', 'prompt' => 'Gợi ý 5 câu hỏi thảo luận nhóm mở, kích thích tư duy phản biện về chủ đề đang học.']];
          foreach ($cards as [$ico, $ti, $de, $q]): ?>
            <button type="button" class="aw-card" data-card='<?= h(json_encode($q, JSON_UNESCAPED_UNICODE)) ?>'><i><?= $ico ?></i><b><?= h($ti) ?></b><span><?= h($de) ?></span></button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <form class="composer" id="ai-form">
      <div class="ai-opts" id="ai-opts">
        <label>Số câu <select id="ai-n"><option>3</option><option selected>5</option><option>10</option><option>15</option><option>20</option></select></label>
        <label>Lựa chọn <select id="ai-nopt"><option>2</option><option>3</option><option selected>4</option><option>5</option><option>6</option></select></label>
        <label>Mức độ <select id="ai-diff"><option value="mix">Trộn dễ–khó</option><option value="de">Dễ</option><option value="tb">Trung bình</option><option value="kho">Khó</option></select></label>
        <label class="grow">Nguồn tài liệu <select id="ai-src">
          <option value="">Không dùng — AI tự soạn theo yêu cầu</option>
          <option value="left">Văn bản ở khung “Nhập câu hỏi”</option>
          <?php $cur = null; foreach ($lessons as $l): if ($cur !== $l['class_id']) { if ($cur !== null) echo '</optgroup>'; echo '<optgroup label="Bài giảng · ' . h($l['class_name']) . '">'; $cur = $l['class_id']; } ?>
            <option value="lesson:<?= $l['id'] ?>" <?= $pre['lesson'] === (int)$l['id'] ? 'selected' : '' ?> <?= $l['has_text'] || $l['has_kp'] ? '' : 'disabled' ?>><?= h(lesson_label($l)) ?><?= $l['has_text'] ? '' : ($l['has_kp'] ? ' (chỉ có kiến thức trọng tâm)' : ' — chưa có bài giảng') ?></option>
          <?php endforeach; if ($cur !== null) echo '</optgroup>'; ?>
        </select></label>
        <button type="button" class="ai-addall" id="ai-addall" disabled title="Thêm mọi câu AI đã gợi ý ở trên (trừ câu đã thêm / đã bỏ) vào bộ kiểm tra đang chọn ở thanh trên cùng">✓ Thêm tất cả vào bài kiểm tra <b id="ai-addall-n">0</b></button>
      </div>
      <div class="ai-box">
        <textarea id="ai-prompt" rows="1" placeholder="Mô tả câu hỏi cần soạn, ví dụ: 5 câu về các mức QoS của MQTT, có 1 câu tình huống"></textarea>
        <button class="ai-send" id="ai-go" title="Gửi (Enter)" aria-label="Gửi">➤</button>
      </div>
      <div class="ai-foot">Enter để gửi · Shift+Enter xuống dòng · AI có thể sai — luôn duyệt lại câu hỏi trước khi dùng</div>
    </form>
  </section>
</div>

<script>
window.ARS_COMPOSE = <?= json_encode(['sets' => array_map(fn($s) => ['id' => (int)$s['id'], 'title' => $s['title'], 'purpose' => $s['purpose'], 'n' => (int)$s['n'], 'fin' => !empty($s['finalized_at'])], $sets),
    'pre' => $pre, 'letters' => LETTERS, 'difficulty' => DIFFICULTY], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/<?= av('qeditor.js') ?>"></script>
<?php page_foot('compose.js');
