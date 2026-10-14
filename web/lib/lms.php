<?php
// Helpers shared by teacher, student and parent LMS. Never trust a posted class/student ID.
declare(strict_types=1);
require_once __DIR__.'/student.php';
const LMS_KINDS=['mcq'=>'Trắc nghiệm','text'=>'Tự luận','drawing'=>'Vẽ / ảnh bài làm','audio'=>'Ghi âm'];
function own_homework(int $id): array {
    $r=q1('SELECT h.*, c.name AS class_name FROM homework h JOIN classes c ON c.id=h.class_id WHERE h.id=? AND c.teacher_id=?',[$id,me()['id']]);
    if (!$r) {http_response_code(404);exit('Không tìm thấy bài tập.');} return $r;
}
function student_homework(string $code,int $id): array {
    $r=q1('SELECT h.*, (h.due_at IS NOT NULL AND h.due_at<NOW()) AS expired FROM homework h JOIN students s ON s.class_id=h.class_id WHERE h.id=? AND s.student_code=? AND h.published=1',[$id,$code]);
    if (!$r) {http_response_code(404);exit('Không tìm thấy bài tập.');} return $r;
}
function required_text(string $key,int $max=200): string {
    $v=trim((string)($_POST[$key]??'')); if($v==='')throw new RuntimeException('Thiếu thông tin: '.(['title'=>'Tên bài','full_name'=>'Họ tên','comment'=>'Nhận xét'][$key]??$key)); return mb_substr($v,0,$max);
}
function due_value($v): ?string {
    if(!$v)return null; $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)$v);
    if(!$d || $d->format('Y-m-d\TH:i')!==$v)throw new RuntimeException('Ngày giờ không hợp lệ.'); return $d->format('Y-m-d H:i:s');
}
function score_value($v,float $max): float {
    if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>$max)throw new RuntimeException('Điểm phải từ 0 đến '.$max); return round((float)$v,2);
}
/** Server MIME allowlist, random name, storage outside document root. No SVG/HTML. */
function lms_upload(array $f,array $allow,int $limit=20971520): array {
    if(account_limited())throw new RuntimeException(t('Tài khoản chưa xác nhận: nhập mã mời giáo viên để tải tệp lên.'));
    if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Tải tệp thất bại (kiểm tra dung lượng/php.ini).');
    if(!is_uploaded_file($f['tmp_name'])||filesize($f['tmp_name'])>$limit)throw new RuntimeException('Tệp không hợp lệ hoặc quá lớn.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if(!isset($allow[$mime]))throw new RuntimeException('Định dạng tệp không được hỗ trợ.');
    $store=bin2hex(random_bytes(20)).'.'.$allow[$mime];
    if(!move_uploaded_file($f['tmp_name'],uploads_dir().'/'.$store))throw new RuntimeException('Không lưu được tệp.');
    return ['store'=>$store,'name'=>mb_substr(basename($f['name']),0,255),'mime'=>$mime,'size'=>(int)filesize(uploads_dir().'/'.$store)];
}
const LMS_IMAGE=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
// iPhone/Safari ghi âm ra MP4; libmagic nhận diện là video/mp4 hoặc audio/x-m4a.
const LMS_AUDIO=['audio/webm'=>'webm','video/webm'=>'webm','audio/ogg'=>'ogg','audio/mpeg'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/mp4'=>'m4a','audio/x-m4a'=>'m4a','video/mp4'=>'m4a','audio/aac'=>'aac'];
// must_change: đăng nhập bằng mật khẩu giáo viên cấp — chưa được xem gì cho tới khi đổi mật khẩu (parent/password.php)
function parent_me(): ?array {$p=$_SESSION['parent']??null;return $p&&empty($p['must_change'])?$p:null;}
function require_parent(): array {if(!empty($_SESSION['parent']['must_change']))redirect('password.php');if(!parent_me())redirect('index.php');return parent_me();}
function parent_child(int $class,string $code): array {
    $r=q1('SELECT s.*, c.name AS class_name FROM parent_links l JOIN students s ON s.class_id=l.class_id AND s.student_code=l.student_code JOIN classes c ON c.id=s.class_id WHERE l.parent_id=? AND l.class_id=? AND l.student_code=?',[parent_me()['id'],$class,$code]);
    if(!$r){http_response_code(404);exit('Không tìm thấy học sinh.');}return $r;
}
function parent_head(string $title): void { page_head($title); }   // thanh trên phụ huynh vẽ trong page_head (lib/bootstrap.php)
function serve_lms_file(array $r): void {
    $store=$r['store']??$r['file_store'];
    if(!preg_match('/^[a-f0-9]{40}\.[a-z0-9]+$/',$store??'')){http_response_code(404);exit;}
    $path=uploads_dir().'/'.$store;if(!is_file($path)){http_response_code(404);exit;}
    session_write_close(); // tệp media dài không giữ khoá phiên
    $size=filesize($path);$start=0;$end=$size-1;
    header('Content-Type: '.$r['mime']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');
    header('Content-Security-Policy: default-src \'none\'');header('Accept-Ranges: bytes');
    header('Content-Disposition: inline; filename="attachment.'.pathinfo($path,PATHINFO_EXTENSION).'"');
    // Safari/iOS chỉ phát audio/video khi server trả 206 cho Range
    if(preg_match('/^bytes=(\d*)-(\d*)$/',$_SERVER['HTTP_RANGE']??'',$m)&&($m[1]!==''||$m[2]!=='')){
        if($m[1]===''){$start=max(0,$size-(int)$m[2]);}else{$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}
        if($start>$end||$start>=$size){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
        http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    }
    header('Content-Length: '.($end-$start+1));
    $fp=fopen($path,'rb');fseek($fp,$start);$left=$end-$start+1;
    while($left>0&&!feof($fp)){$chunk=fread($fp,min(65536,$left));if($chunk===false)break;echo $chunk;$left-=strlen($chunk);}
    fclose($fp);exit;
}
function homework_wrong_items(string $code,?int $class=null): array {
    return qall('SELECT q.id AS question_id,q.*,h.class_id,h.title AS set_title,s.submitted_at AS started_at,0 AS session_id, a.answer,"wrong" AS status,NULL AS class_rate FROM homework_answers a JOIN homework_submissions s ON s.id=a.submission_id JOIN homework_questions hq ON hq.id=a.hw_question_id JOIN homework h ON h.id=s.homework_id JOIN questions q ON q.id=hq.question_id WHERE s.student_code=? AND s.submitted_at IS NOT NULL AND s.released=1 AND hq.kind="mcq" AND a.score<hq.max_score '.($class?'AND h.class_id=? ':'').'ORDER BY s.submitted_at DESC', $class?[$code,$class]:[$code]);
}
function practice_wrong_items(string $code,?int $class=null): array {
 return qall('SELECT q.*,q.id AS question_id,a.class_id,a.title AS set_title,a.started_at,0 AS session_id,i.answer,"wrong" AS status,NULL AS class_rate FROM practice_items i JOIN practice_attempts a ON a.id=i.attempt_id JOIN questions q ON q.id=i.question_id WHERE a.student_code=? AND a.finished_at IS NOT NULL AND i.round=1 AND i.is_correct=0 '.($class?'AND a.class_id=? ':'').'ORDER BY a.started_at DESC',$class?[$code,$class]:[$code]);
}
/** Câu sai từ Remote, bài tập và luyện tập, mới nhất trước (latest_by_question cần thứ tự này). */
function all_wrong_sources(string $code,?int $class=null): array {
 $all=array_merge(my_items($code,$class),homework_wrong_items($code,$class),practice_wrong_items($code,$class));
 usort($all,fn($a,$b)=>strcmp((string)$b['started_at'],(string)$a['started_at']));return $all;
}
/** Aggregate released classroom scores and attendance by day/week for parent progress. */
function parent_progress(array $sessions,string $period): string {
 $groups=[];foreach($sessions as $r){$d=new DateTimeImmutable($r['started_at']);if($period==='week')$d=$d->modify('monday this week');$key=$d->format('Y-m-d');$groups[$key]??=['present'=>0,'total'=>0,'scores'=>[]];$groups[$key]['total']++;$groups[$key]['present']+=(int)(bool)$r['present'];if($r['present']&&$r['n'])$groups[$key]['scores'][]=10*$r['ok']/$r['n'];}
 ksort($groups);$groups=array_slice($groups,-12,null,true);$html='<table class="grid"><tr><th>'.te($period==='week'?'Tuần bắt đầu':'Ngày').'</th><th>'.te('Chuyên cần').'</th><th>'.te('Điểm trung bình / 10').'</th></tr>';$points=[];$i=0;$n=count($groups);
 foreach($groups as $day=>$v){$score=$v['scores']?array_sum($v['scores'])/count($v['scores']):null;$html.='<tr><td>'.h($day).'</td><td>'.te('{a}/{b} buổi',['a'=>$v['present'],'b'=>$v['total']]).'</td><td>'.($score===null?'—':round($score,1)).'</td></tr>';if($score!==null){$x=$n>1?35+$i*530/($n-1):300;$y=180-15*$score;$points[]=$x.','.$y;}$i++;}$html.='</table>';
 if($points){$svg='<svg viewBox="0 0 600 210" role="img" aria-label="'.te($period==='week'?'Tiến độ điểm trung bình theo tuần':'Tiến độ điểm trung bình theo ngày').'" style="width:100%;max-width:700px"><line x1="35" y1="30" x2="35" y2="180" stroke="#888"/><line x1="35" y1="180" x2="565" y2="180" stroke="#888"/><text x="5" y="35">10</text><text x="15" y="180">0</text><polyline class="fx-line" pathLength="1" points="'.h(implode(' ',$points)).'" fill="none" stroke="#267cb0" stroke-width="3" stroke-linejoin="round"/>';foreach($points as $j=>$point){[$x,$y]=explode(',',$point);$svg.='<circle class="fx-dot" style="animation-delay:'.round(.15+1.1*($j+1)/count($points),2).'s" cx="'.$x.'" cy="'.$y.'" r="4" fill="#267cb0"/>';}$svg.='<text x="35" y="205">'.h(array_key_first($groups)).'</text><text x="565" y="205" text-anchor="end">'.h(array_key_last($groups)).'</text></svg>';$html=$svg.$html;}
 return $html;
}

/**
 * Mọi câu học sinh đã làm, cùng định dạng my_items(): Remote trên lớp (buổi đã công bố), luyện tập trên web
 * (lượt đã nộp, tính lần trả lời đầu), bài tập về nhà (câu trắc nghiệm, đã công bố). Mới nhất trước.
 * Thêm: source = remote | practice | homework; bai = s<id> | p<id> | h<id> (một "bài làm" để nhóm trong Rút kinh nghiệm).
 */
function all_items(string $code, ?int $class = null): array {
    $out = [];
    foreach (my_items($code, $class) as $r) $out[] = $r + ['source' => 'remote', 'bai' => 's' . $r['session_id']];
    $cond = $class ? ' AND a.class_id=?' : '';
    foreach (qall('SELECT q.*, q.id AS question_id, a.class_id, c.name AS class_name, a.title AS set_title, a.started_at, a.id AS attempt_id, a.mode, '
        . 'i.answer, i.is_correct, NULL AS sq_id, NULL AS t_answer, NULL AS class_rate, 0 AS session_id '
        . 'FROM practice_items i JOIN practice_attempts a ON a.id=i.attempt_id JOIN questions q ON q.id=i.question_id JOIN classes c ON c.id=a.class_id '
        . 'WHERE a.student_code=? AND a.finished_at IS NOT NULL AND i.round=1 AND q.correct IS NOT NULL AND q.correct<>""' . $cond . ' ORDER BY a.started_at DESC, i.ord',
        $class ? [$code, $class] : [$code]) as $r)
        $out[] = $r + ['status' => $r['answer'] === null ? 'blank' : ((int)$r['is_correct'] ? 'right' : 'wrong'),
                       'kind' => 'practice', 'source' => 'practice', 'bai' => 'p' . $r['attempt_id'], 'present' => 1];
    $cond = $class ? ' AND h.class_id=?' : '';
    foreach (qall('SELECT q.*, q.id AS question_id, h.class_id, c.name AS class_name, h.title AS set_title, s.submitted_at AS started_at, h.id AS homework_id, '
        . 'a.answer, a.score, hq.max_score, NULL AS sq_id, NULL AS t_answer, NULL AS class_rate, 0 AS session_id '
        . 'FROM homework_answers a JOIN homework_submissions s ON s.id=a.submission_id JOIN homework_questions hq ON hq.id=a.hw_question_id '
        . 'JOIN homework h ON h.id=s.homework_id JOIN questions q ON q.id=hq.question_id JOIN classes c ON c.id=h.class_id '
        . 'WHERE s.student_code=? AND s.submitted_at IS NOT NULL AND s.released=1 AND hq.kind="mcq" AND a.score IS NOT NULL' . $cond . ' ORDER BY s.submitted_at DESC, hq.ord',
        $class ? [$code, $class] : [$code]) as $r)
        $out[] = $r + ['status' => ($r['answer'] ?? '') === '' ? 'blank' : ((float)$r['score'] >= (float)$r['max_score'] ? 'right' : 'wrong'),
                       'kind' => 'homework', 'source' => 'homework', 'bai' => 'h' . $r['homework_id'], 'present' => 1];
    usort($out, fn($a, $b) => strcmp((string)$b['started_at'], (string)$a['started_at']));   // latest_by_question cần mới nhất trước
    return $out;
}
const SOURCE_LABEL = ['remote' => ['📡', 'Remote trên lớp'], 'practice' => ['🎯', 'Luyện tập'], 'homework' => ['🏡', 'Bài tập về nhà']];

// ---------------------------------------------------------------- mã mời phụ huynh
// Mã ngắn 8 ký tự dạng "K7PM-3QXA" (không có 0/O/1/I), in kèm QR. Chỉ lưu bản băm; dùng một lần, hết hạn sau 7 ngày.
// Vẫn nhận mã cũ 32 ký tự hex. Sai quá 10 lần trong một phiên thì khoá thử 15 phút (chống dò mã).
const INVITE_ABC = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
function parent_invite_new(int $class_id, string $student_code): string {
    $raw = ''; for ($i = 0; $i < 8; $i++) $raw .= INVITE_ABC[random_int(0, strlen(INVITE_ABC) - 1)];
    q('INSERT INTO parent_invites(class_id,student_code,token_hash,expires_at) VALUES(?,?,?,NOW()+INTERVAL 7 DAY)', [$class_id, $student_code, hash('sha256', $raw)]);
    return substr($raw, 0, 4) . '-' . substr($raw, 4);
}
/** Chuẩn hoá mã người dùng gõ ("k7pm 3qxa" → "K7PM3QXA"); null nếu sai dạng. */
function parent_invite_norm(string $s): ?string {
    $s = trim($s);
    if (preg_match('/^[a-f0-9]{32}$/', $s)) return $s;                       // mã cũ
    $s = strtoupper(preg_replace('/[\s\-_.]+/', '', $s));
    $s = strtr($s, ['O' => '0', 'I' => '1']);                                // gõ nhầm chữ ↔ số → chắc chắn sai, báo lỗi rõ
    return preg_match('/^[' . INVITE_ABC . ']{8}$/', $s) ? $s : null;
}
/** Kiểm tra dạng + chống dò, trả về băm để tra parent_invites. */
function parent_invite_hash(string $input): string {
    $lock = $_SESSION['invite_lock'] ?? 0;
    if ($lock > time()) throw new RuntimeException('Nhập sai mã mời quá nhiều lần. Thử lại sau 15 phút.');
    $n = parent_invite_norm($input);
    if ($n === null) { parent_invite_fail(); throw new RuntimeException('Mã mời không đúng. Mã gồm 8 ký tự, ví dụ K7PM-3QXA.'); }
    return hash('sha256', $n);
}
function parent_invite_fail(): void {
    $_SESSION['invite_fail'] = ($_SESSION['invite_fail'] ?? 0) + 1;
    if ($_SESSION['invite_fail'] >= 10) { $_SESSION['invite_lock'] = time() + 900; $_SESSION['invite_fail'] = 0; }
}
