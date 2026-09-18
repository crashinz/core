// Pool bots choose inputs only. The server remains the owner of every outcome.
import {step, moving, strike, shotSpeed, holes} from './table.js';
export const ENGINE = 'corechat-pool-search-1';
export const profiles = Object.freeze({
  easy: {ms:600, simulations:90, banks:0, combinations:false, position:false, error:1.65},
  normal: {ms:1800, simulations:240, banks:1, combinations:false, position:true, error:.10},
  expert: {ms:5500, simulations:900, banks:2, combinations:true, position:true, error:0},
});
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const length=(a,b)=>Math.hypot(a.x-b.x,a.y-b.y);
const unit=(a,b)=>{const d=length(a,b)||1;return {x:(b.x-a.x)/d,y:(b.y-a.y)/d};};
const dot=(a,b)=>a.x*b.x+a.y*b.y;
const group=n=>n>0&&n<8?'solids':n>8?'stripes':null;
const nine=s=>s.settings?.variant==='nine-ball';
const active=s=>s.balls.filter(b=>b.n>0&&!b.pocket);
const player=s=>s.turnOrder[s.turnIndex];
export function targets(s){
  const live=active(s);
  if(nine(s))return live.filter(b=>b.n===Math.min(...live.map(b=>b.n)));
  const g=s.groups?.[player(s)];
  if(s.breakShot)return live.filter(b=>b.n!==8);
  if(g){const own=live.filter(b=>group(b.n)===g);return own.length?own:live.filter(b=>b.n===8);}
  const open=live.filter(b=>b.n!==8);
  return [...open,...(['solids','stripes'].some(g=>!open.some(b=>group(b.n)===g))?live.filter(b=>b.n===8):[])];
}
function clear(a,b,balls,ignore,r){
  const dx=b.x-a.x,dy=b.y-a.y,d=dx*dx+dy*dy;if(d<1e-9)return false;
  return !balls.some(o=>!o.pocket&&!ignore.includes(o.n)&&(()=>{
    const t=clamp(((o.x-a.x)*dx+(o.y-a.y)*dy)/d,0,1);
    return Math.hypot(o.x-a.x-t*dx,o.y-a.y-t*dy)<2*r+.04;
  })());
}
function seeded(key){let x=2166136261;for(const c of String(key))x=Math.imul(x^c.charCodeAt(0),16777619)>>>0;return()=>{x^=x<<13;x^=x>>>17;x^=x<<5;return (x>>>0)/4294967296;};}
const wall=(r)=>[{axis:'y',at:85+r,lo:83+r,hi:1110-r},{axis:'y',at:590-r,lo:83+r,hi:1110-r},{axis:'x',at:83+r,lo:85+r,hi:590-r},{axis:'x',at:1110-r,lo:85+r,hi:590-r}];
function mirror(p,w){return {...p,[w.axis]:2*w.at-p[w.axis]};}
function bouncePath(start,end,walls){
  let image=end;for(let i=walls.length-1;i>=0;i--)image=mirror(image,walls[i]);
  let a=start,dir=unit(a,image);const path=[];
  for(const w of walls){const v=dir[w.axis];if(Math.abs(v)<1e-9)return null;const t=(w.at-a[w.axis])/v;if(t<=.1)return null;
    const b={x:a.x+dir.x*t,y:a.y+dir.y*t},other=w.axis==='x'?'y':'x';
    if(b[other]<w.lo+38||b[other]>w.hi-38||(w.axis==='y'&&Math.abs(b.x-596)<43))return null;
    path.push(b);a=b;dir={...dir,[w.axis]:-dir[w.axis]};
  }
  if(dot(dir,unit(a,end))<.999)return null;
  return [...path,end];
}
/** Geometric candidates are only suggestions; all finalists use actual physics. */
export function routes(s,level='expert'){
  const cfg=profiles[level],r=s.ballRadius||15.5,balls=s.balls,cue=balls.find(b=>b.n===0&&!b.pocket),legal=targets(s),out=[];
  if(!cue)return out;
  const add=(first,path,pocket,kind,second=null)=>{
    const object=second||first,aim=path[0],direction=unit(object,aim),endGhost={x:object.x-2*r*direction.x,y:object.y-2*r*direction.y};
    let firstDir=direction;
    if(second){firstDir=unit(first,endGhost);if(dot(firstDir,direction)<.22||!clear(first,endGhost,balls,[first.n,second.n,0],r))return;}
    const ghost={x:first.x-2*r*firstDir.x,y:first.y-2*r*firstDir.y};
    const incoming=unit(cue,ghost),cut=dot(incoming,firstDir);
    if(cut<.18||!clear(cue,ghost,balls,[0,first.n],r))return;
    let prev=object,distance=length(cue,ghost)+(second?length(first,endGhost):0);
    for(const p of path){if(!clear(prev,p,balls,[0,object.n,first.n],r))return;distance+=length(prev,p);prev=p;}
    out.push({angle:Math.atan2(ghost.y-cue.y,ghost.x-cue.x),target:object.n,first:first.n,pocket,kind,cut,distance,
      quality:cut*100-distance*.028-(kind==='direct'?0:kind==='combination'?15:kind==='bank'?24:40)});
  };
  for(const first of legal)for(let p=0;p<holes.length;p++){
    const hole={x:holes[p][0],y:holes[p][1]};add(first,[hole],p,'direct');
    if(cfg.banks)for(const w of wall(r)){const path=bouncePath(first,hole,[w]);if(path)add(first,path,p,'bank');}
    if(cfg.banks>1)for(const w of wall(r))for(const w2 of wall(r))if(w!==w2){const path=bouncePath(first,hole,[w,w2]);if(path)add(first,path,p,'two-cushion');}
    if(cfg.combinations)for(const second of active(s))if(second.n!==first.n&&(nine(s)||group(second.n)===group(first.n)||!s.groups?.[player(s)]&&second.n!==8))add(first,[hole],p,'combination',second);
    if(cfg.combinations){
      const dir=unit(first,hole),ghost={x:first.x-2*r*dir.x,y:first.y-2*r*dir.y};
      if(!clear(first,hole,balls,[0,first.n],r))continue;
      for(const w of wall(r)){
        const path=bouncePath(cue,ghost,[w]);if(!path)continue;
        const cut=dot(unit(path[0],ghost),dir);if(cut<.25||!clear(cue,path[0],balls,[0],r)||!clear(path[0],ghost,balls,[0,first.n],r))continue;
        out.push({angle:Math.atan2(path[0].y-cue.y,path[0].x-cue.x),target:first.n,first:first.n,pocket:p,kind:'kick',cut,distance:length(cue,path[0])+length(path[0],ghost)+length(first,hole),quality:cut*100-55});
      }
    }
  }
  return out.sort((a,b)=>b.quality-a.quality||a.target-b.target||a.pocket-b.pocket);
}
export function simulate(s,shot,dt=1/120){
  const balls=s.balls.map(b=>({...b})),cue=balls.find(b=>b.n===0&&!b.pocket),events=[];
  if(!cue)throw Error('Cue ball must be placed');
  strike(cue,shot.angle,shotSpeed(shot.power),{x:shot.spinX||0,y:shot.spinY||0});
  let duration=0,crossed=cue.x>335,firstContactCrossedHeadString=null;
  while(moving(balls)&&duration<45){
    step(balls,dt,e=>{crossed||=cue.x>335;if(firstContactCrossedHeadString===null&&e.type==='ball'&&(e.a.n===0||e.b.n===0))firstContactCrossedHeadString=crossed;
      events.push({type:e.type,a:e.a.n,b:e.b?.n??null,pocket:e.hole?holes.indexOf(e.hole):null,time:duration});});
    duration+=dt;crossed||=cue.x>335;if(events.length>2048)throw Error('Shot contact budget');
  }
  if(moving(balls))throw Error('Shot did not settle');
  return {balls,events,duration,firstContactCrossedHeadString};
}
// A safety is useful only if it is legal. Measure the opponent's next position
// from the resulting public table, with special value for blocked first contact.
export function safetyQuality(s,balls){
  const reply={...s,balls,turnIndex:1-s.turnIndex,breakShot:false},r=s.ballRadius||15.5;
  const cue=balls.find(b=>b.n===0&&!b.pocket);if(!cue)return -500;
  const legal=targets(reply),contacts=legal.filter(b=>clear(cue,b,balls,[0,b.n],r));
  const pots=routes(reply,'easy'),best= Math.max(0,pots[0]?.quality??0);
  const distance=contacts.length?Math.min(...contacts.map(b=>length(cue,b))):1000;
  return (contacts.length?0:180)+Math.min(110,distance*.12)-best*2-Math.min(36,pots.length*6);
}
/** Mirrors rule-relevant event classification; PHP always adjudicates the shot. */
export function evaluate(s,shot,result,position=true){
  let first=null,rail=false;const pots=new Map(),rails=new Set();
  for(const e of result.events){if(e.type==='ball'&&first===null&&(e.a===0||e.b===0))first=e.a===0?e.b:e.a;if(e.type==='rail'&&first!==null){rail=true;if(e.a)rails.add(e.a);}if(e.type==='pocket')pots.set(e.a,e.pocket);}
  const legal=targets(s).map(b=>b.n),scratch=pots.has(0),breaking=!!s.breakShot;
  let foul=scratch||first===null||(!breaking||nine(s))&&!legal.includes(first)||(!breaking&&!rail&&pots.size===0)||!!s.headStringRequired&&!result.firstContactCrossedHeadString;
  if(breaking&&!pots.size&&rails.size<4)foul=true;
  const g=s.groups?.[player(s)],calls=s.settings.pocketCalls||'none';
  const onEight=legal.includes(8)&&!breaking,terminalBall=nine(s)?9:8;
  const madeCall=pots.has(shot.calledBall)&&pots.get(shot.calledBall)===shot.calledPocket&&!shot.safety;
  const winning=pots.has(terminalBall)&&!foul&&(nine(s)||onEight&&(calls==='none'||madeCall));
  const losing=!nine(s)&&!breaking&&pots.has(8)&&!winning;
  const eligible=[...pots.keys()].filter(n=>n>0&&(nine(s)||n!==8&&(!g||group(n)===g)));
  const continues=!foul&&!shot.safety&&(nine(s)?eligible.length>0:calls==='all'&&!breaking?madeCall:eligible.length>0);
  let score=winning?100000:losing?-100000:foul?-2500:continues?1000+eligible.length*100:20;
  if(breaking)score=winning?100000:foul?-2500:200+eligible.length*120+rails.size*8;
  let nextQuality=0;
  if(position&&continues&&!winning){
    const next={...s,balls:result.balls,breakShot:false};
    if(!nine(s)&&!g&&eligible.length)next.groups={...s.groups,[player(s)]:group(calls==='all'?shot.calledBall:eligible[0])};
    const nextRoutes=routes(next,'easy');nextQuality=nextRoutes.length?clamp(nextRoutes[0].quality,0,100)+Math.min(18,nextRoutes.length*3):-40;
    score+=nextQuality*2;
  }
  // Discourage giving the opponent a very easy shot when no pot is available.
  if(position&&!foul&&!continues&&!winning&&!breaking){
    score+=safetyQuality(s,result.balls);
  }
  return {score,foul,winning,losing,continues,first,pots:[...pots.keys()],nextQuality};
}
function placementValid(s,x,y){
  const r=s.ballRadius||15.5;
  if(x<83+r||x>1110-r||y<85+r||y>590-r||(s.placement==='break'&&x>335))return false;
  return holes.every(h=>Math.hypot(x-h[0],y-h[1])>=r+25)&&s.balls.every(b=>!b.n||b.pocket||Math.hypot(x-b.x,y-b.y)>=2*r+1);
}
function placement(s,level){
  const points=[{x:330,y:337}],r=s.ballRadius||15.5;
  if(level!=='easy'&&!s.breakShot)for(const b of targets(s))for(const h of holes){const u=unit(b,{x:h[0],y:h[1]});for(const d of [90,160,240])points.push({x:b.x-u.x*(2*r+d),y:b.y-u.y*(2*r+d)});}
  for(let x=150;x<1080;x+=95)for(let y=150;y<560;y+=80)points.push({x,y});
  let best=null,score=-Infinity;
  for(const p of points){if(!placementValid(s,p.x,p.y))continue;
    if(level==='easy'||s.breakShot)return p;
    const trial={...s,balls:s.balls.map(b=>b.n===0?{...b,...p,pocket:false}:b)},rr=routes(trial,'easy');
    const v=(rr[0]?.quality??-100)+Math.min(10,rr.length);if(v>score){score=v;best=p;}
  }
  if(!best)for(let x=100;x<1095&&!best;x+=16)for(let y=101;y<574;y+=16)if(placementValid(s,x,y)){best={x,y};break;}
  if(!best)throw Error('No legal cue-ball position');return best;
}
export function choose(task,limits={}){
  if(task.engine!==ENGINE||!profiles[task.difficulty])throw Error('Invalid Pool bot task');
  const s=task.position,cfg=profiles[task.difficulty],start=performance.now(),random=seeded(task.positionKey),level=task.difficulty;
  const result=(action,payload,extra={})=>({engine:ENGINE,action,payload,elapsedMs:performance.now()-start,...extra});
  if(task.pendingShot)return result('shot',task.pendingShot,{reason:'announced-pocket'});
  if(['rack','rerack'].includes(s.phase))return result('rack',{}, {reason:'verified-rack'});
  if(s.phase==='placement')return result('place',placement(s,level),{reason:'legal-ball-in-hand'});
  if(s.phase==='choice'){
    const choice=s.choice==='push-out'?(level!=='easy'&&(routes({...s,phase:'aim'},'easy')[0]?.quality??-100)<30?'return':'take'):s.choice?.startsWith('illegal')?'accept':'spot';
    return result('choice',{choice},{reason:s.choice});
  }
  if(s.phase!=='aim')throw Error('No Pool bot action');
  if(s.stalemateRequests?.some(id=>id!==player(s)))return result('stalemate',{}, {reason:'accept-requested-stalemate'});
  const cue=s.balls.find(b=>b.n===0&&!b.pocket),legal=targets(s),r=s.ballRadius||15.5;
  if(!cue||!legal.length)throw Error('No legal target');
  const candidates=routes(s,level);
  if(s.breakShot){const apex=active(s).reduce((a,b)=>a.x<b.x?a:b);candidates.unshift({angle:Math.atan2(apex.y-cue.y,apex.x-cue.x),kind:'break',target:apex.n,first:apex.n,pocket:-1,cut:1,distance:1000,quality:200});}
  // Legal-contact fallback and kick routes also cover positions with no clear pot.
  for(const b of legal){
    if(clear(cue,b,s.balls,[0,b.n],r))candidates.push({angle:Math.atan2(b.y-cue.y,b.x-cue.x),target:b.n,first:b.n,pocket:-1,kind:'contact',quality:-80,cut:1,distance:length(cue,b)});
    if(level==='expert')for(const w of wall(r)){const path=bouncePath(cue,b,[w]);if(path&&clear(cue,path[0],s.balls,[0],r)&&clear(path[0],b,s.balls,[0,b.n],r))candidates.push({angle:Math.atan2(path[0].y-cue.y,path[0].x-cue.x),target:b.n,first:b.n,pocket:-1,kind:'kick-contact',quality:-100,cut:1,distance:1200});}
  }
  if(!candidates.length)candidates.push({angle:Math.atan2(legal[0].y-cue.y,legal[0].x-cue.x),target:legal[0].n,pocket:-1,kind:'escape',quality:-200,cut:1,distance:1200});
  const bestByRoute=new Map(),seen=new Set();let best=null,simulations=0,failures=0;
  const duration=limits.ms??cfg.ms,deadline=start+duration,max=limits.simulations??cfg.simulations;
  let phaseLimit=level==='expert'?Math.max(1,max-240):max,phaseDeadline=level==='expert'?deadline-Math.min(1500,duration*.3):deadline;
  const room=()=>simulations<phaseLimit&&(simulations<1||performance.now()<phaseDeadline);
  function tryShot(route,angle,power,spinX=0,spinY=0){
    if(!room())return;
    power=clamp(power,5,100);
    const calls=!nine(s)&&!s.breakShot&&(s.settings.pocketCalls==='all'||s.settings.pocketCalls==='eight'&&route.target===8);
    const shot={angle,power,spinX,spinY,calledBall:calls&&route.pocket>=0?route.target:0,calledPocket:calls?route.pocket:-1,safety:(!nine(s)&&route.kind==='safety')||calls&&route.pocket<0};
    const key=[angle.toFixed(7),power.toFixed(2),spinX,spinY,shot.calledBall,shot.calledPocket,shot.safety].join(':');if(seen.has(key))return;seen.add(key);simulations++;
    try{const path=simulate(s,shot),v=evaluate(s,shot,path,cfg.position);v.score-=power*.025;
      const trial={shot,route,value:v};if(!best||v.score>best.value.score)best=trial;
      const old=bestByRoute.get(route);if(!old||v.score>old.value.score)bestByRoute.set(route,trial);
    }catch{failures++;}
  }
  // Interleave route types so a dense direct-shot list cannot starve banks/combos.
  const queues=new Map();for(const route of candidates){if(!queues.has(route.kind))queues.set(route.kind,[]);queues.get(route.kind).push(route);}
  const ordered=[];while([...queues.values()].some(q=>q.length)){for(const q of queues.values())if(q.length)ordered.push(q.shift());}
  const initial=ordered.slice(0,level==='expert'?80:level==='normal'?32:10);
  for(const power of (s.breakShot?[100,86]:level==='easy'?[34,46]:[34,48,64]))for(const route of initial){if(!room())break;tryShot(route,route.angle,power);}
  if(level!=='easy'){
    const promising=[...bestByRoute.values()].sort((a,b)=>b.value.score-a.value.score).slice(0,level==='expert'?14:5);
    for(const delta of [-.45,.45,-1.1,1.1,-2,2])for(const t of promising){if(!room())break;tryShot(t.route,t.shot.angle+delta*Math.PI/180,t.shot.power);}
    const finalists=[...bestByRoute.values()].sort((a,b)=>b.value.score-a.value.score).slice(0,level==='expert'?6:2);
    for(const t of finalists)for(const power of [t.shot.power-9,t.shot.power+9,24,78]){if(!room())break;tryShot(t.route,t.shot.angle,power);}
    if(level==='expert'){
      const spinChoices=[[0,-.7],[0,.7],[-.65,0],[.65,0],[-.45,-.45],[.45,-.45],[-.45,.45],[.45,.45]];
      for(const [x,y]of spinChoices)for(const t of finalists){if(!room())break;tryShot(t.route,t.shot.angle,t.shot.power,x,y);}
      const top=best;for(const d of [-.18,.18,-.07,.07])if(room())tryShot(top.route,top.shot.angle+d*Math.PI/180,top.shot.power,top.shot.spinX,top.shot.spinY);
    }
  }
  if(level==='expert'&&!s.breakShot&&best&&!best.value.winning&&!best.value.continues){
    phaseLimit=max;phaseDeadline=deadline;
    // Thin clips, stop/draw and soft contact are deliberate defensive choices,
    // separate from simply missing a pot. Physics rejects scratches/no-rail hits.
    const defensive=[];
    for(const cut of [0,-.55,.55,-.85,.85,-.25,.25])for(const b of legal){
      const u=unit(cue,b),side={x:-u.y,y:u.x},along=2*r*Math.sqrt(1-cut*cut);
      const ghost={x:b.x-u.x*along+side.x*2*r*cut,y:b.y-u.y*along+side.y*2*r*cut};
      if(clear(cue,ghost,s.balls,[0,b.n],r))defensive.push({angle:Math.atan2(ghost.y-cue.y,ghost.x-cue.x),target:b.n,pocket:-1,kind:'safety'});
    }
    for(const power of [16,24,10,34,48,64])for(const route of defensive){if(!room())break;tryShot(route,route.angle,power);}
    const safe=[...bestByRoute.values()].filter(t=>!t.value.foul&&!t.value.continues).sort((a,b)=>b.value.score-a.value.score).slice(0,6);
    for(const sy of [-.7,.7])for(const t of safe)if(room())tryShot(t.route,t.shot.angle,t.shot.power,0,sy);
  }
  if(!best)throw Error('Pool search could not simulate a shot');
  const shot={...best.shot};
  if(cfg.error){shot.angle+=(random()+random()-1)*cfg.error*Math.PI/180;shot.power=clamp(shot.power*(1+(random()-.5)*(level==='easy'?.18:.025)),5,100);}
  const defensive=level==='expert'&&!s.breakShot&&!best.value.foul&&!best.value.winning&&!best.value.continues;
  return result('shot',shot,{reason:defensive?'safety-'+best.route.kind:best.route.kind,simulations,failures,expected:{...best.value,defensive},positionPlanning:cfg.position});
}
