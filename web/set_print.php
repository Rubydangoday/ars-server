<?php
require __DIR__.'/lib/lms.php';require_login();$s=own_set((int)($_GET['id']??0));
page_head('In đề','',true);echo '<button onclick="window.print()">In / Lưu PDF</button><h1>'.h($s['title']).'</h1><p>Mã đề: '.h($s['variant_code']??'').'</p>';
foreach(qall('SELECT * FROM questions WHERE set_id=? ORDER BY position',[$s['id']]) as $q){echo '<article class="qcard"><h2>Câu '.$q['position'].'</h2><div class="qtext">'.nl2br(h($q['content'])).'</div>'.question_media_html((int)$q['id']).'<ol class="choices">';foreach(options_of($q) as $k=>$v)echo '<li>'.h($k.'. '.$v).'</li>';echo '</ol>';if(!empty($_GET['key']))echo '<p>Đáp án: '.h($q['correct']).' · '.h($q['explanation']).'</p>';echo '</article>';}
echo '<style>@media print{button{display:none}.qcard{break-inside:avoid}body{background:white}main{max-width:none}}</style>';page_foot();
