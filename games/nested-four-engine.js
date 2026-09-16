/** First-party Nested Four search. Input is persistent public-observation memory only. */
export const ENGINE='nested-four-search-1';
export const LINES=[[0,1,2,3],[4,5,6,7],[8,9,10,11],[12,13,14,15],[0,4,8,12],[1,5,9,13],[2,6,10,14],[3,7,11,15],[0,5,10,15],[3,6,9,12]];
const top=s=>s.at(-1)||0,sign=p=>p===0?1:-1,size=p=>(Math.abs(p)-1)%4+1;
export function position(task){const m=task.position;return {board:m.board.map(s=>s.slice()),reserves:m.reserves.map(a=>a.map(s=>s.slice())),selected:m.selected?{...m.selected}:null,turn:m.turn,rule:task.reserveCoveringRule,seen:m.seen||{}};}
export function winning(board,p){return LINES.some(l=>l.every(i=>Math.sign(top(board[i]))===sign(p)));}
function key(p){return JSON.stringify([p.board,p.reserves,p.turn]);}
export function moves(p){
 const who=sign(p.turn),sources=[];
 if(p.selected)sources.push(p.selected);
 else{
  p.reserves[p.turn].forEach((s,i)=>{if(s.length)sources.push({sourceType:'reserve',sourceIndex:i,piece:top(s)});});
  p.board.forEach((s,i)=>{if(Math.sign(top(s))===who)sources.push({sourceType:'board',sourceIndex:i,piece:top(s)});});
 }
 const out=[];
 for(const src of sources){
  const board=p.board.map(s=>s.slice()),reserves=p.reserves.map(a=>a.map(s=>s.slice()));
  if(!p.selected){if(src.sourceType==='board')board[src.sourceIndex].pop();else reserves[p.turn][src.sourceIndex].pop();}
  const threats=new Set();
  if(src.sourceType==='reserve'&&p.rule!=='custom')for(const l of LINES){const cells=l.filter(i=>Math.sign(top(board[i]))===-who);if(cells.length===3)cells.forEach(i=>threats.add(i));}
  const revealed=winning(board,1-p.turn);let available=0;
  for(let to=0;to<16;to++){
   if(src.sourceType==='board'&&to===src.sourceIndex)continue;
   const target=top(board[to]);
   if(target&&(size(target)>=size(src.piece)||(src.sourceType==='reserve'&&(Math.sign(target)===who||(p.rule!=='custom'&&!threats.has(to))))))continue;
   board[to].push(src.piece);
   if(!revealed||!winning(board,1-p.turn)){
    const next={board:board.map(s=>s.slice()),reserves,selected:null,turn:1-p.turn,rule:p.rule,seen:p.seen};
    const win=winning(board,p.turn)?p.turn:winning(board,1-p.turn)?1-p.turn:null;
    out.push({...src,destination:to,next,winner:win});available++;
   }
   board[to].pop();
  }
  // Committed pieces with no destination lose under the authoritative rules.
  if(!available)out.push({...src,destination:-1,next:null,winner:1-p.turn});
 }
 return out;
}
function evaluate(p,player){
 const own=sign(player);let score=0;
 for(const l of LINES){let a=0,b=0;for(const i of l){const t=top(p.board[i]);if(Math.sign(t)===own)a++;else if(t)b++;}
  if(!b)score+=[0,4,30,240,100000][a];if(!a)score-=[0,4,30,260,100000][b];
 }
 for(let i=0;i<16;i++){const s=p.board[i],t=top(s);if(!t)continue;const d=Math.sign(t)===own?1:-1;score+=d*(size(t)*3+([5,6,9,10].includes(i)?5:2));if(s.length>1&&Math.sign(s.at(-2))!==Math.sign(t))score+=d*6;}
 return score;
}
export function chooseMove(task,options={}){
 const start=performance.now(),p=position(task),player=p.turn,level=task.difficulty;
 const deadline=start+Math.min(1800,Math.max(20,Number(task.moveTimeMs)||700)),maxNodes=options.maxNodes||Infinity;
 const depthLimit=options.depth||({easy:1,normal:2,expert:5}[level]||2);let nodes=0,depthDone=0;
 const expired=()=>performance.now()>=deadline||nodes>=maxNodes;
 const scoreMove=(m,actor)=>m.winner!==null?(m.winner===actor?1000000:-1000000):evaluate(m.next,actor);
 const rank=(list,actor)=>list.map(m=>({...m,rank:scoreMove(m,actor)})).sort((a,b)=>b.rank-a.rank||a.sourceType.localeCompare(b.sourceType)||a.sourceIndex-b.sourceIndex||a.destination-b.destination);
 let roots=rank(moves(p),player);if(!roots.length)return {engine:ENGINE,error:'no-legal-move'};
 let best=roots[0];
 // All immediate wins are checked before pruning. Easy stops after this one-ply view.
 const path=new Map();const STOP=Symbol('deadline');
 function search(node,depth,alpha,beta,ply){
  if(expired())throw STOP;nodes++;
  const k=key(node),visits=(path.get(k)||0)+(node.seen[k]||0);if(visits>=2)return 0;
  if(depth===0)return evaluate(node,player);
  const maximizing=node.turn===player;let value=maximizing?-Infinity:Infinity;
  let candidates=rank(moves(node),node.turn);if(!candidates.length)return maximizing?-1000000+ply:1000000-ply;
  // Keep the strongest tactical candidates; both levels check every immediate move.
  const width=level==='expert'?20:16;candidates=candidates.slice(0,width);
  path.set(k,(path.get(k)||0)+1);
  try{for(const m of candidates){
   const v=m.winner!==null?(m.winner===player?1000000-ply:-1000000+ply):search(m.next,depth-1,alpha,beta,ply+1);
   value=maximizing?Math.max(value,v):Math.min(value,v);if(maximizing)alpha=Math.max(alpha,value);else beta=Math.min(beta,value);if(beta<=alpha)break;
  }}finally{path.set(k,path.get(k)-1);}
  return value;
 }
 if(best.winner===player)depthDone=1;
 else for(let depth=1;depth<=depthLimit;depth++){
  let completed=true,choice=best,bestScore=-Infinity,alpha=-Infinity;const evaluated=[];
  try{for(const m of roots){
   const score=m.winner!==null?(m.winner===player?1000000:-1000000):search(m.next,depth-1,alpha,Infinity,1);
   evaluated.push({...m,rank:score});if(score>bestScore){choice=m;bestScore=score;}alpha=Math.max(alpha,score);
  }}catch(e){if(e!==STOP)throw e;completed=false;}
  if(!completed)break;best=choice;depthDone=depth;roots=evaluated.sort((a,b)=>b.rank-a.rank);
  if(bestScore>=999000||level==='easy')break;
 }
 return {engine:ENGINE,sourceType:best.sourceType,sourceIndex:best.sourceIndex,destination:best.destination,elapsedMs:Math.round(performance.now()-start),depth:depthDone,nodes};
}
