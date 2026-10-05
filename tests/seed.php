<?php
// Run ONLY against a disposable database. No real user data is needed.
ini_set('session.save_path',sys_get_temp_dir());
$_SERVER['REQUEST_METHOD']='GET';require __DIR__.'/../web/lib/lms.php';require __DIR__.'/../web/lib/qbank.php';
$prefix='test_'.bin2hex(random_bytes(4));$hash=password_hash('Test-password-2026',PASSWORD_DEFAULT);
q('INSERT INTO schools(name) VALUES(?)',[$prefix]);$school=(int)db()->lastInsertId();$ids=[];
for($i=1;$i<=3;$i++){q('INSERT INTO teachers(username,full_name,password_hash,is_admin,school_id) VALUES(?,?,?,?,?)',[$prefix.'_'.$i,'Giáo viên '.$i,$hash,$i===1?1:0,$i<3?$school:null]);$ids[]=(int)db()->lastInsertId();}
$room=next_free_room();q('INSERT INTO classes(teacher_id,name,room_id,join_code) VALUES(?,?,?,?)',[$ids[0],'Lớp kiểm thử',$room,substr(strtoupper($prefix),-6)]);$class=(int)db()->lastInsertId();$code=$prefix.'_hs';
q('INSERT INTO students(class_id,seat_no,student_code,full_name) VALUES(?,?,?,?)',[$class,5,$code,'Học sinh kiểm thử']);q('INSERT INTO student_users(student_code,password_hash,must_change) VALUES(?,?,0)',[$code,$hash]);
q('INSERT INTO question_sets(teacher_id,title,purpose) VALUES(?,?,"test")',[$ids[0],'Đề kiểm thử']);$setid=(int)db()->lastInsertId();$set=q1('SELECT * FROM question_sets WHERE id=?',[$setid]);
qbank_add($set,['content'=>'Một cộng một bằng? \\(1+1\\)','options'=>['1','2','3','4'],'correct'=>'B','topic'=>'Số học','explanation'=>'Một cộng một bằng hai.']);$qid=(int)db()->lastInsertId();
q('INSERT INTO sessions(class_id,set_id) VALUES(?,?)',[$class,$setid]);$sid=(int)db()->lastInsertId();
echo json_encode(['prefix'=>$prefix,'teachers'=>$ids,'class'=>$class,'code'=>$code,'set'=>$setid,'question'=>$qid,'session'=>$sid,'password'=>'Test-password-2026']);
