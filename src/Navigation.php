<?php
/** Bottom navigation is stored independently from the header menu. */
function tabIcons(): array { return ['home'=>'Home','grid'=>'Categories','plus'=>'Publish','edit'=>'Posts','user'=>'Profile','search'=>'Search','image'=>'Media library','share'=>'Link']; }
function mobileTabs(bool $includeHidden=false): array {
    $stored=setting('mobile_tabs');
    $items=$stored==='' ? [
        ['label'=>t('Home'),'url'=>'/','icon'=>'home','visible'=>true,'prominent'=>false],
        ['label'=>t('Publish'),'url'=>'/?write','icon'=>'plus','visible'=>true,'prominent'=>true],
        ['label'=>t('Categories'),'url'=>'/?categories','icon'=>'grid','visible'=>true,'prominent'=>false],
    ] : (json_decode($stored,true) ?: []);
    return $includeHidden ? $items : array_values(array_filter($items,fn($item)=>$item['visible']??true));
}
function validateTabs(array $items): array {
    if(count($items)>5)throw new InvalidArgumentException('Use up to 5 mobile tabs.');
    $clean=[];$prominent=0;
    foreach($items as $item){
        if(!is_array($item)||!is_string($item['label']??null)||!is_string($item['url']??null)||!is_string($item['icon']??null))throw new InvalidArgumentException('Invalid mobile tab.');
        $label=trim($item['label']);$url=trim($item['url']);
        if($label===''||mb_strlen($label)>20||strlen($url)>1000||!validMenuUrl($url)||!array_key_exists($item['icon'],tabIcons()))throw new InvalidArgumentException('Each tab needs a label, an icon and a local path or HTTP(S) URL.');
        $featured=!empty($item['prominent']);$visible=$item['visible']??true;
        if($featured&&$visible)$prominent++;
        $clean[]=['label'=>$label,'url'=>$url,'icon'=>$item['icon'],'visible'=>(bool)$visible,'prominent'=>$featured];
    }
    if($prominent>1)throw new InvalidArgumentException('Choose only one prominent tab.');
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
