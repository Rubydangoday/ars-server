<?php
require __DIR__.'/lib/lms.php';
$t=require_login(); $id=(int)($_GET['id']??$_POST['id']??0);$h=$id?own_homework($id):null;
if($_SERVER['REQUEST_METHOD']==='POST')try{
 $act=$_POST['action']??'';
 if($act==='create'){$c=own_class((int)$_POST['class_id']);q('INSERT INTO homework(class_id,title,instructions,due_at) VALUES(?,?,?,?)',[$c['id'],required_text('title'),mb_substr($_POST['instructions']??'',0,10000),due_value($_POST['due_at']??'')]);redirect('homework.php?id='.db()->lastInsertId());}
 if(!$h)throw new RuntimeException('Chọn bài tập.');
 if($act==='publish'){q('UPDATE homework SET published=? WHERE id=?',[(int)!$h['published'],$id]);}
 if($act==='add'){
  if(qv('SELECT COUNT(*) FROM homework_submissions WHERE homework_id=?',[$id]))throw new RuntimeException('Đã có bài làm: tạo bài tập mới để đổi đề.');
  $kind=$_POST['kind']??'';if(!in_array($kind,['mcq','text','drawing','audio'],true))throw new RuntimeException('Loại câu không hợp lệ.');
  $qid=null;$content=trim($_POST['content']??'');$topic=mb_substr($_POST['topic']??'',0,100);
  if($kind==='mcq'){$r=q1('SELECT q.* FROM questions q JOIN question_sets s ON s.id=q.set_id WHERE q.id=? AND s.teacher_id=?',[(int)$_POST['question_id'],$t['id']]);if(!$r||!$r['correct'])throw new RuntimeException('Chọn câu trắc nghiệm có đáp án của bạn.');$qid=$r['id'];$content=$r['content'];$topic=$r['topic'];}
  if(!$content)throw new RuntimeException('Nhập nội dung câu hỏi.');$max=score_value($_POST['max_score']??1,100);if($max<=0)throw new RuntimeException('Điểm tối đa phải lớn hơn 0.');
  $ord=(int)qv('SELECT COALESCE(MAX(ord),0)+1 FROM homework_questions WHERE homework_id=?',[$id]);
  q('INSERT INTO homework_questions(homework_id,ord,question_id,kind,content,topic,rubric,max_score) VALUES(?,?,?,?,?,?,?,?)',[$id,$ord,$qid,$kind,mb_substr($content,0,20000),$topic,mb_substr($_POST['rubric']??'',0,10000),$max]);
 }
 flash('Đã lưu.');redirect('homework.php?id='.$id);
}catch(Throwable $e){flash($e->getMessage(),'bad');}
page_head('Bài tập về nhà','homework.php');
echo '<h1>Bài tập về nhà</h1><p><a href="homework.php">Danh sách</a></p>';
if(!$h){
 $classes=qall('SELECT * FROM classes WHERE teacher_id=?',[$t['id']]);
 echo '<form method="post" class="card stack">'.csrf_field().'<input type="hidden" name="action" value="create"><h2>Giao bài mới</h2><label>Lớp<select name="class_id">';foreach($classes as $c)echo '<option value="'.$c['id'].'">'.h($c['name']).'</option>';
 echo '</select></label><label>Tên bài<input name="title" required maxlength="200"></label><label>Hướng dẫn<textarea name="instructions"></textarea></label><label>Hạn nộp<input type="datetime-local" name="due_at"></label><button class="primary">Tạo bản nháp</button></form>';
 foreach(qall('SELECT h.*,c.name AS class_name FROM homework h JOIN classes c ON c.id=h.class_id WHERE c.teacher_id=? ORDER BY h.id DESC',[$t['id']]) as $r)echo '<p><a href="homework.php?id='.$r['id'].'">'.h($r['title']).'</a> · '.h($r['class_name']).' · '.($r['published']?'đã giao':'nháp').'</p>';
}else{
 echo '<h2>'.h($h['title']).' · '.h($h['class_name']).'</h2><form method="post">'.csrf_field().'<input type="hidden" name="action" value="publish"><button>'.($h['published']?'Ẩn bài':'Công bố cho học sinh').'</button> <a class="btn" href="homework_grade.php?id='.$id.'">Chấm / công bố kết quả</a></form>';
 foreach(qall('SELECT * FROM homework_questions WHERE homework_id=? ORDER BY ord',[$id]) as $r)echo '<article class="card"><b>Câu '.$r['ord'].' · '.h(LMS_KINDS[$r['kind']]).' · '.$r['max_score'].' điểm</b><p>'.nl2br(h($r['content'])).'</p></article>';
 echo '<form method="post" class="card stack">'.csrf_field().'<input type="hidden" name="action" value="add"><h2>Thêm câu</h2><label>Loại<select name="kind"><option value="mcq">Trắc nghiệm từ ngân hàng</option><option value="text">Tự luận ngắn</option><option value="drawing">Vẽ tay / ảnh bài làm</option><option value="audio">Trả lời bằng ghi âm</option></select></label><label>Câu trắc nghiệm<select name="question_id"><option value="">— chọn nếu trắc nghiệm —</option>';
 foreach(qall('SELECT q.id,q.content,s.title FROM questions q JOIN question_sets s ON s.id=q.set_id WHERE s.teacher_id=? AND q.correct IS NOT NULL ORDER BY q.id DESC',[$t['id']]) as $r)echo '<option value="'.$r['id'].'">'.h($r['title'].' · '.mb_substr($r['content'],0,100)).'</option>';
 echo '</select></label><label>Nội dung (tự luận/vẽ/ghi âm)<textarea name="content"></textarea></label><label>Chủ đề<input name="topic" maxlength="100"></label><label>Đáp án mẫu / tiêu chí chấm<textarea name="rubric"></textarea></label><label>Điểm tối đa<input type="number" name="max_score" value="1" min="0.01" max="100" step="0.01"></label><button>Thêm câu</button></form>';
}page_foot();
