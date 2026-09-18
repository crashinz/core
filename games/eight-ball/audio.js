// Original percussive table sounds. No external media or licensed sound pack.
// Filtered noise and short resonances model impacts, without a sustained tone.
export const impactLevel=speed=>Math.min(1,Math.sqrt(Math.max(0,Number(speed)||0)/1800));
export const soundProfiles={cue:{length:.065,tone:1850,decay:85,body:.12},ball:{length:.055,tone:2750,decay:125,body:.08},rail:{length:.105,tone:380,decay:48,body:.22},pocket:{length:.23,tone:160,decay:20,body:.28}};
export function impactSamples(type,rate=44100){
 const p=soundProfiles[type];if(!p)throw new Error('Unknown pool sound');const out=new Float32Array(Math.ceil(rate*p.length));let seed=19321,low=0;
 for(let i=0;i<out.length;i++){const t=i/rate;seed=(Math.imul(seed,1664525)+1013904223)>>>0;const white=seed/2147483648-1;low+=.24*(white-low);
  const noise=type==='rail'||type==='pocket'?low:white-low;
  const attack=Math.min(1,t/.0007),body=Math.sin(2*Math.PI*p.tone*t)*Math.exp(-t*p.decay*1.8);
  const second=type==='pocket'&&t>.058?.2*Math.sin(2*Math.PI*245*(t-.058))*Math.exp(-(t-.058)*35):0;
  out[i]=attack*(noise*Math.exp(-t*p.decay)*.7+body*p.body+second);
 }return out;
}
export function createPoolAudio(options){
 let context=null,lastAt=-1,burst=0;const buffers=new Map(),voices=new Set();
 async function unlock(){if(!options().enabled)return;try{context??=new(window.AudioContext||window.webkitAudioContext)();if(context.state==='suspended')await context.resume();}catch{}}
 function stop(){for(const source of voices){try{source.stop();}catch{}}voices.clear();}
 function play(type,speed=500){const o=options(),volume=Math.max(0,Math.min(1,(o.volume??100)/100)),strength=impactLevel(speed);if(!o.enabled||!volume||!strength||!soundProfiles[type]||!context||context.state!=='running')return;
  const now=context.currentTime;if(now-lastAt>.025){lastAt=now;burst=0;}if(++burst>6||voices.size>=24)return;
  let buffer=buffers.get(type);if(!buffer){const samples=impactSamples(type,context.sampleRate);buffer=context.createBuffer(1,samples.length,context.sampleRate);buffer.copyToChannel(samples,0);buffers.set(type,buffer);}
  const source=context.createBufferSource(),gain=context.createGain();source.buffer=buffer;gain.gain.value=volume*.77*strength/Math.sqrt(burst);source.connect(gain).connect(context.destination);voices.add(source);source.onended=()=>{voices.delete(source);source.disconnect();gain.disconnect();};source.start();
 }
 return {unlock,play,stop};
}
