/* GNU Backgammon evaluation stays in a disposable worker. */
const engineId='gnubg-95d0ffc-corechat-1';let engine;
onmessage=async({data})=>{try{
 if(data.type==='boot'){
  importScripts('./rules.js?v=1','./search.js?v=fbfa3a846d01','./gnubg.js?v=1');
  engine=await createGnuBG({locateFile:p=>'./'+p+'?v=1',print:()=>{},printErr:()=>{}});
  if(engine._core_init()!==1)throw Error('Engine initialization failed');
  postMessage({type:'ready',engine:engineId});return;
 }
 if(data.type!=='search'||!engine||data.task.engine!==engineId)throw Error('Invalid search');
 postMessage({type:'result',engine:engineId,...BackgammonSearch.search(engine,data.task)});
}catch(e){postMessage({type:'error',message:String(e)});}};
