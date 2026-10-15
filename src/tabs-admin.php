<?php
function tabRow(array $item,int $index): void { ?>
<div class="tab-item">
<label class="menu-visible"><input type="checkbox" name="enabled[]" value="<?=$index?>" <?=($item['visible']??true)?'checked':''?>> <?=e(t('Show'))?></label>
<label><?=e(t('Label'))?><input name="label[]" maxlength="20" value="<?=e($item['label']??'')?>" required></label>
<label><?=e(t('URL'))?><input name="url[]" maxlength="1000" value="<?=e($item['url']??'')?>" placeholder="/ or https://…" required></label>
<label><?=e(t('Icon'))?><select name="icon[]"><?php foreach(tabIcons() as $key=>$title):?><option value="<?=$key?>" <?=($item['icon']??'home')===$key?'selected':''?>><?=e(t($title))?></option><?php endforeach;?></select></label>
<label class="menu-visible"><input type="checkbox" name="login_only[]" value="<?=$index?>" <?=!empty($item['login_only'])?'checked':''?>> <?=e(t('Signed-in users only'))?></label>
<div class="menu-order"><button type="button" class="text-button" data-up aria-label="<?=e(t('Move up'))?>">↑</button><button type="button" class="text-button" data-down aria-label="<?=e(t('Move down'))?>">↓</button><button type="button" class="text-button" data-remove><?=e(t('Remove'))?></button></div>
</div>
<?php } ?>
<section class="panel admin-panel"><h2><?=e(t('Mobile tabs'))?></h2><p class="muted"><?=e(t('Manage up to 5 standard bottom tabs. Publishing tabs are visible only after signing in.'))?></p>
<form data-admin-action="tabs_save" data-stay><div id="tab-editor"><?php foreach(mobileTabs(true) as $index=>$item)tabRow($item,$index);?></div><div class="admin-row-actions"><button type="button" class="text-button" id="add-tab-item"><?=e(t('＋ Add item'))?></button><button type="submit" class="button"><?=e(t('Save tabs'))?></button></div><p role="status"></p></form>
<template id="tab-template"><?php tabRow([],0);?></template></section>
