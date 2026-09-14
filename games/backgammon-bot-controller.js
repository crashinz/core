export function createBackgammonBotController({snapshot,submit,showStatus,WorkerClass=globalThis.Worker}){
 let job=null,failedKey='';
 function stop(){if(job){clearTimeout(job.timeout);job.worker?.terminate();job=null;}}
 function fail(active,message){if(job!==active)return;stop();failedKey=active.key;showStatus(message,()=>{failedKey='';sync();});}
 function sync(){
  const current=snapshot();if(!current?.enabled||!current.task){stop();if(!current?.task)failedKey='';showStatus('');return;}
  if(job?.key===current.key||failedKey===current.key)return;stop();
  const active={key:current.key,worker:null,timeout:null,submitted:false,searching:false};job=active;const task=current.task;
  async function finish(move){
   if(job!==active||active.submitted)return;const latest=snapshot();if(!latest?.enabled||latest.key!==active.key){stop();return;}
   active.submitted=true;clearTimeout(active.timeout);active.worker?.terminate();active.worker=null;showStatus(task.action==='roll'?'The bot is rolling…':'The bot is moving…');
   try{const ok=await submit({positionKey:task.positionKey,engine:task.engine,action:task.action,...move});if(!ok&&snapshot()?.key===active.key){fail(active,'The bot action could not be saved. Retry when the connection is available.');return;}if(job===active){stop();showStatus('');}sync();}catch{fail(active,'The bot action could not be saved. Retry when the connection is available.');}
  }
  if(task.action==='roll'){showStatus('The bot is ready to roll…');active.timeout=setTimeout(()=>void finish({elapsedMs:0}),500);return;}
  showStatus('Loading the backgammon bot…');
  try{
   const worker=new WorkerClass(new URL('./vendor/gnubg/worker.js?v=1',import.meta.url));active.worker=worker;
   active.timeout=setTimeout(()=>fail(active,'The backgammon bot could not load. Check your connection, then retry.'),15000);
   worker.onerror=e=>{e?.preventDefault?.();fail(active,'The backgammon engine could not run. Retry with a current browser.');};
   worker.onmessage=({data})=>{
    if(job!==active)return;
    if((data.type==='ready'||data.type==='result')&&data.engine!==task.engine){fail(active,'The backgammon bot was updated. Reload the game.');return;}
    if(data.type==='ready'&&!active.searching){active.searching=true;clearTimeout(active.timeout);showStatus('The bot is thinking…');active.timeout=setTimeout(()=>fail(active,'The backgammon engine took too long. Retry this position.'),4000);worker.postMessage({type:'search',task});}
    else if(data.type==='result'&&active.searching){if(!((Number.isInteger(data.from)&&data.from>=0&&data.from<24)||data.from==='bar')||!Number.isInteger(data.die)||data.die<1||data.die>6){fail(active,'The backgammon engine returned no usable move. Retry.');return;}void finish({from:data.from,die:data.die,elapsedMs:data.elapsedMs,replyRolls:data.replyRolls});}
    else if(data.type==='error')fail(active,'The backgammon bot could not evaluate this position. Retry.');
   };worker.postMessage({type:'boot'});
  }catch{fail(active,'The backgammon engine could not start. Retry with a current browser.');}
 }
 return {sync,stop};
}
