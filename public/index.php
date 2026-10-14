<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: http:; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) json_response(['error' => 'This page expired. Refresh and try again.'], 403);
        $action = $_POST['action'] ?? '';
        if ($action === 'preview') json_response(['html' => markdown(mb_substr((string)($_POST['markdown'] ?? ''), 0, 200000))]);
        if ($action === 'category') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 40) json_response(['error' => 'Use 1–40 characters for the category name.'], 422);
            $existing = query('SELECT id FROM categories WHERE name = ?', [$name])->fetchColumn();
            if ($existing) json_response(['error' => 'This category already exists.'], 422);
            query('INSERT INTO categories (name) VALUES (?)', [$name]);
            json_response(['id' => (int)db()->lastInsertId(), 'name' => $name]);
        }
        if ($action === 'upload') {
            $file = $_FILES['image'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) json_response(['error' => 'Choose an image up to 5MB.'], 422);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? null;
            if (!$ext || !getimagesize($file['tmp_name'])) json_response(['error' => 'Supported images: JPG, PNG, GIF and WebP.'], 422);
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/uploads/' . $name)) throw new RuntimeException('Upload failed');
            json_response(['url' => '/uploads/' . $name]);
        }
        if ($action === 'save') {
            $title = trim((string)($_POST['title'] ?? ''));
            $source = (string)($_POST['markdown'] ?? '');
            $category = (int)($_POST['category_id'] ?? 0);
            if ($title === '' || mb_strlen($title) > 200 || trim($source) === '' || strlen($source) > 500000) json_response(['error' => 'Enter a title and body. Maximum: 200 title characters and 500KB of Markdown.'], 422);
            if (!query('SELECT id FROM categories WHERE id = ?', [$category])->fetch()) json_response(['error' => 'Select a valid category.'], 422);
            $id = (int)($_POST['id'] ?? 0); $now = date('Y-m-d H:i:s');
            if ($id) {
                if (!query('SELECT id FROM posts WHERE id = ?', [$id])->fetch()) json_response(['error' => 'Post not found.'], 404);
                query('UPDATE posts SET title=?, markdown=?, category_id=?, updated_at=? WHERE id=?', [$title, $source, $category, $now, $id]);
            } else {
                query('INSERT INTO posts (title,markdown,category_id,created_at,updated_at) VALUES (?,?,?,?,?)', [$title, $source, $category, $now, $now]);
                $id = (int)db()->lastInsertId();
            }
            json_response(['url' => '/?post=' . $id]);
        }
        json_response(['error' => 'Unknown action.'], 400);
    }

    $categories = query('SELECT c.id,c.name,COUNT(p.id) AS total FROM categories c LEFT JOIN posts p ON p.category_id=c.id GROUP BY c.id,c.name ORDER BY c.id')->fetchAll();
    $total = (int)query('SELECT COUNT(*) FROM posts')->fetchColumn();
    $categoryId = (int)($_GET['category'] ?? 0);
    $categoryName = 'All posts';
    foreach ($categories as $c) if ((int)$c['id'] === $categoryId) $categoryName = $c['name'];
    $post = null; $missing = false;
    if (isset($_GET['post']) || isset($_GET['edit'])) {
        $post = query('SELECT p.*,c.name AS category_name FROM posts p JOIN categories c ON c.id=p.category_id WHERE p.id=?', [(int)($_GET['post'] ?? $_GET['edit'])])->fetch();
        if (!$post) { http_response_code(404); $missing = true; }
    }
    $editor = isset($_GET['write']) || (isset($_GET['edit']) && !$missing);
    $page = max(1, (int)($_GET['page'] ?? 1)); $limit = 12; $offset = ($page - 1) * $limit;
    $where = $categoryId ? ' WHERE p.category_id=?' : ''; $params = $categoryId ? [$categoryId] : [];
    $search = trim((string)($_GET['q'] ?? ''));
    if ($search !== '') { $where .= ($where ? ' AND ' : ' WHERE ') . '(p.title LIKE ? OR p.markdown LIKE ?)'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }
    $filteredTotal = (int)query('SELECT COUNT(*) FROM posts p' . $where, $params)->fetchColumn();
    $posts = (!$post && !$editor && !$missing) ? query('SELECT p.*,c.name AS category_name FROM posts p JOIN categories c ON c.id=p.category_id' . $where . " ORDER BY p.created_at DESC,p.id DESC LIMIT $limit OFFSET $offset", $params)->fetchAll() : [];
} catch (Throwable $error) {
    error_log((string)$error);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') json_response(['error' => 'Could not complete the action. Check database and directory permissions.'], 500);
    http_response_code(500); exit('Brainpress cannot read the database. Check your configuration.');
}
require dirname(__DIR__) . '/src/view.php';
