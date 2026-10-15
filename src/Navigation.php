<?php
/** Bottom navigation is stored independently from the header menu. */
function tabIcons(): array { return ['home'=>'Home','grid'=>'Categories','plus'=>'Publish','edit'=>'Posts','user'=>'Profile','search'=>'Search','image'=>'Media library','share'=>'Link']; }
function mobileTabs(bool $includeHidden=false): array {
    $stored=setting('mobile_tabs');
    $items=$stored==='' ? [
        ['label'=>t('Home'),'url'=>'/','icon'=>'home','visible'=>true,'prominent'=>false],
        ['label'=>t('Publish'),'url'=>'/?write','icon'=>'edit','visible'=>true,'prominent'=>false,'login_only'=>true],
        ['label'=>t('Categories'),'url'=>'/?categories','icon'=>'grid','visible'=>true,'prominent'=>false],
        ['label'=>t('Account'),'url'=>'/account','icon'=>'user','visible'=>true,'login_only'=>false,'prominent'=>false],
    ] : (json_decode($stored,true) ?: []);
    if(setting('mobile_tabs_account_v1')===''){
        foreach($items as &$item){parse_str(parse_url($item['url']??'',PHP_URL_QUERY)??'',$target);if(array_key_exists('write',$target)&&($item['icon']??'')==='plus')$item['icon']='edit';}unset($item);
        if($stored!==''&&$items&&count($items)<5&&!array_filter($items,fn($item)=>parse_url($item['url']??'',PHP_URL_PATH)==='/account'))$items[]=['label'=>t('Account'),'url'=>'/account','icon'=>'user','visible'=>true,'login_only'=>false,'prominent'=>false];
        if($stored!=='')saveSetting('mobile_tabs',json_encode($items,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));saveSetting('mobile_tabs_account_v1','1');
    }
    foreach($items as &$item){$item['prominent']=false;parse_str(parse_url($item['url']??'',PHP_URL_QUERY)??'',$query);$item['login_only']=!empty($item['login_only'])||array_key_exists('write',$query)||array_key_exists('edit',$query);}unset($item);
    return $includeHidden ? $items : array_values(array_filter($items,fn($item)=>($item['visible']??true)&&(!($item['login_only']??false)||currentUser())));
}
function validateTabs(array $items): array {
    if(count($items)>5)throw new InvalidArgumentException('Use up to 5 mobile tabs.');
    $clean=[];
    foreach($items as $item){
        if(!is_array($item)||!is_string($item['label']??null)||!is_string($item['url']??null)||!is_string($item['icon']??null))throw new InvalidArgumentException('Invalid mobile tab.');
        $label=trim($item['label']);$url=trim($item['url']);
        if($label===''||mb_strlen($label)>20||strlen($url)>1000||!validMenuUrl($url)||!array_key_exists($item['icon'],tabIcons()))throw new InvalidArgumentException('Each tab needs a label, an icon and a local path or HTTP(S) URL.');
        $visible=$item['visible']??true;
        $clean[]=['label'=>$label,'url'=>$url,'icon'=>$item['icon'],'visible'=>(bool)$visible,'prominent'=>false,'login_only'=>!empty($item['login_only'])];
    }
    return $clean;
}
function navigationActive(string $url): bool {
    if(!str_starts_with($url,'/')||str_starts_with($url,'//'))return false;
    $target=parse_url($url);$current=parse_url($_SERVER['REQUEST_URI']??'/');
    if(($target['path']??'/')!==($current['path']??'/'))return false;
    parse_str($target['query']??'',$targetQuery);$currentQuery=$_GET;
    unset($currentQuery['edit_layout'],$currentQuery['page']);
    if(($targetQuery['page_slug']??null)==='home')$targetQuery=[];
    if(($targetQuery['page_slug']??null)==='categories')$targetQuery=['categories'=>''];
    if(($currentQuery['page_slug']??null)==='home')$currentQuery=[];
    if(($currentQuery['page_slug']??null)==='categories')$currentQuery=['categories'=>''];
    ksort($targetQuery);ksort($currentQuery);
    return $targetQuery===$currentQuery;
}
