<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: http:; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

$requestPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
$isAdmin = $requestPath === '/admin' || str_starts_with($requestPath, '/admin/');
if (!$isAdmin && isset($_GET['manage'])) { header('Location: /admin/posts'); exit; }
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) json_response(['error' => 'This page expired. Refresh and try again.'], 403);
        $action = $_POST['action'] ?? '';
        $previewLayout=pagesAction($action);
        adminAction($action);
        if (in_array($action, ['trash', 'restore'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            $item = query('SELECT status,previous_status FROM posts WHERE id=?', [$id])->fetch();
            if (!$item) json_response(['error' => 'Post not found.'], 404);
            if ($action === 'trash' && $item['status'] !== 'trash') query("UPDATE posts SET previous_status=status,status='trash',updated_at=? WHERE id=?", [date('Y-m-d H:i:s'),$id]);
            if ($action === 'restore' && $item['status'] === 'trash') query('UPDATE posts SET status=previous_status,updated_at=? WHERE id=?', [date('Y-m-d H:i:s'),$id]);
            json_response(['ok' => true]);
        }
        if (in_array($action, ['category_rename','category_delete'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            if (!query('SELECT id FROM categories WHERE id=?', [$id])->fetch()) json_response(['error' => 'Category not found.'],404);
            if ($action === 'category_rename') {
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '' || mb_strlen($name)>40) json_response(['error'=>'Use 1–40 characters.'],422);
                if (query('SELECT id FROM categories WHERE name=? AND id<>?',[$name,$id])->fetch()) json_response(['error'=>'Category name already exists.'],422);
                try{$parent=categoryParent($id,(int)($_POST['parent_id']??0));}catch(InvalidArgumentException $error){json_response(['error'=>$error->getMessage()],422);}
                query('UPDATE categories SET name=?,parent_id=? WHERE id=?',[$name,$parent,$id]);
            } else {
                $target = (int)($_POST['target'] ?? 0);
                $count = (int)query('SELECT COUNT(*) FROM posts WHERE category_id=?',[$id])->fetchColumn();
                if ($count && ($target === $id || !query('SELECT id FROM categories WHERE id=?',[$target])->fetch())) json_response(['error'=>'Choose another category to receive all posts, including drafts and trash.'],422);
                db()->beginTransaction();
                try { if ($count) query('UPDATE posts SET category_id=? WHERE category_id=?',[$target,$id]); query('UPDATE categories SET parent_id=NULL WHERE parent_id=?',[$id]); query('DELETE FROM categories WHERE id=?',[$id]); db()->commit(); }
                catch (Throwable $error) { db()->rollBack(); throw $error; }
            }
            json_response(['ok'=>true]);
        }
        if ($action === 'preview') json_response(['html' => markdown(mb_substr((string)($_POST['markdown'] ?? ''), 0, 200000))]);
        if ($action === 'category') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 40) json_response(['error' => 'Use 1–40 characters for the category name.'], 422);
            $existing = query('SELECT id FROM categories WHERE name = ?', [$name])->fetchColumn();
            if ($existing) json_response(['error' => 'This category already exists.'], 422);
            try{$parent=categoryParent(0,(int)($_POST['parent_id']??0));}catch(InvalidArgumentException $error){json_response(['error'=>$error->getMessage()],422);}
            query('INSERT INTO categories (name,parent_id) VALUES (?,?)', [$name,$parent]);
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
            query('INSERT INTO media(filename,title,alt,created_at,status) VALUES(?,?,?,?,?)', [$name,mb_substr(pathinfo(basename($file['name']),PATHINFO_FILENAME),0,200),'',date('Y-m-d H:i:s'),'active']);
            json_response(['url' => '/uploads/' . $name]);
        }
        if ($action === 'save') {
            $raw = (string)($_POST['markdown'] ?? '');
            try { [$meta] = frontmatter($raw); } catch (Throwable $error) { json_response(['error' => 'Invalid YAML Frontmatter: ' . $error->getMessage()], 422); }
            $status = (string)($_POST['status'] ?? (($meta['draft'] ?? false) === true ? 'draft' : 'published'));
            if (!in_array($status, ['draft', 'published'], true)) json_response(['error' => 'Choose Draft or Published.'], 422);
            if (isset($meta['draft']) && !is_bool($meta['draft'])) json_response(['error' => 'Frontmatter draft must be true or false.'], 422);
            if (array_key_exists('draft', $meta) && isset($_POST['status']) && $meta['draft'] !== ($status === 'draft')) {
                [, $body] = frontmatter($raw);
                $meta['draft'] = $status === 'draft';
                $raw = "---\n" . Symfony\Component\Yaml\Yaml::dump($meta, 10, 2) . "---\n" . $body;
            }
            if (isset($meta['title']) && !is_scalar($meta['title'])) json_response(['error' => 'Frontmatter title must be text.'], 422);
            if (isset($meta['author']) && (!is_string($meta['author']) || mb_strlen($meta['author']) > 120)) json_response(['error' => 'Frontmatter author must be text up to 120 characters.'], 422);
            $date = null;
            if (isset($meta['date'])) {
                try {
                    $value = $meta['date'];
                    if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException();
                    if (is_string($value) && !preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ].*)?$/', $value)) throw new InvalidArgumentException();
                    $parsed = is_int($value) ? (new DateTimeImmutable())->setTimestamp($value) : new DateTimeImmutable($value);
                    $errors = DateTimeImmutable::getLastErrors();
                    if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException();
                    $date = $parsed->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
                } catch (Throwable) { json_response(['error' => 'Use an ISO date in Frontmatter, for example 2026-10-14.'], 422); }
            }
            $title = trim((string)($_POST['title'] ?? ($meta['title'] ?? '')));
            $source = $raw;
            $category = (int)($_POST['category_id'] ?? 0);
            if ($title === '' || mb_strlen($title) > 200 || trim($source) === '' || strlen($source) > 500000) json_response(['error' => 'Enter a title and body. Maximum: 200 title characters and 500KB of Markdown.'], 422);
            if (!query('SELECT id FROM categories WHERE id = ?', [$category])->fetch()) json_response(['error' => 'Select a valid category.'], 422);
            try {
                $tagInput = $_POST['tags'] ?? ($meta['tags'] ?? []);
                if (is_array($tagInput)) { foreach ($tagInput as $tagValue) if (!is_string($tagValue)) throw new InvalidArgumentException('Frontmatter tags must be text.'); $tagInput = implode(',', $tagInput); }
                if (!is_string($tagInput)) throw new InvalidArgumentException('Use comma-separated tag names.');
                $tagNames = tagNames($tagInput);
            } catch (InvalidArgumentException $error) { json_response(['error'=>$error->getMessage()],422); }
            $id = (int)($_POST['id'] ?? 0); $now = date('Y-m-d H:i:s');
            if ($id && !query("SELECT id FROM posts WHERE id=? AND status<>'trash'",[$id])->fetch()) json_response(['error'=>'Post not found.'],404);
            db()->beginTransaction();
            if ($id) {
                if (!query("SELECT id FROM posts WHERE id = ? AND status<>'trash'", [$id])->fetch()) json_response(['error' => 'Post not found.'], 404);
                query('UPDATE posts SET title=?, markdown=?, category_id=?, updated_at=?, status=?, created_at=COALESCE(?,created_at) WHERE id=?', [$title, $source, $category, $now, $status, $date, $id]);
            } else {
                query('INSERT INTO posts (title,markdown,category_id,created_at,updated_at,status) VALUES (?,?,?,?,?,?)', [$title, $source, $category, $date ?? $now, $now, $status]);
                $id = (int)db()->lastInsertId();
            }
            savePostTags($id,$tagNames);
            db()->commit();
            json_response(['url' => isset($_POST['admin_editor']) ? '/admin/posts?edit=' . $id : ($status === 'draft' ? '/?edit=' : '/?post=') . $id]);
        }
        if($previewLayout===null)json_response(['error' => 'Unknown action.'], 400);
        parse_str(ltrim((string)($_POST['route']??''),'?'),$previewRoute);
        $_GET=array_intersect_key($previewRoute,array_flip(['post','category','tag','q','page_slug','page'])); $_GET['edit_layout']=1;
    }

    $customPage=null;$layoutContext='home';
    if(isset($_GET['view'])){http_response_code(404);exit(e(t('Page not found')));}
    if(!$isAdmin){
        if(isset($_GET['page_slug'])){
            $customPage=query("SELECT * FROM pages WHERE slug=?" . (isset($_GET['edit_layout'])?'':" AND status='published'"),[(string)$_GET['page_slug']])->fetch();
            if(!$customPage){http_response_code(404);exit(e(t('Page not found')));}
        } elseif(!array_intersect(['post','edit','write','categories','category','tag','q','drafts'],array_keys($_GET))){
            $home=(int)setting('homepage','0');
            if($home)$customPage=query("SELECT * FROM pages WHERE id=? AND status='published'",[$home])->fetch();
        }
        if($customPage){$layoutContext=$customPage['kind']==='custom'?'page:'.$customPage['id']:$customPage['kind'];if($layoutContext==='categories')$_GET['categories']='';}
        if(isset($_GET['post']))$layoutContext='article';
        elseif(isset($_GET['categories']))$layoutContext='categories';
        elseif(array_intersect(['category','tag','q','drafts'],array_keys($_GET)))$layoutContext='archive';
    }
    $manageView = false;
    $managedPosts = $manageView ? query('SELECT p.*,c.name AS category_name FROM posts p JOIN categories c ON c.id=p.category_id ORDER BY p.updated_at DESC,p.id DESC')->fetchAll() : [];
    $categories = query('SELECT c.id,c.name,c.parent_id,COUNT(p.id) AS total FROM categories c LEFT JOIN posts p ON p.category_id=c.id AND p.status=\'published\' GROUP BY c.id,c.name,c.parent_id ORDER BY c.id')->fetchAll();
    $total = (int)query("SELECT COUNT(*) FROM posts WHERE status='published'")->fetchColumn();
    $categoryId = (int)($_GET['category'] ?? 0);
    $categoryName = 'All posts';
    foreach ($categories as $c) if ((int)$c['id'] === $categoryId) $categoryName = $c['name'];
    $post = null; $missing = false;
    if (isset($_GET['post']) || isset($_GET['edit'])) {
        $post = query('SELECT p.*,c.name AS category_name FROM posts p JOIN categories c ON c.id=p.category_id WHERE p.id=?', [(int)($_GET['post'] ?? $_GET['edit'])])->fetch();
        if ($post && $post['status'] === 'trash') $post = false;
        if ($post && isset($_GET['post']) && $post['status'] !== 'published') $post = false;
        if (!$post) { http_response_code(404); $missing = true; }
    }
    if ($post) $post['tags_text'] = implode(', ',array_column(postTags((int)$post['id']),'name'));
    $editor = isset($_GET['write']) || (isset($_GET['edit']) && !$missing);
    $page = max(1, (int)($_GET['page'] ?? 1)); $limit = 12; $offset = ($page - 1) * $limit;
    $draftView = isset($_GET['drafts']);
    $where = ' WHERE p.status=?'; $params = [$draftView ? 'draft' : 'published'];
    if ($categoryId) { $where .= ' AND p.category_id=?'; $params[] = $categoryId; }
    $tagId = max(0,(int)($_GET['tag']??0));
    $tagName = $tagId ? query('SELECT name FROM tags WHERE id=?',[$tagId])->fetchColumn() : '';
    if($tagId){$where .= ' AND EXISTS (SELECT 1 FROM post_tags pt WHERE pt.post_id=p.id AND pt.tag_id=?)';$params[]=$tagId;}
    $search = trim((string)($_GET['q'] ?? ''));
    if ($search !== '') { $where .= ($where ? ' AND ' : ' WHERE ') . '(p.title LIKE ? OR p.markdown LIKE ?)'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }
    $filteredTotal = (int)query('SELECT COUNT(*) FROM posts p' . $where, $params)->fetchColumn();
    $posts = (!$post && !$editor && !$missing) ? query('SELECT p.*,c.name AS category_name FROM posts p JOIN categories c ON c.id=p.category_id' . $where . " ORDER BY p.created_at DESC,p.id DESC LIMIT $limit OFFSET $offset", $params)->fetchAll() : [];
} catch (Throwable $error) {
    try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable) {}
    error_log((string)$error);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') json_response(['error' => 'Could not complete the action. Check database and directory permissions.'], 500);
    http_response_code(500); exit('Brainpress cannot read the database. Check your configuration.');
}
require dirname(__DIR__) . '/src/components.php';
$layout=layoutItems($layoutContext);$scope=get_defined_vars();
if(isset($previewLayout)&&$previewLayout!==null){
    if(($_POST['context']??'')!==$layoutContext)json_response(['error'=>'Layout context changed.'],422);
    $out=['main'=>'','sidebar'=>''];foreach(['main','sidebar'] as $zone){ob_start();foreach($previewLayout as $widget)if($widget['zone']===$zone)renderWidget($widget,$scope);$out[$zone]=ob_get_clean();}json_response($out);
}
if($isAdmin) require dirname(__DIR__) . '/src/admin-view.php';
else require dirname(__DIR__) . '/src/view.php';
