<?php
/**
 * BrainPress v1.0.0 — 公共函数层
 * 被 index.php（主站）和 admin.php（后台）共同 require。
 * 包含：session/config 初始化、MinIO S3 直连、认证、文件扫描、工具函数。
 */

declare(strict_types=1);

session_name('brainpress');
session_start();

// ima mount driver (Tencent ima knowledge base OpenAPI)
require __DIR__ . '/ima.php';

const PANEL_DIR  = __DIR__;
// 配置文件路径：环境变量 BP_CONFIG_FILE 可覆盖（Docker 部署把配置放进持久化目录，代码目录保持只读）
define('CONFIG_FILE', getenv('BP_CONFIG_FILE') ?: PANEL_DIR . '/config.json');
// 扫描缓存目录（默认代码目录下 cache/；Docker 等只读代码目录可用 BP_CACHE_DIR 指到可写持久化目录）
define('BP_CACHE_DIR', getenv('BP_CACHE_DIR') ?: PANEL_DIR . '/cache');
const MAX_FILE_SIZE = 1048576; // 1MB

$config = json_decode((string)@file_get_contents(CONFIG_FILE), true) ?: [];

// MinIO 连接配置（S3 API 直连，不走 mc/exec——FPM 禁用了 exec；可从 /admin 配置）
$minioEndpoint = $config['minio_endpoint'] ?? 'http://127.0.0.1:19000';
$minioAccess   = $config['minio_access'] ?? 'minio';
$minioSecret   = $config['minio_secret'] ?? '';
$minioBucket   = $config['minio_bucket'] ?? 'vault';

// 路由解析（两个入口共用）
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- MinIO S3 直连 ---------- */

/** S3 请求（AWS SigV4 签名），返回 [状态码, 响应体] */
function minio_request(string $method, string $path, string $query = ''): array {
    global $minioEndpoint, $minioAccess, $minioSecret;
    $host = parse_url($minioEndpoint, PHP_URL_HOST) ?: '127.0.0.1';
    $port = parse_url($minioEndpoint, PHP_URL_PORT);
    if ($port !== null && $port !== 80 && $port !== 443) {
        $host .= ':' . $port;
    }
    $now = gmdate('Ymd\THis\Z');
    $date = gmdate('Ymd');
    $service = 's3';
    $region = 'us-east-1';

    // 规范化请求：canonical query 按字典序排序（调用方已传编码后的参数）
    $canonicalQuery = '';
    if ($query !== '') {
        $pairs = explode('&', $query);
        sort($pairs);
        $canonicalQuery = implode('&', $pairs);
    }
    $payloadHash = hash('sha256', '');
    $canonicalHeaders = "host:" . $host . "\n";
    $signedHeaders = 'host';

    $canonicalRequest = $method . "\n" . $path . "\n" . $canonicalQuery . "\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

    $scope = $date . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $date, 'AWS4' . $minioSecret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $auth = 'AWS4-HMAC-SHA256 Credential=' . $minioAccess . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

    $url = $minioEndpoint . $path . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $auth,
            'x-amz-date: ' . $now,
            'x-amz-content-sha256: ' . $payloadHash,
            'Host: ' . $host,
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}

/** MinIO 存储：列桶（XML 解析），prefix 如 '' 或 'knowledge' */
function minio_ls(string $prefix = ''): array {
    global $minioBucket;
    $query = 'list-type=2&delimiter=%2F&prefix=' . urlencode($prefix !== '' ? $prefix . '/' : '');
    [$code, $body] = minio_request('GET', '/' . $minioBucket, $query);
    if ($code !== 200) return [];
    $items = [];
    if (preg_match_all('#<Contents>.*?<Key>(.*?)</Key>.*?</Contents>#s', $body, $m)) {
        foreach ($m[1] as $keyRaw) {
            $key = html_entity_decode($keyRaw);
            $name = basename(rtrim($key, '/'));
            if ($name === '') continue;
            $rel = $prefix !== '' ? $prefix . '/' . $name : $name;
            $items[] = ['name' => $name, 'path' => $rel, 'type' => 'file'];
        }
    }
    // 虚拟目录（CommonPrefixes）
    if (preg_match_all('#<CommonPrefixes>.*?<Prefix>(.*?)</Prefix>.*?</CommonPrefixes>#s', $body, $m2)) {
        foreach ($m2[1] as $preRaw) {
            $pre = html_entity_decode($preRaw);
            $pre = rtrim($pre, '/');
            $name = basename($pre);
            if ($name === '') continue;
            $rel = $prefix !== '' ? $prefix . '/' . $name : $name;
            $items[] = ['name' => $name, 'path' => $rel, 'type' => 'dir'];
        }
    }
    // 去重（同一名字可能 dir+file 并存）
    $seen = [];
    $result = [];
    foreach ($items as $it) {
        $k = $it['path'] . '|' . $it['type'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $result[] = $it;
    }
    // 目录在前
    usort($result, function ($a, $b) {
        if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
        return strcmp($a['name'], $b['name']);
    });
    return $result;
}

/** MinIO 存储：读文件内容 */
function minio_cat(string $rel): ?string {
    global $minioBucket;
    $path = '/' . $minioBucket . '/' . str_replace(' ', '%20', $rel);
    [$code, $body] = minio_request('GET', $path);
    if ($code !== 200) return null;
    return $body;
}

/* ---------- 响应与认证 ---------- */

function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): never {
    json_out(['ok' => false, 'error' => $msg], $code);
}

function ok(array $data = []): never {
    json_out(['ok' => true] + $data);
}

function is_authed(): bool {
    global $config;
    // 未设置密码（首次安装）：视为可进入（引导设置密码）
    if (empty($config['password_hash'])) {
        return true;
    }
    return !empty($_SESSION['authed']);
}

function require_auth(): void {
    if (!is_authed()) {
        fail('未登录', 401);
    }
}

/* ---------- 文件工具 ---------- */

function is_md(string $path): bool {
    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'md';
}

/** 是否为 PDF 文件（阅读器显示用） */
function is_pdf(string $path): bool {
    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
}

/** 是否为 Obsidian Canvas 白板文件（画布渲染显示用） */
function is_canvas(string $path): bool {
    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'canvas';
}

/** 是否为 HTML 文件（在线工具/自定义页面用） */
function is_html(string $path): bool {
    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'html';
}

/** 是否为图片文件（嵌入显示用） */
function is_image(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'avif'], true);
}

/** 音视频资源（Obsidian 嵌入用）：与前端 processObsidian 的媒体扩展名列表保持一致 */
function is_media(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'webm', 'ogv', 'mov', 'm4v', 'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'opus'], true);
}

/** 扫描缓存：每个目录的 scandir 结果按 dir mtime 缓存，避免同一请求/相邻请求反复列目录。
 *  - 进程内静态缓存：同一请求内多次 scan 同一目录不重复 scandir。
 *  - 磁盘缓存（BP_CACHE_DIR/scan.php）：跨请求复用；目录 mtime 不变即认为内容没变（增删改都会更新
 *    父目录 mtime，天然失效）。缓存损坏/写失败自动降级为实时 scandir，不影响正确性。
 */
function scandir_tree_cache_path(): string {
    static $p = null;
    if ($p === null) {
        $p = BP_CACHE_DIR . '/scan.php';
        if (!is_dir(BP_CACHE_DIR)) { @mkdir(BP_CACHE_DIR, 0775, true); }
    }
    return $p;
}

/** 读取磁盘缓存结构 [signature => [dir => [mtime, entries]]] */
function scandir_tree_cache_load(): array {
    $f = scandir_tree_cache_path();
    if (!is_file($f)) return [];
    $data = @(include $f);
    return is_array($data) ? $data : [];
}

/** 写入磁盘缓存：原子写（临时文件 + rename），失败静默；条目数超上限时截断最旧的一半（目录持久累积会无限增长） */
function scandir_tree_cache_save(array $cache): void {
    if (count($cache) > 20000) {
        $cache = array_slice($cache, 0, 10000, true);
    }
    $f = scandir_tree_cache_path();
    $tmp = $f . '.tmp-' . getmypid();
    $out = "<?php return " . var_export($cache, true) . ";\n";
    if (@file_put_contents($tmp, $out) === false) return;
    @rename($tmp, $f);
}

/** 带缓存的 scandir：返回目录条目数组（含 . .. 及隐藏项，交由调用方过滤），保证与 scandir 一致 */
function scandir_tree_cached(string $dir): array {
    static $mem = [];          // 进程内：dir => [mtime, entries]
    static $disk = null;       // 磁盘缓存整体（懒加载一次）
    static $dirty = false;

    if ($disk === null) {
        $disk = scandir_tree_cache_load();
    }
    $mtime = @filemtime($dir);
    // 1) 进程内缓存命中
    if (isset($mem[$dir]) && $mem[$dir][0] === $mtime) {
        return $mem[$dir][1];
    }
    // 2) 磁盘缓存命中（mtime 一致）
    if (isset($disk[$dir]) && is_array($disk[$dir]) && $disk[$dir][0] === $mtime) {
        $entries = $disk[$dir][1];
    } else {
        // 3) 重新列目录
        $entries = @scandir($dir);
        if ($entries === false) $entries = [];
        $mtime = @filemtime($dir);
        $disk[$dir] = [$mtime, $entries];
        $dirty = true;
    }
    // 写回进程内缓存
    $mem[$dir] = [$mtime, $entries];
    // 请求结束时把脏块落盘（一次性）
    if ($dirty) {
        static $registered = false;
        if (!$registered) {
            $registered = true;
            register_shutdown_function(function () use (&$disk) {
                if ($disk !== null) scandir_tree_cache_save($disk);
            });
        }
    }
    return $entries;
}

/** 递归扫描工作区，返回文件树；$excludes 命中的目录/文件不出现在树中；$pinnedDirs 置顶目录、$pinnedArticles 置顶文章（排最前）；
 *  $forMount=true 用于自定义挂载：不收录图片（挂载目录没有静态服务路由，图片是死链，只会造成脏乱显示） */
function scan_tree(string $dir, string $relPrefix = '', array $excludes = [], array $pinnedDirs = [], array $pinnedArticles = [], bool $forMount = false, bool $publishedOnly = false): array {
    if (!is_dir($dir)) {
        return []; // vault 目录缺失时优雅兜底（空树），不报错
    }
    $items = [];
    $entries = scandir_tree_cached($dir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if ($entry[0] === '.') {
            continue; // 隐藏文件
        }
        if ($entry === 'assets') {
            continue; // 面板自身资源目录，不列入文档树
        }
        $full = $dir . '/' . $entry;
        $rel = $relPrefix === '' ? $entry : $relPrefix . '/' . $entry;
        if (is_excluded($rel, $excludes)) {
            continue; // 命中隐藏列表：目录整棵跳过 / 文件不收录
        }
        if (is_dir($full)) {
            $children = scan_tree($full, $rel, $excludes, $pinnedDirs, $pinnedArticles, $forMount, $publishedOnly);
            // 挂载模式：过滤后变空的目录（如纯图片的 Attachments）直接不显示
            if ($forMount && !$children) continue;
            $items[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'dir',
                'children' => $children,
            ];
        } elseif (is_md($full)) {
            if ($publishedOnly && is_unpublished((string)@file_get_contents($full))) continue; // 选择性发布过滤
            $items[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'file',
            ];
        } elseif (is_image($full)) {
            if ($forMount) continue;  // 挂载目录不收录图片（无静态路由的死链）
            // 图片文件也收录（嵌入显示用）
            $items[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'file',
            ];
        } elseif (is_pdf($full)) {
            if ($forMount) continue;  // 同上：挂载 PDF 无静态路由，阅读器加载不到
            // PDF 文件收录（阅读器显示用）
            $items[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'file',
            ];
        } elseif (is_canvas($full)) {
            // Obsidian Canvas 白板收录（画布渲染显示用）
            $items[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'file',
            ];
        } elseif (is_html($full)) {
            // HTML 在线工具/自定义页面收录（直接渲染原始 HTML，保留脚本/样式）
            $items[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'file',
            ];
        } elseif (is_media($full)) {
            if ($forMount) continue;  // 挂载媒体无静态路由（同图片/PDF：死链不收录）
            // 音视频资源收录（![[xx.mp4]] 嵌入按名字解析用；前台菜单 tree_to_md 会跳过展示）
            $items[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'file',
            ];
        }
    }
    usort($items, function ($a, $b) use ($pinnedDirs, $pinnedArticles) {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'dir' ? -1 : 1; // 目录在前
        }
        if ($a['type'] === 'dir') {
            // 置顶目录排最前
            $ap = in_array($a['path'], $pinnedDirs, true) ? 0 : 1;
            $bp = in_array($b['path'], $pinnedDirs, true) ? 0 : 1;
            if ($ap !== $bp) return $ap - $bp;
        } else {
            // 置顶文章排最前（该目录内的）
            $ap = in_array($a['path'], $pinnedArticles, true) ? 0 : 1;
            $bp = in_array($b['path'], $pinnedArticles, true) ? 0 : 1;
            if ($ap !== $bp) return $ap - $bp;
        }
        return strcmp($a['name'], $b['name']);
    });
    return $items;
}

/** 文件树转 markdown 嵌套列表（前台侧滑菜单用）：目录 → 父项，md 文件 → 链接 */
function tree_to_md(array $items, string $prefix = ''): string {
    $lines = [];
    foreach ($items as $item) {
        if ($item['type'] === 'dir') {
            // 无可见子项的目录（空目录/子项全是媒体等资源）整行跳过：
            // 菜单里裸目录行没有内嵌 ul，前端折叠逻辑判不出目录会导致样式错乱
            $childMd = tree_to_md($item['children'] ?? [], $prefix . '  ');
            if ($childMd === '') continue;
            $lines[] = $prefix . '- ' . $item['name'];
            $lines[] = $childMd;
        } else {
            // 媒体/图片是嵌入资源非文档：不进侧滑菜单（树保持干净），仅存在于树数据供 resolveAsset 解析
            if (is_media($item['name']) || is_image($item['name'])) continue;
            $name = preg_replace('/\.(md|pdf|canvas|html)$/i', '', $item['name']);
            // 最小编码：保留斜杠，只编码空格/括号等 md 链接破坏字符
            $href = str_replace('%2F', '/', rawurlencode($item['path']));
            $lines[] = $prefix . '- [' . $name . '](/' . $href . ')';
        }
    }
    return implode("\n", $lines);
}

/** 递归收集所有 md 文件（含路径/名称/修改时间），供最新文章列表用 */
function collect_md_files(string $dir, string $relPrefix = '', array &$out = []): array {
    $entries = scandir($dir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') continue;
        $full = $dir . '/' . $entry;
        $rel = $relPrefix === '' ? $entry : $relPrefix . '/' . $entry;
        if (is_dir($full)) {
            collect_md_files($full, $rel, $out);
        } elseif (is_md($full)) {
            $out[] = [
                'path' => $rel,
                'name' => preg_replace('/\.md$/i', '', $entry),
                'mtime' => (int)filemtime($full),
            ];
        }
    }
    return $out;
}

/* ---------- 自定义挂载（Custom Path）：与主 vault 平权的内容源 ---------- */

/** 设置条目是否为绝对路径形式（/ 开头或 Windows 盘符）——Tree 页四个列表的"双写法"判据：
    相对路径/名字 = 只作用于主 vault；绝对路径 = 作用于自定义挂载目录里的对应条目 */
function setting_is_absolute(string $e): bool {
    $e = trim($e);
    if ($e === '') return false;
    return $e[0] === '/' || (bool)preg_match('/^[A-Za-z]:[\/\\\\]/', $e);
}

/** 绝对条目是否命中绝对路径（精确或目录前缀=整棵子树）；两侧统一正斜杠比较 */
function abs_entry_hits(string $abs, array $entries): bool {
    $a = rtrim(str_replace('\\', '/', $abs), '/');
    foreach ($entries as $e) {
        if (!is_string($e) || !setting_is_absolute($e)) continue;
        $n = rtrim(str_replace('\\', '/', trim($e)), '/');
        if ($n === '') continue;
        if ($a === $n || strpos($a, $n . '/') === 0) return true;
    }
    return false;
}

/** 绝对路径落在根内则返回其相对形式；恰好等于根返回 ''；不属于该根返回 null */
function abs_under_root(string $abs, string $root): ?string {
    $a = rtrim(str_replace('\\', '/', $abs), '/');
    $r = rtrim(str_replace('\\', '/', $root), '/');
    if ($a === $r) return '';
    if (strpos($a, $r . '/') === 0) return substr($a, strlen($r) + 1);
    return null;
}

/** 启用的自定义挂载点：realpath 规范化；isFile = 挂载目标是单个 .md 文件而非目录；无效路径静默跳过 */
function custom_mount_roots(array $config): array {
    $out = [];
    foreach (($config['custom_paths'] ?? []) as $cp) {
        if (empty($cp['on'])) continue;
        $p = trim((string)($cp['path'] ?? ''));
        if ($p === '') continue;
        $rp = realpath($p);
        if ($rp === false) continue;
        $out[] = ['root' => $rp, 'isFile' => is_file($rp)];
    }
    return $out;
}

/** 规范化 WebDAV 同步账号列表（webdav_mounts[]，每项 {user,pass,path}）；兼容旧版单账号字段
    webdav_user / webdav_pass（迁移到多账号）。空行剔除；path 去首尾斜杠，空 = vault 根 */
function webdav_mounts_list(array $config): array {
    $out = [];
    foreach ((array)($config['webdav_mounts'] ?? []) as $m) {
        if (!is_array($m)) continue;
        $u = trim((string)($m['user'] ?? ''));
        $p = (string)($m['pass'] ?? '');
        $pt = trim(trim((string)($m['path'] ?? ''), '/'), " \t");
        if ($u === '' && $p === '' && $pt === '') continue;
        $out[] = ['user' => $u, 'pass' => $p, 'path' => $pt];
    }
    if ($out === [] && trim((string)($config['webdav_user'] ?? '')) !== '') {
        $out[] = ['user' => trim((string)$config['webdav_user']),
                  'pass' => (string)($config['webdav_pass'] ?? ''),
                  'path' => ''];
    }
    return $out;
}

/** 解析 vault 相对路径 → 绝对文件路径。顺序：主 vault → 自定义挂载目录 → 单文件挂载（rel == 文件名）；找不到 null */
function resolve_vault_file(string $rel, array $config): ?string {
    $rel = str_replace('\\', '/', ltrim(trim($rel), '/'));
    if ($rel === '') return null;
    $mainRoot = realpath(PANEL_DIR . '/vault');
    if ($mainRoot !== false) {
        $full = realpath($mainRoot . '/' . $rel);
        if ($full !== false && strpos($full, $mainRoot . '/') === 0 && is_file($full)) return $full;
    }
    foreach (custom_mount_roots($config) as $m) {
        if ($m['isFile']) {
            if ($rel === basename($m['root'])) return $m['root'];
            continue;
        }
        // 目录挂载与主 vault 同级平权：rel 直接落在挂载根内解析（无 URL 前缀包装）
        $full = realpath($m['root'] . '/' . $rel);
        if ($full !== false && strpos($full, $m['root'] . '/') === 0 && is_file($full)) return $full;
    }
    return null;
}

/** 按绝对隐藏条目递归过滤挂载树（命中目录=整棵子树移除） */
function filter_abs_hidden(array $items, string $mountRoot, array $absExcludes): array {
    if (!$absExcludes) return $items;
    $rootN = rtrim(str_replace('\\', '/', $mountRoot), '/');
    $out = [];
    foreach ($items as $it) {
        if (abs_entry_hits($rootN . '/' . str_replace('\\', '/', (string)$it['path']), $absExcludes)) continue;
        if (($it['type'] ?? '') === 'dir' && isset($it['children'])) {
            $it['children'] = filter_abs_hidden($it['children'], $mountRoot, $absExcludes);
        }
        $out[] = $it;
    }
    return $out;
}

/** 递归合并挂载子树进目标树：每层按名字去重（主树已有一律优先）；目录同名则继续向下合并，
    文件同名或类型冲突则丢弃挂载侧。此前按顶层名整棵丢弃挂载目录——主 vault 存在同名目录时
    （如两边都有 BrainPress/）整个挂载被静默吞掉，Mounts 开关切换看起来毫无效果 */
function merge_tree_node(array &$target, array $incoming): void {
    $idx = [];
    foreach ($target as $i => $t) $idx[$t['name']] = $i;
    foreach ($incoming as $n) {
        $name = $n['name'];
        if (!isset($idx[$name])) {
            $idx[$name] = count($target);
            $target[] = $n;
            continue;
        }
        $ti = $idx[$name];
        if (($target[$ti]['type'] ?? '') === 'dir' && ($n['type'] ?? '') === 'dir') {
            merge_tree_node($target[$ti]['children'], $n['children'] ?? []);
        }
    }
}

/** 把启用的自定义挂载扫描成树并合并进 $tree（主树同名优先）：目录递归扫描、单 .md 文件作顶层条目。
    同名目录逐层合并而非整棵丢弃；Tree 页双写法：隐藏/置顶的相对条目不作用于挂载；
    绝对条目换算成挂载内相对坐标生效（置顶沿用 scan_tree 的"本层排最前"语义） */
function merge_custom_trees(array $tree, array $config): array {
    // 占用名字初始化：主树顶层 + 主树各目录的第一层文件（与 /api/list 本地优先口径一致）
    $seen = [];
    foreach ($tree as $t) {
        $seen[$t['name']] = true;
        if (($t['type'] ?? '') === 'dir') {
            foreach (($t['children'] ?? []) as $f) {
                if (($f['type'] ?? '') === 'file') $seen[basename($f['name'])] = true;
            }
        }
    }
    // 隐藏/置顶的绝对条目（作用于挂载）；相对条目只属于主 vault，不传入挂载扫描
    $absEx = [];
    foreach (($config['exclude_paths'] ?? []) as $e) if (is_string($e) && setting_is_absolute($e)) $absEx[] = $e;
    $absPinDirs = [];
    foreach (($config['pinned_dirs'] ?? []) as $e) if (is_string($e) && setting_is_absolute($e)) $absPinDirs[] = $e;
    $absPinArticles = [];
    foreach (($config['pinned_articles'] ?? []) as $e) if (is_string($e) && setting_is_absolute($e)) $absPinArticles[] = $e;

    foreach (custom_mount_roots($config) as $m) {
        if ($m['isFile']) {
            if (abs_entry_hits($m['root'], $absEx)) continue;   // 整个单文件挂载被隐藏
            $name = basename($m['root']);
            if (!isset($seen[$name])) {
                $tree[] = ['name' => $name, 'path' => $name, 'type' => 'file'];
                $seen[$name] = true;
            }
            continue;
        }
        if (!is_dir($m['root'])) continue;
        // 绝对隐藏条目命中挂载根本体 → 整棵跳过（后台可一键隐藏整个挂载目录）
        if (abs_entry_hits($m['root'], $absEx)) continue;
        // 置顶绝对条目 → 挂载内相对坐标，交给 scan_tree 原生排序
        $relPinDirs = []; $relPinArticles = [];
        foreach ($absPinDirs as $a) {
            $rel = abs_under_root($a, $m['root']);
            if ($rel !== null && $rel !== '' && is_dir($m['root'] . '/' . $rel)) $relPinDirs[] = $rel;
        }
        foreach ($absPinArticles as $a) {
            $rel = abs_under_root($a, $m['root']);
            if ($rel !== null && $rel !== '' && is_file($m['root'] . '/' . $rel)) $relPinArticles[] = $rel;
        }
        // 挂载内容与主 vault 同级平权：relPrefix='' 扫描后逐节点合并进顶层（同名目录递归并入、同名文件主 vault 优先），
        // 不包一层挂载名目录——custom 和 vault 本来就是同一层级的内容来源
        $customTree = scan_tree($m['root'], '', [], $relPinDirs, $relPinArticles, true);
        $customTree = filter_abs_hidden($customTree, $m['root'], $absEx);
        merge_tree_node($tree, $customTree);
        $seen = [];
        foreach ($tree as $t) {
            $seen[$t['name']] = true;
            if (($t['type'] ?? '') === 'dir') {
                foreach (($t['children'] ?? []) as $f) {
                    if (($f['type'] ?? '') === 'file') $seen[$f['name']] = true;
                }
            }
        }
    }
    return $tree;
}

/** Merge the ima knowledge base into the tree as an independent content source (same-name main vault / local mounts win);
 *  returns the original tree when ima is disabled or the cache is empty. Shared by the frontend menu and /api/list. */
function merge_ima_tree(array $tree, array $config): array {
    if (!function_exists('ima_enabled') || !ima_enabled($config)) return $tree;
    $imaTree = ima_tree($config, $config['exclude_paths'] ?? []);
    if ($imaTree !== []) {
        merge_tree_node($tree, $imaTree);
    }
    return $tree;
}

/** 多根收集全部 md 文件：主 vault + 各目录型挂载（主 vault 同名相对路径优先）+ 单文件挂载条目。
    隐藏双写法在此统一生效：相对条目只滤主 vault 结果；绝对条目滤挂载结果 */
function collect_all_md_files(array $config): array {
    $out = [];
    $seenPaths = [];
    $absEx = [];
    foreach (($config['exclude_paths'] ?? []) as $e) if (is_string($e) && setting_is_absolute($e)) $absEx[] = $e;
    $roots = [];
    // 主 vault 受「WebDAV 渲染」开关控制（与 /api/list、/api/file 同一开关）：关=搜索/图谱也不收录
    $mainRoot = (($config['render_webdav'] ?? false) && realpath(PANEL_DIR . '/vault') !== false)
        ? realpath(PANEL_DIR . '/vault')
        : false;
    if ($mainRoot !== false && is_dir($mainRoot)) $roots[] = $mainRoot;
    foreach (custom_mount_roots($config) as $m) {
        if (!$m['isFile'] && is_dir($m['root'])) $roots[] = $m['root'];
    }
    foreach ($roots as $i => $root) {
        $files = [];
        collect_md_files($root, '', $files);
        foreach ($files as $f) {
            if (isset($seenPaths[$f['path']])) continue;
            // 挂载来源（$i>0）：按绝对条目判定隐藏；主 vault 来源沿用调用处的相对匹配
            if ($i > 0 && abs_entry_hits($root . '/' . str_replace('\\', '/', (string)$f['path']), $absEx)) continue;
            $seenPaths[$f['path']] = true;
            $out[] = $f;
        }
    }
    foreach (custom_mount_roots($config) as $m) {
        if ($m['isFile'] && is_md($m['root']) && !isset($seenPaths[basename($m['root'])])
            && !abs_entry_hits($m['root'], $absEx)) {
            $name = basename($m['root']);
            $seenPaths[$name] = true;
            $out[] = ['path' => $name, 'name' => preg_replace('/\.md$/i', '', $name), 'mtime' => (int)filemtime($m['root'])];
        }
    }
    return $out;
}

/** 展开目录的有效条目（供前端抽屉匹配）：相对条目原样；绝对条目换算成所属根内的相对坐标
    （抽屉里展示的节点相对路径唯一，换算后前端逻辑零改动） */
function expand_effective_entries(array $config): array {
    $out = [];
    $mainRoot = realpath(PANEL_DIR . '/vault');
    foreach (($config['expanded_dirs'] ?? []) as $d) {
        $d = trim((string)$d);
        if ($d === '') continue;
        if (!setting_is_absolute($d)) { $out[] = $d; continue; }
        $roots = [];
        if ($mainRoot !== false) $roots[] = $mainRoot;
        foreach (custom_mount_roots($config) as $m) if (!$m['isFile']) $roots[] = $m['root'];
        foreach ($roots as $r) {
            $rel = abs_under_root($d, $r);
            if ($rel !== null && $rel !== '') { $out[] = $rel; break; }
        }
    }
    return array_values(array_unique($out));
}

/** 路径是否命中隐藏列表（精确路径 / 目录前缀 / 文件名），命中返回 true（前台不可见） */
function is_excluded(string $rel, array $excludes): bool {
    foreach ($excludes as $ex) {
        $ex = trim((string)$ex);
        if ($ex === '') continue;
        if ($rel === $ex) return true;                    // 精确路径
        if (strpos($rel, $ex . '/') === 0) return true;   // 目录前缀
        if (basename($rel) === $ex) return true;          // 文件名
    }
    return false;
}

/** 解析 markdown 开头的 YAML frontmatter（--- 包裹的键值块），返回关联数组；无 frontmatter 返回 [] */
function parse_frontmatter(string $raw): array {
    if (substr($raw, 0, 3) !== '---') return [];
    $end = strpos($raw, "\n---", 3);
    if ($end === false) return [];
    $block = substr($raw, 3, $end - 3);
    $out = [];
    foreach (preg_split('/\r?\n/', $block) as $line) {
        if (trim($line) === '' || strpos($line, ':') === false) continue;
        $key = trim(substr($line, 0, strpos($line, ':')));
        $val = trim(substr($line, strpos($line, ':') + 1));
        $val = trim($val, "\"' \t");
        if ($key !== '') $out[strtolower($key)] = $val;
    }
    return $out;
}

/** 选择性发布：frontmatter 含 published:false / draft:true / published:no → 视为未发布（返回 true） */
function is_unpublished(string $raw): bool {
    $fm = parse_frontmatter($raw);
    if (isset($fm['published'])) {
        $p = strtolower($fm['published']);
        if ($p === 'false' || $p === 'no' || $p === '0') return true;
    }
    if (isset($fm['draft'])) {
        $d = strtolower($fm['draft']);
        if ($d === 'true' || $d === 'yes' || $d === '1') return true;
    }
    return false;
}

/** 从 markdown 提取 RSS 描述片段：优先 frontmatter description，否则正文前 200 字符去标记 */
function extract_frontmatter_summary(string $raw): string {
    $fm = parse_frontmatter($raw);
    if (isset($fm['description']) && $fm['description'] !== '') return $fm['description'];
    // strip frontmatter + code blocks，取纯文本前 200 字
    $body = preg_replace('/^---[\s\S]*?---\r?\n/', '', $raw, 1);
    $body = preg_replace('/```[\s\S]*?```/', ' ', $body);
    $body = preg_replace('/!\[\[[^\]]*\]\]|\[\[[^\]]*\]\]|#[^\s#]+/u', ' $1', $body);
    $body = preg_replace('/[#>*_`~|=\-]/u', ' ', $body);
    $body = preg_replace('/\s+/u', ' ', $body);
    return mb_substr(trim($body), 0, 200);
}

/* ---------- RSS Feeds（外部源订阅） ---------- */

/** RSS 缓存目录 */
function rss_cache_dir(): string {
    static $d = null;
    if ($d === null) {
        $d = BP_CACHE_DIR . '/rss';
        if (!is_dir($d)) @mkdir($d, 0775, true);
    }
    return $d;
}

/** 生成 feed 的缓存文件名（url 的 md5） */
function rss_cache_file(string $url): string {
    return rss_cache_dir() . '/' . md5($url) . '.json';
}

/** 抓取并解析单个 RSS/Atom feed，返回标准化条目数组
 *  结构：[ ['title','link','pubDate','description','guid'], ... ]
 *  失败返回 []，不抛异常
 */
function rss_fetch_feed(string $url, int $timeout = 10): array {
    $cacheFile = rss_cache_file($url);
    $ttl = 3600; // 默认 1 小时，后续可从 config 读取
    // 缓存命中且未过期
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        $cached = @json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    // 抓取
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'BrainPress RSS Reader/1.0',
        CURLOPT_SSL_VERIFYPEER => false, // 兼容自签名/内网源
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $xml = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($xml === false || $httpCode !== 200) return [];
    // 解析 XML（支持 RSS 2.0 和 Atom 1.0）
    $items = [];
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_clear_errors();
    if ($doc === false) return [];
    // RSS 2.0: /rss/channel/item
    if (isset($doc->channel->item)) {
        foreach ($doc->channel->item as $item) {
            $title = (string)($item->title ?? '');
            $link = (string)($item->link ?? '');
            $pubDate = isset($item->pubDate) ? strtotime((string)$item->pubDate) : time();
            $desc = (string)($item->description ?? '');
            $guid = (string)($item->guid ?? $link);
            if ($title !== '' && $link !== '') {
                $items[] = ['title' => $title, 'link' => $link, 'pubDate' => $pubDate, 'description' => $desc, 'guid' => $guid];
            }
        }
    }
    // Atom 1.0: /feed/entry
    elseif (isset($doc->entry)) {
        $ns = $doc->getNamespaces(true);
        foreach ($doc->entry as $entry) {
            $title = (string)($entry->title ?? '');
            $link = '';
            if (isset($entry->link)) {
                foreach ($entry->link as $l) {
                    $attrs = $l->attributes();
                    if ((string)($attrs['rel'] ?? '') === 'alternate' || (string)($attrs['rel'] ?? '') === '') {
                        $link = (string)($attrs['href'] ?? '');
                        break;
                    }
                }
            }
            $pubDate = isset($entry->updated) ? strtotime((string)$entry->updated) : (isset($entry->published) ? strtotime((string)$entry->published) : time());
            $desc = (string)($entry->summary ?? $entry->content ?? '');
            $guid = (string)($entry->id ?? $link);
            if ($title !== '' && $link !== '') {
                $items[] = ['title' => $title, 'link' => $link, 'pubDate' => $pubDate, 'description' => $desc, 'guid' => $guid];
            }
        }
    }
    // 按时间倒序
    usort($items, fn($a, $b) => $b['pubDate'] - $a['pubDate']);
    // 写缓存（原子写）
    $tmp = $cacheFile . '.tmp-' . getmypid();
    @file_put_contents($tmp, json_encode($items, JSON_UNESCAPED_UNICODE));
    @rename($tmp, $cacheFile);
    return $items;
}

/** 获取所有启用的 RSS feeds 的配置列表（供前端目录树用） */
function rss_enabled_feeds(array $config): array {
    $out = [];
    foreach (($config['rss_feeds'] ?? []) as $f) {
        if (empty($f['on'])) continue;
        $url = trim((string)($f['url'] ?? ''));
        if ($url === '') continue;
        $title = trim((string)($f['title'] ?? ''));
        if ($title === '') {
            // 从 URL 推断标题
            $title = parse_url($url, PHP_URL_HOST) ?: 'RSS Feed';
        }
        $out[] = ['url' => $url, 'title' => $title];
    }
    return $out;
}

/** 合并 RSS feeds 到文件树（作为顶层目录节点，children 为该源的各篇文章；与 IMA 相同的静态树结构） */
function merge_rss_tree(array $tree, array $config): array {
    if (empty($config['rss_feeds'])) return $tree;
    $feeds = rss_enabled_feeds($config);
    if (!$feeds) return $tree;
    // 记录已有顶层名字（主 vault + custom mounts 优先）
    $seen = [];
    foreach ($tree as $t) $seen[$t['name']] = true;
    foreach ($feeds as $f) {
        $name = $f['title'];
        // 避免重名：若已存在则加后缀
        $baseName = $name;
        $suffix = 1;
        while (isset($seen[$name])) {
            $name = $baseName . ' ' . $suffix++;
        }
        $seen[$name] = true;
        // 抓取该源文章（有 1h 缓存；失败则跳过该源目录）
        $timeout = (int)($config['rss_timeout'] ?? 10);
        $items = rss_fetch_feed($f['url'], $timeout);
        $children = [];
        foreach ($items as $it) {
            $dateStr = isset($it['pubDate']) && $it['pubDate'] ? date('Y-m-d', (int)$it['pubDate']) : '';
            $disp = $dateStr !== '' ? '[' . $dateStr . '] ' . $it['title'] : $it['title'];
            $children[] = [
                'name' => $disp,
                'path' => 'rss://' . $f['url'] . '/' . ($it['guid'] ?? $it['link']),
                'type' => 'file',
                'rss_item' => true,
            ];
        }
        if ($children === []) continue; // 抓取失败或空源：不显示
        $tree[] = [
            'name' => $name,
            'path' => 'rss://' . $f['url'],
            'type' => 'dir',
            'children' => $children,
            'rss_feed' => true,
            'rss_url' => $f['url'],
        ];
    }
    return $tree;
}
