<?php
// Soạn câu hỏi: bên trái nhập/dán/tải file Word–Excel để tự tách câu; bên phải trợ lý AI gợi ý câu hỏi.
// Mỗi câu chỉ được lưu khi giáo viên bấm xác nhận đưa vào bài kiểm tra hoặc bài ôn tập.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/ai.php';
$t = require_login();

$sets = qall('SELECT qs.id, qs.title, qs.purpose, (SELECT COUNT(*) FROM questions q WHERE q.set_id=qs.id) AS n '
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
    <select id="dst-test" data-purpose="test"></select></label>
  <label>Bài ôn tập (học sinh làm trên web)
    <select id="dst-practice" data-purpose="practice"></select></label>
  <span class="hint">Câu hỏi chỉ được lưu khi bấm <b>✓ Thêm vào…</b> dưới từng câu (hoặc “Thêm tất cả”). Có thể bấm <b>Sửa</b> để chỉnh trước khi thêm.</span>
</div>

<div class="workspace">
  <!-- ============ TRÁI: nhập câu hỏi ============ -->
  <section class="panel" id="p-import">
    <header><span class="ico">⇪</span><div><h2>Nhập câu hỏi</h2><span class="muted small">Gõ, dán hoặc tải file — hệ thống tự tách từng câu</span></div></header>
    <div class="body">
      <div class="seg" role="tablist"><button type="button" class="on" data-tab="text">Gõ / dán văn bản</button><button type="button" data-tab="file">Tải file Word / Excel</button></div>
      <div data-pane="text" class="stack" style="max-width:none">
        <textarea id="imp-text" rows="10" class="mono-ish" placeholder="Câu 1: MQTT hoạt động theo mô hình nào?&#10;A. Client/Server&#10;*B. Publish/Subscribe&#10;C. Peer-to-peer&#10;D. Request/Response&#10;Giải thích: Broker trung gian nhận và phân phối bản tin theo topic.&#10;&#10;Câu 2: …"></textarea>
        <details class="fmt-help"><summary>Định dạng được nhận</summary>
          <pre>• Câu mới: "Câu 1:", "Câu 1.", "1." hoặc "1)"
• Phương án: "A.", "B)", "(C)" — mỗi dòng một phương án hoặc cùng một dòng
• Đáp án đúng: dấu * trước phương án (*B.), hoặc dòng "Đáp án: B",
  hoặc bảng đáp án cuối đề (1.A 2.C 3.B …)
• Trong Word: phương án gạch chân / tô đỏ / tô sáng được hiểu là đáp án đúng
• Tuỳ chọn: "Giải thích: …", "Chủ đề: …" (áp dụng cho các câu sau đó)</pre></details>
        <div class="row" style="margin:0"><button type="button" class="primary" id="imp-parse">Tách câu hỏi</button><button type="button" class="ghost" id="imp-clear">Xoá</button></div>
      </div>
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
    <header><span class="ico">✨</span><div><h2>Trợ lý AI soạn câu hỏi</h2><span class="muted small">Mô tả điều bạn cần, AI gợi ý — bạn duyệt từng câu</span></div>
      <a class="tag ai ai-status" href="settings.php#ai" title="Đổi trong Cài đặt"><?= h(ai_label($ai)) ?></a></header>
    <div class="results chat" id="ai-chat" style="padding-top:14px">
      <div class="placeholder" id="ai-empty"><b>Bắt đầu bằng một yêu cầu</b>Ví dụ: “5 câu về QoS trong MQTT, có 1 câu tình huống”, hoặc chọn nguồn là bài giảng của một buổi để AI hỏi đúng nội dung đã dạy.</div>
    </div>
    <form class="composer" id="ai-form">
      <div class="chips">
        <button type="button" data-p="Soạn câu hỏi kiểm tra nhanh đầu giờ về nội dung buổi trước">Kiểm tra đầu giờ</button>
        <button type="button" data-p="Câu hỏi vận dụng dạng tình huống thực tế, phương án nhiễu là lỗi sinh viên hay mắc">Tình huống thực tế</button>
        <button type="button" data-p="Câu hỏi so sánh, phân biệt các khái niệm dễ nhầm lẫn">Phân biệt khái niệm</button>
        <button type="button" data-p="Câu hỏi tính toán ngắn có số liệu cụ thể">Bài tập tính toán</button>
        <button type="button" data-p="Câu hỏi ôn tập tổng hợp trước bài kiểm tra, phủ đều các chủ đề">Ôn trước kiểm tra</button>
      </div>
      <textarea id="ai-prompt" rows="3" placeholder="Ví dụ: 5 câu về các mức QoS của MQTT, mức trung bình, phương án ngắn gọn"></textarea>
      <div class="opts">
        <label>Số câu <select id="ai-n"><option>3</option><option selected>5</option><option>10</option><option>15</option><option>20</option></select></label>
        <label>Số lựa chọn <select id="ai-nopt"><option>2</option><option>3</option><option selected>4</option><option>5</option><option>6</option></select></label>
        <label>Mức độ <select id="ai-diff"><option value="mix">Trộn dễ–khó</option><option value="de">Dễ</option><option value="tb">Trung bình</option><option value="kho">Khó</option></select></label>
        <label style="flex:1;min-width:200px">Nguồn tài liệu <select id="ai-src">
          <option value="">Không dùng — AI tự soạn theo yêu cầu</option>
          <option value="left">Văn bản ở khung “Nhập câu hỏi”</option>
          <?php $cur = null; foreach ($lessons as $l): if ($cur !== $l['class_id']) { if ($cur !== null) echo '</optgroup>'; echo '<optgroup label="Bài giảng · ' . h($l['class_name']) . '">'; $cur = $l['class_id']; } ?>
            <option value="lesson:<?= $l['id'] ?>" <?= $pre['lesson'] === (int)$l['id'] ? 'selected' : '' ?> <?= $l['has_text'] || $l['has_kp'] ? '' : 'disabled' ?>><?= h(lesson_label($l)) ?><?= $l['has_text'] ? '' : ($l['has_kp'] ? ' (chỉ có kiến thức trọng tâm)' : ' — chưa có bài giảng') ?></option>
          <?php endforeach; if ($cur !== null) echo '</optgroup>'; ?>
        </select></label>
        <button class="ai" id="ai-go">✨ Tạo câu hỏi</button>
      </div>
    </form>
  </section>
</div>

<script>
window.ARS_COMPOSE = <?= json_encode(['sets' => array_map(fn($s) => ['id' => (int)$s['id'], 'title' => $s['title'], 'purpose' => $s['purpose'], 'n' => (int)$s['n']], $sets),
    'pre' => $pre, 'letters' => LETTERS, 'difficulty' => DIFFICULTY], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php page_foot('compose.js');
