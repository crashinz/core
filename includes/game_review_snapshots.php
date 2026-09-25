<?php
declare(strict_types=1);

function game_review_snapshot_manifest(): array
{
    static $manifest;
    return $manifest ??= json_decode((string)file_get_contents(__DIR__.'/game_review_snapshot_v5.json'),true,512,JSON_THROW_ON_ERROR);
}

function game_review_snapshot_file(string $key): array
{
    $manifest=game_review_snapshot_manifest();$entry=$manifest['files'][$key]??null;
    if(!$entry)throw new MultiplayerGameException('Reference file not found.','GAME_REVIEW_REFERENCE_MISSING',404);
    $root=security_private_storage_directory('game-review-snapshots').'/v5';$path=$root.'/'.$key;
    if(!is_file($path))throw new MultiplayerGameException(str_starts_with($key,'media/')?'Classic reference media is not installed for this game. Use Copy installed Classic media in Game Review.':'The optional reference pack is not installed completely. Download and install it in Game Review.','GAME_REVIEW_REFERENCE_MISSING',409);
    if(!hash_equals($entry['sha256'],hash_file('sha256',$path)))throw new MultiplayerGameException('The frozen reference has changed. Restore its private backup.','GAME_REVIEW_REFERENCE_INTEGRITY',409);
    return $entry+['path'=>$path];
}

function game_review_snapshot_case(array $review): array
{
    $case=game_review_snapshot_manifest()['cases'][$review['case']['id'].'|'.$review['pack']]??null;
    if(!$case)throw new MultiplayerGameException('No frozen replay exists for this example.','GAME_REVIEW_REFERENCE_MISSING',404);
    $file=game_review_snapshot_file($case['file']);
    return json_decode((string)file_get_contents($file['path']),true,512,JSON_THROW_ON_ERROR)+$case;
}

function game_review_snapshot_projection(array $review): array
{
    $saved=game_review_snapshot_case($review);$cursor=(int)($review['referenceCursor']??0);$frame=$saved['frames'][$cursor]??$saved['frames'][0];
    $frame['publicId']=$review['id'];$frame['stateVersion']=(int)($review['referenceVersion']??($cursor+1));$frame['nowUnixMs']=(int)floor(microtime(true)*1000);
    $frame['createdAt']=$review['created'];$frame['expiresAt']=gmdate('c',$review['expires']);
    // Clocks do not consume the historical reference while the administrator compares it.
    if(isset($frame['state']['settlement']['settleAfterUnixMs']))$frame['state']['settlement']['settleAfterUnixMs']=(int)($review['referenceChangedAt']??$frame['nowUnixMs'])+1200;
    if(($review['case']['game']??'')==='eight-ball'){
        $now=$frame['nowUnixMs']/1000;$frame['state']['serverNow']=$now;
        if(isset($frame['state']['lastShot']['startedAt'])){
            $id=$frame['state']['lastShot']['id'];$previous=$saved['frames'][$cursor-1]['state']['lastShot']['id']??null;
            $start=$id!==$previous?($review['referenceChangedAt']??$frame['nowUnixMs'])/1000:$now-60;
            $frame['state']['lastShot']['startedAt']=$start;
            $frame['state']['animationUntil']=$start+$frame['state']['lastShot']['duration'];
        }
    }
    $frame['review']=true;$frame['frozenReference']=true;
    if(in_array($review['case']['game'],['space-invasion','tetris-versus'],true)){$frame['viewerRole']='spectator';$frame['state']['legalActions']=[];}
    if($review['case']['game']==='space-invasion'){
        $timeline=$saved['playbacks'][(string)$cursor]??[['atMs'=>0,'frame'=>$frame]];
        foreach($timeline as &$item){
            $item['frame']['publicId']=$frame['publicId'];$item['frame']['stateVersion']=$frame['stateVersion'];
            $item['frame']['nowUnixMs']=$frame['nowUnixMs'];$item['frame']['review']=true;$item['frame']['frozenReference']=true;
            $item['frame']['viewerRole']='spectator';$item['frame']['state']['legalActions']=[];
        }unset($item);
        $frame['referencePlayback']=['id'=>$review['id'].':'.$frame['stateVersion'],'frames'=>$timeline];
    }
    return $frame;
}

function game_review_snapshot_step(array &$review): void
{
    $saved=game_review_snapshot_case($review);$cursor=(int)($review['referenceCursor']??0);
    if($cursor>=count($saved['steps']))throw new MultiplayerGameException('Reference complete. Reset to replay.','GAME_REVIEW_REFERENCE_DONE',409);
    $review['referenceVersion']=(int)($review['referenceVersion']??($cursor+1))+1;
    $review['referenceCursor']=$cursor+1;$review['referenceChangedAt']=(int)floor(microtime(true)*1000);
}

function game_review_snapshot_dispatch(array $user,array $source): array
{
    $r=game_review_get($user,(string)($source['game_session_id']??''));$action=(string)($source['action']??'session');$post=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
    if($post&&!csrf_verify($source))throw new MultiplayerGameException('Refresh the review page.','CSRF_INVALID',403);
    if(!$post&&!in_array($action,['session','options','records'],true))throw new MultiplayerGameException('Use POST.','GAME_REVIEW_METHOD',405);
    if(in_array($action,['session','reconnect'],true))return game_review_snapshot_projection($r);
    if($action==='disconnect')return ['ok'=>true];
    if($action==='options'){
        if($post&&isset($source['options'])){$opts=$source['options'];if(!is_array($opts)||strlen(json_encode($opts))>20000)throw new MultiplayerGameException('Invalid options.','GAME_REVIEW_OPTIONS',422);$r['referenceOptions']=array_replace($r['options'],array_intersect_key($opts,$r['options']));game_review_save($r);}
        return $r['referenceOptions']??$r['options'];
    }
    if($action==='records')return ['gameKey'=>$r['gameKey'],'lifetime'=>['win'=>0,'loss'=>0,'draw'=>0,'recorded'=>0],'opponents'=>[],'recent'=>[]];
    if(in_array($action,['randomness','reveal-practice-randomness'],true))return ['review'=>true,'ready'=>true];
    if($action==='extension-action'){
        // Historical arcade clients can send input before observing their read-only role.
        // Only the administrator's reference-step control advances this replay.
        if(in_array($r['case']['game'],['tetris-versus','space-invasion'],true)&&in_array((string)($source['action_type']??''),['arcade-input','arcade-tick'],true))return ['session'=>game_review_snapshot_projection($r)];
        $saved=game_review_snapshot_case($r);$cursor=(int)($r['referenceCursor']??0);$step=$saved['steps'][$cursor]??null;$type=(string)($source['action_type']??'');
        if((!$step&&in_array($type,['deal','dealer-play','settle-trick'],true))||($type==='settle-trick'&&($step['action']??'')!==$type))return ['session'=>game_review_snapshot_projection($r)];
        // Background arcade heartbeats must not advance a saved example.
        if(in_array($type,['arcade-tick','clock-sync','heartbeat'],true)&&($step['action']??'')!==$type)return ['session'=>game_review_snapshot_projection($r)];
        $request=(string)($source['request_id']??'');if($request!==''&&isset($r['referenceRequests'][$request]))return ['idempotentReplay'=>true,'session'=>game_review_snapshot_projection($r)];
        if((int)($source['expected_version']??-1)!==(int)($r['referenceVersion']??($cursor+1)))throw new MultiplayerGameException('The reference changed. Try again.','MULTIPLAYER_GAME_STATE_CONFLICT',409);
        if(!$step||$type!==$step['action']||($step['actor']??1)!==1||($type!=='settle-trick'&&(array)($source['payload']??[])!==$step['payload']))throw new MultiplayerGameException('Use Play reference action for this recorded sequence.','GAME_REVIEW_REFERENCE_SEQUENCE',422);
        if($type==='settle-trick'&&(int)floor(microtime(true)*1000)<(int)($r['referenceChangedAt']??0)+1200)throw new MultiplayerGameException('The trick is still being shown.','GAME_REVIEW_REFERENCE_WAIT',409);
        game_review_snapshot_step($r);if($request!==''){$r['referenceRequests'][$request]=true;while(count($r['referenceRequests'])>40)array_shift($r['referenceRequests']);}game_review_save($r);return ['session'=>game_review_snapshot_projection($r)];
    }
    throw new MultiplayerGameException('Use the review controls above the frozen reference.','GAME_REVIEW_REFERENCE_ACTION',422);
}
