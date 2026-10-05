<?php
// Tạo lượt ôn tập rồi chuyển sang làm bài.
//   src=assignment&aid=      bài GV giao
//   src=wrong[&class_id&topic&session]   các câu sai/bỏ trống chưa thuộc (theo bộ lọc)
//   src=topic&class_id&topic ôn kỹ một chủ đề: câu sai trước, thêm câu cùng chủ đề trong các bộ được phép ôn
//   src=session&sid=         làm lại cả bài của một buổi
//   src=retry&attempt=       ôn lại các câu sai lần đầu của một lượt ôn
//   src=lesson&lid=          câu hỏi củng cố của một buổi học đã công bố
require __DIR__ . '/../lib/lms.php';
$me = require_student();
$code = $me['code'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('practice.php');
$src = $_POST['src'] ?? '';
$NOTOPIC = '(chưa gắn chủ đề)';

try {
    switch ($src) {
        case 'assignment':
            $a = q1('SELECT a.* FROM practice_assignments a JOIN students s ON s.class_id=a.class_id AND s.student_code=? WHERE a.id=?', [$code, (int)$_POST['aid']]);
            if (!$a) throw new RuntimeException('Không tìm thấy bài ôn tập.');
            if($a['due_at'] && strtotime($a['due_at'])<time())throw new RuntimeException('Bài đã hết hạn.');
            $ids = array_column(qall("SELECT id FROM questions WHERE set_id=? AND correct IS NOT NULL AND correct<>''", [$a['set_id']]), 'id');
            $aid = create_attempt($code, (int)$a['class_id'], 'assignment', $a['title'], $ids, $a['mode'], (int)$a['id'], $a['mode'] === 'exam' && $a['time_limit'] ? (int)$a['time_limit'] : null);
            break;

        case 'wrong':
            $cid = (int)($_POST['class_id'] ?? 0) ?: null;
            $items = !empty($_POST['session'])
                ? array_filter(my_items($code, $cid), fn($r) => in_array($r['status'], ['wrong', 'blank'], true))
                : array_filter(latest_by_question(all_wrong_sources($code, $cid)), fn($r) => in_array($r['status'], ['wrong', 'blank'], true));
            if (!empty($_POST['topic'])) $items = array_filter($items, fn($r) => ($r['topic'] ?: $NOTOPIC) === $_POST['topic']);
            if (!empty($_POST['session'])) $items = array_filter($items, fn($r) => (int)$r['session_id'] === (int)$_POST['session']);
            $ms = mastery($code, array_column($items, 'question_id'));
            $ids = array_column(array_filter($items, fn($r) => $ms[$r['question_id']] !== 'thuoc'), 'question_id');
            if (!$ids) $ids = array_column($items, 'question_id');          // đã thuộc hết: cho ôn lại tất cả
            $first = reset($items);
            $aid = create_attempt($code, (int)($cid ?? $first['class_id'] ?? 0), 'wrong', 'Ôn lại câu sai' . (!empty($_POST['topic']) ? ' — ' . $_POST['topic'] : ''), array_slice($ids, 0, 40));
            break;

        case 'topic':
            $cid = (int)$_POST['class_id'];
            my_class($code, $cid);
            $topic = (string)$_POST['topic'];
            $items = array_filter(my_items($code, $cid), fn($r) => ($r['topic'] ?: $NOTOPIC) === $topic);
            $wrong = array_column(array_filter($items, fn($r) => $r['status'] !== 'right'), 'question_id');
            $sets = visible_set_ids($cid) ?: [0];
            $in = implode(',', $sets);
            $more = array_column(qall("SELECT id FROM questions WHERE set_id IN ($in) AND correct IS NOT NULL AND correct<>'' AND "
                . ($topic === $NOTOPIC ? '(topic IS NULL OR topic="")' : 'topic=?') . ' ORDER BY RAND() LIMIT 30', $topic === $NOTOPIC ? [] : [$topic]), 'id');
            $ids = array_slice(array_values(array_unique(array_merge($wrong, $more))), 0, max(15, count($wrong)));
            $aid = create_attempt($code, $cid, 'topic', 'Ôn kỹ: ' . $topic, $ids);
            break;

        case 'session':
            $items = array_filter(my_items($code), fn($r) => (int)$r['session_id'] === (int)$_POST['sid']);
            if (!$items) throw new RuntimeException('Không tìm thấy buổi học.');
            $first = reset($items);
            $aid = create_attempt($code, (int)$first['class_id'], 'session', 'Làm lại: ' . $first['set_title'], array_column($items, 'question_id'));
            break;

        case 'lesson':                     // câu hỏi củng cố cuối buổi học
            $l = q1('SELECT l.* FROM lessons l JOIN students s ON s.class_id=l.class_id AND s.student_code=? WHERE l.id=? AND l.published=1', [$code, (int)$_POST['lid']]);
            if (!$l || !$l['quiz_set_id']) throw new RuntimeException('Buổi học chưa có câu hỏi củng cố.');
            $ids = array_column(qall("SELECT id FROM questions WHERE set_id=? AND correct IS NOT NULL AND correct<>''", [$l['quiz_set_id']]), 'id');
            $aid = create_attempt($code, (int)$l['class_id'], 'lesson', 'Củng cố Buổi ' . $l['no'] . ($l['title'] !== '' ? ': ' . $l['title'] : ''), $ids);
            q('UPDATE practice_attempts SET lesson_id=? WHERE id=?', [$l['id'], $aid]);
            break;

        case 'retry':                      // ôn lại các câu sai lần đầu của một lượt đã làm
            $old = my_attempt($code, (int)$_POST['attempt']);
            $ids = array_column(qall('SELECT question_id FROM practice_items WHERE attempt_id=? AND round=1 AND (is_correct IS NULL OR is_correct=0)', [$old['id']]), 'question_id');
            $aid = create_attempt($code, (int)$old['class_id'], 'wrong', 'Ôn lại câu sai: ' . preg_replace('/^Ôn lại câu sai: /u', '', $old['title']), $ids);
            break;

        default:
            throw new RuntimeException('Yêu cầu không hợp lệ.');
    }
    redirect('quiz.php?a=' . $aid);
} catch (Throwable $e) {
    flash($e->getMessage(), 'err');
    redirect('practice.php');
}
