<?php
declare(strict_types=1);

/** Admin orphan review for approved media roots. Custom emoji files are always excluded. */
final class UnusedImageException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 409) { parent::__construct($message); }
}

function unused_image_require_admin(array $actor): void
{
    if (($actor['role'] ?? '') !== 'admin' || (int)($actor['id'] ?? 0) < 1) throw new UnusedImageException('Administrator required.', 403);
}

/** Explicit roots only; never scan emoji, attachments, records, backups or staging. */
function unused_image_roots(): array
{
    $uploads = dirname(__DIR__).'/assets/uploads/';
    return [
        '/assets/uploads/avatars/' => ['directory'=>$uploads.'avatars','kind'=>'avatar','extensions'=>['gif','webp','png','jpg','jpeg']],
        '/assets/uploads/nameplates/' => ['directory'=>$uploads.'nameplates','kind'=>'nameplate','extensions'=>['gif','webp','png','jpg','jpeg']],
        '/assets/uploads/gestures/' => ['directory'=>$uploads.'gestures','kind'=>'gesture','extensions'=>['gif','webp','png','jpg','jpeg','mp3','wav','ogg','agst']],
        'private-gestures/' => ['directory'=>gesture_package_storage_root(),'kind'=>'gesture','extensions'=>['gif','webp','png','jpg','jpeg','mp3','wav','ogg','agst']],
        '/assets/uploads/imported-rooms/' => ['directory'=>$uploads.'imported-rooms','kind'=>'room','extensions'=>['gif','webp','png','jpg','jpeg','mp3','ogg','wav','aac','m4a']],
        '/assets/uploads/backgrounds/' => ['directory'=>$uploads.'backgrounds','kind'=>'background','extensions'=>['gif','webp','png','jpg','jpeg','mp4','webm']],
    ];
}

function unused_image_location(string $public): array
{
    foreach(unused_image_roots() as $prefix=>$root) {
        if(!str_starts_with($public,$prefix))continue;
        $file=substr($public,strlen($prefix));
        if(!preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/',$file)||str_contains($file,'..')||!in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),$root['extensions'],true))break;
        if($root['kind']==='avatar'&&str_starts_with($file,'nameplate-'))$root['kind']='nameplate';
        return $root+['file'=>$file];
    }
    throw new UnusedImageException('This file is outside the supported cleanup folders.',400);
}

function unused_image_path(string $public, bool $mustExist = true): string
{
    $location=unused_image_location($public);$directory=$location['directory'];$root=realpath($directory);
    if(!$root||is_link($directory)||is_link(dirname($directory))||realpath(dirname($root))!==realpath(dirname($directory)))throw new UnusedImageException('The source folder cannot be verified.');
    $path=$root.DIRECTORY_SEPARATOR.$location['file'];
    if(is_link($path)||($mustExist&&(!is_file($path)||!is_readable($path)||realpath(dirname($path))!==$root)))throw new UnusedImageException('The file cannot be verified.');
    return $path;
}

function unused_image_trash_root(): string
{
    $path = security_private_storage_directory('unused-image-trash');
    if (is_link($path) || !realpath($path)) throw new UnusedImageException('Private trash storage cannot be verified.', 503);
    return (string)realpath($path);
}

function unused_image_lock(): mixed
{
    $path = unused_image_trash_root() . '/cleanup.lock';
    if (is_link($path)) throw new UnusedImageException('Cleanup storage cannot be verified.', 503);
    $lock = fopen($path, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); throw new UnusedImageException('Another cleanup request is running. Try again.', 409); }
    return $lock;
}

/** Discover text columns so legacy, historical and extension-owned pointers also protect files. */
function unused_image_reference_schema(PDO $pdo): array
{
    $tables = db_uses_mysql_syntax($pdo)
        ? $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    $schema = [];
    foreach ($tables as $table) {
        if (!preg_match('/\A[A-Za-z0-9_]+\z/', $table)) throw new UnusedImageException('A reference table cannot be checked.', 503);
        $columns = db_uses_mysql_syntax($pdo) ? $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) : $pdo->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $column) {
            $name = $column['Field'] ?? $column['name']; $type = strtolower((string)($column['Type'] ?? $column['type']));
            if (!preg_match('/\A[A-Za-z0-9_]+\z/', $name)) throw new UnusedImageException('A reference column cannot be checked.', 503);
            if (preg_match('/char|text|json|clob|blob/', $type) || $type === '') $schema[$table][] = $name;
        }
    }
    foreach (['users','participants','server_media_assets','app_settings','gestures','gesture_package_generations','rooms'] as $required) if (!isset($schema[$required])) throw new UnusedImageException('Required reference data is unavailable. Nothing can be removed.', 503);
    return $schema;
}

function unused_image_like(string $value): string { return '%' . strtr($value, ['!'=>'!!','%'=>'!%','_'=>'!_']) . '%'; }

/** Return only a boolean. Never disclose private-library owners, records or contents. */
function unused_image_referenced(PDO $pdo, string $public, ?array $schema = null, bool $lockAssets = false): bool
{
    $schema ??= unused_image_reference_schema($pdo);
    $file = basename($public);
    $q = $pdo->prepare("SELECT * FROM server_media_assets WHERE source_key LIKE ? ESCAPE '!' OR storage_path LIKE ? ESCAPE '!' OR legacy_public_path LIKE ? ESCAPE '!'" . ($lockAssets && db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : ''));
    $needle = unused_image_like($file); $q->execute([$needle,$needle,$needle]); $assetIds = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $asset) {
        $id = (string)$asset['public_id']; $assetIds[] = $id;
        if ($asset['source_owner'] === 'gesture') {
            $gestureId=explode(':',(string)$asset['source_key'])[0];
            $gesture=$pdo->prepare('SELECT deleted_at FROM gestures WHERE public_id=?');$gesture->execute([$gestureId]);$g=$gesture->fetch();
            if(!$g||$g['deleted_at']===null||!empty($asset['pinned']))return true;
            $assetIds[]=$gestureId;
            $refs=$pdo->prepare('SELECT 1 FROM server_media_references WHERE asset_id=? AND active=1 LIMIT 1');$refs->execute([(int)$asset['id']]);if($refs->fetchColumn())return true;
            continue;
        }
        if ($asset['source_owner'] !== 'avatar' || !in_array($asset['source_role'], ['avatar','nameplate'], true)) return true;
        $prefix = $asset['source_role'] . '_library.';
        // Saved but not currently worn is still in use. An unavailable/expired asset is not permission to erase it.
        if (app_setting($pdo, $prefix . 'deleted.' . $id, '0') !== '1' || app_setting($pdo, $prefix . 'shared.' . $id, '0') === '1' || !empty($asset['pinned'])) return true;
        $refs = $pdo->prepare('SELECT 1 FROM server_media_references WHERE asset_id=? AND active=1 LIMIT 1');
        $refs->execute([(int)$asset['id']]); if ($refs->fetchColumn()) return true;
    }
    // Deleted gesture metadata alone is not usage, but retained messages naming it are.
    $gestures=$pdo->prepare("SELECT public_id FROM gestures WHERE gif_path LIKE ? ESCAPE '!' OR audio_path LIKE ? ESCAPE '!'");$gestures->execute([$needle,$needle]);
    $assetIds=array_merge($assetIds,$gestures->fetchAll(PDO::FETCH_COLUMN));
    $versions=$pdo->prepare("SELECT g.public_id FROM gesture_package_generations pg JOIN gestures g ON g.id=pg.gesture_id WHERE pg.package_storage_name LIKE ? ESCAPE '!' OR pg.animation_storage_name LIKE ? ESCAPE '!' OR pg.poster_storage_name LIKE ? ESCAPE '!' OR pg.audio_storage_name LIKE ? ESCAPE '!'");$versions->execute([$needle,$needle,$needle,$needle]);$assetIds=array_values(array_unique(array_merge($assetIds,$versions->fetchAll(PDO::FETCH_COLUMN))));
    foreach ($schema as $table => $columns) {
        if ($table === 'server_media_assets' || $table === 'server_media_references') continue;
        $where = []; $params = [];
        foreach ($columns as $column) foreach (array_merge([$file], $assetIds) as $value) {
            $where[] = "`$column` LIKE ? ESCAPE '!'"; $params[] = unused_image_like($value);
        }
        // Library metadata/tombstones do not themselves keep removed bytes alive.
        $filter = $table === 'app_settings' ? " AND setting_key NOT LIKE 'avatar!_library.%' ESCAPE '!' AND setting_key NOT LIKE 'nameplate!_library.%' ESCAPE '!'" : '';
        if($table==='gestures')$filter=' AND deleted_at IS NULL';
        if($table==='gesture_package_generations')$filter=' AND gesture_id IN (SELECT id FROM gestures WHERE deleted_at IS NULL)';
        $find = $pdo->prepare("SELECT 1 FROM `$table` WHERE (" . implode(' OR ', $where) . ")$filter LIMIT 1");
        $find->execute($params); if ($find->fetchColumn()) return true;
    }
    return false;
}

function unused_image_identity(string $public, string $path): array
{
    clearstatcache(true, $path);
    $size = filesize($path); $mtime = filemtime($path);
    if ($size === false || $mtime === false || $size > 256 * 1024 * 1024) throw new UnusedImageException('This file cannot be verified within the review limit.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $image=in_array($mime,['image/gif','image/webp','image/png','image/jpeg'],true);
    $location=unused_image_location($public);
    $other=in_array($location['kind'],['gesture','room'],true)&&in_array($mime,['audio/mpeg','audio/mp3','audio/ogg','application/ogg','audio/wav','audio/x-wav','audio/aac','audio/mp4','video/mp4','audio/x-m4a','application/zip','application/x-zip-compressed'],true);
    $other=$other||($location['kind']==='background'&&in_array($mime,['video/mp4','video/webm'],true));
    if(($image&&!@getimagesize($path))||(!$image&&!$other))throw new UnusedImageException('This file format cannot be verified for cleanup.');
    $hash = hash_file('sha256', $path); if (!$hash) throw new UnusedImageException('This file could not be read.');
    return ['path'=>$public,'name'=>basename($public),'kind'=>$location['kind'],'previewable'=>$image,'bytes'=>$size,'mtime'=>$mtime,'sha256'=>$hash,'mime'=>$mime,'snapshot'=>hash('sha256', "$public|$size|$mtime|$hash")];
}

function unused_image_scan(PDO $pdo, array $actor, string $after = '', string $kind = 'all'): array
{
    unused_image_require_admin($actor);
    if (!in_array($kind, ['all','avatar','nameplate','gesture','room','background'], true) || strlen($after)>300) throw new UnusedImageException('Invalid scan filter.',400);
    $paths=[]; $unsupported=0;
    foreach (unused_image_roots() as $prefix=>$location) {
        $root=$location['directory'];
        if (!file_exists($root)) continue;
        if (is_link($root) || !is_readable($root)) throw new UnusedImageException('A cleanup folder cannot be checked.',503);
        foreach (new DirectoryIterator($root) as $file) {
            if ($file->isDot() || $file->getFilename()==='.gitkeep' || $file->getFilename()==='.htaccess') continue;
            $public=$prefix.$file->getFilename();
            try{$entry=unused_image_location($public);}catch(UnusedImageException $error){$unsupported++;continue;}
            if($kind!=='all'&&$entry['kind']!==$kind)continue;
            if ($file->isLink() || !$file->isFile()) { $unsupported++; continue; }
            $paths[]=$public;
            if (count($paths)>50000) throw new UnusedImageException('This folder needs a smaller maintenance review; nothing was changed.',503);
        }
    }
    sort($paths,SORT_STRING);$remaining=array_values(array_filter($paths,static fn($p)=>strcmp($p,$after)>0));$batch=array_slice($remaining,0,2);$items=[];$protected=0;$skipped=0;
    $schema=unused_image_reference_schema($pdo);
    foreach($batch as $public) {
        try { $path=unused_image_path($public);$identity=unused_image_identity($public,$path); }
        catch (UnusedImageException $error) { $skipped++;continue; }
        if ($identity['mtime']>time()-86400) {$skipped++;continue;}
        if (unused_image_referenced($pdo,$public,$schema)) {$protected++;continue;}
        $items[]=$identity+['reason'=>'No saved-library, room, selected-media or retained database reference found.'];
    }
    return ['items'=>$items,'checked'=>count($batch),'protected'=>$protected,'skipped'=>$skipped,'unsupported'=>$unsupported,'total'=>count($paths),'after'=>$batch ? end($batch) : $after,'done'=>count($remaining)<=count($batch)];
}

function unused_image_record_path(string $id): string
{
    if (!preg_match('/\A[a-f0-9]{32}\z/',$id)) throw new UnusedImageException('Invalid trash entry.',400);
    return unused_image_trash_root().'/'.$id.'.json';
}

function unused_image_record_save(array $record): void
{
    $path=unused_image_record_path($record['id']); if(is_link($path)) throw new UnusedImageException('Invalid trash record.',503);
    $temporary=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
    if(file_put_contents($temporary,json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),LOCK_EX)===false) throw new UnusedImageException('Trash journal could not be saved.',503);
    @chmod($temporary,0600);
    if(!rename($temporary,$path)) { @unlink($temporary);throw new UnusedImageException('Trash journal could not be saved.',503); }
}

function unused_image_record(string $id): array
{
    $path=unused_image_record_path($id);
    if(is_link($path)||!is_file($path)||filesize($path)>10000) throw new UnusedImageException('Trash entry unavailable.',404);
    $record=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(($record['id']??'')!==$id) throw new UnusedImageException('Trash entry cannot be verified.',503);
    unused_image_path((string)$record['path'],false);
    return $record;
}

function unused_image_move(PDO $pdo,array $actor,string $public,string $snapshot): array
{
    unused_image_require_admin($actor);$lock=unused_image_lock();$transaction=null;
    try {
        $path=unused_image_path($public);$identity=unused_image_identity($public,$path);
        if(!hash_equals($identity['snapshot'],$snapshot)||$identity['mtime']>time()-86400) throw new UnusedImageException('This file changed or is too recent. Scan again.');
        $transaction=database_transaction_begin($pdo,true);
        if(empty($transaction['owned'])) throw new UnusedImageException('Cleanup needs its own transaction.',503);
        if(unused_image_referenced($pdo,$public,null,true)) throw new UnusedImageException('This image is now referenced and was skipped.');
        if(!hash_equals($snapshot,unused_image_identity($public,unused_image_path($public))['snapshot']))throw new UnusedImageException('This file changed during the usage check. Scan again.');
        $id=bin2hex(random_bytes(16));$destination=unused_image_trash_root().'/'.$id.'.image';
        $record=$identity+['id'=>$id,'state'=>'moving','movedAt'=>gmdate('c'),'actorId'=>(int)$actor['id']];
        unused_image_record_save($record);
        // Journal precedes rename so interrupted requests remain recoverable.
        if(!rename($path,$destination)) throw new UnusedImageException('The image could not be moved. Nothing was deleted.',503);
        @chmod($destination,0600);$record['state']='trash';unused_image_record_save($record);
        database_transaction_commit($pdo,$transaction);
        return ['ok'=>true,'id'=>$id,'name'=>$identity['name']];
    } finally {if(is_array($transaction)&&!empty($transaction['active']))database_transaction_rollback($pdo,$transaction);flock($lock,LOCK_UN);fclose($lock);}
}

function unused_image_trash_list(array $actor,string $after=''): array
{
    unused_image_require_admin($actor);$rows=[];
    foreach(new DirectoryIterator(unused_image_trash_root()) as $file) {
        if(!$file->isFile()||$file->isLink()||!preg_match('/\A([a-f0-9]{32})\.json\z/',$file->getFilename(),$m)||strcmp($m[1],$after)<=0)continue;
        $r=unused_image_record($m[1]);$data=unused_image_trash_root().'/'.$m[1].'.image';
        if(in_array($r['state'],['trash','moving','restoring','deleting'],true)&&is_file($data)&&!is_link($data))$rows[]=['id'=>$r['id'],'name'=>$r['name'],'path'=>$r['path'],'kind'=>$r['kind'],'previewable'=>$r['previewable']??true,'bytes'=>$r['bytes'],'movedAt'=>$r['movedAt']];
    }
    usort($rows,static fn($a,$b)=>strcmp($a['id'],$b['id']));$page=array_slice($rows,0,20);
    return ['items'=>$page,'after'=>$page ? end($page)['id']:$after,'done'=>count($rows)<=20];
}

function unused_image_trash_action(PDO $pdo,array $actor,string $id,string $action): array
{
    unused_image_require_admin($actor);if(!in_array($action,['restore','purge'],true))throw new UnusedImageException('Unknown trash action.',400);
    $lock=unused_image_lock();$transaction=null;
    try {
        $r=unused_image_record($id);$trash=unused_image_trash_root().'/'.$id.'.image';$original=unused_image_path($r['path'],false);
        if(!is_file($trash)&&in_array($r['state'],['restored','restoring'],true)&&$action==='restore'&&is_file($original)&&hash_equals($r['sha256'],(string)hash_file('sha256',$original))){$r['state']='restored';unused_image_record_save($r);return ['ok'=>true];}
        if(!is_file($trash)&&in_array($r['state'],['purged','deleting'],true)&&$action==='purge'){$r['state']='purged';unused_image_record_save($r);return ['ok'=>true];}
        if(is_link($trash)||!is_file($trash)||!hash_equals($r['sha256'],(string)hash_file('sha256',$trash)))throw new UnusedImageException('Trash contents changed or are missing; no action taken.');
        if($action==='restore') {
            if($r['state']==='restoring'&&!is_link($original)&&is_file($original)&&hash_equals($r['sha256'],(string)hash_file('sha256',$original))){if(!unlink($trash))throw new UnusedImageException('The restored copy is safe, but the trash copy could not be removed.',503);$r['state']='restored';unused_image_record_save($r);return ['ok'=>true];}
            if(file_exists($original)||is_link($original))throw new UnusedImageException('The original filename is occupied. It was not overwritten.');
            $r['state']='restoring';unused_image_record_save($r);
            $out=@fopen($original,'xb');if(!$out)throw new UnusedImageException('The original path cannot be restored.',503);
            $in=fopen($trash,'rb');$success=false;
            try {$copied=stream_copy_to_stream($in,$out);fflush($out);$success=$copied===$r['bytes'];}finally{fclose($in);fclose($out);}
            if(!$success||!hash_equals($r['sha256'],(string)hash_file('sha256',$original))){@unlink($original);throw new UnusedImageException('Restore failed; the trash copy is intact.',503);}
            @chmod($original,str_starts_with($r['path'],'private-gestures/')?0600:0644);@touch($original,$r['mtime']);
            if(!unlink($trash))throw new UnusedImageException('The image was restored, but the trash copy could not be removed.',503);
            $r['state']='restored';
        } else {
            $transaction=database_transaction_begin($pdo,true);if(empty($transaction['owned']))throw new UnusedImageException('Cleanup needs its own transaction.',503);
            if(unused_image_referenced($pdo,$r['path'],null,true))throw new UnusedImageException('This image is now referenced. Restore it instead.');
            $r['state']='deleting';unused_image_record_save($r);
            if(!unlink($trash))throw new UnusedImageException('The trash file could not be deleted.',503);
            $r['state']='purged';database_transaction_commit($pdo,$transaction);
        }
        $r['finishedAt']=gmdate('c');unused_image_record_save($r);return ['ok'=>true];
    } finally {if(is_array($transaction)&&!empty($transaction['active']))database_transaction_rollback($pdo,$transaction);flock($lock,LOCK_UN);fclose($lock);}
}

function unused_image_preview(PDO $pdo,array $actor,string $public,string $snapshot,string $id=''): never
{
    unused_image_require_admin($actor);
    if($id!=='') {$r=unused_image_record($id);$public=$r['path'];$path=unused_image_trash_root().'/'.$id.'.image';if(is_link($path)||!is_file($path)||!hash_equals($r['sha256'],(string)hash_file('sha256',$path)))throw new UnusedImageException('Preview unavailable.',404);}
    else {$path=unused_image_path($public);$r=unused_image_identity($public,$path);if(!hash_equals($r['snapshot'],$snapshot)||$r['mtime']>time()-86400)throw new UnusedImageException('Preview changed.',409);}
    if(empty($r['previewable']))throw new UnusedImageException('This file has no image preview.',404);
    if(unused_image_referenced($pdo,$public))throw new UnusedImageException('Referenced images are excluded from this review.',403);
    header('Content-Type: '.$r['mime']);header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; sandbox");header('Cache-Control: private, no-store');readfile($path);exit;
}
