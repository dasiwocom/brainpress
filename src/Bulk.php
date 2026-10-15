<?php
declare(strict_types=1);

function deleteEmptyCategories(array $ids): void {
    db()->beginTransaction();
    try {
        $marks=implode(',',array_fill(0,count($ids),'?'));
        if((int)query("SELECT COUNT(*) FROM categories WHERE id IN ($marks)",$ids)->fetchColumn()!==count($ids))throw new InvalidArgumentException('Category not found.');
        foreach($ids as $id) {
            if(query('SELECT id FROM posts WHERE category_id=? LIMIT 1',[$id])->fetchColumn())throw new InvalidArgumentException('Move all posts, including drafts and trash, in the post list before deleting this category.');
            query('UPDATE categories SET parent_id=NULL WHERE parent_id=?',[$id]);
            query('DELETE FROM categories WHERE id=?',[$id]);
        }
        db()->commit();
    } catch(Throwable $error){db()->rollBack();throw $error;}
}
function bulkAction(string $action): void {
    if($action!=='bulk')return;
    requireUser(true);
    $entity=$_POST['entity']??'';$operation=$_POST['operation']??'';$raw=$_POST['ids']??[];
    $allowed=['posts'=>['category','published','draft','trash','restore'],'categories'=>['delete'],'tags'=>['delete'],'media'=>['trash','restore'],'pages'=>['published','draft'],'links'=>['delete'],'link_categories'=>['delete'],'users'=>['active','disabled']];
    if(!is_string($entity)||!is_string($operation)||!isset($allowed[$entity])||!in_array($operation,$allowed[$entity],true)||!is_array($raw)||!$raw||count($raw)>200)json_response(['error'=>'Select items and a valid bulk action.'],422);
    foreach($raw as $id)if(!is_string($id)||!ctype_digit($id)||(int)$id<1)json_response(['error'=>'Invalid selection.'],422);
    $ids=array_values(array_unique(array_map('intval',$raw)));$placeholders=implode(',',array_fill(0,count($ids),'?'));
    try {
        authMutex(function()use($entity,$operation,$ids,$placeholders){
            if($entity==='categories'){deleteEmptyCategories($ids);return;}
            db()->beginTransaction();
            try {
                $rows=query("SELECT * FROM $entity WHERE id IN ($placeholders)",$ids)->fetchAll();
                if(count($rows)!==count($ids))throw new InvalidArgumentException('Some selected items no longer exist. Refresh and try again.');
                if($entity==='users'&&$operation==='disabled'){
                    $remaining=(int)query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active' AND id NOT IN ($placeholders)",$ids)->fetchColumn();
                    if(!$remaining)throw new InvalidArgumentException('Keep at least one active administrator.');
                }
                if($entity==='pages'&&$operation==='draft')foreach($rows as $row){
                    if($row['kind']!=='custom')throw new InvalidArgumentException('Built-in pages must stay published.');
                    if((int)setting('homepage','0')===(int)$row['id'])throw new InvalidArgumentException('Choose another homepage before unpublishing this page.');
                }
                if($entity==='posts'&&$operation==='category'){
                    $category=(int)($_POST['category_id']??0);
                    if(!query('SELECT id FROM categories WHERE id=?',[$category])->fetchColumn())throw new InvalidArgumentException('Choose a category.');
                    query("UPDATE posts SET category_id=?,updated_at=? WHERE id IN ($placeholders)",[$category,date('Y-m-d H:i:s'),...$ids]);
                } else foreach($rows as $row){
                    $id=$row['id'];
                    if($entity==='posts'){
                        if($operation==='trash'){if($row['status']!=='trash')query("UPDATE posts SET previous_status=status,status='trash',updated_at=? WHERE id=?",[date('Y-m-d H:i:s'),$id]);}
                        else { $status=$operation==='restore'?($row['status']==='trash'?$row['previous_status']:$row['status']):$operation;query('UPDATE posts SET status=?,updated_at=? WHERE id=?',[$status,date('Y-m-d H:i:s'),$id]); }
                    } elseif($entity==='media')query('UPDATE media SET status=? WHERE id=?',[$operation==='restore'?'active':'trash',$id]);
                    elseif($entity==='users')query('UPDATE users SET status=?,session_version=session_version+1 WHERE id=? AND status<>?',[$operation,$id,$operation]);
                    elseif($entity==='pages')query('UPDATE pages SET status=? WHERE id=?',[$operation,$id]);
                    else query("DELETE FROM $entity WHERE id=?",[$id]);
                }
                db()->commit();
            }catch(Throwable $error){db()->rollBack();throw $error;}
        });
    }catch(InvalidArgumentException $error){json_response(['error'=>$error->getMessage()],422);}
    json_response(['ok'=>true,'message'=>'Bulk changes applied.']);
}
function bulkToolbar(string $entity,array $categories=[]): void {
    $operations=['posts'=>['category'=>'Change category','published'=>'Publish','draft'=>'Move to drafts','trash'=>'Move to trash','restore'=>'Restore'],'categories'=>['delete'=>'Delete'],'tags'=>['delete'=>'Delete'],'media'=>['trash'=>'Move to trash','restore'=>'Restore'],'pages'=>['published'=>'Publish','draft'=>'Move to drafts'],'links'=>['delete'=>'Delete'],'link_categories'=>['delete'=>'Delete'],'users'=>['active'=>'Enable','disabled'=>'Disable']]; ?>
    <div class="bulk-controls" data-bulk="<?=e($entity)?>"><select aria-label="<?=e(t('Bulk actions'))?>" data-bulk-operation><option value=""><?=e(t('Bulk actions'))?></option><?php foreach($operations[$entity] as $key=>$label):?><option value="<?=$key?>"><?=e(t($label))?></option><?php endforeach;?></select><?php if($entity==='posts'):?><select data-bulk-category hidden aria-label="<?=e(t('Category'))?>"><option value=""><?=e(t('Select a category'))?></option><?php categoryOptions($categories,0);?></select><?php endif;?><button type="button" class="button secondary" data-bulk-apply><?=e(t('Apply'))?></button><span class="muted" data-selection-count aria-live="polite"></span></div>
<?php }
function selectionBox(int $id=0,string $label=''): void { ?><input type="checkbox" <?=$id?'data-select-item value="'.$id.'"':'data-select-all'?> aria-label="<?=e($id?t('Select item').' '.$label:t('Select all on this page'))?>"><?php }
