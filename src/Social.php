<?php
declare(strict_types=1);

function recordPostView(array &$post): void {
    $id=(int)$post['id'];$seen=$_SESSION['post_views']??[];
    if(time()-(int)($seen[$id]??0)>=1800){query('UPDATE posts SET views=views+1 WHERE id=?',[$id]);$post['views']++;$seen[$id]=time();if(count($seen)>200)$seen=array_slice($seen,-200,null,true);$_SESSION['post_views']=$seen;}
}
function postSocial(int $id): array {
    $userId=(int)(currentUser()['id']??0);
    return ['likes'=>(int)query('SELECT COUNT(*) FROM post_likes WHERE post_id=?',[$id])->fetchColumn(),'comments'=>(int)query("SELECT COUNT(*) FROM comments WHERE post_id=? AND status='published'",[$id])->fetchColumn(),'liked'=>(bool)query('SELECT user_id FROM post_likes WHERE post_id=? AND user_id=?',[$id,$userId])->fetchColumn(),'bookmarked'=>(bool)query('SELECT user_id FROM post_bookmarks WHERE post_id=? AND user_id=?',[$id,$userId])->fetchColumn()];
}
function socialAction(string $action): void {
    if(!in_array($action,['post_like','post_bookmark','comment_create','comment_delete'],true))return;
    $user=requireUser();$rawId=$_POST['post_id']??'';if(!is_string($rawId)||!ctype_digit($rawId)||(int)$rawId<1)json_response(['error'=>'Post not found.'],404);$id=(int)$rawId;
    if(!query("SELECT id FROM posts WHERE id=? AND status='published'",[$id])->fetchColumn())json_response(['error'=>'Post not found.'],404);
    if($action==='post_like'||$action==='post_bookmark'){
        $table=$action==='post_like'?'post_likes':'post_bookmarks';$state=$_POST['active']??'';
        if(!in_array($state,['0','1'],true))json_response(['error'=>'Invalid action.'],422);
        authMutex(function()use($table,$state,$id,$user){
            if($state==='0')query("DELETE FROM $table WHERE post_id=? AND user_id=?",[$id,$user['id']]);
            elseif(!query("SELECT user_id FROM $table WHERE post_id=? AND user_id=?",[$id,$user['id']])->fetchColumn())query("INSERT INTO $table(post_id,user_id) VALUES(?,?)",[$id,$user['id']]);
        });json_response(['ok'=>true,...postSocial($id)]);
    }
    if($action==='comment_create'){
        $body=$_POST['body']??'';$parent=(int)($_POST['parent_id']??0);
        if(!is_string($body)||trim($body)===''||mb_strlen($body)>2000)json_response(['error'=>'Write a comment of 1–2000 characters.'],422);
        $body=trim($body);
        if($parent){$reply=query("SELECT id,parent_id FROM comments WHERE id=? AND post_id=? AND status='published'",[$parent,$id])->fetch();if(!$reply)json_response(['error'=>'This comment is no longer available.'],422);}
        $commentId=authMutex(function()use($id,$user,$parent,$body){
            // Serializes rate and duplicate checks across browser sessions.
            $recent=query('SELECT id,body,post_id,parent_id,created_at FROM comments WHERE user_id=? ORDER BY id DESC LIMIT 1',[$user['id']])->fetch();
            if($recent&&$recent['post_id']==$id&&(int)$recent['parent_id']===$parent&&$recent['body']===$body&&strtotime($recent['created_at'])>time()-60)return (int)$recent['id'];
            if($recent&&strtotime($recent['created_at'])>time()-15)json_response(['error'=>'Please wait a few seconds before commenting again.'],429);
            query('INSERT INTO comments(post_id,user_id,parent_id,body,status,created_at) VALUES(?,?,?,?,?,?)',[$id,$user['id'],$parent?:null,$body,'published',date('Y-m-d H:i:s')]);return (int)db()->lastInsertId();
        });json_response(['ok'=>true,'id'=>$commentId]);
    }
    $comment=query('SELECT * FROM comments WHERE id=? AND post_id=?',[(int)($_POST['id']??0),$id])->fetch();
    if(!$comment)json_response(['error'=>'Comment not found.'],404);
    if((int)$comment['user_id']!==(int)$user['id']&&!isAdministrator())json_response(['error'=>'You cannot delete this comment.'],403);
    query("UPDATE comments SET status='deleted',body='' WHERE id=?",[$comment['id']]);json_response(['ok'=>true]);
}
