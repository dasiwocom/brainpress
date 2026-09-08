<?php
/**
 * BrainPress v3.0.0 — WebDAV 端点（Obsidian Remotely Save 同步）
 * 由 index.php 在 /dav 路径命中时 require；生产 nginx 无需感知本文件。
 */

declare(strict_types=1);

/** WebDAV 端点：Obsidian Remotely Save 同步（挂载根 = vault/ 下该账号绑定的同步目录） */
function handle_webdav(string $uri, string $method, array $config): never
{
    // 同步账号列表（每账号 = user/pass/path 三元组，各自锁一个 vault 子目录）
    $mounts = [];
    foreach ((array)($config['webdav_mounts'] ?? []) as $m) {
        if (!is_array($m)) continue;
        $u = trim((string)($m['user'] ?? ''));
        $p = (string)($m['pass'] ?? '');
        $pt = trim(trim((string)($m['path'] ?? ''), '/'), " \t");
        if ($u === '' && $p === '') continue;
        $mounts[] = ['user' => $u, 'pass' => $p, 'path' => $pt];
    }
    // 兼容旧单账号字段 webdav_user / webdav_pass（仍写入 config 的老后台）
    if ($mounts === [] && trim((string)($config['webdav_user'] ?? '')) !== '') {
        $mounts[] = ['user' => trim((string)$config['webdav_user']),
                     'pass' => (string)($config['webdav_pass'] ?? ''),
                     'path' => ''];
    }
    // Basic Auth 校验（Remotely Save 必填认证）：哪个账号匹配，就用它的目录
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? '';
    }
    $matched = null;
    if ($authHeader !== '' && strpos($authHeader, 'Basic ') === 0) {
        $decoded = base64_decode(substr($authHeader, 6));
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            [$u, $p] = explode(':', $decoded, 2);
            foreach ($mounts as $m) {
                if ($u === $m['user'] && $p === $m['pass']) { $matched = $m; break; }
            }
        }
    }
    if (!$matched) {
        header('WWW-Authenticate: Basic realm="vault"');
        http_response_code(401);
        exit;
    }

    $davRoot = PANEL_DIR . '/vault';
    // 该账号的同步目录（path 为空 = vault 根；Obsidian 往这个目录里读写并把它当作库根）
    if ($matched['path'] !== '') $davRoot .= '/' . $matched['path'];
    // 目标根不存在时自动创建（首次同步、或账号指定的子目录尚未建立）
    if (!is_dir($davRoot)) @mkdir($davRoot, 0775, true);
    $realRoot = realpath($davRoot);
    // 相对路径（去掉 /dav/ 前缀）；兼容 Obsidian 附加的库名前缀（/dav/<vault>/...）
    $rel = trim(substr($uri, 4), '/');
    $rel = urldecode($rel);
    // 剥掉 Obsidian Remotely Save 附加的库名前缀（兼容大小写 + vault/obsidian 两种 vault 名）
    $relLower = strtolower($rel);
    if ($relLower === 'obsidian' || strpos($relLower, 'obsidian/') === 0) {
        $rel = substr($rel, strlen('obsidian'));
        $rel = trim($rel, '/');
    } elseif ($relLower === 'vault' || strpos($relLower, 'vault/') === 0) {
        $rel = substr($rel, strlen('vault'));
        $rel = trim($rel, '/');
    }
    // 防目录穿越：路径规范化（支持不存在的目标——PUT/MKCOL 要创建）
    $full = $davRoot . ($rel !== '' ? '/' . $rel : '');
    // 规范化（解析 ..）；边界比较带尾部 '/'，杜绝 /vault 命中 /vault-other 这类前缀伪命中
    $norm = realpath(dirname($full));
    if ($rel !== '' && ($norm === false || ($norm !== $realRoot && strpos($norm, $realRoot . '/') !== 0))) {
        http_response_code(403);
        exit;
    }
    // 已存在的目标用 realpath（确保是真实路径）
    if (file_exists($full)) {
        $full = realpath($full);
        if ($full !== $realRoot && strpos($full, $realRoot . '/') !== 0) {
            http_response_code(403);
            exit;
        }
    }

    switch ($method) {
        case 'PROPFIND':
            // 列目录 / 查属性（Remotely Save 需要）
            header('Content-Type: application/xml; charset=utf-8');
            http_response_code(207);
            // href 回显请求路径（保持 /dav/<vault>/... 形式，Obsidian 按此匹配）
            $reqPath = rtrim($uri, '/');
            $base = $reqPath;
            // 状态时间：存在返回 filemtime，不存在（新目录尚未建立）给当前时间。
            // filemtime 在路径不存在时返回 false，直接喂 gmdate 会抛 TypeError → 整段 PROPFIND 输出被截断，
            // Obsidian Remotely Save 解析残破 XML 报「cannot read properties of undefined (reading 'split')」。
            $mtime = @filemtime($full);
            if ($mtime === false) $mtime = time();
            echo '<?xml version="1.0" encoding="utf-8"?>';
            echo '<D:multistatus xmlns:D="DAV:">';
            // 当前项（不存在也回 C：collection，Remotely Save 视其为可进入的同步根，触发 MKCOL/PUT 建库）
            echo '<D:response><D:href>' . htmlspecialchars($base . (is_dir($full) ? '/' : '')) . '</D:href>';
            echo '<D:propstat><D:prop><D:resourcetype><D:collection/></D:resourcetype>';
            echo '<D:getlastmodified>' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT</D:getlastmodified>';
            if (is_file($full)) echo '<D:getcontentlength>' . filesize($full) . '</D:getcontentlength>';
            echo '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
            // 子项（目录时）——href 用请求路径 + 文件名
            if (is_dir($full)) {
                foreach (scandir($full) as $entry) {
                    if ($entry === '.' || $entry === '..' || $entry[0] === '.') continue;
                    $child = $full . '/' . $entry;
                    $childHref = $base . '/' . rawurlencode($entry);
                    echo '<D:response><D:href>' . htmlspecialchars($childHref . (is_dir($child) ? '/' : '')) . '</D:href>';
                    echo '<D:propstat><D:prop><D:resourcetype>' . (is_dir($child) ? '<D:collection/>' : '') . '</D:resourcetype>';
                    echo '<D:getlastmodified>' . gmdate('D, d M Y H:i:s', $childMtime = @filemtime($child) ?: time()) . ' GMT</D:getlastmodified>';
                    if (is_file($child)) echo '<D:getcontentlength>' . filesize($child) . '</D:getcontentlength>';
                    echo '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
                }
            }
            echo '</D:multistatus>';
            exit;

        case 'GET':
        case 'HEAD':
            // 读文件
            if ($rel === '' || !is_file($full)) {
                http_response_code(404);
                exit;
            }
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Length: ' . filesize($full));
            readfile($full);
            exit;

        case 'PUT':
            // 写文件（创建/覆盖）
            if ($rel === '') {
                http_response_code(400);
                exit;
            }
            // 写入大小上限（256MB）：防磁盘耗尽；Content-Length 预检 + 流拷贝后置校验
            $maxBytes = 256 * 1024 * 1024;
            $cl = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($cl > $maxBytes) {
                http_response_code(413);
                exit;
            }
            $dir = dirname($full);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $in = fopen('php://input', 'rb');
            $out = fopen($full, 'wb');
            if ($in && $out) {
                $written = stream_copy_to_stream($in, $out, $maxBytes + 1);
                $over = $written === false || $written > $maxBytes;
                fclose($in);
                fclose($out);
                if ($over) { @unlink($full); http_response_code(413); exit; }
                @chmod($full, 0644);
                http_response_code(201);
            } else {
                http_response_code(500);
            }
            exit;

        case 'DELETE':
            // 删除文件/目录
            if ($rel === '' || !file_exists($full)) {
                http_response_code(404);
                exit;
            }
            if (is_dir($full)) {
                // 递归删除目录内容
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($full);
            } else {
                @unlink($full);
            }
            http_response_code(204);
            exit;

        case 'MKCOL':
            // 建目录
            if ($rel === '') {
                http_response_code(405);
                exit;
            }
            if (!is_dir($full)) {
                @mkdir($full, 0755, true);
                http_response_code(201);
            } else {
                http_response_code(405);
            }
            exit;

        case 'OPTIONS':
            // 能力声明
            header('Content-Type: text/plain; charset=utf-8');
            header('DAV: 1,2');
            header('Allow: PROPFIND, GET, HEAD, PUT, DELETE, MKCOL, OPTIONS');
            header('MS-Author-Via: DAV');
            http_response_code(200);
            exit;

        default:
            http_response_code(405);
            exit;
    }
}
