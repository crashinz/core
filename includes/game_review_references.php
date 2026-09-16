<?php
declare(strict_types=1);

function game_review_reference_root(): string
{
    return security_private_storage_directory('game-review-references');
}

function game_review_reference_key(string $case, string $pack): string
{
    return hash('sha256', $case.'|'.$pack);
}

function game_review_references(string $case, string $pack): array
{
    $key=game_review_reference_key($case,$pack);$path=game_review_reference_root().DIRECTORY_SEPARATOR.$key.'.json';
    if(!is_file($path))return [];
    $rows=json_decode((string)file_get_contents($path),true);
    return is_array($rows)?$rows:[];
}

function game_review_source_version(): array
{
    $root=dirname(__DIR__);$manifest=json_decode((string)file_get_contents($root.'/release-manifest.json'),true);
    return ['release'=>$manifest['release_id']??'development','renderer'=>hash_file('sha256',$root.'/games/ocx-extension-game.js'),'fiveDiceRenderer'=>hash_file('sha256',$root.'/games/five-dice/five-dice.js')];
}


function game_review_upload_limit(): int
{
    $limit=33554432;
    foreach(['upload_max_filesize','post_max_size'] as $setting){$raw=trim((string)ini_get($setting));$value=(float)$raw;$unit=strtolower(substr($raw,-1));$value*=['g'=>1073741824,'m'=>1048576,'k'=>1024][$unit]??1;if($value>0)$limit=min($limit,(int)$value-($setting==='post_max_size'?65536:0));}
    return max(1024,$limit);
}

/** References are append-only, separate from application updates and live examples. */
function game_review_store_reference(array $user,array $review,array $upload,string $note,string $previous): array
{
    game_review_assert_admin($user);
    if(trim($note)===''||strlen($note)>1500)throw new MultiplayerGameException('Describe what you verified (up to 1,500 characters).','GAME_REVIEW_NOTE_REQUIRED',422);
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($upload['tmp_name']??''))||($upload['size']??0)>game_review_upload_limit())throw new MultiplayerGameException('Choose a reference image or clip within this server’s upload limit.','GAME_REVIEW_UPLOAD_INVALID',422);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);$extensions=['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp','video/webm'=>'webm','video/mp4'=>'mp4'];
    if(!isset($extensions[$mime]))throw new MultiplayerGameException('Use PNG, JPEG, GIF, WebP, WebM or MP4.','GAME_REVIEW_UPLOAD_TYPE',422);
    if(str_starts_with($mime,'image/')){$size=getimagesize($upload['tmp_name']);if(!$size||$size[0]>8192||$size[1]>8192)throw new MultiplayerGameException('Reference image dimensions are invalid.','GAME_REVIEW_UPLOAD_DIMENSIONS',422);}
    $root=game_review_reference_root();$key=game_review_reference_key($review['case']['id'],$review['pack']);$lock=fopen($root.'/'.$key.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Reference storage is unavailable.');
    try {
        $rows=game_review_references($review['case']['id'],$review['pack']);$latest=$rows?end($rows)['id']:'';
        if($previous!==$latest)throw new MultiplayerGameException('Another reference was saved. Reload before replacing it.','GAME_REVIEW_REFERENCE_CONFLICT',409);
        $id=bin2hex(random_bytes(16));$file=$key.'-'.$id.'.'.$extensions[$mime];
        if(!move_uploaded_file($upload['tmp_name'],$root.'/'.$file))throw new RuntimeException('Reference upload could not be saved.');
        $entry=['id'=>$id,'file'=>$file,'mime'=>$mime,'sha256'=>hash_file('sha256',$root.'/'.$file),'createdAt'=>gmdate('c'),'verifiedBy'=>(int)$user['id'],
            'note'=>trim($note),'case'=>$review['case']['id'],'pack'=>$review['pack'],'label'=>$review['case']['label'],'expected'=>$review['expected'],
            'source'=>game_review_source_version(),'state'=>$review['state'],'stateVersion'=>$review['version']];
        $rows[]=$entry;$tmp=$root.'/'.$key.'.'.bin2hex(random_bytes(8)).'.tmp';
        if(file_put_contents($tmp,json_encode($rows,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT))===false||!rename($tmp,$root.'/'.$key.'.json'))throw new RuntimeException('Reference index could not be saved.');
        return $entry;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
