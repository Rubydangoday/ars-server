<?php
// Thông báo kết quả buổi học cho phụ huynh qua Zalo OA / SMS: cấu hình (quản trị server) và nhật ký gửi (giáo viên).
require_once __DIR__ . '/lib/notify.php';
$t = require_login();
$admin = is_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'config') {
            if (!$admin) throw new RuntimeException('Chỉ quản trị viên được đổi mục này.');
            foreach (['zalo_endpoint', 'sms_url'] as $k)       // token đi kèm trong header → chỉ gửi qua https
                if (trim($_POST[$k] ?? '') !== '' && !preg_match('#^https://\S+$#i', trim($_POST[$k]))) throw new RuntimeException('Địa chỉ nhà cung cấp phải bắt đầu bằng https://');
            foreach (['zalo_template_id' => 60, 'zalo_template_params' => 300, 'zalo_endpoint' => 255, 'sms_url' => 255] as $k => $n) setting_set($k, mb_substr(trim($_POST[$k] ?? ''), 0, $n) ?: null);
            foreach (['zalo_access_token', 'sms_token'] as $k) {            // ô hiện dạng •••• = giữ giá trị cũ
                $v = trim($_POST[$k] ?? '');
                if (isset($_POST['clear_' . $k])) setting_set($k, null);
                elseif ($v !== '' && !preg_match('/^•+/u', $v)) setting_set($k, mb_substr($v, 0, 2000));
            }
            $tpl = trim($_POST['notify_template'] ?? '');
            setting_set('notify_template', $tpl !== '' ? mb_substr($tpl, 0, 400) : null);
            setting_set('notify_auto', isset($_POST['notify_auto']) ? '1' : '0');
            setting_set('notify_test_mode', isset($_POST['notify_test_mode']) ? '1' : '0');
            setting_set('sms_ascii', isset($_POST['sms_ascii']) ? '1' : '0');
            flash('Đã lưu cài đặt thông báo.');
        } elseif ($act === 'retry') {
            $own = array_map('intval', array_column(qall('SELECT s.id FROM sessions s JOIN classes c ON c.id=s.class_id WHERE c.teacher_id=?', [$t['id']]), 'id'));
            $r = notify_send_pending(100, $own);
            flash("Đã gửi {$r['sent']} tin" . ($r['failed'] ? ", {$r['failed']} tin lỗi" : '') . '.', $r['failed'] ? 'err' : 'ok');
        } else throw new RuntimeException('Lệnh không hợp lệ.');
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('notify.php');
}

$mask = fn(string $k) => ($v = (string)setting($k, '')) === '' ? '' : str_repeat('•', 8) . mb_substr($v, -4);
$rows = qall('SELECT n.*, p.full_name AS parent_name, c.name AS class_name, s.started_at FROM notifications n JOIN sessions s ON s.id=n.session_id JOIN classes c ON c.id=s.class_id '
    . 'JOIN parents p ON p.id=n.parent_id WHERE c.teacher_id=? ORDER BY n.id DESC LIMIT 100', [$t['id']]);
$retry = count(array_filter($rows, fn($r) => $r['status'] === 'pending' || ($r['status'] === 'failed' && $r['attempts'] < NOTIFY_MAX_ATTEMPTS)));
$ch = notify_channels();
page_head('Thông báo phụ huynh', 'notify.php');
?>
<div class="page-head"><div><h1>📨 Thông báo Zalo / SMS cho phụ huynh</h1>
  <p class="muted">Sau buổi học đã công bố kết quả, phụ huynh nhận tin như: “<?= h(strtr(setting('notify_template') ?: NOTIFY_DEFAULT_TEMPLATE, ['{ngay}' => date('d/m/Y'), '{hs}' => 'Nguyễn Văn A', '{dung}' => '8', '{tong}' => '10', '{mon}' => 'Toán', '{lop}' => '6A1'])) ?>”.
  Chỉ gửi cho phụ huynh đã tự nhập số điện thoại và chọn nhận tin trong cổng phụ huynh.</p></div>
  <div><a class="btn" href="sync.php">← Đồng bộ Cloud</a></div></div>
<p>Kênh đang dùng được: <?= $ch ? implode(', ', array_map('h', $ch)) : '<b>chưa có</b>' ?><?= setting('notify_test_mode') === '1' ? ' <span class="tag gold">chế độ thử — chỉ ghi nhật ký, không gửi thật</span>' : '' ?></p>

<section class="card"><h2>Tin đã gửi (lớp của bạn)</h2>
  <?php if ($retry): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><button class="primary">Gửi <?= $retry ?> tin đang chờ / lỗi</button></form><?php endif; ?>
  <?php if (!$rows): ?><p class="muted">Chưa có tin nào. Gửi từ trang <b>Kết quả</b> của buổi học đã kết thúc và công bố.</p><?php else: ?>
  <table class="grid compact"><thead><tr><th>Buổi</th><th>Phụ huynh</th><th>Kênh</th><th>Nội dung</th><th>Trạng thái</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td class="small"><?= h($r['class_name']) ?><br><?= h($r['started_at']) ?></td><td><?= h($r['parent_name']) ?><br><span class="small muted"><?= h(substr($r['phone'], 0, 3) . '****' . substr($r['phone'], -3)) ?></span></td>
    <td><?= h(['zalo' => 'Zalo', 'sms' => 'SMS', 'log' => 'Thử'][$r['channel']]) ?></td><td class="small"><?= h($r['message']) ?></td>
    <td><?= ['sent' => '<span class="tag live">đã gửi</span>', 'pending' => '<span class="tag">chờ gửi</span>', 'sending' => '<span class="tag">đang gửi</span>', 'failed' => '<span class="tag off">lỗi</span>'][$r['status']] ?>
      <?= $r['error'] ? '<br><span class="small muted">' . h($r['error']) . '</span>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>

<?php if ($admin): ?>
<section class="card"><h2>Cài đặt nhà cung cấp <span class="muted small">(quản trị server — nên cấu hình trên server Cloud)</span></h2>
<form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="config">
  <h3>Zalo OA — Zalo Notification Service (ZNS)</h3>
  <p class="muted small">Cần Zalo Official Account đã xác thực và một template ZNS được Zalo duyệt. Access token OA hết hạn định kỳ: cập nhật lại khi Zalo báo lỗi token.</p>
  <label>Access token OA <input name="zalo_access_token" autocomplete="off" value="<?= h($mask('zalo_access_token')) ?>"></label>
  <label class="check"><input type="checkbox" name="clear_zalo_access_token"> Xoá token</label>
  <label>Template ID <input name="zalo_template_id" value="<?= h(setting('zalo_template_id', '')) ?>"></label>
  <label>Tham số template <input name="zalo_template_params" value="<?= h(setting('zalo_template_params', '')) ?>" placeholder="student_name=hs,class_name=lop,subject=mon,correct=dung,total=tong,date=ngay">
    <span class="muted small">Dạng <code>tên_trong_template=nguồn</code>, nguồn: hs, lop, mon, dung, tong, ngay, vang. Để trống = gửi đúng tên nguồn.</span></label>
  <label>Endpoint <input name="zalo_endpoint" value="<?= h(setting('zalo_endpoint', '')) ?>" placeholder="https://business.openapi.zalo.me/message/template"></label>
  <h3>SMS qua cổng HTTP</h3>
  <p class="muted small">ARS gửi <code>POST {"phone":"09…","message":"…"}</code> (JSON) với header <code>Authorization: Bearer &lt;token&gt;</code>; mã 2xx = thành công. Với eSMS, SpeedSMS… dùng một webhook trung gian chuyển sang định dạng của nhà cung cấp.</p>
  <label>URL cổng SMS <input name="sms_url" value="<?= h(setting('sms_url', '')) ?>" placeholder="https://sms.example.vn/send"></label>
  <label>Token <input name="sms_token" autocomplete="off" value="<?= h($mask('sms_token')) ?>"></label>
  <label class="check"><input type="checkbox" name="clear_sms_token"> Xoá token</label>
  <label class="check"><input type="checkbox" name="sms_ascii" <?= setting('sms_ascii', '1') === '1' ? 'checked' : '' ?>> Gửi SMS không dấu (rẻ hơn, không bị cắt)</label>
  <h3>Nội dung &amp; tự động</h3>
  <label>Mẫu tin (SMS / nhật ký) <textarea name="notify_template" rows="2" placeholder="<?= h(NOTIFY_DEFAULT_TEMPLATE) ?>"><?= h(setting('notify_template', '')) ?></textarea>
    <span class="muted small">Biến: {hs} {lop} {mon} {dung} {tong} {ngay}. Học sinh vắng nhận mẫu “vắng buổi học”.</span></label>
  <label class="check"><input type="checkbox" name="notify_auto" <?= setting('notify_auto') === '1' ? 'checked' : '' ?>> Tự gửi khi server lớp học đồng bộ buổi đã công bố lên Cloud</label>
  <label class="check"><input type="checkbox" name="notify_test_mode" <?= setting('notify_test_mode') === '1' ? 'checked' : '' ?>> Chế độ thử: chỉ ghi nhật ký, không gửi ra ngoài</label>
  <button class="primary">Lưu</button>
</form></section>
<?php endif; ?>
<?php page_foot();
