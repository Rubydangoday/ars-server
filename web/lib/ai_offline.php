<?php
// Chế độ cơ bản của trợ lý (không cần Internet, không cần API key):
// tìm câu định nghĩa ("X là …", "X: …", "X dùng để …") và câu có số liệu trong tài liệu rồi dựng câu hỏi trắc nghiệm.
declare(strict_types=1);

const OFFLINE_STOP = ['nó', 'đây', 'điều này', 'việc này', 'nếu', 'khi', 'vì', 'do', 'và', 'hoặc', 'nhưng', 'tuy', 'mà', 'thì', 'ví dụ', 'lưu ý', 'chú ý', 'kết luận', 'tóm lại', 'câu', 'hình', 'bảng'];

function offline_lines(string $text): array {
    $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
    // PDF ngắt dòng giữa câu: nối dòng sau nếu nó bắt đầu bằng chữ thường hoặc dòng trước kết thúc bằng dấu phẩy
    $text = preg_replace('/(?<=[\p{Ll},\-])[ \t]*\n[ \t]*(?=\p{Ll})/u', ' ', $text);
    $out = []; $heading = ''; $slide_title = false;
    foreach (explode("\n", $text) as $raw) {
        if (preg_match('/^— Slide \d+ —$/u', trim($raw))) { $slide_title = true; continue; }
        if ($slide_title && trim($raw) !== '') {             // dòng đầu mỗi slide là tiêu đề
            $slide_title = false;
            if (mb_strlen(trim($raw)) <= 70 && !preg_match('/[\.;]$/u', trim($raw))) { $heading = trim($raw); continue; }
        }
        $line = trim(preg_replace('/^[\s•●○▪■◦\-\*\x{2013}\x{2014}\x{F0A7}\x{F0B7}]+/u', '', $raw));
        $line = preg_replace('/\s+/u', ' ', $line);
        if ($line === '' || mb_strlen($line) < 3) continue;
        if (preg_match('/^(?:chương|bài|phần|chapter|mục|\d+(?:\.\d+)*\.?)\s+(\S.{1,70})$/iu', $line, $m) && !preg_match('/[\.;:,]$/u', $line) && mb_strlen($line) <= 80) {
            $heading = trim(preg_replace('/^(?:chương|bài|phần|chapter|mục)?\s*[\dIVX]+(?:\.\d+)*\s*[\.:\-–]?\s*/iu', '', $line)) ?: $heading;
            continue;
        }
        // tách câu trong đoạn dài
        foreach (preg_split('/(?<=[\.\!\?;])\s+(?=[A-ZÀ-Ỹ0-9"“(])/u', $line) as $s) {
            $s = trim($s);
            if (mb_strlen($s) >= 12) $out[] = ['s' => rtrim($s, ' .;'), 'topic' => mb_substr($heading, 0, 60)];
        }
    }
    return $out;
}

function offline_facts(string $text): array {
    $defs = []; $nums = [];
    foreach (offline_lines($text) as $ln) {
        $s = $ln['s'];
        if (mb_strlen($s) > 260) continue;
        $m = null; $kind = '';
        if (preg_match('/^(?<t>[^,;:()]{2,70}?)\s+(?:được gọi là|được định nghĩa là|có nghĩa là|là)\s+(?<d>.{8,220})$/u', $s, $m)) $kind = 'la';
        elseif (preg_match('/^(?<t>[^,;:()]{2,70}?)\s+(?<v>dùng để|dùng cho|có nhiệm vụ|có chức năng|cho phép|giúp)\s+(?<d>.{8,220})$/u', $s, $m)) $kind = 'dung';
        elseif (preg_match('/^(?<t>[^,;:()]{2,50}?)\s*[:：]\s+(?<d>.{8,220})$/u', $s, $m)) $kind = 'la';
        if ($kind) {
            $term = trim($m['t'], " \"“”'");
            $wc = count(preg_split('/\s+/u', $term));
            $low = mb_strtolower($term);
            $bad = $wc > 8 || $wc < 1 || preg_match('/^\d/u', $term);
            foreach (OFFLINE_STOP as $w) if ($low === $w || str_starts_with($low, $w . ' ')) $bad = true;
            if (!$bad) {
                $d = rtrim(trim($m['d']), ' .;');
                $d = mb_strtoupper(mb_substr($d, 0, 1)) . mb_substr($d, 1);
                $defs[] = ['kind' => $kind, 'term' => $term, 'verb' => $m['v'] ?? '', 'def' => $d, 'src' => $s, 'topic' => $ln['topic']];
                continue;
            }
        }
        if (preg_match('/(?<![\w,\.])(\d+(?:[\.,]\d+)?)\s*(%|[a-zA-Zµ]{1,4}\b|giây|phút|byte|bit|lần|mét|độ)/u', $s, $n, PREG_OFFSET_CAPTURE) && mb_strlen($s) <= 200) {
            $nums[] = ['src' => $s, 'num' => $n[1][0], 'unit' => $n[2][0], 'pos' => $n[1][1], 'topic' => $ln['topic']];
        }
    }
    // bỏ định nghĩa trùng thuật ngữ
    $seen = []; $defs = array_values(array_filter($defs, function ($d) use (&$seen) { $k = mb_strtolower($d['term']); if (isset($seen[$k])) return false; return $seen[$k] = true; }));
    return [$defs, $nums];
}

function offline_relevance(string $s, array $words): int {
    $low = mb_strtolower($s); $n = 0;
    foreach ($words as $w) if (mb_strpos($low, $w) !== false) $n++;
    return $n;
}

function offline_num_variants(string $num, int $k): array {
    $dec = strpos($num, ',') !== false ? ',' : '.';
    $v = (float)str_replace(',', '.', $num);
    $places = strpos($num, $dec) !== false ? strlen(substr($num, strpos($num, $dec) + 1)) : 0;
    $cands = [$v * 2, $v / 2, $v * 10, $v + ($v >= 10 ? 10 : 1), max(0, $v - ($v >= 10 ? 10 : 1)), $v * 4, $v / 10];
    $out = [];
    foreach ($cands as $c) {
        $s = str_replace('.', $dec, number_format($c, $places, '.', ''));
        if ($s !== $num && !in_array($s, $out, true) && $c > 0) $out[] = $s;
        if (count($out) >= $k) break;
    }
    return $out;
}

function offline_generate(array $o): array {
    $src = trim((string)$o['source']);
    if ($src === '') throw new RuntimeException('Chế độ cơ bản (không có API key) cần tài liệu nguồn: chọn nguồn "Bài giảng" hoặc "Văn bản bên trái". '
        . 'Để AI tự soạn theo chủ đề bất kỳ, vào Cài đặt → Trợ lý AI và nhập API key.');
    [$defs, $nums] = offline_facts($src);
    $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string)$o['prompt'])), fn($w) => mb_strlen($w) >= 3));
    usort($defs, fn($a, $b) => offline_relevance($b['src'], $words) <=> offline_relevance($a['src'], $words));
    $existing = array_map('mb_strtolower', $o['existing'] ?? []);
    $k = $o['n_options'] - 1;
    $qs = [];
    foreach ($defs as $i => $d) {
        if (count($qs) >= $o['n']) break;
        $others = array_values(array_filter($defs, fn($x) => $x !== $d));
        shuffle($others);
        usort($others, fn($x, $y) => ($y['topic'] === $d['topic']) <=> ($x['topic'] === $d['topic']));   // phương án nhiễu cùng phần trước
        $reverse = $i % 3 === 2 && count($others) >= $k;
        if ($reverse) {
            $content = 'Khái niệm nào được mô tả như sau: “' . $d['def'] . '”?';
            $opts = array_merge([$d['term']], array_column(array_slice($others, 0, $k), 'term'));
        } else {
            $same = array_values(array_filter($others, fn($x) => $x['kind'] === $d['kind']));
            $pool = array_merge($same, array_filter($others, fn($x) => $x['kind'] !== $d['kind']));
            if (count($pool) < $k) continue;
            $content = $d['kind'] === 'dung' ? $d['term'] . ' ' . $d['verb'] . ' gì?' : $d['term'] . ' là gì?';
            $opts = array_merge([$d['def']], array_column(array_slice($pool, 0, $k), 'def'));
        }
        if (in_array(mb_strtolower($content), $existing, true)) continue;
        $qs[] = offline_pack($content, $opts, 'Theo tài liệu: “' . $d['src'] . '”.', $d['topic'], 'de');
    }
    foreach ($nums as $n) {
        if (count($qs) >= $o['n']) break;
        $vars = offline_num_variants($n['num'], $k);
        if (count($vars) < $k) continue;
        $content = 'Điền vào chỗ trống: ' . substr_replace($n['src'], '____', $n['pos'], strlen($n['num']));
        if (in_array(mb_strtolower($content), $existing, true)) continue;
        $qs[] = offline_pack($content, array_map(fn($x) => $x . ' ' . $n['unit'], array_merge([$n['num']], $vars)), 'Theo tài liệu: “' . $n['src'] . '”.', $n['topic'], 'tb');
    }
    if (!$qs) throw new RuntimeException('Không tìm thấy đủ câu định nghĩa trong tài liệu để tạo câu hỏi ở chế độ cơ bản. '
        . 'Bật Google Gemini (miễn phí), Claude, OpenAI hoặc Ollama trong Cài đặt → Trợ lý AI để AI soạn theo yêu cầu.');
    return $qs;
}

function offline_pack(string $content, array $opts, string $expl, string $topic, string $diff): array {
    $correct = $opts[0];
    shuffle($opts);
    $q = ['content' => $content, 'options' => $opts, 'correct' => LETTERS[array_search($correct, $opts, true)],
          'explanation' => $expl, 'topic' => $topic, 'difficulty' => $diff];
    return norm_question($q, 'ai');
}

function offline_summary(string $text): string {
    [$defs] = offline_facts($text);
    $lines = [];
    foreach (array_slice($defs, 0, 8) as $d) $lines[] = '- ' . $d['term'] . ($d['kind'] === 'dung' ? ' ' . $d['verb'] . ' ' . mb_lcfirst($d['def']) : ': ' . $d['def']);
    if (count($lines) < 3) {
        foreach (offline_lines($text) as $ln) { if (count($lines) >= 6) break; if (mb_strlen($ln['s']) >= 30 && mb_strlen($ln['s']) <= 200) $lines[] = '- ' . $ln['s']; }
    }
    if (!$lines) throw new RuntimeException('Không trích được ý chính từ bài giảng ở chế độ cơ bản.');
    return implode("\n", array_unique($lines));
}

if (!function_exists('mb_lcfirst')) {
    function mb_lcfirst(string $s): string { return mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1); }
}
