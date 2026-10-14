<?php
function categoryParent(int $id,int $parent): ?int {
    if(!$parent)return null;
    if($id===$parent)throw new InvalidArgumentException('A category cannot be its own parent.');
    $row=query('SELECT parent_id FROM categories WHERE id=?',[$parent])->fetch();
    if(!$row || $row['parent_id']!==null)throw new InvalidArgumentException('Choose a top-level category as parent.');
    if($id&&query('SELECT id FROM categories WHERE parent_id=?',[$id])->fetchColumn())throw new InvalidArgumentException('A category with children must remain top-level.');
    return $parent;
}
function validateMenuParents(array $items): array {
    $ids=[];foreach($items as $item){if(isset($ids[$item['id']]))throw new InvalidArgumentException('Duplicate menu identifier.');$ids[$item['id']]=$item;}
    foreach($items as $item){$parent=$item['parent'];if($parent==='')continue;if($parent===$item['id']||!isset($ids[$parent])||$ids[$parent]['parent']!=='')throw new InvalidArgumentException('Menu supports a parent and one child level only.');}
    return $items;
}
