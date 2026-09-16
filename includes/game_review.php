<?php
declare(strict_types=1);
require_once __DIR__ . '/game_review_catalog.php';
require_once __DIR__ . '/game_review_positions.php';

const GAME_REVIEW_TTL = 7200;

function game_review_assert_admin(array $user): void
{
    if (($user['role'] ?? '') !== 'admin') throw new MultiplayerGameException('Administrator access is required.', 'GAME_REVIEW_ADMIN_REQUIRED', 403);
}

function game_review_definition(PDO $pdo, string $extension): array
{
    foreach (multiplayer_game_registry() as $definition) {
        if (($definition['extensionId'] ?? '') === $extension) {
            if (!multiplayer_game_extension_adapter($pdo, $definition)) break;
            return $definition;
        }
    }
    throw new MultiplayerGameException('This game is not installed.', 'GAME_REVIEW_UNAVAILABLE', 404);
}

function game_review_create(PDO $pdo, array $user, string $caseId, string $pack): array
{
    game_review_assert_admin($user);
    $case = game_review_catalog()[$caseId] ?? null;
    if (!$case) throw new MultiplayerGameException('Choose a listed example.', 'GAME_REVIEW_CASE_INVALID', 422);
    $def = game_review_definition($pdo, $case['game']);
    if (!in_array($pack, array_column($def['presentationPacks'], 'id'), true)) throw new MultiplayerGameException('Choose a supported appearance.', 'GAME_REVIEW_PACK_INVALID', 422);
    $adapter = multiplayer_game_extension_adapter($pdo, $def);
    $input=['inactivityProfile'=>'unlimited'];
    if(in_array($case['game'],['acey-deucy','backgammon-first-party'],true))$input['starterMethod']='rotate-starter';
    if(isset($case['rulesProfile']))$input['rulesProfile']=$case['rulesProfile'];
    $settings = multiplayer_game_validate_extension_settings($pdo, $def, $input, 'practice');
    $count = $case['game']==='space-invasion'?1:2;
    if (in_array($case['game'], ['spades','hearts'], true)) $count=4;
    $state = ($adapter['initialState'])(range(1,$count), ['mode'=>'practice','settings'=>multiplayer_game_extension_only_settings($settings),'nowUnixMs'=>1000]);
    [$state,$steps,$instruction,$expected] = game_review_position($state,$case);
    $review = ['id'=>'review-'.bin2hex(random_bytes(16)), 'owner'=>(int)$user['id'],'expires'=>time()+GAME_REVIEW_TTL,
        'case'=>$case,'gameKey'=>$def['key'],'pack'=>$pack,'settings'=>$settings,'state'=>$state,'version'=>1,'step'=>0,'steps'=>$steps,
        'instruction'=>$instruction,'expected'=>$expected,'receipts'=>[],'requests'=>[],
        'options'=>['gameKey'=>$def['key'],'masterVolume'=>80,'effectsEnabled'=>true,'voiceEnabled'=>true,'musicEnabled'=>false,
            'categories'=>['visualFxEnabled'=>true,'gfxEnabled'=>true,'sfxEnabled'=>true,'spadesCardActivationMode'=>'legacy-ocx-one-click'],'individual'=>[]],
        'created'=>gmdate('c')];
    $review['referenceOptions']=$review['options'];
    return $review;
}

function game_review_get(array $user, string $id): array
{
    game_review_assert_admin($user);
    $review = $_SESSION['game_reviews'][$id] ?? null;
    if (!is_array($review) || $review['owner'] !== (int)$user['id'] || $review['expires'] < time()) throw new MultiplayerGameException('This review expired. Start the example again.', 'GAME_REVIEW_EXPIRED', 404);
    return $review;
}

function game_review_save(array $review): void
{
    if (strlen(json_encode($review, JSON_THROW_ON_ERROR)) > 2097152) throw new MultiplayerGameException('Reset this example to continue.', 'GAME_REVIEW_LIMIT', 422);
    foreach ($_SESSION['game_reviews'] ?? [] as $id=>$entry) if (($entry['expires']??0)<time()) unset($_SESSION['game_reviews'][$id]);
    $_SESSION['game_reviews'][$review['id']]=$review;
    while (count($_SESSION['game_reviews'])>4) array_shift($_SESSION['game_reviews']);
}

function game_review_projection(PDO $pdo, array $review): array
{
    $def=game_review_definition($pdo,$review['case']['game']);$s=$review['state'];$status=!empty($s['completed'])?'completed':'active';
    $members=[];foreach($s['turnOrder'] as $index=>$id)$members[]=['userId'=>$id,'participantId'=>$id,'role'=>$id===1?'master':'player','seat'=>$index+1,'displayName'=>$id===1?'You':'Example player '.$id,'avatarUrl'=>null,'membershipStatus'=>'active','accepted'=>true];
    $ctx=['mode'=>'practice','status'=>$status,'viewerRole'=>'master','members'=>$members,'settings'=>$review['settings'],'nowUnixMs'=>(int)floor(microtime(true)*1000)];
    if($review['case']['game']==='five-dice')foreach($s['turnOrder'] as $id)$ctx['memberMetadata']['personalBests'][(string)$id]=['mode'=>'practice','score'=>!empty($s['completed'])?(float)$s['players'][(string)$id]['total']:0,'retentionOwner'=>'isolated-review'];
    $projected=multiplayer_game_project_extension_state($pdo,$def,$s,1,$ctx);
    $presentation=multiplayer_game_presentation_projection($pdo,$def,0,$review['pack']);
    return ['publicId'=>$review['id'],'gameKey'=>$def['key'],'displayName'=>multiplayer_game_effective_display_name($pdo,$def),'mode'=>'practice','profile'=>'versus','status'=>$status,'masterUserId'=>1,'sourceRoomSessionId'=>0,
        'settings'=>$review['settings'],'settingsSha256'=>hash('sha256',json_encode($review['settings'])),'settingsControls'=>[],
        'frameworkSchemaVersion'=>MULTIPLAYER_GAME_FRAMEWORK_SCHEMA_VERSION,'adaptationVersion'=>$def['adaptationVersion'],'presentation'=>$presentation,
        'rules'=>multiplayer_game_rules_projection($pdo,$def,$review['settings'],'practice'),'state'=>$projected,'stateVersion'=>$review['version'],
        'turnUserId'=>$s['turnIndex']===null?null:($s['turnOrder'][$s['turnIndex']]??null),'viewerRole'=>'master','members'=>$members,'seatRequests'=>[],
        'createdAt'=>$review['created'],'startedAt'=>$review['created'],'expiresAt'=>gmdate('c',$review['expires']),'endedAt'=>$status==='completed'?gmdate('c'):null,
        'nowUnixMs'=>$ctx['nowUnixMs'],'review'=>true];
}

function game_review_apply(PDO $pdo, array &$review, int $actor, string $action, array $payload, ?array $fixedRandom = null, ?int $nowUnixMs = null): array
{
    $def=game_review_definition($pdo,$review['case']['game']);$adapter=multiplayer_game_extension_adapter($pdo,$def);
    $ctx=['mode'=>'practice','status'=>'active','viewerRole'=>'master','settings'=>$review['settings'],'randomnessRequestId'=>'review-roll-'.$review['version'],'now'=>gmdate('c'),'nowUnixMs'=>(int)floor(microtime(true)*1000)];
    if(isset($review['state']['realtime']))$ctx['nowUnixMs']=(int)$review['state']['realtime']['lastAtMs']+100;
    if($nowUnixMs!==null)$ctx['nowUnixMs']=$nowUnixMs;
    $seed=json_encode(['nonce'=>'review-'.$review['case']['id'].'-'.$review['version']]);
    if (!empty($adapter['deriveRandomness'])) $ctx['authoritativeRandomness']=($adapter['deriveRandomness'])($seed,$action,$payload,$ctx);
    $ctx['authoritativeRandomness']=$fixedRandom??($ctx['authoritativeRandomness']??['dice'=>[3,4]]);
    if(isset($ctx['authoritativeRandomness']['dice']))$ctx['authoritativeDice']=$ctx['authoritativeRandomness']['dice'];
    $result=($adapter['applyAction'])($review['state'],$actor,$action,$payload,$ctx);
    $review['state']=multiplayer_game_validate_initial_state_result($result['state']);$review['version']++;
    return $result;
}

/** This dispatcher cannot touch real match, participant, record or recording tables. */
function game_review_dispatch(PDO $pdo, array $user, array $source): array
{
    $id=(string)($source['game_session_id']??'');$review=game_review_get($user,$id);$action=(string)($source['action']??'session');
    if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && !csrf_verify($source)) throw new MultiplayerGameException('Refresh this page before continuing.', 'CSRF_INVALID', 403);
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST' && !in_array($action,['session','options','records'],true)) throw new MultiplayerGameException('Use POST for review actions.', 'GAME_REVIEW_METHOD',405);
    if($action==='session'||$action==='reconnect')return game_review_projection($pdo,$review);
    if($action==='disconnect')return ['ok'=>true];
    if($action==='records')return ['gameKey'=>$review['gameKey'],'lifetime'=>['win'=>0,'loss'=>0,'draw'=>0,'recorded'=>0],'opponents'=>[],'recent'=>[]];
    if($action==='options'){
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&isset($source['options'])){
            if(!is_array($source['options'])||strlen(json_encode($source['options']))>20000)throw new MultiplayerGameException('Invalid review options.','GAME_REVIEW_OPTIONS',422);
            $review['options']=array_replace($review['options'],array_intersect_key($source['options'],$review['options']));game_review_save($review);
        }return $review['options'];
    }
    if($action==='presentation-pack'){
        throw new MultiplayerGameException('Choose the appearance at the top of the review page, then Start the example.','GAME_REVIEW_PACK_AT_PAGE',422);
    }
    if(in_array($action,['randomness','reveal-practice-randomness'],true))return ['review'=>true,'ready'=>true];
    if($action==='extension-action'){
        $type=(string)($source['action_type']??'');$payload=(array)($source['payload']??[]);
        if(in_array($review['case']['game'],['tetris-versus','space-invasion'],true)&&in_array($type,['arcade-input','arcade-tick'],true)){
            $intent=!empty($payload['commands'])||!empty($payload['left'])||!empty($payload['right'])||!empty($payload['fire'])||array_filter((array)($payload['frames']??[]));
            if(!empty($review['state']['completed'])||($review['step']===0&&empty($review['manualArcadeStarted'])&&($type==='arcade-tick'||!$intent)))return ['session'=>game_review_projection($pdo,$review)];
            if($type==='arcade-tick'&&(int)($source['expected_version']??-1)!==$review['version'])return ['session'=>game_review_projection($pdo,$review)];
            if($intent)$review['manualArcadeStarted']=true;
        }
        if($type==='deal'&&$review['step']>=count($review['steps'])&&in_array($review['case']['game'],['hearts','spades','blackjack'],true))return ['session'=>game_review_projection($pdo,$review)];
        if($type==='settle-trick'&&($review['state']['phase']??'')!=='settling')return ['session'=>game_review_projection($pdo,$review)];
        $request=(string)($source['request_id']??'');
        if($request!==''&&isset($review['requests'][$request]))return ['idempotentReplay'=>true,'session'=>game_review_projection($pdo,$review)];
        if((int)($source['expected_version']??-1)!==$review['version'])throw new MultiplayerGameException('The example changed. Try again.','MULTIPLAYER_GAME_STATE_CONFLICT',409);
        $result=game_review_apply($pdo,$review,1,(string)($source['action_type']??''),(array)($source['payload']??[]));
        if($request!==''){$review['requests'][$request]=true;while(count($review['requests'])>40)array_shift($review['requests']);}
        $next=$review['steps'][$review['step']]??null;
        if($next&&$next['actor']===1&&$next['action']===$type&&array_intersect_key($payload,$next['payload'])==$next['payload'])$review['step']++;
        game_review_save($review);return array_diff_key($result,['state'=>true])+['session'=>game_review_projection($pdo,$review)];
    }
    throw new MultiplayerGameException('Use Start or Reset in the review page.','GAME_REVIEW_ACTION_UNSUPPORTED',422);
}

/** Private installed artwork stays protected by both admin and review ownership. */
function game_review_media_file(PDO $pdo,array $user,string $id,string $game,string $slot): array
{
    $r=game_review_get($user,$id);
    if($r['case']['game']!==$game||$r['pack']!=='classic')throw new MultiplayerGameException('This review media is unavailable.','GAME_REVIEW_MEDIA_DENIED',403);
    $def=game_review_definition($pdo,$game);
    first_party_extension_assert_capability($pdo,$game,'game.presentation.private-media');
    if($game==='five-dice'){
        $dir=five_dice_media_pack_directory($pdo);$file=five_dice_media_pack_validate_slot($slot,$dir);
        if(($file['state']??'')!=='installed')throw new MultiplayerGameException('This media slot is unavailable.','GAME_REVIEW_MEDIA_MISSING',404);
        return is_array($file['preparation']??null)?five_dice_media_pack_prepared_file($file,$dir):$file;
    }
    $dir=ocx_game_media_active_directory($pdo,$game);$file=ocx_game_media_validate_slot($game,$slot,$dir);
    if(($file['state']??'')!=='installed'||$dir===null)throw new MultiplayerGameException('This media slot is unavailable.','GAME_REVIEW_MEDIA_MISSING',404);
    return is_array($file['preparation']??null)?ocx_game_media_prepared_file($file,$dir):$file;
}

function game_review_missing_optional_media(PDO $pdo,string $game): array
{
    if($game==='five-dice'){
        $slots=five_dice_media_pack_slots();$dir=five_dice_media_pack_directory($pdo);
        $missing=[];foreach($slots as $id=>$slot)if(empty($slot['requiredForClassic'])){$file=five_dice_media_pack_validate_slot($id,$dir);if(($file['state']??'')!=='installed')$missing[]=$file['label']??$id;}return $missing;
    }
    $status=ocx_game_media_pack_status($pdo,$game);
    return array_column(array_filter($status['optional'],static fn($slot)=>$slot['state']!=='installed'),'label');
}
