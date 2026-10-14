<?php
function pageUrl(array $page): string {return '/?page_slug='.rawurlencode($page['slug']);}
function defaultLayout(string $context): array {
    $main=$context==='categories'?'categories':($context==='article'?null:'posts');
    $items=$main?[['type'=>$main,'zone'=>'main']]:[];
    $items[]=['type'=>'profile','zone'=>'sidebar'];
    if($context==='article')$items[]=['type'=>'toc','zone'=>'sidebar'];
    return $items;
}
function layoutItems(string $context): array {
    $raw=setting('layout:'.$context);
    if($raw==='')return defaultLayout($context);
    $data=json_decode($raw,true);
    if(!is_array($data)||!array_is_list($data))return defaultLayout($context);
    return array_values(array_filter($data,fn($row)=>is_array($row)&&in_array($row['type']??'',['posts','categories','profile','toc'],true)&&in_array($row['zone']??'',['main','sidebar'],true))); 
}
function validLayoutContext(string $context): bool {
    if(in_array($context,['home','article','categories','archive'],true))return true;
    return preg_match('/^page:(\d+)$/',$context,$m) && query('SELECT id FROM pages WHERE id=?',[(int)$m[1]])->fetchColumn();
}
function layoutLink(bool $edit=true): string {
    $params=array_intersect_key($_GET,array_flip(['post','category','tag','q','page_slug','page']));
    if($edit)$params['edit_layout']=1;
    return '/'.($params?'?'.http_build_query($params):'');
}
function pagesAction(string $action): ?array {
    if($action==='page_save') {
        $id=(int)($_POST['id']??0);$title=trim((string)($_POST['title']??''));$slug=trim((string)($_POST['slug']??''));$status=(string)($_POST['status']??'draft');
        if($title===''||mb_strlen($title)>200||!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/',$slug)||!in_array($status,['draft','published'],true))json_response(['error'=>'Enter a title, a lowercase URL slug and a valid status.'],422);
        $existing=$id?query('SELECT * FROM pages WHERE id=?',[$id])->fetch():null;
        if($id&&!$existing)json_response(['error'=>'Page not found'],404);
        if($existing&&$existing['kind']!=='custom'&&$status!=='published')json_response(['error'=>'Built-in pages must stay published.'],422);
        if((int)setting('homepage','0')===$id&&$id&&$status!=='published')json_response(['error'=>'Choose another homepage before unpublishing this page.'],422);
        if(query('SELECT id FROM pages WHERE slug=? AND id<>?',[$slug,$id])->fetchColumn())json_response(['error'=>'This page address is already used.'],422);
        if($id)query('UPDATE pages SET title=?,slug=?,status=? WHERE id=?',[$title,$slug,$status,$id]);
        else {query('INSERT INTO pages(title,slug,status,kind) VALUES(?,?,?,?)',[$title,$slug,$status,'custom']);$id=(int)db()->lastInsertId();}
        json_response(['ok'=>true,'url'=>'/admin/pages']);
    }
    if(in_array($action,['layout_save','layout_preview'],true)) {
        $context=(string)($_POST['context']??'');
        if(!validLayoutContext($context))json_response(['error'=>'Invalid layout context.'],422);
        if(isset($_POST['reset'])){query('DELETE FROM settings WHERE setting_key=?',['layout:'.$context]);json_response(['ok'=>true]);}
        try{$items=json_decode((string)($_POST['widgets']??'[]'),true,32,JSON_THROW_ON_ERROR);}catch(Throwable){json_response(['error'=>'Invalid widgets.'],422);}
        if(!is_array($items)||!array_is_list($items)||count($items)>10)json_response(['error'=>'Use up to 10 widgets.'],422);
        $clean=[];$seen=[];
        foreach($items as $item){
            if(!is_array($item))json_response(['error'=>'Invalid widgets.'],422);
            $type=$item['type']??'';$zone=$item['zone']??'';
            if(!in_array($type,['posts','categories','profile','toc'],true)||!in_array($zone,['main','sidebar'],true))json_response(['error'=>'Invalid widget or region.'],422);
            if(in_array($type,['posts','categories'],true)&&($zone!=='main'||$context==='article'))json_response(['error'=>'Lists belong in the main region outside article pages.'],422);
            if($type==='toc'&&($context!=='article'||$zone!=='sidebar'))json_response(['error'=>'Contents belongs in article sidebars.'],422);
            if(isset($seen[$type]))json_response(['error'=>'Use one instance of each widget in this version.'],422);$seen[$type]=true;
            $row=['type'=>$type,'zone'=>$zone];
            foreach(['name','bio','avatar','cover'] as $field){$value=trim((string)($item[$field]??''));if(mb_strlen($value)>500)json_response(['error'=>'Widget text is too long.'],422);if(in_array($field,['avatar','cover'],true)&&$value!==''&&!validMenuUrl($value))json_response(['error'=>'Use a local path or HTTP(S) URL.'],422);$row[$field]=$value;}
            $clean[]=$row;
        }
        if($action==='layout_preview')return $clean;
        saveSetting('layout:'.$context,json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));json_response(['ok'=>true]);
    }
    return null;
}
function renderWidget(array $widget,array $scope): void {
    extract($scope);$type=$widget['type']??'';
    if(!in_array($type,['posts','categories','profile','toc'],true))return;
    if($type==='toc'&&!$post)return;
    require __DIR__.'/widgets/'.$type.'.php';
}
