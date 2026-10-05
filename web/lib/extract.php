<?php
// Đọc chữ từ file bài giảng (PDF, PowerPoint, Word, văn bản) để AI tóm tắt / soạn câu hỏi và để xem nhanh.
declare(strict_types=1);

const LECTURE_EXT = ['pdf', 'pptx', 'docx', 'ppt', 'doc', 'txt', 'md'];

function extract_text(string $path, string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    try {
        switch ($ext) {
            case 'pdf':  return extract_pdf($path);
            case 'pptx': return extract_pptx($path);
            case 'docx':
                require_once __DIR__ . '/qparse.php';
                return implode("\n", array_filter(array_map(fn($p) => trim($p['text']), docx_paragraphs($path)), fn($t) => $t !== ''));
            case 'txt': case 'md':
                $t = (string)file_get_contents($path);
                return mb_check_encoding($t, 'UTF-8') ? $t : mb_convert_encoding($t, 'UTF-8', 'Windows-1258');
        }
    } catch (Throwable $e) { return ''; }
    return '';                                                 // .ppt/.doc cũ: chỉ lưu file, không đọc chữ
}

function extract_pdf(string $path): string {
    $bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
    if ($bin === '') return '';                                // cài: sudo apt install poppler-utils
    $out = @shell_exec(escapeshellcmd($bin) . ' -enc UTF-8 -nopgbrk ' . escapeshellarg($path) . ' - 2>/dev/null');
    return trim(preg_replace("/\n{3,}/", "\n\n", (string)$out));
}

function extract_pptx(string $path): string {
    $z = new ZipArchive();
    if ($z->open($path) !== true) return '';
    $slides = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $n, $m)) $slides[(int)$m[1]] = $n;
    }
    ksort($slides);
    $out = [];
    foreach ($slides as $no => $n) {
        $dom = new DOMDocument();
        if (!@$dom->loadXML((string)$z->getFromName($n), LIBXML_NONET)) continue;
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $lines = [];
        foreach ($xp->query('//a:p') as $p) {
            $t = '';
            foreach ($xp->query('.//a:t', $p) as $r) $t .= $r->textContent;
            if (trim($t) !== '') $lines[] = trim($t);
        }
        if ($lines) $out[] = "— Slide $no —\n" . implode("\n", $lines);
    }
    $z->close();
    return implode("\n\n", $out);
}
