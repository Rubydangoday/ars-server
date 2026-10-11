<?php
// Cài đặt: trợ lý AI, tài khoản giáo viên, thông tin chung.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/ai.php';
require __DIR__ . '/lib/oauth.php';
$t = require_login();
$act = $_POST['action'] ?? '';
$admin = is_admin();
if (in_array($act, ['ai', 'tutor', 'general', 'links', 'oauth'], true) && !$admin) { flash('Chỉ quản trị viên được đổi mục này.', 'err'); redirect('settings.php'); }
if ($act === 'links') {
    $u = trim($_POST['public_url'] ?? '');
    if ($u !== '' && !preg_match('#^https?://[a-z0-9.-]+(:\d+)?#i', $u, $m)) { flash('Link công khai phải dạng https://ten-may.tailxxxx.ts.net', 'err'); redirect('settings.php#links'); }
    setting_set('public_url', $u !== '' ? strtolower($m[0]) : null);
    $n = strtolower(trim($_POST['lan_name'] ?? ''));
    setting_set('lan_name', preg_match('/^[a-z0-9-]+\.local$/', $n) ? $n : null);
    flash('Đã lưu đường dẫn.'); redirect('settings.php#links');
}
if ($act === 'ai') {
    $p = $_POST['provider'] ?? 'offline';
    if (!isset(AI_PROVIDERS[$p])) $p = 'offline';
    setting_set('ai_provider', $p);
    $bad = [];
    $pwhash = (string)qv('SELECT password_hash FROM teachers WHERE id=?', [$t['id']]);
    foreach (['anthropic', 'gemini', 'openai'] as $x) {
        $k = trim($_POST['key_' . $x] ?? '');
        // Ô để trống = giữ key cũ. Bỏ qua chữ trình duyệt tự điền: mật khẩu đăng nhập ARS, chuỗi che •••, key sai dạng.
        $autofill = $k !== '' && $pwhash !== '' && password_verify($k, $pwhash);
        $fmt = ['anthropic' => '/^sk-ant-[A-Za-z0-9_\-]{20,}$/', 'gemini' => '/^AIza[0-9A-Za-z_\-]{30,}$/', 'openai' => '/^\S{8,}$/'][$x];
        if ($k !== '' && !$autofill && !preg_match('/^•+$/u', $k)) {
            if (preg_match($fmt, $k)) setting_set('ai_key_' . $x, $k);
            else $bad[] = AI_PROVIDERS[$x][0];
        }
        if (isset($_POST['clear_' . $x])) setting_set('ai_key_' . $x, null);
        setting_set('ai_model_' . $x, trim($_POST['model_' . $x] ?? '') ?: null);
        setting_set('ai_base_' . $x, trim($_POST['base_' . $x] ?? '') ?: null);
    }
    if ($bad) flash(t('API key của {p} không đúng dạng nên chưa được lưu (key cũ vẫn giữ). Kiểm tra lại: key Gemini bắt đầu bằng AIza…, key Claude bắt đầu bằng sk-ant-…', ['p' => implode(', ', $bad)]), 'bad');
    else flash('Đã lưu cài đặt trợ lý AI.');
    redirect('settings.php#ai');
}
if ($act === 'tutor') {
    setting_set('tutor_enabled', isset($_POST['tutor_enabled']) ? '1' : '0');
    setting_set('tutor_daily_limit', (string)max(0, min(500, (int)($_POST['tutor_daily_limit'] ?? 40))));
    flash('Đã lưu cài đặt trợ lý học sinh.');
    redirect('settings.php#tutor');
}
if ($act === 'recovery') {
    $_SESSION['my_recovery'] = new_recovery_code((int)$t['id']);
    redirect('settings.php#account');
}
if ($act === 'profile') {
    $name = trim($_POST['full_name'] ?? '');
    if ($name !== '') { q('UPDATE teachers SET full_name=? WHERE id=?', [$name, $t['id']]); $_SESSION['teacher']['full_name'] = $name; }
    $p1 = $_POST['p1'] ?? ''; $p2 = $_POST['p2'] ?? '';
    if ($p1 !== '' || $p2 !== '') {
        $row = q1('SELECT password_hash FROM teachers WHERE id=?', [$t['id']]);
        if (!password_verify($_POST['p0'] ?? '', $row['password_hash'])) { flash('Mật khẩu hiện tại không đúng.', 'err'); redirect('settings.php#account'); }
        if (mb_strlen($p1) < 6) { flash('Mật khẩu mới phải có ít nhất 6 ký tự.', 'err'); redirect('settings.php#account'); }
        if ($p1 !== $p2) { flash('Mật khẩu xác nhận không khớp.', 'err'); redirect('settings.php#account'); }
        q('UPDATE teachers SET password_hash=? WHERE id=?', [password_hash($p1, PASSWORD_DEFAULT), $t['id']]);
        flash('Đổi mật khẩu thành công.');
    } else flash('Đã lưu thông tin tài khoản.');
    redirect('settings.php#account');
}
if ($act === 'oauth') {
    foreach (OAUTH_PROVIDERS as $p => $n) {
        setting_set('oauth_' . $p . '_id', mb_substr(trim($_POST[$p . '_id'] ?? ''), 0, 200) ?: null);
        $sec = trim($_POST[$p . '_secret'] ?? '');                       // ô hiện •••• = giữ secret cũ
        if (isset($_POST['clear_' . $p])) setting_set('oauth_' . $p . '_secret', null);
        elseif ($sec !== '' && !preg_match('/^•+/u', $sec)) setting_set('oauth_' . $p . '_secret', mb_substr($sec, 0, 300));
    }
    flash('Đã lưu cài đặt đăng nhập Google.');
    redirect('settings.php#oauth');
}
if ($act === 'general') {
    setting_set('org_name', mb_substr(trim($_POST['org_name'] ?? ''), 0, 80) ?: null);
    setting_set('privacy_contact', mb_substr(trim($_POST['privacy_contact'] ?? ''), 0, 120) ?: null);
    setting_set('machine_translate', isset($_POST['machine_translate']) ? '1' : '0');
    flash('Đã lưu.');
    redirect('settings.php#general');
}

$c = ai_conf();
$stat = q1('SELECT COUNT(*) n, SUM(error IS NULL) ok, SUM(COALESCE(tokens_in,0)+COALESCE(tokens_out,0)) tok, SUM(n_out) nq FROM ai_log WHERE teacher_id=? AND created_at > NOW() - INTERVAL 30 DAY', [$t['id']]);
$last_err = q1('SELECT created_at, error FROM ai_log WHERE teacher_id=? AND error IS NOT NULL ORDER BY id DESC LIMIT 1', [$t['id']]);
$masked = function (string $p): string { $k = (string)setting('ai_key_' . $p, ''); return $k === '' ? '' : str_repeat('•', 8) . substr($k, -4); };
$dir = uploads_dir();
$used = 0; foreach (glob($dir . '/*') ?: [] as $f) $used += (int)@filesize($f);

page_head('Cài đặt', 'settings.php');
?>
<div class="page-head"><div><h1>⚙️ Cài đặt</h1><p class="muted">Đường dẫn truy cập, kết nối clicker, trợ lý AI, tài khoản.<?= $admin ? '' : ' Một số mục do giáo viên quản trị server cài đặt.' ?></p></div></div>
<div class="settings-grid">
  <nav class="settings-nav"><a href="#links">🔗 Đường dẫn</a><a href="#conn">🔵 Kết nối clicker</a><?php if ($admin): ?><a href="#ai">✨ Trợ lý AI</a><a href="#tutor">🦉 Trợ lý học sinh</a><?php endif; ?><a href="#account">👤 Tài khoản</a><?php if ($admin): ?><a href="#oauth">🔐 Google</a><?php endif; ?><?php if ($admin): ?><a href="#general">🏫 Thông tin chung</a><a href="teachers.php">👥 Giáo viên</a><?php endif; ?><a href="#system">🖥 Hệ thống</a></nav>
  <div>
    <?php require __DIR__ . '/lib/settings_links.php'; ?>
    <?php if ($admin): ?>
    <section class="card" id="ai">
      <h2>Trợ lý AI soạn câu hỏi</h2>
      <p class="muted small">Đang dùng: <b><?= h(ai_label($c)) ?></b> · 30 ngày qua: <?= (int)$stat['ok'] ?> lượt thành công, <?= (int)$stat['nq'] ?> câu gợi ý<?= $stat['tok'] ? ', ' . number_format((int)$stat['tok'], 0, ',', '.') . ' token' : '' ?>.
        <?php if ($last_err): ?><br><span style="color:var(--bad)">Lỗi gần nhất (<?= h($last_err['created_at']) ?>): <?= h($last_err['error']) ?></span><?php endif; ?></p>
      <form method="post" class="stack" style="max-width:none"><?= csrf_field() ?><input type="hidden" name="action" value="ai">
        <div class="radio-cards">
          <label><input type="radio" name="provider" value="offline" <?= $c['provider'] === 'offline' ? 'checked' : '' ?>><span><b>Cơ bản</b><small>Không cần Internet, không tốn phí. Tạo câu hỏi từ các câu định nghĩa trong bài giảng.</small></span></label>
          <label><input type="radio" name="provider" value="anthropic" <?= $c['provider'] === 'anthropic' ? 'checked' : '' ?>><span><b>Claude (Anthropic)</b><small>Chất lượng cao, đọc được bài giảng dài. Cần API key và Internet.</small></span></label>
          <label><input type="radio" name="provider" value="gemini" <?= $c['provider'] === 'gemini' ? 'checked' : '' ?>><span><b>Google Gemini</b><small>Có gói miễn phí, dễ đăng ký nhất: chỉ cần tài khoản Google. Cần Internet.</small></span></label>
          <label><input type="radio" name="provider" value="openai" <?= $c['provider'] === 'openai' ? 'checked' : '' ?>><span><b>OpenAI / Ollama / LM Studio</b><small>API tương thích OpenAI. Dùng Ollama trên máy để chạy AI không cần Internet.</small></span></label>
        </div>
        <fieldset class="card" data-prov="anthropic" style="box-shadow:none"><legend class="small muted">Claude (Anthropic)</legend>
          <div class="row">
            <label style="flex:2">API key <input name="key_anthropic" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore readonly onfocus="this.removeAttribute('readonly')" placeholder="<?= $masked('anthropic') ? te('Đã lưu — để trống nếu không đổi') : 'sk-ant-…' ?>"><?= $masked('anthropic') ? '<small class="key-saved">✓ ' . te('Đã lưu key') . ' <code>' . h($masked('anthropic')) . '</code></small>' : '' ?></label>
            <label style="flex:1">Model <input name="model_anthropic" list="m-anthropic" value="<?= h(setting('ai_model_anthropic', AI_PROVIDERS['anthropic'][1])) ?>"></label>
            <datalist id="m-anthropic"><option value="claude-sonnet-5"><option value="claude-opus-5"><option value="claude-haiku-4-5-20251001"></datalist>
          </div>
          <div class="row"><label style="flex:1">Địa chỉ API (để trống = mặc định) <input name="base_anthropic" value="<?= h(setting('ai_base_anthropic', '')) ?>" placeholder="https://api.anthropic.com"></label>
            <?php if ($masked('anthropic')): ?><label class="check"><input type="checkbox" name="clear_anthropic"> Xoá key đã lưu</label><?php endif; ?></div>
          <p class="small muted" style="margin:0">Tạo key tại console.anthropic.com → API Keys. Key được lưu trong CSDL trên máy này, không hiển thị lại.</p>
        </fieldset>
        <fieldset class="card" data-prov="gemini" style="box-shadow:none"><legend class="small muted">Google Gemini</legend>
          <div class="row">
            <label style="flex:2">API key <input name="key_gemini" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore readonly onfocus="this.removeAttribute('readonly')" placeholder="<?= $masked('gemini') ? te('Đã lưu — để trống nếu không đổi') : 'AIza…' ?>"><?= $masked('gemini') ? '<small class="key-saved">✓ ' . te('Đã lưu key') . ' <code>' . h($masked('gemini')) . '</code></small>' : '' ?></label>
            <label style="flex:1">Model <input name="model_gemini" list="m-gemini" value="<?= h(setting('ai_model_gemini', AI_PROVIDERS['gemini'][1])) ?>"></label>
            <datalist id="m-gemini"><option value="gemini-3.8-flash"><option value="gemini-3-flash-preview"><option value="gemini-2.5-flash-lite"></datalist>
          </div>
          <div class="row"><label style="flex:1">Địa chỉ API (để trống = mặc định) <input name="base_gemini" value="<?= h(setting('ai_base_gemini', '')) ?>" placeholder="https://generativelanguage.googleapis.com/v1beta/openai"></label>
            <?php if ($masked('gemini')): ?><label class="check"><input type="checkbox" name="clear_gemini"> Xoá key đã lưu</label><?php endif; ?></div>
          <p class="small muted" style="margin:0">Lấy key miễn phí: mở <b>aistudio.google.com</b> → đăng nhập Google → <b>Get API key</b> → <b>Create API key</b> → chép key dạng <code>AIza…</code> dán vào đây → Lưu → Kiểm tra kết nối.
            Gói miễn phí giới hạn số lượt mỗi phút/ngày; Google có thể dùng nội dung gửi lên gói miễn phí để cải tiến sản phẩm.</p>
        </fieldset>
        <fieldset class="card" data-prov="openai" style="box-shadow:none"><legend class="small muted">OpenAI / Ollama / LM Studio</legend>
          <div class="row">
            <label style="flex:2">API key (Ollama không cần) <input name="key_openai" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore readonly onfocus="this.removeAttribute('readonly')" placeholder="<?= $masked('openai') ? te('Đã lưu — để trống nếu không đổi') : 'sk-…' ?>"><?= $masked('openai') ? '<small class="key-saved">✓ ' . te('Đã lưu key') . ' <code>' . h($masked('openai')) . '</code></small>' : '' ?></label>
            <label style="flex:1">Model <input name="model_openai" list="m-openai" value="<?= h(setting('ai_model_openai', AI_PROVIDERS['openai'][1])) ?>"></label>
            <datalist id="m-openai"><option value="gpt-4o-mini"><option value="gpt-4o"><option value="qwen2.5:7b"><option value="llama3.1:8b"></datalist>
          </div>
          <div class="row"><label style="flex:1">Địa chỉ API <input name="base_openai" value="<?= h(setting('ai_base_openai', '')) ?>" placeholder="https://api.openai.com/v1 — Ollama: http://localhost:11434/v1"></label>
            <?php if ($masked('openai')): ?><label class="check"><input type="checkbox" name="clear_openai"> Xoá key đã lưu</label><?php endif; ?></div>
          <p class="small muted" style="margin:0">Chạy AI ngay trên laptop: cài Ollama, chạy <code>ollama pull qwen2.5:7b</code>, rồi điền địa chỉ <code>http://localhost:11434/v1</code> và model <code>qwen2.5:7b</code>.</p>
        </fieldset>
        <div class="row"><button class="primary">Lưu</button><button type="button" id="ai-test">Kiểm tra kết nối</button><span id="ai-test-out" class="small"></span></div>
      </form>
    </section>

    <?php $tu = q1('SELECT COUNT(*) n, COUNT(DISTINCT student_code) s FROM tutor_messages WHERE role="user" AND created_at > NOW() - INTERVAL 7 DAY'); ?>
    <section class="card" id="tutor">
      <h2>🦉 Trợ lý học tập cho học sinh (Cú Học)</h2>
      <p class="muted small">Học sinh hỏi đáp, nhờ giảng lại phần còn yếu, làm câu hỏi ôn tập và bài nâng cao ở tab <b>Trợ lý AI</b> trên cổng học sinh. Dùng cùng dịch vụ AI ở trên.
        Trợ lý tự khoá khi học sinh đang làm bài dạng kiểm tra. 7 ngày qua: <?= (int)$tu['n'] ?> câu hỏi từ <?= (int)$tu['s'] ?> học sinh.</p>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="tutor">
        <label class="check"><input type="checkbox" name="tutor_enabled" <?= setting('tutor_enabled', '1') === '1' ? 'checked' : '' ?>> Bật trợ lý cho học sinh</label>
        <label>Số lượt hỏi AI tối đa / học sinh / ngày (0 = không giới hạn) <input name="tutor_daily_limit" type="number" min="0" max="500" value="<?= h(setting('tutor_daily_limit', '40')) ?>" class="w8"></label>
        <button class="primary">Lưu</button>
      </form>
    </section>

    <?php else: ?>
    <section class="card"><h2>Trợ lý AI</h2><p class="muted small">Đang dùng: <b><?= h(ai_label($c)) ?></b>. Dịch vụ AI và trợ lý học sinh do giáo viên quản trị server cài đặt.</p></section>
    <?php endif; ?>

    <section class="card" id="account">
      <h2>Tài khoản</h2>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <label>Họ tên hiển thị <input name="full_name" value="<?= h($t['full_name']) ?>" required></label>
        <label>Tên đăng nhập <input value="<?= h($t['username']) ?>" disabled></label>
        <p class="small muted" style="margin:0">Đổi mật khẩu (để trống nếu không đổi):</p>
        <label>Mật khẩu hiện tại <input name="p0" type="password" autocomplete="current-password"></label>
        <label>Mật khẩu mới <input name="p1" type="password" minlength="6" autocomplete="new-password"></label>
        <label>Xác nhận mật khẩu mới <input name="p2" type="password" minlength="6" autocomplete="new-password"></label>
        <div><button class="primary">Lưu</button></div>
      </form>
      <h3 style="margin-top:18px">Mã khôi phục mật khẩu</h3>
      <?php if (!empty($_SESSION['my_recovery'])): ?>
        <div class="recovery"><b>Mã khôi phục mới</b><code><?= h($_SESSION['my_recovery']) ?></code><span class="small">Chép lại ngay — mã chỉ hiện một lần, mã cũ đã hết hiệu lực.</span></div>
      <?php unset($_SESSION['my_recovery']); endif; ?>
      <p class="small muted">Dùng khi quên mật khẩu (trang “Quên mật khẩu?” ở màn hình đăng nhập). Ngồi ngay tại laptop chạy server thì không cần mã; trên terminal có lệnh <code>ars reset-gv</code>.</p>
      <form method="post" onsubmit="return confirm('Tạo mã khôi phục mới? Mã cũ sẽ hết hiệu lực.')"><?= csrf_field() ?><input type="hidden" name="action" value="recovery"><button>Tạo mã khôi phục mới</button></form>
      <?= oauth_manage_html('teacher', (string)$t['id']) ?>
    </section>

    <?php if ($admin): $osec = fn($p) => setting('oauth_' . $p . '_secret') ? str_repeat('•', 8) : ''; ?>
    <section class="card" id="oauth">
      <h2>Đăng nhập bằng Google</h2>
      <p class="muted small">Cho giáo viên, học sinh, phụ huynh đăng nhập bằng tài khoản Google đã liên kết. Google chỉ chấp nhận địa chỉ <b>https://</b> có tên miền (hoặc localhost): bật trên server Cloud / link công khai. Truy cập bằng http qua IP LAN sẽ không hiện nút.</p>
      <p class="small">Redirect URI cần khai báo (đúng từng ký tự):</p>
      <?= link_box(oauth_redirect_uri(), 'Địa chỉ đang mở', false) ?>
      <?php if ($pub = setting('public_url')): ?><?= link_box(rtrim($pub, '/') . web_root_path() . '/oauth.php', 'Link công khai', false) ?><?php endif; ?>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="oauth">
        <h3>Google <span class="muted small">— Google Cloud Console → APIs &amp; Services → Credentials → OAuth client ID (Web application)</span></h3>
        <label>Client ID <input name="google_id" value="<?= h(setting('oauth_google_id', '')) ?>" placeholder="…apps.googleusercontent.com"></label>
        <label>Client secret <input name="google_secret" autocomplete="off" value="<?= $osec('google') ?>"></label>
        <label class="check"><input type="checkbox" name="clear_google"> Xoá secret (tắt Google)</label>
        <div><button class="primary">Lưu</button></div>
      </form>
      <p class="muted small">Tài khoản không tự ghép theo email: mỗi người đăng nhập bằng mật khẩu một lần rồi bấm “Liên kết” trong trang tài khoản. Phụ huynh mới có thể đăng ký thẳng bằng Google kèm mã mời.</p>
    </section>
    <?php endif; ?>

    <?php if ($admin): ?>
    <section class="card" id="general">
      <h2>Thông tin chung</h2>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="general">
        <label style="flex:1">Tên trường / khoa (hiện ở thanh trên cùng) <input name="org_name" value="<?= h(setting('org_name', '')) ?>" placeholder="VD: Khoa Điện – Điện tử, HCMUTE"></label>
        <label style="flex:1">Email liên hệ quyền riêng tư <input name="privacy_contact" value="<?= h(setting('privacy_contact', '')) ?>" placeholder="VD: phongdaotao@truong.edu.vn"></label>
        <label class="check" style="flex-basis:100%"><input type="checkbox" name="machine_translate" <?= machine_translate_on() ? 'checked' : '' ?>> Cho phép “Ngôn ngữ khác (Google Dịch)” ở nút chọn ngôn ngữ — dịch máy hơn 200 ngôn ngữ, cần Internet; nội dung trang được gửi tới Google khi người dùng chọn.</label>
        <button class="primary">Lưu</button>
      </form>
    </section>

    <?php endif; ?>

    <section class="card" id="system">
      <h2>Hệ thống</h2>
      <table class="grid compact">
        <tr><td>Thư mục lưu bài giảng</td><td><code><?= h($dir) ?></code> · <?= is_writable($dir) ? '<span class="pill-ok">ghi được</span>' : '<span style="color:var(--bad)">không ghi được — chạy lại install_ubuntu.sh</span>' ?> · đã dùng <?= human_size($used) ?></td></tr>
        <tr><td>Đọc chữ từ PDF</td><td><?= trim((string)@shell_exec('command -v pdftotext')) ? '<span class="pill-ok">có (pdftotext)</span>' : 'chưa cài — <code>sudo apt install poppler-utils</code>' ?></td></tr>
        <tr><td>Gọi dịch vụ AI qua mạng</td><td><?= function_exists('curl_init') ? '<span class="pill-ok">có (PHP cURL)</span>' : 'chưa cài — <code>sudo apt install php-curl</code>' ?></td></tr>
        <tr><td>Giới hạn tải file</td><td><?= h(ini_get('upload_max_filesize')) ?> mỗi file</td></tr>
        <tr><td>Giáo viên trên server</td><td><?= (int)qv('SELECT COUNT(*) FROM teachers WHERE active=1') ?> tài khoản hoạt động · <?= (int)qv('SELECT COUNT(*) FROM sessions WHERE status="running"') ?> buổi học đang chạy</td></tr>
      </table>
    </section>
  </div>
</div>
<script>
(function () {
  if (!document.getElementById('ai-test')) return;
  const radios = document.querySelectorAll('input[name=provider]');
  const upd = () => { const v = document.querySelector('input[name=provider]:checked').value; document.querySelectorAll('[data-prov]').forEach((f) => { f.hidden = f.dataset.prov !== v; }); };
  radios.forEach((r) => r.addEventListener('change', upd)); upd();
  document.getElementById('ai-test').addEventListener('click', async (e) => {
    const out = document.getElementById('ai-test-out');
    out.innerHTML = '<span class="spinner"></span>Đang kiểm tra (dùng cài đặt đã lưu)…'; e.target.disabled = true;
    const fd = new FormData(); fd.append('csrf', document.querySelector('meta[name=csrf]').content); fd.append('task', 'test');
    try { const r = await fetch('api/ai.php', { method: 'POST', body: fd }); const j = await r.json(); out.textContent = j.error ? '✗ ' + j.error : '✓ ' + j.msg; out.style.color = j.error ? 'var(--bad)' : 'var(--ok)'; }
    catch (err) { out.textContent = '✗ ' + err.message; }
    e.target.disabled = false;
  });
})();
</script>
<?php page_foot();
