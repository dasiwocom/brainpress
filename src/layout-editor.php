<?php $widgetNames=['posts'=>t('Post list'),'categories'=>t('Category list'),'profile'=>t('Profile card'),'toc'=>t('Contents')];?>
<section class="panel layout-editor" id="layout-editor" data-context="<?=e($layoutContext)?>">
<div class="layout-edit-heading"><div><h2><?=e(t('Edit current layout'))?></h2><p class="muted"><?=e(t('Scope'))?>: <?=e(t(['home'=>'Home','article'=>'All article pages','categories'=>'Category list page','archive'=>'Archive pages'][$layoutContext]??'This page'))?></p></div><a class="text-button" href="<?=e(layoutLink(false))?>"><?=e(t('Exit editing'))?></a></div>
<p class="muted"><?=e(t('Changes are previewed here and apply publicly only after saving.'))?></p>
<div id="layout-rows"></div>
<div class="layout-edit-actions"><select id="widget-type" aria-label="<?=e(t('Widget'))?>"><?php foreach($widgetNames as $key=>$label):if(($key==='toc'&&$layoutContext!=='article')||($layoutContext==='article'&&in_array($key,['posts','categories'],true)))continue;?><option value="<?=$key?>"><?=e($label)?></option><?php endforeach;?></select><button type="button" class="text-button" id="add-widget"><?=e(t('Add widget'))?></button><button type="button" class="button" id="save-layout"><?=e(t('Save layout'))?></button><button type="button" class="text-button" id="reset-layout"><?=e(t('Restore default'))?></button><span role="status" id="layout-message"></span></div>
<script type="application/json" id="layout-data"><?=json_encode($layout,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script>
</section>
