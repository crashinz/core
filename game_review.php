<?php
declare(strict_types=1);
require __DIR__.'/includes/base.php';
require_once __DIR__.'/includes/game_review.php';
require_once __DIR__.'/includes/game_review_references.php';
require_once __DIR__.'/includes/game_review_baselines.php';
require_once __DIR__.'/includes/game_review_snapshots.php';
require_once __DIR__.'/includes/game_review_pack.php';
$user=require_user();
try { game_review_assert_admin($user); } catch(MultiplayerGameException $e){http_response_code(403);exit('Administrator access is required.');}
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$pdo=db();$catalog=game_review_catalog();$caseId=(string)($_GET['example']??array_key_first($catalog));
if(!isset($catalog[$caseId])){http_response_code(404);exit('Example not found.');}
$case=$catalog[$caseId];$def=game_review_definition($pdo,$case['game']);$packs=array_column($def['presentationPacks'],null,'id');
$defaultPack=isset($packs['classic'])?'classic':array_key_first($packs);
$pack=(string)($_GET['pack']??$defaultPack);if(!isset($packs[$pack]))$pack=$defaultPack;
$references=game_review_references($caseId,$pack);
if(isset($_GET['reference'])){
    foreach($references as $ref)if(hash_equals($ref['id'],(string)$_GET['reference'])){
        $file=game_review_reference_root().'/'.basename($ref['file']);
        if(!is_file($file)||hash_file('sha256',$file)!==$ref['sha256']){http_response_code(409);exit('Reference integrity check failed.');}
        header('Content-Type: '.$ref['mime']);header('Content-Length: '.filesize($file));header('Content-Security-Policy: sandbox');readfile($file);exit;
    }http_response_code(404);exit('Reference not found.');
}
$missingOptional=$pack==='classic'?game_review_missing_optional_media($pdo,$case['game']):[];
$review=null;$error='';$notice='';$ruleCheck=null;
try {
    $reviewId=(string)($_POST['review_id']??$_GET['review']??'');
    if($reviewId!==''){
        try {$review=game_review_get($user,$reviewId);}
        catch(MultiplayerGameException $expired){if($expired->errorCode!=='GAME_REVIEW_EXPIRED'||$_SERVER['REQUEST_METHOD']!=='POST'||($_POST['operation']??'')!=='start')throw $expired;}
        if($review&&($review['case']['id']!==$caseId||$review['pack']!==$pack))throw new MultiplayerGameException('Start the selected example.','GAME_REVIEW_BINDING',409);
    }
    if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['progress'])&&$review){$referenceProgress=null;try{$snapshot=game_review_snapshot_case($review);$referenceProgress=['step'=>(int)($review['referenceCursor']??0),'complete'=>(int)($review['referenceCursor']??0)>=count($snapshot['steps'])];}catch(Throwable $missingReference){}json_out(['live'=>['step'=>$review['step'],'complete'=>$review['step']>=count($review['steps'])],'reference'=>$referenceProgress]);}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!csrf_verify())csrf_failure_response();$op=(string)($_POST['operation']??'');
        if($op==='start'){
            if($review)unset($_SESSION['game_reviews'][$review['id']]);
            $review=game_review_create($pdo,$user,$caseId,$pack);game_review_save($review);
        }elseif($op==='compare'){
            $ruleCheck=game_review_rule_check($pdo,$user,$caseId,$pack);
        }elseif($op==='step'&&$review){
            $index=(int)($_POST['step']??-1);if($index!==$review['step'])throw new MultiplayerGameException('This action already ran. Refresh the example.','GAME_REVIEW_STEP_CONFLICT',409);
            $step=$review['steps'][$index]??null;if(!$step)throw new MultiplayerGameException('Prepared actions are complete. Reset to repeat.','GAME_REVIEW_DONE',409);
            game_review_apply($pdo,$review,$step['actor'],$step['action'],$step['payload'],$step['random']??null);$review['step']++;game_review_save($review);
        }elseif(in_array($op,['reference-step','reference-reset'],true)&&$review){
            if($op==='reference-reset'){$review['referenceCursor']=0;$review['referenceRequests']=[];}
            else {if((int)($_POST['step']??-1)!==(int)($review['referenceCursor']??0))throw new MultiplayerGameException('The reference advanced. Reset to repeat.','GAME_REVIEW_STEP_CONFLICT',409);game_review_snapshot_step($review);}
            game_review_save($review);
        }elseif($op==='reference'&&$review){
            if(empty($_POST['verified']))throw new MultiplayerGameException('Confirm that you reviewed this reference.','GAME_REVIEW_VERIFY_REQUIRED',422);
            $projection=game_review_projection($pdo,$review);if($projection['presentation']['effectivePack']!==$pack)throw new MultiplayerGameException('Install the selected Classic media before saving a Classic reference.','GAME_REVIEW_MEDIA_MISSING',422);
            game_review_store_reference($user,$review,$_FILES['reference_file']??[],(string)($_POST['note']??''),(string)($_POST['previous']??''));$references=game_review_references($caseId,$pack);$notice='Verified reference saved. Earlier revisions remain available.';
        }else throw new MultiplayerGameException('Invalid review action.','GAME_REVIEW_ACTION',422);
        if(isset($_POST['ajax'])){$reference=str_starts_with($op,'reference-');$step=$reference?(int)($review['referenceCursor']??0):$review['step'];$total=$reference?count(game_review_snapshot_case($review)['steps']):count($review['steps']);json_out(['ok'=>true,'review'=>$review['id'],'step'=>$step,'version'=>$review['version'],'complete'=>$step>=$total]);}
    }
}catch(Throwable $e){if(isset($_POST['ajax']))json_out(['error'=>$e->getMessage()],$e instanceof MultiplayerGameException?$e->httpStatus:500);$error=$e->getMessage();}
$projection=$review?game_review_projection($pdo,$review):null;$latest=$references?end($references):null;
$selectedRef=$latest;foreach($references as $ref)if($ref['id']===($_GET['revision']??''))$selectedRef=$ref;
$frozen=null;$frozenError='';if($review){try{
    $referenceStatus=game_review_pack_status();$baseStatus=$referenceStatus['base'];
    if($baseStatus['changed'])throw new RuntimeException('Some frozen reference files have changed. Restore their backup; they will not be replaced automatically.');
    if($baseStatus['missing'])throw new RuntimeException('Reference pack v4 is not fully installed. Open Reference pack to download and install it.');
    if($pack==='classic'){$mediaStatus=$referenceStatus['classic'][$case['game']]??null;if($mediaStatus&&($mediaStatus['missing']||$mediaStatus['changed']))throw new RuntimeException('Classic reference media is missing or different for this game. Open Reference pack to copy matching installed Classic media or restore your reference backup.');}
    $frozen=game_review_snapshot_case($review);
}catch(Throwable $e){$frozenError=$e->getMessage();}}
$packDownload=game_review_pack_descriptor();
$url=null;if($review){$path=$def['path']??'';$entry=$def['entry']??'index.html';
    $url='games/'.$path.'/'.$entry.'?'.http_build_query(['game_session_id'=>$review['id'],'participant_id'=>1,'user'=>1,'csrf'=>csrf_token()]);}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Administrator game review</title><link rel="stylesheet" href="assets/css/game-review.css"></head><body>
<main><header><p class="eyebrow">Administrator tools</p><h1>Game review</h1><p>Repeat a known example and compare the live game with a saved, verified reference.</p></header>
<details id="reference-pack"><summary>Reference pack — optional download and installation</summary>
<p>Frozen references stay unchanged when games are updated. Existing matching references are reused. Live examples and rules comparisons also work without this pack.</p>
<p><a href="<?=e($packDownload['url'])?>">Download reference pack <?=e($packDownload['version'])?> ZIP (<?=e((string)round($packDownload['bytes']/1000000))?> MB)</a> · <a href="<?=e($packDownload['releaseUrl'])?>" target="_blank" rel="noopener">Release details and checksum</a></p>
<form id="reference-pack-upload" data-bytes="<?=$packDownload['bytes']?>" data-chunk="<?=min(524288,game_review_upload_limit())?>"><?=csrf_input()?>
<label>Downloaded reference ZIP<input type="file" name="pack_file" accept=".zip,application/zip" required></label><button type="submit">Install reference pack</button></form>
<p class="minor">Uploads use small chunks. Allow about <?=e((string)ceil(($packDownload['bytes']+$packDownload['expandedBytes'])/1000000))?> MB of free private storage during installation. The ZIP contains no original OCX media or personal reference images.</p>
<div class="toolbar"><button type="button" id="reference-pack-check">Check installed references</button><button type="button" id="reference-pack-classic">Copy installed Classic media</button></div>
<p class="minor">Classic references use verified copies of your installed Classic artwork and sounds, up to 18 MB for all games. Missing or different media affects that game's Classic comparison only. Install missing Classic media through the game's administrator controls, then retry. Different existing reference files are never overwritten.</p>
<progress hidden aria-label="Reference pack upload"></progress><p class="pack-status" role="status" aria-live="polite"></p><ul id="reference-pack-status"></ul>
</details>
<form method="get" class="selector" id="review-selector"><label>Game / example<select name="example" id="review-example"><?php foreach($catalog as $id=>$item):?><option value="<?=e($id)?>" <?=$id===$caseId?'selected':''?>><?=e($item['label'])?></option><?php endforeach?></select></label><label>Appearance<select name="pack"><?php foreach($packs as $id=>$item):?><option value="<?=e($id)?>" <?=$id===$pack?'selected':''?>><?=e($item['label']??ucfirst($id))?></option><?php endforeach?></select></label></form>
<?php if($error):?><p class="error" role="alert"><?=e($error)?></p><?php endif?><?php if($notice):?><p role="status"><?=e($notice)?></p><?php endif?>
<h2><?=e($case['label'])?> — <?=e($packs[$pack]['label']??ucfirst($pack))?></h2>
<p><?=e($review['instruction']??$case['instruction'])?></p><p class="expected"><strong>Expected:</strong> <?=e($review['expected']??$case['expected'])?></p>

<p class="minor">Review only · No match, ranking or game recording is created. Visual FX starts on. Options changed here apply only to this example. Examples expire after two hours; Start/Reset creates a fresh one.</p>
<?php if($projection&&!empty($projection['presentation']['fallbackApplied'])):?><p class="error">Classic media is unavailable or incomplete. This live example is using Built-in fallback.</p><?php endif?>
<?php if($missingOptional):?><details><summary>Optional Classic media missing (<?=count($missingOptional)?>)</summary><p><?=e(implode(', ',$missingOptional))?>. The live game uses its supported fallback for these effects; the frozen reference retains the media saved with it.</p></details><?php endif?>
<div class="comparison<?=$case['game']==='eight-ball'?' pool-comparison':''?>"><section><h3>Live example</h3><div class="toolbar pane-controls"><form method="post"><?=csrf_input()?><input type="hidden" name="operation" value="start"><input type="hidden" name="review_id" value="<?=e($review['id']??'')?>"><button class="primary"><?=$review?'Reset and repeat':'Start example'?></button></form>
<?php if($review&&$review['steps']):?><form method="post" id="review-step" class="review-action"><?=csrf_input()?><input type="hidden" name="operation" value="step"><input type="hidden" name="review_id" value="<?=e($review['id'])?>"><input type="hidden" name="step" value="<?=$review['step']?>"><button <?=$review['step']>=count($review['steps'])?'disabled':''?>>Play example action</button></form><?php endif?><span id="review-status" role="status" aria-live="polite"></span></div><?php if($url):?><iframe id="review-game" title="<?=e($case['label'])?> — Live example" src="<?=e($url)?>"></iframe><?php else:?><p>Start the example to load the live game.</p><?php endif?></section>
<section><h3>Frozen reference</h3>
<?php if($frozen):$frozenUrl='game_review_reference.php/v4/'.$frozen['entry'].'?'.http_build_query(['game_session_id'=>$review['id'],'participant_id'=>1,'user'=>1,'csrf'=>csrf_token()]);?>
<div class="toolbar pane-controls"><form method="post" class="review-action"><?=csrf_input()?><input type="hidden" name="operation" value="reference-step"><input type="hidden" name="review_id" value="<?=e($review['id'])?>"><input type="hidden" name="step" value="<?=(int)($review['referenceCursor']??0)?>"><button <?=(int)($review['referenceCursor']??0)>=count($frozen['steps'])?'disabled':''?>>Play reference action</button><span class="action-status" role="status"></span></form>
<form method="post"><?=csrf_input()?><input type="hidden" name="operation" value="reference-reset"><input type="hidden" name="review_id" value="<?=e($review['id'])?>"><button>Reset reference</button></form></div>
<iframe id="review-reference" title="<?=e($case['label'])?> — Frozen reference" src="<?=e($frozenUrl)?>"></iframe><p class="minor">Saved <?=e(game_review_snapshot_manifest()['createdAt'])?> · <?=e(substr(game_review_snapshot_manifest()['files'][$frozen['entry']]['sha256'],0,16))?>. This renderer, artwork and prepared sequence stay fixed when the live game changes.</p>
<p><strong>Saved expected behavior:</strong> <?=e($frozen['expected'])?></p>

<p class="minor">A prepared replay, not a free-play match. Use each game’s Sound FX control to hear one side at a time.</p>
<?php elseif($review):?><p class="error"><?=e($frozenError)?> The frozen rules comparison remains available.</p><?php else:?><p>Start the example to load its saved reference.</p><?php endif?>
<details <?=($ruleCheck||$notice)?'open':''?>><summary>Rules comparison and saved images/clips</summary><form method="post"><?=csrf_input()?><input type="hidden" name="operation" value="compare"><input type="hidden" name="review_id" value="<?=e($review['id']??'')?>"><button>Compare rules with saved reference</button></form><?php if($ruleCheck):?><p role="status"><?=e($ruleCheck['message'])?></p><p class="minor"><?=e($ruleCheck['visualLimit']??'')?></p><?php endif?><?php if($selectedRef):?>
<p><?=e($selectedRef['createdAt'])?> · <?=e($selectedRef['note'])?></p><p class="minor">Saved version <?=e(substr($selectedRef['source']['release'],0,16))?>. Live updates do not replace this reference.</p>
<?php $refUrl='?'.http_build_query(['example'=>$caseId,'pack'=>$pack,'reference'=>$selectedRef['id']]);if(str_starts_with($selectedRef['mime'],'video/')):?><video controls loop preload="metadata" src="<?=e($refUrl)?>"></video><?php else:?><img src="<?=e($refUrl)?>" alt="<?=e($selectedRef['label'])?> — verified reference"><?php endif?>
<p><strong>Saved expected behavior:</strong> <?=e($selectedRef['expected'])?></p>
<details><summary>Reference revisions (<?=count($references)?>)</summary><ul><?php foreach(array_reverse($references) as $ref):?><li><a href="?<?=e(http_build_query(['example'=>$caseId,'pack'=>$pack,'review'=>$review['id']??'','revision'=>$ref['id']]))?>"><?=e($ref['createdAt'].' — '.$ref['note'])?></a></li><?php endforeach?></ul></details>
<?php else:?><p>No additional reference image or clip has been saved. The frozen replay above is independent of live updates.</p><?php endif?>
<?php if($review):?><details><summary><?=$latest?'Add a new verified reference revision':'Save a verified reference'?></summary><form method="post" enctype="multipart/form-data"><?=csrf_input()?><input type="hidden" name="operation" value="reference"><input type="hidden" name="review_id" value="<?=e($review['id'])?>"><input type="hidden" name="previous" value="<?=e($latest['id']??'')?>"><label>Reference image or animation clip<input type="file" name="reference_file" accept="image/png,image/jpeg,image/gif,image/webp,video/webm,video/mp4" required></label><label>What you verified<textarea name="note" maxlength="1500" required></textarea></label><label class="check"><input type="checkbox" name="verified" value="1" required>I checked this reference and want to preserve it as correct.</label><button>Save verified reference</button><p class="minor">Up to <?=e((string)round(game_review_upload_limit()/1048576,1))?> MB on this server. Stored privately for administrators. Earlier revisions are retained.</p></form></details><?php endif?></details></section></div>
</main><script src="assets/js/game-review.js"></script><script src="assets/js/game-review-pack.js"></script></body></html>
