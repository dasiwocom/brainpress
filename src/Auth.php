<?php
declare(strict_types=1);
function currentUser(): ?array {
    if(isset($_SESSION['auth_seen'])&&time()-(int)$_SESSION['auth_seen']>43200){unset($_SESSION['user_id'],$_SESSION['user_version'],$_SESSION['auth_seen']);}
    $id=(int)($_SESSION['user_id']??0);if(!$id)return null;
    $user=query('SELECT id,username,display_name,role,status,session_version FROM users WHERE id=?',[$id])->fetch();
    if(!$user||$user['status']!=='active'||(int)$user['session_version']!==(int)($_SESSION['user_version']??0)){unset($_SESSION['user_id'],$_SESSION['user_version']);return null;}
    $_SESSION['auth_seen']=time();return $user;
}
function isAdministrator(): bool {return (currentUser()['role']??'')==='admin';}
function ownsContent(array $content): bool {$user=currentUser();return $user&&($user['role']==='admin'||(int)($content['user_id']??0)===(int)$user['id']);}
function requireUser(bool $admin=false): array {
    $user=currentUser();if(!$user)json_response(['error'=>'Please sign in first.'],401);
    if($admin&&$user['role']!=='admin')json_response(['error'=>'Administrator access required.'],403);return $user;
}
function authNext(string $next,string $fallback='/account'): string {
    return str_starts_with($next,'/')&&!str_starts_with($next,'//')&&!preg_match('/[\x00-\x20\x7f\\\\]/',$next)&&!in_array(parse_url($next,PHP_URL_PATH),['/login','/register','/setup'],true)?$next:$fallback;
}
function authDirectory(): string {
    $directory=getenv('BRAINPRESS_AUTH_DIR')?:dirname(__DIR__).'/storage';
    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Cannot create authentication storage.');return $directory;
}
function authMutex(callable $callback): mixed {
    $lock=fopen(authDirectory().'/auth.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock authentication storage.');
    try{return $callback();}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function setupAvailable(): bool {return !(bool)query('SELECT id FROM users LIMIT 1')->fetchColumn();}
function setupToken(): string {
    $path=authDirectory().'/setup-token.txt';
    if(!is_file($path)){$handle=@fopen($path,'x');if($handle){chmod($path,0600);fwrite($handle,bin2hex(random_bytes(32)));fclose($handle);}}
    $token=trim((string)@file_get_contents($path));if(strlen($token)!==64)throw new RuntimeException('Cannot create setup token.');return $token;
}
function validateUserCredentials(array $input,bool $passwordRequired=true): array {
    $username=$input['username']??'';$display=$input['display_name']??'';$password=$input['password']??'';
    if(!is_string($username)||!is_string($display)||!is_string($password))throw new InvalidArgumentException('Invalid user details.');
    $username=strtolower(trim($username));$display=trim($display)?:$username;
    if(!preg_match('/^[a-z0-9_][a-z0-9_.-]{2,39}$/',$username))throw new InvalidArgumentException('Use 3–40 letters, numbers, dots, hyphens or underscores for the username.');
    if(mb_strlen($display)>80)throw new InvalidArgumentException('Use a display name up to 80 characters.');
    if(($passwordRequired||$password!=='')&&(strlen($password)<10||strlen($password)>72||str_contains($password,"\0")))throw new InvalidArgumentException('Use a password of 10–72 bytes.');
    return ['username'=>$username,'display_name'=>$display,'password'=>$password];
}
function establishLogin(array $user): void {
    session_regenerate_id(true);$_SESSION['user_id']=(int)$user['id'];$_SESSION['user_version']=(int)$user['session_version'];$_SESSION['csrf']=bin2hex(random_bytes(24));$_SESSION['auth_seen']=time();
}
function authRateLimit(string $scope,string $identity=''): void {
    $ip=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'local'));$key=hash('sha256',$scope.'|'.$identity.'|'.$ip);$since=time()-900;
    query('DELETE FROM auth_attempts WHERE created_at<?',[$since]);
    $count=(int)query('SELECT COUNT(*) FROM auth_attempts WHERE scope=? AND (attempt_key=? OR ip_key=?) AND created_at>=?',[$scope,$key,$ip,$since])->fetchColumn();
    if($count>=12)json_response(['error'=>'Too many attempts. Try again in 15 minutes.'],429);
    query('INSERT INTO auth_attempts(scope,attempt_key,ip_key,created_at) VALUES(?,?,?,?)',[$scope,$key,$ip,time()]);
}
function authAction(string $action): void {
    if($action==='login'){
        $name=$_POST['username']??'';$password=$_POST['password']??'';if(!is_string($name)||!is_string($password)||strlen($name)>80||strlen($password)>72)json_response(['error'=>'Incorrect username or password.'],422);
        $name=strtolower(trim($name));authRateLimit('login',$name);$user=query('SELECT * FROM users WHERE username=?',[$name])->fetch();
        $hash=$user['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if(!password_verify($password,$hash)||!$user||$user['status']!=='active')json_response(['error'=>'Incorrect username or password.'],422);
        if(password_needs_rehash($user['password_hash'],PASSWORD_DEFAULT))query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$user['id']]);
        establishLogin($user);json_response(['url'=>authNext((string)($_POST['next']??''),$user['role']==='admin'?'/admin/posts':'/account')]);
    }
    if(in_array($action,['register','setup'],true)){
        if(currentUser())json_response(['error'=>'You are already signed in.'],409);
        authRateLimit($action);
        try{$data=validateUserCredentials($_POST);}catch(InvalidArgumentException $error){json_response(['error'=>$error->getMessage()],422);}
        $user=authMutex(function()use($action,$data){
            if($action==='setup'){
                if(!setupAvailable())json_response(['error'=>'Administrator setup is already complete.'],409);
                $token=$_POST['setup_token']??'';if(!is_string($token)||!hash_equals(setupToken(),$token))json_response(['error'=>'Invalid setup token. Read storage/setup-token.txt on the server.'],403);
            }elseif(setupAvailable())json_response(['error'=>'Create the administrator first.'],403);
            if(query('SELECT id FROM users WHERE username=?',[$data['username']])->fetchColumn())json_response(['error'=>'This username is already in use.'],422);
            db()->beginTransaction();try{
                query('INSERT INTO users(username,display_name,password_hash,role,status,session_version,created_at) VALUES(?,?,?,?,?,?,?)',[$data['username'],$data['display_name'],password_hash($data['password'],PASSWORD_DEFAULT),$action==='setup'?'admin':'user','active',1,date('Y-m-d H:i:s')]);$id=(int)db()->lastInsertId();
                if($action==='setup'){query('UPDATE posts SET user_id=? WHERE user_id IS NULL',[$id]);query('UPDATE media SET user_id=? WHERE user_id IS NULL',[$id]);}
                db()->commit();return query('SELECT * FROM users WHERE id=?',[$id])->fetch();
            }catch(Throwable $error){db()->rollBack();throw $error;}
        });
        establishLogin($user);json_response(['url'=>$action==='setup'?'/admin/posts':'/account']);
    }
    if($action==='logout'){requireUser();$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(24));json_response(['url'=>'/']);}
    if($action==='password_change'){
        $user=requireUser();$old=$_POST['current_password']??'';if(!is_string($old)||!password_verify($old,(string)query('SELECT password_hash FROM users WHERE id=?',[$user['id']])->fetchColumn()))json_response(['error'=>'Incorrect current password.'],422);
        try{$data=validateUserCredentials(['username'=>$user['username'],'password'=>$_POST['password']??'']);}catch(InvalidArgumentException $error){json_response(['error'=>$error->getMessage()],422);}
        query('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash($data['password'],PASSWORD_DEFAULT),$user['id']]);establishLogin(query('SELECT * FROM users WHERE id=?',[$user['id']])->fetch());json_response(['url'=>'/account']);
    }
    if($action==='user_save'){
        requireUser(true);$id=(int)($_POST['id']??0);$role=$_POST['role']??'user';$status=$_POST['status']??'active';
        if(!in_array($role,['admin','user'],true)||!in_array($status,['active','disabled'],true))json_response(['error'=>'Invalid user role or status.'],422);
        try{$data=validateUserCredentials($_POST,!$id);}catch(InvalidArgumentException $error){json_response(['error'=>$error->getMessage()],422);}
        authMutex(function()use($id,$role,$status,$data){
            $old=$id?query('SELECT * FROM users WHERE id=?',[$id])->fetch():null;if($id&&!$old)json_response(['error'=>'User not found.'],404);
            if(query('SELECT id FROM users WHERE username=? AND id<>?',[$data['username'],$id])->fetchColumn())json_response(['error'=>'This username is already in use.'],422);
            if($old&&$old['role']==='admin'&&$old['status']==='active'&&($role!=='admin'||$status!=='active')&&(int)query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetchColumn()<=1)json_response(['error'=>'Keep at least one active administrator.'],422);
            if($id){$revoke=$old['role']!==$role||$old['status']!==$status||$data['password']!=='';query('UPDATE users SET username=?,display_name=?,role=?,status=?,password_hash=?,session_version=session_version+? WHERE id=?',[$data['username'],$data['display_name'],$role,$status,$data['password']!==''?password_hash($data['password'],PASSWORD_DEFAULT):$old['password_hash'],$revoke?1:0,$id]);}
            else query('INSERT INTO users(username,display_name,password_hash,role,status,session_version,created_at) VALUES(?,?,?,?,?,?,?)',[$data['username'],$data['display_name'],password_hash($data['password'],PASSWORD_DEFAULT),$role,$status,1,date('Y-m-d H:i:s')]);
        });json_response(['ok'=>true,'url'=>'/admin/users']);
    }
}
function authorizeAction(string $action): void {
    if(in_array($action,['login','register','setup'],true))return;
    $user=requireUser();
    if(in_array($action,['logout','password_change','save','upload','preview','media_list','post_like','post_bookmark','comment_create','comment_delete'],true))return;
    if(in_array($action,['trash','restore'],true)){$post=query('SELECT user_id FROM posts WHERE id=?',[(int)($_POST['id']??0)])->fetch();if(!$post||!ownsContent($post))json_response(['error'=>'You cannot edit this post.'],403);return;}
    if($user['role']!=='admin')json_response(['error'=>'Administrator access required.'],403);
}

function contentAuthor(array $post): string {
    if(!empty($post['user_id'])){$name=query('SELECT display_name FROM users WHERE id=?',[$post['user_id']])->fetchColumn();if($name!==false)return (string)$name;}return postAuthor($post['markdown']??'');
}
