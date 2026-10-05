<?php
ini_set('session.save_path',sys_get_temp_dir());
$_SERVER['REQUEST_METHOD']='GET';require __DIR__.'/../web/lib/lms.php';require __DIR__.'/../web/lib/variants.php';
function assert_true(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
$r=['content'=>'Câu','n_options'=>4,'opt_a'=>'a','opt_b'=>'b','opt_c'=>'c','opt_d'=>'d','correct'=>'C','topic'=>'chủ đề','difficulty'=>'de','explanation'=>'giải thích','time_limit'=>30];
for($j=0;$j<1000;$j++){$v=shuffled_question($r,true);assert_true($v['options'][strpos(LETTERS,$v['correct'])]==='c','Answer key not preserved');$sorted=$v['options'];sort($sorted);assert_true($sorted===['a','b','c','d'],'Options lost');}
foreach([-1,1.1,'oops',INF] as $invalid){$caught=false;try{score_value($invalid,1);}catch(RuntimeException $e){$caught=true;}assert_true($caught,'Invalid score accepted');}
assert_true(score_value('0.75',1)===.75,'Valid score rejected');
$trend=parent_progress([['started_at'=>'2026-09-28 08:00:00','present'=>1,'n'=>2,'ok'=>1],['started_at'=>'2026-09-29 08:00:00','present'=>0,'n'=>2,'ok'=>0]],'week');assert_true(str_contains($trend,'1/2 buổi')&&str_contains($trend,'<svg')&&str_contains($trend,'>5</td>'),'Weekly progress/absence wrong');
echo "PASS: 1000 remappings, 4 invalid scores, valid score, weekly attendance/score\n";
