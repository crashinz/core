export function createCheckersBotController({snapshot,submit,showStatus,WorkerClass=globalThis.Worker}) {
  let job=null,failedKey='';
  function stop(){if(job){clearTimeout(job.timeout);job.worker?.terminate();job=null;}}
  function fail(active,message){
    if(job!==active)return;
    stop();failedKey=active.key;
    showStatus(message,()=>{failedKey='';sync();});
  }
  function sync(){
    const current=snapshot();
    if(!current?.enabled||!current.task){stop();failedKey='';showStatus('');return;}
    if(job?.key===current.key||failedKey===current.key)return;
    stop();
    const active={key:current.key,worker:null,timeout:null,submitted:false,searching:false};job=active;
    const task=current.task;
    async function finish(move){
      if(job!==active||active.submitted)return;
      const latest=snapshot();if(!latest?.enabled||latest.key!==active.key){stop();return;}
      active.submitted=true;clearTimeout(active.timeout);active.worker?.terminate();active.worker=null;
      showStatus('The bot is moving…');
      try{
        const ok=await submit({positionKey:task.positionKey,engine:task.engine,...move});
        if(!ok&&snapshot()?.key===active.key){fail(active,'The bot move could not be saved. Retry when the connection is available.');return;}
        if(job===active){stop();showStatus('');}sync();
      }catch{fail(active,'The bot move could not be saved. Retry when the connection is available.');}
    }
    if(task.respondToDraw){void finish({elapsedMs:0});return;}
    showStatus('Loading the checkers bot…');
    try{
      const worker=new WorkerClass(new URL('./vendor/marcher/worker.js?v=corechat-2',import.meta.url));active.worker=worker;
      active.timeout=setTimeout(()=>fail(active,'The checkers bot could not load. Check your connection, then retry.'),15000);
      worker.onerror=e=>{e?.preventDefault?.();fail(active,'The checkers engine could not run. Retry or use a current browser with WebAssembly support.');};
      worker.onmessage=({data})=>{
        if(job!==active)return;
        if((data.type==='ready'||data.type==='result')&&data.engine!==task.engine){
          fail(active,'The checkers bot was updated. Reload the game to use the current engine.');return;
        }
        if(data.type==='ready'&&!active.searching){
          active.searching=true;clearTimeout(active.timeout);showStatus('The bot is thinking…');
          active.timeout=setTimeout(()=>fail(active,'The checkers engine took too long. Retry this move.'),Math.max(3000,task.moveTimeMs+2000));
          worker.postMessage({type:'search',difficulty:task.difficulty,moveTimeMs:task.moveTimeMs,position:task.position});
        }else if(data.type==='result'&&active.searching){
          if(![data.from,data.to].every(s=>Array.isArray(s)&&s.length===2&&s.every(n=>Number.isInteger(n)&&n>=0&&n<8))){fail(active,'The checkers engine returned no usable move. Retry this position.');return;}
          void finish({from:data.from,to:data.to,elapsedMs:data.elapsedMs});
        }else if(data.type==='error')fail(active,active.searching
          ? 'The checkers bot could not evaluate this position. Retry; older saved games may require a new round.'
          : 'The checkers bot could not load. Check your connection, then retry.');
      };
      worker.postMessage({type:'boot'});
    }catch{fail(active,'The checkers engine could not start. Retry or use a current browser with WebAssembly support.');}
  }
  return {sync,stop};
}
