<?php
/**
 * MD2HTML v1.1.0 — 后台管理（独立入口，nginx 将 /admin 和 /api/admin/ 转发到此）
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
            'storage' => $storage,
            'webdav_user' => $config['webdav_user'] ?? '',
            'webdav_pass' => $config['webdav_pass'] ?? '',
            'webdav_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'docs.dasiwo.com') . '/dav/',
            'render_webdav' => $config['render_webdav'] ?? true,
            'render_minio' => $config['render_minio'] ?? true,
            'custom_paths' => $config['custom_paths'] ?? [],
            'exclude_paths' => $config['exclude_paths'] ?? [],
            'pinned_dirs' => $config['pinned_dirs'] ?? [],
            'pinned_articles' => $config['pinned_articles'] ?? [],
            'expanded_dirs' => $config['expanded_dirs'] ?? [],
            'site_title' => $config['site_title'] ?? 'MD2HTML',
            'home_article' => $config['home_article'] ?? '',
            'api_token' => $config['api_token'] ?? '',
            'ai_api_key' => $config['ai_api_key'] ?? '',
            'ai_model' => $config['ai_model'] ?? 'deepseek-chat',
            'ai_mode' => $config['ai_mode'] ?? 'hybrid',
            'ai_enabled' => $config['ai_enabled'] ?? true,
            'graph_show_labels' => $config['graph_show_labels'] ?? false,
            'default_light' => $config['default_light'] ?? false,
            'front_drawer_expanded' => $config['front_drawer_expanded'] ?? true,
            'minio' => [
                'endpoint' => $config['minio_endpoint'] ?? 'http://127.0.0.1:19000',
                'access' => $config['minio_access'] ?? 'minio',
                'secret' => $config['minio_secret'] ?? '',
                'bucket' => $config['minio_bucket'] ?? 'vault',
            ],
        ]);
    }
    if ($uri === '/api/admin/config' && $method === 'POST') {
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $endpoint = trim((string)($body['endpoint'] ?? ''));
        $access = trim((string)($body['access'] ?? ''));
        $secret = trim((string)($body['secret'] ?? ''));
        $bucket = trim((string)($body['bucket'] ?? ''));
        $storageMode = ($body['storage'] ?? '') === 'minio' ? 'minio' : 'local';
        $renderMinio = !empty($body['render_minio']);
        $test = [];
        // 仅当 MinIO 渲染开关开启时才要求填 MinIO 字段并做连接测试
        if ($renderMinio || $storageMode === 'minio') {
            if ($endpoint === '' || $access === '' || $secret === '' || $bucket === '') {
                fail('All fields are required');
            }
            // 校验 MinIO 连接：列桶测试
            $GLOBALS['minioEndpoint'] = $endpoint;
            $GLOBALS['minioAccess'] = $access;
            $GLOBALS['minioSecret'] = $secret;
            $GLOBALS['minioBucket'] = $bucket;
            $test = minio_ls('');
            if ($test === [] && $storageMode === 'minio') {
                fail('Cannot connect to MinIO, check endpoint/key/bucket');
            }
        }
        $config['minio_endpoint'] = $endpoint;
        $config['minio_access'] = $access;
        $config['minio_secret'] = $secret;
        $config['minio_bucket'] = $bucket;
        $config['storage'] = $storageMode;
        // 渲染开关（多选）
        $config['render_webdav'] = !empty($body['render_webdav']);
        $config['render_minio'] = $renderMinio;
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
        $config['site_title'] = trim((string)($body['site_title'] ?? '')) !== '' ? trim((string)$body['site_title']) : ($config['site_title'] ?? 'MD2HTML');
        $config['home_article'] = trim((string)($body['home_article'] ?? ''));
        $config['api_token'] = trim((string)($body['api_token'] ?? ''));
        $config['ai_api_key'] = trim((string)($body['ai_api_key'] ?? ''));
        $config['ai_model'] = trim((string)($body['ai_model'] ?? '')) !== '' ? trim((string)$body['ai_model']) : 'deepseek-chat';
        $config['ai_mode'] = !empty($body['ai_mode']) ? 'hybrid' : 'strict';
        $config['ai_enabled'] = !empty($body['ai_enabled']);
        $config['graph_show_labels'] = !empty($body['graph_show_labels']);
        $config['default_light'] = !empty($body['default_light']);
        $config['front_drawer_expanded'] = !empty($body['front_drawer_expanded']);
        if (file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            fail('Failed to save config', 500);
        }
        ok(['storage' => $storageMode, 'tested' => $renderMinio ? count($test) . ' 项' : 'skip']);
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
        <title><?php echo $needsSetup ? 'Setup' : 'Login'; ?> — MD2HTML</title>
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
/* Nunito 本地化（Google Fonts 国内不稳，字体切换导致页面颤动） */
@font-face { font-family:'Nunito'; src:url('/assets/fonts/nunito-400.woff2') format('woff2'); font-weight:400; font-display:optional; }
@font-face { font-family:'Nunito'; src:url('/assets/fonts/nunito-600.woff2') format('woff2'); font-weight:600; font-display:optional; }
@font-face { font-family:'Nunito'; src:url('/assets/fonts/nunito-700.woff2') format('woff2'); font-weight:700; font-display:optional; }
@font-face { font-family:'Nunito'; src:url('/assets/fonts/nunito-800.woff2') format('woff2'); font-weight:800; font-display:optional; }
</style>
<link rel="preload" href="/assets/fonts/nunito-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/nunito-600.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/nunito-700.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/nunito-800.woff2" as="font" type="font/woff2" crossorigin>
        <style>
        * { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        :root { --bg:#ffffff; --line:#e2e2e3; --vp-c-text-1:#3c3c43; --vp-c-text-3:#67676c; --vp-c-brand:#3451b2; --vp-c-bg-soft:#f6f6f7; }
        html.dark { --bg:#1b1b1f; --line:#2e2e32; --vp-c-text-1:#dfdfd6; --vp-c-text-3:#98989f; --vp-c-brand:#a8b1ff; --vp-c-bg-soft:#161618; }
        *, *::before, *::after { transition:background-color .25s ease, color .25s ease, border-color .25s ease; }
        html, body { background:var(--bg); color:var(--vp-c-text-1); font-family:"Nunito","PingFang SC","Microsoft YaHei",sans-serif; height:100%; }
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
            <div class="logo">MD2HTML</div>
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
    $siteTitle = (string)($config['site_title'] ?? 'MD2HTML');
    // 侧滑菜单：PHP 生成（一级 System Settings + 五个子视图），点击切换视图
    $adminMenuMd = "- [System Settings](#)\n"
        . "  - [Mounts](#view=mounts)\n"
        . "  - [Preferences](#view=prefs)\n"
        . "  - [Site](#view=site)\n"
        . "  - [AI](#view=ai)\n"
        . "  - [Graph](#view=graph)\n"
        . "  - [Hide](#view=hidden)\n"
        . "  - [Tree](#view=tree)\n";
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — MD2HTML</title>
    <script>
    var DEFAULT_LIGHT = <?php echo json_encode($config['default_light'] ?? false); ?>;
    (function () {
        try {
            if (!DEFAULT_LIGHT && localStorage.getItem('vp-theme') === 'dark') {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    })();
    </script>
<style>
/* Nunito 本地化（Google Fonts 国内不稳，字体切换导致页面颤动） */
@font-face { font-family:'Nunito'; src:url('/assets/fonts/nunito-400.woff2') format('woff2'); font-weight:400; font-display:optional; }
@font-face { font-family:'Nunito'; src:url('/assets/fonts/nunito-600.woff2') format('woff2'); font-weight:600; font-display:optional; }
@font-face { font-family:'Nunito'; src:url('/assets/fonts/nunito-700.woff2') format('woff2'); font-weight:700; font-display:optional; }
@font-face { font-family:'Nunito'; src:url('/assets/fonts/nunito-800.woff2') format('woff2'); font-weight:800; font-display:optional; }
</style>
    <script src="/assets/marked.min.js"></script>
    <script src="/assets/purify.min.js?v=20260812o"></script>
    <link rel="stylesheet" href="/assets/admin.css?v=20260812r">
    </head>
    <body>
        <!-- 第一层：VitePress 风格导航栏（与前台顶部栏完全一致，仅右侧内容不同） -->
        <div id="nav-wrap">
        <div id="vp-nav">
            <div id="vp-nav-left">
                <!-- 菜单按钮：位置与前台完全一致（最左） -->
                <button class="vp-icon-btn" id="vp-menu-btn" aria-label="menu">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
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
    <div class="wrap">
        <!-- 视图：挂载设置（WebDAV / MinIO / Custom Path） -->
        <div id="view-mounts">
        <!-- 滑动切换开关：WebDAV Mount / MinIO Storage / Custom Path -->
        <div class="toggle" id="toggle">
            <div class="toggle-thumb" id="toggle-thumb"></div>
            <div class="toggle-opt active" data-mode="webdav">WebDAV Mount</div>
            <div class="toggle-opt" data-mode="minio">MinIO Storage</div>
            <div class="toggle-opt" data-mode="custom">Custom Path</div>
        </div>

        <!-- WebDAV 挂载信息（滑块在左时显示） -->
        <div class="section" id="panel-webdav">
            <p class="desc">Fill in the following in Obsidian Remotely Save to sync notes to this site (mounted to the <code>vault/</code> directory, rendered directly).</p>
            <div class="render-row"><span class="render-label">Frontend render</span><button class="switch" id="switch-webdav" aria-label="toggle webdav render"></button></div>
            <div class="field-row"><span class="field-label">Server URL</span><span class="field-value" id="dav-url">…</span><button class="copy-btn" onclick="copyVal('dav-url')">Copy</button></div>
            <div class="field-row"><span class="field-label">Username</span><span class="field-value" id="dav-user">…</span><button class="copy-btn" onclick="copyVal('dav-user')">Copy</button></div>
            <div class="field-row"><span class="field-label">Password</span><span class="field-value" id="dav-pass">…</span><button class="copy-btn" onclick="copyVal('dav-pass')">Copy</button></div>
            <div class="field-row"><span class="field-label">Auth Type</span><span class="field-value">WebDAV (Basic Auth)</span></div>
            <div class="msg" id="msg-webdav"></div>
        </div>

        <!-- MinIO 配置表单（滑块在右时显示） -->
        <div class="section" id="panel-minio" style="display:none">
            <p class="desc">Enter S3-compatible object storage (MinIO) config. The site will read Markdown files from this bucket and render them.</p>
            <div class="render-row"><span class="render-label">Frontend render</span><button class="switch" id="switch-minio" aria-label="toggle minio render"></button></div>
            <div class="field-row"><span class="field-label">Endpoint</span><input type="text" id="minio-endpoint" placeholder="http://127.0.0.1:19000"></div>
            <div class="field-row"><span class="field-label">Access Key</span><input type="text" id="minio-access" placeholder="minio"></div>
            <div class="field-row"><span class="field-label">Secret Key</span><input type="text" id="minio-secret" placeholder="…"></div>
            <div class="field-row"><span class="field-label">Bucket</span><input type="text" id="minio-bucket" placeholder="vault"></div>
            <div class="msg" id="msg"></div>
        </div>

        <!-- 自定义本地路径（滑块在 Custom 时显示，最多 5 条：左输入框 + 右开关） -->
        <div class="section" id="panel-custom" style="display:none">
            <p class="desc">Enter up to 5 local absolute paths on this server. Directory → render all Markdown inside it; a single .md file → render only that file.</p>
            <div class="field-row"><input type="text" id="custom-path-1" placeholder="Path 1 · /root/.hermes/memories"><button class="switch" id="switch-custom-1" aria-label="toggle custom 1 render"></button></div>
            <div class="field-row"><input type="text" id="custom-path-2" placeholder="Path 2 · /root/.hermes/workspace"><button class="switch" id="switch-custom-2" aria-label="toggle custom 2 render"></button></div>
            <div class="field-row"><input type="text" id="custom-path-3" placeholder="Path 3"><button class="switch" id="switch-custom-3" aria-label="toggle custom 3 render"></button></div>
            <div class="field-row"><input type="text" id="custom-path-4" placeholder="Path 4"><button class="switch" id="switch-custom-4" aria-label="toggle custom 4 render"></button></div>
            <div class="field-row"><input type="text" id="custom-path-5" placeholder="Path 5"><button class="switch" id="switch-custom-5" aria-label="toggle custom 5 render"></button></div>
            <div class="msg" id="msg-custom"></div>
        </div>
        </div>

        <!-- 视图：偏好设置（开关即保存） -->
        <div id="view-prefs" style="display:none">
            <p class="desc">Site behavior preferences. Switches save immediately.</p>
            <div class="render-row"><span class="render-label">Default light mode</span><button class="switch" id="switch-light" aria-label="toggle default light"></button></div>
            <div class="msg" id="msg-prefs"></div>
        </div>

        <!-- 视图：站点设置（标题 + 首页文章 + 密码修改） -->
        <div id="view-site" style="display:none">
            <p class="desc">Site identity, home page article and admin password. Paths are relative to vault/ (e.g. knowledge/article/note.md). Title and article save on blur; new password saves on blur after verifying current password.</p>
            <div class="field-row"><span class="field-label">Site title</span><input type="text" id="site-title" placeholder="MD2HTML"></div>
            <div class="field-row"><span class="field-label">Home article</span><input type="text" id="home-article" placeholder="knowledge/article/your-note.md"></div>
            <div class="field-row"><span class="field-label">API token</span><input type="text" id="api-token" placeholder="Empty = write API disabled (e.g. openssl rand -hex 32)"></div>
            <div class="field-row"><span class="field-label">Current password</span><input type="password" id="site-password-old" placeholder="Enter current password" autocomplete="current-password"></div>
            <div class="field-row"><span class="field-label">New password</span><input type="password" id="site-password" placeholder="Min 4 chars, blur to save" autocomplete="new-password"></div>
            <div class="msg" id="msg-site"></div>
        </div>

        <!-- 视图：AI（对话接入设置：API key + model + 连接测试） -->
        <div id="view-ai" style="display:none">
            <p class="desc">AI chat integration. The DeepSeek API key enables the ask button on the front site (retrieval + answer with sources). The key is stored in config.json only — never exposed to visitors. Fields save on blur.</p>
            <div class="field-row"><span class="field-label">AI API key</span><input type="text" id="ai-api-key" placeholder="Empty = AI chat disabled (DeepSeek key, platform.deepseek.com)"></div>
            <div class="field-row"><span class="field-label">AI model</span><input type="text" id="ai-model" placeholder="deepseek-chat"></div>
            <div class="render-row"><span class="render-label">AI enabled</span><button class="switch" id="switch-ai-enabled" aria-label="toggle AI enabled"></button></div>
            <div class="render-row"><span class="render-label">Hybrid mode</span><button class="switch" id="switch-ai-mode" aria-label="toggle AI hybrid mode"></button></div>
            <p class="desc">AI enabled: master switch — off hides the ask button and rejects /api/ask. Hybrid mode: on = knowledge base first with general fallback, off (strict) = answers only from the knowledge base.</p>
            <div class="render-row"><button class="btn" id="btn-ai-test">Test connection</button></div>
            <div class="msg" id="msg-ai"></div>
        </div>

        <!-- 视图：Graph（知识图谱设置：文件名显示等） -->
        <div id="view-graph" style="display:none">
            <p class="desc">Knowledge graph view settings. Show file names controls whether node labels are always visible or only on hover.</p>
            <div class="render-row"><span class="render-label">Show file names</span><button class="switch" id="switch-graph-labels" aria-label="toggle graph file name labels"></button></div>
            <div class="msg" id="msg-graph"></div>
        </div>

        <!-- View: Hide (paths hidden from the frontend) -->
        <div id="view-hidden" style="display:none">
            <p class="desc">Hidden from the frontend: enter a directory name to hide the whole directory, or an exact file path to hide one file. Removed from tree, search and direct access.</p>
            <div class="field-row"><input type="text" id="exclude-input" placeholder="draft or private/secret.md"><button class="btn" id="btn-exclude-add">Add</button></div>
            <div id="exclude-list"></div>
            <div class="msg" id="msg-hidden"></div>
        </div>

        <!-- 视图：Tree（目录管理：默认展开 + 置顶 + 强制展开） -->
        <div id="view-tree" style="display:none">
            <p class="desc">Front drawer behavior: default expand, pinned directory on top, pinned articles first in their directory, and directories forced expanded regardless of the default toggle.</p>
            <div class="render-row"><span class="render-label">Front drawer expanded</span><button class="switch" id="switch-drawer" aria-label="toggle front drawer expanded"></button></div>
            <div class="section-title">Pinned dirs</div>
            <div class="field-row"><input type="text" id="pinned-dir-input" placeholder="draft (top of the tree)"><button class="btn" id="btn-pinned-dir-add">Add</button></div>
            <div id="pinned-dir-list"></div>
            <div class="section-title">Pinned articles</div>
            <div class="field-row"><input type="text" id="pinned-article-input" placeholder="knowledge/article/note.md"><button class="btn" id="btn-pinned-article-add">Add</button></div>
            <div id="pinned-article-list"></div>
            <div class="section-title">Expanded dirs</div>
            <div class="field-row"><input type="text" id="expanded-dir-input" placeholder="draft (always expanded)"><button class="btn" id="btn-expanded-dir-add">Add</button></div>
            <div id="expanded-dir-list"></div>
            <div class="msg" id="msg-tree"></div>
        </div>
    </div>
    <script>
    // 侧滑菜单内容：服务端内联的 _admin-menu.md（零额外请求）
    window.ADMIN_MENU_MD = <?php echo json_encode($adminMenuMd); ?>;
    </script>
    <script src="/assets/admin.js?v=20260814b"></script>
    </body>
    </html>
    <?php
    exit;
}

http_response_code(404);
exit;
