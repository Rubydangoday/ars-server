<?php
// Ghi câu hỏi (đã chuẩn hoá) vào một bộ câu hỏi.
declare(strict_types=1);

const MAX_TEST_QUESTIONS = 64;           // giới hạn mặt nạ câu mở trong beacon (bộ dùng với clicker)

/** Kiểm tra câu hỏi dạng chuẩn hoá: ['content','options'=>[],'correct','explanation','topic','difficulty','time_limit'?] */
function qbank_check(array $q): array {
    $e = [];
    if (trim((string)($q['content'] ?? '')) === '') $e[] = 'thiếu nội dung câu hỏi';
    $n = count($q['options'] ?? []);
    if ($n < 2 || $n > 10) $e[] = 'cần 2–10 phương án';
    foreach ($q['options'] ?? [] as $i => $o) if (trim((string)$o) === '') $e[] = 'phương án ' . LETTERS[$i] . ' trống';
    $c = (string)($q['correct'] ?? '');
    if ($c !== '' && strpos(substr(LETTERS, 0, $n), $c) === false) $e[] = 'đáp án đúng không hợp lệ';
    return $e;
}

/** Thêm vào cuối bộ; trả về số thứ tự câu trong bộ. */
function qbank_add(array $set, array $q): int {
    $errs = qbank_check($q);
    if ($errs) throw new RuntimeException('Câu hỏi chưa hợp lệ: ' . implode('; ', $errs) . '.');
    $n = (int)qv('SELECT COUNT(*) FROM questions WHERE set_id=?', [$set['id']]);
    if (($set['purpose'] ?? 'test') === 'test' && $n >= MAX_TEST_QUESTIONS)
        throw new RuntimeException('Bộ kiểm tra "' . $set['title'] . '" đã đủ ' . MAX_TEST_QUESTIONS . ' câu (giới hạn của clicker). Chọn hoặc tạo bộ khác.');
    $dup = qv('SELECT position FROM questions WHERE set_id=? AND content=?', [$set['id'], trim($q['content'])]);
    if ($dup) throw new RuntimeException('Câu này đã có trong bộ (câu ' . $dup . ').');
    $opts = array_values($q['options']);
    $cols = [];
    for ($i = 0; $i < 10; $i++) $cols[] = isset($opts[$i]) ? mb_substr(trim((string)$opts[$i]), 0, 500) : null;
    set_unfinalize((int)$set['id']);                     // trước INSERT: giữ nguyên lastInsertId() cho nơi gọi
    $pos = (int)qv('SELECT COALESCE(MAX(position),0)+1 FROM questions WHERE set_id=?', [$set['id']]);
    $tl = isset($q['time_limit']) && (int)$q['time_limit'] >= 5 ? (int)$q['time_limit'] : null;
    $d = (string)($q['difficulty'] ?? '');
    q('INSERT INTO questions (set_id, position, content, topic, difficulty, n_options, opt_a,opt_b,opt_c,opt_d,opt_e,opt_f,opt_g,opt_h,opt_i,opt_j, correct, explanation, origin, time_limit) '
    . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      array_merge([$set['id'], $pos, trim($q['content']), trim((string)($q['topic'] ?? '')) ?: null, isset(DIFFICULTY[$d]) ? $d : null, count($opts)], $cols,
                  [(string)($q['correct'] ?? '') ?: null, trim((string)($q['explanation'] ?? '')) ?: null, mb_substr((string)($q['origin'] ?? 'manual'), 0, 12), $tl]));
    return $pos;
}

/** Nội dung các câu đã có (để AI tránh trùng). */
function qbank_stems(array $set_ids): array {
    $set_ids = array_filter(array_map('intval', $set_ids));
    if (!$set_ids) return [];
    return array_column(qall('SELECT content FROM questions WHERE set_id IN (' . implode(',', $set_ids) . ') ORDER BY id DESC LIMIT 80'), 'content');
}
