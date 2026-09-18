// Original visual sphere rendering. These rotations never affect shot physics.
export const identity = () => [0, 0, 0, 1];
export function multiply(a, b) {
  return [a[3]*b[0]+a[0]*b[3]+a[1]*b[2]-a[2]*b[1], a[3]*b[1]-a[0]*b[2]+a[1]*b[3]+a[2]*b[0], a[3]*b[2]+a[0]*b[1]-a[1]*b[0]+a[2]*b[3], a[3]*b[3]-a[0]*b[0]-a[1]*b[1]-a[2]*b[2]];
}
export function roll(q, dx, dy, radius) {
  const distance=Math.hypot(dx,dy); if(distance<1e-9)return q;
  const a=distance/(2*radius),s=Math.sin(a)/distance;
  const next=multiply([-dy*s,dx*s,0,Math.cos(a)],q),length=Math.hypot(...next);
  return next.map(v=>v/length);
}
export function inverseMatrix([x,y,z,w]) {
  return [1-2*(y*y+z*z),2*(x*y+z*w),2*(x*z-y*w),2*(x*y-z*w),1-2*(x*x+z*z),2*(y*z+x*w),2*(x*z+y*w),2*(y*z-x*w),1-2*(x*x+y*y)];
}

export function createRollingBallArt(colors, {fixedOrientation=false}={}) {
  // Supersample the surface before the table scales it down. A larger number
  // cap keeps single and double digits readable without enlarging the ball.
  const size=128,patchSize=128,capRadius=.60,states=new Map(),patches=new Map(),points=[];
  const rgb=colors.map(c=>[1,3,5].map(i=>parseInt(c.slice(i,i+2),16)));
  for(let y=0;y<size;y++)for(let x=0;x<size;x++){
    const u=(x+.5-size/2)/(size/2),v=(y+.5-size/2)/(size/2),d=u*u+v*v;
    if(d<1)points.push([(y*size+x)*4,u,v,Math.sqrt(1-d),Math.min(1,(1-Math.sqrt(d))*size/2)*255]);
  }
  function patch(n){
    if(patches.has(n))return patches.get(n);
    const c=document.createElement('canvas');c.width=c.height=patchSize;const g=c.getContext('2d');
    g.fillStyle='#fffdf4';g.fillRect(0,0,patchSize,patchSize);g.fillStyle='#101318';
    g.font='bold 88px Arial';g.textAlign='center';g.textBaseline='alphabetic';
    const metrics=g.measureText(String(n));
    g.fillText(n,patchSize/2,(patchSize+metrics.actualBoundingBoxAscent-metrics.actualBoundingBoxDescent)/2);
    const data=g.getImageData(0,0,patchSize,patchSize).data;patches.set(n,data);return data;
  }
  function paint(b,r,now){
    let st=states.get(b.n);
    if(!st){const canvas=document.createElement('canvas');canvas.width=canvas.height=size;
      st={canvas,context:canvas.getContext('2d'),image:null,x:b.x,y:b.y,q:identity(),painted:null};
      st.image=st.context.createImageData(size,size);states.set(b.n,st);
    }
    const dx=b.x-st.x,dy=b.y-st.y;
    // Stopped balls keep their surface orientation; elapsed time cannot turn them.
    if(!fixedOrientation&&Array.isArray(b.orientation)&&b.orientation.length===4&&b.orientation.every(Number.isFinite))st.q=[...b.orientation];
    else if(!fixedOrientation&&Math.hypot(dx,dy)>1e-5)st.q=roll(st.q,dx,dy,r);
    st.x=b.x;st.y=b.y;
    if(st.painted&&st.painted.every((v,i)=>Math.abs(v-st.q[i])<1e-7))return st.canvas;
    st.painted=[...st.q];const m=inverseMatrix(st.q),data=st.image.data,label=patch(b.n),cap=Math.sqrt(1-capRadius*capRadius);
    for(const [i,x,y,z,alpha] of points){
      const lx=m[0]*x+m[1]*y+m[2]*z,ly=m[3]*x+m[4]*y+m[5]*z,lz=m[6]*x+m[7]*y+m[8]*z;
      let color=b.n>8&&Math.abs(ly)>.62?[242,237,220]:rgb[b.n];
      if(Math.abs(lz)>cap){const u=lz<0?-lx:lx,px=Math.max(0,Math.min(patchSize-1,Math.floor((u/capRadius+1)*patchSize/2))),py=Math.max(0,Math.min(patchSize-1,Math.floor((ly/capRadius+1)*patchSize/2))),at=(py*patchSize+px)*4;color=[label[at],label[at+1],label[at+2]];}
      data[i]=color[0];data[i+1]=color[1];data[i+2]=color[2];data[i+3]=alpha;
    }
    st.context.putImageData(st.image,0,0);return st.canvas;
  }
  return {draw(context,b,r,now){context.drawImage(paint(b,r,now),-r,-r,2*r,2*r);},reset(){states.clear();}};
}
