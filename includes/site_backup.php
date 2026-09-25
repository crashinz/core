<?php
declare(strict_types=1);

/** Versioned, encrypted site-data archives. No executable code is accepted. */
const SITE_BACKUP_FORMAT = 'corechat-site-data';
const SITE_BACKUP_VERSION = 1;
const SITE_BACKUP_MAX_ENTRIES = 100000;

function site_backup_groups(): array {
    return [
        'accounts' => 'Accounts and profiles',
        'avatars' => 'Avatar libraries',
        'nameplates' => 'Nameplate libraries',
        'gestures' => 'Gestures and library preferences',
        'emojis' => 'Custom emojis',
        'rooms' => 'Rooms, backgrounds, music and permissions',
        'settings' => 'Site settings and link icons',
        'games' => 'Game preferences, bot avatars and Pool setups',
        'classic' => 'Installed Classic artwork and sounds',
        'reviews' => 'Installed game-review references',
    ];
}

function site_backup_identifier(string $name): string {
    if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $name)) throw new RuntimeException('Invalid database identifier.');
    return '`' . $name . '`';
}

function site_backup_schema(PDO $pdo): array {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $tables = $mysql
        ? $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    sort($tables, SORT_STRING);
    $schema = [];
    foreach ($tables as $table) {
        $q = site_backup_identifier($table);
        if ($mysql) {
            $columns = $pdo->query("SHOW COLUMNS FROM $q")->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($columns, 'Field');
            $pk = array_column(array_filter($columns, static fn($c) => $c['Key'] === 'PRI'), 'Field');
            $fk = $pdo->prepare('SELECT COLUMN_NAME AS col,REFERENCED_TABLE_NAME AS target,REFERENCED_COLUMN_NAME AS ref FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY ORDINAL_POSITION');
            $fk->execute([$table]);
            $foreign = $fk->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $columns = $pdo->query("PRAGMA table_info($q)")->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($columns, 'name');
            $primary = array_filter($columns, static fn($c) => $c['pk'] > 0);
            usort($primary, static fn($a,$b) => $a['pk'] <=> $b['pk']);
            $pk = array_column($primary, 'name');
            $foreign = array_map(static fn($f) => ['col'=>$f['from'],'target'=>$f['table'],'ref'=>$f['to']], $pdo->query("PRAGMA foreign_key_list($q)")->fetchAll(PDO::FETCH_ASSOC));
        }
        usort($foreign,static fn($a,$b)=>strcmp($a['col'].'/'.$a['target'].'/'.$a['ref'],$b['col'].'/'.$b['target'].'/'.$b['ref']));
        $schema[$table] = ['columns'=>$names, 'primary'=>$pk, 'foreign'=>$foreign];
    }
    return $schema;
}

function site_backup_table_group(string $table): ?string {
    $groups = [
        'accounts' => ['users','member_profiles','member_identity_names','member_display_name_history','voice_webcam_preferences','user_blocks','personal_mutes','profile_relationship_requests','avatar_hidden_preferences','account_two_factor','account_two_factor_backup','account_email_state','account_two_factor_email'],
        'rooms' => ['rooms','live_website_rooms','room_ejections'],
        'gestures' => ['gestures','gesture_preferences','gesture_custom_order','gesture_hidden','gesture_sender_media_hidden','gesture_package_generations'],
        'games' => ['multiplayer_game_options','multiplayer_game_presentation_preferences'],
        'settings' => ['link_icon_catalog'],
    ];
    foreach ($groups as $group=>$tables) if (in_array($table,$tables,true)) return $group;
    return null;
}

function site_backup_setting_group(string $key): ?string {
    if (preg_match('/^(schema_version|core_|migration|database_|site_backup_|installation_)/', $key)) return null;
    if (str_starts_with($key,'avatar_library.')) return 'avatars';
    if (str_starts_with($key,'nameplate_library.') || str_starts_with($key,'nameplate.')) return 'nameplates';
    if (str_starts_with($key,'custom_emoji.')) return 'emojis';
    if (preg_match('/^(pool-practice-|game\.bot_avatar|game_bot_avatar|game-bot-avatar|game_bot_)/',$key)) return 'games';
    if (str_contains($key,'media_pack_active_generation') || str_starts_with($key,'ocx_media_')) return 'classic';
    return 'settings';
}

function site_backup_private_categories(): array {
    return [
        'gestures'=>'gestures','server-media'=>'attachments','server-media-quarantine'=>'attachments',
        'game-recordings'=>'recordings','game-review-references'=>'reviews','game-review-snapshots'=>'reviews',
        'five-dice-media-pack'=>'classic','five-dice-media-pack-generations'=>'classic',
        'ocx-game-checkers-media-pack'=>'classic','ocx-game-checkers-media-generations'=>'classic',
        'ocx-game-chess-media-pack'=>'classic','ocx-game-chess-media-generations'=>'classic',
        'ocx-game-acey-deucy-media-pack'=>'classic','ocx-game-acey-deucy-media-generations'=>'classic',
        'ocx-game-battleship-media-pack'=>'classic','ocx-game-battleship-media-generations'=>'classic',
        'ocx-game-spades-media-pack'=>'classic','ocx-game-spades-media-generations'=>'classic',
        'ocx-game-backgammon-first-party-media-pack'=>'classic','ocx-game-backgammon-first-party-media-generations'=>'classic',
        'two-factor'=>'security','message-protection'=>'security','network-privacy'=>'security',
        'runtime-issue-screenshots'=>'diagnostics','moderation-evidence'=>'moderation','account-lifecycle'=>'security',
    ];
}

function site_backup_public_root(): string {
    if (PHP_SAPI === 'cli' && defined('CORECHAT_SITE_BACKUP_TEST_PUBLIC_ROOT')) return CORECHAT_SITE_BACKUP_TEST_PUBLIC_ROOT;
    return dirname(__DIR__);
}
function site_backup_private_root(): string { return dirname(security_private_storage_directory('site-backup')); }
function site_backup_work_root(): string { return security_private_storage_directory('site-backup'); }
function site_backup_limit(PDO $pdo): int { return app_setting_bytes($pdo,'database_import_max_size_mb',512); }

function site_backup_roots(): array {
    $roots=[];
    foreach (['avatars'=>'avatars','nameplates'=>'nameplates','gestures'=>'gestures','emojis'=>'emojis','backgrounds'=>'rooms','imported-rooms'=>'rooms','branding'=>'settings','link-icons'=>'settings','files'=>'attachments','voice'=>'attachments'] as $name=>$group) {
        $roots['uploads/'.$name]=['path'=>site_backup_public_root().'/assets/uploads/'.$name,'group'=>$group];
    }
    foreach(site_backup_private_categories() as $name=>$group) $roots['private/'.$name]=['path'=>site_backup_private_root().'/'.$name,'group'=>$group];
    return $roots;
}

function site_backup_destination(string $logical): string {
    if (strlen($logical)>800 || str_contains($logical,'\\') || str_contains($logical,':') || preg_match('~(^|/)(\.{1,2}|[^/]*[. ])(/|$)|[\x00-\x1f]~',$logical)) throw new RuntimeException('Unsafe backup file path.');
    foreach(site_backup_roots() as $prefix=>$root) {
        if(!str_starts_with($logical,$prefix.'/')) continue;
        $tail=substr($logical,strlen($prefix)+1);
        if($tail==='' || str_contains($tail,'//') || preg_match('~(^|/)(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|/|$)~i',$tail)) break;
        if(str_starts_with($logical,'uploads/') && preg_match('/\.(php\d*|phtml|phar|cgi|pl|py|exe|dll|ocx|htaccess|html?|js|svg)$/i',$tail)) throw new RuntimeException('Executable upload content is not restorable.');
        $path=$root['path'].'/'.$tail;
        for($p=$path;strlen($p)>=strlen($root['path']);$p=dirname($p)) {
            if(is_link($p)) throw new RuntimeException('Backup paths cannot contain symbolic links.');
            if($p===$root['path']) break;
        }
        return $path;
    }
    throw new RuntimeException('Unsupported backup file destination.');
}

function site_backup_logical_path(string $value): ?string {
    $v=str_replace('\\','/',$value);
    if(str_starts_with($v,'/assets/uploads/')) return 'uploads/'.substr($v,16);
    foreach(site_backup_roots() as $logical=>$root) {
        $prefix=rtrim(str_replace('\\','/',$root['path']),'/').'/';
        if(str_starts_with(strtolower($v),strtolower($prefix))) return $logical.'/'.substr($v,strlen($prefix));
    }
    return null;
}

function site_backup_encode_value(mixed $value): mixed {
    if(!is_string($value)) return $value;
    if(!mb_check_encoding($value,'UTF-8')) return ['@binary'=>base64_encode($value)];
    $path=site_backup_logical_path($value);
    return $path!==null && !str_starts_with($value,'/assets/uploads/') ? ['@file'=>$path] : $value;
}
function site_backup_decode_value(mixed $value): mixed {
    if(!is_array($value)) return $value;
    if(array_keys($value)===['@file'] && is_string($value['@file'])) return site_backup_destination($value['@file']);
    if(array_keys($value)===['@binary'] && is_string($value['@binary'])) {
        $raw=base64_decode($value['@binary'],true); if($raw!==false) return $raw;
    }
    throw new RuntimeException('Invalid encoded database value.');
}

function site_backup_check_password(string $password): void {
    if(strlen($password)<12 || strlen($password)>1024) throw new RuntimeException('Use a backup password of at least 12 characters (maximum 1,024).');
    if(!class_exists('ZipArchive') || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)) throw new RuntimeException('This server needs PHP ZIP with AES-256 encryption support.');
}

function site_backup_selected_row(string $table,array $row,array $groups): bool {
    if($table==='app_settings') return in_array(site_backup_setting_group((string)$row['setting_key']),$groups,true);
    if($table==='server_media_assets') return ($row['source_owner']??'')==='avatar' && in_array(($row['source_role']??'')==='nameplate'?'nameplates':'avatars',$groups,true);
    return in_array(site_backup_table_group($table),$groups,true);
}

function site_backup_export(PDO $pdo,string $password,string $mode,array $groups=[]): array {
    site_backup_check_password($password);
    if(!in_array($mode,['complete','selective'],true)) throw new RuntimeException('Unknown backup mode.');
    $groups=array_values(array_unique($groups));
    if($mode==='selective' && (!$groups || array_diff($groups,array_keys(site_backup_groups())))) throw new RuntimeException('Choose supported backup sections.');
    $id=bin2hex(random_bytes(16));$dir=site_backup_work_root().'/export-'.$id;
    if(!mkdir($dir,0700)) throw new RuntimeException('Cannot prepare backup storage.');
    $zip=new ZipArchive();$archive=$dir.'/site.corechat';
    if($zip->open($archive,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Cannot create backup archive.');
    $schema=site_backup_schema($pdo);$limit=site_backup_limit($pdo);$total=0;
    $manifest=['format'=>SITE_BACKUP_FORMAT,'version'=>SITE_BACKUP_VERSION,'mode'=>$mode,'groups'=>$groups,'createdAt'=>gmdate('c'),'schemaVersion'=>CHATSPACE_SCHEMA_VERSION,'driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'schema'=>$schema,'tables'=>[],'files'=>[],'identities'=>[]];
    $sources=[];$referenced=[];$owners=[];
    $add=static function(string $entry,string $source) use($zip,$password,&$total,$limit): array {
        $bytes=filesize($source);if($bytes===false || ($total+=$bytes)>$limit) throw new RuntimeException('Backup exceeds the configured database import/export size limit.');
        if(!$zip->addFile($source,$entry) || !$zip->setEncryptionName($entry,ZipArchive::EM_AES_256,$password)) throw new RuntimeException('Cannot encrypt backup entry.');
        return ['bytes'=>$bytes,'sha256'=>hash_file('sha256',$source)];
    };
    $owned=!$pdo->inTransaction();
    try {
        if($owned) $pdo->beginTransaction();
        foreach($schema as $table=>$definition) {
            if($mode==='selective' && !in_array($table,['app_settings','server_media_assets'],true) && !in_array(site_backup_table_group($table),$groups,true)) continue;
            $file=$dir.'/'.$table.'.jsonl';$handle=fopen($file,'xb');$count=0;
            $query=$pdo->query('SELECT * FROM '.site_backup_identifier($table));
            while($row=$query->fetch(PDO::FETCH_ASSOC)) {
                if($mode==='selective' && !site_backup_selected_row($table,$row,$groups)) continue;
                foreach($row as $column=>$value)if(($column==='user_id'||str_ends_with($column,'_user_id')||($table==='rooms'&&$column==='owner_id')||($table==='users'&&$column==='id')) && $value!==null)$owners[(string)$value]=true;
                if($table==='app_settings'){
                    if(preg_match('/^pool-practice-user:(\d+):/',$row['setting_key'],$ownerMatch))$owners[$ownerMatch[1]]=true;
                    if(str_starts_with($row['setting_key'],'custom_emoji.item.')){$item=json_decode($row['value'],true);if(isset($item['createdBy']))$owners[(string)$item['createdBy']]=true;}
                }
                foreach($row as $value) { if(is_string($value) && ($logical=site_backup_logical_path($value))!==null)$referenced[$logical]=true; }
                $row=array_map('site_backup_encode_value',$row);
                $line=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
                if(strlen($line)>16*1024*1024)throw new RuntimeException('A backup record exceeds the supported 16 MB record limit.');
                if(fwrite($handle,$line)!==strlen($line)) throw new RuntimeException('Backup write failed.');
                $count++;
            }
            fclose($handle);$query->closeCursor();
            $manifest['tables'][$table]=['count'=>$count]+$add('tables/'.$table.'.jsonl',$file);
            $sources[]=$file;
        }
        if($mode==='selective') {
            $manifest['identities']=array_values(array_filter($pdo->query('SELECT id,username,email FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),static fn($r)=>isset($owners[(string)$r['id']])));
        }
        foreach(site_backup_roots() as $prefix=>$root) {
            // Selected accounts include the source key only for re-encryption to destination identities.
            if($mode==='selective' && !in_array($root['group'],$groups,true) && !($prefix==='private/two-factor' && in_array('accounts',$groups,true)) && !array_filter(array_keys($referenced),static fn($p)=>str_starts_with($p,$prefix.'/'))) continue;
            if(!is_dir($root['path'])) continue;
            $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root['path'],FilesystemIterator::SKIP_DOTS));
            foreach($iterator as $item) {
                if($item->isLink()) throw new RuntimeException('Backup cannot follow linked media files.');
                if(!$item->isFile() || preg_match('/\.(lock|part|tmp)$/i',$item->getFilename())) continue;
                $relative=str_replace('\\','/',substr($item->getPathname(),strlen($root['path'])+1));
                $logical=$prefix.'/'.$relative;site_backup_destination($logical);
                if($mode==='selective' && !isset($referenced[$logical]) && (in_array($root['group'],['avatars','nameplates','attachments'],true)))continue;
                if(count($manifest['files'])>=SITE_BACKUP_MAX_ENTRIES) throw new RuntimeException('Too many backup files.');
                $manifest['files'][$logical]=$add('files/'.$logical,$item->getPathname());
            }
        }
        if($owned)$pdo->commit();
        $json=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($json)>16*1024*1024 || count($manifest['tables'])+count($manifest['files'])+1>SITE_BACKUP_MAX_ENTRIES || $total+strlen($json)>$limit)throw new RuntimeException('Backup exceeds the supported archive limits.');
        if(!$zip->addFromString('manifest.json',$json) || !$zip->setEncryptionName('manifest.json',ZipArchive::EM_AES_256,$password) || !$zip->close()) throw new RuntimeException('Backup archive could not be completed.');
        foreach($sources as $source) unlink($source);
        return ['path'=>$archive,'directory'=>$dir,'bytes'=>filesize($archive),'sha256'=>hash_file('sha256',$archive),'manifest'=>$manifest];
    } catch(Throwable $e) {
        if($owned && $pdo->inTransaction())$pdo->rollBack();
        @$zip->close();
        foreach(array_keys($schema) as $table) { $temp=$dir.'/'.$table.'.jsonl'; if(is_file($temp))@unlink($temp); }
        if(is_file($archive))@unlink($archive);
        @rmdir($dir);
        throw $e;
    }
}

function site_backup_open(string $path,string $password,int $limit): array {
    site_backup_check_password($password);$zip=new ZipArchive();
    if($zip->open($path)!==true) throw new RuntimeException('Backup archive could not be opened.');
    $zip->setPassword($password);$stat=$zip->statName('manifest.json');
    if(!$stat || ($stat['encryption_method']??0)!==ZipArchive::EM_AES_256 || $stat['size']>16*1024*1024) throw new RuntimeException('Backup manifest is missing or too large.');
    $raw=$zip->getFromName('manifest.json');
    if($raw===false) throw new RuntimeException('Wrong backup password or damaged archive.');
    $m=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(($m['format']??'')!==SITE_BACKUP_FORMAT || ($m['version']??0)!==SITE_BACKUP_VERSION || !in_array($m['mode']??'',['complete','selective'],true)) throw new RuntimeException('Unsupported site backup format.');
    if(!is_array($m['groups']??null) || array_diff($m['groups'],array_keys(site_backup_groups())))throw new RuntimeException('Unknown backup sections.');
    $expected=['manifest.json'=>true];$normalized=[];$total=strlen($raw);
    foreach(['tables','files'] as $kind) {
        if(!is_array($m[$kind]??null)) throw new RuntimeException('Incomplete backup manifest.');
        foreach($m[$kind] as $key=>$identity) {
            if($kind==='tables')site_backup_identifier($key);else site_backup_destination($key);
            $entry=$kind.'/'.$key.($kind==='tables'?'.jsonl':'');
            if(isset($normalized[strtolower($entry)]))throw new RuntimeException('Backup contains colliding file names.');
            $normalized[strtolower($entry)]=true;
            if(!is_array($identity) || !is_int($identity['bytes']??null) || $identity['bytes']<0 || !preg_match('/^[a-f0-9]{64}$/D',$identity['sha256']??''))throw new RuntimeException('Invalid backup entry identity.');
            $s=$zip->statName($entry);
            if(!$s || ($s['encryption_method']??0)!==ZipArchive::EM_AES_256 || $s['size']!==$identity['bytes'] || ($total+=$s['size'])>$limit) throw new RuntimeException('Backup size or file inventory is invalid.');
            $stream=$zip->getStream($entry);if(!$stream)throw new RuntimeException('Cannot decrypt backup entry.');
            $hash=hash_init('sha256');$read=hash_update_stream($hash,$stream);fclose($stream);
            if($read!==$identity['bytes'] || !hash_equals($identity['sha256'],hash_final($hash)))throw new RuntimeException('Backup file verification failed.');
            $expected[$entry]=true;
        }
    }
    if($zip->numFiles!==count($expected) || $zip->numFiles>SITE_BACKUP_MAX_ENTRIES)throw new RuntimeException('Backup contains duplicate or unlisted entries.');
    for($i=0;$i<$zip->numFiles;$i++) if(!isset($expected[$zip->getNameIndex($i)]))throw new RuntimeException('Backup contains an unexpected entry.');
    return ['zip'=>$zip,'manifest'=>$m,'sha256'=>hash_file('sha256',$path)];
}

function site_backup_rows(array $archive,string $table): Generator {
    $stream=$archive['zip']->getStream('tables/'.$table.'.jsonl');
    if(!$stream)throw new RuntimeException('Missing table data.');
    $count=0;
    try {
        while(($line=fgets($stream,16*1024*1024+1))!==false) {
            if(!str_ends_with($line,"\n"))throw new RuntimeException('A backup record is incomplete or too large.');
            $row=json_decode($line,true,64,JSON_THROW_ON_ERROR);
            if(!is_array($row))throw new RuntimeException('Invalid backup row.');
            $count++;yield array_map('site_backup_decode_value',$row);
        }
    }finally{fclose($stream);}
    if($count!==$archive['manifest']['tables'][$table]['count'])throw new RuntimeException('Backup row count mismatch.');
}

function site_backup_row_identity(string $table,array $row,array $definition): array {
    if($table==='users') return ['email'=>$row['email']];
    if(isset($row['public_id'])) return ['public_id'=>$row['public_id']];
    if($table==='member_profiles' && isset($row['public_profile_id'])) return ['public_profile_id'=>$row['public_profile_id']];
    $natural=match($table){
        'member_display_name_history'=>['user_id','change_request_id'],
        'avatar_hidden_preferences'=>['viewer_user_id','target_user_id','preference_key'],
        'gesture_package_generations'=>['gesture_id','generation'],
        'room_ejections'=>['room_id','user_id','created_at'],
        default=>null,
    };
    if($natural!==null)return array_intersect_key($row,array_fill_keys($natural,true));
    if($definition['primary']===['id'])throw new RuntimeException('This selected table needs a stable import identity: '.$table);
    $identity=[];foreach($definition['primary'] as $column)$identity[$column]=$row[$column];
    if(!$identity)throw new RuntimeException('This table has no safe import identity: '.$table);
    return $identity;
}
function site_backup_find(PDO $pdo,string $table,array $identity): ?array {
    $where=[];$args=[];
    foreach($identity as $key=>$value) {if($value===null){$where[]=site_backup_identifier($key).' IS NULL';}else{$where[]=site_backup_identifier($key).' = ?';$args[]=$value;}}
    $q=$pdo->prepare('SELECT * FROM '.site_backup_identifier($table).' WHERE '.implode(' AND ',$where).' LIMIT 1');$q->execute($args);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function site_backup_order(array $tables,array $schema): array {
    $result=[];usort($tables,static fn($a,$b)=>($a==='users'?-1:($b==='users'?1:strcmp($a,$b))));$pending=array_fill_keys($tables,true);
    while($pending) {
        $progress=false;
        foreach(array_keys($pending) as $table) {
            $waiting=false;foreach($schema[$table]['foreign'] as $fk)if($fk['target']!==$table && isset($pending[$fk['target']]))$waiting=true;
            // current_room_id is never carried into a selective account import.
            if($table==='users')$waiting=false;
            if($waiting)continue;
            $result[]=$table;unset($pending[$table]);$progress=true;
        }
        if(!$progress)throw new RuntimeException('Selected data has a circular dependency that needs a complete restore.');
    }
    return $result;
}

function site_backup_plan(PDO $pdo,array $archive,array $selected=[],string $policy='keep'): array {
    $m=$archive['manifest'];$schema=site_backup_schema($pdo);$complete=$m['mode']==='complete';
    if(($m['schemaVersion']??'')!==CHATSPACE_SCHEMA_VERSION || ($complete && (($m['driver']??'')!==$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) || ($m['schema']??[])!==$schema)))throw new RuntimeException('Install the matching CoreChat release and database type before restoring this site archive. Legacy SQLite/JSON imports remain available separately.');
    if(!in_array($policy,['keep','replace'],true))throw new RuntimeException('Choose Keep existing or Replace selected content.');
    if($complete && array_keys($m['tables'])!==array_keys($schema))throw new RuntimeException('Complete backup is missing database tables.');
    if(!$complete) {
        $selected=$selected?:$m['groups'];
        if(!$selected || array_diff($selected,$m['groups']))throw new RuntimeException('The selected sections are not in this archive.');
    }
    $summary=['mode'=>$m['mode'],'sections'=>$complete?['Complete installation data']:$selected,'add'=>0,'update'=>0,'skip'=>0,'filesAdd'=>0,'filesReplace'=>0,'filesSkip'=>0,'warnings'=>[]];
    $plan=['summary'=>$summary,'rows'=>[],'files'=>[],'schema'=>$schema,'mode'=>$m['mode'],'selected'=>$selected,'policy'=>$policy,'archiveHash'=>$archive['sha256'],'source'=>$archive];
    $tables=[];$sourceRows=[];$referenced=[];$maps=[];$next=[];$skippedUsers=[];$totalRows=0;$factorCount=0;$administratorCount=0;
    foreach($m['tables'] as $table=>$metadata) {
        if(!isset($schema[$table]))throw new RuntimeException('Unknown backup table.');
        if(!$complete && !in_array($table,['app_settings','server_media_assets'],true) && !in_array(site_backup_table_group($table),$selected,true)) continue;
        $tables[]=$table;
        foreach(site_backup_rows($archive,$table) as $row) {
            $actualColumns=array_keys($row);$expectedColumns=$schema[$table]['columns'];sort($actualColumns);sort($expectedColumns);
            if($actualColumns!==$expectedColumns)throw new RuntimeException('Backup columns do not match '.$table.'.');
            if(!$complete && !site_backup_selected_row($table,$row,$selected))continue;
            if(!$complete && ++$totalRows>250000)throw new RuntimeException('This archive exceeds the 250,000-record interactive restore limit.');
            foreach($row as $column=>$value) {
                if(!is_string($value)||$value==='')continue;
                $logical=site_backup_logical_path($value);
                if(in_array($column,['storage_path','preview_path'],true) && (str_starts_with($value,'/') || preg_match('/^[A-Za-z]:/',$value)) && $logical===null)throw new RuntimeException('A media record points outside supported backup storage.');
                $removed=($table==='server_media_assets' && ($row['status']??'active')!=='active') || ($table==='gestures' && !empty($row['deleted_at']));
                if($logical!==null && !$removed)$referenced[$logical]=true;
            }
            if($complete) {
                $plan['summary']['add']++;
                if($table==='account_two_factor' && !empty($row['secret_ciphertext']))$factorCount++;
                if($table==='users' && ($row['role']??'')==='admin')$administratorCount++;
            } else {
                $limit=ini_get('memory_limit');$unit=strtolower(substr($limit,-1));$memory=(int)$limit*match($unit){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};
                if($memory>0 && memory_get_usage(true)>$memory*0.65)throw new RuntimeException('Selected data exceeds the server interactive memory limit. Import fewer sections at a time.');
                $sourceRows[$table][]=$row;
            }
        }
    }
    if(!$complete) {
        foreach($m['identities']??[] as $identity) {
            $found=site_backup_find($pdo,'users',['email'=>$identity['email']??'']);
            if($found && strtolower((string)$found['username'])!==strtolower((string)($identity['username']??'')))throw new RuntimeException('An account email belongs to a different username. Resolve that identity conflict before importing.');
            if($found)$maps['users'][(string)$identity['id']]=$found['id'];
        }
    }
    $emojiSkip=[];$emojiRemove=[];
    if(!$complete)foreach($sourceRows['app_settings']??[] as $setting) {
        if(!str_starts_with($setting['setting_key'],'custom_emoji.name.'))continue;
        $found=site_backup_find($pdo,'app_settings',['setting_key'=>$setting['setting_key']]);
        if($found && $found['value']!==$setting['value']) {
            if($policy==='keep')$emojiSkip[]=$setting['value'];else $emojiRemove[]=$found['value'];
        }
    }
    $ordered=$complete?array_keys($sourceRows):site_backup_order(array_keys($sourceRows),$schema);
    foreach($ordered as $table) {
        $def=$schema[$table];$auto=$def['primary']===['id'];
        if(!$complete && $auto)$next[$table]=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM '.site_backup_identifier($table))->fetchColumn();
        foreach($sourceRows[$table]??[] as $original) {
            $row=$original;$mergeIndex=false;
            if(!$complete) {
                if($table==='users') {$row['current_room_id']=null;$row['last_seen_at']=null;}
                foreach(site_backup_selective_foreign($def) as $fk) {
                    $column=$fk['col'];$value=$row[$column]??null;
                    if($value===null || $value==='')continue;
                    if($fk['ref']!=='id')continue;
                    if(isset($maps[$fk['target']][(string)$value]))$row[$column]=$maps[$fk['target']][(string)$value];
                    else throw new RuntimeException('Missing dependency: include '.$fk['target'].' before '.$table.', or import its matching account/content first.');
                }
                if($table==='app_settings' && preg_match('/^pool-practice-user:(\d+):(.*)$/D',$row['setting_key'],$match)) {
                    if(!isset($maps['users'][$match[1]]))throw new RuntimeException('A personal Pool setup has no matching owner.');
                    $row['setting_key']='pool-practice-user:'.$maps['users'][$match[1]].':'.$match[2];
                }
                if($table==='app_settings' && str_starts_with($row['setting_key'],'custom_emoji.item.')) {
                    $emoji=json_decode($row['value'],true,16,JSON_THROW_ON_ERROR);
                    if(in_array($emoji['id']??'',$emojiSkip,true)){$plan['summary']['skip']++;continue;}
                    if(isset($emoji['createdBy'])) {
                        if(!isset($maps['users'][(string)$emoji['createdBy']]))throw new RuntimeException('A custom emoji has no matching account owner.');
                        $emoji['createdBy']=$maps['users'][(string)$emoji['createdBy']];
                    }
                    $row['value']=json_encode($emoji,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                }
                if($table==='app_settings' && $row['setting_key']==='custom_emoji.registry.v1') {
                    $prior=site_backup_find($pdo,'app_settings',['setting_key'=>$row['setting_key']]);
                    $old=json_decode((string)($prior['value']??'[]'),true,16,JSON_THROW_ON_ERROR);
                    $incoming=json_decode($row['value'],true,16,JSON_THROW_ON_ERROR);
                    $ids=array_values(array_unique(array_merge(array_diff($old,$emojiRemove),array_diff($incoming,$emojiSkip))));
                    if(count($ids)>500)throw new RuntimeException('The combined emoji library exceeds its 500-entry limit.');
                    $row['value']=json_encode($ids,JSON_THROW_ON_ERROR);$mergeIndex=true;
                }
                if($table==='server_media_assets') {
                    $row['session_id']=null;
                    if(($row['source_owner']??'')!=='avatar')throw new RuntimeException('Unsupported selective media source.');
                }
            }
            $identity=site_backup_row_identity($table,$row,$def);
            $existing=$complete?null:site_backup_find($pdo,$table,$identity);
            if(!$complete && $auto) {
                $row['id']=$existing?$existing['id']:++$next[$table];
                $maps[$table][(string)$original['id']]=$row['id'];
            }
            if(!$complete && $table==='users' && $existing) {
                if(strtolower((string)$existing['username'])!==strtolower((string)$row['username']))throw new RuntimeException('The destination account uses a different username.');
                foreach(['password_hash','recovery_code_hash','recovery_code_suffix','role','email','username','password_changed_at','email_changed_at','current_room_id','last_seen_at'] as $protected)if(array_key_exists($protected,$row))$row[$protected]=$existing[$protected];
                $skippedUsers[(string)$row['id']]=true;
            }
            if(!$complete && in_array($table,['account_two_factor','account_two_factor_backup','account_email_state','account_two_factor_email'],true) && isset($skippedUsers[(string)$row['user_id']])) {$plan['summary']['skip']++;continue;}
            if(!$complete && $table==='member_profiles' && $existing && $existing['user_id']!=$row['user_id'])throw new RuntimeException('Profile identity conflicts with another account.');
            $same=$existing && site_backup_normalize_row($row)===site_backup_normalize_row($existing);
            $action=$complete?'insert':($existing?($same||($policy==='keep'&&!$mergeIndex)?'skip':'update'):'insert');
            $plan['summary'][$action==='insert'?'add':($action==='update'?'update':'skip')]++;
            $plan['rows'][]=['table'=>$table,'row'=>$row,'original'=>$original,'action'=>$action,'identity'=>$existing?site_backup_row_identity($table,$existing,$def):$identity,'before'=>$existing];
        }
    }
    foreach($m['files'] as $logical=>$metadata) {
        $group=null;foreach(site_backup_roots() as $prefix=>$root)if(str_starts_with($logical,$prefix.'/')){$group=$root['group'];break;}
        if(!$complete && !in_array($group,$selected,true) && !isset($referenced[$logical]))continue;
        if(!$complete && in_array($group,['avatars','nameplates'],true) && !isset($referenced[$logical]))continue;
        $path=site_backup_destination($logical);$exists=is_file($path);$hash=$exists?hash_file('sha256',$path):null;
        $action=$exists?($hash===$metadata['sha256']?'skip':'replace'):'add';
        // Selective merge never overwrites different bytes at an existing path.
        if(!$complete && $action==='replace')throw new RuntimeException('A media filename already contains different content. Rename/re-export that media before importing; existing files were not changed.');
        $plan['files'][]=['logical'=>$logical,'path'=>$path,'identity'=>$metadata,'action'=>$action,'before'=>$hash];
        $plan['summary'][$action==='add'?'filesAdd':($action==='replace'?'filesReplace':'filesSkip')]++;
    }
    foreach($referenced as $logical=>$_)if(!isset($m['files'][$logical]) && !is_file(site_backup_destination($logical)))throw new RuntimeException('A referenced media file is missing: '.$logical);
    if($complete && $factorCount>0 && !isset($m['files']['private/two-factor/key-v1.bin']))throw new RuntimeException('Complete backup is missing its authenticator key.');
    if($complete && $administratorCount<1)throw new RuntimeException('A complete backup must contain an administrator.');
    if(!$complete){site_backup_validate_bot_portraits($pdo,$plan);site_backup_prepare_factors($plan,$archive);}
    $plan['summary']['warnings'][]=$complete?'Restores all database records and backed-up media. Everyone must sign in again. Website code and host connection settings stay in place.':'Existing passwords, account roles and two-factor authentication are preserved. Missing owners must be imported or matched before their content.';
    $plan['summary']['warnings'][]='Device-only logins, saved connections and browser-held encryption keys are not in this website archive.';
    $fingerprint=['archive'=>$archive['sha256'],'mode'=>$m['mode'],'selected'=>$selected,'policy'=>$policy,'before'=>array_map(static fn($r)=>[$r['table'],$r['identity'],$r['before']],$plan['rows']),'files'=>array_map(static fn($f)=>[$f['logical'],$f['before']],$plan['files'])];
    $plan['fingerprint']=hash('sha256',json_encode($fingerprint,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
    return $plan;
}
function site_backup_normalize_row(array $row): array { return array_map(static fn($v)=>$v===null?null:(string)$v,$row); }

function site_backup_prepare_factors(array &$plan,array $archive): void {
    $needed=false;foreach($plan['rows'] as $r)if($r['table']==='account_two_factor' && $r['action']==='insert' && !empty($r['row']['secret_ciphertext']))$needed=true;
    if(!$needed)return;
    $source=$archive['zip']->getFromName('files/private/two-factor/key-v1.bin');
    if(!is_string($source) || strlen($source)!==32)throw new RuntimeException('The imported accounts require their authenticator encryption key.');
    $path=site_backup_destination('private/two-factor/key-v1.bin');$target=is_file($path)?file_get_contents($path):random_bytes(32);
    if(!is_string($target)||strlen($target)!==32)throw new RuntimeException('The destination authenticator key is invalid.');
    if(!is_file($path))$plan['newFactorKey']=$target;
    foreach($plan['rows'] as &$record) {
        if($record['table']!=='account_two_factor'||$record['action']!=='insert'||empty($record['row']['secret_ciphertext']))continue;
        $bytes=base64_decode($record['row']['secret_ciphertext'],true);
        if($bytes===false||strlen($bytes)<29)throw new RuntimeException('Invalid authenticator record.');
        $secret=openssl_decrypt(substr($bytes,28),'aes-256-gcm',$source,OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16),'CoreChat-TOTP-v1:'.$record['original']['user_id']);
        if($secret===false)throw new RuntimeException('Cannot verify the imported authenticator.');
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($secret,'aes-256-gcm',$target,OPENSSL_RAW_DATA,$iv,$tag,'CoreChat-TOTP-v1:'.$record['row']['user_id'],16);
        if($cipher===false)throw new RuntimeException('Cannot protect the imported authenticator.');
        $record['row']['secret_ciphertext']=base64_encode($iv.$tag.$cipher);
    }
    unset($record);
}

function site_backup_write_json(string $path,array $value): void {
    $temp=$path.'.part';$text=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    if(file_put_contents($temp,$text,LOCK_EX)!==strlen($text) || !rename($temp,$path))throw new RuntimeException('Cannot commit the restore journal.');
    @chmod($path,0600);
}
function site_backup_runtime_guard(): void {
    $root=site_backup_work_root();$handle=fopen($root.'/installation.lock','c+');
    if(!$handle || !flock($handle,LOCK_SH|LOCK_NB)) {http_response_code(503);header('Retry-After: 5');exit('A site backup or restore is in progress. Please retry shortly.');}
    $GLOBALS['site_backup_request_lock']=$handle;
    if(is_file($root.'/pending.json')) {
        flock($handle,LOCK_UN);
        if(!flock($handle,LOCK_EX|LOCK_NB)) {http_response_code(503);exit('Site recovery is in progress.');}
        try {site_backup_reconcile(db());}catch(Throwable $e){error_log('SITE_BACKUP_RECOVERY_REQUIRED');http_response_code(503);exit('Site restore recovery needs administrator attention. Original recovery files are retained.');}
        flock($handle,LOCK_SH);
    }
}
function site_backup_exclusive_lock() {
    $handle=$GLOBALS['site_backup_request_lock']??fopen(site_backup_work_root().'/installation.lock','c+');
    if(!$handle)throw new RuntimeException('Cannot lock site restoration.');
    flock($handle,LOCK_UN);
    if(!flock($handle,LOCK_EX|LOCK_NB))throw new RuntimeException('Other requests are still running. Retry when the room is quiet. Nothing was imported.');
    return $handle;
}
function site_backup_restore_files(array $journal,bool $committed): void {
    $root=site_backup_work_root();
    if(!preg_match('/^[a-f0-9]{32}$/D',$journal['id']??''))throw new RuntimeException('Invalid recovery journal identity.');
    $dir=$root.'/restore-'.$journal['id'];
    foreach($journal['files'] as $index=>$file) {
        $target=site_backup_destination($file['logical']);
        $old=$dir.'/old-'.$index;$fresh=$dir.'/new-'.$index;
        if(!$committed) {
            if($file['before']!==null) {
                if(!is_file($old)||hash_file('sha256',$old)!==$file['before'])throw new RuntimeException('Recovery copy failed verification.');
                if(is_file($target)&&hash_file('sha256',$target)===$file['before'])continue;
                $tmp=$target.'.restore-part';
                if(!copy($old,$tmp)||hash_file('sha256',$tmp)!==$file['before']||!rename($tmp,$target))throw new RuntimeException('Cannot restore original media file.');
            }elseif(is_file($target)){
                if(hash_file('sha256',$target)!==$file['after'])throw new RuntimeException('Recovery refuses to remove a changed media file.');
                if(!unlink($target))throw new RuntimeException('Cannot remove newly restored media.');
            }
        }elseif(!is_file($target)||hash_file('sha256',$target)!==$file['after'])throw new RuntimeException('Committed media failed verification.');
    }
    // Remove only generated names within this exact recorded attempt.
    foreach($journal['files'] as $index=>$file)foreach(['old-','new-'] as $prefix){$p=$dir.'/'.$prefix.$index;if(is_file($p)&&!unlink($p))throw new RuntimeException('Cannot clean completed recovery staging.');}
    if(is_dir($dir))@rmdir($dir);
    if(!unlink($root.'/pending.json'))throw new RuntimeException('Cannot close recovery journal.');
}
function site_backup_reconcile(PDO $pdo): void {
    $path=site_backup_work_root().'/pending.json';if(!is_file($path))return;
    $journal=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    $q=$pdo->prepare('SELECT value FROM app_settings WHERE setting_key=?');$q->execute(['site_backup_commit']);
    site_backup_restore_files($journal,hash_equals((string)$journal['id'],(string)$q->fetchColumn()));
}
function site_backup_assert_foreign_keys(PDO $pdo,array $schema): void {
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        if($pdo->query('PRAGMA foreign_key_check')->fetch())throw new RuntimeException('Restored data has missing relationships.');return;
    }
    foreach($schema as $table=>$def)foreach($def['foreign'] as $fk) {
        $col=site_backup_identifier($fk['col']);$ref=site_backup_identifier($fk['ref']);
        $sql='SELECT 1 FROM '.site_backup_identifier($table).' a LEFT JOIN '.site_backup_identifier($fk['target'])." b ON a.$col=b.$ref WHERE a.$col IS NOT NULL AND b.$ref IS NULL LIMIT 1";
        if($pdo->query($sql)->fetchColumn())throw new RuntimeException('Restored data has missing relationships in '.$table.'.');
    }
}
function site_backup_assert_transactional(PDO $pdo): void {
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return;
    $tables=$pdo->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\'')->fetchAll(PDO::FETCH_ASSOC);
    foreach($tables as $table)if(strtolower((string)$table['ENGINE'])!=='innodb')throw new RuntimeException('Complete/selected restore requires InnoDB tables for rollback safety.');
}
function site_backup_apply(PDO $pdo,array $plan,string $password,string $expectedFingerprint): array {
    site_backup_assert_transactional($pdo);
    if($pdo->inTransaction())throw new RuntimeException('Restore cannot run inside another transaction.');
    $lock=site_backup_exclusive_lock();$journal=null;$committed=false;$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    try {
        site_backup_reconcile($pdo);
        $current=site_backup_plan($pdo,$plan['source'],$plan['selected'],$plan['policy']);
        if(!hash_equals($expectedFingerprint,$current['fingerprint']))throw new RuntimeException('The destination changed after preview. Review it again before importing.');
        $plan=$current;
        // Recovery captures the current installation, including private keys and media.
        $recovery=site_backup_export($pdo,$password,'complete');
        $id=bin2hex(random_bytes(16));$dir=site_backup_work_root().'/restore-'.$id;
        if(!mkdir($dir,0700))throw new RuntimeException('Cannot stage restore.');
        $files=[];
        foreach($plan['files'] as $file) {
            if($file['action']==='skip')continue;
            $index=count($files);$source=$plan['source']['zip']->getStream('files/'.$file['logical']);$dest=fopen($dir.'/new-'.$index,'xb');
            if(!$source||!$dest)throw new RuntimeException('Cannot stage media.');
            $count=stream_copy_to_stream($source,$dest);fclose($source);fclose($dest);
            if($count!==$file['identity']['bytes']||hash_file('sha256',$dir.'/new-'.$index)!==$file['identity']['sha256'])throw new RuntimeException('Staged media did not match the archive.');
            if($file['before']!==null && (!copy($file['path'],$dir.'/old-'.$index)||hash_file('sha256',$dir.'/old-'.$index)!==$file['before']))throw new RuntimeException('Cannot back up the existing media.');
            $files[]=['logical'=>$file['logical'],'before'=>$file['before'],'after'=>$file['identity']['sha256']];
        }
        if(isset($plan['newFactorKey'])) {
            $index=count($files);$bytes=$plan['newFactorKey'];file_put_contents($dir.'/new-'.$index,$bytes,LOCK_EX);
            $files[]=['logical'=>'private/two-factor/key-v1.bin','before'=>null,'after'=>hash('sha256',$bytes)];
        }
        $journal=['id'=>$id,'files'=>$files,'recovery'=>basename($recovery['directory']).'/site.corechat'];
        site_backup_write_json(site_backup_work_root().'/pending.json',$journal);
        if($mysql)$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->beginTransaction();if(!$mysql)$pdo->exec('PRAGMA defer_foreign_keys=ON');
        if($plan['mode']==='complete')foreach(array_reverse(array_keys($plan['schema'])) as $table)$pdo->exec('DELETE FROM '.site_backup_identifier($table));
        foreach(site_backup_operations($plan) as $record) {
            if($record['action']==='skip')continue;
            $row=$record['row'];$table=$record['table'];
            if($plan['mode']==='complete' && $table==='users'){$row['current_room_id']=null;$row['last_seen_at']=null;}
            if($plan['mode']==='complete' && $table==='participants'){$row['last_seen_at']='1970-01-01 00:00:00';$row['join_token']=bin2hex(random_bytes(32));$row['webcam_enabled']=0;}
            if($record['action']==='update') {
                $set=[];$args=[];foreach($row as $key=>$value){$set[]=site_backup_identifier($key).'=?';$args[]=$value;}
                $where=[];foreach($record['identity'] as $key=>$value){if($value===null){$where[]=site_backup_identifier($key).' IS NULL';}else{$where[]=site_backup_identifier($key).'=?';$args[]=$value;}}
                $sql='UPDATE '.site_backup_identifier($table).' SET '.implode(',',$set).' WHERE '.implode(' AND ',$where);
            } else {
                $sql='INSERT INTO '.site_backup_identifier($table).' ('.implode(',',array_map('site_backup_identifier',array_keys($row))).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')';$args=array_values($row);
            }
            $pdo->prepare($sql)->execute($args);
        }
        site_backup_assert_foreign_keys($pdo,$plan['schema']);
        if($plan['mode']==='complete') {
            if((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn()<1)throw new RuntimeException('Complete restore must contain an administrator.');
            set_app_setting($pdo,'site_backup_auth_epoch',bin2hex(random_bytes(16)));
        }
        set_app_setting($pdo,'site_backup_commit',$id);
        foreach($files as $index=>$file) {
            $target=site_backup_destination($file['logical']);$parent=dirname($target);
            if(!is_dir($parent)&&!mkdir($parent,0700,true)&&!is_dir($parent))throw new RuntimeException('Cannot create restored media directory.');
            $tmp=$target.'.restore-part';
            if(!copy($dir.'/new-'.$index,$tmp)||hash_file('sha256',$tmp)!==$file['after']||!rename($tmp,$target))throw new RuntimeException('Cannot activate restored media.');
            @chmod($target,str_starts_with($file['logical'],'uploads/')?0644:0600);
        }
        $pdo->commit();$committed=true;
        if($mysql)$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        site_backup_restore_files($journal,true);$journal=null;
        return ['ok'=>true,'summary'=>$plan['summary'],'recovery'=>basename($recovery['directory']).'/site.corechat','signInRequired'=>$plan['mode']==='complete'];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($mysql)$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        // Re-read the durable marker: a connection failure during COMMIT can have an uncertain outcome.
        if($journal!==null)site_backup_reconcile($pdo);
        throw $e;
    }finally{flock($lock,LOCK_UN);}
}

function site_backup_selective_foreign(array $definition): array {
    $foreign=$definition['foreign'];$known=array_column($foreign,'col');
    foreach($definition['columns'] as $column) {
        if(!in_array($column,$known,true) && ($column==='user_id' || str_ends_with($column,'_user_id'))) $foreign[]=['col'=>$column,'target'=>'users','ref'=>'id'];
    }
    return $foreign;
}

function site_backup_operations(array $plan): Generator {
    if($plan['mode']!=='complete') { yield from $plan['rows']; return; }
    foreach(array_keys($plan['source']['manifest']['tables']) as $table) {
        foreach(site_backup_rows($plan['source'],$table) as $row) yield ['table'=>$table,'row'=>$row,'action'=>'insert'];
    }
}

function site_backup_validate_bot_portraits(PDO $pdo,array $plan): void {
    $settings=[];$assets=[];$portraits=[];
    foreach($plan['rows'] as $record) {
        $row=$record['action']==='skip'?$record['before']:$record['row'];
        if(!$row)continue;
        if($record['table']==='server_media_assets')$assets[$row['public_id']]=$row;
        if($record['table']==='app_settings') {
            $settings[$row['setting_key']]=$row['value'];
            if(str_starts_with($row['setting_key'],'game.bot_avatar.seat.') && $row['value']!=='')$portraits[]=$row['value'];
        }
    }
    foreach($portraits as $id) {
        $asset=$assets[$id]??site_backup_find($pdo,'server_media_assets',['public_id'=>$id]);
        $shared=$settings['avatar_library.shared.'.$id]??app_setting($pdo,'avatar_library.shared.'.$id);
        $deleted=$settings['avatar_library.deleted.'.$id]??app_setting($pdo,'avatar_library.deleted.'.$id);
        if(!$asset || $shared!=='1' || $deleted==='1' || $asset['source_owner']!=='avatar' || $asset['source_role']!=='avatar' || $asset['status']!=='active')throw new RuntimeException('A bot avatar needs its community avatar library item. Include Avatar libraries and its account owner, or import them first.');
    }
}
