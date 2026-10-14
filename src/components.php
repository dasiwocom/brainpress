<?php
function icon(string $name): string {
    $paths = [
        'home'=>'<path d="m3 10 9-7 9 7v10H3z"/><path d="M9 20v-7h6v7"/>',
        'chat'=>'<path d="M21 11a8 8 0 0 1-8 8H6l-4 3V11a9 9 0 0 1 19 0Z"/><path d="M7 9h10M7 13h6"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 8-3 8-3 10h18c0-2-3-2-3-10M10 22h4"/>',
        'user'=>'<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/>',
        'search'=>'<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>',
        'moon'=>'<path d="M21 13A9 9 0 0 1 11 3a9 9 0 1 0 10 10Z"/>',
        'sun'=>'<circle cx="12" cy="12" r="4"/><path d="M12 1v2M12 21v2M1 12h2M21 12h2M4 4l2 2M18 18l2 2M4 20l2-2M18 6l2-2"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'edit'=>'<path d="m16 3 5 5-12 12-6 1 1-6zM13 6l5 5"/>',
        'image'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="2"/><path d="m3 17 6-6 4 4 3-3 5 5"/>',
        'arrow'=>'<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'share'=>'<path d="M14 3h7v7M21 3 10 14M10 4H4v16h16v-6"/>',
        'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name] ?? $paths['grid']).'</svg>';
}
function categoryOptions(array $categories, int $selected): void { foreach ($categories as $c): ?><option value="<?=$c['id']?>" <?=$selected === (int)$c['id'] ? 'selected' : ''?>><?=e($c['name'])?></option><?php endforeach; }
function writeForm(array $categories, mixed $post, int $categoryId, bool $quick=false): void { ?>
<form id="post-form" class="post-form <?=$quick ? 'quick-post' : 'full-editor'?>">
<?php if ($GLOBALS['isAdmin'] ?? false): ?><input type="hidden" name="admin_editor" value="1"><?php endif; ?><input type="hidden" name="id" value="<?=e($post['id'] ?? '')?>">
<div class="editor-surface panel">
<?php if (!$quick): ?><div class="post-cover-editor"><button type="button" class="cover-upload post-cover-preview" id="choose-cover" aria-label="<?=e(t('Choose cover image'))?>"><img id="cover-preview-image" alt="<?=e(t('Post cover'))?>" hidden><span class="cover-empty"><?=icon('image')?><span><?=e(t('Choose cover image'))?></span></span><span class="cover-preview-caption" hidden><?=e(t('Change cover'))?></span></button><div class="cover-settings"><label class="sr-only" for="cover-url"><?=e(t('Cover image URL'))?></label><input id="cover-url" name="cover_url" maxlength="1000" placeholder="<?=e(t('Cover image URL (optional)'))?>" value="<?=e($post['cover_url']??'')?>"><button class="text-button" type="button" id="remove-cover"><?=e(t('Remove cover'))?></button></div><p class="cover-feedback muted" id="cover-feedback" role="status"></p><input id="cover-upload" type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden></div><?php endif; ?>
<label class="sr-only" for="title"><?=e(t('Post title'))?></label><input id="title" class="title-input" name="title" maxlength="200" placeholder="<?=e(t('Enter a title'))?>" required value="<?=e($post['title'] ?? '')?>">
<div class="editor-toolbar"><div><button type="button" data-insert="heading" aria-label="<?=e(t('Insert heading'))?>">H2</button><button type="button" data-insert="bold" aria-label="<?=e(t('Bold'))?>"><strong>B</strong></button><button type="button" data-insert="italic" aria-label="<?=e(t('Italic'))?>"><em>I</em></button><button type="button" data-insert="list" aria-label="<?=e(t('Insert list'))?>">☷</button><button type="button" data-insert="quote" aria-label="<?=e(t('Insert quote'))?>">❞</button><button type="button" data-insert="code" aria-label="<?=e(t('Insert code'))?>">&lt;/&gt;</button><label for="image-upload" class="toolbar-image" title="<?=e(t('Upload image'))?>"><?=icon('image')?></label></div><div><button type="button" class="tab active" id="source-tab"><?=e(t('Write'))?></button><button type="button" class="tab" id="preview-tab"><?=e(t('Preview'))?></button></div></div>
<label class="sr-only" for="markdown"><?=e(t('Markdown body'))?></label><textarea id="markdown" name="markdown" placeholder="<?=e(t('Write something in Markdown…'))?>" required><?=e($post['markdown'] ?? '')?></textarea><div id="preview" class="prose" hidden></div><input id="image-upload" type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
<div class="editor-footer"><span><?=e(t('Markdown'))?></span><span id="char-count">0 characters</span></div>
</div>
<div class="publish-panel <?=$quick ? '' : 'panel'?>"><div class="publish-info"><h3><?=e(t('Publish your post'))?></h3><p><?=e(t('Write in Markdown, preview your work, then save a draft or publish to your feed.'))?></p></div><div class="status-picker"><label for="post-status"><?=e(t('Status'))?></label><select id="post-status" name="status"><option value="published" <?=($post['status'] ?? 'published') === 'published' ? 'selected' : ''?>><?=e(t('Published'))?></option><option value="draft" <?=($post['status'] ?? '') === 'draft' ? 'selected' : ''?>><?=e(t('Draft'))?></option></select></div><div class="category-picker"><label for="category"><?=e(t('Category'))?></label><div class="field-control"><select id="category" name="category_id" required><option value=""><?=e(t('Select a category'))?></option><?php categoryOptions($categories,(int)($post['category_id'] ?? $categoryId)); ?></select><button type="button" class="text-button" data-add-category aria-label="<?=e(t('Create category'))?>" title="<?=e(t('Create category'))?>">＋</button></div></div><div class="tag-picker"><label for="post-tags"><?=e(t('Tags'))?></label><input id="post-tags" name="tags" placeholder="notes, ideas" value="<?=e($post['tags_text'] ?? '')?>"><small class="muted"><?=e(t('Separate tags with commas.'))?></small></div><button class="button publish-button" type="submit"><?=icon('edit')?> <?=$post ? t('Save changes') : t('Publish')?></button><p class="form-message" id="form-message" role="status"></p></div>
</form>
<?php }
