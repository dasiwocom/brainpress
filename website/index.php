<?php
/**
 * BrainPress v1.1.0 — 文档站（主站入口）
 * 公共函数层见 functions.php；公开 API 见 api.php；WebDAV 见 dav.php；
 * 后台见 admin.php（nginx 转发 /admin、/api/admin/）。
 */

require __DIR__ . '/functions.php';
require __DIR__ . '/dav.php';
require __DIR__ . '/api.php';

/* ---------- 路由 ---------- */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 静态资源由 PHP 直接读取（不依赖内置服务器的文档根目录）
if (strpos($uri, '/assets/') === 0) {
    $f = __DIR__ . $uri;
    if (is_file($f)) {
        $mimeMap = [
            'js' => 'application/javascript', 'css' => 'text/css',
            'woff2' => 'font/woff2', 'woff' => 'font/woff',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
        ];
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: max-age=3600');
        readfile($f);
        exit;
    }
    fail('Not Found', 404);
}

// /vault/ 缺失文件兜底（GET）：主 vault 没有的静态资源，从启用的自定义挂载目录流式输出。
// 前端引用固定走 /vault/<rel>（pdf.js 字节流、md 内嵌图片），挂载外部目录后同样要可读；
// 网关侧只需一条「/vault/ 缺失 → index.php」重写即可（Docker 镜像已内置）。md/canvas 不在此
// 流式输出：交给下方渲染管线成页面（直达/刷新必须仍进应用而非下载原始文件）。
if (strpos($uri, '/vault/') === 0 && $method === 'GET') {
    $rel = substr(ltrim(rawurldecode($uri), '/'), strlen('vault/'));
    if ($rel !== '' && strpos($rel, '..') === false && !preg_match('~\.(md|canvas)$~i', $rel)) {
        $mimeMap = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'bmp' => 'image/bmp', 'avif' => 'image/avif',
            'pdf' => 'application/pdf', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'txt' => 'text/plain; charset=utf-8',
        ];
        foreach (custom_mount_roots($config) as $m) {
            if ($m['isFile']) continue;
            $full = realpath($m['root'] . '/' . $rel);
            if ($full === false || strpos($full, $m['root'] . '/') !== 0 || !is_file($full)) continue;
            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
            header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
            header('Content-Length: ' . (string)filesize($full));
            readfile($full);
            exit;
        }
        // ima mount static files (PDF, etc.): proxy-fetch then forward (download requires X-IMA-* headers)
        if (ima_index_lookup($config, $rel) !== null) {
            if (ima_stream_file($config, $rel)) exit;
        }
        fail('Not Found', 404);
    }
}

// 虚拟页别名路径：访问配置的别名 → 302 跳转真实路由（页面本体不变）
$graphAlias = trim((string)($config['graph_path'] ?? ''), "/ \t");
if ($graphAlias !== '' && !preg_match('#\.md$#i', $graphAlias) && $uri === '/' . $graphAlias) {
    header('Location: /graph', true, 302);
    exit;
}

// WebDAV 端点（Obsidian Remotely Save 同步）：实现在 dav.php
if (strpos($uri, '/dav/') === 0 || $uri === '/dav') {
    handle_webdav($uri, $method, $config);
}

// HTML 页面禁用缓存，避免用户看到旧版本
if ($uri === '/' || $uri === '/index.php') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

/* --- API --- */
// 公开 API（/api/*）：实现在 api.php；/api/admin/* 由 nginx 直转 admin.php
if (strpos($uri, '/api/') === 0) {
    handle_api($uri, $method, $config);
}

/* --- 页面 --- */

// 服务端渲染文章/Excalidraw：/xxx.md → 读 md 内联到页面（打开即内容，无 JS fetch 等待）。
// Excalidraw 判定：扩展名 .excalidraw.md，或内容特征（excalidraw-plugin 标记 + compressed-json 块）——
// 后者兜底 Obsidian 导出/重命名丢失后缀的绘画文件（否则压缩数据会被当 md 渲染成一屏乱码字母）
$ssrArticlePath = '';
$ssrArticleContent = '';
$ssrExcalidrawPath = '';
$ssrExcalidrawContent = '';
if (preg_match('#\.md$#i', $uri)) {
    $rel = urldecode(ltrim($uri, '/'));
    $full = resolve_vault_file($rel, $config);   // 主 vault 或自定义挂载目录
    if ($full !== null) {
        if (!is_excluded($rel, $config['exclude_paths'] ?? [])) {
            $rawContent = (string)@file_get_contents($full);
            $isExcalidraw = preg_match('/\.excalidraw\.md$/i', $rel)
                || (strpos($rawContent, 'excalidraw-plugin:') !== false && preg_match('/^```compressed-json\s*$/m', $rawContent));
            if ($isExcalidraw) {
                $ssrExcalidrawPath = $rel;
                $ssrExcalidrawContent = $rawContent;
            } else {
                $ssrArticlePath = $rel;
                $ssrArticleContent = $rawContent;
            }
        }
    } elseif (ima_index_lookup($config, $rel) !== null) {
        // ima mount md: proxy-fetch body and inline it (open-and-read)
        if (!is_excluded($rel, $config['exclude_paths'] ?? [])) {
            $raw = ima_read_raw($config, $rel);
            if ($raw !== null) {
                $ssrArticlePath = $rel;
                $ssrArticleContent = $raw['bytes'];
            }
        }
    }
}

// 服务端渲染 PDF：/xxx.pdf → 内联路径，前端 pdf.js 渲染阅读器（打开即阅读器，无跳转/下载）
$ssrPdfPath = '';
if (preg_match('#\.pdf$#i', $uri)) {
    $pdfRel = urldecode(ltrim($uri, '/'));
    $pdfFull = resolve_vault_file($pdfRel, $config);
    if ($pdfFull !== null) {
        if (!is_excluded($pdfRel, $config['exclude_paths'] ?? [])) {
            $ssrPdfPath = $pdfRel;
        }
    } elseif (ima_index_lookup($config, $pdfRel) !== null) {
        // ima mount PDF: frontend streams it via /vault/<rel> (server proxy)
        if (!is_excluded($pdfRel, $config['exclude_paths'] ?? [])) {
            $ssrPdfPath = $pdfRel;
        }
    }
}

// 服务端渲染 Canvas：/xxx.canvas（Obsidian Canvas 白板，JSON 格式）→ 内联原文，前端渲染画布
$ssrCanvasPath = '';
$ssrCanvasContent = '';
if (preg_match('#\.canvas$#i', $uri)) {
    $canvasRel = urldecode(ltrim($uri, '/'));
    $canvasFull = resolve_vault_file($canvasRel, $config);
    if ($canvasFull !== null) {
        if (!is_excluded($canvasRel, $config['exclude_paths'] ?? [])) {
            $ssrCanvasPath = $canvasRel;
            $ssrCanvasContent = (string)@file_get_contents($canvasFull);
        }
    }
}

// 服务端渲染 Graph View：/graph 或 /graph?dir=xxx → 前端渲染知识图谱（虚拟路径，非文件）
$ssrGraph = preg_match('#^/graph(/|\\?|$)#', $uri) ? true : false;

if ($uri !== '/' && $uri !== '/index.php' && $ssrArticlePath === '' && $ssrPdfPath === '' && $ssrExcalidrawPath === '' && $ssrCanvasPath === '' && !$ssrGraph) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
// SSR 页面不缓存（CDN/浏览器）：防旧缓存导致文章/PDF 更新不生效
header('Cache-Control: no-cache, must-revalidate');
// 前台侧滑菜单：扫描 vault/ 生成文章目录树（md 嵌套列表，内联零请求；隐藏列表不收录，置顶排最前）
// 自定义挂载与主 vault 平权：合并进同一棵树（同名主 vault 优先）
// 主 vault 受「WebDAV 渲染」开关控制（与 /api/list、/api/file 同一开关）：关=目录不显示（挂载目录走各自开关不受影响）
$frontMenuMd = '';
$frontTree = (($config['render_webdav'] ?? false) && is_dir(PANEL_DIR . '/vault'))
    ? scan_tree(PANEL_DIR . '/vault', '', $config['exclude_paths'] ?? [], $config['pinned_dirs'] ?? [], $config['pinned_articles'] ?? [])
    : [];
$frontMenuMd = tree_to_md(merge_ima_tree(merge_custom_trees($frontTree, $config), $config));
// Graph View 虚拟条目：仅在未配置别名路径时放进树末尾（配置了别名则由 JS 注入到目标目录）
if (trim((string)($config['graph_path'] ?? ''), "/ \t") === '') {
    $frontMenuMd = rtrim($frontMenuMd) . "\n- [Graph-View](/graph)";
}
// 站点设置：标题 / 默认日间 / 前台抽屉默认展开 / 首页文章
$siteTitle = (string)($config['site_title'] ?? 'BrainPress');
// 内容区宽度（后台可调，px）：三栏模型的中栏度量，左右轨道 = (视口−内容宽)/2 封顶 600
$contentW = (int)($config['content_width'] ?? 840);
if ($contentW < 480) $contentW = 480;
if ($contentW > 1600) $contentW = 1600;
// 页面标题：文件路径访问时用文件名（去扩展名，保留数字前缀，与目录树一致），否则站点标题
$pageTitle = $ssrArticlePath !== ''
    ? preg_replace('/\.md$/i', '', basename($ssrArticlePath)) . ' · ' . $siteTitle
    : ($ssrPdfPath !== ''
        ? preg_replace('/\.pdf$/i', '', basename($ssrPdfPath)) . ' · ' . $siteTitle
        : $siteTitle);
$defaultLight = !empty($config['default_light']);
$frontDrawerExpanded = !empty($config['front_drawer_expanded'] ?? true);
// 首页文章：配置的路径（绝对路径或 vault/ 相对路径，容错带前导 / 的误填）→ 读 md 内容内联渲染正文
$homeMd = '';
$homeArticle = trim((string)($config['home_article'] ?? ''));
if ($homeArticle !== '') {
    $homeFile = '';
    // 1) 绝对路径尝试（仅当看起来是绝对路径）
    if ($homeArticle[0] === '/' && @is_file($homeArticle) && is_md($homeArticle)) {
        $homeFile = $homeArticle;
    }
    // 2) vault/ 相对路径（去掉可能的前导 /，兼容误填）；含自定义挂载目录回退
    if ($homeFile === '') {
        $resolved = resolve_vault_file(ltrim($homeArticle, '/'), $config);
        if ($resolved !== null && is_md($resolved)) $homeFile = $resolved;
    }
    if ($homeFile !== '') {
        $homeMd = (string)@file_get_contents($homeFile);
        // 标题 = 文件名（与文章页 doc-title、目录树一致，保留数字前缀），正文保持完整
        $homeTitle = preg_replace('/\.md$/i', '', basename($homeFile));
    } else {
        $homeTitle = '';
    }
} else {
    $homeTitle = '';
}
?><!DOCTYPE html>
<!-- 服务端预告主题（cookie 由 JS 切换主题时同步写入）：Firefox 刷新时首帧即夜间，避免先白后黑闪屏；cookie 代表用户实际选择（手动夜间优先于 default_light） -->
<html lang="zh-CN" class="<?php echo (($_COOKIE['vp-theme'] ?? '') === 'dark') ? 'dark' : ''; ?>">
 <head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
 <title><?php echo htmlspecialchars($pageTitle); ?></title>
<script>
/* 提前应用主题：HTML 渲染前读取 localStorage 加 dark 类，避免夜间模式刷新闪屏；默认日间模式开启时强制日间 */
var DEFAULT_LIGHT = <?php echo $defaultLight ? 'true' : 'false'; ?>;
(function () {
    try {
        if (!DEFAULT_LIGHT && localStorage.getItem('vp-theme') === 'dark') {
            document.documentElement.classList.add('dark');
            document.cookie = 'vp-theme=dark; path=/';
        }
    } catch (e) {}
})();
</script>

<link rel="stylesheet" href="/assets/vs.min.css">
<link rel="stylesheet" href="/assets/vs2015.min.css">
<script src="/assets/highlight.min.js"></script>
<script src="/assets/lz-string.min.js"></script>
    <link rel="stylesheet" href="/assets/site.css?v=20260829b">
<style>/* 阅读列宽（后台可调）：覆盖 site.css 的默认值 */
:root { --vp-content-w:<?php echo $contentW; ?>px; }
</style>
<?php if (($config['font_preset'] ?? 'nunito') === 'serif'): ?>
<style>/* 字体预设：DejaVu Serif（自托管，开源 Bitstream Vera）——只管西文，中文走系统宋体 */
@font-face { font-family:'DejaVu Serif'; src:url('/assets/fonts/dejavu-serif.woff2') format('woff2'); font-weight:400; font-display:swap; }
@font-face { font-family:'DejaVu Serif'; src:url('/assets/fonts/dejavu-serif-bold.woff2') format('woff2'); font-weight:700; font-display:swap; }
html, body { font-family:"DejaVu Serif","Songti SC","STSong","SimSun","Noto Serif CJK SC",serif !important; }
.md { font-family:"DejaVu Serif","Songti SC","STSong","SimSun","Noto Serif CJK SC",serif !important; }
.doc-title { font-family:"DejaVu Serif","Songti SC","STSong","SimSun","Noto Serif CJK SC",serif !important; }
#vp-logo { font-family:"DejaVu Serif","Songti SC","STSong","SimSun","Noto Serif CJK SC",serif !important; }
/* 移动端目录抽屉（#front-drawer-md）也要跟着切，否则手机汉堡菜单仍是默认无衬线 */
.drawer-md, #front-drawer-md { font-family:"DejaVu Serif","Songti SC","STSong","SimSun","Noto Serif CJK SC",serif !important; }
</style>
<?php endif; ?>
</head>
<body>

<!-- 主界面 -->
<div id="app">
    <!-- 桌面端左侧常驻目录栏（三栏：左树 | 中正文 | 右 TOC）；移动端隐藏，仍走全屏抽屉 -->
    <aside id="left-sidebar">
        <!-- 与 AI 面板同款顶部标题条：sticky 紧贴导航栏下沿 -->
        <div class="ai-header">Contents</div>
        <div class="drawer-md" id="left-drawer-md"></div>
    </aside>
    <div id="main">
        <!-- 第一层：VitePress 风格导航栏（外层 wrap 用 transform 平滑推出） -->
        <div id="nav-wrap">
        <div id="vp-nav">
            <div id="vp-nav-left">
                <!-- 菜单按钮：位置与后台完全一致（最左） -->
                <button class="vp-icon-btn" id="vp-menu-btn" aria-label="menu">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                </button>
                <!-- AI 对话按钮：菜单按钮旁边 -->
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
                <button class="vp-icon-btn" id="vp-search-btn" aria-label="search">
                    <svg class="search-ico search-ico-magnifier" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <svg class="search-ico search-ico-close" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="5" x2="5" y2="19"></line><line x1="5" y1="5" x2="19" y2="19"></line></svg>
                </button>
            </div>
        </div>
        </div><!-- /nav-wrap -->
        <!-- Full-screen side drawer：文章目录树（PHP 扫描生成） -->
        <div id="vp-drawer">
            <div class="drawer-md" id="front-drawer-md"></div>
        </div>
        <div id="content">
            <!-- 主页：渲染站点设置中配置的首页文章正文（无配置时显示空状态提示）；首页 TOC 在右轨道 -->
            <div class="archive-flex" id="archive-flex">
                <div class="archive-view" id="archive-view">
                    <h1 class="doc-title" id="home-title" style="display:none"></h1>
                    <div class="md" id="home-md" style="display:none"></div>
                </div>
            </div>
            <div class="doc-wrap" id="doc-wrap">
                <div class="doc-main">
                    <h1 class="doc-title" id="doc-title"></h1>
                    <div class="md" id="md-view" style="display:none"></div>
                    <!-- PDF 阅读器（pdf.js 渲染，翻页/缩放/夜间反转） -->
                    <div id="pdf-view" style="display:none"></div>
                    <!-- Excalidraw 绘画渲染（.excalidraw.md：lz-string 解码 compressed-json → SVG） -->
                    <div id="excalidraw-view" style="display:none"><div class="excalidraw-canvas" id="excalidraw-canvas"></div></div>
                    <!-- Obsidian Canvas 白板渲染（.canvas：JSON → SVG 节点/连线画布） -->
                    <div id="canvas-view" style="display:none"><div class="canvas-board" id="canvas-board"></div></div>
                    <!-- 反向链接（被谁引用） -->
                    <div id="backlinks"></div>
                </div>
            </div>
            <!-- Graph View：独立图谱页（/graph，铺满内容区，只显示所有文章的关系图） -->
            <div class="graph-view" id="graph-view" style="display:none">
                <div class="graph-canvas-wrap" id="graph-canvas-wrap">
                    <svg id="graph-svg" xmlns="http://www.w3.org/2000/svg"></svg>
                    <div class="graph-empty" id="graph-empty">No articles with links in this scope.</div>
                    <span class="graph-info" id="graph-info"></span>
                </div>
            </div>
            <div class="empty-state" id="empty-state">Select a note to start reading</div>
        </div>
        <!-- AI 对话面板（顶栏按钮切换：桌面端覆盖左侧目录栏区域，移动端从导航下方弹出；/api/ask） -->
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
        <!-- 搜索面板（从顶部栏下方滑出，顶部栏图标 morph 为叉子） -->
        <div class="search-view" id="search-view">
            <div class="search-bar">
                <input type="text" id="search-input" placeholder="Search notes..." autocomplete="off" spellcheck="false">
            </div>
            <div class="search-results" id="search-results"></div>
        </div>
    </div><!-- /#main -->
    <!-- 桌面端右侧常驻 TOC 轨道（与左轨道等宽镜像；无目录时轨道即对称留白） -->
    <aside id="right-sidebar">
        <div class="toc-panel" id="home-toc-panel">
            <div class="toc-title">Contents</div>
            <div id="home-toc-list"></div>
        </div>
        <div class="toc-panel" id="toc-panel">
            <div class="toc-title">Contents</div>
            <div id="toc-list"></div>
        </div>
    </aside>
</div>

<div id="toast"></div>

<script src="/assets/marked.min.js"></script>
<script src="/assets/purify.min.js"></script>
<script src="/assets/lunr.min.js"></script>
<link rel="stylesheet" href="/assets/katex.min.css">
<script src="/assets/katex.min.js"></script>
<script>
// 前台侧滑菜单：PHP 扫描生成的文章目录树（md 嵌套列表，内联零请求）
var FRONT_MENU_MD = <?php echo json_encode($frontMenuMd); ?>;
// 前台目录树默认展开状态（后台偏好设置控制）
var FRONT_DRAWER_EXPANDED = <?php echo $frontDrawerExpanded ? 'true' : 'false'; ?>;
// 强制展开目录（后台目录管理设置，优先级高于默认展开开关）
var FRONT_EXPANDED_DIRS = <?php echo json_encode(expand_effective_entries($config)); ?>;
var GRAPH_ALIAS_PATH = <?php echo json_encode(trim((string)($config['graph_path'] ?? ''), "/ \t")); ?>;

// 首页文章（后台站点设置配置，内联零请求；空 = 未配置）
var HOME_MD = <?php echo json_encode($homeMd); ?>;
// 服务端渲染的文章（打开即内容）：路径 + 原始 md，前端同步渲染显示（无 fetch 等待）
var SSR_MD = <?php echo json_encode($ssrArticleContent !== '' ? $ssrArticleContent : null); ?>;
var SSR_PATH = <?php echo json_encode($ssrArticlePath !== '' ? $ssrArticlePath : null); ?>;
// 服务端渲染的 PDF（阅读器打开即用）：路径（前端 pdf.js 加载 /vault/路径）
var SSR_PDF = <?php echo json_encode($ssrPdfPath !== '' ? $ssrPdfPath : null); ?>;
// AI 问答总开关（后台 AI 视图配置）：关闭时隐藏 AI 按钮
var AI_ENABLED = <?php echo !empty($config['ai_enabled'] ?? true) ? 'true' : 'false'; ?>;
// Graph View 直达（/graph）：前端渲染知识图谱（?dir= 由 fetch 参数决定）
var SSR_GRAPH = <?php echo $ssrGraph ? 'true' : 'false'; ?>;
// Graph 设置：文件名标签默认显示（后台 Graph 视图开关）
var GRAPH_SHOW_LABELS = <?php echo !empty($config['graph_show_labels']) ? 'true' : 'false'; ?>;
// 固定顶部栏（后台偏好设置控制）：开启时导航栏不随滚动隐藏
var PIN_NAVBAR = <?php echo !empty($config['pin_navbar']) ? 'true' : 'false'; ?>;
// Excalidraw 绘画直达（.excalidraw.md）：内联原文——前端 lz-string 解码 compressed-json → SVG 渲染
var SSR_EXCALIDRAW = <?php echo $ssrExcalidrawPath !== '' ? 'true' : 'false'; ?>;
var EXCALIDRAW_PATH = <?php echo $ssrExcalidrawPath !== '' ? json_encode($ssrExcalidrawPath) : '""'; ?>;
var EXCALIDRAW_RAW = <?php echo $ssrExcalidrawPath !== '' ? json_encode($ssrExcalidrawContent) : '""'; ?>;
// Obsidian Canvas 白板直达（.canvas）：内联 JSON——前端渲染节点/连线画布
var SSR_CANVAS = <?php echo $ssrCanvasPath !== '' ? 'true' : 'false'; ?>;
var CANVAS_PATH = <?php echo $ssrCanvasPath !== '' ? json_encode($ssrCanvasPath) : '""'; ?>;
var CANVAS_JSON = <?php echo $ssrCanvasPath !== '' ? json_encode($ssrCanvasContent) : '""'; ?>;
// 首页大标题（从文章第一个标题提取）
var HOME_TITLE = <?php echo json_encode($homeTitle); ?>;
// 站点标题（PDF/Canvas/Excalidraw 视图动态 document.title 用）
var SITE_TITLE = <?php echo json_encode($siteTitle); ?>;
</script>
<script src="/assets/site.js?v=20260829b"></script>
</body>
</html>
