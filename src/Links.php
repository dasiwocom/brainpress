<?php
function validateLink(array $input): array {
    $clean=[];foreach(['name'=>200,'url'=>1000,'description'=>500,'logo_url'=>1000] as $field=>$limit){$value=$input[$field]??'';if(!is_string($value)||mb_strlen($value)>$limit)throw new InvalidArgumentException('Invalid link field.');$clean[$field]=trim($value);}
    if($clean['name']===''||!validMenuUrl($clean['url']))throw new InvalidArgumentException('Enter a link name and a valid local or HTTP(S) URL.');if($clean['logo_url']!==''&&!validMenuUrl($clean['logo_url']))throw new InvalidArgumentException('Use a local path or HTTP(S) URL.');
    $categories=$input['link_categories']??[];if(!is_array($categories)||count($categories)>20)throw new InvalidArgumentException('Choose up to 20 link categories.');$clean['categories']=[];foreach($categories as $value){$id=filter_var($value,FILTER_VALIDATE_INT);if(!$id||!query('SELECT id FROM link_categories WHERE id=?',[$id])->fetchColumn())throw new InvalidArgumentException('Choose an existing link category.');$clean['categories'][]=$id;}$clean['categories']=array_values(array_unique($clean['categories']));return $clean;
}
function linkCategories(int $id): array {return query('SELECT c.* FROM link_categories c JOIN link_category_relations r ON r.category_id=c.id WHERE r.link_id=? ORDER BY c.name',[$id])->fetchAll();}
function linksAction(string $action): void {
    if($action==='link_save'){
        try{$data=validateLink($_POST);}catch(InvalidArgumentException $error){json_response(['error'=>$error->getMessage()],422);}$id=(int)($_POST['id']??0);if($id&&!query('SELECT id FROM links WHERE id=?',[$id])->fetchColumn())json_response(['error'=>'Link not found.'],404);
        $fields=['name','url','description','logo_url'];$values=array_map(fn($field)=>$data[$field],$fields);$now=date('Y-m-d H:i:s');db()->beginTransaction();
        if($id)query('UPDATE links SET '.implode(',',array_map(fn($field)=>"$field=?",$fields)).',updated_at=? WHERE id=?',[...$values,$now,$id]);
        else{
            // Accommodate databases created during the earlier, unshipped draft without exposing those fields.
            $columns=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?query('SHOW COLUMNS FROM links')->fetchAll(PDO::FETCH_COLUMN):array_column(query('PRAGMA table_info(links)')->fetchAll(),'name');
            foreach(['image_url'=>'','rss_url'=>'','notes'=>'','rel'=>'','target'=>'','rating'=>0,'visible'=>1] as $field=>$value)if(in_array($field,$columns,true)){$fields[]=$field;$values[]=$value;}
            query('INSERT INTO links('.implode(',',$fields).',created_at,updated_at) VALUES('.implode(',',array_fill(0,count($values)+2,'?')).')',[...$values,$now,$now]);$id=(int)db()->lastInsertId();
        }
        query('DELETE FROM link_category_relations WHERE link_id=?',[$id]);foreach($data['categories'] as $category)query('INSERT INTO link_category_relations(link_id,category_id) VALUES(?,?)',[$id,$category]);db()->commit();json_response(['ok'=>true,'url'=>'/admin/links']);
    }
    if($action==='link_delete'){$id=(int)($_POST['id']??0);if(!query('SELECT id FROM links WHERE id=?',[$id])->fetchColumn())json_response(['error'=>'Link not found.'],404);db()->beginTransaction();query('DELETE FROM link_category_relations WHERE link_id=?',[$id]);query('DELETE FROM links WHERE id=?',[$id]);db()->commit();json_response(['ok'=>true]);}
    if(in_array($action,['link_category_save','link_category_delete'],true)){
        $id=(int)($_POST['id']??0);if($id&&!query('SELECT id FROM link_categories WHERE id=?',[$id])->fetchColumn())json_response(['error'=>'Link category not found.'],404);
        if($action==='link_category_delete'){if(!$id)json_response(['error'=>'Link category not found.'],404);db()->beginTransaction();query('DELETE FROM link_category_relations WHERE category_id=?',[$id]);query('DELETE FROM link_categories WHERE id=?',[$id]);db()->commit();json_response(['ok'=>true]);}
        $name=$_POST['name']??'';if(!is_string($name)||trim($name)===''||mb_strlen($name)>80)json_response(['error'=>'Use a category name up to 80 characters.'],422);$name=trim($name);if(query('SELECT id FROM link_categories WHERE name=? AND id<>?',[$name,$id])->fetchColumn())json_response(['error'=>'This link category already exists.'],422);
        if($id)query('UPDATE link_categories SET name=? WHERE id=?',[$name,$id]);else query('INSERT INTO link_categories(name,description) VALUES(?,?)',[$name,'']);json_response(['ok'=>true]);
    }
    if($action==='media_list'){
        $search=$_POST['q']??'';if(!is_string($search)||mb_strlen($search)>200)json_response(['error'=>'Invalid search.'],422);mediaLibrary();$page=max(1,(int)($_POST['page']??1));$where=" WHERE status='active'";$params=[];if(trim($search)!==''){$where.=' AND (title LIKE ? OR alt LIKE ? OR filename LIKE ?)';$params=array_fill(0,3,'%'.trim($search).'%');}$total=(int)query('SELECT COUNT(*) FROM media'.$where,$params)->fetchColumn();$page=min($page,max(1,(int)ceil($total/24)));$offset=($page-1)*24;$items=query('SELECT id,filename,title,alt FROM media'.$where." ORDER BY id DESC LIMIT 24 OFFSET $offset",$params)->fetchAll();foreach($items as &$item){$item['url']='/uploads/'.$item['filename'];unset($item['filename']);}unset($item);json_response(['items'=>$items,'page'=>$page,'pages'=>max(1,(int)ceil($total/24)),'total'=>$total]);
    }
}
