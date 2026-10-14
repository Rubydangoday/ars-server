<?php
// Trợ lý AI soạn câu hỏi trắc nghiệm và tóm tắt bài giảng.
//   provider = anthropic : Claude (API key từ console.anthropic.com)
//   provider = openai    : mọi API tương thích OpenAI — OpenAI, Ollama/LM Studio chạy trên máy (không cần Internet), v.v.
//   provider = gemini    : Google Gemini qua cổng tương thích OpenAI (key miễn phí tại aistudio.google.com)
//   provider = offline   : không gọi mạng; tự sinh câu hỏi từ câu định nghĩa trong tài liệu (chất lượng cơ bản)
declare(strict_types=1);

const AI_PROVIDERS = [
    'offline'   => ['Cơ bản (không cần Internet)', ''],
    'anthropic' => ['Claude (Anthropic)', 'claude-sonnet-5'],
    'gemini'    => ['Google Gemini', 'gemini-3.8-flash'],
    'openai'    => ['OpenAI / Ollama / LM Studio', 'gpt-4o-mini'],
];

function ai_conf(): array {
    // Giáo viên chưa nhập mã mời (hoặc học sinh chỉ ở lớp của họ): luôn dùng chế độ Cơ bản, không tốn API key của server
    $p = function_exists('account_limited') && account_limited() ? 'offline' : setting('ai_provider', 'offline');
    if (!isset(AI_PROVIDERS[$p])) $p = 'offline';
    return [
        'provider' => $p,
        'key'      => (string)setting('ai_key_' . $p, ''),
        'model'    => (string)setting('ai_model_' . $p, AI_PROVIDERS[$p][1]),
        'base'     => rtrim((string)setting('ai_base_' . $p, ['openai' => 'https://api.openai.com/v1', 'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai'][$p] ?? 'https://api.anthropic.com'), '/'),
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
         . "- Không lặp lại các câu đã có trong ngân hàng.\n"
         . "- Công thức toán / lý / hoá viết bằng LaTeX đặt trong \\( … \\) (ví dụ \\(\\frac{a}{b}\\), \\(x^2\\), \\(\\ce{H2O}\\)) để hiển thị đẹp trên máy chiếu.\n"
         . "- Viết theo ngôn ngữ giảng viên dùng trong yêu cầu (mặc định tiếng Việt).\n"
         . "- Nếu có “các yêu cầu trước trong cuộc trò chuyện”, hiểu yêu cầu mới là điều chỉnh tiếp (vd “khó hơn” = khó hơn lượt trước) và tạo câu MỚI, khác hẳn các câu đã gợi ý.";
}

/** Trợ lý giảng dạy (hỏi đáp tự do): giáo án, cách giảng, rubric, hoạt động lớp… */
function ai_teacher_system(): string {
    return "Bạn là trợ lý giảng dạy trong ứng dụng lớp học ARS, hỗ trợ giáo viên/giảng viên Việt Nam. "
         . "Giúp: soạn giáo án, ý tưởng hoạt động trên lớp, cách giảng phần khó, ví dụ minh hoạ, rubric chấm tự luận, nhận xét học sinh, phân tích kết quả, câu hỏi thảo luận.\n"
         . "Nguyên tắc: trả lời đúng trọng tâm, thực tế, dùng được ngay trên lớp; trình bày gọn bằng ### tiêu đề nhỏ, gạch đầu dòng, **in đậm** ý chính; "
         . "công thức viết LaTeX trong \\( … \\). Trả lời theo ngôn ngữ giáo viên dùng. Nếu có tài liệu bài giảng thì bám sát tài liệu. "
         . "Đọc lịch sử trò chuyện; khi được yêu cầu làm lại / cách khác thì đổi cách tiếp cận, không lặp lại câu trả lời cũ.";
}

function ai_user_prompt(array $o): string {
    $diff = ['mix' => 'trộn: khoảng 30% dễ (nhớ/hiểu), 50% trung bình (vận dụng), 20% khó (phân tích)', 'de' => 'dễ (nhớ, hiểu khái niệm)',
             'tb' => 'trung bình (vận dụng vào tình huống cụ thể)', 'kho' => 'khó (phân tích, so sánh, tình huống nhiều bước)'][$o['difficulty']] ?? 'trộn';
    $s = "Hãy soạn {$o['n']} câu hỏi trắc nghiệm, mỗi câu {$o['n_options']} phương án, độ khó: $diff.\n";
    if (trim($o['prompt']) !== '') $s .= "Yêu cầu của giảng viên: " . trim($o['prompt']) . "\n";
    if (!empty($o['subject'])) $s .= "Môn/lớp: {$o['subject']}\n";
    if (trim($o['source'] ?? '') !== '') $s .= "\nTài liệu (bài giảng) dùng làm nguồn:\n<tai_lieu>\n" . $o['source'] . "\n</tai_lieu>\n";
    if (!empty($o['history'])) $s .= "\nCác yêu cầu trước trong cuộc trò chuyện (cũ → mới):\n- " . implode("\n- ", array_slice($o['history'], -6)) . "\n";
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
/** Gọi API dịch vụ AI; tự thử lại khi dịch vụ quá tải / vượt hạn mức tạm thời (429, 500, 503, 529): chờ 2 s rồi 5 s. */
function ai_http(string $url, array $headers, array $body, int $timeout = 170): array {
    foreach ([0, 2, 5] as $i => $wait) {
        if ($wait) sleep($wait);
        try { return ai_http_once($url, $headers, $body, $timeout); }
        catch (RuntimeException $e) { if ($i === 2 || !in_array($e->getCode(), [429, 500, 503, 529], true)) throw $e; }
    }
    throw new RuntimeException('Dịch vụ AI không phản hồi.');
}
function ai_http_once(string $url, array $headers, array $body, int $timeout): array {
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
        $hint = $code === 401 ? ' (API key không đúng)' : ($code === 429 ? ' (vượt hạn mức, thử lại sau ít phút)' : ($code === 404 ? ' (sai tên model hoặc địa chỉ)'
              : ($code === 403 ? ' (dự án / tài khoản bị từ chối — tạo key bằng Gmail cá nhân ở dự án mới)' : (in_array($code, [503, 529], true) ? ' (model đang quá tải — thử lại sau hoặc đổi model nhẹ hơn)' : ''))));
        throw new RuntimeException("Dịch vụ AI báo lỗi $code$hint: $msg", $code);
    }
    return $j;
}

/** Gọi mô hình. $user: chuỗi hoặc danh sách [['role'=>'user'|'assistant','content'=>…], …] (hội thoại).
 *  $schema != null → yêu cầu trả JSON theo schema. Trả về ['data' => array|string, 'in' => , 'out' => ]. */
/** Model Gemini dự phòng khi model đang chọn quá tải (503) / vượt hạn mức (429). */
const GEMINI_FALLBACK = ['gemini-3-flash-preview', 'gemini-2.5-flash-lite'];
function ai_call(array $c, string $system, $user, ?array $schema, int $max_tokens = 8000): array {
    if ($c['provider'] === 'gemini' && empty($c['no_fallback'])) {
        try { return ai_call($c + ['no_fallback' => true], $system, $user, $schema, $max_tokens); }
        catch (RuntimeException $e) {
            if (!in_array($e->getCode(), [429, 503, 529], true)) throw $e;
            foreach (array_diff(GEMINI_FALLBACK, [$c['model']]) as $m) {
                try { return ai_call(['model' => $m, 'no_fallback' => true] + $c, $system, $user, $schema, $max_tokens) + ['fallback' => $m]; }
                catch (RuntimeException $e2) { if (!in_array($e2->getCode(), [404, 429, 503, 529], true)) throw $e2; }
            }
            throw $e;
        }
    }
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
    if ($c['provider'] === 'openai' || $c['provider'] === 'gemini') {
        if ($c['provider'] === 'gemini' && $c['key'] === '') throw new RuntimeException('Chưa nhập API key của Gemini. Vào Cài đặt → Trợ lý AI.');
        $sys = $system . ($schema ? "\n\nChỉ trả về một đối tượng JSON hợp lệ theo schema sau, không thêm chữ nào khác:\n" . json_encode($schema, JSON_UNESCAPED_UNICODE) : '');
        $body = ['model' => $c['model'], 'temperature' => 0.7,
                 'messages' => array_merge([['role' => 'system', 'content' => $sys]], $msgs)];
        $auth = $c['key'] !== '' ? ['authorization: Bearer ' . $c['key']] : [];
        if ($schema) {
            // Gemini: đầu ra có cấu trúc (API bảo đảm JSON hợp lệ); dịch vụ khác / khi bị từ chối: chế độ JSON thường
            $body['response_format'] = $c['provider'] === 'gemini' ? ['type' => 'json_schema', 'json_schema' => ['name' => 'ket_qua', 'schema' => $schema]] : ['type' => 'json_object'];
            try { $j = ai_http($c['base'] . '/chat/completions', $auth, $body); }
            catch (RuntimeException $e) {
                if ($e->getCode() !== 400 || $body['response_format']['type'] !== 'json_schema') throw $e;
                $body['response_format'] = ['type' => 'json_object']; $j = ai_http($c['base'] . '/chat/completions', $auth, $body);
            }
            $text = (string)($j['choices'][0]['message']['content'] ?? '');
            try { $data = ai_json_from_text($text); }
            catch (RuntimeException $e) {                 // thử lại một lần, nhắc rõ quy tắc JSON
                $body['messages'][] = ['role' => 'assistant', 'content' => mb_substr($text, 0, 4000)];
                $body['messages'][] = ['role' => 'user', 'content' => 'Câu trả lời trên không phải JSON hợp lệ. Trả lại DUY NHẤT một đối tượng JSON hợp lệ theo schema, không có ```, mọi dấu \\ trong công thức LaTeX phải viết thành \\\\ (ví dụ "\\\\frac{a}{b}").'];
                $j = ai_http($c['base'] . '/chat/completions', $auth, $body);
                $text = (string)($j['choices'][0]['message']['content'] ?? '');
                $data = ai_json_from_text($text);
            }
            return ['data' => $data, 'in' => $j['usage']['prompt_tokens'] ?? null, 'out' => $j['usage']['completion_tokens'] ?? null];
        }
        $j = ai_http($c['base'] . '/chat/completions', $auth, $body);
        $text = (string)($j['choices'][0]['message']['content'] ?? '');
        return ['data' => trim($text), 'in' => $j['usage']['prompt_tokens'] ?? null, 'out' => $j['usage']['completion_tokens'] ?? null];
    }
    throw new RuntimeException('Chế độ cơ bản không gọi mô hình AI.');
}

/**
 * Đọc JSON từ câu trả lời của mô hình. Chịu được: khối ```json, chữ thừa trước/sau, dấu "\" của LaTeX chưa nhân đôi
 * (\frac, \(, \times… — JSON hiểu nhầm \f \t \n), dấu phẩy thừa, câu trả lời bị cắt giữa chừng (giữ các câu hỏi còn nguyên).
 */
function ai_json_from_text(string $t): array {
    $t = trim(preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/m', '', $t));
    foreach ([$t, ai_fix_json($t)] as $try) {
        $j = json_decode($try, true);
        if (is_array($j)) return array_is_list($j) ? ['questions' => $j] : $j;
        $a = strpos($try, '{'); $b = strrpos($try, '}');
        if ($a !== false && $b > $a && is_array($j = json_decode(substr($try, $a, $b - $a + 1), true))) return $j;
        $a = strpos($try, '['); $b = strrpos($try, ']');
        if ($a !== false && $b > $a && is_array($j = json_decode(substr($try, $a, $b - $a + 1), true))) return ['questions' => $j];
    }
    // bị cắt giữa chừng: lấy từng đối tượng câu hỏi còn nguyên
    $fixed = ai_fix_json($t); $qs = [];
    if (preg_match_all('/\{(?:[^{}"]|"(?:[^"\\\\]|\\\\.)*")*\}/u', $fixed, $m))
        foreach ($m[0] as $o) if (is_array($q = json_decode($o, true)) && isset($q['content'])) $qs[] = $q;
    if ($qs) return ['questions' => $qs];
    throw new RuntimeException('Mô hình AI trả về dữ liệu không đúng định dạng. Thử lại hoặc giảm số câu.');
}
/** Sửa lỗi JSON hay gặp ở mô hình: "\" lẻ trong chuỗi (LaTeX), xuống dòng thật trong chuỗi, dấu phẩy thừa trước } ]. */
function ai_fix_json(string $t): string {
    $out = ''; $in = false; $n = strlen($t);
    for ($i = 0; $i < $n; $i++) {
        $ch = $t[$i];
        if (!$in) { $out .= $ch; if ($ch === '"') $in = true; continue; }
        if ($ch === '"') { $out .= $ch; $in = false; continue; }
        if ($ch === "\n") { $out .= '\n'; continue; }
        if ($ch === "\r") continue;
        if ($ch === "\t") { $out .= '\t'; continue; }
        if ($ch !== '\\') { $out .= $ch; continue; }
        $nx = $t[$i + 1] ?? '';
        $nx2 = $t[$i + 2] ?? '';
        if ($nx === '\\' || $nx === '"' || $nx === '/') { $out .= $ch . $nx; $i++; continue; }
        if ($nx === 'u' && preg_match('/^[0-9a-fA-F]{4}$/', substr($t, $i + 2, 4))) { $out .= substr($t, $i, 6); $i += 5; continue; }
        // \n \t \r \b \f là ký tự điều khiển chỉ khi KHÔNG phải đầu một lệnh LaTeX (\neq, \times, \frac, \beta, \right…)
        if (strpos('bfnrt', $nx) !== false && $nx !== '' && !ctype_alpha($nx2)) { $out .= $ch . $nx; $i++; continue; }
        $out .= '\\\\';                                   // "\" lẻ (LaTeX: \frac, \(, \alpha…) → "\\"
    }
    return preg_replace('/,\s*([}\]])/', '$1', $out);
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
