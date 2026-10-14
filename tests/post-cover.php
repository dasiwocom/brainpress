<?php
putenv('BRAINPRESS_DSN=sqlite::memory:');require dirname(__DIR__).'/src/bootstrap.php';
function checkCover(bool $ok,string $name): void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
checkCover(in_array('cover_url',array_column(db()->query('PRAGMA table_info(posts)')->fetchAll(),'name'),true),'Cover metadata column exists');
foreach(['','/uploads/example.png','https://example.com/cover.png','http://example.com/a.jpg?size=2'] as $url)checkCover(validCoverUrl($url),'Local and HTTP(S) cover URLs accepted');
foreach(['javascript:alert(1)','//example.com/a.png','data:image/png,test',"/a\nb",'/\\example.com',str_repeat('x',1001)] as $url)checkCover(!validCoverUrl($url),'Invalid cover URL rejected');
$source="# Original\n\n![Body](https://example.com/body.png)\n";
checkCover(postCover(['cover_url'=>'https://example.com/cover.png','markdown'=>$source])==='https://example.com/cover.png','Explicit cover takes precedence');
checkCover(postCover(['cover_url'=>'','markdown'=>$source])==='https://example.com/body.png','Empty cover falls back to body image');
checkCover(postCover(['markdown'=>'![Body](</uploads/example.png>)'])==='/uploads/example.png','Angle-bracket body image is supported');
checkCover(postCover(['markdown'=>'No image'])==='','Text-only posts have no cover');
checkCover(coverDimensions('https://example.com/image.png')===null,'Remote images are never probed by the server');
query('INSERT INTO categories(name) VALUES(?)',['Cover tests']);$category=(int)db()->lastInsertId();
query('INSERT INTO posts(title,markdown,category_id,created_at,updated_at,cover_url) VALUES(?,?,?,?,?,?)',['Title',$source,$category,'2026-10-14 00:00:00','2026-10-14 00:00:00','https://example.com/cover.png']);
$post=query('SELECT * FROM posts')->fetch();checkCover($post['markdown']===$source&&$post['cover_url']==='https://example.com/cover.png','Cover persists independently without changing Markdown');
