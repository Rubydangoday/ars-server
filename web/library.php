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
page_head('Kho đề dùng chung','library.php');
$VIS=['private'=>['🔒','Riêng tư',''],'school'=>['🏫','Trong trường','info'],'public'=>['🌐','Công khai','ok']];
$sets=qall('SELECT s.*,t.full_name,(SELECT COUNT(*) FROM questions q WHERE q.set_id=s.id) nq FROM question_sets s JOIN teachers t ON t.id=s.teacher_id WHERE s.teacher_id=? OR s.visibility="public" OR (s.visibility="school" AND t.school_id=? AND t.school_id IS NOT NULL) ORDER BY s.id DESC',[$t['id'],$school]);
$tab=$_GET['tab']??'all';
echo '<div class="page-head"><div><h1>🗂 '.te('Kho đề dùng chung').'</h1><p class="muted">'.te('Kho trường chỉ hiện với giáo viên được quản trị gán cùng trường. Kho công khai là bộ đề cho giáo viên đăng nhập; không chia sẻ dữ liệu học sinh.').'</p></div></div>';
$cnt=['all'=>count($sets),'mine'=>count(array_filter($sets,fn($s)=>(int)$s['teacher_id']===(int)$t['id'])),'shared'=>count(array_filter($sets,fn($s)=>(int)$s['teacher_id']!==(int)$t['id']))];
echo '<div class="card-head lib-bar"><nav class="pill-tabs mini">';foreach(['all'=>'Tất cả','mine'=>'Của tôi','shared'=>'Được chia sẻ'] as $k=>$v)echo '<a href="library.php?tab='.$k.'" class="'.($tab===$k?'on':'').'">'.te($v).' <small>'.$cnt[$k].'</small></a>';
echo '</nav><input type="search" class="tbl-filter" placeholder="🔎 '.te('Tìm bộ đề…').'" data-filter-cards=".lib-grid"></div>';
$sets=array_filter($sets,fn($s)=>$tab==='mine'?(int)$s['teacher_id']===(int)$t['id']:($tab==='shared'?(int)$s['teacher_id']!==(int)$t['id']:true));
if(!$sets)echo '<div class="card">'.ui_empty('🗂',t('Chưa có bộ đề nào'),t('Tạo bộ câu hỏi ở tab Câu hỏi, rồi chia sẻ trong trường hoặc công khai.'),'<a class="btn primary" href="compose.php">'.te('Soạn câu hỏi').'</a>').'</div>';
echo '<div class="lib-grid">';
foreach($sets as $s){$mine=(int)$s['teacher_id']===(int)$t['id'];[$vi,$vn,$vt]=$VIS[$s['visibility']]??$VIS['private'];
 echo '<article class="lib-card"><div class="lib-top"><span class="lib-ico">'.($s['purpose']==='practice'?'📝':'📡').'</span><span class="tag '.$vt.'">'.$vi.' '.te($vn).'</span></div>'
   .'<h3>'.h($s['title']).'</h3><p class="muted small">'.te('{n} câu',['n'=>(int)$s['nq']]).' · '.($mine?te('của bạn'):h($s['full_name'])).'</p>'
   .'<form method="post" class="lib-act">'.csrf_field().'<input type="hidden" name="set_id" value="'.$s['id'].'">';
 if($mine)echo '<div class="lib-share"><select name="visibility">'.implode('',array_map(fn($v)=>'<option '.($s['visibility']===$v?'selected':'').' value="'.$v.'">'.$VIS[$v][0].' '.te($VIS[$v][1]).'</option>',['private','school','public'])).'</select><button class="small" name="action" value="share">'.te('Cập nhật chia sẻ').'</button></div>'
   .'<div class="row" style="margin:0"><a class="btn small" href="set.php?id='.$s['id'].'">'.te('Mở').'</a><a class="btn small" href="variants.php?id='.$s['id'].'">'.te('Tạo mã đề').'</a>';
 else echo '<div class="row" style="margin:0">';
 echo '<button class="small'.($mine?'':' primary').'" name="action" value="copy">'.te('Sao chép về kho của tôi').'</button></div></form></article>';}
echo '</div><script>document.querySelector("[data-filter-cards]")?.addEventListener("input",e=>{const q=e.target.value.toLowerCase();document.querySelectorAll(".lib-card").forEach(c=>{c.hidden=q&&!c.textContent.toLowerCase().includes(q)})})</script>';
page_foot();
