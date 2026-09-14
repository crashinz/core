// Fixed-step mirror of space_arcade_tick. Inputs are replayed and validated by
// the server; this module never submits a position, collision, score or clock.
export function spaceSnapshot(state) {
  return {
    ...(state.ships?{turnOrder:[...state.turnOrder],ships:Object.fromEntries(state.turnOrder.map(id=>[id,{...state.ships[id],controls:{...state.ships[id].controls}}]))}:{}),
    elapsedMs:state.elapsedMs || 0, level:state.level, levelElapsedMs:state.levelElapsedMs,
    completed:!!state.completed, terminalReason:state.terminalReason || null,
    kills:{...state.kills}, orbs:(state.orbs || []).map(orb=>({...orb})),
    ship:{...state.ship,controls:{...state.ship.controls}},
  };
}
export function spaceCoopStep(state,actor,input) {
  if(state.completed)return;
  for(const id of state.turnOrder){
    const ship=state.ships[id];
    if(Number(id)===Number(actor)){ship.controls={...input};ship.inputLeaseMs=1200;}
    ship.inputLeaseMs=Math.max(0,ship.inputLeaseMs-20);
    if(!ship.inputLeaseMs)ship.controls={left:false,right:false,fire:false};
    ship.cooldownMs=Math.max(0,ship.cooldownMs-20);
    ship.x=Math.max(30,Math.min(930,ship.x+(Number(ship.controls.right)-Number(ship.controls.left))*7.6));
  }
  state.elapsedMs+=20;state.levelElapsedMs+=20;
  if(state.levelElapsedMs>=0){
    for(const id of state.turnOrder){const ship=state.ships[id];if(ship.controls.fire&&!ship.cooldownMs){state.orbs.push({x:ship.x,y:550,by:Number(id)});ship.cooldownMs=280;}}
    const aliens=spaceAliens(state);
    if(aliens.some(a=>a.y+32>=562)){state.completed=true;state.terminalReason='invaded';}
    else {
      state.orbs=state.orbs.filter(orb=>{
        orb.y-=10.4;if(orb.y < -8)return false;
        const hit=aliens.find(a=>!state.kills[a.id]&&orb.x>a.x&&orb.x<a.x+44&&orb.y>a.y&&orb.y<a.y+32);
        if(hit){state.kills[hit.id]=true;state.ships[orb.by].score+=10;return false;}return true;
      });
      if(Object.keys(state.kills).length===Math.min(7,5+Math.floor((state.level-1)/2))*11){state.level++;state.kills={};state.orbs=[];state.levelElapsedMs=-850;}
    }
  }
  state.ship={...state.ships[state.turnOrder[0]],controls:{...state.ships[state.turnOrder[0]].controls}};
}
export function spaceAliens(state) {
  const step=Math.floor(Math.max(0,state.levelElapsedMs)/Math.max(150,480-(state.level-1)*42));
  const cycle=Math.floor(step/4),phase=step%4;
  const dx=(cycle%2===0?phase:3-phase)*44,dy=cycle*(16+Math.min(14,(state.level-1)*2));
  const sy=Math.max(48,80-Math.min(22,(state.level-1)*4)), result=[];
  for(let r=0;r<Math.min(7,5+Math.floor((state.level-1)/2));r++)for(let c=0;c<11;c++) {
    const id=r*11+c;
    if(!state.kills[id])result.push({id,x:80+c*62+dx,y:sy+r*48+dy});
  }
  return result;
}
export function spaceStep(state,controls) {
  if(state.completed)return;
  const ship=state.ship;
  ship.controls={...controls};ship.inputLeaseMs=1180;
  ship.cooldownMs=Math.max(0,ship.cooldownMs-20);
  ship.x=Math.max(30,Math.min(930,ship.x+(Number(controls.right)-Number(controls.left))*7.6));
  state.elapsedMs+=20;state.levelElapsedMs+=20;
  if(state.levelElapsedMs<0)return;
  if(controls.fire&&ship.cooldownMs===0){state.orbs.push({x:ship.x,y:550});ship.cooldownMs=280;}
  const aliens=spaceAliens(state);
  if(aliens.some(alien=>alien.y+32>=562)){state.completed=true;state.terminalReason='invaded';return;}
  state.orbs=state.orbs.filter(orb=>{
    orb.y-=10.4;
    if(orb.y < -8)return false;
    const hit=aliens.find(alien=>!state.kills[alien.id]&&orb.x>alien.x&&orb.x<alien.x+44&&orb.y>alien.y&&orb.y<alien.y+32);
    if(hit){state.kills[hit.id]=true;ship.score+=10;return false;}
    return true;
  });
  if(Object.keys(state.kills).length===Math.min(7,5+Math.floor((state.level-1)/2))*11){
    state.level++;state.kills={};state.orbs=[];state.levelElapsedMs=-850;
  }
}
export function spaceKey(state) {
  const value=spaceSnapshot(state);
  value.kills=Object.keys(value.kills).filter(k=>value.kills[k]).map(Number).sort((a,b)=>a-b);
  return JSON.stringify(value,(_,v)=>typeof v==='number'?Math.round(v*1e6)/1e6:v);
}
