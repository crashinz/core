/** First-party Acey Deucy search; authoritative PHP remains the rules owner. */
export const ENGINE='acey-deucy-search-1';
export function unpack(task){const s=task.position;return {points:s.turnOrder.map(id=>s.points[String(id)].slice()),off:s.turnOrder.map(id=>s.off[String(id)]),bar:s.turnOrder.map(id=>s.bar[String(id)]),borne:s.turnOrder.map(id=>s.borneOff[String(id)]),turn:s.turnIndex,dice:s.remainingDice.slice(),stage:s.aceyStage,sequence:s.europeanSequence?{...s.europeanSequence}:null,european:s.rulesProfile==='european-double-double',winner:null};}
const copy=p=>({...p,points:p.points.map(a=>a.slice()),off:p.off.slice(),bar:p.bar.slice(),borne:p.borne.slice(),dice:p.dice.slice(),sequence:p.sequence?{...p.sequence}:null});
const stateKey=p=>JSON.stringify([p.points,p.off,p.bar,p.borne,p.turn,p.dice,p.stage,p.sequence]);
export function allHome(p,side){return !p.off[side]&&!p.bar[side]&&p.points[side].every((n,i)=>!n||(side===0?i>=18:i<=5));}
export function movesForDie(p,side,die){
 const sources=p.bar[side]?['bar']:[...(p.off[side]?['off']:[]),...p.points[side].flatMap((n,i)=>n?[i]:[])],home=allHome(p,side),out=[];
 for(const from of sources){const exact=typeof from==='number'?(side===0?24-from:from+1):0;
  if(p.european&&home){if(exact===die)out.push({from,to:'borne-off',die});continue;}
  const to=typeof from==='string'?(side===0?die-1:24-die):from+(side===0?die:-die);
  if(to>=0&&to<24){if(p.points[1-side][to]<2)out.push({from,to,die});}
  else if(home&&typeof from==='number'&&(die===exact||(die>exact&&!p.points[side].some((n,i)=>n&&(side===0?i<from:i>from)))))out.push({from,to:'borne-off',die});
 }return out;
}
function movePosition(p,m,side=p.turn){const q=copy(p);if(m.from==='off')q.off[side]--;else if(m.from==='bar')q.bar[side]--;else q.points[side][m.from]--;
 if(m.to==='borne-off')q.borne[side]++;else{if(q.points[1-side][m.to]===1){q.points[1-side][m.to]=0;q.bar[1-side]++;}q.points[side][m.to]++;}return q;}
export function maxUsable(p,dice=p.dice,memo=new Map()){
 if(!dice.length)return 0;const key=JSON.stringify([p.points,p.off,p.bar,p.turn,dice.slice().sort()]);if(memo.has(key))return memo.get(key);let best=0;
 for(const die of new Set(dice))for(const m of movesForDie(p,p.turn,die)){const left=dice.slice();left.splice(left.indexOf(die),1);best=Math.max(best,1+maxUsable(movePosition(p,m),left,memo));if(best===dice.length){memo.set(key,best);return best;}}
 memo.set(key,best);return best;
}
export function legal(p,memo=new Map()){
 if(p.winner!==null)return [];
 if(p.stage==='choose-double')return Array.from({length:6},(_,i)=>i+1).filter(value=>p.european||movesForDie(p,p.turn,value).length).map(value=>({action:'choose-double',value}));
 if(!p.dice.length)return [];
 const max=maxUsable(p,p.dice,memo),high=max===1&&p.dice.length===2&&p.dice[0]!==p.dice[1]?Math.max(...p.dice):0;
 const required=high&&movesForDie(p,p.turn,high).length?high:0,out=[];
 for(const die of new Set(p.dice)){if(required&&die!==required)continue;const left=p.dice.slice();left.splice(left.indexOf(die),1);
  for(const m of movesForDie(p,p.turn,die))if(1+maxUsable(movePosition(p,m),left,memo)===max)out.push({action:'move',...m});
 }return out;
}
const europeanStage=s=>['european-double-primary','european-double-complement','european-acey-primary','european-acey-complement'].includes(s);
function pass(p){p.dice=[];p.sequence=null;p.stage='roll';p.turn=1-p.turn;return p;}
function moreRoll(p){p.dice=[];p.stage='roll-again';return p;}
function choice(p){if([1,2,3,4,5,6].some(d=>movesForDie(p,p.turn,d).length)){p.stage='choose-double';return p;}return pass(p);}
function settle(p){
 if(p.borne[p.turn]===15){p.winner=p.turn;return p;}
 const exhausted=!p.dice.length,blocked=!exhausted&&!p.dice.some(d=>movesForDie(p,p.turn,d).length);
 if(!exhausted&&!blocked)return p;
 if(p.european){
  if(blocked&&(p.stage==='acey-initial'||europeanStage(p.stage)))return pass(p);
  if(p.stage==='acey-initial'){p.stage='choose-double';return p;}
  if(['european-double-primary','european-acey-primary'].includes(p.stage)){
   const seq=p.sequence;p.stage=seq.origin==='acey-deucey'?'european-acey-complement':'european-double-complement';p.sequence={...seq,phase:'complement'};p.dice=Array(4).fill(seq.complement);return settle(p);
  }
  if(['european-double-complement','european-acey-complement'].includes(p.stage)){p.sequence=null;return moreRoll(p);}
 }
 p.dice=[];if(p.stage==='acey-initial')return choice(p);
 if(['ordinary-double','acey-double'].includes(p.stage))return moreRoll(p);
 return pass(p);
}
function primary(p,value,origin){p.dice=Array(4).fill(value);p.stage=origin==='acey-deucey'?'european-acey-primary':'european-double-primary';p.sequence={origin,primary:value,complement:7-value,phase:'primary'};return settle(p);}
export function advance(p,a){let q=copy(p);
 if(a.action==='choose-double'){if(q.european)return primary(q,a.value,'acey-deucey');q.dice=Array(4).fill(a.value);q.stage='acey-double';return q;}
 if(a.action==='roll'){
  const [x,y]=a.dice;q.sequence=null;
  if(q.european&&x===y)return primary(q,x,'rolled-double');
  q.dice=x===y?Array(4).fill(x):[x,y];q.stage=x+y===3?'acey-initial':x===y?'ordinary-double':'moving';return settle(q);
 }
 q=movePosition(p,a);q.dice.splice(q.dice.indexOf(a.die),1);return settle(q);
}
export function evaluate(p,side){
 if(p.winner!==null)return p.winner===side?100000:-100000;
 const values=[0,0];
 for(let s=0;s<2;s++){
  let pip=p.off[s]*25+p.bar[s]*25,score=p.borne[s]*22-p.off[s]*4-p.bar[s]*13,run=0;
  for(let i=0;i<24;i++){const n=p.points[s][i],home=s===0?i>=18:i<=5;pip+=n*(s===0?24-i:i+1);
   if(n>=2){score+=(home?7:4)+Math.min(3,n-2)*-.7;run++;if(run>=2)score+=run*2;}
   else {run=0;if(n===1){let danger=0;for(let d=1;d<=12;d++){const from=i+(s===0?d:-d);if(from>=0&&from<24&&p.points[1-s][from])danger+=d<=6?1:0.35;}if(p.bar[1-s]||p.off[1-s]){const entry=s===0?24-i:i+1;if(entry<=6)danger+=2;}score-=Math.min(8,danger)*(home?1.6:1.2);}}
  }
  values[s]=score-pip*.55;
 }return values[side]-values[1-side];
}
function terminalTurn(p,actor){return p.winner!==null||p.turn!==actor||['roll','roll-again'].includes(p.stage);}
export function chooseMove(task,options={}){
 const start=performance.now(),p=unpack(task),actor=p.turn,budget=Math.min(1800,Math.max(30,task.moveTimeMs||700)),deadline=start+budget;
 const STOP=Symbol('deadline'),memo=new Map();let nodes=0;const maxNodes=options.maxNodes||Infinity;
 const check=()=>{if(performance.now()>=deadline||nodes>=maxNodes)throw STOP;};
 const score=q=>evaluate(q,actor)+(q.turn===actor&&q.stage==='roll-again'?7:0);
 const initial=legal(p,memo);if(!initial.length)return {engine:ENGINE,error:'no-legal-move'};
 let fallback=initial.map(a=>({first:a,p:advance(p,a)})).sort((a,b)=>score(b.p)-score(a.p));
 if(task.difficulty==='easy'){
  // Deterministic modest noise; actual dice are never chosen by the engine.
  let seed=2166136261;for(const c of task.positionKey||'easy')seed=Math.imul(seed^c.charCodeAt(0),16777619)>>>0;
  const ranked=fallback.map(t=>{seed=(Math.imul(seed,1664525)+1013904223)>>>0;return {...t,v:score(t.p)+(seed/4294967296-.5)*12};}).sort((a,b)=>b.v-a.v);
  return {engine:ENGINE,...ranked[0].first,elapsedMs:Math.round(performance.now()-start),nodes:initial.length,replyRolls:0};
 }
 function finishTurns(root,side,width){
  let frontier=[{p:root,first:null}],done=[];
  for(let depth=0;frontier.length&&depth<11;depth++){
   const next=new Map();
   for(const t of frontier){check();if(terminalTurn(t.p,side)){done.push(t);continue;}
    for(const a of legal(t.p,memo)){nodes++;check();const q=advance(t.p,a),row={p:q,first:t.first||a},k=stateKey(q);if(!next.has(k))next.set(k,row);}
   }
   frontier=[...next.values()].sort((a,b)=>evaluate(b.p,side)-evaluate(a.p,side)).slice(0,width);
  }
  done.push(...frontier.filter(t=>terminalTurn(t.p,side)));return done.sort((a,b)=>evaluate(b.p,side)-evaluate(a.p,side));
 }
 let best=fallback[0],replyRolls=0,completedTurn=false;
 try{
  const ends=finishTurns(p,actor,task.difficulty==='expert'?40:24);if(ends.length){ends.sort((a,b)=>score(b.p)-score(a.p));best=ends[0];completedTurn=true;}
  if(task.difficulty==='expert'&&ends.length>1&&best.p.winner===null){
   const candidates=ends.slice(0,3),scores=[];
   // Only complete comparisons over all 21 weighted rolls can replace the static result.
   for(const t of candidates){let total=0;const rolling=t.p.turn;
    for(let x=1;x<=6;x++)for(let y=1;y<=x;y++){check();const rolled=advance(t.p,{action:'roll',dice:[x,y]}),replies=finishTurns(rolled,rolling,8),end=replies[0]?.p||rolled;total+=evaluate(end,actor)*(x===y?1:2);}
    scores.push({t,value:total/36});
   }
   scores.sort((a,b)=>b.value-a.value);best=scores[0].t;replyRolls=21;
  }
 }catch(e){if(e!==STOP)throw e;}
 return {engine:ENGINE,...best.first,elapsedMs:Math.round(performance.now()-start),nodes,completedTurn,replyRolls};
}
