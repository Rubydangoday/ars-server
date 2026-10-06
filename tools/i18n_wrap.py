#!/usr/bin/env python3
"""Công cụ dịch giao diện: bọc chuỗi tiếng Việt trong template PHP bằng te()/t() (lib/i18n.php).

  python3 tools/i18n_wrap.py html web/x.php 120     # bọc chữ trong phần HTML từ dòng 120 (sau page_head)
  python3 tools/i18n_wrap.py php  web/x.php 120     # bọc chuỗi '…' tiếng Việt trong <?php/<?= … ?> từ dòng 120
  python3 tools/i18n_wrap.py keys web               # in mọi khoá dịch (t/te/T) trong thư mục, mỗi dòng một khoá
Không bọc: giá trị mặc định tham số hàm / hằng số (PHP không cho gọi hàm ở đó) — sửa tay những chỗ đó. Luôn chạy php -l sau khi bọc.
"""
import os, re, sys
VI = re.compile(r'[À-ỹĐđ]')


def lit(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def wrap_html(chunk):
    def attr(m):
        v = m.group(2)
        if not VI.search(v) or '&' in v:
            return m.group(0)
        return f'{m.group(1)}="<?= te({lit(v)}) ?>"'
    chunk = re.sub(r'\b(placeholder|title|aria-label)="([^"<>]*)"', attr, chunk)

    def conf(m):
        v = m.group(1)
        return m.group(0) if not VI.search(v) else f'confirm(<?= h(json_encode(t({lit(v)}), JSON_UNESCAPED_UNICODE)) ?>)'
    chunk = re.sub(r"confirm\('([^'<>]*)'\)", conf, chunk)
    out, i = [], 0
    for m in re.finditer(r'(^|>)([^<>]+)(?=<|$)', chunk):
        text = m.group(2)
        core = text.strip()
        if VI.search(core) and '&' not in core and '"' not in core and '=' not in core:
            lead = text[:len(text) - len(text.lstrip())]
            trail = text[len(text.rstrip()):]
            out.append(chunk[i:m.start()])
            out.append(m.group(1) + lead + f'<?= te({lit(core)}) ?>' + trail)
            i = m.end()
    out.append(chunk[i:])
    return ''.join(out)


def wrap_html_doc(src):
    parts = re.split(r'(<\?(?:php|=)[\s\S]*?\?>)', src)
    return ''.join(p if p.startswith('<?') else wrap_html(p) for p in parts)


def wrap_php_literals(src):
    def lit_rep(m):
        raw = m.group(1).replace("\\'", "'")
        if not VI.search(raw):
            return m.group(0)
        if '<' not in raw:
            core = raw.strip()
            lead = raw[:len(raw) - len(raw.lstrip())]
            trail = raw[len(raw.rstrip()):]
            return (lit(lead) + ' . ' if lead else '') + f"t({lit(core)})" + (' . ' + lit(trail) if trail else '')
        out = []
        for p in re.split(r'(<[^>]*>)', raw):
            if not p:
                continue
            if p.startswith('<') or not VI.search(p):
                out.append(lit(p))
            else:
                core = p.strip()
                lead = p[:len(p) - len(p.lstrip())]
                trail = p[len(p.rstrip()):]
                if lead:
                    out.append(lit(lead))
                out.append(f"t({lit(core)})")
                if trail:
                    out.append(lit(trail))
        return '(' + ' . '.join(out) + ')'

    def block(m):
        return re.sub(r"(?<![\w\]\)])(?<!t\()(?<!te\()'((?:[^'\\]|\\.)*)'", lit_rep, m.group(0))
    return re.sub(r'<\?(?:php|=)[\s\S]*?(?:\?>|\Z)', block, src)   # khối chưa đóng: tới cuối file


def clean_double(src):
    return re.sub(r"\b(te?)\(t\(('(?:[^'\\]|\\.)*')\)\)", r"\1(\2)", src)


def keys(root):
    found = set()
    pat = re.compile(r"""\b(?:te|t|T)\(\s*'((?:[^'\\]|\\.)*)'""")
    for d, _, files in os.walk(root):
        if 'vendor' in d:
            continue
        for f in files:
            if f.endswith(('.php', '.js')) and f != 'en.php':
                for k in pat.findall(open(os.path.join(d, f), encoding='utf-8').read()):
                    k = k.replace("\\'", "'")
                    if VI.search(k):
                        found.add(k)
    return sorted(found)


if __name__ == '__main__':
    mode, path = sys.argv[1], sys.argv[2]
    if mode == 'keys':
        print('\n'.join(keys(path)))
        sys.exit()
    start = int(sys.argv[3]) if len(sys.argv) > 3 else 1
    lines = open(path, encoding='utf-8').read().split('\n')
    head, body = '\n'.join(lines[:start - 1]), '\n'.join(lines[start - 1:])
    body = wrap_html_doc(body) if mode == 'html' else wrap_php_literals(body)
    open(path, 'w', encoding='utf-8').write(clean_double((head + '\n' if head else '') + body))
