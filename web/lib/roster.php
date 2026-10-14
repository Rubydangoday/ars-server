<?php
// Đọc danh sách học sinh từ file giáo viên tải lên: Excel (.xlsx), CSV, Word (.docx — bảng hoặc từng dòng), văn bản (.txt).
// Tự nhận cột theo tiêu đề (có dấu hoặc không): STT / Số, MSSV / Mã HS, Họ tên — hoặc "Họ đệm" + "Tên" tách hai cột như file
// xuất từ phần mềm quản lý trường — và SĐT phụ huynh (tên đăng nhập của phụ huynh, lib/parent_accounts.php). Không có tiêu đề thì đoán theo nội dung; thiếu số thứ tự thì tự đánh số 1, 2, 3… (bỏ số đã dùng).
declare(strict_types=1);
require_once __DIR__ . '/sheet.php';

/** Chữ thường, bỏ dấu tiếng Việt, gộp khoảng trắng — để so tiêu đề cột. */
function roster_fold(string $s): string {
    static $tr = null;
    if ($tr === null) {
        $tr = [];
        foreach (['a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ',
                  'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ'] as $to => $from)
            foreach (mb_str_split($from) as $ch) $tr[$ch] = $to;
    }
    return trim(preg_replace('/[^a-z0-9]+/', ' ', strtr(mb_strtolower(trim($s)), $tr)));
}

/** File → bảng (mảng các dòng, mỗi dòng mảng ô). */
function roster_table(string $path, string $name): array {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'xlsx' || $ext === 'csv') {
        $sheets = sheet_read($path, $name);
        return $sheets['Danh sach lop'] ?? $sheets['Danh sách lớp'] ?? reset($sheets);
    }
    if ($ext === 'docx') return roster_docx($path);
    if ($ext === 'txt') return roster_text((string)file_get_contents($path));
    if ($ext === 'doc') throw new RuntimeException('File .doc (Word 97–2003) chưa hỗ trợ: mở bằng Word rồi "Lưu thành" .docx.');
    throw new RuntimeException('Chỉ nhận file .xlsx, .csv, .docx hoặc .txt.');
}

/** Word: bảng đầu tiên có ≥ 2 dòng; không có bảng thì đọc từng đoạn văn như file .txt. */
function roster_docx(string $path): array {
    $z = new ZipArchive();
    if ($z->open($path) !== true) throw new RuntimeException('File .docx hỏng hoặc không đọc được.');
    $xml = $z->getFromName('word/document.xml');
    $z->close();
    if ($xml === false) throw new RuntimeException('Không thấy nội dung trong file .docx.');
    $dom = new DOMDocument();
    $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $text = fn(DOMNode $n) => trim(implode(' ', array_map(fn($p) => implode('', array_map(fn($t) => $t->textContent, iterator_to_array($xp->query('.//w:t', $p)))),
                                                          iterator_to_array($xp->query('.//w:p', $n)))));
    foreach ($xp->query('//w:tbl') as $tbl) {
        $rows = [];
        foreach ($xp->query('w:tr', $tbl) as $tr) $rows[] = array_map($text, iterator_to_array($xp->query('w:tc', $tr)));
        if (count($rows) >= 2) return $rows;
    }
    return roster_text(implode("\n", array_map(fn($p) => implode('', array_map(fn($t) => $t->textContent, iterator_to_array($xp->query('.//w:t', $p)))),
                                               iterator_to_array($xp->query('//w:body//w:p')))));
}

/** Văn bản: mỗi dòng một học sinh. Tách theo tab ; | (hoặc dấu phẩy); không có dấu tách thì nhận dạng
 *  "12. 23134001 Nguyễn Văn A", "23134001 - Nguyễn Văn A", "Nguyễn Văn A". */
function roster_text(string $txt): array {
    if (str_starts_with($txt, "\xEF\xBB\xBF")) $txt = substr($txt, 3);
    $rows = [];
    foreach (preg_split('/\R/u', $txt) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        foreach (["\t", ';', '|', ','] as $sep) if (str_contains($line, $sep)) { $rows[] = array_map('trim', explode($sep, $line)); continue 2; }
        $phone = '';                                                   // "… Nguyễn Văn A 0912 345 678": SĐT phụ huynh thành cột thứ 4
        if (preg_match('/(?:^|\s)((?:\+?84[\s.]?|0)[35789](?:[\s.]?\d){8})\s*$/u', $line, $m)) { $phone = $m[1]; $line = trim(substr($line, 0, -strlen($m[0]))); }
        if (preg_match('/^(\d{1,2})[\.\)\-:\s]+(?:([A-Za-z]*\d{4,}[A-Za-z0-9]*)\s*[-–:]?\s+)?(.+)$/u', $line, $m)) $r = [$m[1], $m[2], trim($m[3])];
        elseif (preg_match('/^([A-Za-z]*\d{4,}[A-Za-z0-9]*)\s*[-–:]?\s+(.+)$/u', $line, $m)) $r = ['', $m[1], trim($m[2])];
        else $r = ['', '', $line];
        $rows[] = $phone !== '' ? [...$r, $phone] : $r;
    }
    return $rows;
}

/** Số điện thoại di động VN trong một ô (có thể có dấu cách / chấm, +84, hoặc mất số 0 đầu khi Excel lưu dạng số). */
function roster_is_phone(string $v, bool $strict = false): bool {
    return (bool)preg_match($strict ? '/^(\+?84|0)[35789]\d{8}$/' : '/^(\+?84|0)?[35789]\d{8}$/', preg_replace('/[\s.\-()]/', '', $v));
}

/** Bảng → [['line', 'seat', 'code', 'name', 'phone', 'auto'], ...]. 'line' = số dòng trong file (báo lỗi), 'auto' = số thứ tự tự đánh. */
function roster_rows(array $rows): array {
    $is_seat = fn($v) => preg_match('/^\d{1,2}$/', trim((string)$v)) === 1;
    $col = ['seat' => null, 'code' => null, 'name' => null, 'family' => null, 'given' => null, 'phone' => null];
    $start = 0;
    $phones = [];                                                      // SĐT tách từ bảng không có tiêu đề, theo chỉ số dòng
    foreach (array_slice($rows, 0, 6, true) as $i => $r) {            // tìm dòng tiêu đề trong 6 dòng đầu
        $found = [];
        foreach ($r as $j => $cell) {
            $k = roster_fold((string)$cell);
            if ($k === '') continue;
            if (preg_match('/(sdt|so ?dt|dien thoai|phone|mobile|^dt\b)/', $k)) $found['phone'] ??= $j;      // trước STT: "Số điện thoại"
            elseif (preg_match('/^(stt|so ?tt|so thu tu|tt|ghe|so ghe|id|seat|no)\b|^so$/', $k)) $found['seat'] ??= $j;   // cả "Số thứ tự (ID trên clicker)"
            elseif (preg_match('/(phu huynh|cha me|bo me|^cha$|^me$|^bo$|parent|nghe nghiep|dia chi|ngay sinh|gioi tinh|email)/', $k)) continue;   // cột khác: bỏ qua
            elseif (preg_match('/(mssv|ma ?hs|ma hoc sinh|ma ?sv|ma so|ma dinh danh|student ?(code|id)|^code$)/', $k)) $found['code'] ??= $j;
            elseif (preg_match('/^(ho (dem|lot)|ho va ten dem|ho)$/', $k)) $found['family'] ??= $j;
            elseif (preg_match('/^(ten|first ?name|given ?name)$/', $k)) $found['given'] ??= $j;
            elseif (preg_match('/(ho (va )?ten|ho ten|full ?name|ten hoc sinh|hoc sinh|^name$)/', $k)) $found['name'] ??= $j;
        }
        if (isset($found['name']) || (isset($found['family']) && isset($found['given']))) { $col = array_merge($col, $found); $start = $i + 1; break; }
    }
    if ($start === 0) {                                                // không có tiêu đề: đoán theo dòng đầu
        $first = reset($rows) ?: [];
        // cột SĐT (số bắt đầu bằng 0 / 84, để không nhầm MSSV dài): tách hẳn ra rồi đoán các cột còn lại
        foreach ($rows as $r) foreach ($r as $j => $v) if (roster_is_phone((string)$v, true)) {
            foreach ($rows as $i => $x) { $phones[$i] = trim((string)($x[$j] ?? '')); unset($x[$j]); $rows[$i] = array_values($x); }
            $first = reset($rows);
            break 2;
        }
        $n = count(array_filter($first, fn($v) => trim((string)$v) !== ''));
        if ($n >= 3) $col = array_merge($col, $is_seat($first[0] ?? '') ? ['seat' => 0, 'code' => 1, 'name' => 2] : ['code' => 0, 'name' => 1]);
        elseif ($n === 2) $col = array_merge($col, $is_seat($first[0] ?? '') ? ['seat' => 0, 'name' => 1] : (preg_match('/\d{4,}/', (string)($first[0] ?? '')) ? ['code' => 0, 'name' => 1] : ['name' => 0, 'code' => 1]));
        else $col['name'] = array_key_first(array_filter($first, fn($v) => trim((string)$v) !== '')) ?? 0;
    }
    $get = fn(array $r, ?int $j) => $j === null ? '' : trim((string)($r[$j] ?? ''));
    $out = [];
    foreach (array_slice($rows, $start, null, true) as $i => $r) {
        $name = $col['name'] !== null ? $get($r, $col['name']) : trim($get($r, $col['family']) . ' ' . $get($r, $col['given']));
        $seat = $get($r, $col['seat']);
        $code = $get($r, $col['code']);
        if ($name === '' && $seat === '' && $code === '') continue;    // dòng trống
        $phone = $col['phone'] !== null ? $get($r, $col['phone']) : ($phones[$i] ?? '');
        $out[] = ['line' => $i + 1, 'seat' => $seat, 'code' => $code, 'name' => preg_replace('/\s+/u', ' ', $name), 'phone' => $phone, 'auto' => false];
    }
    // thiếu số thứ tự → tự đánh 1, 2, 3… theo thứ tự trong file, bỏ qua số đã có ở dòng khác
    $used = [];
    foreach ($out as $r) if ($is_seat($r['seat'])) $used[(int)$r['seat']] = true;
    $next = 1;
    foreach ($out as &$r) {
        if ($r['seat'] !== '') continue;
        while (isset($used[$next])) $next++;
        $r['seat'] = (string)$next; $r['auto'] = true; $used[$next] = true;
    }
    unset($r);
    return $out;
}
