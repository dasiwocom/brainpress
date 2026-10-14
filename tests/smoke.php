<?php
declare(strict_types=1);
putenv('BRAINPRESS_DSN=sqlite::memory:');
require dirname(__DIR__) . '/src/bootstrap.php';
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); echo "PASS $message\n"; }
try {
    query('INSERT INTO categories (name) VALUES (?)', ['中文分类']);
    $category = (int)db()->lastInsertId();
    $source = "# 原文\n\n**加粗** and `code`\n\n> 引用\n\n- 第一项\n- 第二项\n\n[链接](https://example.com)\n";
    query('INSERT INTO posts(title,markdown,category_id,created_at,updated_at) VALUES (?,?,?,?,?)', ['测试标题', $source, $category, '2026-10-14 12:00:00', '2026-10-14 12:00:00']);
    check(query('SELECT markdown FROM posts')->fetchColumn() === $source, 'Markdown survives database round trip unchanged');
    $html = markdown($source);
    check(str_contains($html, '<h1>原文</h1>') && str_contains($html, '<strong>加粗</strong>') && str_contains($html, '<blockquote>') && str_contains($html, '<ul>'), 'CommonMark blocks and inline syntax render');
    check(!str_contains(markdown('<script>alert(1)</script>'), '<script>'), 'Raw HTML cannot execute');
    check(!str_contains(markdown('[unsafe](javascript:alert%281%29)'), 'href="javascript:'), 'Unsafe URL is blocked');
    check(str_contains(markdown("| a | b |\n|---|---|\n| c | d |"), '<table>'), 'GFM tables render');
    check(str_contains(excerpt($source), '加粗') && !str_contains(excerpt($source), '<strong>'), 'List excerpt is plain text');
    try { query('INSERT INTO posts(title,markdown,category_id,created_at,updated_at) VALUES (?,?,?,?,?)', ['bad','body',999,'now','now']); throw new RuntimeException('Foreign key missing'); } catch (PDOException) { echo "PASS invalid category rejected\n"; }
} finally { putenv('BRAINPRESS_DSN'); }
