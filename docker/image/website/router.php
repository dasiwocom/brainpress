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

/* /vault/ 缺失文件兜底（GET）：主 vault 没有的静态资源，从启用的自定义挂载目录流式输出。
   Mirrors apache-site.conf; renders md/pdf/canvas/html through index.php (hidden + render-type gates). */
$abs = $ROOT . rawurldecode($uri);
if ($uri !== '/' && strpos($abs, $ROOT . '/') === 0 && is_file($abs)) {
    if (strpos($uri, '/vault/') === 0) {
        /* 渲染文档类型（md/pdf/canvas/html）→ index.php：隐藏列表 + 渲染类型门禁；
           图片/音视频等保持静态直出（性能）。 */
        if (!preg_match('~\.(md|pdf|canvas|html)$~i', $uri)) {
            return false;
        }
    } elseif (!preg_match('~\.(md|json|pdf|canvas|html)$~i', $uri)) {
        return false;
    }
}

/* Everything else (.md render, .pdf reader, /vault/<rel> mount fallback, /api/*) -> index.php.
   The custom-mount streaming fallback now lives inside index.php (shared by every SAPI/gateway). */
require $ROOT . '/index.php';
