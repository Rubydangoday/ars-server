<?php
// Đồng bộ Hybrid. Cùng một trang cho cả hai vai:
//  • Server lớp học (offline): nhập địa chỉ Cloud + mã đồng bộ, bấm "Sync to Cloud".
//  • Server Cloud: tạo mã đồng bộ để server lớp học đẩy dữ liệu vào tài khoản của giáo viên.
require __DIR__ . '/lib/sync.php';
$t = require_login();
$tid = (int)$t['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'config') {
            $url = trim($_POST['cloud_url'] ?? '');
            $tok = trim($_POST['cloud_token'] ?? '');
            if ($url === '') { q('UPDATE teachers SET cloud_url=NULL, cloud_token=NULL WHERE id=?', [$tid]); flash('Đã tắt đồng bộ Cloud.'); }
            else {
                $url = normalize_cloud_url($url);
                if ($tok !== '' && !preg_match('/^•+/u', $tok)) {
                    if (!preg_match('/^[A-Za-z0-9_-]{20,80}$/', $tok)) throw new RuntimeException('Mã đồng bộ không đúng dạng (bắt đầu bằng ars_).');
                    q('UPDATE teachers SET cloud_token=? WHERE id=?', [$tok, $tid]);
                }
                q('UPDATE teachers SET cloud_url=? WHERE id=?', [$url, $tid]);
                $row = q1('SELECT cloud_token FROM teachers WHERE id=?', [$tid]);
                if (!$row['cloud_token']) throw new RuntimeException('Đã lưu địa chỉ. Dán mã đồng bộ tạo trên Cloud.');
                $j = sync_http($url, $row['cloud_token'], ['ping' => true]);
                flash('Kết nối Cloud thành công — tài khoản trên Cloud: ' . ($j['teacher'] ?? '?') . '.');
            }
        } elseif ($act === 'auto') {
            q('UPDATE teachers SET cloud_auto=? WHERE id=?', [(int)!empty($_POST['on']), $tid]);
            flash(!empty($_POST['on']) ? 'Đã bật tự đồng bộ: máy chủ tự gửi buổi mới lên Cloud mỗi 3 phút khi có Internet.' : 'Đã tắt tự đồng bộ. Nhớ bấm Sync to Cloud sau mỗi buổi.');
        } elseif ($act === 'push') {
            flash(sync_push($tid, !empty($_POST['all'])));
        } elseif ($act === 'new_token') {
            $_SESSION['new_sync_token'] = new_sync_token($tid, trim($_POST['label'] ?? ''));
        } elseif ($act === 'revoke') {
            q('UPDATE sync_tokens SET revoked=1 WHERE id=? AND teacher_id=?', [(int)$_POST['token_id'], $tid]);
            flash('Đã thu hồi mã. Server lớp học dùng mã này sẽ không đồng bộ được nữa.');
        } else throw new RuntimeException('Lệnh không hợp lệ.');
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('sync.php');
}

$me = q1('SELECT cloud_url, cloud_token, cloud_auto FROM teachers WHERE id=?', [$tid]);
$auto_fail = json_decode(setting('sync_auto_fail_' . $tid) ?? '', true);
$pending = (int)qv('SELECT COUNT(*) FROM sessions s JOIN classes c ON c.id=s.class_id WHERE c.teacher_id=? AND s.status="ended" AND s.cloud_synced_at IS NULL', [$tid]);
$last_ok = qv('SELECT MAX(created_at) FROM sync_log WHERE teacher_id=? AND direction="push" AND ok=1', [$tid]);
$tokens = qall('SELECT * FROM sync_tokens WHERE teacher_id=? ORDER BY id DESC', [$tid]);
$log = qall('SELECT * FROM sync_log WHERE teacher_id=? ORDER BY id DESC LIMIT 15', [$tid]);

page_head('Đồng bộ Cloud', 'sync.php');
?>
<div class="page-head"><div><h1>☁️ Đồng bộ Cloud</h1>
  <p class="muted">Trên lớp, server này chạy offline để nhận Remote. Khi có Internet, máy chủ <b>tự động</b> đẩy lớp, học sinh và điểm bấm Remote lên server Cloud (hoặc bấm <b>Sync to Cloud</b> để gửi ngay) — phụ huynh và học sinh ở nhà mở web Cloud là xem được ngay.</p></div>
  <div><a class="btn" href="notify.php">Thông báo Zalo / SMS →</a></div></div>

<section class="card">
  <h2>☁️ Đẩy dữ liệu lên Cloud <span class="muted small">(dùng trên server lớp học)</span></h2>
  <?php if ($me['cloud_url'] && $me['cloud_token']): ?>
    <div class="kpis"><div><b><?= $pending ?></b><span>buổi chưa đồng bộ</span></div><div><b><?= h($last_ok ? date('d/m H:i', strtotime($last_ok)) : '—') ?></b><span>lần đồng bộ gần nhất</span></div></div>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="push">
      <button class="primary" style="font-size:17px;padding:10px 22px">⬆ Sync to Cloud</button>
      <label class="check"><input type="checkbox" name="all" value="1"> Gửi lại toàn bộ buổi (sau khi khôi phục Cloud)</label></form>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="auto"><input type="hidden" name="on" value="<?= $me['cloud_auto'] ? 0 : 1 ?>">
      <?php if ($me['cloud_auto']): ?><span class="tag live">🔄 Tự động: BẬT</span>
        <span class="muted small">Khi máy chủ bật và có Internet, buổi mới (kết thúc buổi, công bố điểm, sửa điểm danh) được gửi lên Cloud trong vòng 3 phút — không cần bấm nút.<?php if ($auto_fail): ?> <b>Lần thử gần nhất lỗi (chưa có Internet?) — máy chủ sẽ tự thử lại.</b><?php endif; ?></span>
        <button class="small">Tắt</button>
      <?php else: ?><span class="tag off">Tự động: TẮT</span><span class="muted small">Phải bấm Sync to Cloud sau mỗi buổi.</span><button class="small primary">Bật tự đồng bộ</button><?php endif; ?></form>
    <p class="muted small">Gửi: lớp, danh sách học sinh, bộ câu hỏi đã dùng, điểm danh và đáp án cuối của từng học sinh (buổi đã kết thúc). Không gửi bài giảng, ghi âm hay mật khẩu. Gửi lại nhiều lần không nhân đôi dữ liệu.</p>
  <?php endif; ?>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="config">
    <label>Địa chỉ Cloud <input name="cloud_url" value="<?= h($me['cloud_url'] ?? '') ?>" placeholder="https://ars.truong.edu.vn"></label>
    <label>Mã đồng bộ (tạo ở trang này trên Cloud) <input name="cloud_token" autocomplete="off" value="<?= $me['cloud_token'] ? str_repeat('•', 8) . h(substr($me['cloud_token'], -4)) : '' ?>" placeholder="ars_…"></label>
    <button>Lưu &amp; kiểm tra kết nối</button>
    <?php if (!$me['cloud_url']): ?><span class="muted small">Chỉ cần nhập một lần: sau đó máy chủ <b>tự đồng bộ</b> mỗi khi bật và có Internet.</span><?php endif; ?>
    <span class="muted small">Để trống địa chỉ để tắt đồng bộ. Nên dùng https:// khi Cloud ở trên Internet.</span></form>
</section>

<section class="card">
  <h2>🔑 Nhận dữ liệu từ server lớp học <span class="muted small">(dùng trên server Cloud)</span></h2>
  <?php if (!empty($_SESSION['new_sync_token'])): ?>
    <div class="flash warn">Mã đồng bộ mới — chép vào server lớp học ngay, mã chỉ hiện một lần:<br><?= link_box($_SESSION['new_sync_token'], '', false) ?></div>
  <?php unset($_SESSION['new_sync_token']); endif; ?>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="new_token">
    <label>Tên máy <input name="label" maxlength="100" placeholder="Máy phòng 201"></label><button>Tạo mã đồng bộ</button></form>
  <?php if ($tokens): ?><table class="grid compact"><thead><tr><th>Tên máy</th><th>Tạo lúc</th><th>Dùng gần nhất</th><th></th></tr></thead><tbody>
    <?php foreach ($tokens as $k): ?><tr><td><?= h($k['label']) ?></td><td class="small"><?= h($k['created_at']) ?></td><td class="small"><?= h($k['last_used_at'] ?? '—') ?></td>
      <td class="right"><?php if ($k['revoked']): ?><span class="tag off">đã thu hồi</span><?php else: ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="token_id" value="<?= (int)$k['id'] ?>"><button class="small" onclick="return confirm('Thu hồi mã này?')">Thu hồi</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
  <p class="muted small">Dữ liệu nhận được vào đúng tài khoản giáo viên này trên Cloud. Lớp từ server lớp học là bản sao: sửa danh sách học sinh ở server lớp học rồi đồng bộ lại. Tạo mã mời phụ huynh, giao bài tập về nhà trên Cloud.</p>
</section>

<section class="card"><h2>Nhật ký đồng bộ</h2>
  <?php if (!$log): ?><p class="muted">Chưa có.</p><?php endif; ?>
  <?php foreach ($log as $l): ?><p class="small"><?= $l['ok'] ? '✅' : '⚠️' ?> <?= h($l['created_at']) ?> · <?= $l['direction'] === 'push' ? 'gửi' : 'nhận' ?> · <?= h($l['message']) ?></p><?php endforeach; ?>
</section>
<?php page_foot();
