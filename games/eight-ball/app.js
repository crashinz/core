/* Framework owns sessions, identity, persistence, actions and chat. This bridge
 * keeps the canvas document mounted while authoritative snapshots arrive. */
(() => {
  'use strict';
  const source=new URL('table.html?v=110c1ad1d228',document.currentScript.src);
  let viewportWidth=0;
  document.addEventListener('corechat-pool-viewport',e=>{viewportWidth=e.detail.width;if(ready)frame.contentWindow.postMessage({type:'pool-viewport',width:viewportWidth},location.origin);});
  let root,frame,context,ready=false,inFlight=false,autoRackQueued=false,autoRackAttempt='';
  function rackNeeded(){
    const s=context?.session,st=s?.state;
    return !context?.session?.review&&ready&&!inFlight&&!context.busy&&s?.status==='active'&&!st?.completed
      &&['rack','rerack'].includes(st?.phase)
      &&Number(st.turnOrder?.[st.turnIndex])===Number(context.currentUserId());
  }
  async function perform(action,payload={}){
    if(inFlight||context.busy)return;
    inFlight=true;send();
    try{await context.performAction(action,payload,action==='rack'?'eight-ball-rack':'');}
    finally{inFlight=false;send();}
  }
  function queueRack(){
    if(autoRackQueued||!rackNeeded())return;
    autoRackQueued=true;
    queueMicrotask(()=>{
      autoRackQueued=false;
      if(!rackNeeded())return;
      const key=context.session.publicId+':'+context.session.state.sequence;
      if(autoRackAttempt===key)return;
      autoRackAttempt=key;
      // One verified action per waiting rack. The existing button remains a
      // retry after a failed request; polls never continuously re-rack.
      void perform('rack').catch(()=>{});
    });
  }
  function send(){
    if(!ready||!context)return;
    const s=context.session;
    if(s.review){const width=Math.max(240,(root?.clientWidth||600)*1200/2080);frame.contentWindow.postMessage({type:'pool-viewport',width},location.origin);}
    const members=(s.members||[]).map(m=>{
      const node=context.memberAvatar(m,'pool-avatar');
      const image=node?.tagName==='IMG'?node:node?.querySelector?.('img');
      return {userId:Number(m.userId),name:context.memberName(Number(m.userId)),avatar:image?.src||''};
    });
    frame.contentWindow.postMessage({type:'pool-snapshot',sessionId:s.publicId,status:s.status,review:!!s.review,state:s.state,currentUserId:Number(context.currentUserId()),members,options:context.options,busy:context.busy||inFlight},location.origin);
    queueRack();
  }
  window.addEventListener('message',async e=>{
    if(!frame||e.source!==frame.contentWindow||e.origin!==location.origin)return;
    if(e.data?.type==='pool-height'){const h=Number(e.data.height);if(Number.isFinite(h))frame.style.height=Math.max(350,Math.min(10000,h))+'px';return;}
    if(e.data?.type==='pool-width'){document.dispatchEvent(new CustomEvent('corechat-pool-layout',{detail:{extraWidth:e.data.extraWidth}}));return;}
    if(e.data?.type==='pool-ready'){ready=true;if(viewportWidth)frame.contentWindow.postMessage({type:'pool-viewport',width:viewportWidth},location.origin);send();return;}
    if(e.data?.type!=='pool-action'||!context||inFlight)return;
    const {action,payload}=e.data;
    if(!['shot','call','push-out','rack','place','cue','choice','layout','stalemate','practice-edit','practice-rewind'].includes(action))return;
    await perform(action,payload||{});
  });
  window.CoreChatEightBall={isAnimating(){return !ready||!!frame?.contentWindow.CoreChatPoolPlayback?.isActive();},render(c){context=c;if(!root){root=document.createElement('section');root.className='eight-ball-frame';root.style.cssText='width:100%;min-width:0';root.addEventListener('contextmenu',e=>e.preventDefault());frame=document.createElement('iframe');frame.title='Pool table';frame.src=source.href;frame.style.cssText='width:100%;height:600px;max-height:none;border:0;display:block;background:#101923;border-radius:12px';root.append(frame);}send();return root;}};
})();
