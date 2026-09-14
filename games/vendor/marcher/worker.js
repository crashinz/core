/* Marcher runs off the UI thread. The PHP reducer owns every accepted move. */
let engine;
const version='corechat-2';
const engineId='marcher-1fa785ed-corechat-2';
onmessage=async ({data:job})=>{
  try {
    if(job.type==='boot'){
      importScripts('./context.js?v='+version,'./presets.js?v='+version);
      let variant='marcher';
      try {
        importScripts('./marcher.js?v='+version);
        engine=await createMarcher({locateFile:p=>'./'+p+'?v='+version});
      } catch {
        variant='marcher-scalar';
        importScripts('./marcher-scalar.js?v='+version);
        engine=await createMarcher({locateFile:p=>'./'+p+'?v='+version});
      }
      postMessage({type:'ready',variant,engine:engineId});return;
    }
    const level=MarcherLevels[job.difficulty];
    if(!engine||!Object.hasOwn(MarcherLevels,job.difficulty))throw Error('Unknown Checkers difficulty');
    const budget=Number(job.moveTimeMs);
    if(!Number.isFinite(budget)||budget<20||budget>level.moveTimeMs)throw Error('Invalid thinking budget');
    const started=performance.now(),{a,forced}=MarcherContext.prepare(engine,job.position);
    if(engine._wasm_search(...a,budget/1000,level.depth,forced)<0)throw Error('Search unavailable');
    const p=engine._wasm_result_ptr()>>2,r=Array.from(engine.HEAP32.subarray(p,p+12));
    postMessage({type:'result',engine:engineId,from:[r[0]>>3,r[0]&7],to:[r[1]>>3,r[1]&7],elapsedMs:Math.round(performance.now()-started)});
  } catch(error) { postMessage({type:'error',message:String(error)}); }
};
