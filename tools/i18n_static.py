#!/usr/bin/env python3
"""Quét mã nguồn PHP, liệt kê mọi đoạn chữ tiếng Việt có thể hiển thị mà web/lang/en.php chưa có bản dịch.

  python3 tools/i18n_static.py > /tmp/missing_static.jsonl

Bổ sung cho i18n_collect.py (duyệt trang thật): bắt cả chữ chỉ hiện sau khi bấm nút / chọn lớp / báo lỗi.
Tách chuỗi theo thẻ HTML và theo đoạn PHP ('…' . $x . '…'), chuẩn hoá giống i18n_lookup() (bỏ ký hiệu đầu-cuối, số → {n}).
Bỏ qua chú thích và các lời nhắc gửi cho AI (lib/ai.php, lib/tutor.php, lib/ai_offline.php).
"""
import os, re, sys, json, glob, html, subprocess

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'web')
VI = re.compile(r'[À-ỹĐđ]')
SKIP_FILES = {'lib/ai.php', 'lib/tutor.php', 'lib/ai_offline.php', 'lib/qparse.php', 'lang/en.php'}
keys = set(json.loads(subprocess.run(['php', '-r', 'echo json_encode(array_keys(require "%s/lang/en.php"), JSON_UNESCAPED_UNICODE);' % ROOT],
                                     capture_output=True, text=True).stdout))


def norm(s):
    s = re.sub(r'\s+', ' ', html.unescape(s)).strip()
    if not VI.search(s) or len(s) > 400:
        return None
    if s in keys:
        return None
    m = re.match(r'^([^\w]*)(.*?)([\s:：.…!?·,;)]*)$', s, re.S)
    core = m.group(2) if m and m.group(2) else s
    if core in keys or not VI.search(core):
        return None
    key = re.sub(r'\d+(?:[.,]\d+)*', '{n}', core)
    return None if key in keys else key


def fragments(text):
    """Tách một đoạn (HTML hoặc chuỗi PHP) thành các mảnh chữ giữa thẻ / biến."""
    text = re.sub(r'<(script|style)\b.*?</\1>', ' ', text, flags=re.S | re.I)
    for a in re.findall(r'\b(?:placeholder|title|aria-label|alt)="([^"<>$]*)"', text):
        yield a
    text = re.sub(r'<[^>]*>', '\n', text)
    for part in re.split(r'\n|\{\$[^}]*\}|\$[a-z_]\w*(?:\[[^\]]*\])*', text):
        yield part


found = {}
# Lấy chuỗi bằng bộ phân tích cú pháp của PHP (token_get_all): HTML ngoài thẻ PHP, chuỗi '…' / "…" — bỏ qua chú thích chính xác.
PHP_TOK = r'''
$out = [];
foreach (array_slice($argv, 1) as $f) {
    $parts = [];
    foreach (token_get_all(file_get_contents($f)) as $t) {
        if (!is_array($t)) continue;
        [$id, $txt] = $t;
        if ($id === T_INLINE_HTML) $parts[] = $txt;
        elseif ($id === T_CONSTANT_ENCAPSED_STRING) $parts[] = stripcslashes(substr($txt, 1, -1));
        elseif ($id === T_ENCAPSED_AND_WHITESPACE) $parts[] = $txt;
    }
    $out[$f] = $parts;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
'''
files = [f for f in sorted(glob.glob(ROOT + '/**/*.php', recursive=True)) if os.path.relpath(f, ROOT) not in SKIP_FILES]
chunks_by_file = json.loads(subprocess.run(['php', '-r', PHP_TOK, '--'] + files, capture_output=True, text=True).stdout)
for f, chunks in chunks_by_file.items():
    rel = os.path.relpath(f, ROOT)
    for ch in chunks:
        if not VI.search(ch):
            continue
        for frag in fragments(ch):
            k = norm(frag)
            if k and not re.search(r'[{}$\\]|\w\(|=>|::', k.replace('{n}', '')):
                found.setdefault(k, rel)

for k, w in sorted(found.items(), key=lambda x: (x[1], x[0])):
    print(json.dumps([k, w], ensure_ascii=False))
print(len(found), 'chuỗi', file=sys.stderr)
