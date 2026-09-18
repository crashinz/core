<?php
require dirname(__DIR__).'/includes/multiplayer_game_framework.php';
require dirname(__DIR__).'/includes/dominos_extension.php';
$checks=0;function ec(bool $b,string $s):void{global $checks;$checks++;if(!$b)throw new RuntimeException($s);}
$matches=[];
foreach([[2,false],[3,false],[4,false],[4,true]] as [$n,$teams]){
    $settings=['tableMode'=>$teams?'teams':'individual','winningScore'=>50];for($i=2;$i<=$n;$i++)$settings['botSeat'.$i.'Difficulty']='expert';
    $s=dominos_initial_state([1],['mode'=>'practice','settings'=>$settings]);ec(isset($s['botKnowledge']),'Expert knowledge initialized');
    for($i=0;$i<1000&&!$s['completed'];$i++){
        $id=dominos_turn_user($s);$before=$s;
        if($id<0){$task=dominos_bot_task($s,1,['mode'=>'practice','status'=>'active','viewerRole'=>'master']);$action=$task['action'];$payload=['engine'=>$task['engine'],'positionKey'=>$task['positionKey']];}
        else{$choice=dominos_bot_choose(dominos_bot_observation($s,$id));$action=$choice['action'];$payload=$choice['payload'];}
        $ctx=['mode'=>'practice'];if(in_array($action,['deal','bot-deal']))$ctx['authoritativeRandomness']=dominos_derive_randomness("expert-prepared-$n-$teams-".$s['roundNumber'],'deal',[],[]);
        $s=dominos_apply_action($s,1,$action,$payload,$ctx)['state'];
        $decoded=json_decode(json_encode($s),true);ec($decoded===$s,'Knowledge survives save encoding');
        ec(!isset(dominos_project_state($s,1,['mode'=>'practice','status'=>'active','viewerRole'=>'master'])['botKnowledge']),'Knowledge stays out of display projection');
        if($s['lastAction']['type']==='deal')ec($s['botKnowledge']===[],'Deal resets memory');
        foreach($s['botKnowledge'] as $player=>$bounds)if($player!=='_joint')foreach($bounds as $pip=>$cap){$actual=count(array_filter($s['hands'][$player],fn($t)=>in_array((int)$pip,dominos_pips($t),true)));ec($actual<=$cap,'Stored bounds valid');}
        $last=$s['lastAction'];$p=$last['type']==='play'?['tile'=>$last['tile'],'end'=>$last['end']]:[];
        $replay=dominos_apply_action_core($before,$last['userId'],$last['type'],$p,$ctx)['state'];ec($replay===$s,'Action replay includes identical memory');
    }ec($s['completed'],'Expert match completes');$matches[]=['players'=>$n,'teams'=>$teams,'actions'=>$i];
}
$normal=dominos_initial_state([1],['mode'=>'practice','settings'=>['botSeat2Difficulty'=>'normal']]);ec(!isset($normal['botKnowledge']),'Normal state remains unchanged');
ec(in_array('botKnowledge',dominos_recording_adapter()['stateKeys'],true),'Recording retains expert memory');
echo json_encode(['checks'=>$checks,'matches'=>$matches,'status'=>'PASS'],JSON_PRETTY_PRINT);
