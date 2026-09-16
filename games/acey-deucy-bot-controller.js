import { remainingBotPause } from "./bot-pacing.js?v=31356370ce09";
export function createAceyDeucyBotController({snapshot,submit,showStatus,WorkerClass=globalThis.Worker}){
  let job=null,failedKey='';
  function stop(){if(job){clearTimeout(job.timeout);clearTimeout(job.delay);job.worker?.terminate();job=null;}}
  function fail(active,message){if(job!==active)return;stop();failedKey=active.key;showStatus(message,()=>{failedKey='';sync();});}
  function sync(){
    const current=snapshot();
    if(!current?.enabled||!current.task){stop();failedKey='';showStatus('');return;}
    if(job?.key===current.key||failedKey===current.key)return;
    stop();const task=current.task,active={key:current.key,worker:null,submitted:false,started:performance.now()};job=active;
    showStatus(task.action==='roll'?'The bot is ready to roll…':task.action==='choose-double'?'The bot is choosing doubles…':'The bot is thinking…');
    try{
      const worker=task.action==='roll'?{terminate(){},postMessage(){}}:new WorkerClass(new URL('./acey-deucy-worker.js?v=a5d1cb14f714',import.meta.url),{type:'module'});active.worker=worker;
      active.timeout=setTimeout(()=>fail(active,'The Acey Deucy bot took too long. Retry this move.'),Math.max(5000,task.moveTimeMs+3000));
      worker.onerror=e=>{e?.preventDefault?.();fail(active,'The Acey Deucy bot could not load. Check your connection, then retry.');};
      worker.onmessage=({data})=>{
        if(job!==active||active.submitted)return;
        if(data.engine!==task.engine){fail(active,'The bot was updated. Reload the game to use the current engine.');return;}
        if(data.type!=='result'||(task.action==='move'?(!((Number.isInteger(data.from)&&data.from>=0&&data.from<24)||['off','bar'].includes(data.from))||!Number.isInteger(data.die)||data.die<1||data.die>6):(task.action==='choose-double'?(!Number.isInteger(data.value)||data.value<1||data.value>6):false))){
          fail(active,data.error==='no-legal-move'?'The bot has no legal move under these game rules.':'The bot could not evaluate this position. Retry this move.');return;
        }
        active.submitted=true;clearTimeout(active.timeout);worker.terminate();active.worker=null;
        active.delay=setTimeout(async()=>{
          if(job!==active)return;const latest=snapshot();if(!latest?.enabled||latest.key!==active.key){stop();return;}
          showStatus(task.action==='roll'?'The bot is rolling…':'The bot is moving…');
          try{
            const ok=await submit({engine:task.engine,positionKey:task.positionKey,action:task.action,from:data.from,die:data.die,value:data.value,elapsedMs:data.elapsedMs,replyRolls:data.replyRolls});
            if(!ok&&snapshot()?.key===active.key){fail(active,'The bot move could not be saved. Retry when the connection is available.');return;}
            // Keep the submitted key until fresh state arrives: polling must not resubmit.
            if(job===active)showStatus('');sync();
          }catch{fail(active,'The bot move could not be saved. Retry when the connection is available.');}
        },remainingBotPause(active.started,task));
      };
      if(task.action==='roll')worker.onmessage({data:{type:'result',engine:task.engine,elapsedMs:0}});
      else worker.postMessage({type:'search',task});
    }catch{fail(active,'The Acey Deucy bot could not start. Retry in a current browser.');}
  }
  return {sync,stop};
}
