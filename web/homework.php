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
$KIND_ICO = ['mcq' => '🔘', 'text' => '✍️', 'drawing' => '🎨', 'audio' => '🎙'];
if(!$h){
 $classes=qall('SELECT * FROM classes WHERE teacher_id=? ORDER BY name',[$t['id']]);
 $rows=qall('SELECT h.*,c.name AS class_name,(SELECT COUNT(*) FROM homework_questions q WHERE q.homework_id=h.id) nq,(SELECT COUNT(*) FROM students s WHERE s.class_id=h.class_id) ns,'
   .'(SELECT COUNT(*) FROM homework_submissions s WHERE s.homework_id=h.id AND s.submitted_at IS NOT NULL) nsub,(SELECT COUNT(*) FROM homework_submissions s WHERE s.homework_id=h.id AND s.submitted_at IS NOT NULL AND s.released=0) nwait '
   .'FROM homework h JOIN classes c ON c.id=h.class_id WHERE c.teacher_id=? ORDER BY h.id DESC',[$t['id']]);
 $now=time();$st=fn($r)=>!$r['published']?'draft':($r['due_at']&&strtotime($r['due_at'])<$now?'closed':'open');
 $cnt=['all'=>count($rows),'open'=>0,'draft'=>0,'closed'=>0];foreach($rows as $r)$cnt[$st($r)]++;
 ?>
<div class="page-head"><div><h1>🏡 Bài tập về nhà</h1><p class="muted">Trắc nghiệm, tự luận, vẽ tay, ghi âm — học sinh làm trên điện thoại, AI gợi ý chấm, bạn duyệt và công bố.</p></div>
  <button type="button" class="primary" onclick="document.getElementById('hw-new').open=true;document.querySelector('#hw-new input[name=title]').focus()">＋ Giao bài mới</button></div>
<div class="stat-row">
  <div class="stat"><b><?= $cnt['open'] ?></b><span>Đang giao</span></div>
  <div class="stat"><b><?= array_sum(array_column($rows,'nwait')) ?></b><span>Bài nộp chờ chấm / công bố</span></div>
  <div class="stat"><b><?= $cnt['draft'] ?></b><span>Bản nháp</span></div>
  <div class="stat"><b><?= $cnt['closed'] ?></b><span>Đã hết hạn</span></div>
</div>
<details class="card hw-new" id="hw-new" <?= $rows ? '' : 'open' ?>><summary><b>＋ Giao bài mới</b><span class="muted small">Tạo bản nháp, thêm câu hỏi rồi công bố cho học sinh</span></summary>
  <form method="post" class="hw-form"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label>Lớp<select name="class_id"><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></label>
    <label class="wide">Tên bài<input name="title" required maxlength="200" placeholder="VD: Ôn tập chương 3 — MQTT"></label>
    <label class="wide">Hướng dẫn<textarea name="instructions" rows="3" placeholder="Yêu cầu, cách nộp, lưu ý cho học sinh…"></textarea></label>
    <label>Hạn nộp<input type="datetime-local" name="due_at"></label>
    <div class="hw-go"><button class="primary">Tạo bản nháp</button></div>
  </form></details>
<section class="card flush">
  <div class="card-head"><nav class="pill-tabs mini" data-hw-filter>
    <?php foreach(['all'=>'Tất cả','open'=>'Đang giao','draft'=>'Nháp','closed'=>'Hết hạn'] as $k=>$v): ?><a href="#" data-f="<?= $k ?>" class="<?= $k==='all'?'on':'' ?>"><?= h($v) ?> <small><?= $cnt[$k] ?></small></a><?php endforeach; ?></nav>
    <input type="search" class="tbl-filter" placeholder="🔎 Tìm bài tập…" data-filter="#hw-table"></div>
  <?php if(!$rows): ?><div class="empty-state"><div style="font-size:40px">📭</div><b>Chưa có bài tập nào</b><p class="muted">Bấm “＋ Giao bài mới” để tạo bài đầu tiên.</p></div>
  <?php else: ?>
  <table class="data" id="hw-table"><thead><tr><th>Bài tập</th><th>Câu</th><th>Đã nộp</th><th>Hạn nộp</th><th>Trạng thái</th><th></th></tr></thead><tbody>
  <?php foreach($rows as $r): $s=$st($r); $pct=$r['ns']?round($r['nsub']*100/$r['ns']):0; ?>
    <tr data-st="<?= $s ?>">
      <td><a class="row-title" href="homework.php?id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a><small class="muted"><?= h($r['class_name']) ?></small></td>
      <td><?= (int)$r['nq'] ?></td>
      <td style="min-width:140px"><b><?= (int)$r['nsub'] ?></b><span class="muted">/<?= (int)$r['ns'] ?></span><div class="meter-sm"><span style="width:<?= $pct ?>%"></span></div></td>
      <td><?= $r['due_at'] ? h(date('d/m H:i', strtotime($r['due_at']))) : '<span class="muted">—</span>' ?></td>
      <td><?= ['open'=>'<span class="tag ok">● Đang giao</span>','draft'=>'<span class="tag">Nháp</span>','closed'=>'<span class="tag warn">Hết hạn</span>'][$s] ?><?= $r['nwait'] ? ' <span class="tag info">'.(int)$r['nwait'].' chờ chấm</span>' : '' ?></td>
      <td class="actions"><a class="btn small" href="homework.php?id=<?= (int)$r['id'] ?>">Mở</a> <a class="btn small" href="homework_grade.php?id=<?= (int)$r['id'] ?>">Chấm</a></td>
    </tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
</section>
<script>
document.querySelector('[data-hw-filter]')?.addEventListener('click', (e) => {
  const a = e.target.closest('[data-f]'); if (!a) return; e.preventDefault();
  a.parentElement.querySelectorAll('a').forEach((x) => x.classList.toggle('on', x === a));
  document.querySelectorAll('#hw-table tbody tr').forEach((tr) => { tr.hidden = a.dataset.f !== 'all' && tr.dataset.st !== a.dataset.f; });
});
</script>
<?php
}else{
 $qs=qall('SELECT * FROM homework_questions WHERE homework_id=? ORDER BY ord',[$id]);
 $nsub=(int)qv('SELECT COUNT(*) FROM homework_submissions WHERE homework_id=? AND submitted_at IS NOT NULL',[$id]);
 $ns=(int)qv('SELECT COUNT(*) FROM students WHERE class_id=?',[$h['class_id']]);
 $total=array_sum(array_map(fn($r)=>(float)$r['max_score'],$qs));
 ?>
<div class="crumbs"><a href="homework.php">Bài tập về nhà</a> › <?= h($h['class_name']) ?></div>
<div class="page-head"><div><h1><?= h($h['title']) ?> <?= $h['published'] ? '<span class="tag ok">● Đã công bố</span>' : '<span class="tag">Nháp</span>' ?></h1>
  <p class="muted"><?= h($h['class_name']) ?> · <?= count($qs) ?> câu · tổng <?= rtrim(rtrim(number_format($total,2,'.',''),'0'),'.') ?> điểm · hạn nộp <?= $h['due_at'] ? h(date('d/m/Y H:i', strtotime($h['due_at']))) : 'không giới hạn' ?> · đã nộp <?= $nsub ?>/<?= $ns ?></p></div>
  <form method="post" class="row" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="<?= $h['published'] ? '' : 'primary' ?>"><?= $h['published'] ? 'Ẩn bài' : 'Công bố cho học sinh' ?></button> <a class="btn" href="homework_grade.php?id=<?= $id ?>">Chấm / công bố kết quả</a> <a class="btn" href="homework_export.php?id=<?= $id ?>">⬇ Xuất bảng điểm</a></form></div>
<?php if ($h['instructions']): ?><div class="card hw-ins"><b>Hướng dẫn</b><p><?= nl2br(h($h['instructions'])) ?></p></div><?php endif; ?>
<div class="hw-grid">
  <section class="stack" style="max-width:none">
    <?php if (!$qs): ?><div class="card empty-state"><div style="font-size:36px">🧩</div><b>Bài chưa có câu hỏi</b><p class="muted">Thêm câu ở khung bên cạnh — trắc nghiệm từ ngân hàng, tự luận, vẽ tay hoặc ghi âm.</p></div><?php endif; ?>
    <?php foreach($qs as $r): ?>
      <article class="card hw-q"><div class="hw-q-head"><span class="hw-q-no"><?= (int)$r['ord'] ?></span><span class="tag info"><?= $KIND_ICO[$r['kind']] ?> <?= h(LMS_KINDS[$r['kind']]) ?></span>
        <?= $r['topic'] ? '<span class="tag">'.h($r['topic']).'</span>' : '' ?><span class="sp"></span><b><?= rtrim(rtrim($r['max_score'],'0'),'.') ?> điểm</b></div>
        <div class="qtext"><?= nl2br(h($r['content'])) ?></div>
        <?php if ($r['rubric']): ?><div class="hw-rub"><b>Tiêu chí chấm:</b> <?= nl2br(h($r['rubric'])) ?></div><?php endif; ?></article>
    <?php endforeach; ?>
  </section>
  <form method="post" class="card stack hw-add"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="id" value="<?= $id ?>"><h2>＋ Thêm câu</h2>
    <div class="kind-pick"><?php foreach(['mcq'=>'Trắc nghiệm từ ngân hàng','text'=>'Tự luận ngắn','drawing'=>'Vẽ tay / ảnh bài làm','audio'=>'Trả lời bằng ghi âm'] as $k=>$v): ?>
      <label><input type="radio" name="kind" value="<?= $k ?>" <?= $k==='mcq'?'checked':'' ?>><span><i><?= $KIND_ICO[$k] ?></i><?= h($v) ?></span></label><?php endforeach; ?></div>
    <label data-k="mcq">Câu trắc nghiệm<select name="question_id"><option value="">— chọn nếu trắc nghiệm —</option>
      <?php foreach(qall('SELECT q.id,q.content,s.title FROM questions q JOIN question_sets s ON s.id=q.set_id WHERE s.teacher_id=? AND q.correct IS NOT NULL ORDER BY q.id DESC',[$t['id']]) as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h($r['title'].' · '.mb_substr($r['content'],0,100)) ?></option><?php endforeach; ?></select></label>
    <label data-k="text drawing audio" hidden>Nội dung (tự luận/vẽ/ghi âm)<textarea name="content" rows="3"></textarea></label>
    <label data-k="text drawing audio" hidden>Chủ đề<input name="topic" maxlength="100"></label>
    <label data-k="text drawing audio" hidden>Đáp án mẫu / tiêu chí chấm<textarea name="rubric" rows="3" placeholder="AI dùng tiêu chí này để gợi ý chấm"></textarea></label>
    <label>Điểm tối đa<input type="number" name="max_score" value="1" min="0.01" max="100" step="0.01"></label>
    <button class="primary">Thêm câu</button></form>
</div>
<script>
(function () {
  const f = document.querySelector('.hw-add'); if (!f) return;
  const sync = () => { const k = f.querySelector('[name=kind]:checked').value; f.querySelectorAll('[data-k]').forEach((el) => { el.hidden = !el.dataset.k.split(' ').includes(k); }); };
  f.addEventListener('change', sync); sync();
})();
</script>
<?php
}page_foot();
