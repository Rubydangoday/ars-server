<?php
// Đổi mật khẩu phụ huynh. Bắt buộc ở lần đăng nhập đầu với mật khẩu giáo viên cấp (parents.must_change_pw = 1):
// chưa đổi thì mọi trang phụ huynh đều chuyển về đây (require_parent). Đổi tự nguyện từ tab Tài khoản: cần mật khẩu hiện tại.
require __DIR__.'/../lib/lms.php';require __DIR__.'/../lib/auth_ui.php';
$s=$_SESSION['parent']??null;
if(!$s)redirect('index.php');
$forced=!empty($s['must_change']);
if($_SERVER['REQUEST_METHOD']==='POST')try{
 $p=q1('SELECT * FROM parents WHERE id=?',[(int)$s['id']]);
 if(!$p){unset($_SESSION['parent']);redirect('index.php');}
 $new=(string)($_POST['new']??'');
 if(!$forced&&!password_verify((string)($_POST['current']??''),$p['password_hash'])){usleep(400000);throw new RuntimeException(t('Mật khẩu hiện tại không đúng.'));}
 if(mb_strlen($new)<8||!preg_match('/\pL/u',$new)||!preg_match('/\d/',$new))throw new RuntimeException(t('Mật khẩu mới tối thiểu 8 ký tự, có cả chữ và số.'));
 if($new!==(string)($_POST['confirm']??''))throw new RuntimeException(t('Hai lần nhập mật khẩu mới không khớp.'));
 if($new===$p['username']||password_verify($new,$p['password_hash']))throw new RuntimeException(t('Mật khẩu mới phải khác mật khẩu cũ và khác số điện thoại.'));
 q('UPDATE parents SET password_hash=?, must_change_pw=0 WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$p['id']]);
 session_regenerate_id(true);unset($_SESSION['parent']['must_change']);
 flash(t('Đã đổi mật khẩu. Lần sau đăng nhập bằng mật khẩu mới.'));redirect('home.php');
}catch(Throwable $e){flash($e->getMessage(),'bad');}
auth_open('parent',$forced?'Đổi mật khẩu lần đầu':'Đổi mật khẩu',$forced?'Bạn đang dùng mật khẩu thầy cô cấp. Hãy đặt mật khẩu riêng để bảo vệ thông tin học tập của con.':'Nhập mật khẩu hiện tại và mật khẩu mới.','../');
echo '<form method="post" class="stack">'.csrf_field()
 .'<input type="hidden" name="username" autocomplete="username" value="'.h((string)qv('SELECT username FROM parents WHERE id=?',[(int)$s['id']])).'">'
 .($forced?'':'<label>'.te('Mật khẩu hiện tại').'<input type="password" name="current" autocomplete="current-password" required></label>')
 .'<label>'.te('Mật khẩu mới (8 ký tự trở lên, có chữ và số)').'<input type="password" name="new" minlength="8" autocomplete="new-password" required></label>'
 .'<label>'.te('Nhập lại mật khẩu mới').'<input type="password" name="confirm" minlength="8" autocomplete="new-password" required></label>'
 .'<button class="primary">'.te('Lưu mật khẩu mới').'</button></form>';
echo '<p class="auth-links">'.($forced?'':'<a href="home.php?tab=account">'.te('← Quay lại').'</a>').'<a href="index.php?logout=1">'.te('Đăng xuất').'</a></p>';
auth_close();
