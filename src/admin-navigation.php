<?php
function renderAdminNavigation(string $section): void {
    $items=[
        ['label'=>'Page management','icon'=>'grid','children'=>[
            ['label'=>'Page list','url'=>'/admin/pages','active'=>$section==='pages'],
            ['label'=>'Page layout','url'=>'/admin/pages/layout','active'=>$section==='pages/layout'],
        ]],
        ['label'=>'Post management','icon'=>'edit','children'=>[
            ['label'=>'Article list','url'=>'/admin/posts','active'=>$section==='posts'],
            ['label'=>'Article categories','url'=>'/admin/categories','active'=>$section==='categories'],
            ['label'=>'Tags','url'=>'/admin/tags','active'=>$section==='tags'],
        ]],
        ['label'=>'Media management','icon'=>'image','children'=>[
            ['label'=>'Library','url'=>'/admin/media','active'=>$section==='media'&&!isset($_GET['trash'])],
            ['label'=>'Media trash','url'=>'/admin/media/trash','active'=>$section==='media/trash'||($section==='media'&&isset($_GET['trash']))],
        ]],
        ['label'=>'System management','icon'=>'sun','children'=>[
            ['label'=>'Site information','url'=>'/admin/system','active'=>$section==='system'],
            ['label'=>'Footer settings','url'=>'/admin/system/footer','active'=>$section==='system/footer'],
            ['label'=>'Header menu','url'=>'/admin/menus','active'=>$section==='menus'],
            ['label'=>'Mobile tabs','url'=>'/admin/tabs','active'=>$section==='tabs'],
        ]],
        ['label'=>'Link management','icon'=>'share','children'=>[
            ['label'=>'Link list','url'=>'/admin/links','active'=>$section==='links'&&!isset($_GET['link_categories'])],
            ['label'=>'Link categories','url'=>'/admin/links?link_categories=1','active'=>$section==='links'&&isset($_GET['link_categories'])],
        ]],
        ['label'=>'Users','key'=>'users','icon'=>'user'],
    ];
    foreach($items as $item){
        if(isset($item['children'])){$active=(bool)array_filter($item['children'],fn($child)=>$child['active']);?>
        <details class="admin-nav-group" <?=$active?'open':''?>><summary class="<?=$active?'group-current':''?>"><?=icon($item['icon'])?><span class="admin-nav-label"><?=e(t($item['label']))?></span><span class="admin-nav-chevron" aria-hidden="true"><?=icon('arrow')?></span></summary><div class="admin-nav-children"><?php foreach($item['children'] as $child):?><a href="<?=e($child['url'])?>" class="<?=$child['active']?'active':''?>" <?=$child['active']?'aria-current="page"':''?>><span class="admin-nav-child-dot" aria-hidden="true"></span><?=e(t($child['label']))?></a><?php endforeach;?></div></details>
        <?php }else{$active=$section===$item['key'];?><a href="/admin/<?=$item['key']?>" title="<?=e(t($item['label']))?>" class="<?=$active?'active':''?>" <?=$active?'aria-current="page"':''?>><?=icon($item['icon'])?><span class="admin-nav-label"><?=e(t($item['label']))?></span></a><?php }
    }
}
