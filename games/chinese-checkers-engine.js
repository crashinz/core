/* JumpStar classical adaptation, Joe Philleo, CC BY-NC 4.0.
 * Source b39d39e57d7444098feadae007f7714171310981, src/cczero.cpp
 * evaluate_state/TrafficGreedy/beam ordering. See THIRD_PARTY_NOTICES.md.
 * CoreChat changes: JS port, 2-6 independent players, exact server goal lock,
 * assignment to distinct goal holes, bounded search and public move memory.
 * No neural weights or upstream benchmark-strength claim.
 */
export const ENGINE = 'jumpstar-classical-b39d39e-corechat-1';
const directions=[[0,2],[0,-2],[1,1],[1,-1],[-1,1],[-1,-1]];
export function createPosition(task) {
  const ids=Object.keys(task.geometry).sort(), index=new Map(ids.map((id,i)=>[id,i]));
  const coords=ids.map(id=>task.geometry[id]), lookup=new Map(coords.map((c,i)=>[`${c.row}:${c.x2}`,i]));
  const neighbors=coords.map(c=>directions.map(([r,x])=>lookup.get(`${c.row+r}:${c.x2+x}`) ?? -1));
  const jumps=coords.map(c=>directions.map(([r,x])=>lookup.get(`${c.row+2*r}:${c.x2+2*x}`) ?? -1));
  const order=task.position.turnOrder, board=new Int8Array(ids.length);board.fill(-1);
  for(const [id,user] of Object.entries(task.position.board))board[index.get(id)]=order.indexOf(user);
  const targets=order.map(user=>task.arms[task.position.targetByUser[user]].map(id=>index.get(id)));
  const homes=order.map(user=>new Set(task.arms[task.position.homeByUser[user]].map(id=>index.get(id))));
  const goal=targets.map(a=>new Set(a));
  const distances=coords.map(a=>coords.map(b=>{const dr=b.row-a.row,dx=(b.x2-a.x2-dr)/2;return Math.max(Math.abs(dr),Math.abs(dx),Math.abs(dr+dx));}));
  const distance=targets.map(t=>ids.map((_,i)=>Math.min(...t.map(j=>distances[i][j]))));
  const history=(task.position.history||[]).filter(m=>m.type==='move').map(m=>({player:order.indexOf(m.userId),from:index.get(m.from),to:index.get(m.to)}));
  return {ids,index,board,order,neighbors,jumps,targets,homes,goal,distances,distance,history,turn:task.position.turnIndex};
}
export function legalMoves(p,player,board=p.board) {
  const out=[];
  for(let from=0;from<board.length;from++)if(board[from]===player){
    const locked=p.goal[player].has(from), seen=new Set([from]), destinations=new Set();
    for(const to of p.neighbors[from])if(to>=0&&board[to]===-1&&(!locked||p.goal[player].has(to)))destinations.add(to);
    const queue=[[from,locked]];
    for(let q=0;q<queue.length;q++){
      const [at,entered]=queue[q];
      for(let d=0;d<6;d++){
        const mid=p.neighbors[at][d],to=p.jumps[at][d];
        if(mid<0||to<0||mid===from||board[mid]===-1||(board[to]!==-1&&to!==from)||seen.has(to))continue;
        if(entered&&!p.goal[player].has(to))continue;
        seen.add(to);destinations.add(to);queue.push([to,entered||p.goal[player].has(to)]);
      }
    }
    for(const to of destinations)out.push({from,to});
  }
  return out;
}
export function moved(board,move,player){const b=board.slice();b[move.from]=-1;b[move.to]=player;return b;}
export function won(p,board,player){return p.targets[player].every(i=>board[i]===player);}
// Hungarian minimum assignment avoids treating every marble as headed to the same hole.
function assignment(p,pieces,targets) {
  const n=pieces.length,u=new Float64Array(n+1),v=new Float64Array(n+1),match=new Int16Array(n+1),way=new Int16Array(n+1);
  for(let i=1;i<=n;i++){
    match[0]=i;let j0=0;const min=new Float64Array(n+1).fill(Infinity),used=new Uint8Array(n+1);
    do{
      used[j0]=1;const i0=match[j0];let delta=Infinity,j1=0;
      for(let j=1;j<=n;j++)if(!used[j]){
        const cur=p.distances[pieces[i0-1]][targets[j-1]]-u[i0]-v[j];
        if(cur<min[j]){min[j]=cur;way[j]=j0;}
        if(min[j]<delta){delta=min[j];j1=j;}
      }
      for(let j=0;j<=n;j++)if(used[j]){u[match[j]]+=delta;v[j]-=delta;}else min[j]-=delta;
      j0=j1;
    }while(match[j0]);
    do{const j1=way[j0];match[j0]=match[j1];j0=j1;}while(j0);
  }
  return -v[0];
}
export function evaluate(p,board,player,advanced=true) {
  let distance=0,home=0,goal=0,lag=0,blockers=0;const pieces=[];
  for(let i=0;i<board.length;i++){
    if(board[i]===player){pieces.push(i);distance+=p.distance[player][i];lag=Math.max(lag,p.distance[player][i]);home+=+p.homes[player].has(i);goal+=+p.goal[player].has(i);}
    else if(board[i]>=0&&p.goal[player].has(i))blockers++;
  }
  if(goal===10)return 1000000;
  // Upstream HandEval weights, expressed as each player's own utility for multiplayer.
  let score=-18*distance+120*goal-16*home-7*lag-45*blockers;
  if(goal>=7)score+=-45*distance+180*goal-80*home;
  if(advanced)score-=38*assignment(p,pieces,p.targets[player])+240*home+8*lag*lag;
  return score;
}
function memoryPenalty(p,player,m){
  let penalty=0;
  for(const h of p.history.slice(-4*p.order.length*3))if(h.player===player){
    if(h.from===m.to&&h.to===m.from)penalty+=100;
    if(h.from===m.from&&h.to===m.to)penalty+=45;
  }
  return penalty;
}
function ordered(p,board,player,level,deadline=Infinity){
  const list=[];
  for(const m of legalMoves(p,player,board)){
    const b=moved(board,m,player);let score;
    if(level==='easy'){
      score=-10*p.distance[player].reduce((sum,d,i)=>sum+(b[i]===player?d:0),0)+25*p.targets[player].filter(i=>b[i]===player).length;
      score+=6*(p.distance[player][m.from]-p.distance[player][m.to]);
      if(won(p,b,player))score=1000000;
    }else score=evaluate(p,b,player);
    // Empty the starting triangle before an opponent's locked goal marbles seal it.
    // This is a strategy preference, not JumpStar's different anti-blocking win rule.
    if(p.homes[player].has(m.from))score+=160*(p.distance[player][m.from]-p.distance[player][m.to]);
    score-=memoryPenalty(p,player,m);
    // TrafficGreedy favors a landing with room for the following move.
    if(level!=='easy')score+=p.neighbors[m.to].filter(i=>i>=0&&b[i]===-1).length*2;
    list.push({...m,score,board:b});
    if(list.length>0&&performance.now()>deadline)break;
  }
  return list.sort((a,b)=>b.score-a.score||a.from-b.from||a.to-b.to);
}
export function chooseMove(task,options={}){
  const started=performance.now(),p=createPosition(task),player=p.turn,level=task.difficulty;
  const budget=Math.max(20,Math.min(2000,task.moveTimeMs||1400)),deadline=started+budget;
  const roots=ordered(p,p.board,player,level,deadline);if(!roots.length)return {engine:ENGINE,error:'no-legal-move'};
  let best=roots[0],searched=0;
  if(level==='expert'&&best.score<900000){
    const beam=options.beam||10;let bestScore=-Infinity;
    for(const root of roots.slice(0,beam)){
      if(performance.now()>deadline)break;
      let board=root.board,threat=false;
      // Each opponent pursues its own goal; never assume a two-player coalition.
      for(let offset=1;offset<p.order.length;offset++){
        const other=(player+offset)%p.order.length,reply=ordered(p,board,other,'normal',deadline)[0];
        if(reply){board=reply.board;if(won(p,board,other)){threat=true;break;}}
        if(performance.now()>deadline)break;
      }
      if(performance.now()>deadline)break; // incomplete branches never replace a completed choice
      const follow=threat?null:ordered(p,board,player,'normal',deadline)[0];
      if(performance.now()>deadline)break;
      const score=threat?-1000000:follow?0.25*root.score+0.75*follow.score:root.score;
      searched++;
      if(score>bestScore){bestScore=score;best=root;}
    }
  }
  return {engine:ENGINE,from:p.ids[best.from],to:p.ids[best.to],elapsedMs:Math.round(performance.now()-started),searched,score:best.score};
}
