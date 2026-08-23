<?php
/**
 * MD2HTML v1.0.0 — 公共函数层
 * 被 index.php（主站）和 admin.php（后台）共同 require。
 * 包含：session/config 初始化、MinIO S3 直连、认证、文件扫描、工具函数。
 */

declare(strict_types=1);

session_name('wmm_panel');
session_start();

const PANEL_DIR  = __DIR__;
const CONFIG_FILE = PANEL_DIR . '/config.json';
const DEFAULT_WORKSPACE = '/root/.hermes/workspace';
const MAX_FILE_SIZE = 1048576; // 1MB

$config = json_decode((string)file_get_contents(CONFIG_FILE), true) ?: [];
$workspace = $config['workspace'] ?? DEFAULT_WORKSPACE;
// 存储后端：minio（读 Obsidian 存储桶）/ local（读本地 posts/）
$storage = $config['storage'] ?? 'local';

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
    $host = parse_url($minioEndpoint, PHP_URL_HOST) . ':' . parse_url($minioEndpoint, PHP_URL_PORT);
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

/** 将面板内相对路径转换为工作区内的安全绝对路径，非法返回 null */
function safe_path(string $rel): ?string {
    global $workspace;
    $base = realpath($workspace);
    if ($base === false) {
        return null;
    }
    $rel = str_replace('\\', '/', $rel);
    $full = realpath($base . '/' . $rel);
    if ($full === false || $full === $base) {
        return null;
    }
    if (strpos($full, $base . '/') !== 0) {
        return null;
    }
    return $full;
}

function is_md(string $path): bool {
    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'md';
}

/** 是否为 PDF 文件（阅读器显示用） */
function is_pdf(string $path): bool {
    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
}

/** 是否为图片文件（嵌入显示用） */
function is_image(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'avif'], true);
}

/** 递归扫描工作区，返回文件树；$excludes 命中的目录/文件不出现在树中；$pinnedDirs 置顶目录、$pinnedArticles 置顶文章（排最前） */
function scan_tree(string $dir, string $relPrefix = '', array $excludes = [], array $pinnedDirs = [], array $pinnedArticles = []): array {
    $items = [];
    $entries = scandir($dir);
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
            $items[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'dir',
                'children' => scan_tree($full, $rel, $excludes, $pinnedDirs, $pinnedArticles),
            ];
        } elseif (is_md($full)) {
            $items[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'file',
            ];
        } elseif (is_image($full)) {
            // 图片文件也收录（嵌入显示用）
            $items[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'file',
            ];
        } elseif (is_pdf($full)) {
            // PDF 文件收录（阅读器显示用）
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
            $lines[] = $prefix . '- ' . $item['name'];
            $lines[] = tree_to_md($item['children'] ?? [], $prefix . '  ');
        } else {
            $name = preg_replace('/\.(md|pdf)$/i', '', $item['name']);
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

/** 把启用的自定义挂载扫描成树并合并进 $tree（主树同名优先）：目录递归扫描、单 .md 文件作顶层条目。
    去重只针对"先前已占用的名字"（主树 + 更早的挂载）——不能把挂载自己的子文件过滤掉。
    Tree 页双写法：隐藏/置顶的相对条目不作用于挂载；绝对条目换算成挂载内相对坐标生效
    （置顶沿用 scan_tree 的"本层排最前"语义） */
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
        $customTree = scan_tree($m['root'], '', [], $relPinDirs, $relPinArticles);
        $customTree = filter_abs_hidden($customTree, $m['root'], $absEx);
        // 仅过滤"之前已占用"的顶层名；本挂载自己的子节点不参与去重
        $customTree = array_values(array_filter($customTree, function ($n) use ($seen) {
            return !isset($seen[$n['name']]);
        }));
        // 本挂载占用的名字 → 供后续挂载去重
        foreach ($customTree as $item) {
            $seen[$item['name']] = true;
            if ($item['type'] === 'dir') {
                foreach (($item['children'] ?? []) as $f) {
                    if (($f['type'] ?? '') === 'file') $seen[basename($f['name'])] = true;
                }
            }
        }
        $tree = array_merge($tree, $customTree);
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
    $mainRoot = realpath(PANEL_DIR . '/vault');
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
