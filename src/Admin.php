<?php
declare(strict_types=1);

function setting(string $key, string $default = ''): string {
    $value = query('SELECT value FROM settings WHERE setting_key=?', [$key])->fetchColumn();
    return $value === false ? $default : (string)$value;
}
function saveSetting(string $key, string $value): void {
    if (query('SELECT setting_key FROM settings WHERE setting_key=?', [$key])->fetchColumn() !== false) query('UPDATE settings SET value=? WHERE setting_key=?', [$value,$key]);
    else query('INSERT INTO settings(setting_key,value) VALUES(?,?)', [$key,$value]);
}
function topMenu(bool $includeHidden = false): array {
    $value = setting('top_menu');
    $items = $value === '' ? [['label'=>'Home','url'=>'/'],['label'=>'AI chat','url'=>'/?view=ai'],['label'=>'Messages','url'=>'/?view=messages'],['label'=>'Profile','url'=>'/?view=profile']] : (json_decode($value,true) ?: []);
    return $includeHidden ? $items : array_values(array_filter($items,fn($item)=>$item['visible']??true));
}
function validMenuUrl(string $url): bool {
    if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return false;
    return (str_starts_with($url,'/') && !str_starts_with($url,'//')) || (filter_var($url,FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url,PHP_URL_SCHEME) ?: ''), ['http','https'],true));
}
function tagNames(string $value): array {
    $names = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,，]/u', $value)))));
    if (count($names)>20) throw new InvalidArgumentException('Use up to 20 tags.');
    foreach($names as $name) if (mb_strlen($name)>40) throw new InvalidArgumentException('Tag names must be up to 40 characters.');
    return $names;
}
function savePostTags(int $postId, array $names): void {
    query('DELETE FROM post_tags WHERE post_id=?', [$postId]);
    foreach($names as $name) {
        $tagId=query('SELECT id FROM tags WHERE name=?',[$name])->fetchColumn();
        if (!$tagId) { query('INSERT INTO tags(name) VALUES(?)',[$name]); $tagId=db()->lastInsertId(); }
        if (!query('SELECT tag_id FROM post_tags WHERE post_id=? AND tag_id=?',[$postId,$tagId])->fetchColumn()) query('INSERT INTO post_tags(post_id,tag_id) VALUES(?,?)',[$postId,$tagId]);
    }
}
function postTags(int $id): array { return query('SELECT t.* FROM tags t JOIN post_tags pt ON pt.tag_id=t.id WHERE pt.post_id=? ORDER BY t.name',[$id])->fetchAll(); }
function mediaLibrary(string $status='active'): array {
    // Adopt pre-library uploads without moving or renaming the files.
    $root=dirname(__DIR__).'/public/uploads/';
    foreach(glob($root.'*') ?: [] as $file) {
        $name=basename($file);
        if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|gif|webp)$/',$name) || !is_file($file)) continue;
        if (!query('SELECT id FROM media WHERE filename=?',[$name])->fetchColumn()) query('INSERT INTO media(filename,title,alt,created_at,status) VALUES(?,?,?,?,?)',[$name,$name,'',date('Y-m-d H:i:s',filemtime($file)),'active']);
    }
    return query('SELECT * FROM media WHERE status=? ORDER BY id DESC',[$status])->fetchAll();
}
function adminAction(string $action): bool {
    if ($action==='settings_save') {
        $name=trim((string)($_POST['site_name']??'')); $description=trim((string)($_POST['description']??''));
        if ($name==='' || mb_strlen($name)>60 || mb_strlen($description)>200) json_response(['error'=>'Use a site name up to 60 characters and a description up to 200.'],422);
        $logo=trim((string)($_POST['logo_url']??setting('logo_url','/assets/site-logo.svg')));
        if($logo!=='' && (!validMenuUrl($logo)||strlen($logo)>1000)) json_response(['error'=>'Use a local path or HTTP(S) URL for the logo.'],422);
        db()->beginTransaction();saveSetting('logo_url',$logo);saveSetting('site_name',$name);saveSetting('description',$description);db()->commit();
        json_response(['ok'=>true,'message'=>'Settings saved.']);
    }
    if ($action==='menu_save') {
        $labels=$_POST['label']??[]; $urls=$_POST['url']??[]; $enabled=$_POST['enabled']??[];
        if (!is_array($labels)||!is_array($urls)||!is_array($enabled)||count($labels)>12) json_response(['error'=>'Use up to 12 menu items.'],422);
        $items=[];
        foreach($labels as $i=>$label) {
            if (!is_string($label)||!is_string($urls[$i]??null)) json_response(['error'=>'Invalid menu item.'],422);
            $label=trim($label);$url=trim($urls[$i]);
            if ($label==='' && $url==='') continue;
            if ($label==='' || mb_strlen($label)>40 || strlen($url)>1000 || !validMenuUrl($url)) json_response(['error'=>'Each item needs a label and a local path or HTTP(S) URL.'],422);
            $items[]=['label'=>$label,'url'=>$url,'visible'=>in_array((string)$i,$enabled,true)];
        }
        saveSetting('top_menu',json_encode($items,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        json_response(['ok'=>true,'message'=>'Menu saved.']);
    }
    if (in_array($action,['tag_create','tag_rename','tag_delete'],true)) {
        $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));
        if ($action!=='tag_create' && !query('SELECT id FROM tags WHERE id=?',[$id])->fetch()) json_response(['error'=>'Tag not found.'],404);
        if ($action==='tag_delete') { db()->beginTransaction(); query('DELETE FROM post_tags WHERE tag_id=?',[$id]);query('DELETE FROM tags WHERE id=?',[$id]);db()->commit(); }
        else {
            if ($name===''||mb_strlen($name)>40) json_response(['error'=>'Use 1–40 characters for a tag.'],422);
            if(query('SELECT id FROM tags WHERE name=? AND id<>?',[$name,$id])->fetch()) json_response(['error'=>'This tag already exists.'],422);
            if($action==='tag_create')query('INSERT INTO tags(name) VALUES(?)',[$name]);else query('UPDATE tags SET name=? WHERE id=?',[$name,$id]);
        }
        json_response(['ok'=>true]);
    }
    if (in_array($action,['media_update','media_trash','media_restore'],true)) {
        $id=(int)($_POST['id']??0);
        if(!query('SELECT id FROM media WHERE id=?',[$id])->fetch())json_response(['error'=>'Image not found.'],404);
        if($action==='media_update') {
            $title=trim((string)($_POST['title']??''));$alt=trim((string)($_POST['alt']??''));
            if(mb_strlen($title)>200||mb_strlen($alt)>500)json_response(['error'=>'Title: up to 200 characters; alternative text: up to 500.'],422);
            query('UPDATE media SET title=?,alt=? WHERE id=?',[$title,$alt,$id]);
        } else query('UPDATE media SET status=? WHERE id=?',[$action==='media_trash'?'trash':'active',$id]);
        json_response(['ok'=>true]);
    }
    return false;
}

function brandLogo(string $name): string {
    $url=setting('logo_url','/assets/site-logo.svg');
    return $url==='' ? e($name) : '<img class="site-logo" src="'.e($url).'" alt="'.e($name).'">';
}
