import {recordedImpacts} from './recorded-impacts.js?v=8553bed28c71';
// Selected real cue, ball, cushion and pocket recordings.
// Samples are embedded so opening index.html from disk stays fully offline.
const decoded=new Map();
function recordingSamples(type,rate){
 const record=recordedImpacts[type];if(!record)return null;
 let pcm=decoded.get(type);if(!pcm){const bytes=Uint8Array.from(atob(record.pcm),c=>c.charCodeAt(0)),view=new DataView(bytes.buffer);pcm=new Float32Array(bytes.length/2);for(let i=0;i<pcm.length;i++)pcm[i]=view.getInt16(i*2,true)/32768;decoded.set(type,pcm);}
 if(rate===record.rate)return pcm;
 const out=new Float32Array(Math.ceil(pcm.length*rate/record.rate));
 for(let i=0;i<out.length;i++){const pos=i*record.rate/rate,a=Math.min(pcm.length-1,Math.floor(pos)),b=Math.min(pcm.length-1,a+1);out[i]=pcm[a]+(pcm[b]-pcm[a])*(pos-a);}
 return out;
}
export const impactLevel=speed=>Math.min(1,Math.sqrt(Math.max(0,Number(speed)||0)/1800));
export const soundProfiles={cue:['cue'],ball:['ball'],rail:['rail'],pocket:['pocket','pocketAlt']};
export function impactSamples(type,rate=44100){
 const recorded=recordingSamples(type,rate);if(recorded)return recorded;
 throw new Error('Unknown pool recording');
}
export function createPoolAudio(options){
 let context=null,lastAt=-1,burst=0,pocketVariant=0;const buffers=new Map(),voices=new Set();
 async function unlock(){if(!options().enabled)return;try{context??=new(window.AudioContext||window.webkitAudioContext)();if(context.state==='suspended')await context.resume();}catch{}}
 function stop(){for(const source of voices){try{source.stop();}catch{}}voices.clear();}
 function play(type,speed=500){const o=options(),volume=Math.max(0,Math.min(1,(o.volume??100)/100)),strength=impactLevel(speed);if(!o.enabled||!volume||!strength||!soundProfiles[type]||!context||context.state!=='running')return;
  const now=context.currentTime;if(now-lastAt>.025){lastAt=now;burst=0;}if(++burst>6||voices.size>=24)return;
  // Alternate the approved pocket drops, keeping the same impact-volume control.
  const clips=soundProfiles[type],clip=clips[type==='pocket'?pocketVariant++%clips.length:0];
  let buffer=buffers.get(clip);if(!buffer){const samples=impactSamples(clip,context.sampleRate);buffer=context.createBuffer(1,samples.length,context.sampleRate);buffer.copyToChannel(samples,0);buffers.set(clip,buffer);}
  const source=context.createBufferSource(),gain=context.createGain();source.buffer=buffer;gain.gain.value=volume*.77*strength/Math.sqrt(burst);source.connect(gain).connect(context.destination);voices.add(source);source.onended=()=>{voices.delete(source);source.disconnect();gain.disconnect();};source.start();
 }
 return {unlock,play,stop};
}
