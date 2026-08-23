<?php
/**
 * BrainPress dev router — emulates the nginx rewrite rules for `php -S`.
 * Usage: php -S 127.0.0.1:8080 -t <project-dir> bp-router.php
 */
$ROOT = __DIR__;
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

/* Block sensitive files (mirrors nginx: return 404) */
if (preg_match('~(config\.json|\.user\.ini|\.env|\.bak|\.tmp|\.log)$~i', $uri)) {
    http_response_code(404);
    exit('Not Found');
}

/* /admin and /api/admin/* -> admin.php */
if ($uri === '/admin' || $uri === '/admin/' || strpos($uri, '/api/admin/') === 0) {
    require $ROOT . '/admin.php';
    return true;
}

/* Real static files served directly by the built-in server.
   Mirrors nginx `location ^~ /vault/ {}` (prefix match beats regex): EVERYTHING
   under /vault/ passes through as-is, so pdf.js can fetch raw /vault/*.pdf bytes.
   Outside /vault/, .md/.json still go to index.php (rendering / blocking).
   The pretty extension-less or /xxx.pdf URLs are not real files -> index.php. */
$abs = $ROOT . rawurldecode($uri);
if ($uri !== '/' && strpos($abs, $ROOT . '/') === 0 && is_file($abs)) {
    if (strpos($uri, '/vault/') === 0 || !preg_match('~\.(md|json)$~i', $uri)) {
        return false;
    }
}

/* Custom Path 挂载兜底：/vault/<rel> 在主 vault 不存在时，从启用的自定义挂载目录流式输出。
   前端资源引用固定走 /vault/<rel>（pdf.js 字节流、md 内嵌图片），挂载外部目录后同样要可读。
   仅 GET、禁目录穿越；MIME 按扩展名白名单映射，未知类型按二进制流。 */
if (strpos($uri, '/vault/') === 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $rel = ltrim(rawurldecode($uri), '/');
    $rel = substr($rel, strlen('vault/'));
    $cfgFile = $ROOT . '/config.json';
    $cfg = is_file($cfgFile) ? (json_decode((string)@file_get_contents($cfgFile), true) ?: []) : [];
    foreach (($cfg['custom_paths'] ?? []) as $cp) {
        if (empty($cp['on'])) continue;
        $p = trim((string)($cp['path'] ?? ''));
        if ($p === '') continue;
        $root = realpath($p);
        if ($root === false || !is_dir($root)) continue;
        $full = realpath($root . '/' . $rel);
        if ($full === false || strpos($full, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) continue;
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mimeMap = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'bmp' => 'image/bmp', 'avif' => 'image/avif',
            'pdf' => 'application/pdf', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'txt' => 'text/plain; charset=utf-8',
        ];
        header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string)filesize($full));
        readfile($full);
        return true;
    }
}

/* Everything else (.md render, .pdf reader, /, /api/*) -> index.php */
require $ROOT . '/index.php';
