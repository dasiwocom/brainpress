<?php
function layoutZones(): array { return ['top_full'=>'Top full width','main_top'=>'Main content top','main'=>'Main content','main_bottom'=>'Main content bottom','sidebar'=>'Sidebar','bottom_full'=>'Bottom full width','footer'=>'Footer']; }
function widgetTypes(): array { return ['posts'=>'Post list','categories'=>'Category list','profile'=>'Profile card','toc'=>'Contents']; }
function pageUrl(array $page): string {return '/?page_slug='.rawurlencode($page['slug']);}
function defaultLayout(string $context): array {
    $main=$context==='categories'?'categories':($context==='article'?null:'posts');
    $items=$main?[['type'=>$main,'zone'=>'main']]:[];
    $items[]=['type'=>'profile','zone'=>'sidebar'];
    if($context==='article')$items[]=['type'=>'toc','zone'=>'sidebar'];
    return $items;
}
function layoutItems(string $context): array {
    $raw=setting('layout:'.$context);if($raw==='')return defaultLayout($context);
    $data=json_decode($raw,true);if(!is_array($data)||!array_is_list($data))return defaultLayout($context);
    return array_values(array_filter($data,fn($row)=>is_array($row)&&isset(widgetTypes()[$row['type']??''])&&isset(layoutZones()[$row['zone']??''])));
}
function validLayoutContext(string $context): bool {
    if(in_array($context,['home','article','categories','archive'],true))return true;
    return preg_match('/^page:(\d+)$/',$context,$m) && query('SELECT id FROM pages WHERE id=?',[(int)$m[1]])->fetchColumn();
}
function layoutLink(bool $edit=true): string {
    $params=array_intersect_key($_GET,array_flip(['post','category','tag','q','page_slug','page']));if($edit)$params['edit_layout']=1;
    return '/'.($params?'?'.http_build_query($params):'');
}
function validateLayout(array $items,string $context): array {
    if(!array_is_list($items)||count($items)>30)throw new InvalidArgumentException('Use up to 30 widgets.');
    $clean=[];$seen=[];
    foreach($items as $item){
        if(!is_array($item))throw new InvalidArgumentException('Invalid widgets.');
        $type=$item['type']??'';$zone=$item['zone']??'';
        if(!is_string($type)||!is_string($zone)||!isset(widgetTypes()[$type])||!isset(layoutZones()[$zone]))throw new InvalidArgumentException('Invalid widget or region.');
        if(in_array($type,['posts','categories'],true)&&($zone==='sidebar'||$context==='article'))throw new InvalidArgumentException('Lists belong outside article pages and sidebars.');
        if($type==='toc'&&($context!=='article'||$zone!=='sidebar'))throw new InvalidArgumentException('Contents belongs in article sidebars.');
        if(in_array($type,['posts','categories','toc'],true)&&isset($seen[$type]))throw new InvalidArgumentException('Use one instance of each list or contents widget.');$seen[$type]=true;
        $row=['type'=>$type,'zone'=>$zone];
        foreach(['name','bio','avatar','cover'] as $field){
            if(!is_string($item[$field]??''))throw new InvalidArgumentException('Invalid widget text.');
            $value=trim($item[$field]??'');
            if(mb_strlen($value)>500)throw new InvalidArgumentException('Widget text is too long.');
            if(in_array($field,['avatar','cover'],true)&&$value!==''&&!validMenuUrl($value))throw new InvalidArgumentException('Use a local path or HTTP(S) URL.');$row[$field]=$value;
        }
        $clean[]=$row;
    }
    return $clean;
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
        $context=(string)($_POST['context']??'');if(!validLayoutContext($context))json_response(['error'=>'Invalid layout context.'],422);
        if(isset($_POST['reset'])){query('DELETE FROM settings WHERE setting_key=?',['layout:'.$context]);json_response(['ok'=>true]);}
        try{$items=json_decode((string)($_POST['widgets']??'[]'),true,32,JSON_THROW_ON_ERROR);if(!is_array($items))throw new InvalidArgumentException('Invalid widgets.');$clean=validateLayout($items,$context);}catch(Throwable $error){json_response(['error'=>$error instanceof InvalidArgumentException?$error->getMessage():'Invalid widgets.'],422);}
        if($action==='layout_preview')return $clean;
        saveSetting('layout:'.$context,json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));json_response(['ok'=>true]);
    }
    return null;
}
function renderWidget(array $widget,array $scope): void {
    extract($scope);$type=$widget['type']??'';if(!isset(widgetTypes()[$type]))return;if($type==='toc'&&!$post)return;
    require __DIR__.'/widgets/'.$type.'.php';
}
function renderRegion(string $zone,array $layout,array $scope): void {foreach($layout as $widget)if($widget['zone']===$zone)renderWidget($widget,$scope);}
