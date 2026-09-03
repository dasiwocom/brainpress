<?php
/**
 * BrainPress v1.1.0 — 后台管理（独立入口，nginx 将 /admin 和 /api/admin/ 转发到此）
 * 页面：/admin（未登录 → 登录页；已登录 → 配置面板）
 * API：/api/admin/config（GET 读取 / POST 保存）
 * 登录 API（/api/setup、/api/login、/api/logout）保留在 index.php（nginx /api/ 主路由）
 */

require __DIR__ . '/functions.php';

/* --- API：/api/admin/*（仅登录后可访问） --- */
if (strpos($uri, '/api/admin/') === 0) {
    header('Content-Type: application/json; charset=utf-8');
    require_auth();

    // 管理配置：GET 返回当前配置，POST 保存
    if ($uri === '/api/admin/config' && $method === 'GET') {
        ok([
            'webdav_mounts' => webdav_mounts_list($config),
            'webdav_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/dav/',
            'render_webdav' => $config['render_webdav'] ?? false,
            'render_minio' => $config['render_minio'] ?? true,
            'render_ima' => $config['render_ima'] ?? false,
            'custom_paths' => $config['custom_paths'] ?? [],
            'exclude_paths' => $config['exclude_paths'] ?? [],
            'pinned_dirs' => $config['pinned_dirs'] ?? [],
            'pinned_articles' => $config['pinned_articles'] ?? [],
            'expanded_dirs' => $config['expanded_dirs'] ?? [],
            'site_title' => $config['site_title'] ?? 'BrainPress',
            'home_article' => $config['home_article'] ?? '',
            'content_width' => (int)($config['content_width'] ?? 840),
            'api_token' => $config['api_token'] ?? '',
            'font_preset' => $config['font_preset'] ?? 'nunito',
            'pin_navbar' => $config['pin_navbar'] ?? false,
            'ai_api_base' => $config['ai_api_base'] ?? '',
            'ai_api_key' => $config['ai_api_key'] ?? '',
            'ai_model' => $config['ai_model'] ?? 'deepseek-chat',
            'ai_mode' => $config['ai_mode'] ?? 'hybrid',
            'ai_enabled' => $config['ai_enabled'] ?? true,
            'graph_show_labels' => $config['graph_show_labels'] ?? false,
            'graph_path' => $config['graph_path'] ?? '',
            'default_light' => $config['default_light'] ?? false,
            'front_drawer_expanded' => $config['front_drawer_expanded'] ?? true,
            'minio' => [
                'endpoint' => $config['minio_endpoint'] ?? 'http://127.0.0.1:19000',
                'access' => $config['minio_access'] ?? 'minio',
                'secret' => $config['minio_secret'] ?? '',
                'bucket' => $config['minio_bucket'] ?? 'vault',
            ],
            'ima' => [
                'client_id' => $config['ima']['client_id'] ?? '',
                'api_key' => $config['ima']['api_key'] ?? '',
            ],
        ]);
    }
    if ($uri === '/api/admin/config' && $method === 'POST') {
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $endpoint = trim((string)($body['endpoint'] ?? ''));
        $access = trim((string)($body['access'] ?? ''));
        $secret = trim((string)($body['secret'] ?? ''));
        $bucket = trim((string)($body['bucket'] ?? ''));
        $renderMinio = !empty($body['render_minio']);
        $test = [];
        // 仅当 MinIO 渲染开关开启时才要求填 MinIO 字段并做连接测试
        if ($renderMinio) {
            if ($endpoint === '' || $access === '' || $secret === '' || $bucket === '') {
                fail('All fields are required');
            }
            // 校验 MinIO 连接：列桶测试
            $GLOBALS['minioEndpoint'] = $endpoint;
            $GLOBALS['minioAccess'] = $access;
            $GLOBALS['minioSecret'] = $secret;
            $GLOBALS['minioBucket'] = $bucket;
            $test = minio_ls('');
            if ($test === []) {
                fail('Cannot connect to MinIO, check endpoint/key/bucket');
            }
        }
        $config['minio_endpoint'] = $endpoint;
        $config['minio_access'] = $access;
        $config['minio_secret'] = $secret;
        $config['minio_bucket'] = $bucket;
        // 渲染开关（多选）
        $config['render_webdav'] = !empty($body['render_webdav']);
        $config['render_minio'] = $renderMinio;
        // ima mount: credentials + render switch + connection test (only when enabled)
        $imaCid = trim((string)($body['ima_client_id'] ?? ''));
        $imaKey = trim((string)($body['ima_api_key'] ?? ''));
        $renderIma = !empty($body['render_ima']);
        $imaTest = [];
        if ($renderIma) {
            if ($imaCid === '' || $imaKey === '') {
                fail('ima client id and api key are both required');
            }
            $config['ima'] = ['client_id' => $imaCid, 'api_key' => $imaKey];
            // connection test: fetch own knowledge base list to validate the credentials
            $imaTest = ima_knowledge_bases($config);
            if ($imaTest === []) {
                fail('Cannot connect to ima, check client id / api key');
            }
        }
        $config['render_ima'] = $renderIma;
        if ($renderIma) {
            $config['ima'] = ['client_id' => $imaCid, 'api_key' => $imaKey];
        }
        // 自定义路径（最多 5 条：每条 = 路径 + 开关）
        $rawPaths = $body['custom_paths'] ?? [];
        $customPaths = [];
        for ($i = 0; $i < 5; $i++) {
            $customPaths[] = [
                'path' => trim((string)($rawPaths[$i]['path'] ?? '')),
                'on' => !empty($rawPaths[$i]['on']),
            ];
        }
        $config['custom_paths'] = $customPaths;
        // Exclude list: strip empty entries before saving
        $config['exclude_paths'] = array_values(array_filter(array_map('trim', (array)($body['exclude_paths'] ?? []))));
        // Tree: pinned dirs + pinned articles + expanded dirs (all lists)
        $config['pinned_dirs'] = array_values(array_filter(array_map('trim', (array)($body['pinned_dirs'] ?? []))));
        $config['pinned_articles'] = array_values(array_filter(array_map('trim', (array)($body['pinned_articles'] ?? []))));
        $config['expanded_dirs'] = array_values(array_filter(array_map('trim', (array)($body['expanded_dirs'] ?? []))));
        // 站点设置：标题 + 首页文章（密码走独立 /api/admin/password 接口）
        $config['site_title'] = trim((string)($body['site_title'] ?? '')) !== '' ? trim((string)$body['site_title']) : ($config['site_title'] ?? 'BrainPress');
        $config['home_article'] = trim((string)($body['home_article'] ?? ''));
        // 内容宽度：空 = 保持现值；数值则夹到 480–1600
        $rawW = trim((string)($body['content_width'] ?? ''));
        $config['content_width'] = ($rawW === '') ? (int)($config['content_width'] ?? 840) : max(480, min(1600, (int)$rawW));
        $config['api_token'] = trim((string)($body['api_token'] ?? ''));
        // WebDAV 同步账号：数组（{user,pass,path}）；空行剔除；path 去首尾斜杠，空 = vault 根。
        // 显式提交 webdav_mounts 才写（其他接口的增量保存不碰它）；旧字段仅随第一组账号同步，兼容老读取方
        if (isset($body['webdav_mounts']) && is_array($body['webdav_mounts'])) {
            $mounts = [];
            foreach ($body['webdav_mounts'] as $m) {
                if (!is_array($m)) continue;
                $u = trim((string)($m['user'] ?? ''));
                $p = (string)($m['pass'] ?? '');
                $pt = trim(trim((string)($m['path'] ?? ''), '/'), " \t");
                if ($u === '' && $p === '' && $pt === '') continue;
                $mounts[] = ['user' => $u, 'pass' => $p, 'path' => $pt];
            }
            $config['webdav_mounts'] = $mounts;
            $config['webdav_user'] = $mounts[0]['user'] ?? '';
            $config['webdav_pass'] = $mounts[0]['pass'] ?? '';
        }
        $config['dav_path'] = '';
        // WebDAV：账号密码 + 自定义同步子目录（vault 相对；留空 = vault 根）
        $config['webdav_user'] = trim((string)($body['webdav_user'] ?? ''));
        $config['webdav_pass'] = trim((string)($body['webdav_pass'] ?? ''));
        $config['dav_path'] = trim(trim((string)($body['dav_path'] ?? ''), '/'), " \t");
        $config['ai_api_base'] = trim((string)($body['ai_api_base'] ?? ''));
        $config['ai_api_key'] = trim((string)($body['ai_api_key'] ?? ''));
        $config['ai_model'] = trim((string)($body['ai_model'] ?? '')) !== '' ? trim((string)$body['ai_model']) : 'deepseek-chat';
        $config['ai_mode'] = !empty($body['ai_mode']) ? 'hybrid' : 'strict';
        $config['ai_enabled'] = !empty($body['ai_enabled']);
        $config['graph_show_labels'] = !empty($body['graph_show_labels']);
        $config['graph_path'] = trim((string)($body['graph_path'] ?? ''), "/ \t");
        $config['default_light'] = !empty($body['default_light']);
        $config['front_drawer_expanded'] = !empty($body['front_drawer_expanded']);
        $fontPreset = trim((string)($body['font_preset'] ?? 'nunito'));
        $config['font_preset'] = in_array($fontPreset, ['nunito', 'serif']) ? $fontPreset : 'nunito';
        $config['pin_navbar'] = !empty($body['pin_navbar']);
        if (file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            fail('Failed to save config', 500);
        }
        $imaNotes = $renderIma ? ('ima ' . count($imaTest) . ' bases') : '';
        $minioNotes = $renderMinio ? ('MinIO ' . count($test) . ' items') : '';
        $notes = trim(trim($minioNotes . ' / ' . $imaNotes, ' '), ' /');
        ok(['tested' => $notes === '' ? 'skip' : $notes]);
    }

    // 修改密码：新密码失焦提交，需先验证旧密码（独立接口）
    if ($uri === '/api/admin/password' && $method === 'POST') {
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $old = (string)($body['old_password'] ?? '');
        $new = (string)($body['new_password'] ?? '');
        // 校验旧密码（防未授权改密）
        if (empty($config['password_hash']) || !password_verify($old, $config['password_hash'])) {
            fail('Current password is incorrect');
        }
        if (strlen($new) < 4) {
            fail('Password must be at least 4 characters');
        }
        $config['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        if (file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            fail('Failed to save config', 500);
        }
        ok();
    }

    fail('接口不存在', 404);
}

/* --- 页面：/admin --- */
if ($uri === '/admin') {
    $needsSetup = empty($config['password_hash']);
    if ($needsSetup || !is_authed()) {
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo $needsSetup ? 'Setup' : 'Login'; ?> — BrainPress</title>
        <script>
        (function () {
            try {
                if (localStorage.getItem('vp-theme') === 'dark') {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
        </script>
        <style>
        * { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        :root { --bg:#ffffff; --line:#e2e2e3; --vp-c-text-1:#3c3c43; --vp-c-text-3:#67676c; --vp-c-brand:#5672cd; --vp-c-bg-soft:#f6f6f7; }
        html.dark { --bg:#1b1b1f; --line:#2e2e32; --vp-c-text-1:#dfdfd6; --vp-c-text-3:#98989f; --vp-c-brand:#3e63dd; --vp-c-bg-soft:#161618; }
        *, *::before, *::after { transition:background-color .25s ease, color .25s ease, border-color .25s ease; }
        html, body { background:var(--bg); color:var(--vp-c-text-1); font-family:system-ui,-apple-system,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei","Noto Sans CJK SC",sans-serif; height:100%; }
        body { display:flex; align-items:center; justify-content:center; }
        .card { width:min(360px, 90vw); }
        .logo { font-size:24px; font-weight:800; letter-spacing:2px; margin-bottom:6px; }
        .sub { font-size:13px; color:var(--vp-c-text-3); margin-bottom:32px; }
        input {
            width:100%; background:var(--vp-c-bg-soft); border:1px solid var(--line);
            border-radius:6px; padding:12px 14px; font-size:16px; color:var(--vp-c-text-1);
            font-family:inherit; outline:none; margin-bottom:14px;
        }
        input:focus { border-color:var(--vp-c-brand); }
        button {
            width:100%; background:var(--vp-c-brand); color:#fff; border:none;
            border-radius:6px; padding:12px; font-size:15px; font-weight:600;
            cursor:pointer; font-family:inherit; outline:none;
        }
        .msg { margin-top:14px; font-size:13px; color:#ef4444; min-height:18px; text-align:center; }
        </style>
        </head>
        <body>
        <div class="card">
            <div class="logo">BrainPress</div>
            <div class="sub"><?php echo $needsSetup ? 'Set a password to protect this site' : 'Enter password to continue'; ?></div>
            <input type="password" id="pwd" placeholder="<?php echo $needsSetup ? 'New password' : 'Password'; ?>" autocomplete="off">
            <button id="go"><?php echo $needsSetup ? 'Set Password' : 'Login'; ?></button>
            <div class="msg" id="msg"></div>
        </div>
        <script>
        var needsSetup = <?php echo $needsSetup ? 'true' : 'false'; ?>;
        var btn = document.getElementById('go');
        var pwd = document.getElementById('pwd');
        var msg = document.getElementById('msg');
        function submit() {
            var p = pwd.value;
            if (!p) { msg.textContent = 'Please enter a password'; return; }
            fetch('/api/' + (needsSetup ? 'setup' : 'login'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: p })
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d.ok) {
                    location.href = '/admin';
                } else {
                    msg.textContent = d.error || 'Failed';
                }
            }).catch(function () { msg.textContent = 'Request failed'; });
        }
        btn.addEventListener('click', submit);
        pwd.addEventListener('keydown', function (e) { if (e.key === 'Enter') submit(); });
        pwd.focus();
        </script>
        </body>
        </html>
        <?php
        exit;
    }

    // 已登录：配置面板
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    $siteTitle = (string)($config['site_title'] ?? 'BrainPress');
    // Side drawer menu: PHP-generated (top-level System Settings + child views), click switches view
    $adminMenuMd = "- [System Settings](#)\n"
        . "  - [WebDAV](#view=dav)\n"
        . "  - [Mounts](#)\n"
        . "    - [Default Mount](#view=mounts)\n"
        . "    - [MinIO](#view=minio)\n"
        . "    - [ima](#view=ima)\n"
        . "  - [Preferences](#view=prefs)\n"
        . "  - [Site](#view=site)\n"
        . "  - [AI](#view=ai)\n"
        . "  - [Graph](#view=graph)\n"
        . "  - [Tree](#view=tree)\n";
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN" class="<?php echo (($_COOKIE['vp-theme'] ?? '') === 'dark') ? 'dark' : ''; ?>">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — BrainPress</title>
    <script>
    var DEFAULT_LIGHT = <?php echo json_encode($config['default_light'] ?? false); ?>;
    (function () {
        try {
            if (!DEFAULT_LIGHT && localStorage.getItem('vp-theme') === 'dark') {
                document.documentElement.classList.add('dark');
                document.cookie = 'vp-theme=dark; path=/';
            }
        } catch (e) {}
    })();
    </script>
<?php if (($config['font_preset'] ?? 'nunito') === 'serif'): ?>
<style>/* 字体预设：DejaVu Serif（自托管，开源 Bitstream Vera）——只管西文，中文走系统宋体 */
@font-face { font-family:'DejaVu Serif'; src:url('/assets/fonts/dejavu-serif.woff2') format('woff2'); font-weight:400; font-display:swap; }
@font-face { font-family:'DejaVu Serif'; src:url('/assets/fonts/dejavu-serif-bold.woff2') format('woff2'); font-weight:700; font-display:swap; }
html, body { font-family:"DejaVu Serif","Songti SC","STSong","SimSun","Noto Serif CJK SC",serif !important; }
#left-drawer-md, #drawer-md { font-family:"DejaVu Serif","Songti SC","STSong","SimSun","Noto Serif CJK SC",serif !important; }
#vp-logo { font-family:"DejaVu Serif","Songti SC","STSong","SimSun","Noto Serif CJK SC",serif !important; }
</style>
<?php endif; ?>
    <script src="/assets/marked.min.js"></script>
    <script src="/assets/purify.min.js?v=20260812o"></script>
    <link rel="stylesheet" href="/assets/admin.css?v=20260829c">
    <style>/* 阅读列宽（同前台）：覆盖 admin.css 的默认值 */
    :root { --vp-content-w:<?php echo max(480, min(1600, (int)($config['content_width'] ?? 840))); ?>px; }
    </style>
    </head>
    <body>
    <div id="app">
    <!-- 桌面端左侧常驻目录栏（同前台三栏布局） -->
    <aside id="left-sidebar">
        <div class="sidebar-header">Contents</div>
        <div class="drawer-md" id="left-drawer-md"></div>
    </aside>
    <div id="main">
        <!-- 第一层：VitePress 风格导航栏（与前台顶部栏完全一致） -->
        <div id="nav-wrap">
        <div id="vp-nav">
            <div id="vp-nav-left">
                <button class="vp-icon-btn" id="vp-menu-btn" aria-label="menu">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                </button>
                <button class="vp-icon-btn" id="vp-ai-btn" aria-label="ask AI">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                </button>
                <span id="vp-logo"><?php echo htmlspecialchars($siteTitle); ?></span>
            </div>
            <div id="vp-nav-right">
                <button class="vp-icon-btn theme-btn-mobile" id="vp-theme-btn-m" aria-label="theme">
                    <svg class="theme-sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                    <svg class="theme-moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                </button>
                <button class="vp-icon-btn theme-btn-desktop" id="vp-theme-btn" aria-label="theme">
                    <svg class="theme-sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                    <svg class="theme-moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                </button>
                <button class="vp-icon-btn" id="vp-home-btn" aria-label="back to home">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                </button>
            </div>
        </div>
        </div><!-- /nav-wrap -->
    <!-- Full-screen side drawer：渲染 _admin-menu.md 内容 -->
    <div id="vp-drawer">
        <div class="drawer-md" id="drawer-md"></div>
    </div>
    <div id="content">
    <div class="doc-wrap">
        <div class="doc-main">
        <!-- 视图：WebDAV 同步（多账号管理同步存储，账号各自绑定 vault 下子目录） -->
        <div id="view-dav" style="display:none">
            <p class="desc">WebDAV sync for Obsidian Remotely Save. Every sync account shares the same Server URL below — the account you log in with decides which folder it sees. Each account has its own username/password and mounts a sync folder under <code>vault/</code> (the whole vault if the folder is left empty), which is exactly what that account can read and write. Only <code>vault/</code> is ever exposed, never anything outside it.</p>
            <div class="field-row"><span class="field-label">Server URL</span><span class="field-value" id="dav-url">…</span><button class="copy-btn" onclick="copyVal('dav-url')">Copy</button></div>
            <div class="section-title">Sync accounts</div>
            <p class="desc" style="margin-bottom:8px">Directory is relative to <code>vault/</code> (empty = vault root). Give each account its own folder to keep notebooks separate.</p>
            <div id="dav-rows"></div>
            <div class="field-row"><button class="btn" id="btn-dav-add">+ Add sync account</button></div>
            <div class="field-row"><button class="btn primary" id="btn-dav-save">Save sync accounts</button></div>
            <div class="msg" id="msg-dav"></div>
        </div>

        <!-- View: Default Mount (render sources: Vault Render / Custom Path two-tab toggle) -->
        <div id="view-mounts">
            <div class="toggle two" id="toggle-mounts">
                <div class="toggle-thumb" id="toggle-mounts-thumb"></div>
                <div class="toggle-opt active" data-mode="vault">Vault Render</div>
                <div class="toggle-opt" data-mode="custom">Custom Path</div>
            </div>

            <div class="section" id="panel-vault">
                <p class="desc">One master switch for everything synced under <code>vault/</code> (all WebDAV sync folders). Off by default — syncing stores your notes, this switch publishes them to the frontend, search and graph. Rendering only reads files, it can't write or delete anything.</p>
                <div class="render-row"><span class="render-label">Render synced vault</span><button class="switch" id="switch-webdav" aria-label="toggle vault render"></button></div>
                <div class="msg" id="msg-vault"></div>
            </div>

            <div class="section" id="panel-custom" style="display:none">
                <p class="desc">Enter up to 5 local absolute paths on this server. A directory → render all Markdown inside it; a single .md file → render only that file. Each has its own on/off switch and saves when you toggle a switch or leave an input.</p>
                <div class="field-row"><input type="text" id="custom-path-1" placeholder="Path 1 · /srv/notes/memories"><button class="switch" id="switch-custom-1" aria-label="toggle custom 1 render"></button></div>
                <div class="field-row"><input type="text" id="custom-path-2" placeholder="Path 2 · /srv/notes/workspace"><button class="switch" id="switch-custom-2" aria-label="toggle custom 2 render"></button></div>
                <div class="field-row"><input type="text" id="custom-path-3" placeholder="Path 3"><button class="switch" id="switch-custom-3" aria-label="toggle custom 3 render"></button></div>
                <div class="field-row"><input type="text" id="custom-path-4" placeholder="Path 4"><button class="switch" id="switch-custom-4" aria-label="toggle custom 4 render"></button></div>
                <div class="field-row"><input type="text" id="custom-path-5" placeholder="Path 5"><button class="switch" id="switch-custom-5" aria-label="toggle custom 5 render"></button></div>
                <div class="msg" id="msg-custom"></div>
            </div>
        </div>

        <!-- View: MinIO (S3-compatible object storage plugin) -->
        <div id="view-minio" style="display:none">
            <div class="section">
                <p class="desc">Enter S3-compatible object storage (MinIO) config. The site reads Markdown files from this bucket, merges them into the tree (by name, local wins) and renders them.</p>
                <div class="render-row"><span class="render-label">Frontend render</span><button class="switch" id="switch-minio" aria-label="toggle minio render"></button></div>
                <div class="field-row"><span class="field-label">Endpoint</span><input type="text" id="minio-endpoint" placeholder="http://127.0.0.1:19000"></div>
                <div class="field-row"><span class="field-label">Access Key</span><input type="text" id="minio-access" placeholder="minio"></div>
                <div class="field-row"><span class="field-label">Secret Key</span><input type="text" id="minio-secret" placeholder="…"></div>
                <div class="field-row"><span class="field-label">Bucket</span><input type="text" id="minio-bucket" placeholder="vault"></div>
                <div class="msg" id="msg-minio"></div>
            </div>
        </div>

        <!-- View: ima knowledge base mount (standalone config page) -->
        <div id="view-ima" style="display:none">
            <div class="section">
                <p class="desc">Tencent ima knowledge base mount. After entering your ima OpenAPI credentials, your own knowledge bases (knowledge base → folder → file) are flattened into the file tree as an independent content source and rendered live — PDF via the pdf.js reader, md via Markdown, both fetched on demand through the server proxy without being stored on this site. Files in subscription knowledge bases can't be fetched via the API and are excluded.</p>
                <div class="render-row"><span class="render-label">Render ima</span><button class="switch" id="switch-ima" aria-label="toggle ima render"></button></div>
                <div class="field-row"><span class="field-label">ima Client ID</span><input type="text" id="ima-client-id" placeholder="da82a2a7..." autocomplete="off"></div>
                <div class="field-row"><span class="field-label">ima API Key</span><input type="password" id="ima-api-key" placeholder="…"></div>
                <div class="msg" id="msg-ima"></div>
            </div>
        </div>

        <!-- 视图：偏好设置（开关即保存） -->
        <div id="view-prefs" style="display:none">
            <p class="desc">Site behavior preferences. Switches save immediately.</p>
            <div class="render-row"><span class="render-label">Default light mode</span><button class="switch" id="switch-light" aria-label="toggle default light"></button></div>
            <div class="render-row"><span class="render-label">Pin navbar (always visible)</span><button class="switch" id="switch-pin-nav" aria-label="toggle pin navbar"></button></div>
            <div class="render-row"><span class="render-label">Font preset</span>
                <select id="font-preset" class="font-select">
                    <option value="nunito">Sans (System)</option>
                    <option value="serif">Serif (DejaVu Serif)</option>
                </select>
            </div>
            <div class="msg" id="msg-prefs"></div>
        </div>

        <!-- 视图：站点设置（标题 + 首页文章 + 密码修改） -->
        <div id="view-site" style="display:none">
            <p class="desc">Site identity, home page article and admin password. Paths are relative to vault/ (e.g. knowledge/article/note.md). Title and article save on blur; new password saves on blur after verifying current password.</p>
            <div class="field-row"><span class="field-label">Site title</span><input type="text" id="site-title" placeholder="BrainPress"></div>
            <div class="field-row"><span class="field-label">Home article</span><input type="text" id="home-article" placeholder="knowledge/article/your-note.md"></div>
            <div class="field-row"><span class="field-label">Content width</span><input type="text" id="content-width" placeholder="840 (px) — reading column width; side rails auto-balance"></div>
            <div class="field-row"><span class="field-label">Current password</span><input type="password" id="site-password-old" placeholder="Enter current password" autocomplete="current-password"></div>
            <div class="field-row"><span class="field-label">New password</span><input type="password" id="site-password" placeholder="Min 4 chars, blur to save" autocomplete="new-password"></div>
            <div class="msg" id="msg-site"></div>
        </div>

        <!-- 视图：Graph（知识图谱设置：文件名显示 + 访问路径别名） -->
        <div id="view-graph" style="display:none">
            <p class="desc">Knowledge graph view settings. Show file names controls whether node labels are always visible or only on hover.</p>
            <div class="render-row"><span class="render-label">Show file names</span><button class="switch" id="switch-graph-labels" aria-label="toggle graph file name labels"></button></div>
            <div class="field-row"><span class="field-label">Graph path alias</span><input type="text" id="graph-path" placeholder="e.g. Visual-Knowledge/graph — tree entry + 302 to /graph; empty = bottom entry"></div>
            <div class="msg" id="msg-graph"></div>
        </div>

        <!-- 视图：Tree（目录管理：默认展开 + 置顶 + 强制展开 + 隐藏路径） -->
        <div id="view-tree" style="display:none">
            <p class="desc">Front drawer behavior: default expand, pinned directory on top, pinned articles first in their directory, and directories forced expanded regardless of the default toggle. All four lists below support two notations: a relative path (or bare name) targets the main vault only; an absolute path (/...) targets an entry inside a custom mount directory.</p>
            <div class="render-row"><span class="render-label">Front drawer expanded</span><button class="switch" id="switch-drawer" aria-label="toggle front drawer expanded"></button></div>
            <div class="section-title">Pinned dirs</div>
            <div class="field-row"><input type="text" id="pinned-dir-input" placeholder="draft or /mnt/vault/Mechanic"><button class="btn" id="btn-pinned-dir-add">Add</button></div>
            <div id="pinned-dir-list"></div>
            <div class="section-title">Pinned articles</div>
            <div class="field-row"><input type="text" id="pinned-article-input" placeholder="knowledge/a.md or /mnt/vault/Prompts/note.md"><button class="btn" id="btn-pinned-article-add">Add</button></div>
            <div id="pinned-article-list"></div>
            <div class="section-title">Expanded dirs</div>
            <div class="field-row"><input type="text" id="expanded-dir-input" placeholder="draft or /mnt/vault/Mechanic/sub"><button class="btn" id="btn-expanded-dir-add">Add</button></div>
            <div id="expanded-dir-list"></div>
            <div class="section-title">Hidden paths</div>
            <p class="desc" style="margin-bottom:8px">Hidden from the frontend: a bare name hides that name in the main vault; an exact main-vault path hides one file there; an absolute path hides one file (or a whole subtree) inside a custom mount. Removed from tree, search and direct access.</p>
            <div class="field-row"><input type="text" id="exclude-input" placeholder="draft, private/secret.md or /mnt/vault/tmp/"><button class="btn" id="btn-exclude-add">Add</button></div>
            <div id="exclude-list"></div>
            <div class="msg" id="msg-tree"></div>
        </div>
        <!-- 视图：AI 配置（内容区，菜单可访问） -->
        <div id="view-ai" style="display:none">
            <p class="desc">AI chat integration settings. Any OpenAI-compatible chat endpoint works. Agent API details are at the bottom.</p>
            <div class="toggle two" id="toggle-ai">
                <div class="toggle-thumb" id="toggle-ai-thumb"></div>
                <div class="toggle-opt active" data-tab="chat">Chat Model</div>
                <div class="toggle-opt" data-tab="agent">Agent API</div>
            </div>
            <div class="section" id="panel-ai-chat">
                <p class="desc">AI chat integration — any OpenAI-compatible chat endpoint works.</p>
                <div class="field-row"><span class="field-label">API base URL</span><input type="text" id="ai-api-base" placeholder="https://api.deepseek.com · http://127.0.0.1:11434/v1"></div>
                <div class="field-row"><span class="field-label">API key</span><input type="text" id="ai-api-key" placeholder="Empty = AI chat disabled"></div>
                <div class="field-row"><span class="field-label">Model</span><input type="text" id="ai-model" placeholder="deepseek-chat"></div>
                <div class="render-row"><span class="render-label">AI enabled</span><button class="switch" id="switch-ai-enabled" aria-label="toggle AI enabled"></button></div>
                <div class="render-row"><span class="render-label">Hybrid mode</span><button class="switch" id="switch-ai-mode" aria-label="toggle AI hybrid mode"></button></div>
                <div class="render-row"><button class="btn" id="btn-ai-test">Test connection</button></div>
                <div class="msg" id="msg-ai"></div>
            </div>
            <div class="section" id="panel-ai-agent" style="display:none">
                <p class="desc">Agent API access details for remote knowledge base operation.</p>
                <div class="field-row"><span class="field-label">Base URL</span><code id="agent-base-url" style="flex:1;font-size:12px;"></code><button class="btn" id="btn-agent-copy-url">Copy</button></div>
                <div class="field-row"><span class="field-label">Bearer token</span><input type="text" id="api-token" placeholder="Empty = write API disabled"></div>
                <div class="msg" id="msg-agent"></div>
            </div>
        </div>

        </div><!-- /.doc-main -->
        </div><!-- /.doc-wrap -->
    </div><!-- /#content -->
    <!-- AI 对话面板（同前台：桌面端填充左栏，窄屏端走抽屉） -->
    <div class="ai-view" id="ai-view">
        <div class="ai-header">Ask the knowledge base</div>
        <div class="ai-msgs" id="ai-msgs">
            <div class="ai-msg ai-bot">Hi! Ask me anything — I answer based on the articles in this knowledge base.</div>
        </div>
        <div class="ai-input-wrap">
            <input type="text" id="ai-input" placeholder="Ask a question..." autocomplete="off" spellcheck="false">
            <button class="ai-send" id="ai-send">Send</button>
        </div>
    </div>
    </div><!-- /#main -->
    <!-- 桌面端右侧轨道（同前台结构，后台无 TOC） -->
    <aside id="right-sidebar"></aside>
    </div><!-- /#app -->
    <script>
    window.ADMIN_MENU_MD = <?php echo json_encode($adminMenuMd); ?>;
    </script>
    <script src="/assets/admin.js?v=20260829d"></script>
    </body>
    </html>
    <?php
    exit;
}

http_response_code(404);
exit;
