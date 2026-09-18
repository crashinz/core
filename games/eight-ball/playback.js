import {roll,identity} from './ball-art.js';
// Saved shot frames are the common visual path for live play, reconnect and replay.
// Surface rotation is cosmetic; it never enters collision or scoring calculations.
export function shotFrames(last,radius){
 const raw=atob(last.frames),view=new DataView(Uint8Array.from(raw,c=>c.charCodeAt(0)).buffer),count=last.ballOrder.length;
 const before=new Map(last.before.map(b=>[b.n,b])),frames=[],previous=new Map();
 for(let f=0;f<last.frameCount;f++){
  const frame=[];for(let i=0;i<count;i++){
   const at=(f*count+i)*6,bits=view.getUint16(at+4,true),n=last.ballOrder[i],x=view.getUint16(at,true)/32,y=view.getUint16(at+2,true)/32;
   const old=previous.get(n)||before.get(n),q=old?.orientation||identity();
   const b={n,x,y,pocket:!!(bits&32768),roll:(bits&32767)/1000,orientation:roll(q,x-old.x,y-old.y,radius)};previous.set(n,b);frame.push(b);
  }frames.push(frame);
 }return frames;
}
export function sampleShot(frames,time,radius){
 const q=Math.min(frames.length-1,Math.max(0,time)*30),lo=Math.floor(q),hi=Math.min(frames.length-1,lo+1),f=q-lo;
 return frames[lo].map((a,i)=>{const b=frames[hi][i],dx=(b.x-a.x)*f,dy=(b.y-a.y)*f;return {...a,x:a.x+dx,y:a.y+dy,orientation:roll(a.orientation,dx,dy,radius)};});
}
