<?php
putenv('BRAINPRESS_DSN=sqlite::memory:');require dirname(__DIR__).'/src/bootstrap.php';
function linkCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
query('INSERT INTO link_categories(name,description) VALUES(?,?)',['Docs','']);$id=(int)db()->lastInsertId();$row=validateLink(['name'=>'Example','url'=>'https://example.com','description'=>'A site','logo_url'=>'/assets/site-logo.svg','link_categories'=>[$id,$id]]);linkCheck($row['categories']===[$id],'Link categories are deduplicated');linkCheck(array_keys($row)===['name','url','description','logo_url','categories'],'Only the requested link fields are accepted');
foreach(['url','logo_url'] as $field)foreach(['javascript:alert(1)','//evil.test','data:image/svg+xml,test'] as $url){try{validateLink(['name'=>'Bad','url'=>'/valid',$field=>$url]);throw new RuntimeException('Unsafe URL accepted');}catch(InvalidArgumentException){echo "PASS Unsafe link or logo URL rejected\n";}}
try{validateLink(['name'=>'Bad','url'=>'/','link_categories'=>[999]]);throw new RuntimeException('Unknown category accepted');}catch(InvalidArgumentException){echo "PASS Missing link category rejected\n";}
linkCheck(validateLink(['name'=>'Example','url'=>'/','logo_url'=>''])['logo_url']==='','Logo is optional');
