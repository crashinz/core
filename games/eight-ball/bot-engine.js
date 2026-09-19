// Pool bots choose inputs only. The server remains the owner of every outcome.
import {step, moving, strike, shotSpeed, holes} from './table.js?v=f37c53619037';
export const ENGINE = 'corechat-pool-search-1';
export const profiles = Object.freeze({
  easy: {ms:600, simulations:90, banks:0, combinations:false, position:false, error:1.65},
  normal: {ms:1800, simulations:240, banks:1, combinations:false, position:true, error:.10},
  expert: {ms:30000, simulations:5000, banks:4, combinations:true, position:true, error:0},
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
const railSequences=(walls,n)=>n===0?[[]]:railSequences(walls,n-1).flatMap(s=>walls.filter(w=>w!==s.at(-1)).map(w=>[...s,w]));
// General visible-board routes. No practice-layout IDs or stored shot answers.
function advancedRoutes(s,cue,legal,r){
  const balls=s.balls,live=active(s),ws=wall(r),out=[],seqs=[1,2,3,4].map(n=>railSequences(ws,n));
  const eligible=(first,b)=>b.n!==first.n&&(nine(s)||group(b.n)===group(first.n)||!s.groups?.[player(s)]&&b.n!==8);
  const openPath=(start,path,ignore)=>{let a=start;for(const b of path){if(!clear(a,b,balls,ignore,r))return false;a=b;}return true;};
  const add=(first,aim,path,target,pocket,kind,penalty)=>{
    const u=unit(first,aim),ghost={x:first.x-2*r*u.x,y:first.y-2*r*u.y},cut=dot(unit(cue,ghost),u);
    if(cut<.10||!clear(cue,ghost,balls,[0,first.n],r))return;
    let distance=length(cue,ghost),a=first;for(const b of path){distance+=length(a,b);a=b;}
    out.push({angle:Math.atan2(ghost.y-cue.y,ghost.x-cue.x),target:target.n,first:first.n,pocket,kind,cut,distance,quality:cut*100-distance*.028-penalty});
  };
  for(const first of legal)for(let p=0;p<holes.length;p++){
    const h={x:holes[p][0],y:holes[p][1]},u=unit(first,h),ghost={x:first.x-2*r*u.x,y:first.y-2*r*u.y};
    for(const n of [3,4])for(const seq of seqs[n-1]){const path=bouncePath(first,h,seq);if(path&&openPath(first,path,[0,first.n]))add(first,path[0],path,first,p,n+'-cushion-bank',n*22);}
    if(clear(first,h,balls,[0,first.n],r))for(const n of [2,3])for(const seq of seqs[n-1]){
      const path=bouncePath(cue,ghost,seq);if(!path||!openPath(cue,path,[0,first.n]))continue;
      const cut=dot(unit(path.at(-2),ghost),u);if(cut<.12)continue;
      let distance=length(first,h),a=cue;for(const b of path){distance+=length(a,b);a=b;}
      out.push({angle:Math.atan2(path[0].y-cue.y,path[0].x-cue.x),target:first.n,first:first.n,pocket:p,kind:n+'-cushion-kick',cut,distance,quality:cut*100-distance*.028-n*22});
    }
    for(const second of live.filter(b=>eligible(first,b))){
      const v=unit(second,h),g={x:second.x-2*r*v.x,y:second.y-2*r*v.y};if(!clear(second,h,balls,[0,second.n,first.n],r))continue;
      for(const n of [1,2])for(const seq of seqs[n-1]){const path=bouncePath(first,g,seq);if(!path||dot(unit(path.at(-2),g),v)<.15||!openPath(first,path,[0,first.n,second.n]))continue;
        add(first,path[0],[...path,h],second,p,'bank-combination',45+n*12);
      }
      // A three-object-ball combination starts by sending first into second's ghost.
      for(const middle of live.filter(b=>b.n!==second.n&&eligible(first,b))){
        const v2=unit(middle,g),g2={x:middle.x-2*r*v2.x,y:middle.y-2*r*v2.y};
        if(dot(v2,v)<.2||dot(unit(first,g2),v2)<.2||!openPath(first,[g2,g,h],[0,first.n,middle.n,second.n]))continue;
        add(first,g2,[g2,g,h],second,p,'three-ball-combination',55);
      }
    }
  }
  return out;
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
  if(cfg.banks>2)out.push(...advancedRoutes(s,cue,legal,r));
  return out.sort((a,b)=>b.quality-a.quality||a.target-b.target||a.pocket-b.pocket);
}
/** Summarize actual events, independently of the geometric seed's label. */
export function shotFeatures(result){
  const pairs=new Map(),railsByBall={},pots=[],first=result.events.findIndex(e=>e.type==='ball'&&(e.a===0||e.b===0));
  for(const e of result.events){if(e.type==='ball'){const key=[e.a,e.b].sort((a,b)=>a-b).join(':');pairs.set(key,(pairs.get(key)||0)+1);}if(e.type==='rail')railsByBall[e.a]=(railsByBall[e.a]||0)+1;if(e.type==='pocket')pots.push(e.a);}
  return {railsByBall,railBeforeContact:result.events.slice(0,Math.max(0,first)).filter(e=>e.type==='rail'&&e.a===0).length,maxRepeatedContact:Math.max(0,...pairs.values()),ballContacts:[...pairs.keys()],pots};
}
export function simulate(s,shot,dt=1/120,goal=null){
  const balls=s.balls.map(b=>({...b})),cue=balls.find(b=>b.n===0&&!b.pocket),events=[];
  if(!cue)throw Error('Cue ball must be placed');
  strike(cue,shot.angle,shotSpeed(shot.power),{x:shot.spinX||0,y:shot.spinY||0});
  let duration=0,crossed=cue.x>335,firstContactCrossedHeadString=null;
  const object=goal?balls.find(b=>b.n===goal.target):null,railTrace=goal?{rails:[],first:null,approach:2000,pocketDistance:2000}:null;
  const measure=(b,p)=>length(b,p);
  const targetHole=goal?{x:holes[goal.pocket][0],y:holes[goal.pocket][1]}:null;
  while(moving(balls)&&duration<45){
    step(balls,dt,e=>{crossed||=cue.x>335;if(firstContactCrossedHeadString===null&&e.type==='ball'&&(e.a.n===0||e.b.n===0))firstContactCrossedHeadString=crossed;
      if(railTrace){
        if(e.type==='rail'&&e.a.n===0&&railTrace.first===null){const r=s.ballRadius||15.5;railTrace.rails.push(wall(r).findIndex(w=>Math.abs(e.a[w.axis]-w.at)<.01));}
        if(e.type==='ball'&&railTrace.first===null&&(e.a.n===0||e.b.n===0))railTrace.first=e.a.n===0?e.b.n:e.a.n;
      }
      events.push({type:e.type,a:e.a.n,b:e.b?.n??null,pocket:e.hole?holes.indexOf(e.hole):null,time:duration});});
    duration+=dt;crossed||=cue.x>335;
    if(railTrace){if(railTrace.first===null&&goal.walls.every((w,i)=>railTrace.rails[i]===w))railTrace.approach=Math.min(railTrace.approach,measure(cue,goal.ghost));railTrace.pocketDistance=Math.min(railTrace.pocketDistance,measure(object,targetHole));}if(events.length>2048)throw Error('Shot contact budget');
  }
  if(moving(balls))throw Error('Shot did not settle');
  return {balls,events,duration,firstContactCrossedHeadString,...(goal?{railTrace}:{})};
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
// General geometric rail goals, including a clear tangent around an occluder.
export function railGoals(s){
 const r=s.ballRadius||15.5,cue=s.balls.find(b=>b.n===0&&!b.pocket),goals=[],ws=wall(r);
 const delta=(a,b)=>Math.atan2(Math.sin(a-b),Math.cos(a-b));
 for(const first of targets(s))for(let pocket=0;pocket<holes.length;pocket++){
  const h={x:holes[pocket][0],y:holes[pocket][1]},u=unit(first,h),ghost={x:first.x-2*r*u.x,y:first.y-2*r*u.y};
  if(!clear(first,h,s.balls,[0,first.n],r))continue;
  for(const count of [1,2,3])for(const seq of railSequences(ws,count)){
   const path=bouncePath(cue,ghost,seq);if(!path||dot(unit(path.at(-2)||cue,ghost),u)<.12)continue;
   const base=Math.atan2(path[0].y-cue.y,path[0].x-cue.x),angles=[base],w=seq[0];
   for(const b of active(s)){
    const d=length(cue,b);if(d<=2*r+.1)continue;const bearing=Math.atan2(b.y-cue.y,b.x-cue.x),tangent=Math.asin(Math.min(1,(2*r+.35)/d));
    for(const a of [bearing-tangent,bearing+tangent])if(Math.abs(delta(a,base))<.48)angles.push(base+delta(a,base));
   }
   const seeds=[];for(const a of angles){
    const u0={x:Math.cos(a),y:Math.sin(a)},t=(w.at-cue[w.axis])/u0[w.axis];if(!(t>1))continue;
    const p={x:cue.x+u0.x*t,y:cue.y+u0.y*t},other=w.axis==='x'?'y':'x';
    if(p[other]<w.lo+38||p[other]>w.hi-38||w.axis==='y'&&Math.abs(p.x-596)<43||!clear(cue,p,s.balls,[0],r))continue;
    if(!seeds.some(x=>Math.abs(x-a)<.01))seeds.push(a);
   }
   if(!seeds.length)continue;
   let distance=0,a=cue;for(const p of path){distance+=length(a,p);a=p;}
   goals.push({first:first.n,target:first.n,pocket,ghost,walls:seq.map(w=>ws.indexOf(w)),angles:seeds,angle:seeds[0],kind:'refined-rail',quality:100-distance*.025-count*12,cut:1,distance});
  }
 }
 // Keep different first rails and pocket approaches represented.
 goals.sort((a,b)=>b.quality-a.quality);
 const distinct=[];for(const g of goals)if(!distinct.some(x=>x.first===g.first&&x.pocket===g.pocket&&x.walls[0]===g.walls[0]))distinct.push(g);
 return distinct;
}
function railLoss(goal,path,value){
 const f=path.railTrace;if(!f)return 1e6;
 const matches=goal.walls.every((w,i)=>f.rails[i]===w);
 if(!matches)return 4000+goal.walls.reduce((n,w,i)=>n+(f.rails[i]===w?0:300),0)+Math.min(1000,f.approach);
 if(f.first!==goal.first)return 2000+Math.min(1500,f.approach);
 if(path.events.some(e=>e.type==='pocket'&&e.a===goal.target&&e.pocket===goal.pocket)&&!value.foul&&!value.losing)return -1000-Math.min(value.score,1500)*.02;
 return f.pocketDistance*2+(value.foul?500:0);
}
// Joint population refinement discovers inputs from the current public layout.
// It never reads saved example inputs or identifies a board by a fixture ID.
function refineRails(s,tryShot,room,remaining,random){
 const goals=railGoals(s),selected=goals.slice(0,8),N=16,stats={goals:selected.length,trials:0,pots:0,best:[]};
 const cells=selected.map(goal=>({goal,pop:[]}));
 const score=(cell,v)=>{const n=Math.hypot(v[2],v[3]);if(n>1){v[2]/=n;v[3]/=n;}const t=tryShot(cell.goal,v[0],v[1],v[2],v[3],cell.goal);if(!t)return null;stats.trials++;const cost=railLoss(cell.goal,t.path,t.value);if(cost<0)stats.pots++;return{v,cost,trial:t};};
 const powers=[48,64,80,96],spins=[[0,0],[-.6,0],[.6,0],[0,-.6],[0,.6],[-.5,-.5],[.5,-.5],[-.5,.5],[.5,.5]];
 for(let i=0;i<N&&room();i++)for(const c of cells){if(!room())break;const a=c.goal.angles[i%c.goal.angles.length]+[0,.3,-.3,.8,-.8,.08,-.08,1.5][i%8]*Math.PI/180,sp=spins[i%spins.length];const t=score(c,[a,powers[i%powers.length],...sp]);if(t)c.pop.push(t);}
 let generation=0;
 while(room()&&cells.some(c=>c.pop.length>=4)&&generation<80){
  for(const c of cells){if(c.pop.length<4)continue;c.pop.sort((a,b)=>a.cost-b.cost);
   for(let i=0;i<c.pop.length&&room();i++){
    const ids=[];while(ids.length<3){const j=Math.floor(random()*c.pop.length);if(j!==i&&!ids.includes(j))ids.push(j);}
    const force=Math.floor(random()*4),base=c.pop[ids[0]].v,F=.5+random()*.3;
    const v=c.pop[i].v.map((x,k)=>random()<.85||k===force?base[k]+F*(c.pop[ids[1]].v[k]-c.pop[ids[2]].v[k]):x);
    const seed=c.goal.angles.reduce((a,b)=>Math.abs(a-v[0])<Math.abs(b-v[0])?a:b);v[0]=clamp(v[0],seed-.14,seed+.14);v[1]=clamp(v[1],25,100);v[2]=clamp(v[2],-1,1);v[3]=clamp(v[3],-1,1);
    const t=score(c,v);if(t&&t.cost<c.pop[i].cost)c.pop[i]=t;
   }
  }
  generation++;
 }
 stats.generations=generation;stats.best=cells.filter(c=>c.pop.length).map(c=>{const b=c.pop.reduce((x,y)=>x.cost<y.cost?x:y);return{first:c.goal.first,pocket:c.goal.pocket,walls:c.goal.walls,cost:b.cost,shot:b.trial.shot,score:b.trial.value.score,pots:b.trial.value.pots};});return stats;
}

// Tight groups need approaches between neighboring balls as well as at a ball.
// Inflated-circle intersections locate cue-center contacts from public geometry.
export function clusterEntries(s){
 const cue=s.balls.find(b=>b.n===0&&!b.pocket),live=active(s),legal=new Set(targets(s).map(b=>b.n)),r=s.ballRadius||15.5,out=[],seen=new Set();
 if(!cue||s.breakShot)return out;
 const unseen=new Set(live.map(b=>b.n)),groups=[];
 while(unseen.size){const group=[live.find(b=>b.n===unseen.values().next().value)];unseen.delete(group[0].n);
  for(let i=0;i<group.length;i++)for(const b of live)if(unseen.has(b.n)&&length(group[i],b)<=4*r+.1){unseen.delete(b.n);group.push(b);}
  if(group.length>=3&&group.some(b=>legal.has(b.n)))groups.push(group);
 }
 for(const cluster of groups){
  const members=cluster.map(b=>b.n),first=cluster.find(b=>legal.has(b.n));
  const add=(p,kind)=>{if(p.x<83+r||p.x>1110-r||p.y<85+r||p.y>590-r||!clear(cue,p,s.balls,[0,...members],r))return;
   const angle=Math.atan2(p.y-cue.y,p.x-cue.x),key=angle.toFixed(6);if(seen.has(key))return;seen.add(key);
   out.push({angle,first:first.n,target:first.n,pocket:-1,kind:'cluster-outcome',entryKind:kind,members,quality:cluster.length*20-length(cue,p)*.01,window:Math.min(.07,Math.asin(Math.min(.9,2*r/length(cue,p)))*.5)});
  };
  add({x:cluster.reduce((n,b)=>n+b.x,0)/cluster.length,y:cluster.reduce((n,b)=>n+b.y,0)/cluster.length},'center');
  for(const b of cluster)if(legal.has(b.n))add(b,'ball');
  for(let i=0;i<cluster.length;i++)for(let j=i+1;j<cluster.length;j++){
   const a=cluster[i],b=cluster[j],d=length(a,b);if(d<2*r-.01||d>4*r||(!legal.has(a.n)&&!legal.has(b.n)))continue;
   const m={x:(a.x+b.x)/2,y:(a.y+b.y)/2},u=unit(a,b),h=Math.sqrt(Math.max(0,4*r*r-d*d/4));
   add({x:m.x-u.y*h,y:m.y+u.x*h},'seam');add({x:m.x+u.y*h,y:m.y-u.x*h},'seam');
  }
 }
 return out.sort((a,b)=>b.quality-a.quality).slice(0,24);
}
function refineClusters(s,entries,tryShot,room,remaining,random){
 const allowance=Math.min(2600,Math.max(0,remaining())),stats={entries:entries.length,trials:0,maxPots:0,best:[]},useful=new Set(targets(s).map(b=>b.n));
 const cells=entries.map(route=>({route,pop:[]})),spins=[[0,0],[0,.65],[0,-.65],[-.65,0],[.65,0],[-.45,.45],[.45,.45],[-.45,-.45],[.45,-.45]];
 const available=()=>stats.trials<allowance&&room();
 const score=(cell,v)=>{v[0]=clamp(v[0],cell.route.angle-cell.route.window,cell.route.angle+cell.route.window);v[1]=clamp(v[1],12,100);v[2]=clamp(v[2],-1,1);v[3]=clamp(v[3],-1,1);const n=Math.hypot(v[2],v[3]);if(n>1){v[2]/=n;v[3]/=n;}
  const t=tryShot(cell.route,...v);if(!t)return null;stats.trials++;
  const pots=t.value.pots.filter(n=>cell.route.members.includes(n)&&(nine(s)||useful.has(n))).length,legal=!t.value.foul&&!t.value.losing;if(legal)stats.maxPots=Math.max(stats.maxPots,pots);
  // Extra pot-count diversity guides exploration; final selection still uses
  // the unchanged game evaluator, including next position and defensive value.
  return {v,trial:t,merit:t.value.score+(legal?pots*60:0)};
 };
 for(const power of [30,50,70,90])for(let j=0;j<spins.length;j++)for(const c of cells){if(!available())break;const d=[0,.08,-.08][j%3]*Math.PI/180,t=score(c,[c.route.angle+d,power,...spins[j]]);if(t)c.pop.push(t);}
 const finalists=cells.filter(c=>c.pop.length).sort((a,b)=>Math.max(...b.pop.map(t=>t.merit))-Math.max(...a.pop.map(t=>t.merit))).slice(0,6);
 for(const c of finalists)c.pop=c.pop.sort((a,b)=>b.merit-a.merit).slice(0,12);
 let generation=0;
 while(available()&&generation<60&&finalists.some(c=>c.pop.length>=4)){
  for(const c of finalists)for(let i=0;i<c.pop.length&&available();i++){
   if(c.pop.length<4)continue;const ids=[];while(ids.length<3){const k=Math.floor(random()*c.pop.length);if(k!==i&&!ids.includes(k))ids.push(k);}
   const force=Math.floor(random()*4),f=.45+random()*.4,base=c.pop[ids[0]].v;
   const v=c.pop[i].v.map((x,k)=>random()<.85||k===force?base[k]+f*(c.pop[ids[1]].v[k]-c.pop[ids[2]].v[k]):x);
   if(generation%5===0){v[0]+=(random()-.5)*.12*Math.PI/180;v[1]+=(random()-.5)*8;}
   const t=score(c,v);if(t&&t.merit>c.pop[i].merit)c.pop[i]=t;
  }generation++;
 }
 stats.generations=generation;stats.best=finalists.map(c=>{const b=c.pop.reduce((a,b)=>a.merit>b.merit?a:b);return{kind:c.route.entryKind,shot:b.trial.shot,score:b.trial.value.score,pots:b.trial.value.pots,foul:b.trial.value.foul};});return stats;
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
  let phaseLimit=level==='expert'?Math.max(1,max-300):max,phaseDeadline=level==='expert'?deadline-Math.min(1500,duration*.3):deadline;
  const room=()=>simulations<phaseLimit&&(simulations<1||performance.now()<phaseDeadline);
  function tryShot(route,angle,power,spinX=0,spinY=0,goal=null){
    if(!room())return;
    power=clamp(power,5,100);
    const calls=!nine(s)&&!s.breakShot&&(s.settings.pocketCalls==='all'||s.settings.pocketCalls==='eight'&&route.target===8);
    const shot={angle,power,spinX,spinY,calledBall:calls&&route.pocket>=0?route.target:0,calledPocket:calls?route.pocket:-1,safety:(!nine(s)&&route.kind==='safety')||calls&&route.pocket<0};
    const key=[angle.toFixed(7),power.toFixed(2),spinX,spinY,shot.calledBall,shot.calledPocket,shot.safety,goal?goal.first+'/'+goal.pocket+'/'+goal.walls.join(','):''].join(':');if(seen.has(key))return;seen.add(key);simulations++;
    try{const path=simulate(s,shot,1/120,goal);
      // Outcome probes discover caroms/kisses without a known pocket beforehand.
      // Announce the predicted legal pot BEFORE the real shot; robustness keeps that call fixed.
      if(calls&&(route.kind==='contact-outcome'||route.kind==='cluster-outcome')){
        const made=path.events.filter(e=>e.type==='pocket'&&e.a>0&&(legal.some(b=>b.n===8)?e.a===8:group(e.a)===group(route.first)||!s.groups?.[player(s)]&&e.a!==8));
        if(made.length){shot.calledBall=made[0].a;shot.calledPocket=made[0].pocket;shot.safety=false;}
      }
      const v=evaluate(s,shot,path,cfg.position);v.score-=power*.025;
      const trial={shot,route,value:v,features:shotFeatures(path),...(goal?{path}:{})};if(!best||v.score>best.value.score)best=trial;
      const old=bestByRoute.get(route);if(!old||v.score>old.value.score)bestByRoute.set(route,trial);return trial;
    }catch{failures++;}
  }
  // Interleave route types so a dense direct-shot list cannot starve banks/combos.
  const queues=new Map();for(const route of candidates){if(!queues.has(route.kind))queues.set(route.kind,[]);queues.get(route.kind).push(route);}
  const ordered=[];while([...queues.values()].some(q=>q.length)){for(const q of queues.values())if(q.length)ordered.push(q.shift());}
  const initial=ordered.slice(0,level==='expert'?110:level==='normal'?32:10);
  for(const power of (s.breakShot?[100,86]:level==='easy'?[34,46]:level==='expert'?[34,64,90,48,100]:[34,48,64]))for(const route of initial){if(!room())break;tryShot(route,route.angle,power);}
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
  if(level==='expert'&&!s.breakShot&&room()){
    // Bounded low-discrepancy contact exploration covers post-contact curves,
    // caroms, repeated kisses, touching pairs and combinations not captured by
    // reflected straight-line geometry. Only real simulated outcomes earn value.
    const radical=(n,b)=>{let v=0,f=1/b;while(n){v+=(n%b)*f;n=Math.floor(n/b);f/=b;}return v;};
    // Spend the extra allowance on difficult positions, without padding a win.
    const probes=best?.value.winning?420:3000;
    for(let i=1;i<=probes&&room();i++){
      const b=legal[(i-1)%legal.length],u=unit(cue,b),side={x:-u.y,y:u.x},cut=(radical(i,2)*2-1)*.985;
      const along=2*r*Math.sqrt(1-cut*cut),ghost={x:b.x-u.x*along+side.x*2*r*cut,y:b.y-u.y*along+side.y*2*r*cut};
      if(!clear(cue,ghost,s.balls,[0,b.n],r))continue;
      const a=Math.atan2(ghost.y-cue.y,ghost.x-cue.x),rho=Math.sqrt(radical(i,7)),theta=radical(i,11)*Math.PI*2;
      const route={angle:a,first:b.n,target:b.n,pocket:-1,kind:'contact-outcome',quality:-60};
      tryShot(route,a,12+88*radical(i,3),rho*Math.cos(theta),rho*Math.sin(theta));
    }
    const promising=[...bestByRoute.values()].filter(t=>t.route.kind==='contact-outcome').sort((a,b)=>b.value.score-a.value.score).slice(0,8);
    for(const t of promising)for(const d of [-.2,.2,-.05,.05])if(room())tryShot(t.route,t.shot.angle+d*Math.PI/180,t.shot.power,t.shot.spinX,t.shot.spinY);
  }
  let clusterSearch=null;
  if(level==='expert'&&!s.breakShot&&room()&&!best?.value.winning){const entries=clusterEntries(s);if(entries.length)clusterSearch=refineClusters(s,entries,tryShot,room,()=>phaseLimit-simulations,seeded(task.positionKey+'|cluster'));}
  let railSearch=null;
  if(level==='expert'&&!s.breakShot&&room()&&!best?.value.winning&&(!best?.value.continues||!candidates.some(t=>t.kind==='direct'&&t.cut>.35&&t.quality>20))){
    railSearch=refineRails(s,tryShot,room,()=>phaseLimit-simulations,random);
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
  let robustness=null;
  if(level==='expert'&&!s.breakShot){
    // Favor a repeatable winner/pot over an equally valuable razor-thin result.
    // Compare the same public position and announced call with tiny input errors.
    const finalists=[...bestByRoute.values()].sort((a,b)=>b.value.score-a.value.score).slice(0,4);
    let chosen=null;
    for(const t of finalists){const scores=[t.value.score];let good=0;
      for(const [da,dp]of [[-.035,0],[.035,0],[0,-.5],[0,.5]]){
        if(simulations>=max||performance.now()>=deadline)break;
        simulations++;const q={...t.shot,angle:t.shot.angle+da*Math.PI/180,power:clamp(t.shot.power+dp,5,100)};
        try{const v=evaluate(s,q,simulate(s,q),cfg.position);scores.push(v.score-q.power*.025);if(!v.foul&&!v.losing&&(t.value.winning?v.winning:t.value.continues?v.continues:true))good++;}catch{scores.push(-2500);failures++;}
      }
      // Never compare a partly checked candidate against a fully checked one.
      if(scores.length!==5)break;
      const adjusted=scores.reduce((a,b)=>a+b,0)/scores.length;
      if(!chosen||adjusted>chosen.adjusted)chosen={t,adjusted,good};
    }
    if(chosen){best=chosen.t;robustness={successful:chosen.good,tested:4,score:chosen.adjusted};}
  }
  const shot={...best.shot};
  if(cfg.error){shot.angle+=(random()+random()-1)*cfg.error*Math.PI/180;shot.power=clamp(shot.power*(1+(random()-.5)*(level==='easy'?.18:.025)),5,100);}
  const defensive=level==='expert'&&!s.breakShot&&!best.value.foul&&!best.value.winning&&!best.value.continues;
  return result('shot',shot,{reason:defensive?'safety-'+best.route.kind:best.route.kind,simulations,failures,expected:{...best.value,defensive},features:best.features,robustness,railSearch,clusterSearch,positionPlanning:cfg.position});
}
