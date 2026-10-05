<?php
require __DIR__.'/../lib/lms.php';require __DIR__.'/../lib/oauth.php';
if(isset($_GET['logout'])){unset($_SESSION['parent']);redirect('index.php');}
if($_SERVER['REQUEST_METHOD']==='POST')try{
 $act=$_POST['action']??'';$user=trim($_POST['username']??'');$pass=$_POST['password']??'';
 if(!preg_match('/^[A-Za-z0-9_.-]{3,50}$/',$user))throw new RuntimeException('Tên đăng nhập: 3–50 ký tự chữ/số.');
 if($act==='login'){$p=q1('SELECT * FROM parents WHERE username=?',[$user]);if(!$p||!password_verify($pass,$p['password_hash'])){usleep(400000);throw new RuntimeException('Thông tin đăng nhập không đúng.');}session_regenerate_id(true);$_SESSION['parent']=['id'=>$p['id'],'full_name'=>$p['full_name']];redirect('home.php');}
 if($act==='register'){
  if(strlen($pass)<10)throw new RuntimeException('Mật khẩu tối thiểu 10 ký tự.');$token=trim($_POST['invite']??'');if(!preg_match('/^[a-f0-9]{32}$/',$token))throw new RuntimeException('Mã mời không đúng.');
  db()->beginTransaction();$i=q1('SELECT * FROM parent_invites WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[hash('sha256',$token)]);
  if(!$i||!qv('SELECT 1 FROM students WHERE class_id=? AND student_code=?',[$i['class_id'],$i['student_code']]))throw new RuntimeException('Mã mời đã hết hạn hoặc đã dùng.');
  q('INSERT INTO parents(username,full_name,password_hash) VALUES(?,?,?)',[$user,required_text('full_name',100),password_hash($pass,PASSWORD_DEFAULT)]);$pid=(int)db()->lastInsertId();
  q('INSERT INTO parent_links(parent_id,student_code,class_id) VALUES(?,?,?)',[$pid,$i['student_code'],$i['class_id']]);q('UPDATE parent_invites SET used_at=NOW() WHERE id=?',[$i['id']]);db()->commit();flash('Đã tạo tài khoản. Hãy đăng nhập.');redirect('index.php');
 }throw new RuntimeException('Lệnh không hợp lệ.');
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();flash($e instanceof PDOException?'Tên đăng nhập đã tồn tại hoặc chưa thể tạo tài khoản.':$e->getMessage(),'bad');}
page_head('Phụ huynh','',true);echo '<h1>Cổng phụ huynh</h1><form method="post" class="card stack">'.csrf_field().'<h2>Đăng nhập</h2><label>Tên đăng nhập<input name="username" autocomplete="username" required></label><label>Mật khẩu<input type="password" name="password" autocomplete="current-password" required></label><button name="action" value="login">Đăng nhập</button></form>'.oauth_buttons('parent','login','../').'<form method="post" class="card stack">'.csrf_field().'<h2>Đăng ký bằng mã mời giáo viên cấp</h2><label>Mã mời<input name="invite" value="'.h($_GET['invite']??'').'" required></label><label>Họ tên<input name="full_name" required></label><label>Tên đăng nhập<input name="username" required></label><label>Mật khẩu (10 ký tự trở lên)<input type="password" name="password" minlength="10" required></label><button name="action" value="register">Tạo tài khoản</button></form>';
if($on=oauth_enabled()){echo '<form method="post" action="../oauth.php" class="card stack">'.csrf_field().'<input type="hidden" name="action" value="start"><input type="hidden" name="for" value="parent"><input type="hidden" name="mode" value="register"><h2>Hoặc đăng ký nhanh</h2><label>Mã mời<input name="invite" value="'.h($_GET['invite']??'').'" required></label><div class="oauth">';foreach($on as $k=>$v)echo '<button class="oauth-btn oauth-'.$k.'" name="provider" value="'.$k.'"><span class="oauth-ico">'.($k==='google'?'G':'f').'</span>Đăng ký với '.h($v).'</button>';echo '</div><span class="muted small">Không cần nhớ mật khẩu: lần sau bấm “Đăng nhập với '.h(implode(' / ',$on)).'”.</span></form>';}
page_foot();
