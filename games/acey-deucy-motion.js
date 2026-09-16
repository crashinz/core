/** Presentation-only motion from the last server-confirmed public Acey Deucy move. */
let active=null,timer=0,lastKey='';
export function aceyMoveAnimating(){return Boolean(active&&performance.now()<active.end);}
const zone=from=>typeof from==='number'?`[data-point-key="point:${from}"]`:`[data-zone-key="${from}"]`;
function checker(board,from,actor){return Array.from(board?.querySelectorAll(`${zone(from)} [data-owner-user-id="${actor}"] .built-in-checker`)||[]).at(-1);}
function shape(node){if(!node)return null;const r=node.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2,width:r.width,height:r.height,art:node.cloneNode(true)};}
export function appendAceyMove(board,motion,sessionId,enabled,rerender){
 clearTimeout(timer);
 if(!enabled||!motion||!['point-move','point-hit','point-bear-off'].includes(motion.type)||typeof Element.prototype.animate!=='function'){active=null;return;}
 const key=sessionId+':'+motion.version;
 if(lastKey!==key){
  lastKey=key;active=null;
  if((motion.after.history?.length||0)!==(motion.before.history?.length||0)+1)return;
  const old=document.querySelector('.built-in-point-board.is-acey'),actor=Number(motion.move.actorUserId),origin=shape(checker(old,motion.move.from,actor));if(!origin)return;
  const victim=motion.after.turnOrder.map(Number).find(id=>id!==actor),hit=motion.type==='point-hit'?shape(checker(old,motion.move.to,victim)):null;
  const duration=hit?1500:900,started=performance.now();active={key,origin,actor,victim,hit,to:motion.move.to,started,end:started+duration,duration};
 }
 if(!aceyMoveAnimating())return;const current=active;
 for(const button of board.querySelectorAll('button'))button.disabled=true;
 timer=setTimeout(rerender,Math.max(1,current.end-performance.now())+20);
 requestAnimationFrame(()=>{
  if(!board.isConnected||active!==current||!aceyMoveAnimating())return;
  const br=board.getBoundingClientRect(),elapsed=performance.now()-current.started;
  function travel(origin,to,delay,duration){
   const target=checker(board,to,origin===current.origin?current.actor:current.victim);if(!target)return;
   const r=target.getBoundingClientRect(),layer=origin.art.cloneNode(true);layer.classList.add('acey-moving-checker');layer.setAttribute('aria-hidden','true');
   layer.style.cssText=`position:absolute;left:0;top:0;width:${origin.width}px;height:${origin.height}px;margin:0;pointer-events:none;z-index:50;transform:none;`;
   const x=origin.x-br.x-origin.width/2,y=origin.y-br.y-origin.height/2,endX=r.x-br.x,endY=r.y-br.y;
   target.style.visibility='hidden';board.append(layer);
   const animation=layer.animate([{transform:`translate(${x}px,${y}px)`},{transform:`translate(${endX}px,${endY}px)`}],{duration,delay,easing:'ease-in-out',fill:'both'});animation.currentTime=elapsed;
   animation.onfinish=()=>{layer.remove();if(target.isConnected)target.style.visibility='';};
  }
  if(current.hit)travel(current.hit,'bar',0,600);
  travel(current.origin,current.to,current.hit?600:0,current.hit?900:900);
 });
}

