import { remainingBotPause } from "./bot-pacing.js?v=31356370ce09";
export function createNestedFourBotController({snapshot,submit,showStatus,WorkerClass=globalThis.Worker}){
  let job=null,failedKey='';
  function stop(){if(job){clearTimeout(job.timeout);clearTimeout(job.delay);job.worker?.terminate();job=null;}}
  function fail(active,message){if(job!==active)return;stop();failedKey=active.key;showStatus(message,()=>{failedKey='';sync();});}
  function sync(){
    const current=snapshot();
    if(!current?.enabled||!current.task){stop();failedKey='';showStatus('');return;}
    if(job?.key===current.key||failedKey===current.key)return;
    stop();const task=current.task,active={key:current.key,worker:null,submitted:false,started:performance.now()};job=active;
    showStatus('The bot is thinking…');
    try{
      const worker=new WorkerClass(new URL('./nested-four-worker.js?v=bfbabaada408',import.meta.url),{type:'module'});active.worker=worker;
      active.timeout=setTimeout(()=>fail(active,'The Nested Four bot took too long. Retry this move.'),Math.max(5000,task.moveTimeMs+3000));
      worker.onerror=e=>{e?.preventDefault?.();fail(active,'The Nested Four bot could not load. Check your connection, then retry.');};
      worker.onmessage=({data})=>{
        if(job!==active||active.submitted)return;
        if(data.engine!==task.engine){fail(active,'The bot was updated. Reload the game to use the current engine.');return;}
        if(data.type!=='result'||!['reserve','board'].includes(data.sourceType)||!Number.isInteger(data.sourceIndex)||!Number.isInteger(data.destination)){
          fail(active,data.error==='no-legal-move'?'The bot has no legal move under these game rules.':'The bot could not evaluate this position. Retry this move.');return;
        }
        active.submitted=true;clearTimeout(active.timeout);worker.terminate();active.worker=null;
        active.delay=setTimeout(async()=>{
          if(job!==active)return;const latest=snapshot();if(!latest?.enabled||latest.key!==active.key){stop();return;}
          showStatus('The bot is moving…');
          try{
            const ok=await submit({engine:task.engine,positionKey:task.positionKey,sourceType:data.sourceType,sourceIndex:data.sourceIndex,destination:data.destination,elapsedMs:data.elapsedMs});
            if(!ok&&snapshot()?.key===active.key){fail(active,'The bot move could not be saved. Retry when the connection is available.');return;}
            // Keep the submitted key until fresh state arrives: polling must not resubmit.
            if(job===active)showStatus('');sync();
          }catch{fail(active,'The bot move could not be saved. Retry when the connection is available.');}
        },remainingBotPause(active.started,task));
      };
      worker.postMessage({type:'search',task});
    }catch{fail(active,'The Nested Four bot could not start. Retry in a current browser.');}
  }
  return {sync,stop};
}
