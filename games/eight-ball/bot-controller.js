export function createPoolBotController({snapshot,submit,showStatus,WorkerClass=globalThis.Worker}){
  let job=null,failedKey='';
  function stop(){if(job){clearTimeout(job.timeout);clearTimeout(job.delay);job.worker?.terminate();job=null;}}
  function fail(active,message){if(job!==active)return;stop();failedKey=active.key;showStatus(message,()=>{failedKey='';sync();});}
  function sync(){
    const current=snapshot();
    if(!current?.enabled||!current.task){stop();failedKey='';showStatus('');return;}
    if(job?.key===current.key||failedKey===current.key)return;
    stop();const task=current.task,active={key:current.key,started:performance.now(),submitted:false};job=active;
    showStatus(task.pendingShot?'The bot is lining up the shot…':'The bot is thinking…');
    function ready(data){
      if(job!==active||active.submitted)return;
      if(data?.type!=='result'||data.engine!==task.engine||!['rack','place','choice','shot','stalemate'].includes(data.action)||!data.payload){fail(active,'The Pool bot could not choose a shot. Retry this turn.');return;}
      clearTimeout(active.timeout);active.worker?.terminate();active.worker=null;
      const earliest=active.started+Math.max(0,task.waitForMotionMs||0)+Math.max(2000,task.presentationDelayMs||0);
      const deliver=async()=>{
        if(job!==active)return;const latest=snapshot();
        if(!latest?.enabled||latest.key!==active.key){stop();return;}
        // Never interrupt the final rolling/pocket animation or a manual replay.
        if(latest.animating){active.clearSince=null;active.delay=setTimeout(deliver,150);return;}
        active.clearSince??=performance.now();
        const wait=Math.max(earliest-performance.now(),700-(performance.now()-active.clearSince));
        if(wait>0){active.delay=setTimeout(deliver,wait);return;}
        active.submitted=true;showStatus(task.pendingShot?'The bot is shooting…':data.action==='shot'?'The bot is preparing the shot…':'The bot is playing…');
        try{
          const ok=await submit({engine:task.engine,positionKey:task.positionKey,action:data.action,payload:data.payload,reason:data.reason,elapsedMs:data.elapsedMs,simulations:data.simulations});
          if(!ok&&snapshot()?.key===active.key){fail(active,'The bot move could not be saved. Retry when the connection is available.');return;}
          if(job===active)showStatus('');sync();
        }catch{fail(active,'The bot move could not be saved. Retry when the connection is available.');}
      };
      active.delay=setTimeout(deliver,Math.max(0,earliest-performance.now()));
    }
    if(task.pendingShot){ready({type:'result',engine:task.engine,action:'shot',payload:task.pendingShot,reason:'announced-shot'});return;}
    try{
      active.worker=new WorkerClass(new URL('./bot-worker.js?v=e691cee6d455',import.meta.url),{type:'module'});
      active.timeout=setTimeout(()=>fail(active,'The Pool bot took too long. Retry this turn.'),task.difficulty==='expert'?35000:14000);
      active.worker.onerror=e=>{e?.preventDefault?.();fail(active,'The Pool bot could not load. Check your connection, then retry.');};
      active.worker.onmessage=({data})=>ready(data);active.worker.postMessage({type:'search',task});
    }catch{fail(active,'The Pool bot could not start. Retry in a current browser.');}
  }
  return {sync,stop};
}
