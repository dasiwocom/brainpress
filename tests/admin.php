<?php
putenv('BRAINPRESS_DSN=sqlite::memory:');
require dirname(__DIR__).'/src/bootstrap.php';
function verifyAdmin(bool $ok,string $name): void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
verifyAdmin(validMenuUrl('/?category=1') && validMenuUrl('https://example.com/notes'), 'Menu accepts local and HTTP(S) links');
foreach(['javascript:alert(1)','//example.com','/\\example.com',"/path\nheader",'data:text/html,test'] as $url)verifyAdmin(!validMenuUrl($url),'Unsafe menu URL rejected');
saveSetting('site_name','First');saveSetting('site_name','Second');verifyAdmin(setting('site_name')==='Second','Settings update without duplicate rows');
saveSetting('top_menu',json_encode([['label'=>'Shown','url'=>'/','visible'=>true],['label'=>'Hidden','url'=>'/?categories','visible'=>false]]));
verifyAdmin(count(topMenu())===1 && count(topMenu(true))===2,'Hidden menu entries survive editing');
query('INSERT INTO categories(name) VALUES(?)',['Notes']);$category=(int)db()->lastInsertId();
query('INSERT INTO posts(category_id,title,markdown,created_at,updated_at) VALUES(?,?,?,?,?)',[$category,'A post','# Note','2026-10-14 00:00:00','2026-10-14 00:00:00']);$post=(int)db()->lastInsertId();
savePostTags($post,tagNames('notes, notes, 中文'));verifyAdmin(count(postTags($post))===2,'Tags are distinct and support Unicode');
savePostTags($post,tagNames('new'));verifyAdmin(array_column(postTags($post),'name')===['new'],'Updating tags replaces relationships without removing posts');
try{tagNames(implode(',',range(1,21)));throw new RuntimeException('Tag limit ignored');}catch(InvalidArgumentException){echo "PASS Tag limit enforced\n";}
