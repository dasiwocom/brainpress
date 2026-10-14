<?php
function validateMenuContent(mixed $entries): array {
    if(!is_array($entries)||!array_is_list($entries)||count($entries)>20)throw new InvalidArgumentException('Use up to 20 text items per submenu.');
    $clean=[];foreach($entries as $entry){
        if(!is_array($entry)||!is_string($entry['label']??null)||!is_string($entry['url']??null))throw new InvalidArgumentException('Invalid submenu text.');
        $label=trim($entry['label']);$url=trim($entry['url']);if($label===''&&$url==='')continue;
        if($label===''||mb_strlen($label)>120||strlen($url)>1000||($url!==''&&!validMenuUrl($url)))throw new InvalidArgumentException('Text needs a label and an optional valid URL.');
        $clean[]=['label'=>$label,'url'=>$url];
    }
    return $clean;
}
function menuGroups(): array {
    $items=topMenu();$roots=[];
    foreach($items as $item)if(($item['parent']??'')===''){$item['children']=array_values(array_filter($items,fn($child)=>($child['parent']??'')===$item['id']));$roots[]=$item;}
    return $roots;
}
function menuText(array $entry,string $class=''): void {
    if(($entry['url']??'')!==''){$active=navigationActive($entry['url']);?><a <?=$active?'aria-current="page"':''?> class="<?=e(trim($class.($active?' active selected':'')))?>" href="<?=e($entry['url'])?>"><?=e($entry['label'])?></a><?php }
    else {?><span class="<?=e($class)?>"><?=e($entry['label'])?></span><?php }
}
function renderHeaderMenu(): void {
    foreach(menuGroups() as $root){
        if(!$root['children']){menuText($root);continue;}
        $panel='mega-'.preg_replace('/[^a-zA-Z0-9-]/','',$root['id']);?>
        <div class="header-menu-group"><?php if($root['url']!=='')menuText($root,'header-parent-link');?><button type="button" class="mega-toggle" aria-expanded="false" aria-controls="<?=e($panel)?>" aria-label="<?=e($root['label'])?>"><?php if($root['url']===''):?><?=e($root['label'])?><?php endif;?><span class="menu-chevron" aria-hidden="true">⌄</span></button>
        <div class="mega-panel" id="<?=e($panel)?>" hidden><div class="mega-columns"><?php foreach($root['children'] as $child):?><section class="mega-column"><h2><?php menuText($child);?></h2><div class="mega-items"><?php foreach($child['content']??[] as $entry)menuText($entry);?></div></section><?php endforeach;?></div></div></div>
    <?php }
}
function renderMobileMenu(): void {
    foreach(menuGroups() as $root){?><div class="mobile-menu-group"><?php menuText($root,'mobile-root-link');foreach($root['children'] as $child):?><section class="mobile-submenu"><h3><?php menuText($child);?></h3><div class="mega-items"><?php foreach($child['content']??[] as $entry)menuText($entry);?></div></section><?php endforeach;?></div><?php }
}
