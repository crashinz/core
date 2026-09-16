<?php
declare(strict_types=1);
require_once __DIR__.'/game_review_snapshots.php';

function game_review_pack_descriptor(): array
{
    static $value;
    return $value ??= json_decode((string)file_get_contents(__DIR__.'/game_review_pack_v1.json'),true,512,JSON_THROW_ON_ERROR);
}

function game_review_pack_files(): array
{
    return array_filter(game_review_snapshot_manifest()['files'],static fn($key)=>!str_starts_with($key,'media/'),ARRAY_FILTER_USE_KEY)
        + game_review_pack_descriptor()['files'];
}

/** Only pinned relative paths are accepted. Refuse symlinked parents and existing targets. */
function game_review_pack_path(string $key): string
{
    if(!preg_match('~\A(?:[a-zA-Z0-9_ .-]+/)*[a-zA-Z0-9_ .-]+\z~D',$key)||in_array('..',explode('/',$key),true)||(!isset(game_review_snapshot_manifest()['files'][$key])&&!isset(game_review_pack_descriptor()['files'][$key])))throw new RuntimeException('Invalid reference path.');
    $path=security_private_storage_directory('game-review-snapshots');
    foreach(explode('/','v1/'.$key) as $part){$path.='/'.$part;if(is_link($path))throw new RuntimeException('A reference path is linked. Restore it as a regular file.');}
    return $path;
}

function game_review_pack_matches(string $path,array $entry): bool
{
    clearstatcache(true,$path);
    return !is_link($path)&&is_file($path)&&filesize($path)===$entry['bytes']&&hash_equals($entry['sha256'],(string)hash_file('sha256',$path));
}

function game_review_pack_status(): array
{
    $m=game_review_snapshot_manifest();$base=['ready'=>0,'missing'=>0,'changed'=>0];$media=[];
    foreach($m['files'] as $key=>$entry){
        $path=game_review_pack_path($key);$state=game_review_pack_matches($path,$entry)?'ready':(file_exists($path)?'changed':'missing');
        if(str_starts_with($key,'media/')){$game=explode('/',$key)[1];$media[$game]??=['ready'=>0,'missing'=>0,'changed'=>0];$media[$game][$state]++;}
        else $base[$state]++;
    }
    return ['version'=>'v1','base'=>$base,'classic'=>$media];
}

/** Lock serializes installation and local-media copy; existing files are never replaced. */
function game_review_pack_locked(callable $operation): mixed
{
    $lock=fopen(security_private_storage_directory('game-review-snapshots').'/.install.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another reference installation is running. Try again shortly.');
    try{return $operation();}finally{flock($lock,LOCK_UN);fclose($lock);}
}

function game_review_pack_copy_stream($input,string $key,array $entry): bool
{
    $path=game_review_pack_path($key);
    if(file_exists($path)){
        if(!game_review_pack_matches($path,$entry))throw new RuntimeException('An existing reference differs: '.$key.'. It was left unchanged. Restore its backup before retrying.');
        return false;
    }
    $parent=dirname($path);if(!is_dir($parent)&&!mkdir($parent,0700,true)&&!is_dir($parent))throw new RuntimeException('Private reference storage is unavailable.');
    $temp=$parent.'/.install-'.bin2hex(random_bytes(12));$out=fopen($temp,'xb');
    if(!$out)throw new RuntimeException('Could not prepare a reference file.');
    try{
        $bytes=stream_copy_to_stream($input,$out,$entry['bytes']+1);fclose($out);$out=null;
        if($bytes!==$entry['bytes']||!game_review_pack_matches($temp,$entry))throw new RuntimeException('Reference file verification failed: '.$key);
        // Exclusive creation prevents replacement even when an external process creates a target.
        $target=fopen($path,'xb');if(!$target)throw new RuntimeException('Reference destination changed. Retry installation.');
        try{$source=fopen($temp,'rb');try{if(stream_copy_to_stream($source,$target)!==$entry['bytes'])throw new RuntimeException('Reference storage is full.');}finally{fclose($source);}}catch(Throwable $e){fclose($target);$target=null;unlink($path);throw $e;}finally{if(is_resource($target))fclose($target);}
        chmod($path,0600);return true;
    }finally{if(is_resource($out))fclose($out);if(is_file($temp))unlink($temp);}
}

function game_review_pack_install(string $archive): array
{
    $d=game_review_pack_descriptor();
    if(!class_exists('ZipArchive'))throw new RuntimeException('Enable the PHP ZIP extension to install a reference pack.');
    if(!game_review_pack_matches($archive,$d))throw new RuntimeException('This ZIP does not match reference pack v1. Download the linked ZIP and try again.');
    return game_review_pack_locked(static function()use($archive){
        $expected=game_review_pack_files();$zip=new ZipArchive();
        if($zip->open($archive)!==true)throw new RuntimeException('The reference ZIP could not be opened.');
        try{
            if($zip->numFiles!==count($expected))throw new RuntimeException('Unexpected reference ZIP entries.');
            $seen=[];
            // Preflight every archive entry and every existing destination before changing anything.
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);$key=$stat['name'];$entry=$expected[$key]??null;
                $opsys=0;$attr=0;$zip->getExternalAttributesIndex($i,$opsys,$attr);
                if(!$entry||isset($seen[$key])||$stat['size']!==$entry['bytes']||(($attr>>16)&0170000)===0120000)throw new RuntimeException('Unexpected or unsafe reference ZIP entry.');
                $seen[$key]=true;$path=game_review_pack_path($key);
                if(file_exists($path)&&!game_review_pack_matches($path,$entry))throw new RuntimeException('An existing reference differs: '.$key.'. Nothing was replaced. Restore its backup before retrying.');
            }
            $added=0;
            foreach($expected as $key=>$entry){$stream=$zip->getStream($key);if(!$stream)throw new RuntimeException('A reference file could not be read.');try{$added+=(int)game_review_pack_copy_stream($stream,$key,$entry);}finally{fclose($stream);}}
            return ['added'=>$added,'reused'=>count($expected)-$added];
        }finally{$zip->close();}
    });
}

function game_review_pack_hydrate(PDO $pdo,string $game): array
{
    $slots=game_review_snapshot_manifest()['media'][$game]??null;
    if(!$slots)throw new RuntimeException('Choose a supported Classic game.');
    return game_review_pack_locked(static function()use($pdo,$game,$slots){
        $directory=$game==='five-dice'?five_dice_media_pack_directory($pdo):ocx_game_media_active_directory($pdo,$game);
        $report=['game'=>$game,'copied'=>0,'reused'=>0,'missing'=>[],'different'=>[],'conflicts'=>[]];
        foreach($slots as $slot=>$key){
            $entry=game_review_snapshot_manifest()['files'][$key];$target=game_review_pack_path($key);
            if(file_exists($target)){if(game_review_pack_matches($target,$entry))$report['reused']++;else $report['conflicts'][]=$slot;continue;}
            $source=$game==='five-dice'?five_dice_media_pack_validate_slot($slot,$directory):ocx_game_media_validate_slot($game,$slot,$directory);
            if(($source['state']??'')!=='installed'){$report['missing'][]=$slot;continue;}
            if(is_array($source['preparation']??null))$source=$game==='five-dice'?five_dice_media_pack_prepared_file($source,$directory):ocx_game_media_prepared_file($source,$directory);
            if(!game_review_pack_matches($source['path'],$entry)){$report['different'][]=$slot;continue;}
            $stream=fopen($source['path'],'rb');try{$report['copied']+=(int)game_review_pack_copy_stream($stream,$key,$entry);}finally{fclose($stream);}
        }
        return $report;
    });
}

/** One bounded upload per administrator; it survives a retry but expires after two hours. */
function game_review_pack_upload(array $actor,string $action,array $input,array $upload=[]): array
{
    $root=security_private_storage_directory('game-review-pack-uploads');$prefix=$root.'/admin-'.(int)$actor['id'];$lock=fopen($prefix.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('A pack upload request is still running.');
    try{
        $metaPath=$prefix.'.json';$path=$prefix.'.zip';$meta=is_file($metaPath)?json_decode((string)file_get_contents($metaPath),true):null;
        if($action==='begin'){
            if(($input['size']??0)!==game_review_pack_descriptor()['bytes'])throw new RuntimeException('Choose the linked reference pack v1 ZIP.');
            $meta=['token'=>bin2hex(random_bytes(24)),'expires'=>time()+7200];
            if(file_put_contents($path,'')===false||file_put_contents($metaPath,json_encode($meta,JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Upload storage is unavailable.');
            return $meta+['offset'=>0];
        }
        if(!$meta||$meta['expires']<time()||!hash_equals($meta['token'],(string)($input['token']??'')))throw new RuntimeException('The upload expired. Select the ZIP and try again.');
        if($action==='cancel'){if(is_file($path))unlink($path);unlink($metaPath);return ['cancelled'=>true];}
        if($action==='finish'){
            if(isset($meta['result']))return $meta['result'];
            $result=game_review_pack_install($path);$meta['result']=$result;
            if(file_put_contents($metaPath,json_encode($meta,JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Could not record upload completion. Retry installation.');
            if(is_file($path))unlink($path);return $result;
        }
        if($action!=='chunk')throw new RuntimeException('Unknown upload action.');
        $offset=(int)($input['offset']??-1);$bytes=(int)($upload['size']??0);
        if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($upload['tmp_name']??''))||$bytes<1||$bytes>1048576||$offset<0||$offset+$bytes>game_review_pack_descriptor()['bytes'])throw new RuntimeException('The upload chunk was rejected. Check this server’s upload limits.');
        clearstatcache(true,$path);$size=filesize($path);
        if($size===$offset+$bytes){
            $previous=file_get_contents($path,false,null,$offset,$bytes);
            if(is_string($previous)&&hash_equals(hash('sha256',$previous),hash_file('sha256',$upload['tmp_name'])))return ['offset'=>$size];
        }
        if($offset!==$size)throw new RuntimeException('Upload position changed. Start the upload again.');
        $out=fopen($path,'ab');$source=fopen($upload['tmp_name'],'rb');
        try{if(stream_copy_to_stream($source,$out)!==$bytes)throw new RuntimeException('Upload storage is full.');}finally{fclose($source);fclose($out);}
        return ['offset'=>$offset+$bytes];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
