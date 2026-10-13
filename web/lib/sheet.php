<?php
// Đọc/ghi bảng tính không cần thư viện ngoài: .xlsx (ZipArchive + XML) và .csv.
declare(strict_types=1);

/** Đọc file tải lên → ['Tên sheet' => [[ô, ô, ...], ...]]. CSV trả về một sheet tên 'csv'. */
function sheet_read(string $path, string $origName): array {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'csv') return ['csv' => csv_read($path)];
    if ($ext !== 'xlsx') throw new RuntimeException('Chỉ nhận file .xlsx hoặc .csv');
    return xlsx_read($path);
}

function csv_read(string $path): array {
    $txt = file_get_contents($path);
    if (substr($txt, 0, 3) === "\xEF\xBB\xBF") $txt = substr($txt, 3);            // bỏ BOM UTF-8
    $first = strtok($txt, "\n");
    $sep = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';   // Excel tiếng Việt hay dùng ;
    $rows = [];
    $fh = fopen('php://memory', 'r+'); fwrite($fh, $txt); rewind($fh);
    while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) $rows[] = array_map('trim', $r);
    fclose($fh);
    return $rows;
}

function xlsx_read(string $path): array {
    $z = new ZipArchive();
    if ($z->open($path) !== true) throw new RuntimeException('File .xlsx hỏng hoặc không đọc được');
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $shared = [];
    if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($x);
        foreach ($sx->children($ns)->si as $si) {
            $t = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $node) $t .= (string)$node;
            $shared[] = $t;
        }
    }
    // tên sheet → file
    $wb = simplexml_load_string($z->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string($z->getFromName('xl/_rels/workbook.xml.rels'));
    $target = [];
    foreach ($rels->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $r) {
        $a = $r->attributes();   // thuộc tính không có tiền tố phải đọc qua attributes() khi đã dùng children(ns)
        $target[(string)$a['Id']] = ltrim(preg_replace('#^/?xl/#', '', (string)$a['Target']), '/');
    }
    $out = [];
    foreach ($wb->children($ns)->sheets->sheet as $sh) {
        $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $xml = $z->getFromName('xl/' . ($target[$rid] ?? ''));
        if ($xml === false) continue;
        $sx = simplexml_load_string($xml);
        $rows = [];
        foreach ($sx->children($ns)->sheetData->row as $row) {
            $r = [];
            foreach ($row->children($ns)->c as $c) {
                $ca = $c->attributes();
                $ref = (string)$ca['r'];
                $col = col_index(preg_replace('/\d+/', '', $ref));
                $type = (string)$ca['t'];
                if ($type === 's') $v = $shared[(int)$c->children($ns)->v] ?? '';
                elseif ($type === 'inlineStr') { $v = ''; foreach ($c->xpath('.//*[local-name()="t"]') as $n) $v .= (string)$n; }
                else $v = (string)$c->children($ns)->v;
                if ($type === '' && is_numeric($v) && floor((float)$v) == (float)$v) $v = (string)(int)$v;  // 3.0 → "3"
                $r[$col] = trim($v);
            }
            if ($r) { $max = max(array_keys($r)); $rows[] = array_map(fn($i) => $r[$i] ?? '', range(0, $max)); }
            else $rows[] = [];
        }
        $out[(string)$sh->attributes()['name']] = $rows;
    }
    $z->close();
    if (!$out) throw new RuntimeException('file .xlsx không có sheet nào đọc được');
    return $out;
}

function col_index(string $letters): int {
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}
function col_letter(int $i): string {
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

/** Tạo .xlsx đơn giản: $sheets = ['Tên' => [[...], ...]]. Dòng đầu in đậm. Trả về đường dẫn file tạm. */
function xlsx_write(array $sheets): string {
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $n = count($sheets);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    for ($i = 1; $i <= $n; $i++) $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $z->addFromString('[Content_Types].xml', $ct . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $i = 0;
    foreach ($sheets as $name => $rows) {
        $i++;
        $wb .= '<sheet name="' . $esc(mb_substr($name, 0, 31)) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $maxc = 0; foreach ($rows as $r) $maxc = max($maxc, count($r));
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>';
        for ($c = 0; $c < $maxc; $c++) $x .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . ($c === 1 ? 45 : 14) . '" customWidth="1"/>';
        $x .= '</cols><sheetData>';
        foreach ($rows as $ri => $r) {
            $x .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($r) as $ci => $v) {
                $ref = col_letter($ci) . ($ri + 1);
                $s = $ri === 0 ? ' s="1"' : '';
                if (is_int($v) || is_float($v)) $x .= '<c r="' . $ref . '"' . $s . '><v>' . $v . '</v></c>';
                elseif ($v !== '' && $v !== null) $x .= '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t xml:space="preserve">' . $esc($v) . '</t></is></c>';
            }
            $x .= '</row>';
        }
        $z->addFromString('xl/worksheets/sheet' . $i . '.xml', $x . '</sheetData></worksheet>');
    }
    $z->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
    $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $z->addFromString('xl/_rels/workbook.xml.rels', $rels . '</Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $z->close();
    return $tmp;
}

function send_xlsx(array $sheets, string $filename): void {
    $f = xlsx_write($sheets);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($f));
    output_raw(); readfile($f);
    unlink($f);
    exit;
}

/** Một bảng → nội dung CSV: BOM UTF-8 (Excel đọc đúng tiếng Việt), dấu ";" (thói quen Excel tiếng Việt; csv_read nhận cả "," lẫn ";").
 *  Ô bắt đầu bằng = + - @ (hoặc tab / CR) được thêm dấu ' phía trước: Excel không chạy chúng như công thức (CSV injection).
 *  Số giữ nguyên (điểm âm như -1 vẫn là số). */
function csv_string(array $rows): string {
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, "\xEF\xBB\xBF");
    foreach ($rows as $r) {
        $r = array_map(function ($v) {
            if (is_int($v) || is_float($v) || $v === null) return $v === null ? '' : (string)$v;
            $v = (string)$v;
            return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
        }, $r);
        fputcsv($fh, $r, ';', '"', '');
    }
    rewind($fh);
    $out = stream_get_contents($fh);
    fclose($fh);
    return $out;
}

function send_csv(array $rows, string $filename): void {
    $out = csv_string($rows);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($out));
    output_raw(); echo $out;
    exit;
}

/** Tìm dòng tiêu đề (dòng đầu có chữ) và trả về các dòng dữ liệu phía sau, bỏ dòng trống. */
function data_rows(array $rows): array {
    $out = []; $header = true;
    foreach ($rows as $i => $r) {
        if (!array_filter($r, fn($v) => $v !== '')) continue;
        if ($header) { $header = false; continue; }
        $out[$i + 1] = $r;                           // khoá = số dòng trong file (để báo lỗi)
    }
    return $out;
}
