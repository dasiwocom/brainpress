<?php
/**
 * MD2HTML v1.1.0 — 文档站（主站入口）
 * 公共函数层见 functions.php；后台见 admin.php（nginx 转发 /admin、/api/admin/）
 */

require __DIR__ . '/functions.php';

/* ---------- 路由 ---------- */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 静态资源由 PHP 直接读取（不依赖内置服务器的文档根目录）
if (strpos($uri, '/assets/') === 0) {
    $f = __DIR__ . $uri;
    if (is_file($f)) {
        header('Content-Type: ' . (pathinfo($f, PATHINFO_EXTENSION) === 'js' ? 'application/javascript' : 'text/css'));
        header('Cache-Control: max-age=3600');
        readfile($f);
        exit;
    }
    fail('Not Found', 404);
}

// ===== WebDAV 端点：Obsidian Remotely Save 同步（挂载根 = vault/ 目录） =====
if (strpos($uri, '/dav/') === 0 || $uri === '/dav') {
    // Basic Auth 校验（Remotely Save 必填认证）
    $davUser = $config['webdav_user'] ?? '';
    $davPass = $config['webdav_pass'] ?? '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? '';
    }
    $authOk = false;
    if ($authHeader !== '' && strpos($authHeader, 'Basic ') === 0) {
        $decoded = base64_decode(substr($authHeader, 6));
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            [$u, $p] = explode(':', $decoded, 2);
            $authOk = ($u === $davUser && $p === $davPass);
        }
    }
    if (!$authOk) {
        header('WWW-Authenticate: Basic realm="vault"');
        http_response_code(401);
        exit;
    }

    $davRoot = PANEL_DIR . '/vault';
    // 相对路径（去掉 /dav/ 前缀）；兼容 Obsidian 附加的库名前缀（/dav/<vault>/...）
    $rel = trim(substr($uri, 4), '/');
    $rel = urldecode($rel);
    // 剥掉 Obsidian Remotely Save 附加的库名前缀（兼容 obsidian / vault 两种 vault 名）
    if ($rel === 'obsidian' || strpos($rel, 'obsidian/') === 0) {
        $rel = substr($rel, strlen('obsidian'));
        $rel = trim($rel, '/');
    } elseif ($rel === 'vault' || strpos($rel, 'vault/') === 0) {
        $rel = substr($rel, strlen('vault'));
        $rel = trim($rel, '/');
    }
    // 防目录穿越：路径规范化（支持不存在的目标——PUT/MKCOL 要创建）
    $full = $davRoot . ($rel !== '' ? '/' . $rel : '');
    // 规范化（解析 ..）
    $norm = realpath(dirname($full));
    if ($rel !== '' && ($norm === false || strpos($norm, realpath($davRoot)) !== 0)) {
        http_response_code(403);
        exit;
    }
    // 已存在的目标用 realpath（确保是真实路径）
    if (file_exists($full)) {
        $full = realpath($full);
        if (strpos($full, realpath($davRoot)) !== 0) {
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
            echo '<?xml version="1.0" encoding="utf-8"?>';
            echo '<D:multistatus xmlns:D="DAV:">';
            // 当前项
            echo '<D:response><D:href>' . htmlspecialchars($base . (is_dir($full) ? '/' : '')) . '</D:href>';
            echo '<D:propstat><D:prop><D:resourcetype>' . (is_dir($full) ? '<D:collection/>' : '') . '</D:resourcetype>';
            echo '<D:getlastmodified>' . gmdate('D, d M Y H:i:s', filemtime($full)) . ' GMT</D:getlastmodified>';
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
                    echo '<D:getlastmodified>' . gmdate('D, d M Y H:i:s', filemtime($child)) . ' GMT</D:getlastmodified>';
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
            $dir = dirname($full);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $in = fopen('php://input', 'rb');
            $out = fopen($full, 'wb');
            if ($in && $out) {
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
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

// HTML 页面禁用缓存，避免用户看到旧版本
if ($uri === '/' || $uri === '/index.php') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

/* --- API --- */
if (strpos($uri, '/api/') === 0) {
    header('Content-Type: application/json; charset=utf-8');

    // 首次设置密码（无 password_hash 时可调用）
    if ($uri === '/api/setup' && $method === 'POST') {
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $password = (string)($body['password'] ?? '');
        if (empty($config['password_hash'])) {
            if (strlen($password) < 4) {
                fail('Password must be at least 4 characters');
            }
            $config['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            if (file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
                fail('Failed to save config', 500);
            }
            session_regenerate_id(true);
            $_SESSION['authed'] = true;
            ok();
        } else {
            fail('Password already set', 400);
        }
    }

    // 登录
    if ($uri === '/api/login' && $method === 'POST') {
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $password = (string)($body['password'] ?? '');
        if (empty($config['password_hash'])) {
            ok(['authed' => true]);
        }
        if (password_verify($password, $config['password_hash'] ?? '')) {
            session_regenerate_id(true);
            $_SESSION['authed'] = true;
            ok(['authed' => true]);
        }
        fail('Wrong password', 401);
    }

    // 登出
    if ($uri === '/api/logout' && $method === 'POST') {
        $_SESSION = [];
        session_destroy();
        ok();
    }

    // 仅 /api/admin/* 需要登录（主站 list/file 公开）
    if (strpos($uri, '/api/admin/') === 0) {
        require_auth();
    }

    
    // 文件树（按渲染开关多选合并：本地 vault/ + MinIO 桶）
    if ($uri === '/api/list' && $method === 'GET') {
        $renderWebdav = $config['render_webdav'] ?? true;
        $renderMinio = $config['render_minio'] ?? true;
        $tree = [];
        $seen = []; // 同名去重（本地优先）

        if ($renderWebdav) {
            $localTree = scan_tree(PANEL_DIR . '/vault', '', $config['exclude_paths'] ?? [], $config['pinned_dirs'] ?? [], $config['pinned_articles'] ?? []);
            $tree = array_merge($tree, $localTree);
            // 记录本地目录名 + 文件名（同名去重用）
            foreach ($localTree as $dir) {
                $seen[$dir['name']] = true; // 目录名也去重
                if ($dir['type'] !== 'dir') continue;
                foreach (($dir['children'] ?? []) as $f) {
                    if ($f['type'] === 'file') $seen[basename($f['name'])] = true;
                }
            }
        }

        if ($renderMinio) {
            $minioTree = minio_ls('');
            $minioTree = array_values(array_filter($minioTree, function ($n) use ($seen) {
                // 目录/文件同名都跳过（本地优先）
                return !isset($seen[$n['name']]);
            }));
            foreach ($minioTree as &$n) {
                if ($n['type'] === 'dir') {
                    $children = minio_ls($n['path']);
                    // 过滤掉本地已有的同名文件
                    $n['children'] = array_values(array_filter($children, function ($f) use ($seen) {
                        return !isset($seen[$f['name']]);
                    }));
                }
            }
            unset($n);
            $tree = array_merge($tree, $minioTree);
        }

        // 自定义本地路径（最多 4 条）：目录 → 递归扫描；单个 .md → 只渲染该文件
        $customPaths = $config['custom_paths'] ?? [];
        foreach ($customPaths as $cp) {
            if (empty($cp['on'])) continue;
            $customPath = trim((string)($cp['path'] ?? ''));
            if ($customPath === '') continue;
            $rp = realpath($customPath);
            if ($rp === false) continue;
            if (is_file($rp) && is_md($rp)) {
                $name = basename($rp);
                if (!isset($seen[$name])) {
                    $tree[] = ['name' => $name, 'path' => $name, 'type' => 'file'];
                    $seen[$name] = true;
                }
            } elseif (is_dir($rp)) {
                $customTree = scan_tree($rp);
                // 同名去重（跳过已在树里的名字）
                $customTree = array_values(array_filter($customTree, function ($n) use ($seen) {
                    return !isset($seen[$n['name']]);
                }));
                // 记录本次加入的名字（顶层目录/文件 + 第一层文件），供后续路径去重
                foreach ($customTree as $item) {
                    $seen[$item['name']] = true;
                    if ($item['type'] === 'dir') {
                        foreach (($item['children'] ?? []) as $f) {
                            if ($f['type'] === 'file') $seen[basename($f['name'])] = true;
                        }
                    }
                }
                foreach ($customTree as &$n) {
                    if ($n['type'] === 'dir') {
                        $children = $n['children'] ?? [];
                        $n['children'] = array_values(array_filter($children, function ($f) use ($seen) {
                            return !isset($seen[$f['name']]);
                        }));
                    }
                }
                unset($n);
                $tree = array_merge($tree, $customTree);
            }
        }

        ok(['tree' => $tree]);
    }

    // 读取文件（按渲染开关：本地路径读本地，否则读桶）
    if ($uri === '/api/file' && $method === 'GET') {
        $rel = (string)($_GET['path'] ?? '');
        $renderWebdav = $config['render_webdav'] ?? true;
        $renderMinio = $config['render_minio'] ?? true;

        // Custom Path 优先（最多 4 条）：目录 → rel 必须落在目录内；单文件 → 只匹配该文件（防路径穿越）
        $customPaths = $config['custom_paths'] ?? [];
        foreach ($customPaths as $cp) {
            if (empty($cp['on'])) continue;
            $customPath = trim((string)($cp['path'] ?? ''));
            if ($customPath === '') continue;
            $rp = realpath($customPath);
            if ($rp === false) continue;
            if (is_file($rp) && is_md($rp) && $rel === basename($rp)) {
                $content = @file_get_contents($rp);
                if ($content === false) fail('文件不可读');
                if (strlen($content) > MAX_FILE_SIZE) fail('文件过大');
                ok([
                    'path' => $rel,
                    'content' => $content,
                    'mtime' => date('Y-m-d H:i:s', (int)filemtime($rp)),
                    'size' => strlen($content),
                ]);
            } elseif (is_dir($rp)) {
                $full = realpath($rp . '/' . $rel);
                if ($full !== false && strpos($full, $rp . '/') === 0 && is_file($full) && is_md($full)) {
                    $content = @file_get_contents($full);
                    if ($content === false) fail('文件不可读');
                    if (strlen($content) > MAX_FILE_SIZE) fail('文件过大');
                    ok([
                        'path' => $rel,
                        'content' => $content,
                        'mtime' => date('Y-m-d H:i:s', (int)filemtime($full)),
                        'size' => strlen($content),
                    ]);
                }
            }
        }

        // 隐藏列表校验：命中排除（精确路径/目录前缀/文件名）→ 前台视为不存在
        $excludes = $config['exclude_paths'] ?? [];
        if ($rel !== '' && is_excluded($rel, $excludes)) {
            fail('文件不存在');
        }

        // 本地路径（vault/ 下）→ 读本地；否则 → 读桶
        $localFull = realpath(PANEL_DIR . '/vault/' . $rel);
        $isLocal = ($localFull !== false && strpos($localFull, realpath(PANEL_DIR . '/vault') . '/') === 0);
        if ($isLocal) {
            if (!$renderWebdav) fail('文件不存在');
            $full = $localFull;
            if (!is_file($full) || !is_md($full)) fail('文件不存在');
            $content = @file_get_contents($full);
            if ($content === false) fail('文件不可读');
            if (strlen($content) > MAX_FILE_SIZE) fail('文件过大');
            ok([
                'path' => $rel,
                'content' => $content,
                'mtime' => date('Y-m-d H:i:s', (int)filemtime($full)),
                'size' => strlen($content),
            ]);
        } else {
            if (!$renderMinio) fail('文件不存在');
            // 去掉可能的 posts/ 前缀（桶根就是文章根）
            $rel = preg_replace('#^posts/#', '', $rel);
            if (!is_md($rel)) fail('文件不存在');
            $content = minio_cat($rel);
            if ($content === null) fail('文件不存在');
            if (strlen($content) > MAX_FILE_SIZE) fail('文件过大');
            ok([
                'path' => $rel,
                'content' => $content,
                'mtime' => date('Y-m-d H:i:s'),
                'size' => strlen($content),
            ]);
        }
    }

    // 全文搜索（服务端）：扫 vault 匹配标题/内容，返回路径+名称+命中片段（隐藏列表不收录）
    if ($uri === '/api/search' && $method === 'GET') {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') fail('查询词为空', 400);
        $excludes = $config['exclude_paths'] ?? [];
        $files = [];
        collect_md_files(PANEL_DIR . '/vault', '', $files);
        $results = [];
        foreach ($files as $f) {
            if (is_excluded($f['path'], $excludes)) continue;
            $content = (string)@file_get_contents(PANEL_DIR . '/vault/' . $f['path']);
            $pos = mb_stripos($content, $q);
            $nameHit = mb_stripos($f['name'], $q) !== false;
            if (!$nameHit && $pos === false) continue;
            $snippet = $nameHit ? $f['name'] : '…' . mb_substr($content, max(0, $pos - 40), 90) . '…';
            $results[] = ['path' => $f['path'], 'name' => $f['name'], 'snippet' => $snippet];
        }
        ok(['query' => $q, 'count' => count($results), 'results' => $results]);
    }

    // Graph View 数据：扫描 vault 解析 [[wikilink]]，输出节点 + 连线（?dir= 限定范围；links 仅含两端都在范围内的）
    if ($uri === '/api/graph' && $method === 'GET') {
        $gDir = trim((string)($_GET['dir'] ?? ''));
        $gExcludes = $config['exclude_paths'] ?? [];
        $gFiles = [];
        collect_md_files(PANEL_DIR . '/vault', '', $gFiles);
        $nodes = []; $links = []; $idMap = []; $gid = 0;
        foreach ($gFiles as $f) {
            if (is_excluded($f['path'], $gExcludes)) continue;
            if ($gDir !== '' && strpos($f['path'], $gDir . '/') !== 0) continue;
            $idMap[$f['path']] = $gid;
            $nodes[] = ['id' => $gid, 'name' => $f['name'], 'path' => $f['path'], 'dir' => dirname($f['path'])];
            $gid++;
        }
        // 链接：解析 [[wikilink]]（含 [[路径]] 与 [[文件名]] 两种写法，按 basename 匹配）
        foreach ($gFiles as $f) {
            if (is_excluded($f['path'], $gExcludes)) continue;
            if ($gDir !== '' && strpos($f['path'], $gDir . '/') !== 0) continue;
            if (!isset($idMap[$f['path']])) continue;
            $content = (string)@file_get_contents(PANEL_DIR . '/vault/' . $f['path']);
            if ($content === '') continue;
            if (preg_match_all('/\[\[([^\]\|#]+)(?:\|[^\]]*)?\]\]/u', $content, $gm)) {
                foreach ($gm[1] as $gTarget) {
                    $gTarget = trim($gTarget);
                    if ($gTarget === '') continue;
                    foreach ($idMap as $gPath => $gI) {
                        if ($gI === $idMap[$f['path']]) continue;
                        $gBase = basename($gPath, '.md');
                        if ($gPath === $gTarget || $gBase === $gTarget || $gBase === basename($gTarget, '.md')) {
                            $links[] = ['source' => $idMap[$f['path']], 'target' => $gI];
                            break;
                        }
                    }
                }
            }
        }
        // 去重（双向/重复链接只留一条）
        $seen = [];
        $uLinks = [];
        foreach ($links as $l) {
            $key = $l['source'] < $l['target'] ? $l['source'] . '-' . $l['target'] : $l['target'] . '-' . $l['source'];
            if (!isset($seen[$key])) { $seen[$key] = true; $uLinks[] = $l; }
        }
        ok(['nodes' => $nodes, 'links' => $uLinks, 'count' => count($nodes)]);
    }

    // AI 问答（RAG）：检索知识库相关文章 → 喂 DeepSeek 生成回答（只读；总开关关闭时禁用）
    // 模式：hybrid（默认）知识库优先+通用兜底；strict 只基于知识库回答
    if ($uri === '/api/ask' && $method === 'POST') {
        if (empty($config['ai_enabled'] ?? true)) fail('AI disabled', 403);
        $aiKey = (string)($config['ai_api_key'] ?? '');
        $aiModel = (string)($config['ai_model'] ?? 'deepseek-chat');
        $aiMode = (string)($config['ai_mode'] ?? 'hybrid');
        if ($aiKey === '') fail('AI not configured — set the API key in admin → AI', 503);
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $question = trim((string)($body['question'] ?? ''));
        if ($question === '') fail('Empty question', 400);
        // 1) 检索相关文章（标题加权 + 内容关键词匹配，top 4）
        $excludes = $config['exclude_paths'] ?? [];
        $files = [];
        collect_md_files(PANEL_DIR . '/vault', '', $files);
        $hits = [];
        foreach ($files as $f) {
            if (is_excluded($f['path'], $excludes)) continue;
            $content = (string)@file_get_contents(PANEL_DIR . '/vault/' . $f['path']);
            if ($content === '') continue;
            $score = 0;
            if (mb_stripos($f['name'], $question) !== false) $score += 5;
            if (mb_stripos($content, $question) !== false) $score += 3;
            // 分词：英文/数字整词 + 中文 2 字滑窗（中文句子无空格，整句匹配必失败）
            $tokens = [];
            if (preg_match_all('/[a-zA-Z0-9][a-zA-Z0-9\-\._]*/u', $question, $mEn)) { $tokens = $mEn[0]; }
            $chars = preg_split('//u', $question, -1, PREG_SPLIT_NO_EMPTY);
            for ($i = 0; $i + 1 < count($chars); $i++) { $tokens[] = $chars[$i] . $chars[$i + 1]; }
            foreach ($tokens as $kw) {
                if ($kw === '' || mb_strlen($kw) < 2) continue;
                if (mb_strlen($kw) >= 3 && mb_stripos($f['name'], $kw) !== false) { $score += 2; break; }
                if (mb_stripos($content, $kw) !== false) $score += 1;
            }
            if ($score > 0) $hits[] = ['path' => $f['path'], 'name' => $f['name'], 'score' => $score, 'content' => $content];
        }
        usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($hits, 0, 4);
        // 2) 拼上下文
        $context = '';
        $sources = [];
        foreach ($top as $h) {
            $context .= '### ' . $h['path'] . "\n" . mb_substr($h['content'], 0, 2000) . "\n\n";
            $sources[] = ['path' => $h['path'], 'name' => $h['name']];
        }
        if ($context === '') {
            if ($aiMode === 'strict') {
                ok(['answer' => 'No relevant articles found in the knowledge base for this question.', 'sources' => []]);
                exit;
            }
            // hybrid：无知识库命中 → 直接通用回答（不带参考资料；不提知识库，避免模型回答"无法访问"）
            $siteTitle = (string)($config['site_title'] ?? 'MD2HTML');
            $sys = 'You are a helpful AI assistant. Answer the question directly and concisely.';
            $payload = json_encode([
                'model' => $aiModel,
                'messages' => [
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $question],
                ],
                'temperature' => 0.7,
                'stream' => false,
            ]);
            $ch = curl_init('https://api.deepseek.com/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $aiKey,
                ],
                CURLOPT_TIMEOUT => 60,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) fail('AI request failed: ' . $err, 502);
            $data = json_decode($resp, true);
            if ($httpCode !== 200 || !isset($data['choices'][0]['message']['content'])) {
                fail('AI error (HTTP ' . $httpCode . '): ' . ($data['error']['message'] ?? 'unknown'), 502);
            }
            ok(['answer' => $data['choices'][0]['message']['content'], 'sources' => []]);
            exit;
        }
        // 3) 调 DeepSeek（OpenAI 兼容接口）
        $siteTitle = (string)($config['site_title'] ?? 'MD2HTML');
        if ($aiMode === 'strict') {
            $sys = 'You are the knowledge-base assistant for ' . $siteTitle . '. Answer ONLY based on the provided reference articles. If the references do not contain the answer, say so. Cite sources by their file paths at the end. Be concise and clear.';
            $userMsg = "Reference articles:\n\n" . $context . "\n\nQuestion: " . $question;
        } else {
            // hybrid：知识库硬性优先——references 是主要来源，自身知识只在资料明显不覆盖时兜底；引用必须标来源
            $sys = 'You are a helpful AI assistant for ' . $siteTitle . '. Reference articles from the knowledge base are provided below and are your PRIMARY source. ALWAYS answer using these references when they are relevant — cite sources by file path at the end of your answer. Use your own knowledge ONLY when the references clearly do not cover the question.';
            $userMsg = "Reference articles (PRIMARY source — use them when relevant and cite them):\n\n" . $context . "\n\nQuestion: " . $question;
        }
        $payload = json_encode([
            'model' => $aiModel,
            'messages' => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => $userMsg],
            ],
            // 命中知识库：低 temperature 跟随资料（事实优先）；通用兜底用 0.7
            'temperature' => $aiMode === 'strict' ? 0.3 : 0.4,
            'stream' => false,
        ]);
        $ch = curl_init('https://api.deepseek.com/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $aiKey,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) fail('AI request failed: ' . $err, 502);
        $data = json_decode($resp, true);
        if ($httpCode !== 200 || !isset($data['choices'][0]['message']['content'])) {
            fail('AI error (HTTP ' . $httpCode . '): ' . ($data['error']['message'] ?? 'unknown'), 502);
        }
        ok(['answer' => $data['choices'][0]['message']['content'], 'sources' => $sources]);
    }

    // 文章清单（轻量）：全部文章路径+名称（隐藏列表不收录）
    if ($uri === '/api/article-list' && $method === 'GET') {
        $excludes = $config['exclude_paths'] ?? [];
        $files = [];
        collect_md_files(PANEL_DIR . '/vault', '', $files);
        $articles = [];
        foreach ($files as $f) {
            if (is_excluded($f['path'], $excludes)) continue;
            $articles[] = ['path' => $f['path'], 'name' => $f['name']];
        }
        ok(['count' => count($articles), 'articles' => $articles]);
    }

    // LLM 友好清单（llms.txt 规范）：标题 + 简介 + 每篇文章一个链接（隐藏列表不收录）
    if ($uri === '/api/llms.txt' && $method === 'GET') {
        $excludes = $config['exclude_paths'] ?? [];
        $files = [];
        collect_md_files(PANEL_DIR . '/vault', '', $files);
        $siteBase = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'docs.dasiwo.com');
        header('Content-Type: text/plain; charset=utf-8');
        echo "# " . ($config['site_title'] ?? 'MD2HTML') . " Knowledge Base\n\n";
        echo "> Markdown notes published at " . $siteBase . " — plain Markdown, server-rendered pages.\n\n";
        foreach ($files as $f) {
            if (is_excluded($f['path'], $excludes)) continue;
            $url = $siteBase . '/' . str_replace('%2F', '/', rawurlencode($f['path']));
            echo '- [' . $f['name'] . '](' . $url . ")\n";
        }
        exit;
    }

    // 知识库写 API（Agent 远程控制）：Bearer Token 认证（config.api_token，空=禁用）
    // POST 创建/覆盖文章，DELETE 删除文章（仅允许 vault/ 内 .md，原子写防半截）
    if ($uri === '/api/note' && ($method === 'POST' || $method === 'DELETE')) {
        $token = trim((string)($config['api_token'] ?? ''));
        if ($token === '') fail('Write API disabled (set api_token in admin panel)', 403);
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $given = preg_replace('/^Bearer\s+/i', '', trim($auth));
        if (!hash_equals($token, $given)) fail('Unauthorized', 401);
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $path = trim((string)($body['path'] ?? ''));
        if ($path === '' || !preg_match('#\.md$#i', $path)) fail('Invalid path (must be a .md file)', 400);
        $vaultRoot = realpath(PANEL_DIR . '/vault');
        if ($vaultRoot === false) fail('Vault not found', 500);
        if ($method === 'POST') {
            $content = (string)($body['content'] ?? '');
            $full = realpath(PANEL_DIR . '/vault/' . $path);
            if ($full === false) {
                // 新建：父目录必须存在且在 vault 内
                $parent = realpath(dirname(PANEL_DIR . '/vault/' . $path));
                if ($parent === false || strpos($parent, $vaultRoot) !== 0) fail('Invalid path', 400);
                $full = $parent . '/' . basename($path);
            } elseif (strpos($full, $vaultRoot) !== 0 || !is_file($full)) {
                fail('Invalid path', 400);
            }
            $tmp = $full . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $content) === false) fail('Write failed', 500);
            if (!@rename($tmp, $full)) { @unlink($tmp); fail('Write failed', 500); }
            @chmod($full, 0644);
            ok(['path' => $path, 'bytes' => strlen($content), 'created' => !file_exists($full) || true]);
        } else {
            $full = realpath(PANEL_DIR . '/vault/' . $path);
            if ($full === false || strpos($full, $vaultRoot) !== 0 || !is_file($full)) {
                fail('文件不存在', 400);
            }
            if (!@unlink($full)) fail('Delete failed', 500);
            ok(['path' => $path, 'deleted' => true]);
        }
    }

    fail('接口不存在', 404);
}

/* --- 页面 --- */

// 服务端渲染文章：/xxx.md 或 /dir/xxx.md（vault 内文件）→ 读 md 内联到页面（打开即内容，无 JS fetch 等待）
$ssrArticlePath = '';
$ssrArticleContent = '';
if (preg_match('#\.md$#i', $uri) && !preg_match('/\.excalidraw\.md$/i', $uri)) {
    $articleRel = urldecode(ltrim($uri, '/'));
    $vaultRoot = realpath(PANEL_DIR . '/vault');
    $articleFull = realpath(PANEL_DIR . '/vault/' . $articleRel);
    if ($vaultRoot !== false && $articleFull !== false && strpos($articleFull, $vaultRoot) === 0 && is_file($articleFull)) {
        if (!is_excluded($articleRel, $config['exclude_paths'] ?? [])) {
            $ssrArticlePath = $articleRel;
            $ssrArticleContent = (string)@file_get_contents($articleFull);
        }
    }
}

// 服务端渲染 Excalidraw 绘画：.excalidraw.md（Obsidian 插件 compressed-json 格式）→ 内联原文，前端 lz-string 解码 + SVG 渲染
$ssrExcalidrawPath = '';
$ssrExcalidrawContent = '';
if (preg_match('/\.excalidraw\.md$/i', $uri)) {
    $exRel = urldecode(ltrim($uri, '/'));
    $vaultRoot = realpath(PANEL_DIR . '/vault');  // md 分支不处理 excalidraw——这里重新定义
    $exFull = realpath(PANEL_DIR . '/vault/' . $exRel);
    if ($vaultRoot !== false && $exFull !== false && strpos($exFull, $vaultRoot) === 0 && is_file($exFull)) {
        if (!is_excluded($exRel, $config['exclude_paths'] ?? [])) {
            $ssrExcalidrawPath = $exRel;
            $ssrExcalidrawContent = (string)@file_get_contents($exFull);
        }
    }
}

// 服务端渲染 PDF：/xxx.pdf → 内联路径，前端 pdf.js 渲染阅读器（打开即阅读器，无跳转/下载）
$ssrPdfPath = '';
if (preg_match('#\.pdf$#i', $uri)) {
    $pdfRel = urldecode(ltrim($uri, '/'));
    $vaultRoot = realpath(PANEL_DIR . '/vault');
    $pdfFull = realpath(PANEL_DIR . '/vault/' . $pdfRel);
    if ($vaultRoot !== false && $pdfFull !== false && strpos($pdfFull, $vaultRoot) === 0 && is_file($pdfFull)) {
        if (!is_excluded($pdfRel, $config['exclude_paths'] ?? [])) {
            $ssrPdfPath = $pdfRel;
        }
    }
}

// 服务端渲染 Graph View：/graph 或 /graph?dir=xxx → 前端渲染知识图谱（虚拟路径，非文件）
$ssrGraph = preg_match('#^/graph(/|\\?|$)#', $uri) ? true : false;

if ($uri !== '/' && $uri !== '/index.php' && $ssrArticlePath === '' && $ssrPdfPath === '' && $ssrExcalidrawPath === '' && !$ssrGraph) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
// SSR 页面不缓存（CDN/浏览器）：防旧缓存导致文章/PDF 更新不生效
header('Cache-Control: no-cache, must-revalidate');
// 前台侧滑菜单：扫描 vault/ 生成文章目录树（md 嵌套列表，内联零请求；隐藏列表不收录，置顶排最前）
$frontMenuMd = '';
if (is_dir(PANEL_DIR . '/vault')) {
    $frontMenuMd = tree_to_md(scan_tree(PANEL_DIR . '/vault', '', $config['exclude_paths'] ?? [], $config['pinned_dirs'] ?? [], $config['pinned_articles'] ?? []));
}
// Graph View 作为虚拟条目放进文章树顶部（像文章一样可点击，非文件）
$frontMenuMd = "- [GRAPH-VIEW](/graph)\n" . $frontMenuMd;
// 站点设置：标题 / 默认日间 / 前台抽屉默认展开 / 首页文章
$siteTitle = (string)($config['site_title'] ?? 'MD2HTML');
// 页面标题：文章/PDF 路径访问时用文件名（去扩展名与数字前缀），否则站点标题
$pageTitle = $ssrArticlePath !== ''
    ? preg_replace('/^\d+-/', '', preg_replace('/\.md$/i', '', basename($ssrArticlePath))) . ' · ' . $siteTitle
    : ($ssrPdfPath !== ''
        ? preg_replace('/^\d+-/', '', preg_replace('/\.pdf$/i', '', basename($ssrPdfPath))) . ' · ' . $siteTitle
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
    // 2) vault/ 相对路径（去掉可能的前导 /，兼容误填）
    if ($homeFile === '') {
        $rel = ltrim($homeArticle, '/');
        $full = @realpath(PANEL_DIR . '/vault/' . $rel);
        if ($full !== false && @is_file($full) && is_md($full)) $homeFile = $full;
    }
    if ($homeFile !== '') {
        $homeMd = (string)@file_get_contents($homeFile);
        // 标题 = 文件名（与文章页 doc-title 逻辑一致），正文保持完整
        $homeTitle = preg_replace('/^\\d+-/', '', preg_replace('/\\.md$/i', '', basename($homeFile)));
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
<style>
/* Nunito 本地化（Google Fonts 国内不稳，字体切换导致页面颤动） */
@font-face { font-family:'Nunito'; src:url('/assets/fonts/nunito-400.woff2') format('woff2'); font-weight:400; font-display:optional; }
/* Excalidraw 官方手写体（.excalidraw.md 渲染——文字位置/宽度与 Obsidian 一致） */
@font-face { font-family:'Virgil'; src:url('/assets/fonts/Virgil.woff2') format('woff2'); font-display:swap; }
@font-face { font-family:'Nunito'; src:url('/assets/fonts/nunito-600.woff2') format('woff2'); font-weight:600; font-display:optional; }
@font-face { font-family:'Nunito'; src:url('/assets/fonts/nunito-700.woff2') format('woff2'); font-weight:700; font-display:optional; }
@font-face { font-family:'Nunito'; src:url('/assets/fonts/nunito-800.woff2') format('woff2'); font-weight:800; font-display:optional; }
</style>
<link rel="preload" href="/assets/fonts/nunito-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/nunito-600.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/nunito-700.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/nunito-800.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/vs.min.css">
<link rel="stylesheet" href="/assets/vs2015.min.css">
<script src="/assets/highlight.min.js"></script>
<script src="/assets/lz-string.min.js"></script>
<style>
* { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; -webkit-touch-callout:none; }
/* 代码高亮主题：日间 VS Light+，夜间 VS Dark+（vs2015）——背景统一由 pre 控制（code 透明，避免内层白块） */
.hljs { background:transparent; }
html.dark .hljs { background:transparent; }
.md pre code.hljs { background:transparent; padding:0; display:block; overflow-x:auto; }
html.dark .md pre code.hljs { background:transparent; }
/* 日间 token 显式着色（VS Code Light+ 清晰色，防某些 token 继承变浅） */
.hljs-keyword, .hljs-literal, .hljs-name, .hljs-selector-tag, .hljs-tag, .hljs-built_in { color:#0f4ac0; }
.hljs-string, .hljs-title, .hljs-section, .hljs-attribute, .hljs-addition { color:#b31313; }
.hljs-comment, .hljs-quote { color:#1f6f1f; }
.hljs-number, .hljs-symbol, .hljs-bullet, .hljs-link { color:#0875a8; }
.hljs-type, .hljs-class, .hljs-meta { color:#2b6db0; }
.hljs-attr { color:#b31313; }
.hljs-deletion, .hljs-selector-attr, .hljs-selector-pseudo { color:#2b91af; }
.hljs-doctag { color:#6a737d; }
.hljs-variable, .hljs-template-variable { color:#1f6f1f; }
.hljs-emphasis { font-style:italic; }
.hljs-strong { font-weight:700; }
html.dark .hljs-keyword, html.dark .hljs-literal, html.dark .hljs-name, html.dark .hljs-symbol { color:#569cd6; }
html.dark .hljs-built_in, html.dark .hljs-type { color:#4ec9b0; }
html.dark .hljs-class, html.dark .hljs-number { color:#b8d7a3; }
html.dark .hljs-string { color:#d69d85; }
html.dark .hljs-comment, html.dark .hljs-quote { color:#57a64a; font-style:italic; }
html.dark .hljs-variable { color:#bd63c5; }
html.dark .hljs-attr, html.dark .hljs-attribute { color:#9cdcfe; }
html.dark .hljs-title, html.dark .hljs-function, html.dark .hljs-params { color:#dcdcdc; }
html.dark .hljs-section { color:gold; }
html.dark .hljs-selector-tag, html.dark .hljs-selector-class, html.dark .hljs-selector-id { color:#d7ba7d; }
html.dark .hljs-meta, html.dark .hljs-tag { color:#9b9b9b; }
:root {
    --bg:#ffffff; --fg:#1a1a1a; --muted:#8a8a8a; --line:#e2e2e3;
    --hover:#f5f5f5; --accent:#111111;
    /* VitePress 风格颜色变量 */
    --vp-c-bg:#ffffff;          /* 页面背景 */
    --vp-c-bg-alt:#f6f6f7;      /* 侧边栏/浅色区背景（VP 官方） */
    --vp-c-bg-soft:#f6f6f7;
    --vp-c-text-1:#3a3a3a;      /* 主要文字 */
    --vp-c-text-2:#67676c;      /* 次要文字 */
    --vp-c-text-3:#8f8f94;
    --vp-c-divider:#e2e2e3;     /* 分割线 */
    --vp-c-brand:#3451b2;       /* 品牌紫 */
    --vp-c-brand-dark:#535bf2;
    --vp-c-code:#f6f8fa;
    --vp-c-card:#ffffff;
}
html.dark {
    --bg:#1b1b1f; --fg:#e3e3e3; --muted:#8f8f94; --line:#2e2e32;
    --hover:#232327; --accent:#646cff;
    /* VitePress 官方暗色 */
    --vp-c-bg:#1b1b1f;          /* 页面背景（VP 官方） */
    --vp-c-bg-alt:#161618;      /* 侧边栏背景（VP 官方） */
    --vp-c-bg-soft:#202024;
    --vp-c-text-1:rgba(255,255,255,.87);
    --vp-c-text-2:rgba(235,235,245,.6);
    --vp-c-text-3:rgba(235,235,245,.38);
    --vp-c-divider:#2e2e32;
    --vp-c-brand:#3451b2;
    --vp-c-brand-dark:#535bf2;
    --vp-c-code:#161618;
    --vp-c-card:#202024;
}
html,body {
    font-family:"Nunito","PingFang SC","Microsoft YaHei","Noto Sans CJK SC",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;
}
html,body { height:100%; overflow-x:hidden; }
body {
    font-family:"Nunito","PingFang SC","Microsoft YaHei","Noto Sans CJK SC",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    background:var(--bg); color:var(--fg); font-size:14px; line-height:1.7;
    overflow-x:hidden; max-width:100%;
}
a { color:inherit; text-decoration:none; }

/* 主界面 */
#app { display:none; height:100%; }
#app.show { display:flex; }
#main { flex:1; display:flex; flex-direction:column; min-width:0; min-height:0; overflow:hidden; position:relative; }

/* ===== VitePress 风格导航栏（固定定位 + padding 补偿，无占位空白） ===== */
#nav-wrap {
    position:fixed; top:0; left:0; right:0; z-index:400;
    transition:transform .45s cubic-bezier(.22,1,.36,1);
}
#nav-wrap.hidden { transform:translateY(-100%); }
#content {
    flex:1; overflow-y:auto; overflow-x:hidden; min-height:0; min-width:0;
    padding:84px 48px 40px; position:relative;
}
#main.hide-top #content { padding-top:28px; }
.vp-icon-btn {
    display:inline-flex; align-items:center; justify-content:center;
    width:34px; height:34px; border:none; background:none; cursor:pointer;
    color:var(--vp-c-text-1); border-radius:8px; outline:none;
    touch-action:manipulation; /* 与后台一致：消除移动端点击 300ms 延迟 */
}
.vp-icon-btn svg { display:block; stroke-width:2.5; -webkit-backface-visibility:hidden; backface-visibility:hidden; }
.vp-icon-btn { -webkit-transform:translateZ(0); transform:translateZ(0); }
/* 搜索按钮图标 morph：放大镜 ⇄ 叉子 */
.search-ico { position:absolute; transition:opacity .2s ease, transform .3s cubic-bezier(.22,1,.36,1), color .25s ease; }
.search-ico-magnifier { opacity:1; transform:rotate(0) scale(1); }
.search-ico-close { opacity:0; transform:rotate(-90deg) scale(.6); }
#vp-search-btn.open .search-ico-magnifier { opacity:0; transform:rotate(90deg) scale(.6); }
#vp-search-btn.open .search-ico-close { opacity:1; transform:rotate(0) scale(1); }
#vp-logo {
    font-size:22px; font-weight:700; letter-spacing:1.5px; color:var(--vp-c-text-1);
    user-select:none; cursor:pointer; white-space:nowrap;
    display:inline-flex; align-items:center; height:100%; line-height:1;
    /* 全端（桌面+移动）绝对居中 */
    position:absolute; left:50%; transform:translateX(-50%);
    z-index:1;
}
/* 桌面端：内容间距只留第一层 */
@media (min-width:769px) {
    #content { padding-top:84px; }        /* 只留第一层 56px + 28px 间距 */
    #main.hide-top #content { padding-top:28px; }  /* 第一层收起后只剩间距 */
}
/* 主题过渡：所有元素统一颜色过渡（VitePress 同款），避免闪烁/不同步 */
*,
*::before,
*::after {
    transition: background-color .25s ease, color .25s ease, border-color .25s ease;
}
/* 需要 opacity 动画的元素单独覆盖，不受主题过渡影响 */
#toast {
    transition: opacity .25s;
}
#vp-nav {
    height:56px; display:flex; align-items:center; justify-content:space-between;
    padding:0 24px; border-bottom:1px solid var(--line);
    position:relative;
    /* 玻璃拟态：完全透明（透出内容） */
    background:transparent;
    -webkit-backdrop-filter:saturate(2) blur(30px);
    backdrop-filter:saturate(2) blur(30px);
    position:relative;
}
html.dark #vp-nav { background:transparent; -webkit-backdrop-filter:saturate(1.2) blur(12px); backdrop-filter:saturate(1.2) blur(12px); }
#vp-nav-left { display:flex; align-items:center; gap:10px; min-width:0; flex:1; }
#vp-nav-right { display:flex; align-items:center; gap:4px; flex-shrink:0; }
@media (max-width:768px) {
    /* 移动端：logo 保持绝对居中（全端统一） */
    #vp-logo {
        display:flex; align-items:center; justify-content:center;
    }
}
/* 全屏侧滑菜单（与后台同款：层级低于顶部栏、高于页面内容） */
#vp-drawer {
    position:fixed; top:56px; left:0; right:0; bottom:0; z-index:350;
    background:var(--vp-c-bg);
    transform:translate3d(-100%,0,0);
    will-change:transform;
    transition:transform .2s ease-out;
    overflow-y:auto;
}
#vp-drawer.open { transform:translate3d(0,0,0); }
/* 抽屉打开时锁定页面滚动 */
body.drawer-open { overflow:hidden; }
/* 抽屉动画期间：顶部栏关模糊 + 实心化 + 禁背景过渡（防透字/掉帧） */
#nav-wrap.no-blur #vp-nav {
    -webkit-backdrop-filter:none !important;
    backdrop-filter:none !important;
    background:var(--vp-c-bg) !important;
    transition:background-color 0s !important;
}
/* 侧滑菜单内容（md 渲染的文章目录树）：折叠 + 竖线 + 菜单项 */
.drawer-md { padding:8px 16px 40px; }
.drawer-md ul { list-style:none; margin:0; padding:0; }
.drawer-md li { margin:0; }
.drawer-md p { margin:0; }
.drawer-md a {
    display:block; padding:2px 10px; border-radius:8px;
    font-size:15px; font-weight:600; color:var(--vp-c-text-1);
    text-decoration:none; outline:none;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
/* 树顶 Graph view 虚拟条目：与目录项同字号（17px/700——像文件夹一样醒目）；只命中顶层第一个条目 */
#front-drawer-md > ul > li:first-child > a { font-size:17px; font-weight:700; }
.drawer-md a:active { color:var(--vp-c-brand); }
html.dark .drawer-md a:active { color:var(--vp-c-brand); }
.drawer-md li.has-children {
    position:relative;
    /* 目录项：比文章项更大更粗（层级感） */
    font-size:17px; font-weight:700; color:var(--vp-c-text-1);
    padding:2px 10px;
}
/* 目录项左侧箭头（JS 插入的 SVG chevron 元素）：收起 > / 展开 rotate 90° ↓ */
.drawer-md .dir-arrow {
    margin-right:7px; vertical-align:middle;
    color:var(--vp-c-text-2);
    transition:transform .25s ease;
}
.drawer-md li.has-children:not(.collapsed) .dir-arrow {
    transform:rotate(90deg);
}
.drawer-md li.has-children > ul {
    margin-left:14px; padding-left:4px;
    border-left:1px solid var(--line); /* 树形竖线 */
}
.drawer-md li.collapsed > ul { display:none; }

/* 主题切换：日间显示月亮（点击进夜间），夜间显示太阳 */
.vp-icon-btn .theme-sun { display:none; }
.vp-icon-btn .theme-moon { display:block; }
html.dark .vp-icon-btn .theme-sun { display:block; }
html.dark .vp-icon-btn .theme-moon { display:none; }
/* 日夜按钮：桌面端显示右侧，移动端显示左侧 */
.theme-btn-mobile { display:none !important; }
@media (max-width:768px) {
    .theme-btn-mobile { display:inline-flex !important; }
    .theme-btn-desktop { display:none !important; }
}

.empty-state { color:var(--vp-c-text-3); text-align:center; margin-top:22vh; font-size:14px; letter-spacing:2px; }

/* ===== 文章归档页（序号列表） ===== */
.archive-view { max-width:820px; margin:0 auto; margin-top:-20px; }
/* 垂直有序列表：等高条目，左侧序号 + 右侧文本 */
.archive-list-item {
    display:flex; align-items:center; gap:12px; padding:6px 4px; cursor:pointer;
    outline:none; height:36px; overflow:hidden;
}
.archive-list-item .archive-index {
    width:20px; height:20px; flex-shrink:0; border-radius:5px;
    background:var(--vp-c-bg-soft); color:var(--vp-c-text-3);
    display:inline-flex; align-items:center; justify-content:center;
    font-size:11px; font-weight:600;
}
.archive-list-item .archive-title { font-size:15px; font-weight:500; color:var(--vp-c-text-1); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.archive-list-item:hover .archive-title { color:#3451b2; }
html.dark .archive-list-item:hover .archive-title { color:#a8b1ff; }

/* ===== AI 对话面板（从顶部栏下方展开，问知识库） ===== */
.ai-view {
    position:fixed; top:56px; left:0; right:0; z-index:310;
    display:none; flex-direction:column;
    background:var(--vp-c-bg); border-bottom:1px solid var(--line);
    box-shadow:0 10px 28px rgba(0,0,0,.14);
    max-width:760px; margin:0 auto;
    height:min(72vh,560px);
}
.ai-view.open { display:flex; }
.ai-header { padding:10px 16px; font-size:13px; font-weight:600; color:var(--vp-c-text-2); border-bottom:1px solid var(--line); flex-shrink:0; }
.ai-msgs { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:12px; -webkit-overflow-scrolling:touch; }
.ai-msg { max-width:85%; padding:10px 14px; border-radius:10px; font-size:15px; line-height:1.6; white-space:pre-wrap; word-break:break-word; }
.ai-user { align-self:flex-end; background:#3451b2; color:#fff; }
html.dark .ai-user { background:#a8b1ff; color:#111; }
.ai-bot { align-self:flex-start; background:var(--vp-c-bg-soft); color:var(--vp-c-text-1); }
.ai-src { font-size:12px; color:var(--vp-c-text-3); margin-top:8px; }
.ai-src a { color:#3451b2; text-decoration:none; }
html.dark .ai-src a { color:#a8b1ff; }
.ai-input-wrap { display:flex; gap:8px; padding:12px 16px; border-top:1px solid var(--line); flex-shrink:0; }
.ai-input-wrap input { flex:1; min-height:40px; font-size:16px; padding:0 12px; border:1px solid var(--line); border-radius:6px; background:var(--vp-c-bg); color:var(--vp-c-text-1); -webkit-appearance:none; }
.ai-input-wrap input:focus { outline:none; border-color:#3451b2; }
html.dark .ai-input-wrap input:focus { border-color:#a8b1ff; }
.ai-send { border:1px solid #3451b2; background:#3451b2; color:#fff; border-radius:6px; padding:0 18px; font-size:15px; cursor:pointer; flex-shrink:0; }
html.dark .ai-send { border-color:#a8b1ff; background:#a8b1ff; color:#111; }
.ai-typing { font-size:13px; color:var(--vp-c-text-3); padding:4px 2px; }
/* 移动端：AI 面板占更高（全屏感） */
@media (max-width:768px) {
    .ai-view { height:calc(100vh - 56px); max-width:none; }
}

/* ===== 搜索面板（从顶部栏下方滑出） ===== */
.search-view {
    position:fixed; top:56px; left:0; right:0; bottom:0; z-index:300;
    display:flex; flex-direction:column;
    /* 玻璃拟态：完全透明（透出内容） */
    background:transparent;
    -webkit-backdrop-filter:saturate(2) blur(30px);
    backdrop-filter:saturate(2) blur(30px);
    transform:translateY(-100%); visibility:hidden;
    transition:transform .3s cubic-bezier(.22,1,.36,1), visibility .3s;
}
html.dark .search-view { background:transparent; -webkit-backdrop-filter:saturate(1.2) blur(12px); backdrop-filter:saturate(1.2) blur(12px); }
.search-view.open { transform:translateY(0); visibility:visible; }
.search-bar {
    height:56px; display:flex; align-items:center; gap:10px;
    padding:0 24px; border-bottom:1px solid var(--line); flex-shrink:0;
}
.search-bar input {
    flex:1; border:none; outline:none; font-size:16px; color:var(--fg);
    background:transparent; font-family:inherit;
    margin-top:4px; /* 输入框往下调低一点 */
}
.search-bar input::placeholder { color:var(--vp-c-text-3); }
.search-results { flex:1; overflow-y:auto; padding:12px 0 40px; }
.search-results .search-empty {
    padding:60px 20px; text-align:center; color:var(--vp-c-text-3); font-size:13px;
}
.search-results .search-item {
    display:flex; align-items:flex-start; gap:12px; padding:10px 24px; cursor:pointer;
    font-size:14px; color:var(--fg); outline:none;
}
.search-results .search-item .search-item-main { display:flex; align-items:center; gap:12px; flex:1; min-width:0; }
.search-results .search-item .search-cat {
    font-size:11px; color:var(--vp-c-text-3); background:var(--vp-c-bg-soft); border-radius:4px;
    padding:2px 8px; flex-shrink:0; letter-spacing:.5px;
}
.search-results .search-item .search-name { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.search-results .search-item .search-snippet {
    font-size:12px; color:var(--vp-c-text-3); line-height:1.5;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1; min-width:0;
}
.search-results .search-item .search-arrow { color:var(--vp-c-text-3); flex-shrink:0; }

/* 渲染区排版（VitePress 同款） */
.doc-wrap {
    display:flex; gap:32px; align-items:flex-start;
    max-width:1120px; margin:0 auto;
}
.doc-main { flex:1; min-width:0; }
.doc-title {
    font-size:28px; font-weight:600; letter-spacing:-.02em; line-height:1.43;
    color:var(--vp-c-text-1); margin:0 0 24px; padding-bottom:16px;
    border-bottom:1px solid var(--line);
}
html.dark .doc-title { color:#dfdfd6; }
.md { max-width:760px; flex:1; min-width:0; font-size:16px; line-height:25px; color:#3c3c43; font-family:"Nunito","WenQuanYi Zen Hei","文泉驿正黑","PingFang SC","Microsoft YaHei",sans-serif; }
/* 图片限制在容器内（桌面/移动都生效，防止大图溢出屏幕） */
.md img { max-width:100%; height:auto; border-radius:6px; }
/* 文章目录（右侧 TOC） */
.toc-panel {
    width:260px; flex-shrink:0; position:sticky; top:5px;
    border-left:1px solid var(--line); padding-left:18px; margin-top:-12px;
    max-height:calc(100vh - 150px); overflow-y:auto;
}
.toc-title {
    font-size:13px; font-weight:700; letter-spacing:1px; text-transform:uppercase;
    color:var(--vp-c-text-3); margin-bottom:10px; line-height:1.4;
}
#toc-list { display:flex; flex-direction:column; gap:3px; }
.toc-link {
    display:block; font-size:13.5px; line-height:1.55; color:#67676c;
    text-decoration:none; padding:3px 0; cursor:pointer; outline:none;
    border:none; background:none; text-align:left; font-family:inherit;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.toc-link.active { color:#3451b2; font-weight:600; }
.toc-link:hover { color:#3451b2; }
.toc-link.lv-2 { padding-left:16px; font-size:13px; }
.toc-link.lv-3 { padding-left:32px; font-size:12.5px; }
.toc-link.lv-4 { padding-left:48px; font-size:12.5px; }
html.dark .toc-link { color:#98989f; }
html.dark .toc-link.active { color:#a8b1ff; }
html.dark .toc-link:hover { color:#a8b1ff; }
.md h1, .md h2, .md h3, .md h4, .md h5, .md h6 { color:#3c3c43; }
/* 夜间模式：正文/标题统一亮色 #dfdfd6 */
html.dark .md { color:#dfdfd6; }
html.dark .md h1, html.dark .md h2, html.dark .md h3, html.dark .md h4, html.dark .md h5, html.dark .md h6 { color:#dfdfd6; }
/* 夜间模式：正文中的分隔线统一 #2e2e32 */
html.dark .md h2, html.dark .md hr, html.dark .md table, html.dark .md th, html.dark .md td, html.dark .md blockquote { border-color:#2e2e32; }
html.dark .md code { border-color:#2e2e32; }
.md h1 { font-size:28px; font-weight:600; letter-spacing:-.02em; line-height:1.43; margin:0 0 24px; }
.md h2 { font-size:24px; font-weight:600; letter-spacing:-.02em; line-height:1.33; /* border-top:1px solid var(--line); */ margin:32px 0 16px; padding-top:0; }
.md h3 { font-size:20px; font-weight:600; letter-spacing:-.01em; line-height:1.4; margin:32px 0 8px; }
.md h4 { font-size:18px; font-weight:600; letter-spacing:-.01em; margin:24px 0 8px; }
.md p { margin:12px 0; line-height:1.75; }
/* 正文第一个元素紧贴文章标题（去掉其顶部间距，避免 doc-title 下方大段空白） */
.md > :first-child { margin-top:0; }
.md ul, .md ol { margin:12px 0; padding-left:26px; }
.md li { margin:4px 0; }
.md blockquote {
    margin:14px 0; padding:8px 0 8px 16px; border-left:2px solid var(--line);
    color:var(--vp-c-text-2);
}
.md code {
    background:var(--vp-c-bg-soft); padding:2px 6px; border-radius:3px;
    font-family:ui-monospace,"Menlo","Monaco","Consolas","Liberation Mono","Courier New",monospace; font-size:13px;
}
.md pre {
    background:var(--vp-c-bg-soft); border:1px solid var(--line); border-radius:6px;
    padding:16px 20px; margin:14px 0; overflow-x:auto;
    position:relative;
}
.md pre code { background:none; padding:0; border-radius:0; font-size:13px; line-height:1.6; color:#1a1a1a; }
html.dark .md pre code { color:#dcdcdc; }
/* 日间代码块背景：与页面白底有区分 */
.md pre { background:#f4f4f6; }
html.dark .md pre { background:var(--vp-c-bg-soft); border-color:#2e2e32; }
/* 代码块复制按钮（VitePress 风格：40px 图标按钮） */
.md pre .code-copy {
    position:absolute; top:12px; right:12px; z-index:3;
    border:1px solid var(--line); border-radius:4px;
    background:var(--vp-c-bg) center/1.25rem no-repeat;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cg fill='none' stroke='rgba(128,128,128,1)' stroke-linecap='round' stroke-linejoin='round' stroke-width='2'%3E%3Crect width='8' height='4' x='8' y='2' rx='1' ry='1'/%3E%3Cpath d='M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2'/%3E%3C/g%3E%3C/svg%3E");
    width:40px; height:40px; cursor:pointer; outline:none;
    opacity:0; transition:opacity .2s ease, border-color .25s ease, background-color .25s ease;
}
.md pre:hover .code-copy { opacity:1; }
/* 单行代码块：复制按钮垂直居中（避免偏下不对称） */
.md pre .code-copy.single-line { top:50%; transform:translateY(-50%); }
/* 移动端无 hover：按钮常显 */
@media (max-width:768px) {
    .md pre .code-copy { opacity:1; }
}
/* 复制成功：图标换成对勾 */
.md pre .code-copy.copied {
    border-color:#3451b2; background-color:var(--vp-c-bg);
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cg fill='none' stroke='%233451b2' stroke-linecap='round' stroke-linejoin='round' stroke-width='2'%3E%3Cpath d='M20 6 9 17l-5-5'/%3E%3C/g%3E%3C/svg%3E");
}
html.dark .md pre .code-copy.copied {
    border-color:#a8b1ff;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cg fill='none' stroke='%23a8b1ff' stroke-linecap='round' stroke-linejoin='round' stroke-width='2'%3E%3Cpath d='M20 6 9 17l-5-5'/%3E%3C/g%3E%3C/svg%3E");
}
.md table {
    border-collapse:collapse; width:100%; margin:16px 0; font-size:13.5px;
}
.md th, .md td {
    border:1px solid var(--line); padding:8px 14px; text-align:left; vertical-align:top;
}
.md th { background:var(--hover); font-weight:600; white-space:nowrap; }
.md tr:nth-child(2n) td { background:var(--vp-c-bg-soft); }
.md hr { border:none; border-top:1px solid var(--line); margin:24px 0; }
/* 折叠手风琴（details/summary）VitePress 风格 */
.md details {
    border:1px solid var(--line); border-radius:8px;
    padding:4px 16px; margin:14px 0; background:var(--vp-c-bg-soft);
}
.md details summary {
    cursor:pointer; font-weight:600; font-size:15px;
    padding:8px 0; color:var(--vp-c-text-1); outline:none;
    user-select:none; list-style:none;
}
.md details summary::-webkit-details-marker { display:none; }
.md details summary::before {
    content:''; display:inline-block; width:0; height:0; margin-right:8px;
    border-left:5px solid var(--vp-c-text-3); border-top:4px solid transparent;
    border-bottom:4px solid transparent; transition:transform .2s ease;
    vertical-align:middle;
}
.md details[open] summary::before { transform:rotate(90deg); }
.md details .details-content { padding:4px 0 12px; }
.md a {
    color:var(--vp-c-brand); text-underline-offset:.125rem; font-weight:500;
    text-decoration:underline;
}
html.dark .md a { color:#a8b1ff; }
.md a:hover { color:#535bf2; }
html.dark .md a:hover { color:#c7cdff; }
/* Obsidian 双链：蓝色高亮链接 */
.md a.ob-link { color:var(--vp-c-brand); font-weight:600; text-decoration:none; border-bottom:1px dashed rgba(100,108,255,.5); }
.md a.ob-link:hover { color:#535bf2; border-bottom-style:solid; }
.md a.ob-link-missing { color:var(--vp-c-text-3); font-weight:400; border-bottom:1px dashed var(--vp-c-text-3); }
/* Obsidian 标签 */
.md a.ob-tag { color:var(--vp-c-brand); text-decoration:none; font-weight:500; font-size:.9em; padding:1px 6px; background:rgba(100,108,255,.1); border-radius:4px; margin:0 2px; }
.md a.ob-tag:hover { background:rgba(100,108,255,.2); }
/* Obsidian 嵌入 */
.md .ob-embed { display:block; border:1px solid var(--line); border-left:3px solid var(--vp-c-brand); border-radius:6px; padding:12px 16px; margin:12px 0; background:var(--vp-c-bg-soft); font-size:14px; color:var(--vp-c-text-2); }
.md .ob-embed-missing { color:#c0392b; }
.md .ob-embed-inner { color:var(--vp-c-text-1); }
/* 反向链接（Obsidian 风格：区块 + 条目列表 + 引用上下文） */
#backlinks { margin-top:56px; padding-top:28px; border-top:1px solid var(--line); }
.backlinks-head {
    display:flex; align-items:center; gap:10px; margin-bottom:16px;
}
.backlinks-title { font-size:15px; font-weight:600; color:var(--vp-c-text-1); line-height:1; }
.backlinks-list { display:flex; flex-direction:column; gap:8px; }
.backlinks-item {
    display:flex; align-items:center; gap:10px; padding:10px 14px;
    border:1px solid var(--line); border-radius:8px;
    text-decoration:none; cursor:pointer; background:var(--vp-c-bg);
}
.backlinks-item .bl-name { font-size:14px; font-weight:500; color:var(--vp-c-text-1); flex-shrink:0; }
.backlinks-item .bl-excerpt {
    font-size:12px; color:var(--vp-c-text-3); overflow:hidden;
    text-overflow:ellipsis; white-space:nowrap;
}
.md input[type="checkbox"] { margin-right:6px; }

/* 移动端 */
@media (max-width:768px) {
    #content { padding:84px 18px 40px; }
    #main.hide-top #content { padding-top:28px; }
    .toc-panel { display:none; }
    .doc-wrap { display:block; }
    .md { max-width:100%; }
    #vp-nav { padding:0 12px; height:56px; }
    /* 搜索栏与主页导航栏对齐（移动端 padding 0 12px） */
    .search-bar { padding:0 12px; }
    #vp-logo { font-size:20px; }
    .md h1 { font-size:22px; }
    .md h2 { font-size:18px; }
    .md { max-width:100%; }
    .md img { max-width:100%; height:auto; }
    .md table { display:block; width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
    .md pre { max-width:100%; }
    .md code, .md pre code { word-break:break-all; }
    .md td, .md th { white-space:nowrap; }
    .modal { width:min(360px, 92vw); padding:22px 18px; }
}

#toast {
    position:fixed; bottom:24px; left:50%; transform:translateX(-50%);
    background:var(--fg); color:#fff; padding:8px 20px; border-radius:4px;
    font-size:13px; opacity:0; pointer-events:none; transition:opacity .25s; z-index:200;
}

/* PDF 阅读器（pdf.js）：工具栏/画布/日夜反转 */
.pdf-toolbar { display:flex; align-items:center; gap:8px; padding:8px 12px; border-bottom:1px solid var(--line); position:sticky; top:0; background:var(--vp-c-bg); z-index:10; }
.pdf-btn { border:1px solid var(--line); background:transparent; color:var(--vp-c-text-1); border-radius:4px; min-width:30px; height:30px; font-size:16px; cursor:pointer; line-height:1; }
.pdf-btn:hover { border-color:#3451b2; color:#3451b2; }
html.dark .pdf-btn:hover { border-color:#a8b1ff; color:#a8b1ff; }
.pdf-btn.active { background:#3451b2; color:#fff; border-color:#3451b2; }
html.dark .pdf-btn.active { background:#a8b1ff; color:#111; border-color:#a8b1ff; }
.pdf-info { font-size:13px; color:var(--vp-c-text-mute); min-width:56px; text-align:center; }
.pdf-sep { width:1px; height:18px; background:var(--line); }
.pdf-canvas-wrap { flex:1; overflow:auto; padding:16px 0; display:flex; justify-content:flex-start; align-items:flex-start; }
.pdf-canvas-wrap canvas { box-shadow:0 1px 6px rgba(0,0,0,.2); margin:0 auto; display:block; flex:0 0 auto; transition:filter .25s ease; }
/* 全屏阅读：pdf-view 铺满浏览器（工具栏在顶，画布滚动区占满）——原生全屏 + CSS 模拟（手机/iOS 无 Fullscreen API 时） */
#pdf-view:fullscreen { width:100vw; height:100vh; display:flex; flex-direction:column; background:var(--vp-c-bg); padding:0; }
#pdf-view:fullscreen .pdf-toolbar { position:static; }
#pdf-view:fullscreen .pdf-canvas-wrap { flex:1; min-height:0; }
#pdf-view:-webkit-full-screen { width:100vw; height:100vh; display:flex; flex-direction:column; background:var(--vp-c-bg); padding:0; }
#pdf-view.fs { position:fixed; top:0; left:0; right:0; bottom:0; z-index:9999; display:flex; flex-direction:column; background:var(--vp-c-bg); padding:0; }
#pdf-view.fs .pdf-toolbar { position:static; }
#pdf-view.fs .pdf-canvas-wrap { flex:1; min-height:0; }
/* 夜间主题：PDF 自动反转（透明底 → 页面背景透出，文字变白） */
html.dark #pdf-canvas { filter:invert(1) hue-rotate(180deg); }

/* Excalidraw 绘画渲染（.excalidraw.md：SVG 画布自适应） */
.excalidraw-view { padding:24px 8px 40px; }
.excalidraw-canvas svg { max-width:100%; height:auto; display:block; border-radius:8px; box-shadow:0 1px 8px rgba(0,0,0,.12); }
html.dark .excalidraw-canvas svg { box-shadow:0 1px 8px rgba(0,0,0,.4); }

/* ===== Graph View：知识图谱（SVG 力导向） ===== */
/* fixed 铺满视口（导航下方）：不依赖 content 布局/父级高度——任何情况下都有确定尺寸 */
.graph-view { position:fixed; top:56px; left:0; right:0; bottom:0; z-index:50; display:none; flex-direction:column; background:var(--vp-c-bg); }
.graph-info { position:absolute; right:12px; bottom:10px; font-size:12px; color:var(--vp-c-text-mute); z-index:2; }
.graph-canvas-wrap { flex:1; overflow:hidden; position:relative; min-height:480px; touch-action:none; }
#graph-svg g { will-change:transform; }  /* 合成层提示——动画走 GPU */
#graph-svg { width:100%; height:100%; display:block; }
.graph-empty { position:absolute; inset:0; display:none; align-items:center; justify-content:center; color:var(--vp-c-text-3); font-size:14px; }
.graph-link { stroke:var(--line); stroke-width:1; opacity:.55; transition:opacity .2s ease, stroke-width .2s ease; }
html.dark .graph-link { stroke:#3a3a4a; }
.graph-link-hot { opacity:1; stroke-width:2; }
.graph-link-dim { opacity:.06; }
.graph-label { font-family:'Nunito',sans-serif; font-size:17px; font-weight:700; line-height:28.9px; fill:var(--vp-c-text-1); pointer-events:none; text-rendering:optimizeLegibility; }
.graph-node { cursor:pointer; transition:opacity .2s ease; }
.graph-node circle { fill:#3451b2; transition:transform .2s ease; transform-box:fill-box; transform-origin:center; }
html.dark .graph-node circle { fill:#a8b1ff; }
.graph-node text { fill:var(--vp-c-text-1); font-size:11px; pointer-events:none; }
.graph-node:hover circle { transform:scale(1.3); }
/* hover 高亮：非邻居淡化（class 切换——轻量） */
.graph-dim { opacity:.15; }

/* 抽屉固定入口已移除：Graph view 现作为虚拟条目直接进文章树（见 $frontMenuMd 拼接） */

#toast.show { opacity:1; }
</style>
</head>
<body>

<!-- 主界面 -->
<div id="app">
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
            <!-- 主页：渲染站点设置中配置的首页文章正文（无配置时显示空状态提示） -->
            <div class="archive-view" id="archive-view">
                <h1 class="doc-title" id="home-title" style="display:none"></h1>
                <div class="md" id="home-md" style="display:none"></div>
            </div>
            <div class="doc-wrap" id="doc-wrap">
                <div class="doc-main">
                    <h1 class="doc-title" id="doc-title"></h1>
                    <div class="md" id="md-view" style="display:none"></div>
                    <!-- PDF 阅读器（pdf.js 渲染，翻页/缩放/夜间反转） -->
                    <div id="pdf-view" style="display:none"></div>
                    <!-- Excalidraw 绘画渲染（.excalidraw.md：lz-string 解码 compressed-json → SVG） -->
                    <div id="excalidraw-view" style="display:none"><div class="excalidraw-canvas" id="excalidraw-canvas"></div></div>
                    <!-- 反向链接（被谁引用） -->
                    <div id="backlinks"></div>
                </div>
                <div class="toc-panel" id="toc-panel">
                    <div class="toc-title">Contents</div>
                    <div id="toc-list"></div>
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
        <!-- AI 对话面板（从顶部栏下方展开，问知识库 /api/ask） -->
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
    </div>
</div>

<div id="toast"></div>

<script src="/assets/marked.min.js"></script>
<script src="/assets/purify.min.js"></script>
<script src="/assets/lunr.min.js"></script>
<script>
// 前台侧滑菜单：PHP 扫描生成的文章目录树（md 嵌套列表，内联零请求）
var FRONT_MENU_MD = <?php echo json_encode($frontMenuMd); ?>;
// 前台目录树默认展开状态（后台偏好设置控制）
var FRONT_DRAWER_EXPANDED = <?php echo $frontDrawerExpanded ? 'true' : 'false'; ?>;
// 强制展开目录（后台目录管理设置，优先级高于默认展开开关）
var FRONT_EXPANDED_DIRS = <?php echo json_encode($config['expanded_dirs'] ?? []); ?>;

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
// Excalidraw 绘画直达（.excalidraw.md）：内联原文——前端 lz-string 解码 compressed-json → SVG 渲染
var SSR_EXCALIDRAW = <?php echo $ssrExcalidrawPath !== '' ? 'true' : 'false'; ?>;
var EXCALIDRAW_PATH = <?php echo $ssrExcalidrawPath !== '' ? json_encode($ssrExcalidrawPath) : '""'; ?>;
var EXCALIDRAW_RAW = <?php echo $ssrExcalidrawPath !== '' ? json_encode($ssrExcalidrawContent) : '""'; ?>;
// 首页大标题（从文章第一个标题提取）
var HOME_TITLE = <?php echo json_encode($homeTitle); ?>;
</script>
<script>
(function () {
    'use strict';
    var state = { authed: false, csrf: null, path: null, dir: null, mode: 'view', tree: [] };
    var $ = function (id) { return document.getElementById(id); };

    /* ---------- 基础 ---------- */
    function toast(msg) {
        var el = $('toast');
        el.textContent = msg;
        el.classList.add('show');
        clearTimeout(el._t);
        el._t = setTimeout(function () { el.classList.remove('show'); }, 2200);
    }

    async function api(url, opts) {
        opts = opts || {};
        opts.headers = Object.assign({}, opts.headers || {});
        if (opts.body && typeof opts.body === 'object') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        if (state.csrf && opts.method && opts.method !== 'GET') {
            opts.headers['X-CSRF-Token'] = state.csrf;
        }
        var res = await fetch(url, opts);
        var data = null;
        try { data = await res.json(); } catch (e) { /* ignore */ }
        if (!res.ok) {
            throw new Error(data && data.error ? data.error : ('Request failed' + ' ' + res.status));
        }
        if (data && data.csrf) state.csrf = data.csrf;
        return data;
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    /* ---------- Obsidian 语法保护：marked 渲染前占位，渲染后还原 ---------- */
    // marked 会把 ![[xxx.png]] 里的 [[xxx]] 误判为链接（尤其 www 开头），先替换成占位符
    function protectObsidian(text) {
        return text
            .replace(/!\[\[([^\]]+)\]\]/g, function (m, inner) {
                return '%%OBS_EMBED%%' + btoa(unescape(encodeURIComponent(inner))) + '%%END%%';
            })
            .replace(/\[\[([^\]]+)\]\]/g, function (m, inner) {
                return '%%OBS_LINK%%' + btoa(unescape(encodeURIComponent(inner))) + '%%END%%';
            });
    }
    function restoreObsidian(html) {
        return html
            .replace(/%%OBS_EMBED%%([A-Za-z0-9+/=]+)%%END%%/g, function (m, b64) {
                return '![[' + decodeURIComponent(escape(atob(b64))) + ']]';
            })
            .replace(/%%OBS_LINK%%([A-Za-z0-9+/=]+)%%END%%/g, function (m, b64) {
                return '[[' + decodeURIComponent(escape(atob(b64))) + ']]';
            });
    }

    /* ---------- 界面切换 ---------- */
    // 首页：渲染站点设置配置的首页文章正文（复用文章渲染管线；幂等——只渲染一次，避免二次渲染重排抽搐）
    var homeRendered = false;
    function renderHome() {
        if (homeRendered) return;
        homeRendered = true;
        var md = $('home-md');
        var title = $('home-title');
        var empty = $('empty-state');
        if (!window.HOME_MD) {
            md.style.display = 'none';
            title.style.display = 'none';
            empty.textContent = 'No home article configured. Set it in Admin → Site Settings.';
            empty.style.display = '';
            return;
        }
        try {
            var html = DOMPurify.sanitize(restoreObsidian(marked.parse(protectObsidian(window.HOME_MD), { gfm: true, breaks: true })));
            md.innerHTML = html;
            try {
                md.querySelectorAll('pre code').forEach(function (el) { hljs.highlightElement(el); });
            } catch (e) {}
            // 正文不允许 H1：降级为 h2（H1 全页唯一）
            var allH1 = md.querySelectorAll('h1');
            allH1.forEach(function (el) {
                var h2 = document.createElement('h2');
                h2.innerHTML = el.innerHTML;
                el.replaceWith(h2);
            });
            // 大标题：文章第一个标题（PHP 提取），无则隐藏
            if (window.HOME_TITLE) {
                title.textContent = window.HOME_TITLE;
                title.style.display = '';
            } else {
                title.style.display = 'none';
            }
            md.style.display = '';
            empty.style.display = 'none';
        } catch (e) {
            md.style.display = 'none';
            title.style.display = 'none';
            empty.textContent = 'Home article failed to render.';
            empty.style.display = '';
        }
    }
    function enterApp() {
        $('app').classList.add('show');
        // 服务端渲染直达（URL 直接访问 /xxx.md）：内联内容同步渲染显示（无 fetch 等待，打开即文章）
        if (window.SSR_MD) {
            renderHome(); // 预渲染主页（隐藏状态）：SSR 直达时主页默认未渲染，logo 回首页需立即可用
            state.path = window.SSR_PATH;
            state.dir = window.SSR_PATH.indexOf('/') > -1 ? window.SSR_PATH.substring(0, window.SSR_PATH.lastIndexOf('/')) : '';
            state.mode = 'view';
            var shtml = DOMPurify.sanitize(restoreObsidian(marked.parse(protectObsidian(window.SSR_MD), { gfm: true, breaks: true })));
            showArticle(shtml, window.SSR_PATH);
            loadTree();
            return;
        }
        // 服务端渲染直达（URL 直接访问 /xxx.pdf）：内联路径，前端 pdf.js 渲染阅读器
        if (window.SSR_PDF) {
            renderHome(); // 预渲染主页（隐藏状态）
            state.path = window.SSR_PDF;
            state.dir = window.SSR_PDF.indexOf('/') > -1 ? window.SSR_PDF.substring(0, window.SSR_PDF.lastIndexOf('/')) : '';
            state.mode = 'view';
            openPdf(window.SSR_PDF);
            loadTree();
            return;
        }
        // 默认显示首页（渲染首页文章正文）
        $('doc-wrap').style.display = 'none';
        $('archive-view').style.display = '';
        $('empty-state').style.display = 'none';
        // 带 hash（直达文章）：完整路径立即加载（selectFile 不依赖树，避免黑屏等待 loadTree）；
        // 数字 ID / 纯文件名依赖 _docMap（loadTree 构建），保持隐藏等 loadTree 后 handleHash 处理
        var h = '';
        try { h = decodeURI(location.hash.replace(/^#/, '')); } catch (e) {}
        if (h) {
            if (h.indexOf('/') > -1 || /\.md$/i.test(h)) {
                selectFile({ path: h });
            } else {
                $('archive-view').style.display = 'none';
                $('doc-wrap').style.display = 'none';
            }
        } else {
            // 主页：先隐藏内容区，渲染完成后再显示（避免渲染/加载过程的跳动）
            $('archive-view').style.display = 'none';
            renderHome();
            $('archive-view').style.display = '';
        }
        loadTree();
    }

    /* ---------- 文件树 ---------- */
    async function loadTree() {
        try {
            var data = await api('/api/list');
            state.tree = data.tree;
            // 主页已由 PHP 服务端渲染最新文章列表，树只用于搜索索引 + hash 直达
            // 文件树就绪后，处理 URL hash（数字 ID、完整路径或纯文件名直达）
            var h = location.hash.replace(/^#/, '');
            if (h && !state.path) {
                try {
                    var path = null;
                    if (/^\d+$/.test(h) && window._docMap && window._docMap[h]) {
                        path = window._docMap[h];
                    } else {
                        path = decodeURI(h);
                        // 兜底：纯文件名（无路径前缀）时查 _docMap 显示名映射
                        if (path.indexOf('/') === -1 && window._docMap && window._docMap[path]) {
                            path = window._docMap[path];
                        }
                    }
                    if (path) selectFile({ path: path });
                } catch (e) {}
            }
        } catch (e) {
            toast(e.message);
        }
    }

    function renderTree() {
        // 文章归档：兼容两种树结构（local: posts/ 下有子目录；minio: 桶根直接是子目录）
        var notesDir = null;
        state.tree.forEach(function (n) {
            if (n.type === 'dir' && n.name === 'posts') notesDir = n;
        });
        var root = $('archive-list');
        if (!root) return;
        root.innerHTML = '';
        // minio/散文件模式：桶根没有 posts，直接用整个树（目录 + 根散文件都收）
        var subs = [];
        if (notesDir) {
            subs = notesDir.children || [];
        } else {
            state.tree.forEach(function (n) {
                // 目录收进组；根目录的散文件也收（作为单文件组显示）
                subs.push(n);
            });
        }
        if (!subs.length) {
            root.innerHTML = '<div class="empty-state" style="margin-top:40px">' + 'No notes yet' + '</div>';
            return;
        }
        // 递归收集所有 .md 文件（任意目录层级，检测文件类型而非按层扫描）
        var allFiles = [];
        window._docMap = window._docMap || {};
        (function collect(nodes) {
            nodes.forEach(function (node) {
                if (node.type === 'dir') {
                    collect(node.children || []);
                } else if (node.type === 'file' && /\.md$/i.test(node.name)) {
                    var id = null;
                    var m = node.name.match(/^(\d+)-/);
                    if (m) id = m[1];
                    if (id) window._docMap[id] = node.path;
                    // 显示名（去 ID 前缀、去 .md）也映射，双链 [[名]] 可匹配无 ID 文章
                    var base = node.name.replace(/^\d+-/, '').replace(/\.md$/i, '');
                    window._docMap[base] = node.path;
                    allFiles.push({ path: node.path, name: node.name, id: id });
                }
            });
        })(subs);
        // 垂直有序列表：左侧序号（=ID）+ 右侧文本（去 ID 前缀）
        allFiles.forEach(function (f, i) {
            var el = document.createElement('div');
            el.className = 'archive-list-item';
            el.dataset.path = f.path;
            var idx = document.createElement('span');
            idx.className = 'archive-index';
            idx.textContent = f.id || (i + 1);
            var title = document.createElement('span');
            title.className = 'archive-title';
            title.textContent = f.name.replace(/^\d+-/, '').replace(/\.md$/, '');
            el.appendChild(idx);
            el.appendChild(title);
            el.addEventListener('click', function (e) {
                e.stopPropagation();
                selectFile({ path: f.path });
            });
            root.appendChild(el);
        });
    }

    function highlightActive(path) {
        // 侧边栏已删除，无高亮目标
    }

    /* ---------- 文件操作 ---------- */
    // 生成文章目录（TOC）：扫描 md-view 里的 h1-h3，点击平滑滚动
    function renderToc() {
        var list = $('toc-list');
        if (!list) return;
        var headings = $('md-view').querySelectorAll('h1, h2, h3, h4');
        if (!headings.length) {
            $('toc-panel').style.display = 'none';
            return;
        }
        $('toc-panel').style.display = '';
        list.innerHTML = '';
        headings.forEach(function (h) {
            var lv = parseInt(h.tagName.charAt(1), 10);
            var text = h.textContent.trim();
            if (!text) return;
            var link = document.createElement('button');
            link.className = 'toc-link lv-' + lv;
            link.textContent = text;
            link.addEventListener('click', function () {
                var top = h.offsetTop - $('content').offsetTop;
                $('content').scrollTo({ top: top - 90, behavior: 'smooth' });
            });
            list.appendChild(link);
        });
    }

    // PDF 阅读器（pdf.js）：翻页/缩放/夜间反转；文件从前端 /vault/ 路径加载（无需下载）
    var pdfDoc = null, pdfPageNum = 1, pdfScale = 1;
    // 像素缓存（主题切换用）：原图 + 夜间处理副本——切换只做 putImageData（快、无遍历、无中间帧闪屏）
    var pdfImgOrig = null, pdfImgDark = null;
    function loadPdfJs(cb) {
        if (window.pdfjsLib) { cb(); return; }
        var s = document.createElement('script');
        s.src = '/assets/pdfjs/pdf.min.js';
        s.onload = cb;
        document.head.appendChild(s);
    }
    // 适配宽度（默认铺满内容区 = 与 md 文章内容同宽）：直接测量 doc-wrap 实际宽度，
    // 兜底用窗口宽度减 padding（移动 18×2 / 桌面 48×2）。fit 不做范围限制（限制只属于手动缩放）
    function fitScale(pageWidth) {
        var pv = document.getElementById('pdf-view');
        var isFs = document.fullscreenElement || (pv && pv.classList.contains('fs'));
        var avail;
        if (isFs) {
            avail = window.innerWidth; // 全屏阅读：铺满整个浏览器宽度
        } else {
            var dm = document.querySelector('.doc-wrap');
            avail = (dm && dm.clientWidth > 100) ? dm.clientWidth : (window.innerWidth - (window.innerWidth <= 768 ? 36 : 96));
        }
        var s = avail / pageWidth;
        return Math.max(0.05, s); // 下限 0.05 仅防除零/极小
    }
    // 全屏后重新适配宽度（进入/退出都调用）
    function pdfRefit() {
        if (!pdfDoc) return;
        pdfDoc.getPage(pdfPageNum).then(function (page) {
            pdfScale = fitScale(page.getViewport({ scale: 1 }).width);
            renderPdfPage();
        });
    }
    function renderPdfPage() {
        if (!pdfDoc) return;
        pdfDoc.getPage(pdfPageNum).then(function (page) {
            // 超采样渲染：屏幕密度（DPR）× 1.5 系数——以更高分辨率绘制再缩小显示，抗锯齿更优、文字更锐利。
            // 代价：像素增多（渲染/内存略增），画质优先
            var dpr = (window.devicePixelRatio || 1) * 1.5;
            var viewport = page.getViewport({ scale: pdfScale * dpr });
            var canvas = $('pdf-canvas');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            canvas.style.width = Math.round(viewport.width / dpr) + 'px';
            canvas.style.height = Math.round(viewport.height / dpr) + 'px';
            page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport, background: 'transparent' }).promise.then(function () {
                $('pdf-pageinfo').textContent = pdfPageNum + ' / ' + pdfDoc.numPages;
                $('pdf-zoominfo').textContent = Math.round(pdfScale * 100) + '%';
                // 缓存原图 + 生成夜间处理副本（白→透明）；后续主题切换只 putImageData，不遍历、不闪
                pdfImgOrig = null; pdfImgDark = null;
                try { pdfImgOrig = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height); } catch (e) {}
                if (document.documentElement.classList.contains('dark')) {
                    pdfImgDark = makePdfDarkCopy(pdfImgOrig);
                    if (pdfImgDark) { try { canvas.getContext('2d').putImageData(pdfImgDark, 0, 0); } catch (e) {} }
                }
            });
        });
    }
    // 生成夜间处理副本：接近白色的像素 → 透明（配合 CSS invert：深色文字变白、图片负片）
    function makePdfDarkCopy(img) {
        if (!img) return null;
        try {
            var d = new Uint8ClampedArray(img.data);
            for (var i = 0; i < d.length; i += 4) {
                if (d[i] > 235 && d[i + 1] > 235 && d[i + 2] > 235) {
                    d[i + 3] = 0;
                }
            }
            return new ImageData(d, img.width, img.height);
        } catch (e) { return null; }
    }
    function openPdf(path) {
        $('archive-view').style.display = 'none';
        $('empty-state').style.display = 'none';
        $('doc-wrap').style.display = 'flex';
        $('md-view').style.display = 'none';
        // 标题：文件名（去 ID 前缀与 .pdf 扩展名）
        var docName = path.split('/').pop().replace(/^\d+-/, '').replace(/\.pdf$/i, '');
        $('doc-title').textContent = docName;
        try { document.title = docName + ' · ' + (window.SITE_TITLE || 'MD2HTML'); } catch (e) {}
        var pv = $('pdf-view');
        pv.style.display = '';
        pv.innerHTML = '';
        var bar = document.createElement('div');
        bar.className = 'pdf-toolbar';
        bar.innerHTML = [
            '<button type="button" class="pdf-btn" id="pdf-prev" title="Previous page">‹</button>',
            '<span class="pdf-info" id="pdf-pageinfo">– / –</span>',
            '<button type="button" class="pdf-btn" id="pdf-next" title="Next page">›</button>',
            '<span class="pdf-sep"></span>',
            '<button type="button" class="pdf-btn" id="pdf-zoom-out" title="Zoom out">−</button>',
            '<span class="pdf-info" id="pdf-zoominfo">fit</span>',
            '<button type="button" class="pdf-btn" id="pdf-zoom-in" title="Zoom in">+</button>',
            '<span class="pdf-sep"></span>',
            '<button type="button" class="pdf-btn" id="pdf-fullscreen" title="Fullscreen">⛶</button>'
        ].join('');
        pv.appendChild(bar);
        var cw = document.createElement('div');
        cw.className = 'pdf-canvas-wrap';
        var canvas = document.createElement('canvas');
        canvas.id = 'pdf-canvas';
        cw.appendChild(canvas);
        pv.appendChild(cw);
        // 缩放步进：相对 20%（0.3 ~ 3 倍），点击重渲染新分辨率（像素级清晰）
        var zoomBtn = function (btn, factor) {
            btn.addEventListener('click', function () {
                var next = Math.round(pdfScale * factor * 10) / 10;
                if (next >= 0.3 && next <= 3) { pdfScale = next; renderPdfPage(); }
            });
        };
        $('pdf-prev').addEventListener('click', function () { if (pdfPageNum > 1) { pdfPageNum--; renderPdfPage(); } });
        $('pdf-next').addEventListener('click', function () { if (pdfDoc && pdfPageNum < pdfDoc.numPages) { pdfPageNum++; renderPdfPage(); } });
        zoomBtn($('pdf-zoom-in'), 1.2);
        zoomBtn($('pdf-zoom-out'), 1 / 1.2);
        // 全屏阅读：优先浏览器原生全屏（桌面），手机/iOS 无 Fullscreen API 时用 CSS 模拟（fixed 铺满）
        $('pdf-fullscreen').addEventListener('click', function () {
            var pv = document.getElementById('pdf-view');
            if (document.fullscreenElement || pv.classList.contains('fs')) {
                // 退出全屏
                if (document.exitFullscreen) document.exitFullscreen();
                else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
                pv.classList.remove('fs');
                pdfRefit();
            } else {
                // 进入全屏：优先原生
                var fn = pv.requestFullscreen || pv.webkitRequestFullscreen;
                if (fn) {
                    try { fn.call(pv); } catch (e) { pv.classList.add('fs'); pdfRefit(); }
                } else {
                    // 手机/WebView：CSS 模拟全屏
                    pv.classList.add('fs');
                    pdfRefit();
                }
            }
        });
        // 原生全屏状态变化（桌面进入/退出 Esc）：重新适配宽度
        document.addEventListener('fullscreenchange', function () {
            if (pdfDoc) pdfRefit();
        });
        // 键盘翻页（阅读器内）
        var keyHandler = function (e) {
            if (e.key === 'ArrowLeft') { if (pdfPageNum > 1) { pdfPageNum--; renderPdfPage(); } }
            else if (e.key === 'ArrowRight') { if (pdfDoc && pdfPageNum < pdfDoc.numPages) { pdfPageNum++; renderPdfPage(); } }
        };
        document.addEventListener('keydown', keyHandler);
        // 加载 PDF（按需加载 pdf.min.js，不拖慢首页）
        loadPdfJs(function () {
            pdfjsLib.GlobalWorkerOptions.workerSrc = '/assets/pdfjs/pdf.worker.min.js';
            pdfjsLib.getDocument('/vault/' + path).promise.then(function (doc) {
                pdfDoc = doc;
                pdfPageNum = 1;
                $('pdf-pageinfo').textContent = '1 / ' + doc.numPages;
                // 初始适配宽度：铺满内容区（按窗口宽度计算）
                pdfDoc.getPage(1).then(function (page) {
                    pdfScale = fitScale(page.getViewport({ scale: 1 }).width);
                    renderPdfPage();
                });
            }).catch(function (err) {
                pv.innerHTML = '<div style="padding:48px;text-align:center;color:var(--vp-c-text-mute);">Failed to load PDF: ' + ((err && err.message) || 'unknown error') + '</div>';
            });
        });
    }

    // 文章渲染统一出口：内联 HTML → 高亮/降级/标题/视图切换/增强（selectFile 与 SSR 共用）
    function showArticle(html, nodePath) {
        $('md-view').innerHTML = html;
        // 代码高亮（VS Code 风格：日间 Light+ / 夜间 Dark+）
        try {
            $('md-view').querySelectorAll('pre code').forEach(function (el) {
                hljs.highlightElement(el);
            });
        } catch (e) {}
        // 正文不允许出现 H1：所有 h1 降级为 h2（大标题用文件名显示，H1 全页唯一）
        $('md-view').querySelectorAll('h1').forEach(function (el) {
            var h2 = document.createElement('h2');
            h2.innerHTML = el.innerHTML;
            el.parentNode.replaceChild(h2, el);
        });
        // 文档标题 = 文件名（去掉 ID 前缀与扩展名），清除旧翻译标记
        var docName = nodePath.split('/').pop().replace(/^\d+-/, '').replace(/\.md$/i, '');
        $('doc-title').textContent = docName;
        delete $('doc-title').dataset.orig;
        // 视图切换：内容就绪后一次性显示
        $('archive-view').style.display = 'none';
        $('empty-state').style.display = 'none';
        $('doc-wrap').style.display = 'flex';
        $('md-view').style.display = '';
        $('pdf-view').style.display = 'none';
        $('excalidraw-view').style.display = 'none';  // 切换文章时隐藏 Excalidraw 画布（否则上次的绘图残留文章底部）
        $('graph-view').style.display = 'none';
        $('content').scrollTop = 0;
        renderToc();
        addCodeCopy();
        processObsidian();
        highlightActive(nodePath);
    }

    async function selectFile(node) {
        state.path = node.path;
        state.dir = node.path.indexOf('/') > -1 ? node.path.substring(0, node.path.lastIndexOf('/')) : '';
        state.mode = 'view';
        // PDF 文件：走阅读器（pdf.js），不走 md 渲染管线
        if (/\.pdf$/i.test(node.path)) {
            openPdf(node.path);
            return;
        }
        // Excalidraw 绘画：lz-string 解码 compressed-json → SVG 渲染
        if (/\.excalidraw\.md$/i.test(node.path)) {
            openExcalidraw(node.path);
            return;
        }
        try {
            // 先请求内容并渲染好，再一次性切换视图（避免空白闪烁）
            var data = await api('/api/file?path=' + encodeURIComponent(node.path));
            if (!data || !data.ok) {
                toast((data && data.error) || 'Failed to read file');
                return;
            }
            var html = DOMPurify.sanitize(restoreObsidian(marked.parse(protectObsidian(data.content), { gfm: true, breaks: true })));
            showArticle(html, node.path);
            // 更新地址栏：SPA 点击已 pushState 路径 URL 时不重复设 hash（避免 /path#path 冗余）
            try {
                if (!/\.md$/i.test(location.pathname)) {
                    var docId = null;
                    for (var k in (window._docMap || {})) {
                        if (window._docMap[k] === node.path) { docId = k; break; }
                    }
                    location.hash = docId || encodeURI(node.path);
                }
            } catch (e) {}
        } catch (e) {
            toast(e.message);
        }
    }

    // 给代码块加复制按钮
    function addCodeCopy() {
        $('md-view').querySelectorAll('pre').forEach(function (pre) {
            if (pre.querySelector('.code-copy')) return;
            var btn = document.createElement('button');
            btn.className = 'code-copy';
            btn.title = 'Copy code';
            btn.setAttribute('aria-label', 'Copy code');
            // 单行代码块：按钮垂直居中（避免偏下不对称）
            if (pre.scrollHeight <= 60) btn.classList.add('single-line');
            btn.addEventListener('click', function () {
                var code = pre.querySelector('code');
                var text = code ? code.innerText : pre.innerText;
                var done = function () {
                    btn.classList.add('copied');
                    btn.title = 'Copied';
                    setTimeout(function () {
                        btn.classList.remove('copied');
                        btn.title = 'Copy code';
                    }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done).catch(function () {
                        fallbackCopy(text);
                        done();
                    });
                } else {
                    fallbackCopy(text);
                    done();
                }
            });
            pre.appendChild(btn);
        });
    }
    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }

    /* ===== Obsidian 兼容：双链 / 嵌入 / 标签 ===== */
    // 按名字或 ID 查笔记路径
    function findNote(name) {
        var map = window._docMap || {};
        var target = name.replace(/\.md$/i, '');
        // 1. ID 直接匹配
        if (map[target]) return map[target];
        // 2. 按显示名匹配（去 ID 前缀、去 .md）
        for (var k in map) {
            var p = map[k];
            var base = p.split('/').pop().replace(/^\d+-/, '').replace(/\.md$/i, '');
            if (base === target) return p;
        }
        return null;
    }
    function processObsidian() {
        var root = $('md-view');
        if (!root) return;
        // 遍历文本节点，处理 [[双链]]、![[嵌入]]、#标签
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        var nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach(function (textNode) {
            var text = textNode.nodeValue;
            if (!text) return;
            var out = [];
            var last = 0;
            var re = /(!?)\[\[([^\[\]]+)\]\]|(^|\s)#([\u4e00-\u9fa5\w-]+)/g;
            var m;
            while ((m = re.exec(text)) !== null) {
                if (m.index > last) out.push(text.slice(last, m.index));
                if (m[1] === '!') {
                    // 嵌入 ![[名字]]
                    var parts = m[2].split('|');
                    out.push({ embed: parts[0].trim() });
                } else if (m[2]) {
                    // 双链 [[名字]] 或 [[名字|别名]]
                    var parts2 = m[2].split('|');
                    out.push({ link: parts2[0].trim(), alias: parts2[1] ? parts2[1].trim() : null });
                } else if (m[4]) {
                    // 标签 #标签
                    out.push({ tag: m[4], leading: m[3] });
                }
                last = m.index + m[0].length;
            }
            if (!out.length) return;
            if (last < text.length) out.push(text.slice(last));
            // 重建节点
            var frag = document.createDocumentFragment();
            out.forEach(function (item) {
                if (typeof item === 'string') {
                    frag.appendChild(document.createTextNode(item));
                } else if (item.link) {
                    var a = document.createElement('a');
                    a.className = 'ob-link';
                    var target = findNote(item.link);
                    a.textContent = item.alias || item.link;
                    if (target) {
                        // 用完整路径做 hash（encodeURI 保留斜杠），无 ID 文章也能直达
                        a.href = '#' + encodeURI(target);
                        a.title = target;
                        a.dataset.path = target;
                    } else {
                        a.classList.add('ob-link-missing');
                        a.title = 'Note not found: ' + item.link;
                    }
                    frag.appendChild(a);
                } else if (item.embed) {
                    // 图片嵌入 ![[xxx.png]] → <img>；否则按笔记嵌入
                    var embedName = item.embed;
                    if (/\.(png|jpe?g|gif|webp|svg|bmp|avif)$/i.test(embedName)) {
                        var img = document.createElement('img');
                        img.className = 'ob-embed-img';
                        // 从文件树找图片实际路径（可能在子目录）；找不到回退 vault/ 根
                        var imgPath = null;
                        (function walk(nodes) {
                            nodes.forEach(function (n) {
                                if (imgPath) return;
                                if (n.type === 'file' && n.name === embedName) imgPath = n.path;
                                else if (n.children) walk(n.children);
                            });
                        })(state.tree);
                        img.src = '/vault/' + (imgPath ? encodeURI(imgPath) : encodeURI(embedName));
                        img.alt = embedName;
                        img.loading = 'lazy';
                        frag.appendChild(img);
                    } else {
                        var span = document.createElement('span');
                        span.className = 'ob-embed';
                        span.dataset.name = embedName;
                        span.textContent = 'Loading embed: ' + embedName + '...';
                        frag.appendChild(span);
                        loadEmbed(span, embedName);
                    }
                } else if (item.tag) {
                    var t = document.createElement('a');
                    t.className = 'ob-tag';
                    t.textContent = '#' + item.tag;
                    t.dataset.tag = item.tag;
                    t.href = '#tag=' + item.tag;
                    t.addEventListener('click', function (e) {
                        e.preventDefault();
                        // 打开搜索并填入标签
                        openSearch();
                        searchInput.value = '#' + item.tag;
                        renderSearch('#' + item.tag);
                    });
                    frag.appendChild(document.createTextNode(item.leading || ''));
                    frag.appendChild(t);
                }
            });
            textNode.parentNode.replaceChild(frag, textNode);
        });
        renderBacklinks();
    }
    // 获取笔记的 ID
    function getDocId(path) {
        for (var k in (window._docMap || {})) {
            if (window._docMap[k] === path) return k;
        }
        return '';
    }
    // 加载嵌入内容
    function loadEmbed(el, name) {
        var path = findNote(name);
        if (!path) {
            el.textContent = 'Embed failed: note not found: ' + name;
            el.classList.add('ob-embed-missing');
            return;
        }
        api('/api/file?path=' + encodeURIComponent(path)).then(function (data) {
            var html = DOMPurify.sanitize(restoreObsidian(marked.parse(protectObsidian(data.content), { gfm: true, breaks: true })));
            // 去掉第一个 h1
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            var h1 = tmp.querySelector('h1');
            if (h1) h1.remove();
            // 嵌入内容代码高亮
            try {
                tmp.querySelectorAll('pre code').forEach(function (el) {
                    hljs.highlightElement(el);
                });
            } catch (e) {}
            el.innerHTML = '';
            var inner = document.createElement('div');
            inner.className = 'ob-embed-inner';
            inner.innerHTML = tmp.innerHTML;
            el.appendChild(inner);
            el.classList.add('loaded');
        }).catch(function () {
            el.textContent = 'Embed failed: ' + name;
            el.classList.add('ob-embed-missing');
        });
    }
    // 渲染反向链接（被谁引用）
    function renderBacklinks() {
        var wrap = $('backlinks');
        if (!wrap) return;
        wrap.innerHTML = '';
        wrap.style.display = 'none';   // 构建期间隐藏，避免插入时触发颜色过渡
        if (!state.path) return;
        var currentName = state.path.split('/').pop().replace(/^\d+-/, '').replace(/\.md$/i, '');
        var currentId = getDocId(state.path);
        var refs = [];
        var map = window._docMap || {};
        for (var k in map) {
            var p = map[k];
            if (p === state.path) continue;
            var base = p.split('/').pop().replace(/^\d+-/, '').replace(/\.md$/i, '');
            // 简化：加载每篇笔记内容检查是否引用当前笔记（异步，笔记少可接受）
            refs.push({ path: p, name: base, id: k });
        }
        // 逐个检查引用，并记录引用上下文片段
        var checked = 0;
        var found = [];
        refs.forEach(function (ref) {
            api('/api/file?path=' + encodeURIComponent(ref.path)).then(function (data) {
                var c = data.content || '';
                var idx = c.indexOf('[[' + currentName + ']]');
                if (idx === -1) idx = c.indexOf('[[' + currentId + ']]');
                if (idx > -1) {
                    ref.excerpt = c.slice(Math.max(0, idx - 30), idx + 30 + currentName.length + 4).replace(/\n/g, ' ').trim();
                    found.push(ref);
                }
                checked++;
                if (checked >= refs.length) showBacklinks(found, wrap);
            }).catch(function () {
                checked++;
                if (checked >= refs.length) showBacklinks(found, wrap);
            });
        });
        if (!refs.length) showBacklinks(found, wrap);
    }
    function showBacklinks(found, wrap) {
        if (!found.length) {
            wrap.style.display = '';
            return;
        }
        // 头部：标题
        var head = document.createElement('div');
        head.className = 'backlinks-head';
        var title = document.createElement('span');
        title.className = 'backlinks-title';
        title.textContent = 'Backlinks';
        head.appendChild(title);
        wrap.appendChild(head);
        // 列表：每条 = 笔记名 + 引用上下文
        var list = document.createElement('div');
        list.className = 'backlinks-list';
        found.forEach(function (ref) {
            var a = document.createElement('a');
            a.className = 'backlinks-item';
            // 用完整路径做 hash（encodeURI 保留斜杠），无 ID 文章也能直达
            a.href = '#' + encodeURI(ref.path);
            a.dataset.path = ref.path;
            var name = document.createElement('span');
            name.className = 'bl-name';
            name.textContent = ref.name;
            a.appendChild(name);
            if (ref.excerpt) {
                var ex = document.createElement('span');
                ex.className = 'bl-excerpt';
                ex.textContent = ref.excerpt;
                a.appendChild(ex);
            }
            list.appendChild(a);
        });
        wrap.appendChild(list);
        // 内容构建完成后再显示（避免插入时颜色过渡闪烁）
        wrap.style.display = '';
    }

    /* ---------- 搜索功能（见下方） ---------- */
    // （编辑/余额/上传功能已随二层顶部栏移除）

    /* ---------- 滚动时隐藏/显示第一层导航栏 ---------- */
    // 方向判断：向下滚动隐藏、向上滚动显示
    // 切换后重置 lastY（padding 变化会改变 scrollHeight/scrollTop，必须校准基准）
    var navWrap = $('nav-wrap');
    var mainEl = $('main');
    var contentEl = $('content');
    var lastY = 0;
    var topHidden = false;
    function setTopHidden(hidden) {
        if (hidden === topHidden) return;
        topHidden = hidden;
        navWrap.classList.toggle('hidden', hidden);
        mainEl.classList.toggle('hide-top', hidden);
        lastY = contentEl.scrollTop;   // 校准基准，防止突变干扰方向判断
    }
    contentEl.addEventListener('scroll', function () {
        var y = contentEl.scrollTop;
        var delta = y - lastY;
        var maxY = contentEl.scrollHeight - contentEl.clientHeight;
        // 顶部兜底：scrollTop 接近 0 时强制显示一层（猛拽回顶部也能拉回来）
        if (y < 10) {
            setTopHidden(false);
            lastY = y;
            return;
        }
        // 死区：滚动距离小于 6px 不判断（过滤微抖）
        if (Math.abs(delta) < 6) { lastY = y; return; }
        if (delta > 0) {
            // 向下滚：隐藏（贴底 60px 内不隐藏，避免贴底抖动）
            if (maxY - y < 60) { lastY = y; return; }
            setTopHidden(true);
        } else {
            // 向上滚：显示（不受底部限制）
            setTopHidden(false);
        }
        lastY = y;
    });

    /* ---------- 顶部导航 ---------- */
    // logo 点击：回到文章归档页
    $('vp-logo').addEventListener('click', function () {
        $('md-view').style.display = 'none';
        $('doc-wrap').style.display = 'none';
        $('archive-view').style.display = '';
        $('toc-panel').style.display = 'none';
        $('empty-state').style.display = 'none';
        highlightActive(null);
        // 重置滚动位置（否则保留文章页的滚动位置，归档显示会错位）
        $('content').scrollTop = 0;
        // URL 归位：路径模式（/xxx.md）pushState 回 /；hash 模式清空 hash（产生历史记录，返回可回到文章）
        try {
            if (/\.md$/i.test(location.pathname)) {
                history.pushState(null, '', '/');
            } else if (location.hash) {
                location.hash = '';
            }
        } catch (e) {}
    });
    // 主题切换（日间/夜间，localStorage 记忆）
    function applyTheme(dark) {
        // PDF 阅读器：主题切换用像素副本切换（先恢复/处理像素，再切 CSS 类——无中间帧、不闪屏）
        if (typeof pdfDoc !== 'undefined' && pdfDoc && document.getElementById('pdf-canvas')) {
            var pvCanvas = document.getElementById('pdf-canvas');
            var pvCtx = pvCanvas.getContext('2d');
            if (dark) {
                // 切夜：先换像素（白→透明，invert 未生效——视觉一致），再加 CSS（filter 平滑渐变，与背景同步）
                if (!pdfImgDark && pdfImgOrig) { pdfImgDark = makePdfDarkCopy(pdfImgOrig); }
                if (pdfImgDark) { try { pvCtx.putImageData(pdfImgDark, 0, 0); } catch (e) {} }
                document.documentElement.classList.add('dark');
            } else {
                // 切日：先移除 CSS（filter 平滑渐变），再恢复原图（invert 已无——视觉一致）
                document.documentElement.classList.remove('dark');
                if (pdfImgOrig) { try { pvCtx.putImageData(pdfImgOrig, 0, 0); } catch (e) {} }
            }
            try { localStorage.setItem('vp-theme', dark ? 'dark' : 'light'); document.cookie = 'vp-theme=' + (dark ? 'dark' : 'light') + '; path=/'; } catch (e) {}
            return;
        }
        document.documentElement.classList.toggle('dark', dark);
        try { localStorage.setItem('vp-theme', dark ? 'dark' : 'light'); document.cookie = 'vp-theme=' + (dark ? 'dark' : 'light') + '; path=/'; } catch (e) {}
    }
    try {
        var savedTheme = localStorage.getItem('vp-theme');
        if (savedTheme === 'dark') applyTheme(true);
    } catch (e) {}
    // Graph View：/graph 知识图谱（SVG 力导向，零依赖；?dir= 限定目录，节点点击打开文章）
    var graphView = $('graph-view');
    var graphInfo = $('graph-info');
    var graphSvg = $('graph-svg');
    var graphWrap = $('graph-canvas-wrap');
    var graphEmpty = $('graph-empty');
    var GNS = 'http://www.w3.org/2000/svg';
    function openGraph() {
        try { setDrawer(false); } catch (e) {}
        // 地址栏同步为 /graph（可分享/刷新保持图谱页）
        try { history.pushState(null, '', '/graph'); } catch (e) {}
        // Graph 是独立页面：隐藏文章/首页容器，图谱铺满内容区（不套文章格式）
        $('archive-view').style.display = 'none';
        $('doc-wrap').style.display = 'none';
        graphView.style.display = 'flex';
        if (window.console) console.log('GRAPH OPENED');
        loadGraph();
    }
    function loadGraph() {
        fetch('/api/graph').then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) return;
            try {
                renderGraph(d.nodes || [], d.links || []);
            } catch (err) {
                graphEmpty.textContent = 'Graph error: ' + (err && err.message ? err.message : err);
                graphEmpty.style.display = 'flex';
                if (window.console) console.error('graph:', err);
            }
        }).catch(function (err) {
            graphEmpty.textContent = 'Graph load error: ' + (err && err.message ? err.message : err);
            graphEmpty.style.display = 'flex';
        });
    }
    // Quartz 风格力导向图：目录着色 + 节点大小按度数 + hover 高亮邻居 + 缩放/平移/节点拖拽 + 同目录弱链接聚类
    var GRAPH_PALETTE = ['#3451b2', '#e05d44', '#2f9e44', '#e67700', '#7048e8', '#0b7285', '#c2255c', '#5f3dc4', '#099268', '#d6336c'];
    var GRAPH_DARK_PALETTE = ['#a8b1ff', '#ffa8a8', '#8ce99a', '#ffc078', '#b197fc', '#66d9e8', '#faa2c1', '#d0bfff', '#63e6be', '#ff8787'];
    var graphColors = {};
    var graphTransform = { x: 0, y: 0, k: 1 };
    var graphNodes = [], graphLinks = [];
    var graphSvgG = null;
    function renderGraph(nodes, links) {
        graphNodes = nodes; graphLinks = links;
        graphSvg.innerHTML = '';
        graphInfo.textContent = nodes.length + ' articles · ' + links.length + ' links';
        graphEmpty.style.display = nodes.length ? 'none' : 'flex';
        if (!nodes.length) return;
        // 画布尺寸（视口兜底）
        W = graphWrap.clientWidth || (window.innerWidth - 96);
        H = graphWrap.clientHeight || (window.innerHeight - 120);
        cx = W / 2; cy = H / 2;
        // 目录颜色分配
        var dirs = {};
        nodes.forEach(function (n) { if (n.dir) dirs[n.dir] = true; });
        var dirKeys = Object.keys(dirs);
        dirKeys.forEach(function (d, i) {
            graphColors[d] = document.documentElement.classList.contains('dark') ? GRAPH_DARK_PALETTE[i % GRAPH_DARK_PALETTE.length] : GRAPH_PALETTE[i % GRAPH_PALETTE.length];
        });
        // 度数（仅信息用）
        var degree = {};
        nodes.forEach(function (n) { degree[n.id] = 0; });
        links.forEach(function (l) { degree[l.source] = (degree[l.source] || 0) + 1; degree[l.target] = (degree[l.target] || 0) + 1; });
        // 初始位置：目录分区（同目录节点初始聚在同一扇区——布局成簇、避免对称死锁）
        var dirGroups = {};
        nodes.forEach(function (n) { (dirGroups[n.dir] = dirGroups[n.dir] || []).push(n); });
        var dKeys = Object.keys(dirGroups);
        var sector = 0;
        dKeys.forEach(function (d) {
            var arr = dirGroups[d];
            var ang = (sector / Math.max(1, dKeys.length)) * Math.PI * 2 + (Math.random() - 0.5) * 0.4;
            sector++;
            var rad = Math.min(W, H) * 0.38;
            var ccx = cx + Math.cos(ang) * rad, ccy = cy + Math.sin(ang) * rad;
            arr.forEach(function (n, i) {
                var a = (i / Math.max(1, arr.length)) * Math.PI * 2;
                var r = Math.min(110, Math.sqrt(arr.length) * 24);
                n.x = ccx + Math.cos(a) * r + (Math.random() - 0.5) * 30;
                n.y = ccy + Math.sin(a) * r + (Math.random() - 0.5) * 30;
                n.vx = 0; n.vy = 0; n.fixed = false;
            });
        });
        // 同目录弱吸引对（预构建——stepOnce 每帧用，聚类但不画线）
        clusterPairs = [];
        dKeys.forEach(function (d) {
            var arr = dirGroups[d];
            for (var i = 0; i < arr.length; i++) {
                for (var j = i + 1; j < arr.length; j++) {
                    clusterPairs.push([arr[i].id, arr[j].id]);
                }
            }
        });
        // 快速初排（30 轮同步，避免打开时长时间空白）
        for (var iter = 0; iter < 220; iter++) stepOnce();
        // 渲染 SVG（缓存元素引用——每帧直接更新，不 querySelectorAll）
        graphSvgG = document.createElementNS(GNS, 'g');
        graphSvg.appendChild(graphSvgG);
        simLineEls = [];
        linkAdj = {};
        links.forEach(function (l, li) {
            (linkAdj[l.source] = linkAdj[l.source] || []).push(li);
            (linkAdj[l.target] = linkAdj[l.target] || []).push(li);
            var a = nodes[l.source], b = nodes[l.target];
            if (!a || !b) return;
            var line = document.createElementNS(GNS, 'line');
            line.setAttribute('class', 'graph-link');
            line.setAttribute('data-s', l.source);
            line.setAttribute('data-t', l.target);
            line.setAttribute('x1', a.x); line.setAttribute('y1', a.y);
            line.setAttribute('x2', b.x); line.setAttribute('y2', b.y);
            graphSvgG.appendChild(line);
            simLineEls.push(line);
        });
        simEls = [];
        nodes.forEach(function (n) {
            var r = (5 + Math.min(1.2, (degree[n.id] || 0) * 0.25)) * 0.7;  // 整体缩小 30%：孤立 ≈3.5、有链接最多 ≈4.3
            n.r = r;
            var node = document.createElementNS(GNS, 'g');
            node.setAttribute('class', 'graph-node');
            node.setAttribute('data-id', n.id);
            var c = document.createElementNS(GNS, 'circle');
            c.setAttribute('r', r);
            c.setAttribute('fill', graphColors[n.dir] || '#888');
            node.appendChild(c);
            var t = document.createElementNS(GNS, 'text');
            t.setAttribute('text-anchor', 'middle');
            t.setAttribute('dy', r + 12);
            t.setAttribute('class', 'graph-label');
            t.setAttribute('opacity', window.GRAPH_SHOW_LABELS ? '1' : '0');  // 后台开关：默认显示 / hover 显示
            t.textContent = n.name;  // 完整名字（hover 展开不省略）
            node.appendChild(t);
            node.addEventListener('click', function (ev) {
                ev.stopPropagation();
                // 拖拽过的节点不跳转（拖拽后浏览器仍会触发 click）
                if (n._dragged) { n._dragged = false; return; }
                selectFile({ path: n.path });
            });
            // hover：显示标签 + 高亮邻居（class 切换——轻量）
            node.addEventListener('mouseenter', function () { t.setAttribute('opacity', '1'); highlightNode(n.id); });
            node.addEventListener('mouseleave', function () { t.setAttribute('opacity', '0'); highlightNode(-1); });
            // 节点拖拽：锁定位置 + 持续模拟（其他节点跟随）；移动超 5px 视为拖拽（抑制 click 跳转）
            node.addEventListener('pointerdown', function (ev) {
                ev.preventDefault(); ev.stopPropagation();
                var svgRect = graphSvg.getBoundingClientRect();
                var sx = ev.clientX, sy = ev.clientY;
                var movePending = false, lastX = ev.clientX, lastY = ev.clientY;
                n.fixed = true;
                n._dragged = false;
                stopSim();
                highlightNode(n.id);  // 拖拽聚焦：被拖节点+相连的线/节点高亮，其余淡化（与 hover 一致，手机也生效）
                function move(ev2) {
                    if (!n._dragged && (Math.abs(ev2.clientX - sx) + Math.abs(ev2.clientY - sy) > 5)) n._dragged = true;
                    lastX = ev2.clientX; lastY = ev2.clientY;
                    if (movePending) return;  // rAF 节流：多次 pointermove 合并到一帧一次（手机不卡）
                    movePending = true;
                    requestAnimationFrame(function () {
                        movePending = false;
                        n.x = (lastX - svgRect.left - graphTransform.x) / graphTransform.k;
                        n.y = (lastY - svgRect.top - graphTransform.y) / graphTransform.k;
                        node.setAttribute('transform', 'translate(' + n.x + ',' + n.y + ')');
                        // 拖拽联动：每帧跑一步力模拟（被拖点排斥/拉动其他节点）+ 只更新在移动的节点（轻量）
                        stepOnce();
                        updateMovingEls();
                    });
                }
                function up() {
                    n.fixed = false;
                    highlightNode(-1);  // 恢复全部亮度
                    window.removeEventListener('pointermove', move);
                    window.removeEventListener('pointerup', up);
                    startSim();
                }
                window.addEventListener('pointermove', move);
                window.addEventListener('pointerup', up);
            });
            node.style.transform = 'translate(' + n.x + 'px,' + n.y + 'px)';
            graphSvgG.appendChild(node);
            simEls.push(node);
        });
        // 缩放（滚轮，rAF 节流）+ 平移（空白拖拽）
        graphSvg.addEventListener('wheel', function (ev) {
            ev.preventDefault();
            var rect = graphSvg.getBoundingClientRect();
            var px = ev.clientX - rect.left, py = ev.clientY - rect.top;
            var k2 = graphTransform.k * (ev.deltaY < 0 ? 1.15 : 0.87);
            k2 = Math.max(0.2, Math.min(5, k2));
            graphTransform.x = px - (px - graphTransform.x) * (k2 / graphTransform.k);
            graphTransform.y = py - (py - graphTransform.y) * (k2 / graphTransform.k);
            graphTransform.k = k2;
            applyGraphTransform();
        }, { passive: false });
        var panning = null, panPending = false;
        var pointers = {}, lastPinchDist = 0;
        graphSvg.addEventListener('pointerdown', function (ev) {
            pointers[ev.pointerId] = { x: ev.clientX, y: ev.clientY };
            var nP = Object.keys(pointers).length;
            if (nP >= 2) {
                panning = null;  // 双指 = 缩放模式，停止平移
                var ids = Object.keys(pointers);
                var p1 = pointers[ids[0]], p2 = pointers[ids[1]];
                lastPinchDist = Math.sqrt(Math.pow(p1.x - p2.x, 2) + Math.pow(p1.y - p2.y, 2));
            } else if (nP === 1 && ev.target === graphSvg) {
                panning = { x: ev.clientX, y: ev.clientY, ax: 0, ay: 0 };
            }
        });
        window.addEventListener('pointermove', function (ev) {
            if (pointers[ev.pointerId]) { pointers[ev.pointerId].x = ev.clientX; pointers[ev.pointerId].y = ev.clientY; }
            var ids = Object.keys(pointers);
            // 双指：pinch 缩放（围绕两指中点——Obsidian 同款）
            if (ids.length >= 2 && lastPinchDist > 0) {
                var p1 = pointers[ids[0]], p2 = pointers[ids[1]];
                var dist = Math.sqrt(Math.pow(p1.x - p2.x, 2) + Math.pow(p1.y - p2.y, 2));
                var rect = graphSvg.getBoundingClientRect();
                var mx = (p1.x + p2.x) / 2 - rect.left, my = (p1.y + p2.y) / 2 - rect.top;
                var k2 = graphTransform.k * (dist / lastPinchDist);
                k2 = Math.max(0.2, Math.min(5, k2));
                graphTransform.x = mx - (mx - graphTransform.x) * (k2 / graphTransform.k);
                graphTransform.y = my - (my - graphTransform.y) * (k2 / graphTransform.k);
                graphTransform.k = k2;
                lastPinchDist = dist;
                applyGraphTransform();
                return;
            }
            if (!panning) return;
            panning.ax += ev.clientX - panning.x;  // 单指平移（累积位移——rAF 合并）
            panning.ay += ev.clientY - panning.y;
            panning.x = ev.clientX; panning.y = ev.clientY;
            if (panPending) return;
            panPending = true;
            requestAnimationFrame(function () {
                panPending = false;
                graphTransform.x += panning.ax;
                graphTransform.y += panning.ay;
                panning.ax = 0; panning.ay = 0;
                applyGraphTransform();
            });
        });
        function onPtrUp(ev) {
            delete pointers[ev.pointerId];
            if (Object.keys(pointers).length < 2) lastPinchDist = 0;
            if (Object.keys(pointers).length === 0) panning = null;
        }
        window.addEventListener('pointerup', onPtrUp);
        window.addEventListener('pointercancel', onPtrUp);
        applyGraphTransform();
        // 持续力导向模拟（Obsidian 风格：节点缓缓到位后静止，拖拽/交互时重新激活）
        startSim();
    }
    // ---- 持续力导向模拟（rAF 每帧一步；收敛后静止；拖拽/交互重新激活） ----
    var simRaf = null, simRunning = false;
    var simEls = [], simLineEls = [], clusterPairs = [], linkAdj = {};
    var W = 800, H = 500, cx = 400, cy = 250;
    function stepOnce() {
        var nodes = graphNodes;
        if (!nodes.length) return;
        var REP = 3500, SPRING = 0.01, REST = 100, DAMP = 0.9;
        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].fixed) continue;
            for (var j = i + 1; j < nodes.length; j++) {
                if (nodes[j].fixed) continue;
                var dx = nodes[i].x - nodes[j].x, dy = nodes[i].y - nodes[j].y;
                var d2 = dx * dx + dy * dy + 1;
                var f = REP / d2;
                var d = Math.sqrt(d2);
                nodes[i].vx += (dx / d) * f; nodes[i].vy += (dy / d) * f;
                nodes[j].vx -= (dx / d) * f; nodes[j].vy -= (dy / d) * f;
            }
        }
        graphLinks.forEach(function (l) {
            var a = nodes[l.source], b = nodes[l.target];
            if (!a || !b) return;
            var dx = b.x - a.x, dy = b.y - a.y;
            var d = Math.sqrt(dx * dx + dy * dy) || 1;
            var f = (d - REST) * SPRING;
            if (!a.fixed) { a.vx += (dx / d) * f; a.vy += (dy / d) * f; }
            if (!b.fixed) { b.vx -= (dx / d) * f; b.vy -= (dy / d) * f; }
        });
        // 同目录弱吸引（聚类不画线——强度约为真实链接的 1/5，距离 130 内互相靠近）
        for (var cp = 0; cp < clusterPairs.length; cp++) {
            var ca = nodes[clusterPairs[cp][0]], cb = nodes[clusterPairs[cp][1]];
            if (!ca || !cb || ca.fixed || cb.fixed) continue;
            var cdx = cb.x - ca.x, cdy = cb.y - ca.y;
            var cd = Math.sqrt(cdx * cdx + cdy * cdy) || 1;
            if (cd > 130) continue;
            var cf = (cd - 130) * 0.002;
            ca.vx += (cdx / cd) * cf; ca.vy += (cdy / cd) * cf;
            cb.vx -= (cdx / cd) * cf; cb.vy -= (cdy / cd) * cf;
        }
        nodes.forEach(function (n) {
            if (n.fixed) return;
            n.vx += (cx - n.x) * 0.015;
            n.vy += (cy - n.y) * 0.015;
            n.vx *= DAMP; n.vy *= DAMP;
            // 速度钳制：防节点弹飞
            if (n.vx > 2.5) n.vx = 2.5; if (n.vx < -2.5) n.vx = -2.5;
            if (n.vy > 2.5) n.vy = 2.5; if (n.vy < -2.5) n.vy = -2.5;
            n.x += n.vx; n.y += n.vy;
            // 软边界约束：越界慢慢拉回（不硬反弹——动画自然）；5px 硬兜底防完全出界
            if (n.x < 20) n.vx += (20 - n.x) * 0.08;
            if (n.x > W - 20) n.vx -= (n.x - (W - 20)) * 0.08;
            if (n.y < 20) n.vy += (20 - n.y) * 0.08;
            if (n.y > H - 20) n.vy -= (n.y - (H - 20)) * 0.08;
            if (n.x < 5) n.x = 5;
            if (n.x > W - 5) n.x = W - 5;
            if (n.y < 5) n.y = 5;
            if (n.y > H - 5) n.y = H - 5;
        });
        // 位置更新由调用方负责（tick/move 用 updateMovingEls 轻量更新；renderGraph 末尾用 updateEls 全量一次）
    }
    function updateMovingEls() {
        // 拖拽联动：只更新在移动的节点（其余静止跳过——轻量）；线全量更新（数量少）
        for (var i = 0; i < simEls.length; i++) {
            var n = graphNodes[i];
            if (!n) continue;
            if (n.fixed || Math.abs(n.vx) > 0.08 || Math.abs(n.vy) > 0.08) {
                // CSS transform（合成器 GPU 加速——比 SVG 属性更新丝滑；svg 无 viewBox，CSS px = SVG 单位）
                simEls[i].style.transform = 'translate(' + n.x.toFixed(1) + 'px,' + n.y.toFixed(1) + 'px)';
            }
        }
        for (var j = 0; j < simLineEls.length; j++) {
            var l = graphLinks[j];
            var a = graphNodes[l.source], b = graphNodes[l.target];
            if (!a || !b) continue;
            simLineEls[j].setAttribute('x1', a.x.toFixed(1));
            simLineEls[j].setAttribute('y1', a.y.toFixed(1));
            simLineEls[j].setAttribute('x2', b.x.toFixed(1));
            simLineEls[j].setAttribute('y2', b.y.toFixed(1));
        }
    }
    function updateEls() {
        // 直接索引：simEls[i] 对应 graphNodes[i]（渲染时同序创建）；线同理——每帧零对象分配
        for (var i = 0; i < simEls.length; i++) {
            var n = graphNodes[i];
            if (!n) continue;
            simEls[i].style.transform = 'translate(' + n.x.toFixed(1) + 'px,' + n.y.toFixed(1) + 'px)';
        }
        for (var j = 0; j < simLineEls.length; j++) {
            var l = graphLinks[j];
            var a = graphNodes[l.source], b = graphNodes[l.target];
            if (!a || !b) continue;
            simLineEls[j].setAttribute('x1', a.x.toFixed(1));
            simLineEls[j].setAttribute('y1', a.y.toFixed(1));
            simLineEls[j].setAttribute('x2', b.x.toFixed(1));
            simLineEls[j].setAttribute('y2', b.y.toFixed(1));
        }
    }
    function updateLinkEls(id) {
        // 只更新与指定节点相连的线（拖拽时——手机上快）
        var idxs = linkAdj[id] || [];
        for (var k = 0; k < idxs.length; k++) {
            var j = idxs[k];
            var l = graphLinks[j];
            var a = graphNodes[l.source], b = graphNodes[l.target];
            if (!a || !b) continue;
            simLineEls[j].setAttribute('x1', a.x.toFixed(1));
            simLineEls[j].setAttribute('y1', a.y.toFixed(1));
            simLineEls[j].setAttribute('x2', b.x.toFixed(1));
            simLineEls[j].setAttribute('y2', b.y.toFixed(1));
        }
    }
    function startSim() {
        if (simRaf) return;
        var simFrames = 0;
        function tick() {
            simFrames++;
            stepOnce();  // 每帧模拟（60fps 位置更新——丝滑）
            var totalV = 0;
            for (var i = 0; i < graphNodes.length; i++) {
                if (graphNodes[i].fixed) continue;
                totalV += Math.abs(graphNodes[i].vx) + Math.abs(graphNodes[i].vy);
            }
            // 至少动 60 帧（Obsidian 的"缓缓到位"活感）；收敛（速度 < 0.5）或超过 600 帧强制停止（绝不跳个不停）
            updateMovingEls();  // 只更新移动中的节点（轻量——保持高帧率）
            // 快速稳定：初排已接近收敛——动画只需轻轻微调就停（不长时间游动）
            if ((totalV > 1.5 || simFrames < 15) && simFrames < 200) {
                simRaf = requestAnimationFrame(tick);
            } else {
                simRaf = null;
                simRunning = false;
            }
        }
        simRunning = true;
        simRaf = requestAnimationFrame(tick);
    }
    function stopSim() {
        if (simRaf) { cancelAnimationFrame(simRaf); simRaf = null; }
        simRunning = false;
    }
    function applyGraphTransform() {
        if (graphSvgG) graphSvgG.setAttribute('transform', 'translate(' + graphTransform.x + ',' + graphTransform.y + ') scale(' + graphTransform.k + ')');
        // Obsidian 同款：缩小到一定程度自动隐藏文件名（默认显示开关开启时）
        if (window.GRAPH_SHOW_LABELS && graphSvgG) {
            var showL = graphTransform.k >= 0.8;
            var labels = graphSvgG.querySelectorAll('.graph-label');
            for (var i = 0; i < labels.length; i++) labels[i].setAttribute('opacity', showL ? '1' : '0');
        }
    }
    // hover 高亮邻居（class 切换：邻居亮、其余淡化）
    function highlightNode(id) {
        // 用缓存数组（simEls/simLineEls 与 graphNodes/graphLinks 同序）——不查 DOM
        if (!graphSvgG) return;
        var neighbors = {};
        if (id >= 0) {
            graphLinks.forEach(function (l) {
                if (l.source == id || l.target == id) { neighbors[l.source] = 1; neighbors[l.target] = 1; }
            });
        }
        for (var i = 0; i < simEls.length; i++) {
            var n = graphNodes[i];
            if (!n) continue;
            var on = (id < 0 || n.id == id || neighbors[n.id]);
            simEls[i].classList.toggle('graph-dim', !on);
        }
        for (var j = 0; j < simLineEls.length; j++) {
            var l = graphLinks[j];
            var on2 = (id < 0 || (neighbors[l.source] && neighbors[l.target]));
            simLineEls[j].classList.toggle('graph-link-dim', !on2);
            simLineEls[j].classList.toggle('graph-link-hot', id >= 0 && on2);  // hover 相关线变粗变亮
        }
    }
    // SSR 直达：/graph → 直接打开图谱视图（try/catch 防御：任何前置错误不阻塞图谱）
    if (window.SSR_GRAPH) { try { openGraph(); } catch (e) {} }

    /* ===== Excalidraw 绘画渲染（.excalidraw.md：lz-string 解码 compressed-json → SVG） ===== */
    function escapeXml(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    var exAscent = 0.9;  // Virgil 字形 ascent 比例（canvas 实测缓存——不依赖 dominant-baseline，所有浏览器一致）
    async function renderExcalidraw() {
        var raw = window.EXCALIDRAW_RAW || '';
        var m = raw.match(/```compressed-json\s*([\s\S]*?)```/);
        var dv = $('excalidraw-view');
        if (!dv) return;
        // 等 Virgil 字体加载（文字位置依赖其 metrics），超时 1.5s 兜底
        try { await Promise.race([document.fonts.load('20px Virgil'), new Promise(function (r) { setTimeout(r, 1500); })]); } catch (e) {}
        // canvas 实测 Virgil ascent 比例（基线渲染的精确偏移；字体没就绪时 fallback 0.9）
        try {
            var cc = document.createElement('canvas'), ctx = cc.getContext('2d');
            ctx.font = '20px Virgil, sans-serif';
            var mm = ctx.measureText('Ag');
            if (mm && mm.actualBoundingBoxAscent) exAscent = mm.actualBoundingBoxAscent / 20;
        } catch (e) {}
        if (!m || typeof LZString === 'undefined') { dv.innerHTML = '<p>Excalidraw data not found in this file.</p>'; return; }
        var scene;
        try { scene = JSON.parse(LZString.decompressFromBase64(m[1].replace(/\s+/g, ''))); }
        catch (e) { dv.innerHTML = '<p>Failed to decode drawing: ' + escapeXml(e.message) + '</p>'; return; }
        var els = scene.elements || [];
        // 线文本（bound text）：Excalidraw 把绑定线的文字渲染在线段中点（忽略未吸附的保存坐标——F/I/H/J 错位根因）
        // 预构建：线 id → 中点（text 侧用 containerId=线 id 反向查找）
        var lineMid = {};
        els.forEach(function (a) {
            if (a.type !== 'arrow' && a.type !== 'line') return;
            var pts = a.points || [[0, 0], [100, 0]];
            lineMid[a.id] = {
                x: (a.x + pts[0][0] + a.x + pts[pts.length - 1][0]) / 2,
                y: (a.y + pts[0][1] + a.y + pts[pts.length - 1][1]) / 2
            };
        });
        var minX = 1e9, minY = 1e9, maxX = -1e9, maxY = -1e9;
        els.forEach(function (e) {
            if (e.isDeleted || e.type === 'frame') return;
            if (e.points && e.points.length) {
                e.points.forEach(function (p) {
                    minX = Math.min(minX, e.x + p[0]); maxX = Math.max(maxX, e.x + p[0]);
                    minY = Math.min(minY, e.y + p[1]); maxY = Math.max(maxY, e.y + p[1]);
                });
            } else {
                minX = Math.min(minX, e.x); minY = Math.min(minY, e.y);
                maxX = Math.max(maxX, e.x + (e.width || 0)); maxY = Math.max(maxY, e.y + (e.height || 0));
            }
        });
        if (!isFinite(minX)) { minX = 0; minY = 0; maxX = 800; maxY = 600; }
        var pad = 40, vw = maxX - minX + pad * 2, vh = maxY - minY + pad * 2;
        var bg = scene.viewBackgroundColor || '#ffffff';
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' + (minX - pad) + ' ' + (minY - pad) + ' ' + vw + ' ' + vh + '" style="width:100%;height:auto;background:' + bg + '">';
        svg += '<defs><marker id="ex-arrow" markerWidth="12" markerHeight="12" refX="9" refY="3" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L0,6 L9,3 z" fill="#000000"/></marker></defs>';
        els.forEach(function (e) {
            if (e.isDeleted || e.type === 'frame') return;
            var col = e.strokeColor || '#000';
            var bgc = e.backgroundColor || 'transparent';
            var sw = e.strokeWidth || 1;
            var dash = (e.strokeStyle === 'dashed') ? ' stroke-dasharray="7,5"' : '';
            var op = (e.opacity != null && e.opacity < 100) ? ' opacity="' + (e.opacity / 100) + '"' : '';
            if (e.type === 'text') {
                var fs = e.fontSize || 20;
                var lines = String(e.text || '').split('\n');
                var anchor = e.textAlign === 'center' ? 'middle' : (e.textAlign === 'right' ? 'end' : 'start');
                // 绑定线文字：渲染在线段中点（Excalidraw bound text 引擎行为——替换保存坐标）；自由文字用原坐标
                var ex = e.x, ey = e.y;
                var lm = e.containerId ? lineMid[e.containerId] : null;
                if (lm) { ex = lm.x - (e.width || 0) / 2; ey = lm.y - (e.height || 0) / 2; }
                var tx = e.textAlign === 'center' ? ex + (e.width || 0) / 2 : (e.textAlign === 'right' ? ex + (e.width || 0) : ex);
                // y 是文字顶部；基线渲染 = y + ascent*fs（字形顶部 ≈ ey——不依赖 dominant-baseline，全浏览器一致）
                var baseY = ey + exAscent * fs;
                var rotT = '';
                if (e.angle) { var tc = e.x + (e.width || 0) / 2, tc2 = e.y + (e.height || 0) / 2; rotT = ' transform="rotate(' + (e.angle * 57.2958).toFixed(2) + ' ' + tc + ' ' + tc2 + ')"'; }
                svg += '<text x="' + tx + '" y="' + baseY.toFixed(2) + '" font-size="' + fs + '" fill="' + col + '" font-family="Virgil, Segoe UI Emoji, sans-serif" text-anchor="' + anchor + '"' + rotT + op + '>';
                for (var li = 0; li < lines.length; li++) {
                    if (li > 0) svg += '<tspan x="' + tx + '" dy="' + (fs * 1.25) + '">';  // Excalidraw 行高 1.25
                    svg += escapeXml(lines[li]);
                    if (li > 0) svg += '</tspan>';
                }
                svg += '</text>';
            } else if (e.type === 'arrow' || e.type === 'line') {
                var pts = (e.points || [[0, 0], [100, 0]]).map(function (p) { return (e.x + p[0]).toFixed(1) + ',' + (e.y + p[1]).toFixed(1); });
                var rotL = '';
                if (e.angle) { var lc = e.x + (e.width || 0) / 2, lc2 = e.y + (e.height || 0) / 2; rotL = ' transform="rotate(' + (e.angle * 57.2958).toFixed(2) + ' ' + lc + ' ' + lc2 + ')"'; }
                // polyline 全段 points（弯曲箭头还原）+ 箭头 marker（arrow 才有）
                svg += '<polyline points="' + pts.join(' ') + '" fill="none" stroke="' + col + '" stroke-width="' + sw + '"' + (e.type === 'arrow' ? ' marker-end="url(#ex-arrow)"' : '') + dash + op + rotL + '/>';
            } else if (e.type === 'rectangle') {
                var rx = (e.roundness && e.roundness.type === 'round') ? Math.min(12, (e.roundness.value || 8)) : 0;
                var rotR = '';
                if (e.angle) { var rc = e.x + (e.width || 0) / 2, rc2 = e.y + (e.height || 0) / 2; rotR = ' transform="rotate(' + (e.angle * 57.2958).toFixed(2) + ' ' + rc + ' ' + rc2 + ')"'; }
                svg += '<rect x="' + e.x + '" y="' + e.y + '" width="' + (e.width || 0) + '" height="' + (e.height || 0) + '" fill="' + bgc + '" stroke="' + col + '" stroke-width="' + sw + '" rx="' + rx + '"' + dash + op + rotR + '/>';
            } else if (e.type === 'ellipse') {
                var rotE = '';
                if (e.angle) { var ecx = e.x + (e.width || 0) / 2, ecy = e.y + (e.height || 0) / 2; rotE = ' transform="rotate(' + (e.angle * 57.2958).toFixed(2) + ' ' + ecx + ' ' + ecy + ')"'; }
                svg += '<ellipse cx="' + (e.x + (e.width || 0) / 2) + '" cy="' + (e.y + (e.height || 0) / 2) + '" rx="' + ((e.width || 0) / 2) + '" ry="' + ((e.height || 0) / 2) + '" fill="' + bgc + '" stroke="' + col + '" stroke-width="' + sw + '"' + dash + op + rotE + '/>';
            } else if (e.type === 'diamond') {
                var cx = e.x + (e.width || 0) / 2, cy = e.y + (e.height || 0) / 2;
                var rotD = '';
                if (e.angle) { var dc = e.x + (e.width || 0) / 2, dc2 = e.y + (e.height || 0) / 2; rotD = ' transform="rotate(' + (e.angle * 57.2958).toFixed(2) + ' ' + dc + ' ' + dc2 + ')"'; }
                svg += '<polygon points="' + cx + ',' + e.y + ' ' + (e.x + (e.width || 0)) + ',' + cy + ' ' + cx + ',' + (e.y + (e.height || 0)) + ' ' + e.x + ',' + cy + '" fill="' + bgc + '" stroke="' + col + '" stroke-width="' + sw + '"' + dash + op + rotD + '/>';
            }
        });
        svg += '</svg>';
        dv.innerHTML = svg;
        dv.style.display = 'block';  // 显示画布容器（初始 display:none——忘了设置会导致空白）
        // 视图切换：文章容器显示（excalidraw 在 doc-main 内），md 隐藏
        $('archive-view').style.display = 'none';
        $('doc-wrap').style.display = 'block';
        var mdv = $('md-view'); if (mdv) mdv.style.display = 'none';
        var ttl = $('doc-title');
        if (ttl) ttl.textContent = (window.EXCALIDRAW_PATH || '').split('/').pop().replace(/\.excalidraw\.md$/i, '');
    }
    if (window.SSR_EXCALIDRAW) { try { renderExcalidraw(); } catch (e) { if (window.console) console.log('excalidraw err', e); } }

    // 树里点击 .excalidraw.md：fetch 内容 → 解码渲染（不整页跳转）
    async function openExcalidraw(path) {
        window.EXCALIDRAW_PATH = path;
        try {
            var data = await api('/api/file?path=' + encodeURIComponent(path));
            if (!data || !data.ok) return;
            window.EXCALIDRAW_RAW = data.content || '';
            renderExcalidraw();
        } catch (e) { if (window.console) console.log('excalidraw open err', e); }
    }
    // 搜索功能：整页切换，实时过滤文档
    var searchView = $('search-view');
    var searchInput = $('search-input');
    var searchResults = $('search-results');
    function collectFiles() {
        var files = [];
        (function walk(nodes, cat) {
            nodes.forEach(function (n) {
                if (n.type === 'file' && /\.md$/i.test(n.name)) files.push({ name: n.name, path: n.path, cat: cat });
                else if (n.children) walk(n.children, n.name);
            });
        })(state.tree, '');
        return files;
    }
    function openSearch() {
        // 搜索面板从顶部栏下方滑出 + 按钮图标 morph 为叉子
        $('content').style.display = 'none';
        searchView.classList.add('open');
        $('vp-search-btn').classList.add('open');
        searchInput.value = '';
        searchResults.innerHTML = '';
        // 等面板滑出动画完成后聚焦，避免页面跳动
        setTimeout(function () { searchInput.focus({ preventScroll: true }); }, 320);
    }
    function closeSearch() {
        searchView.classList.remove('open');
        $('vp-search-btn').classList.remove('open');
        $('content').style.display = '';
    }
    function renderSearch(q) {
        if (!q) { searchResults.innerHTML = ''; return; }
        // 标签搜索：#标签 匹配笔记内容里的 #标签
        var tagMatch = q.match(/^#(.+)$/);
        if (tagMatch) {
            var tag = tagMatch[1].toLowerCase();
            var files = collectFiles();
            var results = [];
            var checked = 0;
            if (!files.length) { searchResults.innerHTML = '<div class="search-empty">' + 'No notes with this tag' + '</div>'; return; }
            files.forEach(function (f) {
                api('/api/file?path=' + encodeURIComponent(f.path)).then(function (data) {
                    var c = data.content || '';
                    if (c.toLowerCase().indexOf('#' + tag) > -1) results.push(f);
                    checked++;
                    if (checked >= files.length) renderSearchItems(results);
                }).catch(function () {
                    checked++;
                    if (checked >= files.length) renderSearchItems(results);
                });
            });
            return;
        }
        // 全文搜索：lunr 索引
        if (!window._lunrIndex) {
            buildSearchIndex().then(function () { doLunrSearch(q); });
            searchResults.innerHTML = '<div class="search-empty">Building index…</div>';
            return;
        }
        doLunrSearch(q);
    }
    // 构建全文索引：加载所有文章内容 → lunr 索引
    function buildSearchIndex() {
        var files = collectFiles();
        var docs = [];
        var chain = Promise.resolve();
        files.forEach(function (f) {
            chain = chain.then(function () {
                return api('/api/file?path=' + encodeURIComponent(f.path)).then(function (data) {
                    docs.push({
                        id: f.path,
                        path: f.path,
                        name: f.name.replace(/^\d+-/, '').replace(/\.md$/i, ''),
                        cat: f.cat || '',
                        content: data.content || ''
                    });
                }).catch(function () {});
            });
        });
        return chain.then(function () {
            window._lunrDocs = docs;
            window._lunrIndex = lunr(function () {
                this.ref('id');
                this.field('name', { boost: 10 });
                this.field('content');
                docs.forEach(function (d) { this.add(d); }, this);
            });
        });
    }
    // lunr 搜索结果
    function doLunrSearch(q) {
        var results = [];
        try {
            var hits = window._lunrIndex.search(q);
            results = hits.map(function (h) {
                return window._lunrDocs.filter(function (d) { return d.id === h.ref; })[0];
            }).filter(Boolean);
        } catch (e) {
            // 搜索语法错误（如特殊字符）→ 退化为标题匹配
            var kw = q.toLowerCase();
            results = collectFiles().filter(function (f) {
                return f.name.toLowerCase().indexOf(kw) > -1;
            });
        }
        renderSearchItems(results);
    }
    function renderSearchItems(files) {
        if (!files.length) {
            searchResults.innerHTML = '<div class="search-empty">' + 'No matching notes' + '</div>';
            return;
        }
        var html = files.map(function (f) {
            var dispName = f.name.replace(/^\d+-/, '').replace(/\.md$/, '');
            // 内容匹配片段（截取关键词上下文）
            var snippet = '';
            if (f.content) {
                var kwMatch = searchInput.value.trim().toLowerCase();
                var c = f.content.replace(/[#*`_>\[\]|!-]/g, ' ').replace(/\s+/g, ' ').toLowerCase();
                var idx = kwMatch ? c.indexOf(kwMatch) : -1;
                if (idx > -1) {
                    var start = Math.max(0, idx - 40);
                    snippet = '…' + f.content.slice(start, idx + kwMatch.length + 60).replace(/\n/g, ' ') + '…';
                }
            }
            return '<div class="search-item" data-path="' + esc(f.path) + '">' +
                '<div class="search-item-main">' +
                (f.cat ? '<span class="search-cat">' + esc(f.cat) + '</span>' : '') +
                '<span class="search-name">' + esc(dispName) + '</span>' +
                '</div>' +
                (snippet ? '<div class="search-snippet">' + esc(snippet) + '</div>' : '') +
                '<span class="search-arrow">↵</span></div>';
        }).join('');
        searchResults.innerHTML = html;
        searchResults.querySelectorAll('.search-item').forEach(function (el) {
            el.addEventListener('click', function () {
                var path = el.dataset.path;
                closeSearch();
                selectFile({ path: path });
            });
        });
    }
    // 点击面板外部关闭搜索（面板打开时）
    document.addEventListener('click', function (e) {
        if (!searchView.classList.contains('open')) return;
        if (searchView.contains(e.target)) return;
        if ($('vp-search-btn').contains(e.target)) return;
        closeSearch();
    });
    // 搜索按钮：打开时是叉子（点击关闭），关闭时是放大镜（点击打开）
    // AI 对话：顶栏按钮展开面板，问知识库（/api/ask：检索 + DeepSeek 生成回答）
    // 总开关关闭：隐藏 AI 按钮（面板无入口）
    if (!window.AI_ENABLED) {
        var aiBtn = $('vp-ai-btn');
        if (aiBtn) aiBtn.style.display = 'none';
    }

    var aiView = $('ai-view');
    var aiMsgs = $('ai-msgs');
    var aiInput = $('ai-input');
    function toggleAi(open) {
        if (open === undefined) open = !aiView.classList.contains('open');
        aiView.classList.toggle('open', open);
        if (open) {
            // 与搜索面板互斥
            if (searchView.classList.contains('open')) closeSearch();
            setTimeout(function () { aiInput.focus({ preventScroll: true }); }, 100);
        }
    }
    function aiAddMsg(text, role) {
        var d = document.createElement('div');
        d.className = 'ai-msg ' + (role === 'user' ? 'ai-user' : 'ai-bot');
        d.textContent = text;
        aiMsgs.appendChild(d);
        aiMsgs.scrollTop = aiMsgs.scrollHeight;
        return d;
    }
    function aiAsk() {
        var q = aiInput.value.trim();
        if (!q) return;
        aiAddMsg(q, 'user');
        aiInput.value = '';
        var loading = aiAddMsg('Thinking...', 'bot');
        loading.classList.add('ai-typing');
        fetch('/api/ask', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: q })
        }).then(function (r) { return r.json(); }).then(function (d) {
            loading.remove();
            if (!d.ok) {
                aiAddMsg('Error: ' + (d.error || 'unknown error'), 'bot');
                return;
            }
            var bot = aiAddMsg(d.answer, 'bot');
            if (d.sources && d.sources.length) {
                var src = document.createElement('div');
                src.className = 'ai-src';
                src.appendChild(document.createTextNode('Sources: '));
                d.sources.forEach(function (s, i) {
                    var a = document.createElement('a');
                    a.href = '/' + s.path;
                    a.textContent = s.name;
                    a.addEventListener('click', function (e) {
                        e.preventDefault();
                        toggleAi(false);
                        selectFile({ path: s.path });
                    });
                    src.appendChild(a);
                    if (i < d.sources.length - 1) src.appendChild(document.createTextNode(' · '));
                });
                bot.appendChild(src);
            }
        }).catch(function (err) {
            loading.remove();
            aiAddMsg('Network error: ' + (err && err.message ? err.message : 'unknown'), 'bot');
        });
    }
    $('vp-ai-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        toggleAi();
    });
    $('ai-send').addEventListener('click', aiAsk);
    aiInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); aiAsk(); }
        else if (e.key === 'Escape') { toggleAi(false); }
    });

    $('vp-search-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        if (searchView.classList.contains('open')) {
            closeSearch();
        } else {
            openSearch();
        }
    });
    searchInput.addEventListener('input', function () { renderSearch(searchInput.value); });
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            var first = searchResults.querySelector('.search-item');
            if (first) first.click();
        } else if (e.key === 'Escape') {
            closeSearch();
        }
    });

    // 主题切换按钮（桌面右侧 / 移动最左）：点击切换日夜模式
    function bindThemeBtn(id) {
        $(id).addEventListener('click', function (e) {
            e.stopPropagation();
            var dark = !document.documentElement.classList.contains('dark');
            applyTheme(dark);
        });
    }
    bindThemeBtn('vp-theme-btn');
    bindThemeBtn('vp-theme-btn-m');

    // 菜单按钮：切换全屏侧滑菜单（文章目录树，预渲染零延迟）
    var drawerEl = $('vp-drawer');
    var frontDrawerMd = $('front-drawer-md');
    function setFrontDrawer(open) {
        drawerEl.classList.toggle('open', open);
        document.body.classList.toggle('drawer-open', open); // 锁定页面滚动
        var nw = document.getElementById('nav-wrap');
        if (nw) nw.classList.toggle('no-blur', open); // 顶部栏实心化防透字
    }
    // 预渲染：PHP 内联的 FRONT_MENU_MD（vault/ 文章目录树）
    if (window.FRONT_MENU_MD) {
        try {
            frontDrawerMd.innerHTML = DOMPurify.sanitize(marked.parse(window.FRONT_MENU_MD, { gfm: true }));
            // 兼容写法：找 li 的直接子 UL / 向上找 li 祖先（不用 :scope/closest）
            function childUl(li) {
                for (var i = 0; i < li.children.length; i++) {
                    if (li.children[i].tagName === 'UL') return li.children[i];
                }
                return null;
            }
            function parentLi(el) {
                var n = el.parentNode;
                while (n && n !== frontDrawerMd && n.tagName !== 'LI') n = n.parentNode;
                return n && n.tagName === 'LI' ? n : null;
            }
            // 为每个目录项重建相对路径（用于强制展开匹配）
            function buildDirPaths(root) {
                function walkUl(ul, prefix) {
                    var items = ul.children;
                    for (var i = 0; i < items.length; i++) {
                        var li = items[i];
                        var nameNode = li.firstChild;
                        var name = nameNode ? nameNode.textContent.trim() : '';
                        if (!name) continue;
                        var path = prefix ? prefix + '/' + name : name;
                        li.dataset.path = path;
                        var sub = null;
                        for (var j = 0; j < li.children.length; j++) {
                            if (li.children[j].tagName === 'UL') { sub = li.children[j]; break; }
                        }
                        if (sub) walkUl(sub, path);
                    }
                }
                // 从容器内第一个 ul 开始遍历（root 是 div，不能直接当 ul 用）
                var rootUl = root.querySelector('ul');
                if (rootUl) walkUl(rootUl, '');
            }
            buildDirPaths(frontDrawerMd);
            // 折叠树：父项点击折叠/展开
            var lis = frontDrawerMd.querySelectorAll('li');
            for (var i = 0; i < lis.length; i++) {
                (function (li) {
                    var sub = childUl(li);
                    if (!sub) return;
                    li.classList.add('has-children');
                    // 插入左侧箭头 SVG（chevron：颜色 currentColor 随主题、stroke-width 可控）
                    var arrow = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                    arrow.setAttribute('class', 'dir-arrow');
                    arrow.setAttribute('width', '15');
                    arrow.setAttribute('height', '15');
                    arrow.setAttribute('viewBox', '0 0 24 24');
                    arrow.setAttribute('fill', 'none');
                    arrow.setAttribute('stroke', 'currentColor');
                    arrow.setAttribute('stroke-width', '3');
                    arrow.setAttribute('stroke-linecap', 'round');
                    arrow.setAttribute('stroke-linejoin', 'round');
                    arrow.innerHTML = '<path d="M9 6l6 6-6 6"/>';
                    li.insertBefore(arrow, li.firstChild);
                    // 默认折叠状态（偏好设置控制）；强制展开目录不受影响
                    if (window.FRONT_DRAWER_EXPANDED === false) {
                        var forceExpand = false;
                        var dirs = window.FRONT_EXPANDED_DIRS || [];
                        var p = li.dataset.path || '';
                        for (var k = 0; k < dirs.length; k++) {
                            var d = dirs[k];
                            if (d && (p === d || p.indexOf(d + '/') === 0)) { forceExpand = true; break; }
                        }
                        if (!forceExpand) li.classList.add('collapsed');
                    }
                    li.addEventListener('click', function (ev) {
                        if (sub.contains(ev.target)) return;
                        ev.preventDefault();
                        li.classList.toggle('collapsed');
                    });
                })(lis[i]);
            }
            // 叶子链接：点击打开文章 + 关闭抽屉
            var as = frontDrawerMd.querySelectorAll('a');
            for (var j = 0; j < as.length; j++) {
                (function (a) {
                    var li = parentLi(a);
                    if (li && childUl(li)) return; // 父项跳过（折叠处理）
                    a.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        var href = a.getAttribute('href') || '';
                        // Graph view 虚拟条目：前端直接打开图谱（不整页跳转）
                        if (href === '/graph') {
                            openGraph();
                            setFrontDrawer(false);
                            return;
                        }
                        var h = href.replace(/^#/, '');
                        if (!h) return;
                        setFrontDrawer(false);
                        selectFile({ path: decodeURI(h) });
                    });
                })(as[j]);
            }
        } catch (e) {}
    }
    $('vp-menu-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        setFrontDrawer(!drawerEl.classList.contains('open'));
    });
    // 点击侧滑菜单空白处关闭
    drawerEl.addEventListener('click', function (e) {
        if (e.target === drawerEl || e.target === frontDrawerMd) {
            setFrontDrawer(false);
        }
    });

    /* ---------- 浏览器返回/前进：hash 变化时同步视图 ---------- */
    // 站内文章链接（/xxx.md）：SPA 切换（无刷新）+ pushState 路径化 URL；刷新/直达走服务端渲染
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (/\.md$/i.test(href) && href.charAt(0) === '/') {
            e.preventDefault();
            var p = href.substring(1);
            try { p = decodeURI(p); } catch (err) {}  // 菜单/内链 href 带 URL 编码 → 解码后再查（防双重编码）
            try { history.pushState(null, '', href); } catch (err) {}
            selectFile({ path: p });
        }
    });
    function handleHash() {
        var h = location.hash.replace(/^#/, '');
        if (!h) {
            // hash 为空 → 回到首页（渲染首页文章正文）
            $('md-view').style.display = 'none';
            $('doc-wrap').style.display = 'none';
            $('archive-view').style.display = '';
            $('toc-panel').style.display = 'none';
            renderHome();
            highlightActive(null);
            // 重置滚动位置（避免回归档时错位）
            $('content').scrollTop = 0;
            return;
        }
        if (/^\d+$/.test(h) && window._docMap && window._docMap[h]) {
            // 数字 ID 直达（旧版兼容）
            if (window._docMap[h] !== state.path) {
                selectFile({ path: window._docMap[h] });
            }
        } else if (h) {
            // 完整路径直达（新逻辑：#posts/draft/xxx.md）
            var p = decodeURI(h);
            // 兜底：纯文件名（无路径前缀）时查 _docMap 显示名映射
            if (p.indexOf('/') === -1 && window._docMap && window._docMap[p]) {
                p = window._docMap[p];
            }
            if (p !== state.path) {
                selectFile({ path: p });
            }
        }
    }
    window.addEventListener('hashchange', handleHash);
    // SPA 路径导航（pushState）的返回/前进：popstate 时按当前路径恢复文章或回首页
    window.addEventListener('popstate', function () {
        var p = location.pathname.replace(/^\//, '');
        if (/\.md$/i.test(p)) {
            selectFile({ path: p });
        } else if (location.hash) {
            handleHash();
        } else {
            $('md-view').style.display = 'none';
            $('doc-wrap').style.display = 'none';
            $('archive-view').style.display = '';
            $('toc-panel').style.display = 'none';
            $('content').scrollTop = 0;
            highlightActive(null);
        }
    });

    /* ---------- 初始化 ---------- */
    (function init() {
        // 已移除访问密码，直接进入（URL hash 直达在 loadTree 完成后处理）
        enterApp();
    })();
})();
</script>
</body>
</html>
