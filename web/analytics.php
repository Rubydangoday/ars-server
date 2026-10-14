<?php
require __DIR__.'/lib/school.php';require __DIR__.'/lib/sheet.php';$t=require_login();$id=(int)($_GET['class']??0);if(!$id&&!isset($_GET['class'])){$vc=viewable_classes();$id=(int)($vc[0]['id']??0);}$c=$id?viewable_class($id):null; // GV chủ nhiệm / Admin trường: chỉ đọc
$students=$c?qall('SELECT * FROM students WHERE class_id=? ORDER BY seat_no',[$id]):[];$data=[];$grades=[['Mã học sinh','Họ tên','Nguồn','Ngày','Bài','Điểm','Điểm tối đa']];
if($c){
 foreach(qall('SELECT st.student_code,st.full_name,q.topic,q.correct,f.answer,s.started_at,(SELECT COUNT(*) FROM attendance_final af WHERE af.session_id=s.id AND af.seat_no=st.seat_no AND af.status="present") AS present,qs.title,sq.question_id,st.seat_no FROM students st JOIN sessions s ON s.class_id=st.class_id AND s.status="ended" AND s.released=1 JOIN question_sets qs ON qs.id=s.set_id JOIN session_questions sq ON sq.session_id=s.id JOIN questions q ON q.id=sq.question_id LEFT JOIN final_answers f ON f.session_q_id=sq.id AND f.seat_no=st.seat_no WHERE st.class_id=? AND q.correct IS NOT NULL',[$id]) as $r){if(isset($r['present'])&&!$r['present'])continue;$key=$r['seat_no'];$topic=$r['topic']?:'Chưa gắn chủ đề';$data[$key][$topic]['n']=($data[$key][$topic]['n']??0)+1;$data[$key][$topic]['ok']=($data[$key][$topic]['ok']??0)+(int)($r['answer']===$r['correct']);}
 foreach(qall('SELECT st.seat_no,hq.topic,a.score,hq.max_score FROM homework_answers a JOIN homework_questions hq ON hq.id=a.hw_question_id JOIN homework_submissions s ON s.id=a.submission_id JOIN homework h ON h.id=s.homework_id JOIN students st ON st.class_id=h.class_id AND st.student_code=s.student_code WHERE h.class_id=? AND s.submitted_at IS NOT NULL AND s.released=1 AND a.score IS NOT NULL',[$id]) as $r){if(isset($r['present'])&&!$r['present'])continue;$key=$r['seat_no'];$topic=$r['topic']?:'Chưa gắn chủ đề';$data[$key][$topic]['n']=($data[$key][$topic]['n']??0)+1;$data[$key][$topic]['ok']=($data[$key][$topic]['ok']??0)+($r['score']/$r['max_score']);}
 foreach(qall('SELECT st.student_code,st.full_name,h.title,s.submitted_at,SUM(a.score) AS score,SUM(hq.max_score) AS max_score FROM homework_submissions s JOIN homework h ON h.id=s.homework_id JOIN students st ON st.class_id=h.class_id AND st.student_code=s.student_code JOIN homework_answers a ON a.submission_id=s.id JOIN homework_questions hq ON hq.id=a.hw_question_id WHERE h.class_id=? AND s.released=1 GROUP BY s.id,st.student_code,st.full_name,h.title,s.submitted_at',[$id]) as $r)$grades[]=[$r['student_code'],$r['full_name'],'Bài tập',$r['submitted_at'],$r['title'],(float)$r['score'],(float)$r['max_score']];
 foreach(qall('SELECT st.student_code,st.full_name,s.started_at,qs.title,COUNT(q.id) AS n,SUM(CASE WHEN f.answer=q.correct THEN 1 ELSE 0 END) AS ok FROM students st JOIN sessions s ON s.class_id=st.class_id AND s.status="ended" AND s.released=1 JOIN question_sets qs ON qs.id=s.set_id JOIN session_questions sq ON sq.session_id=s.id JOIN questions q ON q.id=sq.question_id AND q.correct IS NOT NULL LEFT JOIN final_answers f ON f.session_q_id=sq.id AND f.seat_no=st.seat_no WHERE st.class_id=? GROUP BY st.id,s.id,st.student_code,st.full_name,s.started_at,qs.title ORDER BY s.started_at',[$id]) as $r)$grades[]=[$r['student_code'],$r['full_name'],'Remote',$r['started_at'],$r['title'],(int)$r['ok'],(int)$r['n']];
}
$topics=[];foreach($data as $row)foreach($row as $tp=>$v)$topics[$tp]=true;$topics=array_keys($topics);sort($topics);$matrix=[array_merge(['Mã học sinh','Họ tên'],$topics)];foreach($students as $st){$row=[$st['student_code'],$st['full_name']];foreach($topics as $tp){$v=$data[$st['seat_no']][$tp]??null;$row[]=$v?round(100*$v['ok']/$v['n'],1):'';}$matrix[]=$row;}
if($c&&($_GET['format']??'')==='csv')send_csv($grades,'ARS_diem_lop_'.$id.'.csv');
if($c&&($_GET['format']??'')==='xlsx')send_xlsx(['Ket qua'=>$grades,'Ma tran chu de'=>$matrix],'ARS_bao_cao_lop_'.$id.'.xlsx');
page_head('Báo cáo kỹ năng','analytics.php');
$vcs=viewable_classes();
echo '<div class="page-head"><div><h1>📈 '.te('Báo cáo kỹ năng và điểm').'</h1><p class="muted">'.te('Phần trăm đạt theo chủ đề từ kết quả đã công bố. Ô không có dữ liệu để trống. Đây là chỉ báo ôn tập, không thay đánh giá của giáo viên').'.</p></div>';
if($c)echo '<div class="row" style="margin:0"><a class="btn" href="analytics.php?class='.$id.'&format=xlsx">⬇ '.te('Xuất Excel').'</a><a class="btn" href="analytics.php?class='.$id.'&format=csv">⬇ '.te('Xuất điểm CSV').'</a><button type="button" onclick="window.print()">🖨 '.te('In / Lưu PDF').'</button></div>';
echo '</div>';
if(!$vcs){echo '<div class="card">'.ui_empty('📈',t('Chưa có lớp nào'),t('Tạo lớp và thêm học sinh ở tab Lớp học trước.'),'<a class="btn primary" href="classes.php">'.te('Đến Lớp học').'</a>').'</div>';page_foot();exit;}
echo '<nav class="pill-tabs">';foreach($vcs as $r)echo '<a href="analytics.php?class='.$r['id'].'" class="'.((int)$r['id']===$id?'on':'').'">'.h(($r['cohort']?$r['cohort'].' · ':'').$r['name'].($r['subject']?' · '.$r['subject']:'').($r['is_owner']?'':' — '.$r['teacher_name'])).'</a>';echo '</nav>';
foreach(my_homerooms() as $hr)echo '<div class="flash ok">🏫 '.te('Lớp chủ nhiệm').': <a href="cohort.php?name='.rawurlencode($hr['name']).'"><b>'.h($hr['name']).'</b> — '.te('tổng hợp mọi môn').' →</a></div>';
if($c){
 // chỉ số tổng
 $tp_avg=[];foreach($topics as $tp){$sum=0;$n=0;foreach($data as $row)if(isset($row[$tp])&&$row[$tp]['n']){$sum+=$row[$tp]['ok']/$row[$tp]['n'];$n++;}$tp_avg[$tp]=$n?100*$sum/$n:null;}
 $st_avg=[];foreach($students as $st){$sum=0;$n=0;foreach(($data[$st['seat_no']]??[]) as $v)if($v['n']){$sum+=$v['ok']/$v['n'];$n++;}$st_avg[$st['seat_no']]=$n?100*$sum/$n:null;}
 $valid=array_filter($st_avg,fn($v)=>$v!==null);$weak=array_filter($tp_avg,fn($v)=>$v!==null&&$v<50);
 $low=count(array_filter($valid,fn($v)=>$v<50));
 echo '<div class="ui-kpis">'.ui_kpi('👥',(string)count($students),t('Học sinh'),'','blue')
   .ui_kpi('🎯',$valid?round(array_sum($valid)/count($valid)).'%':'—',t('Mức đạt trung bình của lớp'),'','green')
   .ui_kpi('🧩',(string)count($topics),t('Chủ đề có dữ liệu'),$weak?te('{n} chủ đề dưới 50%',['n'=>count($weak)]):'','violet')
   .ui_kpi('🆘',(string)$low,t('Học sinh cần hỗ trợ'),t('mức đạt dưới 50%'),'red').'</div>';
 echo '<div class="an-grid"><section class="card">'.ui_card_head('🧩 '.te('Mức đạt theo chủ đề'),t('Trung bình của cả lớp — chủ đề yếu nhất ở trên.'));
 if(!$tp_avg)echo ui_empty('🧩',t('Chưa có dữ liệu.'),t('Báo cáo hiện khi có buổi học hoặc bài tập được công bố kết quả.'));
 $tpv=array_filter($tp_avg,fn($v)=>$v!==null);asort($tpv);foreach($tpv as $tp=>$v)echo ui_bar($tp,$v);
 echo '</section><section class="card">'.ui_card_head('🆘 '.te('Học sinh cần quan tâm'),t('Mức đạt trung bình thấp nhất.'));
 asort($valid);$names=array_column($students,'full_name','seat_no');
 if(!$valid)echo ui_empty('🙂',t('Chưa có dữ liệu.'));
 foreach(array_slice($valid,0,6,true) as $seat=>$v)echo ui_bar($names[$seat]??('#'.$seat),$v);
 echo '</section></div>';
 // bản đồ nhiệt
 echo '<section class="card flush">'.ui_card_head('🗺 '.te('Ma trận kiến thức'),t('Mỗi ô: % đạt của học sinh ở chủ đề. Đỏ < 50%, vàng < 80%, xanh ≥ 80%.'));
 if(count($matrix[0])<=2)echo ui_empty('🗺',t('Chưa có dữ liệu.'));
 else{echo '<div class="heat-wrap"><table class="data heat"><thead><tr>';foreach($matrix[0] as $i=>$cell)echo '<th'.($i>1?' class="c"':'').'>'.h($i<2?t($cell):$cell).'</th>';echo '</tr></thead><tbody>';
  foreach(array_slice($matrix,1) as $row){echo '<tr>';foreach($row as $i=>$cell){if($i<2){echo '<td'.($i===1?' class="nm"':' class="muted"').'>'.h($cell).'</td>';continue;}
   echo $cell===''?'<td class="c"><span class="hc none">·</span></td>':'<td class="c"><span class="hc '.($cell<50?'low':($cell<80?'mid':'good')).'">'.round((float)$cell).'</span></td>';}echo '</tr>';}
  echo '</tbody></table></div>';}
 echo '</section>';
 // sổ kết quả
 echo '<section class="card flush">'.ui_card_head('📒 '.te('Sổ kết quả'),t('Chưa xác nhận mẫu sổ điểm theo quy định: cần đối chiếu mẫu của trường, môn, cấp học trước khi sử dụng làm sổ chính thức').'.');
 if(count($grades)<=1)echo ui_empty('📒',t('Chưa có dữ liệu.'));
 else{echo '<div class="heat-wrap"><table class="data"><thead><tr>';foreach($grades[0] as $cell)echo '<th>'.te($cell).'</th>';echo '</tr></thead><tbody>';
  foreach(array_slice($grades,1) as $row)echo '<tr><td class="muted">'.h($row[0]).'</td><td><b>'.h($row[1]).'</b></td><td><span class="tag '.($row[2]==='Remote'?'info':'').'">'.te($row[2]).'</span></td><td class="nowrap">'.h(substr((string)$row[3],0,16)).'</td><td>'.h($row[4]).'</td><td>'.ui_score((float)$row[5],(float)$row[6]).'</td><td class="muted">'.h($row[6]).'</td></tr>';
  echo '</tbody></table></div>';}
 echo '</section>';
}
echo '<style>@media print{header.top,nav,form,button,.btn{display:none!important}main{max-width:none}table{font-size:10px}tr{break-inside:avoid}}</style>';page_foot();
