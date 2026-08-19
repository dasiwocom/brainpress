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
$minioSecret   = $config['minio_secret'] ?? 'bnRPEmtSC8Z4E8NK';
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
