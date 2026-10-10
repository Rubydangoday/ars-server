<?php
// Trang chủ ở địa chỉ gốc: trang giới thiệu ARS dạng cuộn (hero, tính năng, cách hoạt động, Remote, đối tượng, FAQ)
// và lối vào cho giáo viên / học sinh / phụ huynh. Giáo viên đã đăng nhập vào thẳng Tổng quan (trừ khi ?home=1).
// Minh hoạ giao diện vẽ bằng HTML/CSS (assets/landing.css), không ảnh ngoài — server có thể chạy offline.
require __DIR__ . '/lib/bootstrap.php';
if (me() && !isset($_GET['home'])) redirect('dashboard.php');

// Số liệu thật của máy chủ này (không phải số quảng cáo)
$stat = ['teachers' => 0, 'students' => 0, 'sessions' => 0, 'answers' => 0];
try {
    $stat = q1('SELECT (SELECT COUNT(*) FROM teachers WHERE active=1) teachers, (SELECT COUNT(DISTINCT COALESCE(student_code, CONCAT(class_id,"#",seat_no))) FROM students) students, '
             . '(SELECT COUNT(*) FROM sessions) sessions, (SELECT COUNT(*) FROM responses) answers') ?: $stat;
} catch (Throwable $e) {}
$org = setting('org_name', t('Lớp học tương tác'));
$feat = fn(string $ico, string $title, string $text) => '<li><i>' . $ico . '</i><div><b>' . te($title) . '</b><span>' . te($text) . '</span></div></li>';

page_head(t('ARS — Lớp học tương tác bằng Remote'), '', true);
?>
<link rel="stylesheet" href="assets/<?= av('landing.css') ?>">
<div class="lp">
<header class="lp-nav">
  <a class="lp-brand" href="portal.php?home=1"><?= ars_logo() ?><span><span translate="no">ARS</span><small><?= h($org) ?></small></span></a>
  <?php $anchors = '<a href="#tinh-nang">' . te('Tính năng') . '</a><a href="#cach-hoat-dong">' . te('Cách hoạt động') . '</a><a href="#remote">' . te('Remote') . '</a>'
      . '<a href="#doi-tuong">' . te('Dành cho') . '</a><a href="#hoi-dap">' . te('Hỏi đáp') . '</a>'; ?>
  <nav class="lp-links"><?= $anchors ?></nav>
  <details class="lp-menu"><summary aria-label="<?= te('Menu') ?>">☰</summary><nav><?= $anchors ?>
    <a href="student/">🎒 <?= te('Học sinh') ?></a><a href="parent/">👪 <?= te('Phụ huynh') ?></a></nav></details>
  <div class="lp-actions"><?= lang_switch() ?>
    <a class="lp-link" href="student/">🎒 <?= te('Học sinh') ?></a><a class="lp-link" href="parent/">👪 <?= te('Phụ huynh') ?></a>
    <a class="btn lp-cta" href="index.php"><?= te('Đăng nhập giáo viên') ?></a></div>
</header>

<!-- ============ HERO -->
<section class="lp-hero">
  <div class="lp-hero-text reveal">
    <span class="lp-eyebrow">📡 <?= te('Hệ thống phản hồi lớp học · Made in Vietnam') ?></span>
    <h1><?= te('Cả lớp cùng trả lời') ?><br><em><?= te('chỉ bằng một chiếc Remote') ?></em></h1>
    <p class="lp-lead"><?= te('Giáo viên thấy ngay ai hiểu bài, ai cần giúp — không cần điện thoại cho học sinh, chạy được cả khi mất Internet. Về nhà, học sinh ôn đúng câu đã sai, phụ huynh theo dõi tiến độ.') ?></p>
    <ul class="lp-checks">
      <li>✅ <?= te('Kết quả từng học sinh sau 1 giây') ?></li>
      <li>✅ <?= te('Soạn đề từ Word / Excel / PDF và AI') ?></li>
      <li>✅ <?= te('Dữ liệu nằm trên máy chủ của trường') ?></li>
    </ul>
    <div class="lp-btns">
      <a class="btn lp-cta big" href="index.php">🧑‍🏫 <?= te('Bắt đầu dạy') ?></a>
      <a class="btn lp-ghost big" href="student/">🎒 <?= te('Học sinh vào lớp') ?></a>
      <a class="btn lp-ghost big" href="parent/">👪 <?= te('Phụ huynh') ?></a>
    </div>
  </div>
  <div class="lp-hero-art reveal" aria-hidden="true">
    <div class="mock-screen">
      <div class="mock-bar"><span></span><span></span><span></span><b><?= te('Màn chiếu') ?> · 6A1</b></div>
      <div class="mock-q"><?= te('Câu 3') ?> · 2 + 3 = ?</div>
      <div class="mock-bars"><?php foreach ([['A', 18], ['B', 82], ['C', 12], ['D', 6]] as $i => [$l, $v]): ?>
        <div class="mb<?= $l === 'B' ? ' ok' : '' ?>"><span translate="no"><?= $l ?></span><i style="--w:<?= $v ?>%;animation-delay:<?= .2 + $i * .15 ?>s"></i><em><?= $v ?>%</em></div><?php endforeach; ?></div>
      <div class="mock-foot">✓ 28/30 <?= te('đã trả lời') ?> · ⏱ 0:12</div>
    </div>
    <div class="mock-remote" translate="no"><div class="lcd">B</div><div class="keys"><?php for ($i = 0; $i < 9; $i++): ?><i<?= $i === 4 ? ' class="hit"' : '' ?>></i><?php endfor; ?></div><div class="ok">OK</div></div>
    <div class="float f1">🟢 <?= te('Lan vừa trả lời') ?></div>
    <div class="float f2">📨 <?= te('Phụ huynh: cháu đúng 8/10 câu') ?></div>
  </div>
</section>

<!-- ============ HUY HIỆU + SỐ LIỆU THẬT -->
<section class="lp-trust reveal">
  <div class="badges"><span>🇻🇳 <?= te('Phát triển tại Việt Nam') ?></span><span>📴 <?= te('Chạy offline trong lớp') ?></span><span>🔒 <?= te('Dữ liệu ở máy chủ của trường') ?></span><span>🌐 Tiếng Việt · English</span></div>
  <div class="lp-stats">
    <div><b data-count="<?= (int)$stat['teachers'] ?>"><?= (int)$stat['teachers'] ?></b><span><?= te('giáo viên trên máy chủ này') ?></span></div>
    <div><b data-count="<?= (int)$stat['students'] ?>"><?= (int)$stat['students'] ?></b><span><?= te('học sinh') ?></span></div>
    <div><b data-count="<?= (int)$stat['sessions'] ?>"><?= (int)$stat['sessions'] ?></b><span><?= te('buổi học bằng Remote') ?></span></div>
    <div><b data-count="<?= (int)$stat['answers'] ?>"><?= (int)$stat['answers'] ?></b><span><?= te('lượt bấm Remote đã ghi nhận') ?></span></div>
  </div>
</section>

<!-- ============ CÁCH HOẠT ĐỘNG -->
<section class="lp-sec" id="cach-hoat-dong">
  <div class="lp-head reveal"><span class="lp-eyebrow"><?= te('Cách hoạt động') ?></span><h2><?= te('Từ soạn đề đến báo cáo trong 4 bước') ?></h2></div>
  <ol class="lp-steps">
    <?php foreach ([['✍️', 'Soạn câu hỏi', 'Gõ, dán, nhập Word / Excel / PDF hoặc nhờ AI tạo câu từ bài giảng.'],
                    ['▶️', 'Bắt đầu buổi học', 'Chọn lớp và bộ câu hỏi. Học sinh bấm MODE → số ghế → OK để điểm danh.'],
                    ['📡', 'Cả lớp bấm Remote', 'Mở câu, học sinh chọn A–J và bấm OK. Màn chiếu và bảng điều khiển cập nhật từng giây.'],
                    ['📊', 'Báo cáo & ôn tập', 'Kết quả, ma trận kiến thức tự động. Học sinh ôn câu sai, phụ huynh nhận tin Zalo / SMS.']] as $i => [$ico, $t, $d]): ?>
      <li class="reveal" style="--d:<?= $i * .08 ?>s"><span class="n"><?= $i + 1 ?></span><i><?= $ico ?></i><b><?= te($t) ?></b><p><?= te($d) ?></p></li>
    <?php endforeach; ?>
  </ol>
</section>

<!-- ============ TÍNH NĂNG (xen kẽ trái / phải) -->
<div id="tinh-nang"></div>
<section class="lp-feature">
  <div class="lp-ft-text reveal"><span class="lp-eyebrow">📡 <?= te('Lớp học trực tiếp') ?></span><h2><?= te('Nhìn một lần là biết cả lớp hiểu bài đến đâu') ?></h2>
    <ul class="lp-list"><?= $feat('🟩', 'Lưới chỗ ngồi trực tiếp', 'Ô học sinh nháy xanh ngay khi bấm; ai chưa trả lời, ai vắng, Remote nào yếu pin đều thấy.') ?>
      <?= $feat('📽', 'Màn chiếu toàn màn hình', 'Đồng hồ đếm ngược có âm thanh, biểu đồ A/B/C/D, pháo giấy khi cả lớp làm tốt.') ?>
      <?= $feat('🛡', 'Chống bấm hộ', 'Ghép Remote với học sinh, một ID một máy, loại gói trùng, gói muộn — tất cả kiểm tra ở máy chủ.') ?></ul></div>
  <div class="lp-ft-art reveal" aria-hidden="true"><div class="mock-card"><div class="mock-title">🎛 <?= te('Bảng điều khiển') ?> · <?= te('Câu 5') ?> <span class="pill">24/30</span></div>
    <div class="mock-seats" translate="no"><?php for ($i = 1; $i <= 30; $i++): $c = $i % 7 === 0 ? 'abs' : ($i % 5 === 0 ? 'pres' : 'ans'); ?><span class="<?= $c ?><?= $i === 12 ? ' pulse' : '' ?>"><?= $i ?></span><?php endfor; ?></div>
    <div class="mock-legend"><i class="ans"></i><?= te('đã trả lời') ?> <i class="pres"></i><?= te('chưa trả lời') ?> <i class="abs"></i><?= te('vắng') ?></div></div></div>
</section>

<section class="lp-feature alt">
  <div class="lp-ft-text reveal"><span class="lp-eyebrow">✨ <?= te('Soạn đề thông minh') ?></span><h2><?= te('Đề có sẵn trong Word? Tải lên là xong') ?></h2>
    <ul class="lp-list"><?= $feat('📄', 'Nhập Word / Excel / PDF', 'Tự tách câu hỏi, phương án, đáp án; xem trước và xác nhận từng câu.') ?>
      <?= $feat('🤖', 'Trợ lý AI', 'Tạo câu trắc nghiệm theo chủ đề hoặc từ bài giảng; chạy offline cơ bản hoặc với Claude / OpenAI / Ollama.') ?>
      <?= $feat('🔀', 'Mã đề & kho đề dùng chung', 'Đảo câu, đảo phương án; chia sẻ đề trong trường; công thức Toán – Lý – Hoá, hình, audio, video.') ?></ul></div>
  <div class="lp-ft-art reveal" aria-hidden="true"><div class="mock-card"><div class="mock-title">📄 De_kiem_tra_15p.docx → <b>20 <?= te('câu') ?></b></div>
    <div class="mock-qs"><?php foreach ([['1', 'x² − 5x + 6 = 0', 'B'], ['2', 'H₂O + CO₂ → ?', 'C'], ['3', 'Thủ đô của Úc?', 'A']] as [$n, $q, $a]): ?>
      <div class="mq"><b><?= $n ?></b><span><?= te($q) ?></span><em><?= te('Đáp án') ?> <?= $a ?></em><i>✓</i></div><?php endforeach; ?></div>
    <div class="mock-ai">✨ <?= te('AI gợi ý thêm 5 câu về phương trình bậc hai') ?>…</div></div></div>
</section>

<section class="lp-feature">
  <div class="lp-ft-text reveal"><span class="lp-eyebrow">🏡 <?= te('Bài tập về nhà') ?></span><h2><?= te('Giao bài một lần, chấm nhanh gấp nhiều lần') ?></h2>
    <ul class="lp-list"><?= $feat('🧩', 'Bốn kiểu câu', 'Trắc nghiệm, tự luận, vẽ tay / chụp ảnh bài làm, ghi âm trả lời — làm trên điện thoại của phụ huynh.') ?>
      <?= $feat('🤖', 'AI gợi ý chấm tự luận', 'Chấm theo đáp án mẫu, giáo viên xác nhận rồi mới công bố điểm.') ?>
      <?= $feat('🎉', 'Học mà vui', 'Thanh tiến độ, pháo giấy khi nộp bài, điểm thưởng và huy hiệu.') ?></ul></div>
  <div class="lp-ft-art reveal" aria-hidden="true"><div class="mock-phone"><div class="ph-top"></div><div class="ph-prog"><i></i></div><b><?= te('Câu 2') ?> · <?= te('Vẽ hình') ?></b>
    <svg viewBox="0 0 200 110"><rect x="2" y="2" width="196" height="106" rx="10" fill="#fff" stroke="#e5e0f5"/><path class="draw" d="M30 90 L100 20 L170 90 Z M100 20 L100 90" fill="none" stroke="#7c3aed" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <div class="ph-btn">🎉 <?= te('Nộp bài') ?></div></div></div>
</section>

<section class="lp-feature alt">
  <div class="lp-ft-text reveal"><span class="lp-eyebrow">📊 <?= te('Báo cáo') ?></span><h2><?= te('Ma trận lỗ hổng kiến thức cho từng học sinh') ?></h2>
    <ul class="lp-list"><?= $feat('🧠', 'Theo chủ đề', 'Thấy ngay học sinh yếu phần nào để dạy lại đúng chỗ.') ?>
      <?= $feat('📈', 'Độ khó, độ phân biệt, phương án nhiễu', 'Phân tích từng câu như phần mềm khảo thí.') ?>
      <?= $feat('🏫', 'GV chủ nhiệm & Ban giám hiệu', 'Tổng hợp mọi môn của lớp hành chính, xuất Excel / PDF.') ?></ul></div>
  <div class="lp-ft-art reveal" aria-hidden="true"><div class="mock-card"><div class="mock-title">🧠 <?= te('Ma trận kiến thức') ?> · 6A1</div>
    <table class="mock-heat"><tr><th></th><th><?= te('Đại số') ?></th><th><?= te('Hình học') ?></th><th><?= te('Số học') ?></th></tr>
    <?php foreach ([['An', 92, 45, 80], ['Bình', 70, 88, 64], ['Chi', 38, 72, 91], ['Dũng', 85, 60, 40]] as $r): ?><tr><td><?= h($r[0]) ?></td>
      <?php for ($j = 1; $j <= 3; $j++): $v = $r[$j]; ?><td class="<?= $v < 50 ? 'lo' : ($v < 80 ? 'mid' : 'hi') ?>"><?= $v ?>%</td><?php endfor; ?></tr><?php endforeach; ?></table></div></div>
</section>

<section class="lp-feature">
  <div class="lp-ft-text reveal"><span class="lp-eyebrow">🦉 <?= te('Cho học sinh') ?></span><h2><?= te('Ôn đúng câu đã sai, có gia sư AI bên cạnh') ?></h2>
    <ul class="lp-list"><?= $feat('❌', 'Sổ câu sai tự động', 'Gom câu bấm sai trên lớp và làm sai ở nhà; ôn lại tới khi thuộc.') ?>
      <?= $feat('🦉', 'Cú Học — trợ lý AI', 'Giải thích ngắn gọn theo cấp học, ra thêm câu luyện tập.') ?>
      <?= $feat('🏆', 'Thi đua & đổi quà', 'Bảng xếp hạng tuần / tháng, đổi avatar, huy hiệu, quà ở lớp.') ?></ul></div>
  <div class="lp-ft-art reveal" aria-hidden="true"><div class="mock-card stu"><div class="mock-title">🏆 <?= te('Bảng xếp hạng tuần') ?></div>
    <?php foreach ([['🥇', '🦁', 'Minh Anh', 320], ['🥈', '🐼', 'Gia Bảo', 290], ['🥉', '🦊', 'Khánh Linh', 275], ['4', '🚀', 'Tuấn Kiệt', 240]] as [$m, $a, $n, $p]): ?>
      <div class="lb-row"><span><?= $m ?></span><i><?= $a ?></i><b><?= h($n) ?></b><em><?= $p ?></em></div><?php endforeach; ?></div></div>
</section>

<section class="lp-feature alt">
  <div class="lp-ft-text reveal"><span class="lp-eyebrow">👪 <?= te('Cho phụ huynh') ?></span><h2><?= te('Biết con học thế nào chỉ trong một phút') ?></h2>
    <ul class="lp-list"><?= $feat('📈', 'Tiến độ theo ngày / tuần', 'Điểm bấm Remote, chuyên cần, bài tập, nhận xét của thầy cô.') ?>
      <?= $feat('📨', 'Tin Zalo / SMS sau mỗi buổi', '“Hôm nay cháu trả lời đúng 8/10 câu bằng Remote.” Phụ huynh tự đăng ký nhận tin.') ?>
      <?= $feat('🔑', 'Đăng nhập bằng Google / Facebook', 'Liên kết đúng con bằng mã mời riêng của thầy cô.') ?></ul></div>
  <div class="lp-ft-art reveal" aria-hidden="true"><div class="mock-phone parent"><div class="ph-top"></div>
    <div class="ph-msg">📨 <b>ARS</b><br><?= te('Hôm nay cháu Minh Anh trả lời đúng 8/10 câu bằng Remote trên lớp Toán.') ?></div>
    <div class="ph-kpis"><span><b>92%</b><?= te('chuyên cần') ?></span><span><b>8.4</b><?= te('điểm TB') ?></span></div>
    <svg viewBox="0 0 200 70"><polyline class="draw" points="8,60 45,50 80,38 115,42 150,22 192,10" fill="none" stroke="#3f7a5a" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg></div></div>
</section>

<!-- ============ REMOTE (phần cứng) -->
<section class="lp-sec lp-dark" id="remote">
  <div class="lp-head reveal"><span class="lp-eyebrow"><?= te('Phần cứng') ?></span><h2><?= te('Remote bền, rẻ, không cần điện thoại') ?></h2>
    <p><?= te('Mỗi học sinh một Remote nRF52840 nhỏ gọn. Laptop giáo viên thu cả lớp cùng lúc bằng Bluetooth sẵn có — không cần thêm thiết bị.') ?></p></div>
  <div class="lp-specs">
    <?php foreach ([['🔵', 'Bluetooth (nRF52840)', 'Laptop nghe cả phòng, không cần ghép đôi từng máy.'],
                    ['💻', 'Bluetooth laptop', 'Máy chủ ARS dùng Bluetooth của chính laptop để thu đáp án và phát trạng thái về Remote.'],
                    ['🔁', 'Gửi lại tự động', 'Mỗi lần bấm phát 5 lần, máy chủ loại trùng, đèn xanh khi đã nhận.'],
                    ['🔋', 'Theo dõi pin & sóng', 'Biết Remote nào yếu pin, ở góc nào sóng kém.'],
                    ['👥', '99 học sinh / lớp', 'Tối đa 64 câu mỗi đề, 2–10 phương án A–J.'],
                    ['📴', 'Không cần Internet', 'Máy chủ chạy ngay trên laptop, đồng bộ Cloud khi có mạng.']] as $i => [$ico, $t, $d]): ?>
      <div class="spec reveal" style="--d:<?= $i * .05 ?>s"><i><?= $ico ?></i><b><?= h($t === 'Bluetooth (nRF52840)' ? $t : t($t)) ?></b><span><?= te($d) ?></span></div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ============ ĐỐI TƯỢNG -->
<section class="lp-sec" id="doi-tuong">
  <div class="lp-head reveal"><span class="lp-eyebrow"><?= te('Dành cho') ?></span><h2><?= te('Phù hợp mọi lớp học') ?></h2></div>
  <div class="lp-aud">
    <?php foreach ([['🧑‍🏫', 'Giáo viên', 'Tạo lớp trong 1 phút, dạy ngay với Remote, có báo cáo sau mỗi buổi.'],
                    ['🏫', 'Trường THCS – THPT', 'Admin trường, GV chủ nhiệm, kho đề dùng chung, báo cáo theo lớp hành chính.'],
                    ['🧒', 'Tiểu học', 'Remote dễ bấm, màn chiếu vui mắt, thi đua đổi quà — không cần cho trẻ dùng điện thoại.'],
                    ['📚', 'Trung tâm', 'Nhiều giáo viên trên một máy chủ, tin nhắn kết quả cho phụ huynh.'],
                    ['🎓', 'Đại học & đào tạo', 'Điểm danh nhanh, câu hỏi khảo sát, phân tích độ khó – độ phân biệt.']] as $i => [$ico, $t, $d]): ?>
      <div class="aud reveal" style="--d:<?= $i * .06 ?>s"><i><?= $ico ?></i><b><?= te($t) ?></b><span><?= te($d) ?></span></div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ============ VÌ SAO CHỌN ARS -->
<section class="lp-sec soft">
  <div class="lp-head reveal"><span class="lp-eyebrow"><?= te('Vì sao chọn ARS') ?></span><h2><?= te('Thiết kế cho lớp học Việt Nam') ?></h2></div>
  <div class="lp-why">
    <?php foreach ([['📴', 'Chạy offline', 'Lớp học không phụ thuộc Wi-Fi trường hay 4G.'], ['🔒', 'Dữ liệu ở trường', 'Không gửi dữ liệu học sinh về bên thứ ba khi không bật.'],
                    ['📵', 'Không cần điện thoại', 'Học sinh không bị phân tâm, không cần thiết bị đắt tiền.'], ['🌐', 'Đa ngôn ngữ', 'Tiếng Việt và English cho giáo viên nước ngoài.'],
                    ['☁️', 'Đồng bộ Cloud', 'Bấm một nút, phụ huynh và học sinh xem được ở nhà.'], ['🧪', 'Kiểm thử kỹ', 'Hàng trăm kiểm tra tự động cho mỗi lần cập nhật.']] as $i => [$ico, $t, $d]): ?>
      <div class="why reveal" style="--d:<?= $i * .05 ?>s"><i><?= $ico ?></i><b><?= te($t) ?></b><span><?= te($d) ?></span></div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ============ HỎI ĐÁP -->
<section class="lp-sec" id="hoi-dap">
  <div class="lp-head reveal"><span class="lp-eyebrow"><?= te('Hỏi đáp') ?></span><h2><?= te('Câu hỏi thường gặp') ?></h2></div>
  <div class="lp-faq">
    <?php foreach ([['Học sinh có cần điện thoại không?', 'Không. Trên lớp học sinh chỉ dùng Remote. Ở nhà, học sinh và phụ huynh dùng điện thoại hoặc máy tính để làm bài tập, ôn câu sai và xem tiến độ.'],
                    ['Mất Internet có dạy được không?', 'Được. Máy chủ ARS chạy trên laptop giáo viên, Remote gửi thẳng tới Bluetooth của laptop, không cần mạng Wi-Fi. Khi có mạng, bấm “Sync to Cloud” để đẩy kết quả lên.'],
                    ['Học sinh vào lớp thế nào?', 'Mở cổng học sinh, tạo tài khoản bằng mã học sinh (hoặc Google / Facebook) và nhập mã tham gia lớp giáo viên cho.'],
                    ['Dữ liệu học sinh được lưu ở đâu?', 'Trên máy chủ của trường / giáo viên. Xem chi tiết trong Chính sách quyền riêng tư.'],
                    ['Giáo viên nước ngoài dùng được không?', 'Được. Chọn English ở góc trên — giao diện giáo viên và phụ huynh chuyển sang tiếng Anh.']] as [$q, $a]): ?>
      <details class="reveal"><summary><?= te($q) ?></summary><p><?= te($a) ?></p></details>
    <?php endforeach; ?>
  </div>
</section>

<!-- ============ KÊU GỌI -->
<section class="lp-final reveal">
  <h2><?= te('Sẵn sàng cho buổi học tương tác đầu tiên?') ?></h2>
  <p><?= te('Tạo lớp, nhập danh sách, bấm Bắt đầu — cả lớp trả lời bằng Remote trong vài phút.') ?></p>
  <div class="lp-btns"><a class="btn lp-cta big" href="index.php">🧑‍🏫 <?= te('Đăng nhập giáo viên') ?></a><a class="btn lp-ghost big light" href="register.php"><?= te('Tạo tài khoản giáo viên') ?></a></div>
  <p class="small"><?= te('Link ngắn') ?>: <b><?= h(current_base()) ?>/gv</b> (<?= te('giáo viên') ?>) · <b><?= h(current_base()) ?>/hs</b> (<?= te('học sinh') ?>)</p>
</section>

<footer class="lp-foot">
  <div><a class="lp-brand" href="portal.php?home=1"><?= ars_logo() ?><span><span translate="no">ARS</span><small><?= h($org) ?></small></span></a>
    <p><?= te('Hệ thống phản hồi lớp học bằng Remote — dạy tương tác, đánh giá tức thì, ôn tập đúng chỗ.') ?></p></div>
  <div><b><?= te('Sản phẩm') ?></b><a href="#tinh-nang"><?= te('Tính năng') ?></a><a href="#remote"><?= te('Remote') ?></a><a href="#cach-hoat-dong"><?= te('Cách hoạt động') ?></a></div>
  <div><b><?= te('Vào hệ thống') ?></b><a href="index.php"><?= te('Giáo viên') ?></a><a href="student/"><?= te('Học sinh') ?></a><a href="parent/"><?= te('Phụ huynh') ?></a></div>
  <div><b><?= te('Hỗ trợ') ?></b><a href="#hoi-dap"><?= te('Hỏi đáp') ?></a><a href="privacy.php"><?= te('Chính sách quyền riêng tư') ?></a><a href="privacy.php#xoa-du-lieu"><?= te('Xoá dữ liệu') ?></a>
    <?php if ($c = setting('privacy_contact')): ?><a href="mailto:<?= h($c) ?>"><?= h($c) ?></a><?php endif; ?></div>
  <p class="copy">© <?= date('Y') ?> ARS · <?= h($org) ?></p>
</footer>
</div>
<script>
// Hiện dần khi cuộn tới. Không có JS / trình duyệt cũ: mọi khối vẫn hiện bình thường (chỉ ẩn khối nằm dưới màn hình lúc tải).
(function () {
  if (!('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const io = new IntersectionObserver((es) => es.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }), { threshold: .12, rootMargin: '0px 0px -40px 0px' });
  document.querySelectorAll('.lp .reveal').forEach((el) => { if (el.getBoundingClientRect().top > innerHeight) { el.classList.add('rv'); io.observe(el); } });
  // menu dính: đổ bóng khi cuộn; menu điện thoại tự đóng khi chọn mục
  const nav = document.querySelector('.lp-nav');
  addEventListener('scroll', () => nav.classList.toggle('stuck', scrollY > 8), { passive: true });
  document.querySelectorAll('.lp-menu nav a').forEach((a) => a.addEventListener('click', () => a.closest('details').open = false));
})();
</script>
<?php page_foot();
