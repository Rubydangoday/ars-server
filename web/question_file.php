<?php
require __DIR__.'/lib/lms.php';$m=q1('SELECT m.*,q.set_id,s.teacher_id FROM question_media m JOIN questions q ON q.id=m.question_id JOIN question_sets s ON s.id=q.set_id WHERE m.id=?',[(int)($_GET['id']??0)]);$ok=false;
if($m&&me()){require_login();$ok=(int)$m['teacher_id']===(int)me()['id'];}
if($m&&stu()){
 $ok=$ok||(bool)qv('SELECT 1 FROM practice_items pi JOIN practice_attempts pa ON pa.id=pi.attempt_id WHERE pa.student_code=? AND pi.question_id=?',[stu()['code'],$m['question_id']]);
 $ok=$ok||(bool)qv('SELECT 1 FROM homework h JOIN students st ON st.class_id=h.class_id JOIN homework_questions hq ON hq.homework_id=h.id WHERE h.published=1 AND st.student_code=? AND hq.question_id=?',[stu()['code'],$m['question_id']]);
 foreach(my_classes(stu()['code']) as $c)if(in_array((int)$m['set_id'],visible_set_ids((int)$c['class_id']),true))$ok=true;
}
if(!$ok){http_response_code(404);exit;}serve_lms_file($m);
