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

/* Everything else (.md render, .pdf reader, /vault/<rel> mount fallback, /api/*) -> index.php.
   The custom-mount streaming fallback now lives inside index.php (shared by every SAPI/gateway). */
require $ROOT . '/index.php';
