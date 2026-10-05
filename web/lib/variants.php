<?php
declare(strict_types=1);
function required_library_title($v): string {$v=trim((string)$v);if(!$v)throw new RuntimeException('Tên đề trống.');return mb_substr($v,0,200);}
/** A variant owns new question IDs and a remapped answer key. Original set is immutable. */
function shuffled_question(array $r,bool $shuffle): array {
 $opts=options_of($r);$keys=array_keys($opts);if($shuffle)shuffle($keys);$correct=null;$values=[];
 foreach($keys as $i=>$key){$values[]=$opts[$key];if($key===$r['correct'])$correct=LETTERS[$i];}
 return ['content'=>$r['content'],'options'=>$values,'correct'=>$correct,'topic'=>$r['topic'],'difficulty'=>$r['difficulty'],'explanation'=>$r['explanation'],'time_limit'=>$r['time_limit'],'origin'=>'variant'];
}
function clone_set(array $src,int $teacher,string $title,bool $questions,bool $options,?string $code): int {
 db()->beginTransaction();try{
 q('INSERT INTO question_sets(teacher_id,title,purpose,description,variant_code,source_set_id) VALUES(?,?,?,?,?,?)',[$teacher,$title,$src['purpose'],$src['description'],$code,$src['id']]);$id=(int)db()->lastInsertId();$dst=q1('SELECT * FROM question_sets WHERE id=?',[$id]);
 $rows=qall('SELECT * FROM questions WHERE set_id=? ORDER BY position',[$src['id']]);if($questions)shuffle($rows);
 foreach($rows as $r){qbank_add($dst,shuffled_question($r,$options));$qid=(int)db()->lastInsertId();q('INSERT INTO question_media(question_id,kind,store,name,mime,size) SELECT ?,kind,store,name,mime,size FROM question_media WHERE question_id=?',[$qid,$r['id']]);}
 db()->commit();return $id;
 }catch(Throwable $e){db()->rollBack();throw $e;}
}
