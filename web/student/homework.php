<?php
require __DIR__.'/../lib/lms.php';$s=require_student();$id=(int)($_GET['id']??0);
if(!$id){student_head('Bài tập về nhà','homework.php');echo '<h1>Bài tập về nhà</h1>';foreach(qall('SELECT h.*, c.name AS class_name FROM homework h JOIN classes c ON c.id=h.class_id JOIN students st ON st.class_id=c.id WHERE st.student_code=? AND h.published=1 ORDER BY h.id DESC',[$s['code']]) as $r)echo '<article class="card"><a href="homework.php?id='.$r['id'].'">'.h($r['title']).'</a> · '.h($r['class_name']).'<p>Hạn nộp: '.h($r['due_at']??'không giới hạn').'</p></article>';student_foot();exit;}
$h=student_homework($s['code'],$id);
$sub=q1('SELECT * FROM homework_submissions WHERE homework_id=? AND student_code=?',[$id,$s['code']]);
$questions=qall('SELECT * FROM homework_questions WHERE homework_id=? ORDER BY ord',[$id]);
$stored=[];
if($_SERVER['REQUEST_METHOD']==='POST')try{
 db()->beginTransaction();
 // Serialize draft-save and final-submit. Never overwrite a submitted answer.
 q('INSERT IGNORE INTO homework_submissions(homework_id,student_code) VALUES(?,?)',[$id,$s['code']]);
 $sub=q1('SELECT * FROM homework_submissions WHERE homework_id=? AND student_code=? FOR UPDATE',[$id,$s['code']]);
 $expired=qv('SELECT due_at IS NOT NULL AND due_at<NOW() FROM homework WHERE id=?',[$id]);
 if($expired||$sub['submitted_at'])throw new RuntimeException('Bài đã nộp hoặc hết hạn; không thể thay đổi.');
 if(!$questions)throw new RuntimeException('Bài chưa có câu hỏi.');
 foreach($questions as $r){
  $key=(int)$r['id'];$ans=mb_substr(trim($_POST['answer'][$key]??''),0,10000);$score=null;
  if($r['kind']==='mcq'){$qst=q1('SELECT * FROM questions WHERE id=?',[$r['question_id']]);if($ans!==''&&!array_key_exists($ans,options_of($qst)))throw new RuntimeException('Đáp án không hợp lệ.');$score=$ans===$qst['correct']?(float)$r['max_score']:0;}
  $file=null;
  if(!empty($_FILES['attachment']['name'][$key])){$f=[];foreach(['name','type','tmp_name','error','size'] as $k)$f[$k]=$_FILES['attachment'][$k][$key];if(!in_array($r['kind'],['drawing','audio'],true))throw new RuntimeException('Câu này không nhận tệp.');$file=lms_upload($f,$r['kind']==='drawing'?LMS_IMAGE:LMS_AUDIO);$stored[]=$file['store'];}
  q('INSERT INTO homework_answers(submission_id,hw_question_id,answer,score,file_store,file_name,mime) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE answer=VALUES(answer),score=VALUES(score),file_store=COALESCE(VALUES(file_store),file_store),file_name=COALESCE(VALUES(file_name),file_name),mime=COALESCE(VALUES(mime),mime)',[$sub['id'],$key,$ans,$score,$file['store']??null,$file['name']??null,$file['mime']??null]);
 }
 if(($_POST['action']??'')==='submit')q('UPDATE homework_submissions SET submitted_at=NOW() WHERE id=?',[$sub['id']]);
 db()->commit();if(($_POST['action']??'')==='submit'){flash('🎉 Đã nộp bài! +20 XP');fx_once('celebrate');}else flash('Đã lưu bài làm.');redirect('homework.php?id='.$id);
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();foreach($stored as $f)@unlink(uploads_dir().'/'.$f);flash($e->getMessage(),'bad');}
$answers=$sub?array_column(qall('SELECT * FROM homework_answers WHERE submission_id=?',[$sub['id']]),null,'hw_question_id'):[];
$locked=$h['expired']||!empty($sub['submitted_at']);student_head($h['title'],'homework.php');
echo '<h1>'.h($h['title']).'</h1><p>'.nl2br(h($h['instructions'])).'</p><p>Hạn nộp: '.h($h['due_at']??'không giới hạn').'</p>';
if(!empty($sub['submitted_at']))echo '<div class="flash ok">Đã nộp · '.($sub['released']?'Kết quả đã công bố':'Chờ giáo viên chấm / công bố').'</div>';
if(!$locked&&$questions)echo '<div class="hw-progress" aria-live="polite"><div class="progress"><span style="width:0"></span></div><span class="hw-count">0/'.count($questions).' câu</span></div>';
echo '<form class="lms-homework" method="post" enctype="multipart/form-data"><fieldset '.($locked?'disabled':'').'>' .csrf_field();
foreach($questions as $r){$key=$r['id'];$a=$answers[$key]??[];
 echo '<article class="card hw-q"'.(!empty($a['file_store'])?' data-has-file="1"':'').'><h2>Câu '.$r['ord'].' · '.$r['max_score'].' điểm</h2><div class="qtext">'.nl2br(h($r['content'])).'</div>';
 if($r['kind']==='mcq'){echo question_media_html((int)$r['question_id'],'../',$id);foreach(options_of(q1('SELECT * FROM questions WHERE id=?',[$r['question_id']])) as $k=>$v)echo '<label class="hw-opt"><input type="radio" name="answer['.$key.']" value="'.$k.'" '.(($a['answer']??'')===$k?'checked':'').'><b>'.$k.'</b><span>'.h($v).'</span></label>';}
 else{echo '<label>Bài làm / ghi chú<textarea name="answer['.$key.']" maxlength="10000">'.h($a['answer']??'').'</textarea></label>';}
 if($r['kind']==='drawing')echo '<div class="capture" data-key="'.$key.'"><canvas data-src="'.h(!empty($a['file_store'])?'../lms_file.php?answer='.$a['id']:'').'" width="720" height="360" aria-label="Vẽ bài làm" style="border:1px solid #aaa;width:100%;max-width:720px;touch-action:none"></canvas><button type="button" class="clear">Xóa nét vẽ</button><button type="button" class="use-drawing">Đính kèm hình vẽ</button><span class="capture-status" role="status"></span><input type="file" name="attachment['.$key.']" accept="image/png,image/jpeg,image/webp"></div>';
 if($r['kind']==='audio')echo '<div class="capture" data-key="'.$key.'"><button type="button" class="record">Ghi âm</button><button type="button" class="stop-record" disabled>Dừng và đính kèm</button><span class="capture-status" role="status"></span><audio controls></audio><input type="file" name="attachment['.$key.']" accept="audio/*,.webm"></div>';
 echo '</article>';
}echo '</fieldset>';if(!$locked)echo '<button name="action" value="save">Lưu nháp</button> <button class="primary" name="action" value="submit" onclick="return confirm(\'Nộp bài? Sau đó không thể sửa.\')">Nộp bài</button>';echo '</form>';
foreach($questions as $r){$a=$answers[$r['id']]??[];if(!empty($a['file_store']))echo '<p>Tệp câu '.$r['ord'].': <a href="../lms_file.php?answer='.$a['id'].'" target="_blank" rel="noopener">Xem tệp đã lưu</a></p>';if(!empty($sub['released']))echo '<div class="card">Câu '.$r['ord'].': '.($a['score']!==null?h($a['score']).'/'.h($r['max_score']):'chưa chấm').'<p>'.nl2br(h($a['feedback']??'')).'</p></div>';}
echo '<script src="../assets/capture.js"></script>';student_foot();
