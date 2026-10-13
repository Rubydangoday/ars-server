<?php
// Tổng hợp một lớp hành chính qua mọi môn (GV chủ nhiệm hoặc Admin trường): tỉ lệ bấm Remote đúng và chuyên cần.
require __DIR__ . '/lib/school.php';
require_login();
$name = mb_substr(trim((string)($_GET['name'] ?? '')), 0, 30);
$sid = my_school_id();
if (!$sid || $name === '' || !cohort_access($sid, $name)) { http_response_code(404); exit('Không tìm thấy lớp.'); }

$classes = qall('SELECT c.*, t.full_name AS teacher_name FROM classes c JOIN teachers t ON t.id=c.teacher_id WHERE t.school_id=? AND c.cohort=? ORDER BY c.subject, c.name', [$sid, $name]);
$hr = q1('SELECT t.full_name FROM school_cohorts sc JOIN teachers t ON t.id=sc.homeroom_teacher_id WHERE sc.school_id=? AND sc.name=?', [$sid, $name]);
$rows = [];   // key: mã HS (hoặc họ tên khi chưa có mã) → [name, per class]
foreach ($classes as $c) {
    foreach (class_remote_summary((int)$c['id']) as $r) {
        $k = $r['code'] ?: 'name:' . mb_strtolower($r['name']);
        $rows[$k] ??= ['name' => $r['name'], 'code' => $r['code'], 'cells' => []];
        $rows[$k]['cells'][(int)$c['id']] = $r;
    }
    foreach (qall('SELECT student_code, full_name FROM students WHERE class_id=?', [$c['id']]) as $s) {
        $k = $s['student_code'] ?: 'name:' . mb_strtolower($s['full_name']);
        $rows[$k] ??= ['name' => $s['full_name'], 'code' => $s['student_code'], 'cells' => []];
    }
}
uasort($rows, fn($a, $b) => strcmp(trim(strrchr(' ' . $a['name'], ' ')), trim(strrchr(' ' . $b['name'], ' '))) ?: strcmp($a['name'], $b['name']));

if (in_array($_GET['format'] ?? '', ['xlsx', 'csv'], true)) {
    require __DIR__ . '/lib/sheet.php';
    $sheet = [array_merge(['Mã học sinh', 'Họ tên'], array_merge(...array_map(fn($c) => [($c['subject'] ?: $c['name']) . ' — % đúng', ($c['subject'] ?: $c['name']) . ' — có mặt'], $classes ?: [[]])))];
    foreach ($rows as $r) {
        $line = [$r['code'], $r['name']];
        foreach ($classes as $c) { $x = $r['cells'][(int)$c['id']] ?? null; $line[] = $x && $x['n'] ? round(100 * $x['ok'] / $x['n'], 1) : ''; $line[] = $x ? $x['present'] . '/' . $x['sessions'] : ''; }
        $sheet[] = $line;
    }
    $base = 'ARS_lop_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
    if ($_GET['format'] === 'csv') send_csv($sheet, $base . '.csv');
    send_xlsx(['Tong hop' => $sheet], $base . '.xlsx');
}

page_head('Lớp ' . $name, 'school.php');
?>
<p class="crumbs"><?= is_school_admin() ? '<a href="school.php">Trường của tôi</a> › ' : '' ?>Lớp hành chính</p>
<h1>Lớp <?= h($name) ?> <span class="muted small">GV chủ nhiệm: <?= h($hr['full_name'] ?? 'chưa gán') ?></span></h1>
<p><a class="btn" href="cohort.php?name=<?= rawurlencode($name) ?>&format=xlsx">Xuất Excel</a> <a class="btn" href="cohort.php?name=<?= rawurlencode($name) ?>&format=csv">Xuất CSV</a> <button onclick="window.print()">In / Lưu PDF</button></p>
<?php if (!$classes): ?><div class="card muted">Chưa có lớp bộ môn nào gắn lớp hành chính này. Giáo viên bộ môn điền “Lớp hành chính” trong trang lớp.</div><?php else: ?>
<section class="card"><div style="overflow:auto"><table class="grid">
  <thead><tr><th>Học sinh</th><?php foreach ($classes as $c): ?><th><?= h($c['subject'] ?: $c['name']) ?><br><span class="small muted"><?= h($c['teacher_name']) ?></span></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= h($r['name']) ?><br><span class="small muted"><?= h($r['code'] ?? '') ?></span></td>
    <?php foreach ($classes as $c): $x = $r['cells'][(int)$c['id']] ?? null; $pct = $x && $x['n'] ? round(100 * $x['ok'] / $x['n']) : null; ?>
      <td <?= $pct !== null ? 'style="background:' . ($pct < 50 ? '#ffe3df' : ($pct < 80 ? '#fff0c2' : '#d7f3e3')) . '"' : '' ?>><?= $pct !== null ? $pct . '% đúng' : '—' ?><br><span class="small muted"><?= $x ? 'có mặt ' . $x['present'] . '/' . $x['sessions'] : '' ?></span></td>
    <?php endforeach; ?></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="muted small">Tính trên các buổi Remote đã kết thúc và đã công bố; buổi vắng không tính vào % đúng. Chi tiết từng môn: <?php foreach ($classes as $c): ?><a href="analytics.php?class=<?= (int)$c['id'] ?>"><?= h($c['subject'] ?: $c['name']) ?></a> <?php endforeach; ?></p>
</section>
<?php endif; ?>
<style>@media print{header.top,nav,form,button,.btn{display:none!important}main{max-width:none}table{font-size:10px}}</style>
<?php page_foot();
