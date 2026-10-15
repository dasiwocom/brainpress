<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/MathExtension.php';
date_default_timezone_set('Asia/Shanghai');

function db(): PDO {
    static $db;
    if ($db) return $db;
    $root = dirname(__DIR__);
    $config = is_file($root . '/config.php') ? require $root . '/config.php' : [];
    $dsn = getenv('BRAINPRESS_DSN') ?: ($config['dsn'] ?? 'sqlite:' . $root . '/storage/brainpress.sqlite');
    $db = new PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if (!$mysql) { $db->exec('PRAGMA foreign_keys = ON'); $db->exec('PRAGMA busy_timeout = 5000'); }
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $db->exec("CREATE TABLE IF NOT EXISTS categories (id $id, name VARCHAR(80) NOT NULL UNIQUE)$suffix");
    $db->exec("CREATE TABLE IF NOT EXISTS posts (id $id, category_id INT NOT NULL, title VARCHAR(200) NOT NULL, markdown " . ($mysql ? 'MEDIUMTEXT' : 'TEXT') . " NOT NULL, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL, FOREIGN KEY(category_id) REFERENCES categories(id))$suffix");
    $columns = $mysql ? $db->query('SHOW COLUMNS FROM posts')->fetchAll(PDO::FETCH_COLUMN) : array_column($db->query('PRAGMA table_info(posts)')->fetchAll(), 'name');
    if (!in_array('status', $columns, true)) $db->exec("ALTER TABLE posts ADD COLUMN status VARCHAR(12) NOT NULL DEFAULT 'published'");
    if (!in_array('cover_url', $columns, true)) $db->exec("ALTER TABLE posts ADD COLUMN cover_url VARCHAR(1000) NOT NULL DEFAULT ''");
    if (!in_array('previous_status', $columns, true)) $db->exec("ALTER TABLE posts ADD COLUMN previous_status VARCHAR(12) NOT NULL DEFAULT 'published'");
    $db->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(80) PRIMARY KEY, value TEXT NOT NULL)$suffix");
    $db->exec("CREATE TABLE IF NOT EXISTS tags (id $id, name VARCHAR(80) NOT NULL UNIQUE)$suffix");
    $db->exec("CREATE TABLE IF NOT EXISTS post_tags (post_id INT NOT NULL, tag_id INT NOT NULL, PRIMARY KEY(post_id,tag_id), FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE, FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE)$suffix");
    $db->exec("CREATE TABLE IF NOT EXISTS media (id $id, filename VARCHAR(100) NOT NULL UNIQUE, title VARCHAR(200) NOT NULL, alt VARCHAR(500) NOT NULL, created_at VARCHAR(19) NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'active')$suffix");
    $db->exec("CREATE TABLE IF NOT EXISTS pages (id $id, title VARCHAR(200) NOT NULL, slug VARCHAR(80) NOT NULL UNIQUE, status VARCHAR(12) NOT NULL, kind VARCHAR(20) NOT NULL)$suffix");
    foreach ([['Home','home','home'],['Categories','categories','categories']] as $seed) {
        $exists=$db->prepare('SELECT id FROM pages WHERE kind=?');$exists->execute([$seed[2]]);
        if(!$exists->fetchColumn()){$insert=$db->prepare('INSERT INTO pages(title,slug,status,kind) VALUES(?,?,?,?)');$insert->execute([$seed[0],$seed[1],'published',$seed[2]]);}
    }
    $categoryColumns=$mysql?$db->query('SHOW COLUMNS FROM categories')->fetchAll(PDO::FETCH_COLUMN):array_column($db->query('PRAGMA table_info(categories)')->fetchAll(),'name');
    if(!in_array('parent_id',$categoryColumns,true))$db->exec('ALTER TABLE categories ADD COLUMN parent_id INT NULL');
    $db->exec("CREATE TABLE IF NOT EXISTS links (id $id, name VARCHAR(200) NOT NULL, url VARCHAR(1000) NOT NULL, description VARCHAR(500) NOT NULL, logo_url VARCHAR(1000) NOT NULL, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL)$suffix");
    $db->exec("CREATE TABLE IF NOT EXISTS link_categories (id $id, name VARCHAR(80) NOT NULL UNIQUE, description VARCHAR(500) NOT NULL)$suffix");
    $db->exec("CREATE TABLE IF NOT EXISTS link_category_relations (link_id INT NOT NULL,category_id INT NOT NULL,PRIMARY KEY(link_id,category_id),FOREIGN KEY(link_id) REFERENCES links(id) ON DELETE CASCADE,FOREIGN KEY(category_id) REFERENCES link_categories(id) ON DELETE CASCADE)$suffix");
    $linkColumns=$mysql?$db->query('SHOW COLUMNS FROM links')->fetchAll(PDO::FETCH_COLUMN):array_column($db->query('PRAGMA table_info(links)')->fetchAll(),'name');
    if(!in_array('logo_url',$linkColumns,true))$db->exec("ALTER TABLE links ADD COLUMN logo_url VARCHAR(1000) NOT NULL DEFAULT ''");
    return $db;
}
function query(string $sql, array $params = []): PDOStatement {
    $q = db()->prepare($sql); $q->execute($params); return $q;
}
function e(mixed $text): string { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function frontmatter(string $source): array {
    if (!preg_match('/\A(?:\xEF\xBB\xBF)?---[ \t]*\r?\n(.*?)\r?\n(?:---|\.\.\.)[ \t]*(?:\r?\n|$)/s', $source, $match)) return [[], $source];
    $meta = Symfony\Component\Yaml\Yaml::parse($match[1], Symfony\Component\Yaml\Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
    if ($meta !== null && (!is_array($meta) || array_is_list($meta) && $meta !== [])) throw new InvalidArgumentException('Frontmatter must be a YAML mapping.');
    return [$meta ?? [], substr($source, strlen($match[0]))];
}
function postAuthor(string $source): string {
    try { [$meta] = frontmatter($source); return is_scalar($meta['author'] ?? null) ? (string)$meta['author'] : 'Brainpress'; }
    catch (Throwable) { return 'Brainpress'; }
}
function markdown(string $source): string {
    try { [, $source] = frontmatter($source); } catch (Throwable) { /* Invalid legacy YAML remains visible. */ }
    static $converter;
    if (!$converter) {
        $environment = new League\CommonMark\Environment\Environment(['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 50]);
        $environment->addExtension(new League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
        $environment->addExtension(new League\CommonMark\Extension\GithubFlavoredMarkdownExtension());
        $environment->addExtension(new League\CommonMark\Extension\Footnote\FootnoteExtension());
        $environment->addExtension(new Brainpress\MathExtension());
        $converter = new League\CommonMark\MarkdownConverter($environment);
    }
    return (string)$converter->convert($source);
}
function excerpt(string $source): string {
    $html = preg_replace('~<pre\\b[^>]*>[\\s\\S]*?</pre>~', ' ', markdown($source));
    return mb_strimwidth(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'))), 0, 220, '…', 'UTF-8');
}
function json_response(mixed $data, int $status = 200): never {
    if(is_array($data)){foreach(['error','message'] as $key)if(isset($data[$key])&&is_string($data[$key]))$data[$key]=t($data[$key]);}
    http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
}

require __DIR__ . '/Admin.php';

require __DIR__ . '/I18n.php';

require __DIR__.'/Pages.php';

require __DIR__.'/Hierarchy.php';

require __DIR__.'/Navigation.php';

require __DIR__.'/MegaMenu.php';

require __DIR__.'/PostCover.php';

require __DIR__.'/Footer.php';

require __DIR__.'/Links.php';
