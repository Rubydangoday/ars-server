<?php
// Chính sách quyền riêng tư + hướng dẫn xoá dữ liệu (công khai, không cần đăng nhập).
// Google yêu cầu đường dẫn này khi công khai nút "Đăng nhập với Google".
// Nội dung mô tả đúng những gì mã nguồn ARS làm; nhà trường nên rà soát trước khi công bố chính thức.
require __DIR__ . '/lib/bootstrap.php';
$org = setting('org_name', 'Đơn vị vận hành ARS');
$contact = setting('privacy_contact');
$ai = setting('ai_provider', 'offline') !== 'offline';
page_head('Chính sách quyền riêng tư', '', true);
?>
<article class="card legal">
  <p><a href="portal.php">← Trang chủ ARS</a></p>
  <h1>Chính sách quyền riêng tư</h1>
  <p class="muted">Áp dụng cho hệ thống ARS do <b><?= h($org) ?></b> vận hành. Cập nhật: <?= date('d/m/Y', filemtime(__FILE__)) ?>.</p>

  <h2>1. Ai chịu trách nhiệm</h2>
  <p>ARS là phần mềm phản hồi lớp học bằng Remote, cài trên máy chủ của <?= h($org) ?>. Dữ liệu do nhà trường / giáo viên quản lý, không gửi về nhà phát triển phần mềm.
  <?= $contact ? 'Liên hệ về dữ liệu cá nhân: <b>' . h($contact) . '</b>.' : 'Liên hệ về dữ liệu cá nhân: giáo viên phụ trách lớp hoặc quản trị viên nhà trường.' ?></p>

  <h2>2. Dữ liệu được thu thập</h2>
  <ul>
    <li><b>Giáo viên:</b> họ tên, tên đăng nhập, mật khẩu (lưu dạng băm, không đọc lại được), lớp, bộ câu hỏi, bài giảng tải lên.</li>
    <li><b>Học sinh:</b> mã học sinh, họ tên, số ghế; đáp án bấm Remote trên lớp kèm thời điểm; điểm danh; bài tập về nhà (chữ, hình vẽ, ghi âm); lượt ôn tập; câu hỏi gửi trợ lý học tập; điểm thi đua và quà đã đổi.</li>
    <li><b>Phụ huynh:</b> họ tên, tên đăng nhập, mật khẩu (dạng băm), học sinh được liên kết; số điện thoại và kênh nhận tin nếu phụ huynh tự đăng ký nhận thông báo.</li>
    <li><b>Thiết bị Remote:</b> địa chỉ MAC, mức pin, cường độ sóng — để vận hành lớp học.</li>
    <li><b>Đăng nhập bằng Google:</b> chỉ khi người dùng tự bấm liên kết. ARS lưu mã định danh tài khoản do Google cấp, họ tên và email (nếu có). ARS <b>không</b> lưu mật khẩu Google, không lưu mã truy cập (access token), không đọc danh bạ, bạn bè, bài đăng hay dữ liệu nào khác.</li>
    <li><b>Cookie:</b> một cookie phiên đăng nhập. Trình duyệt lưu thêm vài lựa chọn giao diện (bật/tắt âm thanh, mức độ trợ lý) ngay trên máy người dùng.</li>
  </ul>

  <h2>3. Mục đích sử dụng</h2>
  <p>Tổ chức lớp học và điểm danh, chấm điểm, giao và chấm bài tập, báo cáo tiến độ cho giáo viên, học sinh và phụ huynh được liên kết, thi đua trong lớp. ARS không bán dữ liệu, không quảng cáo, không dùng dữ liệu để lập hồ sơ thương mại.</p>

  <h2>4. Chia sẻ với bên thứ ba</h2>
  <ul>
    <li><b>Google:</b> chỉ để xác thực khi người dùng chọn đăng nhập bằng tài khoản Google.</li>
    <li><b>Trợ lý AI</b><?= $ai ? '' : ' (hiện đang tắt hoặc chạy cục bộ)' ?>: nếu nhà trường bật dịch vụ AI trên Internet, nội dung câu hỏi, bài giảng, câu hỏi học sinh gửi trợ lý và bài tự luận được gửi chấm thử sẽ được gửi tới nhà cung cấp AI đó để xử lý. Không gửi mật khẩu, số điện thoại.</li>
    <li><b>Zalo / nhà mạng SMS:</b> nếu phụ huynh đăng ký nhận tin, số điện thoại và nội dung tin kết quả buổi học được gửi qua nhà cung cấp đó.</li>
    <li><b>Máy chủ Cloud của nhà trường:</b> khi giáo viên bấm “Sync to Cloud”, lớp, danh sách học sinh và kết quả bấm Remote được sao chép sang máy chủ Cloud cũng do nhà trường quản lý.</li>
  </ul>

  <h2>5. Trẻ em</h2>
  <p>Học sinh có thể dưới 16 tuổi. Phụ huynh chỉ xem được dữ liệu của con sau khi dùng mã mời do giáo viên cấp riêng. Phụ huynh có quyền yêu cầu xem, sửa hoặc xoá dữ liệu của con theo quy định về bảo vệ dữ liệu cá nhân (Nghị định 13/2023/NĐ-CP).</p>

  <h2>6. Lưu trữ và bảo mật</h2>
  <p>Dữ liệu lưu trên máy chủ của nhà trường. Tệp bài làm và bài giảng lưu ngoài thư mục web, chỉ người có quyền mới mở được. Kết nối qua Internet dùng HTTPS. Dữ liệu được giữ trong thời gian học sinh còn theo học hoặc tới khi nhà trường xoá lớp.</p>

  <h2 id="xoa-du-lieu">7. Xoá dữ liệu và gỡ liên kết Google</h2>
  <ol>
    <li><b>Gỡ liên kết ngay:</b> đăng nhập ARS → trang tài khoản (giáo viên: Cài đặt → Tài khoản; học sinh: Đổi mật khẩu; phụ huynh: cuối trang Tiến độ của con) → bấm <b>Gỡ liên kết</b>. ARS xoá mã định danh, tên và email Google đã lưu.</li>
    <li>Bạn cũng có thể gỡ quyền ARS từ phía nhà cung cấp: myaccount.google.com/permissions.</li>
    <li><b>Xoá toàn bộ dữ liệu cá nhân:</b> gửi yêu cầu tới <?= $contact ? '<b>' . h($contact) . '</b>' : 'giáo viên phụ trách lớp hoặc quản trị viên nhà trường' ?>, ghi rõ họ tên và mã học sinh / tên đăng nhập. Yêu cầu được xử lý trong 30 ngày; một số dữ liệu điểm có thể phải lưu theo quy định của nhà trường.</li>
  </ol>

  <h2>8. Thay đổi chính sách</h2>
  <p>Khi chính sách thay đổi, ngày cập nhật ở đầu trang sẽ thay đổi theo.</p>
</article>
<style>.legal{max-width:820px;margin:24px auto;line-height:1.65}.legal h2{margin-top:22px}.legal li{margin:6px 0}</style>
<?php page_foot();
