// Server time is anchored to a monotonic local clock. Re-sending the same
// snapshot during a busy/UI change must not give the player more time.
export function createTurnClock({expire,expired=()=>{},now=()=>performance.now(),root=document}){
  let snapshot=null,anchor=0,server=0,session='',wasExpired=false,lastRequest=-Infinity;
  function observe(data){
    const time=Number(data.state?.serverNow)||0;
    if(data.sessionId!==session||time>server){anchor=now();server=time;session=data.sessionId;}
    if(data.state?.turnClock?.id!==snapshot?.state?.turnClock?.id)lastRequest=-Infinity;
    snapshot=data;
  }
  const time=()=>server+(now()-anchor)/1000;
  const clock=()=>snapshot?.status==='active'&&!snapshot.review&&!snapshot.state?.completed&&snapshot.state?.settings?.tableMode==='match'?snapshot.state.turnClock:null;
  const ended=()=>!!clock()&&clock().frozenAt==null&&time()>=clock().expiresAt;
  function tick(){
    const c=clock(),t=c?.frozenAt??time();
    for(const portrait of root.querySelectorAll('.player .portrait')){
      let svg=portrait.querySelector('.turn-countdown');
      const active=!!c&&String(c.actor)===portrait.closest('.player').dataset.playerId&&t>=c.startsAt;
      if(!svg&&active){
        svg=root.createElementNS('http://www.w3.org/2000/svg','svg');svg.classList.add('turn-countdown');
        svg.setAttribute('viewBox','0 0 100 100');svg.setAttribute('preserveAspectRatio','none');svg.setAttribute('role','img');
        // Starts at the top center and drains clockwise, keeping the avatar
        // and its existing clipping untouched at every responsive size.
        const shape=root.createElementNS('http://www.w3.org/2000/svg','path');
        shape.setAttribute('d','M50 3 H85 Q97 3 97 15 V85 Q97 97 85 97 H15 Q3 97 3 85 V15 Q3 3 15 3 Z');
        shape.setAttribute('pathLength','100');svg.append(shape);portrait.append(svg);
      }
      if(svg){
        svg.style.display=active?'block':'none';
        if(active){const left=Math.max(0,c.expiresAt-t),fraction=Math.min(1,left/c.seconds);
          svg.firstChild.style.strokeDasharray=`${fraction*100} 100`;
          const label=`${Math.ceil(left)} of ${c.seconds} seconds remaining`;
          if(svg.getAttribute('aria-label')!==label)svg.setAttribute('aria-label',label);
        }
      }
    }
    const done=ended();if(done!==wasExpired){wasExpired=done;if(done)expired();}
    if(done&&snapshot.state.turnOrder?.includes(snapshot.currentUserId)&&now()-lastRequest>=2000){lastRequest=now();expire(c.id);}
  }
  return {observe,tick,expired:ended};
}
