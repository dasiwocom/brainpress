<?php
/**
 * BrainPress — ima mount driver (Tencent ima knowledge base OpenAPI)
 *
 * Exposes the user's Tencent ima knowledge base as an independent content source:
 *  - knowledge base → folder → file (PDF/md/etc.) flattened into the existing file tree (same-name main vault wins)
 *  - file content fetched live via the ima OpenAPI (server proxy), PDF/md flow through the existing render pipeline
 *
 * Auth: headers ima-openapi-clientid / ima-openapi-apikey
 * Download: get_media_info returns a signed COS URL + X-IMA-* headers, required along with the request or it 403s
 *
 * Credential source (priority): config[ima][client_id]/[api_key] → ~/.config/ima/ files → environment variables
 *
 * Cache: the file tree (incl. rel→media index) is cached to ima_cache.json to avoid a full fetch on every page refresh
 * (ima is a remote API; full traversal of knowledge bases/folders is network-heavy; reused within the cache TTL)
 */

declare(strict_types=1);

const IMA_BASE = 'https://ima.qq.com';
const IMA_CACHE_FILE = __DIR__ . '/ima_cache.json';
const IMA_CACHE_TTL = 600; // seconds

/** Read ima credentials (config → home file → env) */
function ima_credentials(array $config): array {
    $home = isset($_SERVER['HOME']) ? (string)$_SERVER['HOME'] : '';
    $cfg = $config['ima'] ?? [];
    $cid = trim((string)($cfg['client_id'] ?? ''));
    $key = trim((string)($cfg['api_key'] ?? ''));
    if ($cid === '' && $home !== '') {
        $cid = trim((string)@file_get_contents($home . '/.config/ima/client_id'));
    }
    if ($key === '' && $home !== '') {
        $key = trim((string)@file_get_contents($home . '/.config/ima/api_key'));
    }
    if ($cid === '') $cid = trim((string)(getenv('IMA_CLIENT_ID') ?: ''));
    if ($key === '') $key = trim((string)(getenv('IMA_API_KEY') ?: ''));
    return ['client_id' => $cid, 'api_key' => $key, 'enabled' => ($cid !== '' && $key !== '')];
}

/** Whether the ima mount is enabled: admin switch + credentials present */
function ima_enabled(array $config): bool {
    if (empty($config['render_ima'])) return false;
    $c = ima_credentials($config);
    return $c['enabled'];
}

/** POST to the ima OpenAPI, returns the decoded data; null on failure */
function ima_api(array $config, string $apiPath, array $body): ?array {
    $c = ima_credentials($config);
    if (!$c['enabled']) return null;
    $ch = curl_init(IMA_BASE . '/' . ltrim($apiPath, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'ima-openapi-clientid: ' . $c['client_id'],
            'ima-openapi-apikey: ' . $c['api_key'],
            'ima-openapi-ctx: brainpress=1.1.0',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false || $raw === '') return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || (int)($j['code'] ?? -1) !== 0) return null;
    return is_array($j['data'] ?? null) ? $j['data'] : [];
}

/** List knowledge bases (search_knowledge_base with query='').
 *  Only keeps the user's OWN knowledge bases (base_type = personal, or a non-subscription creator) — files
 *  in subscription knowledge bases cannot be fetched via the skill OpenAPI (get_media_info returns 220030 no-permission),
 *  so including them would only fail to render. */
function ima_knowledge_bases(array $config): array {
    $data = ima_api($config, 'openapi/wiki/v1/search_knowledge_base', [
        'query' => '', 'cursor' => '', 'limit' => 20,
    ]);
    $out = [];
    foreach (($data['info_list'] ?? []) as $kb) {
        $id = (string)($kb['kb_id'] ?? '');
        $name = (string)($kb['kb_name'] ?? '');
        if ($id === '' || $name === '') continue;
        // Own knowledge bases only: subscription bases (those the user joined) can't be read through the API
        $baseType = (string)($kb['base_type'] ?? '');
        if (mb_strpos($baseType, '订阅') !== false) continue; // '订阅' = subscription label returned by the API
        $out[] = [
            'id' => $id,
            'name' => $name,
            'description' => (string)($kb['description'] ?? ''),
        ];
    }
    return $out;
}

/** List one level of a knowledge base: returns ['dirs'=>[], 'files'=>[]]; empty $folderId = root */
function ima_list(array $config, string $kbId, string $folderId = ''): array {
    $cursor = '';
    $dirs = []; $files = [];
    do {
        $body = ['cursor' => $cursor, 'limit' => 50, 'knowledge_base_id' => $kbId];
        if ($folderId !== '') $body['folder_id'] = $folderId;
        $data = ima_api($config, 'openapi/wiki/v1/get_knowledge_list', $body);
        if ($data === null) break;
        foreach (($data['knowledge_list'] ?? []) as $item) {
            $title = (string)($item['title'] ?? '');
            $mid = (string)($item['media_id'] ?? '');
            $mtype = (int)($item['media_type'] ?? 0);
            if ($title === '' || $mid === '') continue;
            if ($mtype === 99) {
                // folder
                $dirs[] = ['id' => $mid, 'name' => $title];
            } else {
                // file
                $files[] = ['id' => $mid, 'title' => $title, 'type' => $mtype];
            }
        }
        $cursor = (string)($data['next_cursor'] ?? '');
        $isEnd = !empty($data['is_end']);
    } while ($cursor !== '' && !$isEnd);
    return ['dirs' => $dirs, 'files' => $files];
}

/** media_type → display extension (used for rel and frontend type detection) */
function ima_ext_of(int $mtype, string $title): string {
    $ext = strtolower(pathinfo($title, PATHINFO_EXTENSION));
    if ($ext !== '' && in_array($ext, ['pdf', 'md', 'canvas', 'txt', 'docx', 'pptx', 'xlsx'], true)) return $ext;
    // recognize .pdf/.md in the name too (titles may carry parentheses etc.)
    if ($mtype === 1) return 'pdf';
    if ($mtype === 7) return 'md';
    if ($mtype === 13) return 'txt';
    // other types fall back to the extension
    return $ext !== '' ? $ext : 'bin';
}

/** Build the cache: traverse all knowledge bases, flatten rel→media index + file tree */
function ima_build_index(array $config): array {
    $bases = ima_knowledge_bases($config);
    $files = [];          // rel -> entry
    $nested = [];         // tree: kb -> [dirs -> [files]]
    foreach ($bases as $kb) {
        $kbNode = ['name' => $kb['name'], 'dirs' => [], 'files' => []];
        $walk = function (string $folderId, string $relPrefix, array &$kbNode) use (&$walk, &$files, $config, $kb) {
            $list = ima_list($config, $kb['id'], $folderId);
            // files of this level (collect files first, dirs after)
            foreach ($list['files'] as $f) {
                $ext = ima_ext_of($f['type'], $f['title']);
                $name = $f['title'];
                // append a correct extension if the title has none
                if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === '' && $ext !== 'bin') {
                    $name .= '.' . $ext;
                }
                $rel = $relPrefix === '' ? $name : $relPrefix . '/' . $name;
                $files[$rel] = [
                    'media_id' => $f['id'],
                    'media_type' => $f['type'],
                    'ext' => $ext,
                    'kb' => $kb['name'],
                    'title' => $f['title'],
                ];
                $kbNode['files'][] = ['name' => $name, 'type' => $ext];
            }
            // sub-folders (recurse)
            foreach ($list['dirs'] as $dir) {
                $dirNode = ['name' => $dir['name'], 'dirs' => [], 'files' => []];
                $walk($dir['id'], $relPrefix === '' ? $dir['name'] : $relPrefix . '/' . $dir['name'], $dirNode);
                $kbNode['dirs'][] = $dirNode;
            }
        };
        // root level: relPrefix is prefixed with the knowledge base name (matches tree path so ima_index_lookup hits)
        $walk('', $kb['name'], $kbNode);
        $nested[] = $kbNode;
    }
    return ['ts' => time(), 'bases' => $bases, 'files' => $files, 'nested' => $nested];
}

/** Read the cache (rebuild when missing/expired) */
function ima_cache(array $config): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = ['ts' => 0, 'bases' => [], 'files' => [], 'nested' => []];
    $raw = @file_get_contents(IMA_CACHE_FILE);
    $j = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($j) && (int)($j['ts'] ?? 0) + IMA_CACHE_TTL > time()) {
        $cache = $j;
    } else {
        try {
            $cache = ima_build_index($config);
            @file_put_contents(IMA_CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            // keep the old cache when fetching fails (if present)
            if (is_array($j)) $cache = $j;
        }
    }
    return $cache;
}

/** Build the ima top-level tree (for merging into the existing tree). Empty KBs are skipped */
function ima_tree(array $config, array $excludes): array {
    if (!ima_enabled($config)) return [];
    $data = ima_cache($config);
    return ima_nested_to_tree($data['nested'] ?? [], $excludes, $config['render_types'] ?? null);
}

/** ima 文件扩展名是否允许展示：主 vault 的目录树按 render_types 过滤，ima 挂载需一致（关闭 PDF 时 ima PDF 不再出现在树里） */
function ima_type_allowed(string $ext, ?array $renderTypes): bool {
    if ($ext === 'pdf')    return !array_key_exists('pdf', (array)$renderTypes) ? true : !empty($renderTypes['pdf']);
    if ($ext === 'md')     return !array_key_exists('markdown', (array)$renderTypes) ? true : !empty($renderTypes['markdown']);
    if ($ext === 'canvas') return !array_key_exists('canvas', (array)$renderTypes) ? true : !empty($renderTypes['canvas']);
    if ($ext === 'txt')    return true;
    return false; // 其它类型（docx/doc 等）不收录 —— 只渲染 md/pdf/canvas/txt
}

/** Convert the ima index's nested structure into scan_tree-equivalent entries */
function ima_nested_to_tree(array $nested, array $excludes, ?array $renderTypes = null): array {
    $items = [];
    foreach ($nested as $kb) {
        if (empty($kb['files']) && empty($kb['dirs'])) continue; // skip empty knowledge bases
        $node = ['name' => $kb['name'], 'path' => $kb['name'], 'type' => 'dir', 'children' => []];
        foreach (($kb['dirs'] ?? []) as $dir) {
            $sub = ima_dir_node($dir, $kb['name'], $renderTypes);
            if (!empty($sub['children'])) $node['children'][] = $sub; // 空目录（内容全被渲染类型过滤）不展示
        }
        foreach (($kb['files'] ?? []) as $f) {
            $ext = $f['type'];
            if (!ima_type_allowed($ext, $renderTypes)) continue;
            $node['children'][] = ['name' => $f['name'], 'path' => $kb['name'] . '/' . $f['name'], 'type' => 'file'];
        }
        if (!empty($node['children'])) $items[] = $node;
    }
    return $items;
}

/** Recursively build an ima directory node; $ancPath = ancestor path prefix */
function ima_dir_node(array $dir, string $ancPath, ?array $renderTypes = null): array {
    $path = $ancPath . '/' . $dir['name'];
    $children = [];
    foreach (($dir['dirs'] ?? []) as $sub) {
        $subNode = ima_dir_node($sub, $path, $renderTypes);
        if (!empty($subNode['children'])) $children[] = $subNode;
    }
    foreach (($dir['files'] ?? []) as $f) {
        $ext = $f['type'];
        if (ima_type_allowed($ext, $renderTypes)) {
            $children[] = ['name' => $f['name'], 'path' => $path . '/' . $f['name'], 'type' => 'file'];
        }
    }
    return ['name' => $dir['name'], 'path' => $path, 'type' => 'dir', 'children' => $children];
}

/** Look up an ima file entry by rel; null when not found */
function ima_index_lookup(array $config, string $rel): ?array {
    if (!ima_enabled($config)) return null;
    $rel = str_replace('\\', '/', ltrim(trim($rel), '/'));
    if ($rel === '') return null;
    $data = ima_cache($config);
    return $data['files'][$rel] ?? null;
}

/** Renderable md files in ima (for full-text search / knowledge graph). Returns [{path,name,mtime}] */
function ima_md_files(array $config): array {
    if (!ima_enabled($config)) return [];
    $data = ima_cache($config);
    $out = [];
    foreach (($data['files'] ?? []) as $rel => $e) {
        if (($e['ext'] ?? '') !== 'md') continue;
        $out[] = [
            'path' => $rel,
            'name' => preg_replace('/\.md$/i', '', basename($rel)),
            'mtime' => 0,
        ];
    }
    return $out;
}

/** Get file access info: returns [url, headers] or null */
function ima_access_info(array $config, array $entry): ?array {
    $data = ima_api($config, 'openapi/wiki/v1/get_media_info', ['media_id' => $entry['media_id']]);
    if ($data === null) return null;
    $url = (string)($data['url_info']['url'] ?? '');
    if ($url === '') return null;
    $headers = [];
    foreach (($data['url_info']['headers'] ?? []) as $k => $v) {
        $headers[] = $k . ': ' . $v;
    }
    return ['url' => $url, 'headers' => $headers];
}

/** Read an ima file's raw bytes (server proxy). Returns [bytes, mime] or null */
function ima_read_raw(array $config, string $rel): ?array {
    $entry = ima_index_lookup($config, $rel);
    if ($entry === null) return null;
    $info = ima_access_info($config, $entry);
    if ($info === null) return null;
    $mime = 'application/octet-stream';
    switch ($entry['ext']) {
        case 'pdf': $mime = 'application/pdf'; break;
        case 'md': $mime = 'text/markdown; charset=utf-8'; break;
        case 'canvas': $mime = 'application/json'; break;
        case 'txt': $mime = 'text/plain; charset=utf-8'; break;
    }
    $ch = curl_init($info['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $info['headers'],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
    ]);
    $bytes = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($bytes === false || $code !== 200 || $bytes === '') return null;
    return ['bytes' => $bytes, 'mime' => $mime];
}

/** Stream an ima file's bytes (for the /vault/ fallback route) */
function ima_stream_file(array $config, string $rel): bool {
    $r = ima_read_raw($config, $rel);
    if ($r === null) return false;
    header('Content-Type: ' . $r['mime']);
    header('Content-Length: ' . strlen($r['bytes']));
    echo $r['bytes'];
    return true;
}
