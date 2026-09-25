<?php
declare(strict_types=1);

/** Server-placed originals only. Reuses static extraction and atomic pack activation. */
function ocx_media_inbox_names(string $game): array
{
    return match ($game) {
        'five-dice' => ['Yahtzee-mychange.ocx', 'Yahtzee.ocx'],
        'checkers' => ['Checkers.ocx'], 'chess' => ['Chess.ocx'],
        'acey-deucy' => ['Acey Deucy.ocx'], 'battleship' => ['Battleship.ocx'],
        'spades' => ['Spades.ocx'], 'backgammon-first-party' => ['Backgammon.ocx'],
        default => throw new RuntimeException('Unsupported Classic inbox game.'),
    };
}

function ocx_media_inbox_directory(): string
{
    $directory = security_private_storage_directory('ocx-inbox');
    $real = realpath($directory);
    $public = realpath(dirname(__DIR__));
    if (is_link($directory) || $real === false || $public === false
        || str_starts_with(strtolower($real).DIRECTORY_SEPARATOR, strtolower($public).DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('The Classic inbox must be a private directory outside the website.');
    }
    return $real;
}

function ocx_media_inbox_receipts(string $directory): array
{
    $path = $directory.'/.import-receipts.json';
    if (is_link($path)) throw new RuntimeException('Invalid Classic inbox receipt.');
    if (!is_file($path)) return [];
    if (filesize($path) > 32768) throw new RuntimeException('Invalid Classic inbox receipt.');
    $data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Invalid Classic inbox receipt.');
    return $data;
}

function ocx_media_inbox_status(string $game): array
{
    $names = ocx_media_inbox_names($game);
    $directory = ocx_media_inbox_directory();
    $status = ['directory'=>$directory, 'acceptedNames'=>$names, 'pending'=>false, 'file'=>null];
    $entries = scandir($directory);
    if ($entries === false) throw new RuntimeException('Cannot read the Classic inbox.');
    foreach ($names as $name) {
        $matches = array_values(array_filter($entries, static fn($entry)=>strcasecmp($entry,$name)===0));
        if (count($matches)>1) throw new RuntimeException('Duplicate Classic inbox names: keep only one '.$name.'.');
        if (!$matches) continue;
        $file = $directory.DIRECTORY_SEPARATOR.$matches[0];
        if (is_link($file) || !is_file($file)) throw new RuntimeException('The Classic inbox source must be a regular file.');
        $size = filesize($file);
        if ($size === false || $size < 1 || $size > OCX_STATIC_MEDIA_MAX_CONTAINER_BYTES) throw new RuntimeException('The Classic inbox source must be between 1 byte and 64 MiB.');
        $sha = hash_file('sha256', $file);
        if ($sha === false) throw new RuntimeException('Cannot read the Classic inbox source.');
        $receipt = ocx_media_inbox_receipts($directory)[$game] ?? null;
        return array_replace($status, ['sha256'=>$sha, 'bytes'=>$size, 'file'=>$matches[0], 'pending'=>!is_array($receipt) || ($receipt['sha256']??'')!==$sha]);
    }
    return $status;
}

function ocx_media_inbox_is_complete(array $status): bool
{
    return !empty($status['classicComplete']) && empty($status['missing']) && empty($status['invalid'])
        && array_filter($status['optional']??[],static fn($slot)=>($slot['state']??'')!=='installed')===[];
}

function ocx_media_inbox_projection(string $game, array $packStatus = []): array
{
    try {
        $status = ocx_media_inbox_is_complete($packStatus)
            ? ['directory'=>ocx_media_inbox_directory(),'acceptedNames'=>ocx_media_inbox_names($game),'pending'=>false,'fullyInstalled'=>true]
            : ocx_media_inbox_status($game);
        $scan = ocx_media_inbox_scan_state($status['directory']);
        $status['lastCheckAt'] = $scan['checkedAt'] ?? null;
        $status['lastResult'] = $scan['results'][$game] ?? null;
        return $status;
    }
    catch (Throwable $error) { return ['pending'=>false, 'error'=>$error->getMessage(), 'acceptedNames'=>ocx_media_inbox_names($game)]; }
}

function ocx_media_inbox_scan_state(string $directory): array
{
    $path = $directory.'/.scan-state.json';
    if (is_link($path)) throw new RuntimeException('Invalid Classic inbox check record.');
    if (!is_file($path)) return [];
    if (filesize($path)>32768) throw new RuntimeException('Invalid Classic inbox check record.');
    $data = json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Invalid Classic inbox check record.');
    return $data;
}

/** Daily runtime work; a new setup or successful update forces an additional check. */
function ocx_media_inbox_scan(PDO $pdo, bool $force = false, ?int $now = null, int $maxImports = 0): array
{
    $owner = moderation_identity_owner($pdo);
    if ($owner === null) return ['status'=>'waiting-for-owner'];
    if ($pdo->inTransaction()) return ['status'=>'waiting-for-commit'];
    $now ??= time();
    $directory = ocx_media_inbox_directory();
    $path = $directory.'/.scan.lock';
    if (is_link($path)) throw new RuntimeException('Invalid Classic inbox check lock.');
    $lock = fopen($path,'c');
    if ($lock === false) throw new RuntimeException('Cannot lock the Classic inbox check.');
    try {
        if (!flock($lock,LOCK_EX|LOCK_NB)) return ['status'=>'already-running'];
        $previous = ocx_media_inbox_scan_state($directory);
        if (!$force && empty($previous['remaining']) && $now-(int)($previous['checkedUnix']??0)<86400) return ['status'=>'not-due'];
        $games = ['five-dice','checkers','chess','acey-deucy','battleship','spades','backgammon-first-party'];
        $remaining = !$force && !empty($previous['remaining']) ? array_values(array_intersect($games,(array)$previous['remaining'])) : $games;
        $results = !$force && !empty($previous['remaining']) ? (array)($previous['results']??[]) : [];
        $imports = 0;
        while ($remaining) {
            $game = array_shift($remaining);
            try {
                $result = ocx_media_inbox_import($pdo,$game,(int)$owner['userId']);
                $results[$game] = ['operation'=>$result['operation']];
                if (in_array($result['operation'],['installed','replaced'],true)) $imports++;
            } catch (Throwable $error) {
                $results[$game] = ['operation'=>'failed','error'=>$error->getMessage()];
                $imports++;
            }
            if ($maxImports>0 && $imports>=$maxImports) break;
        }
        $state = ['status'=>$remaining?'processing':'checked','checkedUnix'=>$now,'checkedAt'=>gmdate('c',$now),'remaining'=>$remaining,'results'=>$results];
        $temporary = $directory.'/.scan-'.bin2hex(random_bytes(8));
        try {
            $bytes = json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temporary,$bytes,LOCK_EX)!==strlen($bytes) || !rename($temporary,$directory.'/.scan-state.json')) throw new RuntimeException('Cannot save the Classic inbox check record.');
        } finally { if (is_file($temporary)) unlink($temporary); }
        return $state;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

function ocx_media_inbox_schedule(PDO $pdo, bool $force = false): void
{
    static $queued = false, $forceQueued = false;
    if (PHP_SAPI==='cli') return; // CLI tools/tests do not implicitly import host media.
    $forceQueued = $forceQueued || $force;
    if ($queued) return;
    try {
        if (moderation_identity_owner($pdo)===null) return;
        $state = ocx_media_inbox_scan_state(ocx_media_inbox_directory());
        if (!$forceQueued && empty($state['remaining']) && time()-(int)($state['checkedUnix']??0)<86400) return;
        $queued = true;
        register_shutdown_function(static function () use ($pdo,&$forceQueued): void {
            // Release the user's session and response before optional media work.
            if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            // At most one heavy import per response; the next site request resumes the queue.
            try { ocx_media_inbox_scan($pdo,$forceQueued,null,1); }
            catch (Throwable $error) { error_log('Classic inbox check failed: '.$error->getMessage()); }
        });
    } catch (Throwable $error) { error_log('Classic inbox scheduling failed: '.$error->getMessage()); }
}

function ocx_media_inbox_import(PDO $pdo, string $game, int $actorUserId): array
{
    if (!moderation_identity_is_owner($pdo,$actorUserId)) throw new RuntimeException('Only the Installation Owner can import the Classic inbox.');
    ocx_media_inbox_names($game);
    $pack = $game==='five-dice' ? five_dice_media_pack_status($pdo) : ocx_game_media_pack_status($pdo,$game);
    if (ocx_media_inbox_is_complete($pack)) return ['operation'=>'inbox-complete','status'=>$pack];
    $directory = ocx_media_inbox_directory();
    $lockPath = $directory.'/.import.lock';
    if (is_link($lockPath)) throw new RuntimeException('Invalid Classic inbox lock.');
    $lock = fopen($lockPath,'c');
    if ($lock === false) throw new RuntimeException('Cannot lock the Classic inbox.');
    try {
        if (!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Another Classic inbox import is running. Check the inbox again when it finishes.');
        $source = ocx_media_inbox_status($game);
        $active = $game==='five-dice' ? app_setting($pdo,FIVE_DICE_MEDIA_PACK_GENERATION_SETTING,'') : app_setting($pdo,ocx_game_media_generation_setting($game),'');
        if (!$source['pending'] && !($source['file']!==null && $active!=='')) return ['operation'=>'inbox-unchanged'];
        $five = $game==='five-dice';
        $operation = static function () use ($pdo,$game,$actorUserId,$source,$directory,$five): array {
            $attemptId = '';
            try {
                $begun = $five ? five_dice_media_pack_begin_attempt($pdo,$actorUserId) : ocx_game_media_begin_attempt($pdo,$game,$actorUserId);
                $attemptId = $begun['attemptId'];
                $attempt = $five ? five_dice_media_pack_attempt_directory($attemptId) : ocx_game_media_attempt_directory($game,$attemptId);
                if ($attempt === null) throw new RuntimeException('Classic inbox validation could not start.');
                $snapshot = $attempt.'/.inbox-source.ocx';
                $file = $directory.DIRECTORY_SEPARATOR.$source['file'];
                if (is_link($file) || !copy($file,$snapshot) || hash_file('sha256',$snapshot)!==$source['sha256']) throw new RuntimeException('The OCX is still changing. Finish uploading it, then check the inbox again.');
                if ($five) five_dice_media_stage_ocx($snapshot,$attempt);
                else ocx_static_media_stage($snapshot,$attempt,ocx_game_media_pack_name_map($game),ocx_game_media_pack_slots($game),static fn($slot,$dir)=>ocx_game_media_validate_slot($game,$slot,$dir));
                if (!unlink($snapshot)) throw new RuntimeException('Cannot finish the private inbox validation.');
                $result = $five ? five_dice_media_pack_activate_attempt($pdo,$actorUserId,$attemptId) : ocx_game_media_activate_attempt($pdo,$game,$actorUserId,$attemptId);
                $attemptId = '';
                return $result;
            } finally {
                if ($attemptId!=='') {
                    if ($five) five_dice_media_pack_abort_attempt($actorUserId,$attemptId);
                    else ocx_game_media_abort_attempt($game,$actorUserId,$attemptId);
                }
            }
        };
        $result = $five ? $operation() : ocx_game_media_with_lock($game,$operation);
        $receipts = ocx_media_inbox_receipts($directory);
        $receipts[$game] = ['sha256'=>$source['sha256'], 'importedAt'=>gmdate('c')];
        $temporary = $directory.'/.receipt-'.bin2hex(random_bytes(8));
        try {
            $bytes = json_encode($receipts,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temporary,$bytes,LOCK_EX)!==strlen($bytes) || !rename($temporary,$directory.'/.import-receipts.json')) throw new RuntimeException('Classic media installed, but its inbox receipt could not be saved.');
        } finally { if (is_file($temporary)) unlink($temporary); }
        return $result;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
