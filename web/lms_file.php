<?php
require __DIR__.'/lib/lms.php';
$r=q1('SELECT a.*,s.student_code,h.class_id,c.teacher_id FROM homework_answers a JOIN homework_submissions s ON s.id=a.submission_id JOIN homework h ON h.id=s.homework_id JOIN classes c ON c.id=h.class_id WHERE a.id=?',[(int)($_GET['answer']??0)]);
$allowed=false;if($r){if(me()){require_login();$allowed=(int)$r['teacher_id']===(int)me()['id'];}if(stu())$allowed=$allowed||$r['student_code']===stu()['code'];if(parent_me())$allowed=$allowed||(bool)qv('SELECT 1 FROM parent_links l JOIN students st ON st.class_id=l.class_id AND st.student_code=l.student_code WHERE l.parent_id=? AND l.class_id=? AND l.student_code=?',[parent_me()['id'],$r['class_id'],$r['student_code']]);}
if(!$allowed){http_response_code(404);exit;}
serve_lms_file($r);
