<?php
// Tách câu hỏi trắc nghiệm từ văn bản dán vào, file Word (.docx) hoặc Excel (.xlsx/.csv).
// Nhận các cách đánh dấu đáp án phổ biến trong đề tiếng Việt:
//   dấu * trước phương án (*B. …), phương án gạch chân / tô đỏ / tô sáng trong Word,
//   dòng "Đáp án: B", bảng đáp án cuối đề (1.A 2.C … hoặc 1-A, Câu 1: A).
declare(strict_types=1);
require_once __DIR__ . '/ai.php';     // norm_question()

/** Đoạn văn của file Word: [['text' => , 'marks' => [[start, end, 'u'|'b'], ...], 'heading' => bool, 'img' => bool], ...] */
function docx_paragraphs(string $path): array {
    $z = new ZipArchive();
    if ($z->open($path) !== true) throw new RuntimeException('File .docx hỏng hoặc không đọc được.');
    $xml = $z->getFromName('word/document.xml');
    $sxml = $z->getFromName('word/styles.xml');
    $z->close();
    if ($xml === false) throw new RuntimeException('Không thấy nội dung trong file .docx.');
    $dom = new DOMDocument();
    $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    $xp = new DOMXPath($dom);
    $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $xp->registerNamespace('w', $W);
    // kiểu đoạn có đánh số sẵn (List Number, List Number 2…) → cấp danh sách
    $style_lvl = [];
    if ($sxml !== false) {
        $sd = new DOMDocument(); $sd->loadXML($sxml, LIBXML_NONET);
        $sx = new DOMXPath($sd); $sx->registerNamespace('w', $W);
        foreach ($sx->query('//w:style[w:pPr/w:numPr]') as $st) {
            $id = $st->getAttributeNS($W, 'styleId');
            $il = $sx->query('w:pPr/w:numPr/w:ilvl', $st)->item(0);
            $style_lvl[$id] = $il ? (int)$il->getAttributeNS($W, 'val') : (preg_match('/[2-9]$/', $id) ? 1 : 0);
        }
    }
    $out = [];
    foreach ($xp->query('//w:body//w:p') as $p) {
        $text = ''; $marks = [];
        $style = (string)($xp->evaluate('string(w:pPr/w:pStyle/@w:val)', $p));
        $num = $xp->query('w:pPr/w:numPr', $p)->length > 0;
        $lvl = (int)$xp->evaluate('number(w:pPr/w:numPr/w:ilvl/@w:val)', $p);
        $lvl = $num ? ($lvl > 0 ? $lvl : 0) : ($style_lvl[$style] ?? -1);
        $img = $xp->query('.//w:drawing|.//w:pict|.//w:object', $p)->length > 0;
        foreach ($xp->query('.//w:r|.//w:tab|.//w:br', $p) as $node) {
            if ($node->localName !== 'r') continue;
            $t = '';
            foreach ($node->childNodes as $ch) {
                if ($ch->localName === 't') $t .= $ch->textContent;
                elseif ($ch->localName === 'tab') $t .= "\t";
                elseif ($ch->localName === 'br') $t .= "\n";
            }
            if ($t === '') continue;
            $rpr = $xp->query('w:rPr', $node)->item(0);
            $kind = '';
            if ($rpr) {
                $u = $xp->query('w:u', $rpr)->item(0);
                $uval = $u ? $u->getAttributeNS($W, 'val') : '';
                $color = strtoupper((string)$xp->evaluate('string(w:color/@w:val)', $rpr));
                $hl = $xp->query('w:highlight|w:shd[@w:fill and @w:fill!="auto" and @w:fill!="FFFFFF"]', $rpr)->length > 0;
                $red = preg_match('/^[0-9A-F]{6}$/', $color) && hexdec(substr($color, 0, 2)) >= 0xB0 && hexdec(substr($color, 2, 2)) < 0x70 && hexdec(substr($color, 4, 2)) < 0x70;
                if (($u && $uval !== 'none') || $red || $hl) $kind = 'u';
                elseif ($xp->query('w:b[not(@w:val) or (@w:val!="0" and @w:val!="false")]', $rpr)->length) $kind = 'b';
            }
            $start = mb_strlen($text);
            $text .= $t;
            if ($kind && trim($t) !== '') $marks[] = [$start, mb_strlen($text), $kind];
        }
        $heading = (bool)preg_match('/^(heading|tieude|title|u)\d?|^Heading/i', $style) && !preg_match('/^u/i', $style);
        // mỗi dòng ngắt (Shift+Enter) coi như một đoạn riêng, giữ nguyên định dạng
        $parts = explode("\n", $text); $off = 0;
        foreach ($parts as $pt) {
            $len = mb_strlen($pt);
            $pm = [];
            foreach ($marks as [$a, $b, $k]) if ($b > $off && $a < $off + $len) $pm[] = [max(0, $a - $off), min($len, $b - $off), $k];
            $out[] = ['text' => $pt, 'marks' => $pm, 'heading' => $heading, 'list' => $num, 'lvl' => $lvl, 'img' => $img];
            $off += $len + 1;
        }
    }
    return $out;
}

/** Văn bản thuần → cùng dạng đoạn như docx_paragraphs (không có định dạng). */
function text_paragraphs(string $text): array {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return array_map(fn($l) => ['text' => $l, 'marks' => [], 'heading' => false, 'list' => false, 'lvl' => -1, 'img' => false], explode("\n", $text));
}

const RX_QSTART = '/^\s*(?:(?:câu\s*hỏi|câu|question|bài|q)\s*(\d{1,3})\s*[\.:\)\-–]?|(\d{1,3})[\.\)](?=\s)|(\d{1,3})\s*[\-–:]\s)\s*(.*)$/iu';
const RX_ANSWER_LINE = '/^\s*(?:đáp\s*án(?:\s*đúng)?|đa|answer|key|chọn|trả\s*lời)\s*[:\.\-–]?\s*\(?([A-J])\)?\s*[\.\)]?\s*$/iu';
const RX_EXPLAIN = '/^\s*(?:giải\s*thích|lời\s*giải|hướng\s*dẫn(?:\s*giải)?|hdg|explanation|gợi\s*ý)\s*[:\.\-–]\s*(.*)$/iu';
const RX_TOPIC = '/^\s*(?:chủ\s*đề|topic|phần|chương|bài\s*học)\s*[:\.\-–]?\s*(.{2,100})$/iu';

/** Vị trí các nhãn phương án A., B), (C) … theo đúng thứ tự trong một dòng. Trả về [[letter, start, bodyStart, star], ...] */
function option_markers(string $line, string $expect): array {
    $res = [];
    if (!preg_match_all('/(?:^|(?<=\s))(\*?)\(?([A-J])\s*[\.\):]\s*(\*?)/u', $line, $m, PREG_OFFSET_CAPTURE)) return [];
    foreach ($m[0] as $i => $whole) {
        $L = $m[2][$i][0];
        if ($L !== $expect) continue;
        $start = mb_strlen(substr($line, 0, $whole[1]));
        $res[] = [$L, $start, $start + mb_strlen($whole[0]), $m[1][$i][0] === '*' || $m[3][$i][0] === '*'];
        $expect = chr(ord($expect) + 1);
    }
    return $res;
}

/** Tách câu hỏi từ danh sách đoạn. Trả về ['questions' => [...], 'warnings' => [...]] */
function parse_paragraphs(array $paras, string $origin): array {
    $qs = []; $cur = null; $topic = ''; $key = []; $warn = []; $in_key = false; $mode = 'content'; $pend = null; $auto = 0;
    $flush = function () use (&$cur, &$qs) { if ($cur) $qs[] = $cur; $cur = null; };
    foreach ($paras as $pi => $p) {
        $raw = str_replace("\t", ' ', $p['text']);          // giữ nguyên độ dài để vị trí định dạng khớp
        $line = trim($raw);
        if ($line === '') continue;
        $lead = mb_strlen($raw) - mb_strlen(ltrim($raw));
        if ($lead) $p['marks'] = array_map(fn($x) => [$x[0] - $lead, $x[1] - $lead, $x[2]], $p['marks']);
        // bảng đáp án cuối đề
        if (preg_match('/^\s*(?:bảng\s*)?đáp\s*án\s*(?:tham\s*khảo)?\s*[:\.]?\s*$/iu', $line)) { $flush(); $in_key = true; continue; }
        if (preg_match_all('/(?:câu\s*)?(\d{1,3})\s*[\.\-:\)]?\s*([A-J])(?![\p{L}])/iu', $line, $km, PREG_SET_ORDER) >= 2 || ($in_key && $km)) {
            if (count($km) >= 2 || $in_key) {
                $rest = trim(preg_replace('/(?:câu\s*)?\d{1,3}\s*[\.\-:\)]?\s*[A-J](?![\p{L}])[\s,;|]*/iu', '', $line));
                if ($rest === '' || $in_key) { foreach ($km as $x) $key[(int)$x[1]] = strtoupper($x[2]); continue; }
            }
        }
        if ($in_key) {                                        // bảng đáp án dạng bảng Word: ô số rồi ô chữ
            if (preg_match('/^(\d{1,3})$/', $line, $m)) { $pend = (int)$m[1]; continue; }
            if ($pend !== null && preg_match('/^([A-J])$/i', $line, $m)) { $key[$pend] = strtoupper($m[1]); $pend = null; continue; }
            if (preg_match('/^(câu|đáp\s*án|stt)$/iu', $line)) continue;
        }
        if ($p['heading'] && !preg_match(RX_QSTART, $line)) { $flush(); $topic = mb_substr($line, 0, 100); continue; }
        // câu hỏi đánh số tự động của Word (danh sách cấp 1), phương án là danh sách cấp 2
        if (($p['lvl'] ?? -1) === 0 && !preg_match(RX_QSTART, $line) && !option_markers($line, 'A') && (!$cur || $cur['options'] || $mode !== 'content')) {
            $auto = max($auto, $cur['no'] ?? 0, $qs ? end($qs)['no'] : 0) + 1;
            $line = 'Câu ' . $auto . ': ' . $line;
        } elseif (($p['lvl'] ?? -1) >= 1 && $cur && !option_markers($line, chr(ord('A') + count($cur['options'])))) {
            $L = chr(ord('A') + count($cur['options']));
            $shift = mb_strlen($L . '. ');
            $p['marks'] = array_map(fn($x) => [$x[0] + $shift, $x[1] + $shift, $x[2]], $p['marks']);
            $line = $L . '. ' . $line;
        }
        if (preg_match(RX_TOPIC, $line, $m) && (!$cur || $mode !== 'content')) { $flush(); $topic = trim($m[1]); continue; }
        if ($cur && preg_match(RX_ANSWER_LINE, $line, $m)) { $cur['correct'] = strtoupper($m[1]); $cur['correct_src'] = 'line'; $mode = 'after'; continue; }
        if ($cur && preg_match(RX_EXPLAIN, $line, $m)) { $cur['explanation'] = trim($m[1]); $mode = 'expl'; continue; }
        $expect = $cur ? chr(ord('A') + count($cur['options'])) : 'A';
        $mk = $cur && $mode !== 'expl' ? option_markers($line, $expect) : [];
        if ($cur && $mk && $mk[0][1] === 0) {
            // một hoặc nhiều phương án trên dòng này
            foreach ($mk as $i => [$L, $s, $bs, $star]) {
                $end = isset($mk[$i + 1]) ? $mk[$i + 1][1] : mb_strlen($line);
                $body = trim(mb_substr($line, $bs, $end - $bs));
                $fmt = '';
                foreach ($p['marks'] as [$a, $b, $k]) {
                    if ($b > $s && $a < $end && trim(mb_substr($line, max($a, $s), min($b, $end) - max($a, $s))) !== '') $fmt = $fmt === 'u' ? 'u' : $k;
                }
                $cur['options'][] = $body;
                $cur['fmt'][] = $star ? 'star' : $fmt;
            }
            $mode = 'opts';
            continue;
        }
        if (preg_match(RX_QSTART, $line, $m) && ($mode !== 'content' || !$cur || $cur['options'])) {
            $flush();
            $no = (int)($m[1] ?: ($m[2] ?: $m[3]));
            $cur = ['no' => $no, 'content' => trim($m[4]), 'options' => [], 'fmt' => [], 'correct' => '', 'explanation' => '', 'topic' => $topic, 'img' => $p['img'], 'correct_src' => ''];
            $mode = 'content';
            // phương án nằm cùng dòng với câu hỏi: "Câu 1: … A. … B. …"
            $mk = option_markers($cur['content'], 'A');
            if (count($mk) >= 2) {
                $txt = $cur['content'];
                $cur['content'] = trim(mb_substr($txt, 0, $mk[0][1]));
                foreach ($mk as $i => [$L, $s, $bs, $star]) {
                    $end = isset($mk[$i + 1]) ? $mk[$i + 1][1] : mb_strlen($txt);
                    $cur['options'][] = trim(mb_substr($txt, $bs, $end - $bs)); $cur['fmt'][] = $star ? 'star' : '';
                }
                $mode = 'opts';
            }
            continue;
        }
        if (!$cur) continue;                                  // chữ trước câu 1 (tiêu đề đề thi…)
        if ($mode === 'content') { $cur['content'] .= "\n" . $line; $cur['img'] = $cur['img'] || $p['img']; }
        elseif ($mode === 'expl') $cur['explanation'] .= "\n" . $line;
        elseif ($mode === 'opts' && $cur['options']) $cur['options'][count($cur['options']) - 1] .= ' ' . $line;   // phương án xuống dòng
    }
    $flush();

    $out = [];
    foreach ($qs as $i => $q) {
        $issues = [];
        $cor = $q['correct'];
        if ($cor === '' && isset($key[$q['no']])) $cor = $key[$q['no']];
        if ($cor === '' && ($j = array_search('star', $q['fmt'], true)) !== false) $cor = LETTERS[$j];
        if ($cor === '') {
            $u = array_keys($q['fmt'], 'u', true);
            if (count($u) === 1) $cor = LETTERS[$u[0]];
            elseif (count($u) > 1) $issues[] = 'nhiều phương án được gạch chân/tô màu — kiểm tra lại đáp án';
        }
        if ($cor === '') { $b = array_keys($q['fmt'], 'b', true); if (count($b) === 1) $cor = LETTERS[$b[0]]; }
        if ($q['img']) $issues[] = 'câu có hình ảnh trong file gốc — hình không được nhập';
        if (count($q['options']) > 10) $issues[] = 'hơn 10 phương án, chỉ giữ 10';
        $out[] = norm_question(['content' => $q['content'], 'options' => $q['options'], 'correct' => $cor, 'explanation' => $q['explanation'],
                                'topic' => $q['topic'], 'issues' => $issues], $origin) + ['no' => $q['no']];
    }
    if (!$out) $warn[] = 'Không tìm thấy câu hỏi nào. Mỗi câu cần bắt đầu bằng "Câu 1:" (hoặc "1.") và các phương án bắt đầu bằng "A.", "B."…';
    $no_ans = count(array_filter($out, fn($q) => $q['correct'] === ''));
    if ($no_ans) $warn[] = "$no_ans câu chưa xác định được đáp án đúng: bấm Sửa để chọn, hoặc đánh dấu * trước phương án đúng.";
    return ['questions' => $out, 'warnings' => $warn];
}

/** Câu hỏi từ sheet Excel theo mẫu của web (sheet "Cau hoi"). */
function parse_sheet_rows(array $rows): array {
    require_once __DIR__ . '/sheet.php';
    $out = [];
    foreach (data_rows($rows) as $line => $r) {
        $n = (int)($r[2] ?? 0);
        $opts = array_slice(array_pad($r, 15, ''), 3, 10);
        if ($n >= 2) $opts = array_slice($opts, 0, $n);
        $opts = array_values(array_filter(array_map('trim', $opts), fn($o) => $o !== ''));
        $q = norm_question(['content' => $r[1] ?? '', 'options' => $opts, 'correct' => $r[13] ?? '', 'explanation' => $r[16] ?? '', 'topic' => $r[15] ?? ''], 'excel');
        if ($q['content'] === '') continue;
        $tl = trim((string)($r[14] ?? ''));
        $q['time_limit'] = ctype_digit($tl) ? (int)$tl : null;
        $q['no'] = $line;
        $out[] = $q;
    }
    return ['questions' => $out, 'warnings' => $out ? [] : ['Không đọc được câu hỏi nào. Dùng file mẫu (sheet "Cau hoi": cột B nội dung, C số lựa chọn, D–M phương án, N đáp án, P chủ đề, Q giải thích).']];
}

/** Điểm vào chung cho file tải lên. */
function parse_upload(string $tmp, string $name): array {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        require_once __DIR__.'/extract.php';
        $text=extract_pdf($tmp);
        if(trim($text)==='') throw new RuntimeException('PDF không có lớp chữ hoặc thiếu pdftotext. PDF scan cần OCR trước.');
        $r=parse_paragraphs(text_paragraphs($text), 'pdf');
        $r['warnings'][]='Kiểm tra thứ tự cột, công thức và đáp án PDF trước khi thêm; không tự trích ảnh/OCR.';return $r;
    }
    if ($ext === 'docx') return parse_paragraphs(docx_paragraphs($tmp), 'word');
    if ($ext === 'xlsx' || $ext === 'csv') {
        require_once __DIR__ . '/sheet.php';
        $sheets = sheet_read($tmp, $name);
        $rows = $sheets['Cau hoi'] ?? $sheets['Câu hỏi'] ?? reset($sheets);
        return parse_sheet_rows($rows);
    }
    if ($ext === 'txt' || $ext === 'md') return parse_paragraphs(text_paragraphs(file_get_contents($tmp)), 'text');
    if ($ext === 'doc') throw new RuntimeException('File .doc (Word 97–2003) chưa hỗ trợ: mở bằng Word rồi Lưu thành .docx.');
    throw new RuntimeException('Chỉ nhận file .docx, .xlsx, .csv, .pdf hoặc .txt.');
}
