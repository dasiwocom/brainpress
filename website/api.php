<?php
/**
 * BrainPress v1.1.0 — 公开 API（/api/*）
 * 由 index.php 在 /api/ 路径命中时 require（生产 nginx 将 /api/ 交给主入口，无需感知本文件）。
 * 端点：setup/login/logout、list、file、search、graph、ask、article-list、llms.txt、note。
 * /api/admin/* 的配置管理在 admin.php（nginx 直接转发）。
 */

declare(strict_types=1);

/** 公开 API 路由：$uri 以 /api/ 开头时由主入口调用 */
function handle_api(string $uri, string $method, array $config): never
{
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
        $renderWebdav = $config['render_webdav'] ?? false;
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

        // 自定义本地路径（最多 5 条，与主 vault 平权）：目录递归扫描、单 .md 文件作顶层条目，同名主 vault 优先
        $tree = merge_custom_trees($tree, $config);

        ok(['tree' => $tree]);
    }

    // 读取文件（按渲染开关：本地路径读本地，否则读桶）
    if ($uri === '/api/file' && $method === 'GET') {
        $rel = (string)($_GET['path'] ?? '');
        $renderWebdav = $config['render_webdav'] ?? false;
        $renderMinio = $config['render_minio'] ?? true;

        // Custom Path 优先（最多 5 条）：目录 → rel 必须落在目录内；单文件 → 只匹配该文件（防路径穿越）
        $customPaths = $config['custom_paths'] ?? [];
        foreach ($customPaths as $cp) {
            if (empty($cp['on'])) continue;
            $customPath = trim((string)($cp['path'] ?? ''));
            if ($customPath === '') continue;
            $rp = realpath($customPath);
            if ($rp === false) continue;
            if (is_file($rp) && (is_md($rp) || is_canvas($rp)) && $rel === basename($rp)) {
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
                // 目录挂载与主 vault 同级平权：rel 直接落在挂载根内解析（无前缀包装）
                $full = realpath($rp . '/' . $rel);
                if ($full !== false && strpos($full, $rp . '/') === 0 && is_file($full) && (is_md($full) || is_canvas($full))) {
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
            if (!is_file($full) || (!is_md($full) && !is_canvas($full))) fail('文件不存在');
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
        $files = collect_all_md_files($config);
        $results = [];
        foreach ($files as $f) {
            if (is_excluded($f['path'], $excludes)) continue;
            $abs = resolve_vault_file($f['path'], $config);
            if ($abs === null) continue;
            $content = (string)@file_get_contents($abs);
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
        $gFiles = collect_all_md_files($config);
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
            $gAbs = resolve_vault_file($f['path'], $config);
            if ($gAbs === null) continue;
            $content = (string)@file_get_contents($gAbs);
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
        // OpenAI 兼容端点：任意网关（DeepSeek/NewAPI/one-api 等），默认 DeepSeek 官方
        $aiBase = rtrim(trim((string)($config['ai_api_base'] ?? '')), '/');
        if ($aiBase === '') $aiBase = 'https://api.deepseek.com';
        $aiChatUrl = $aiBase . '/chat/completions';
        $aiModel = (string)($config['ai_model'] ?? 'deepseek-chat');
        $aiMode = (string)($config['ai_mode'] ?? 'hybrid');
        // key 可为空：Ollama/LM Studio 等本地推理服务无需鉴权（有 key 才发 Authorization 头）
        $aiHeaders = ['Content-Type: application/json'];
        if ($aiKey !== '') $aiHeaders[] = 'Authorization: Bearer ' . $aiKey;
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $question = trim((string)($body['question'] ?? ''));
        if ($question === '') fail('Empty question', 400);
        // 1) 检索相关文章（标题加权 + 内容关键词匹配，top 4）
        $excludes = $config['exclude_paths'] ?? [];
        $files = collect_all_md_files($config);
        $hits = [];
        foreach ($files as $f) {
            if (is_excluded($f['path'], $excludes)) continue;
            $aiAbs = resolve_vault_file($f['path'], $config);
            if ($aiAbs === null) continue;
            $content = (string)@file_get_contents($aiAbs);
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
            $ch = curl_init($aiChatUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $aiHeaders,
                CURLOPT_TIMEOUT => 120,
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
        $siteTitle = (string)($config['site_title'] ?? 'BrainPress');
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
        $ch = curl_init($aiChatUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $aiHeaders,
            CURLOPT_TIMEOUT => 120,
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
        $files = collect_all_md_files($config);
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
        $files = collect_all_md_files($config);
        $siteBase = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        header('Content-Type: text/plain; charset=utf-8');
        echo "# " . ($config['site_title'] ?? 'BrainPress') . " Knowledge Base\n\n";
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
        // 写 API 仅作用于主 vault（不写自定义挂载目录——外部目录可能只读或属用户私有，避免误写）
        $vaultRoot = realpath(PANEL_DIR . '/vault');
        if ($vaultRoot === false) fail('Vault not found', 500);
        if ($method === 'POST') {
            $content = (string)($body['content'] ?? '');
            $full = realpath(PANEL_DIR . '/vault/' . $path);
            $existed = false;
            if ($full === false) {
                // 新建：父目录必须存在且在 vault 内
                $parent = realpath(dirname(PANEL_DIR . '/vault/' . $path));
                if ($parent === false || strpos($parent, $vaultRoot) !== 0) fail('Invalid path', 400);
                $full = $parent . '/' . basename($path);
            } elseif (strpos($full, $vaultRoot) !== 0 || !is_file($full)) {
                fail('Invalid path', 400);
            } else {
                $existed = true;
            }
            $tmp = $full . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $content) === false) fail('Write failed', 500);
            if (!@rename($tmp, $full)) { @unlink($tmp); fail('Write failed', 500); }
            @chmod($full, 0644);
            ok(['path' => $path, 'bytes' => strlen($content), 'created' => !$existed]);
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
