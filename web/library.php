<?php
require __DIR__.'/lib/bootstrap.php';require __DIR__.'/lib/qbank.php';require __DIR__.'/lib/variants.php';$t=require_login();
$school=qv('SELECT school_id FROM teachers WHERE id=?',[$t['id']]);
if($_SERVER['REQUEST_METHOD']==='POST')try{
 $act=$_POST['action']??'';
 if($act==='share'){$s=own_set((int)$_POST['set_id']);$vis=$_POST['visibility']??'';if(!in_array($vis,['private','school','public'],true)||($vis==='school'&&!$school))throw new RuntimeException('Tài khoản chưa được quản trị gán trường.');q('UPDATE question_sets SET visibility=? WHERE id=?',[$vis,$s['id']]);}
 elseif($act==='copy'){
 $src=q1('SELECT s.* FROM question_sets s JOIN teachers t ON t.id=s.teacher_id WHERE s.id=? AND (s.teacher_id=? OR s.visibility="public" OR (s.visibility="school" AND t.school_id=? AND t.school_id IS NOT NULL))',[(int)$_POST['set_id'],$t['id'],$school]);if(!$src)throw new RuntimeException('Không có quyền sao chép.');
 clone_set($src,$t['id'],required_library_title($_POST['title']??($src['title'].' (bản sao)')),false,false,null);
 }else throw new RuntimeException('Lệnh không hợp lệ.');flash('Đã lưu.');redirect('library.php');
}catch(Throwable $e){flash($e->getMessage(),'bad');}
page_head('Kho đề dùng chung','library.php');echo '<h1>Kho đề dùng chung</h1><p>Kho trường chỉ hiện với giáo viên được quản trị gán cùng trường. Kho công khai là bộ đề cho giáo viên đăng nhập; không chia sẻ dữ liệu học sinh.</p>';
foreach(qall('SELECT s.*,t.full_name FROM question_sets s JOIN teachers t ON t.id=s.teacher_id WHERE s.teacher_id=? OR s.visibility="public" OR (s.visibility="school" AND t.school_id=? AND t.school_id IS NOT NULL) ORDER BY s.id DESC',[$t['id'],$school]) as $s){
 echo '<section class="card"><h2>'.h($s['title']).'</h2><p>'.h($s['full_name']).' · '.h($s['visibility']).'</p><form method="post">'.csrf_field().'<input type="hidden" name="set_id" value="'.$s['id'].'">';if((int)$s['teacher_id']===(int)$t['id'])echo '<select name="visibility">'.implode('',array_map(fn($v)=>'<option '.($s['visibility']===$v?'selected':'').' value="'.$v.'">'.$v.'</option>',['private','school','public'])).'</select><button name="action" value="share">Cập nhật chia sẻ</button> <a href="variants.php?id='.$s['id'].'">Tạo mã đề</a> ';echo '<button name="action" value="copy">Sao chép về kho của tôi</button></form></section>';
}page_foot();
