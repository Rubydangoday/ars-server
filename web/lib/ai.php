<?php
// Trợ lý AI soạn câu hỏi trắc nghiệm và tóm tắt bài giảng.
//   provider = anthropic : Claude (API key từ console.anthropic.com)
//   provider = openai    : mọi API tương thích OpenAI — OpenAI, Ollama/LM Studio chạy trên máy (không cần Internet), v.v.
//   provider = offline   : không gọi mạng; tự sinh câu hỏi từ câu định nghĩa trong tài liệu (chất lượng cơ bản)
declare(strict_types=1);

const AI_PROVIDERS = [
    'offline'   => ['Cơ bản (không cần Internet)', ''],
    'anthropic' => ['Claude (Anthropic)', 'claude-sonnet-5'],
    'openai'    => ['OpenAI / Ollama / LM Studio', 'gpt-4o-mini'],
];

function ai_conf(): array {
    $p = setting('ai_provider', 'offline');
    if (!isset(AI_PROVIDERS[$p])) $p = 'offline';
    return [
        'provider' => $p,
        'key'      => (string)setting('ai_key_' . $p, ''),
        'model'    => (string)setting('ai_model_' . $p, AI_PROVIDERS[$p][1]),
        'base'     => rtrim((string)setting('ai_base_' . $p, $p === 'openai' ? 'https://api.openai.com/v1' : 'https://api.anthropic.com'), '/'),
    ];
}
function ai_label(?array $c = null): string {
    $c = $c ?? ai_conf();
    return AI_PROVIDERS[$c['provider']][0] . ($c['provider'] !== 'offline' ? ' · ' . $c['model'] : '');
}

// ---------------------------------------------------------------- lời nhắc
function ai_system_prompt(): string {
    return "Bạn là trợ lý soạn đề của giảng viên đại học Việt Nam. Bạn soạn câu hỏi trắc nghiệm một đáp án đúng, "
         . "dùng cho hệ thống bấm chọn (clicker) chiếu lên màn hình và cho học sinh ôn tập trên web.\n"
         . "Quy tắc:\n"
         . "- Viết bằng tiếng Việt chuẩn, rõ ràng, đúng thuật ngữ chuyên ngành (giữ nguyên thuật ngữ tiếng Anh thông dụng như MQTT, QoS, BLE).\n"
         . "- Mỗi câu có đúng MỘT phương án đúng; các phương án nhiễu hợp lý, cùng độ dài và cùng dạng với đáp án, dựa trên lỗi hiểu sai thường gặp.\n"
         . "- Không dùng \"Tất cả đều đúng\", \"Cả A và B\", không phủ định kép, không đánh đố bằng câu chữ.\n"
         . "- Phương án ngắn gọn (tối đa khoảng 120 ký tự) để đọc được trên máy chiếu; không ghi chữ cái A/B/C trước phương án.\n"
         . "- Vị trí đáp án đúng phân bố đều giữa các chữ cái.\n"
         . "- Giải thích 1–3 câu: vì sao đáp án đúng và vì sao phương án nhiễu dễ gây nhầm nhất là sai.\n"
         . "- Chủ đề (topic) là cụm 2–6 từ, dùng lại cùng một tên cho các câu cùng phần kiến thức.\n"
         . "- Nếu có tài liệu, chỉ hỏi những gì có trong tài liệu; không bịa số liệu.\n"
         . "- Không lặp lại các câu đã có trong ngân hàng.";
}

function ai_user_prompt(array $o): string {
    $diff = ['mix' => 'trộn: khoảng 30% dễ (nhớ/hiểu), 50% trung bình (vận dụng), 20% khó (phân tích)', 'de' => 'dễ (nhớ, hiểu khái niệm)',
             'tb' => 'trung bình (vận dụng vào tình huống cụ thể)', 'kho' => 'khó (phân tích, so sánh, tình huống nhiều bước)'][$o['difficulty']] ?? 'trộn';
    $s = "Hãy soạn {$o['n']} câu hỏi trắc nghiệm, mỗi câu {$o['n_options']} phương án, độ khó: $diff.\n";
    if (trim($o['prompt']) !== '') $s .= "Yêu cầu của giảng viên: " . trim($o['prompt']) . "\n";
    if (!empty($o['subject'])) $s .= "Môn/lớp: {$o['subject']}\n";
    if (trim($o['source'] ?? '') !== '') $s .= "\nTài liệu (bài giảng) dùng làm nguồn:\n<tai_lieu>\n" . $o['source'] . "\n</tai_lieu>\n";
    if (!empty($o['existing'])) $s .= "\nCâu đã có trong ngân hàng (không lặp lại):\n- " . implode("\n- ", array_slice($o['existing'], 0, 60)) . "\n";
    return $s;
}

function ai_question_schema(int $n_options): array {
    return [
        'type' => 'object',
        'properties' => [
            'questions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'content'     => ['type' => 'string', 'description' => 'Nội dung câu hỏi'],
                        'options'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => "Đúng $n_options phương án, không kèm chữ cái"],
                        'correct'     => ['type' => 'string', 'description' => 'Chữ cái của đáp án đúng: A, B, C…'],
                        'explanation' => ['type' => 'string'],
                        'topic'       => ['type' => 'string'],
                        'difficulty'  => ['type' => 'string', 'enum' => ['de', 'tb', 'kho']],
                    ],
                    'required' => ['content', 'options', 'correct', 'explanation', 'topic', 'difficulty'],
                ],
            ],
        ],
        'required' => ['questions'],
    ];
}

// ---------------------------------------------------------------- gọi API
function ai_http(string $url, array $headers, array $body, int $timeout = 170): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Máy chủ thiếu PHP cURL (sudo apt install php-curl rồi khởi động lại Apache).');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => array_merge(['content-type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Không kết nối được tới dịch vụ AI: ' . $err . '. Kiểm tra Internet hoặc địa chỉ máy chủ AI trong Cài đặt.');
    $j = json_decode((string)$raw, true);
    if ($code >= 400 || !is_array($j)) {
        $msg = is_array($j) ? ($j['error']['message'] ?? $j['error'] ?? json_encode($j)) : mb_substr((string)$raw, 0, 200);
        if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        $hint = $code === 401 ? ' (API key không đúng)' : ($code === 429 ? ' (vượt hạn mức, thử lại sau ít phút)' : ($code === 404 ? ' (sai tên model hoặc địa chỉ)' : ''));
        throw new RuntimeException("Dịch vụ AI báo lỗi $code$hint: $msg");
    }
    return $j;
}

/** Gọi mô hình. $user: chuỗi hoặc danh sách [['role'=>'user'|'assistant','content'=>…], …] (hội thoại).
 *  $schema != null → yêu cầu trả JSON theo schema. Trả về ['data' => array|string, 'in' => , 'out' => ]. */
function ai_call(array $c, string $system, $user, ?array $schema, int $max_tokens = 8000): array {
    $msgs = is_array($user) ? array_values($user) : [['role' => 'user', 'content' => $user]];
    if ($c['provider'] === 'anthropic') {
        if ($c['key'] === '') throw new RuntimeException('Chưa nhập API key của Claude. Vào Cài đặt → Trợ lý AI.');
        $body = ['model' => $c['model'], 'max_tokens' => $max_tokens, 'system' => $system,
                 'messages' => $msgs];
        if ($schema) {
            $body['tools'] = [['name' => 'luu_ket_qua', 'description' => 'Lưu kết quả theo đúng cấu trúc', 'input_schema' => $schema]];
            $body['tool_choice'] = ['type' => 'tool', 'name' => 'luu_ket_qua'];
        }
        $j = ai_http($c['base'] . '/v1/messages', ['x-api-key: ' . $c['key'], 'anthropic-version: 2023-06-01'], $body);
        $data = null; $text = '';
        foreach ($j['content'] ?? [] as $blk) {
            if (($blk['type'] ?? '') === 'tool_use') $data = $blk['input'];
            if (($blk['type'] ?? '') === 'text') $text .= $blk['text'];
        }
        if ($schema && $data === null) $data = ai_json_from_text($text);
        return ['data' => $schema ? $data : trim($text), 'in' => $j['usage']['input_tokens'] ?? null, 'out' => $j['usage']['output_tokens'] ?? null];
    }
    if ($c['provider'] === 'openai') {
        $sys = $system . ($schema ? "\n\nChỉ trả về một đối tượng JSON hợp lệ theo schema sau, không thêm chữ nào khác:\n" . json_encode($schema, JSON_UNESCAPED_UNICODE) : '');
        $body = ['model' => $c['model'], 'temperature' => 0.7,
                 'messages' => array_merge([['role' => 'system', 'content' => $sys]], $msgs)];
        if ($schema) $body['response_format'] = ['type' => 'json_object'];
        $j = ai_http($c['base'] . '/chat/completions', $c['key'] !== '' ? ['authorization: Bearer ' . $c['key']] : [], $body);
        $text = (string)($j['choices'][0]['message']['content'] ?? '');
        return ['data' => $schema ? ai_json_from_text($text) : trim($text), 'in' => $j['usage']['prompt_tokens'] ?? null, 'out' => $j['usage']['completion_tokens'] ?? null];
    }
    throw new RuntimeException('Chế độ cơ bản không gọi mô hình AI.');
}

function ai_json_from_text(string $t): array {
    $t = trim(preg_replace('/^```(?:json)?|```$/m', '', $t));
    $j = json_decode($t, true);
    if (is_array($j)) return $j;
    $a = strpos($t, '{'); $b = strrpos($t, '}');
    if ($a !== false && $b > $a && is_array($j = json_decode(substr($t, $a, $b - $a + 1), true))) return $j;
    $a = strpos($t, '['); $b = strrpos($t, ']');
    if ($a !== false && $b > $a && is_array($j = json_decode(substr($t, $a, $b - $a + 1), true))) return ['questions' => $j];
    throw new RuntimeException('Mô hình AI trả về dữ liệu không đúng định dạng. Thử lại hoặc giảm số câu.');
}

/** Chuẩn hoá một câu hỏi (từ AI hoặc từ file) về dạng chung của web. */
function norm_question(array $q, string $origin): array {
    $opts = array_values(array_filter(array_map(fn($o) => trim(preg_replace('/^\s*[A-J][\.\):]\s+/u', '', (string)$o)), (array)($q['options'] ?? [])), fn($o) => $o !== ''));
    $opts = array_slice($opts, 0, 10);
    $cor = strtoupper(trim((string)($q['correct'] ?? '')));
    if (ctype_digit($cor) && (int)$cor >= 1 && (int)$cor <= count($opts)) $cor = LETTERS[(int)$cor - 1];
    if (strlen($cor) > 1) {                                   // mô hình trả nguyên văn đáp án
        $i = array_search(mb_strtolower(trim((string)$q['correct'])), array_map('mb_strtolower', $opts), true);
        $cor = $i !== false ? LETTERS[$i] : substr($cor, 0, 1);
    }
    $issues = $q['issues'] ?? [];
    if (count($opts) < 2) $issues[] = 'cần ít nhất 2 phương án';
    if ($cor !== '' && strpos(substr(LETTERS, 0, count($opts)), $cor) === false) { $issues[] = 'đáp án đúng không khớp phương án'; $cor = ''; }
    if ($cor === '') $issues[] = 'chưa có đáp án đúng';
    $d = (string)($q['difficulty'] ?? '');
    return [
        'content' => trim((string)($q['content'] ?? '')),
        'options' => $opts,
        'correct' => $cor,
        'explanation' => trim((string)($q['explanation'] ?? '')),
        'topic' => mb_substr(trim((string)($q['topic'] ?? '')), 0, 100),
        'difficulty' => isset(DIFFICULTY[$d]) ? $d : '',
        'origin' => $origin,
        'issues' => array_values(array_unique($issues)),
    ];
}

function ai_log(string $task, array $c, string $prompt, int $n, ?array $r, ?string $err, int $ms): void {
    try {
        q('INSERT INTO ai_log (teacher_id, provider, model, task, prompt, n_out, tokens_in, tokens_out, ms, error) VALUES (?,?,?,?,?,?,?,?,?,?)',
          [me()['id'] ?? 0, $c['provider'], $c['model'] ?: null, $task, mb_substr($prompt, 0, 1000), $n, $r['in'] ?? null, $r['out'] ?? null, $ms, $err ? mb_substr($err, 0, 500) : null]);
    } catch (Throwable $e) { /* nhật ký không bắt buộc */ }
}

/** Soạn câu hỏi. $o: prompt, n, n_options, difficulty (mix|de|tb|kho), source (văn bản), existing (câu đã có), subject */
function ai_generate(array $o): array {
    $c = ai_conf();
    $o['n'] = max(1, min(20, (int)$o['n']));
    $o['n_options'] = max(2, min(10, (int)$o['n_options']));
    $limit = $c['provider'] === 'anthropic' ? 120000 : 24000;
    $o['source'] = mb_substr(trim((string)($o['source'] ?? '')), 0, $limit);
    $t0 = microtime(true);
    if ($c['provider'] === 'offline') {
        require_once __DIR__ . '/ai_offline.php';
        $qs = offline_generate($o);
        ai_log('questions', $c, (string)$o['prompt'], count($qs), null, null, (int)((microtime(true) - $t0) * 1000));
        return ['questions' => $qs, 'provider' => ai_label($c),
                'note' => $qs ? 'Chế độ cơ bản: câu hỏi được tạo từ các câu định nghĩa trong tài liệu, nên đọc lại trước khi dùng. Thêm API key trong Cài đặt để AI soạn theo yêu cầu.'
                              : ''];
    }
    try {
        $r = ai_call($c, ai_system_prompt(), ai_user_prompt($o), ai_question_schema($o['n_options']));
        $list = $r['data']['questions'] ?? (array_is_list((array)$r['data']) ? $r['data'] : []);
        $qs = [];
        foreach ($list as $q) if (is_array($q)) { $nq = norm_question($q, 'ai'); if ($nq['content'] !== '') $qs[] = $nq; }
        if (!$qs) throw new RuntimeException('Mô hình AI không trả về câu hỏi nào. Thử diễn đạt yêu cầu cụ thể hơn.');
        ai_log('questions', $c, (string)$o['prompt'], count($qs), $r, null, (int)((microtime(true) - $t0) * 1000));
        return ['questions' => $qs, 'provider' => ai_label($c), 'note' => '', 'tokens' => ($r['in'] ?? 0) + ($r['out'] ?? 0)];
    } catch (Throwable $e) {
        ai_log('questions', $c, (string)$o['prompt'], 0, null, $e->getMessage(), (int)((microtime(true) - $t0) * 1000));
        throw $e;
    }
}

/** Tóm tắt kiến thức trọng tâm của bài giảng: trả về các dòng "- ...". */
function ai_summary(string $text, string $title = ''): string {
    $c = ai_conf();
    $text = trim($text);
    if ($text === '') throw new RuntimeException('Buổi này chưa có nội dung bài giảng để tóm tắt (tải file bài giảng trước).');
    $t0 = microtime(true);
    if ($c['provider'] === 'offline') {
        require_once __DIR__ . '/ai_offline.php';
        $s = offline_summary($text);
        ai_log('summary', $c, $title, 1, null, null, (int)((microtime(true) - $t0) * 1000));
        return $s;
    }
    try {
        $r = ai_call($c, 'Bạn là trợ lý giảng dạy đại học. Viết bằng tiếng Việt, ngắn gọn, chính xác.',
            "Tóm tắt KIẾN THỨC TRỌNG TÂM của bài giảng" . ($title ? " \"$title\"" : '') . " thành 5–8 ý, mỗi ý một dòng bắt đầu bằng \"- \", "
            . "tối đa khoảng 30 từ mỗi ý, ưu tiên định nghĩa, công thức, so sánh và lưu ý hay nhầm. Chỉ trả về danh sách.\n\n<tai_lieu>\n"
            . mb_substr($text, 0, $c['provider'] === 'anthropic' ? 120000 : 24000) . "\n</tai_lieu>", null, 1500);
        ai_log('summary', $c, $title, 1, $r, null, (int)((microtime(true) - $t0) * 1000));
        return (string)$r['data'];
    } catch (Throwable $e) {
        ai_log('summary', $c, $title, 0, null, $e->getMessage(), (int)((microtime(true) - $t0) * 1000));
        throw $e;
    }
}
