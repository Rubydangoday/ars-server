<?php
require __DIR__.'/../lib/lms.php';require __DIR__.'/../lib/oauth.php';require __DIR__.'/../lib/auth_ui.php';
if(isset($_GET['logout'])){unset($_SESSION['parent']);redirect('index.php');}
$pending=oauth_pending('parent'); // đến từ "Đăng nhập với Google": chỉ cần mã mời, không cần tên đăng nhập / mật khẩu
if($_SERVER['REQUEST_METHOD']==='POST')try{
 $act=$_POST['action']??'';$user=trim($_POST['username']??'');$pass=$_POST['password']??'';
 if($act==='register'&&$pending){$user=oauth_username_suggest($pending['email']??$pending['name'],'parents');$pass=bin2hex(random_bytes(24));}
 if(!preg_match('/^[A-Za-z0-9_.-]{3,50}$/',$user))throw new RuntimeException('Tên đăng nhập: 3–50 ký tự chữ/số.');
 if($act==='login'){$p=q1('SELECT * FROM parents WHERE username=?',[$user]);if(!$p||!password_verify($pass,$p['password_hash'])){usleep(400000);throw new RuntimeException('Thông tin đăng nhập không đúng.');}session_regenerate_id(true);$_SESSION['parent']=['id'=>$p['id'],'full_name'=>$p['full_name']];redirect('home.php');}
 if($act==='register'){
  if(strlen($pass)<10)throw new RuntimeException('Mật khẩu tối thiểu 10 ký tự.');$token=trim($_POST['invite']??'');if(!preg_match('/^[a-f0-9]{32}$/',$token))throw new RuntimeException('Mã mời không đúng.');
  db()->beginTransaction();$i=q1('SELECT * FROM parent_invites WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[hash('sha256',$token)]);
  if(!$i||!qv('SELECT 1 FROM students WHERE class_id=? AND student_code=?',[$i['class_id'],$i['student_code']]))throw new RuntimeException('Mã mời đã hết hạn hoặc đã dùng.');
  q('INSERT INTO parents(username,full_name,password_hash) VALUES(?,?,?)',[$user,required_text('full_name',100),password_hash($pass,PASSWORD_DEFAULT)]);$pid=(int)db()->lastInsertId();
  q('INSERT INTO parent_links(parent_id,student_code,class_id) VALUES(?,?,?)',[$pid,$i['student_code'],$i['class_id']]);q('UPDATE parent_invites SET used_at=NOW() WHERE id=?',[$i['id']]);db()->commit();
  if($pending&&($n=oauth_attach_pending('parent',(string)$pid))){session_regenerate_id(true);$_SESSION['parent']=['id'=>$pid,'full_name'=>trim($_POST['full_name'])];flash('Đã tạo tài khoản. Lần sau bấm “Đăng nhập với '.$n.'”.');redirect('home.php');}
  flash('Đã tạo tài khoản. Hãy đăng nhập.');redirect('index.php');
 }throw new RuntimeException('Lệnh không hợp lệ.');
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();flash($e instanceof PDOException?'Tên đăng nhập đã tồn tại hoặc chưa thể tạo tài khoản.':$e->getMessage(),'bad');}
$tab=($_GET['tab']??'')==='register'||!empty($_GET['invite'])||($_POST['action']??'')==='register'||$pending?'register':'login';
auth_open('parent',$tab==='login'?'Chào phụ huynh 👋':'Tạo tài khoản phụ huynh',$tab==='login'?'Xem con học thế nào trên lớp, bài tập và nhận xét của thầy cô.':'Nhập <b>mã mời</b> thầy cô gửi riêng cho bạn để liên kết đúng học sinh.','../');
echo '<nav class="auth-tabs"><a href="index.php"'.($tab==='login'?' class="on"':'').'>Đăng nhập</a><a href="index.php?tab=register"'.($tab==='register'?' class="on"':'').'>Đăng ký bằng mã mời</a></nav>';
if($tab==='login'){
 echo oauth_buttons('parent','login','../','Đăng nhập với',[],true).auth_divider();
 echo '<form method="post" class="stack">'.csrf_field().'<label>Tên đăng nhập<input name="username" autocomplete="username" required placeholder="Tên đăng nhập của bạn"></label><label>Mật khẩu<input type="password" name="password" autocomplete="current-password" required placeholder="••••••••"></label><button class="primary" name="action" value="login">Đăng nhập</button></form>';
 echo '<p class="auth-links"><a href="index.php?tab=register">Chưa có tài khoản? Đăng ký</a><span class="muted small">Quên mật khẩu: nhờ thầy cô cấp mã mời mới</span></p>';
}else{
 // Một form: nút "Tạo tài khoản" đứng trước trong DOM (phím Enter), nút Google/Facebook gửi sang ../oauth.php bằng formaction.
 if($pending)echo oauth_pending_banner($pending,'../');   // ngoài form đăng ký: form "Huỷ" không được lồng
 echo '<form method="post" class="stack reg-form">'.csrf_field().'<input type="hidden" name="for" value="parent"><input type="hidden" name="mode" value="register">'
   .'<label style="order:0">Mã mời<input name="invite" value="'.h($_POST['invite']??$_GET['invite']??'').'" required autocomplete="off" placeholder="32 ký tự thầy cô gửi"></label>'
   .'<label style="order:3">Họ tên<input name="full_name" placeholder="Nguyễn Thị B" value="'.h($_POST['full_name']??($pending['name']??'')).'"></label>'
   .($pending?'':'<label style="order:3">Tên đăng nhập<input name="username" autocomplete="username" placeholder="3–50 ký tự, không dấu" value="'.h($_POST['username']??'').'"></label><label style="order:3">Mật khẩu (10 ký tự trở lên)<input type="password" name="password" minlength="10" autocomplete="new-password"></label>')
   .'<button class="primary" name="action" value="register" style="order:4">Tạo tài khoản</button>'.($pending?'<div style="display:none">':'<div class="oauth" style="order:1;margin:0">');
 foreach(OAUTH_PROVIDERS as $k=>$v){$why=oauth_status($k);echo '<button class="oauth-btn oauth-'.$k.'" name="provider" value="'.$k.'" formaction="../oauth.php" formnovalidate'.($why?' disabled title="'.h($why).'"':'').'>'.oauth_logo($k).'<span>Đăng ký với '.h($v).'</span></button>';}
 $notes=[];foreach(OAUTH_PROVIDERS as $k=>$v)if($s=oauth_status($k))$notes[$s][]=$v;
 echo ($notes?'<p class="oauth-note">ⓘ '.implode('<br>ⓘ ',array_map(fn($s,$who)=>h(implode(', ',$who).': '.$s),array_keys($notes),$notes)).'</p>':'').'</div><div class="auth-or" style="order:2;margin:2px 0"><span>hoặc tạo tài khoản bằng mật khẩu</span></div></form>';
 echo '<p class="auth-links"><a href="index.php">Đã có tài khoản? Đăng nhập</a></p>';
}
auth_close();
