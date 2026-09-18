// Original preview scheduler/geometry. Collision and motion models are attributed
// in their respective modules. These custom pockets are not a measured real table.
import {collide,resolveCluster} from './collision.js';
import {defaults,phase,advance,cushion,init,strike} from './motion.js';
export {strike};
// Owner-approved mobile-reference power curve. Gentle shots blend into the established curve at 20%; smooth gain anchors strengthen medium/high power. Cue cosmetics
// all share this curve. Keep EightBallPhysics::speed in sync.
export function shotSpeed(power){
 const p=Math.max(5,Math.min(100,Number.isFinite(power)?power:5));
 const base=125+9*p+1055*Math.pow(Math.max(0,p-45)/55,2);
 // Remove the old minimum-speed floor without changing power >=20.
 if(p<20){const t=(p-5)/15;return base-108*(1-t*t*(3-2*t));}
 const knots=[[5,1],[35,1],[50,1.38],[65,1.28],[100,1.25]];
 for(let i=1;i<knots.length;i++){
  const [hi,b]=knots[i],[lo,a]=knots[i-1];
  if(p<=hi){const t=(p-lo)/(hi-lo),smooth=t*t*(3-2*t);return base*(a+(b-a)*smooth);}
 }
 return base*1.25;
}

export const holes=[[82,77],[596,66],[1110,77],[82,598],[596,609],[1110,598]];
// Corner mouths follow the owner-approved Android reference proportions (~1.8 ball diameters).
// Straight cloth bounds, side pockets, pocket centers and capture radius are unchanged.
export const rails=[[122.392,85,569,85],[631,85,1070.608,85],[122.392,590,569,590],[631,590,1070.608,590],[83,125.014,83,549.986],[1110,125.014,1110,549.986],[122.392,85,101.289,64.303],[569,85,575,64],[631,85,625,64],[1070.608,85,1091.711,64.303],[122.392,590,101.289,610.697],[569,590,575,614],[631,590,625,614],[1070.608,590,1091.711,610.697],[83,125.014,63.304,105.697],[83,549.986,63.304,569.303],[1110,125.014,1129.696,105.697],[1110,549.986,1129.696,569.303]];
// Visible cushions are built from the same straight and angled collision segments.
export const railPolygons=[[0,6,7],[1,8,9],[2,10,11],[3,12,13],[4,14,15],[5,16,17]].map(([main,a,b])=>[rails[a].slice(2),rails[main].slice(0,2),rails[main].slice(2),rails[b].slice(2)]);
// Geometric first boundary for the guide; independent of shot power and spin.
export function aimBoundary(b,angle,R=defaults.R){
 const ux=Math.cos(angle),uy=Math.sin(angle);let distance=Infinity,type=null;
 const take=(t,kind)=>{if(t>=-1e-8&&t<distance){distance=Math.max(0,t);type=kind;}};
 const circle=(x,y,r,kind)=>{const dx=x-b.x,dy=y-b.y,proj=dx*ux+dy*uy,side=dx*dx+dy*dy-proj*proj,disc=r*r-side;
  if(disc<0)return;const t=proj-Math.sqrt(Math.max(0,disc));
  if(t>=-1e-8)take(t,kind);else if(kind==='pocket'&&dx*dx+dy*dy<r*r)take(0,kind);
 };
 for(const [x,y]of holes)circle(x,y,19,'pocket');
 for(const [x1,y1,x2,y2]of rails){const dx=x2-x1,dy=y2-y1,len=Math.hypot(dx,dy),tx=dx/len,ty=dy/len,nx=-ty,ny=tx,d=(b.x-x1)*nx+(b.y-y1)*ny,v=ux*nx+uy*ny;
  for(const sign of [-1,1])if(v*sign< -1e-12){const t=(sign*R-d)/v,along=(b.x+ux*t-x1)*tx+(b.y+uy*t-y1)*ty;if(along>=0&&along<=len)take(t,'rail');}
  circle(x1,y1,R,'rail');circle(x2,y2,R,'rail');
 }
 return {distance,type};
}

function val(c,t){let v=0;for(let i=c.length-1;i>=0;i--)v=v*t+c[i];return v;}
// Isolate polynomial roots between derivative extrema. Quartic distance to a
// moving point is valid up to the next cloth transition. No frame-end overlap normal.
export function roots(c,lo,hi){
  while(c.length>1&&Math.abs(c.at(-1))<1e-18)c=c.slice(0,-1);
  if(c.length===1)return[];
  if(c.length===2){const t=-c[0]/c[1];return t>=lo&&t<=hi?[t]:[];}
  const d=c.slice(1).map((v,i)=>v*(i+1)),cuts=[lo,...roots(d,lo,hi),hi],out=[];
  for(let i=0;i<cuts.length-1;i++){
    let a=cuts[i],b=cuts[i+1],fa=val(c,a),fb=val(c,b);
    if(Math.abs(fa)<1e-10)out.push(a);
    if(fa*fb<0){for(let j=0;j<42;j++){const m=(a+b)/2,fm=val(c,m);if(fa*fm<=0){b=m;fb=fm;}else{a=m;fa=fm;}}out.push((a+b)/2);}
  }
  if(Math.abs(val(c,hi))<1e-10)out.push(hi);
  return out.sort((a,b)=>a-b);
}
const at=(b,f,t)=>({x:b.x+b.vx*t+.5*f.ax*t*t,y:b.y+b.vy*t+.5*f.ay*t*t,vx:b.vx+f.ax*t,vy:b.vy+f.ay*t});
function circleTime(a,fa,b,fb,r,dt){
  const x=b.x-a.x,y=b.y-a.y,vx=b.vx-a.vx,vy=b.vy-a.vy,ax=.5*(fb.ax-fa.ax),ay=.5*(fb.ay-fa.ay);
  const gap=Math.hypot(x,y)-r;
  if(gap>Math.hypot(vx,vy)*dt+Math.hypot(ax,ay)*dt*dt+1e-7)return null;
  if(gap<=1e-7&&x*vx+y*vy< -r*.02)return 0;
  for(const t of roots([x*x+y*y-r*r,2*(x*vx+y*vy),vx*vx+vy*vy+2*(x*ax+y*ay),2*(vx*ax+vy*ay),ax*ax+ay*ay],0,dt)){
    if((x+vx*t+ax*t*t)*(vx+2*ax*t)+(y+vy*t+ay*t*t)*(vy+2*ay*t)<-r*.02)return t;
  }return null;
}
const zero={ax:0,ay:0},station=(x,y)=>({x,y,vx:0,vy:0});
export function step(balls,dt,notify=()=>{},p=defaults){
  let left=dt,events=0,lastEvent=null;
  for(const b of balls)if(b.pocket)b.drop=Math.min(1,(b.drop||0)+dt*4);else init(b);
  while(left>1e-12){
    if(++events>160)throw new Error('Physics contact limit; reset this layout. '+JSON.stringify(lastEvent));
    const live=balls.filter(b=>!b.pocket),ph=live.map(b=>phase(b,p));
    let h=Math.min(left,...ph.map(f=>f.time));
    if(h<1e-12){for(let i=0;i<live.length;i++)if(ph[i].time<1e-12)advance(live[i],1e-12,p);h=Math.min(left,1e-9);}
    let event=null;
    const take=(t,e)=>{if(t!==null&&t<=h){h=t;event=e;}};
    for(let i=0;i<live.length;i++){
      const a=live[i],f=ph[i];if(Math.hypot(a.vx,a.vy)<1e-9&&Math.hypot(f.ax,f.ay)<1e-9)continue;
      for(const hole of holes){if(Math.hypot(a.x-hole[0],a.y-hole[1])<18.999){take(0,{type:'pocket',a,hole});break;}
        take(circleTime(a,f,station(...hole),zero,19,h),{type:'pocket',a,hole});}
      for(const s of rails){
        const [x1,y1,x2,y2]=s,dx=x2-x1,dy=y2-y1,len=Math.hypot(dx,dy),tx=dx/len,ty=dy/len,nx=-ty,ny=tx;
        const distance=(a.x-x1)*nx+(a.y-y1)*ny;
        const reach=Math.hypot(a.vx,a.vy)*h+.5*Math.hypot(f.ax,f.ay)*h*h+p.R+1e-6;
        if(Math.abs(distance)<=reach){
          for(const sign of [-1,1])for(const t of roots([distance-sign*p.R,a.vx*nx+a.vy*ny,.5*(f.ax*nx+f.ay*ny)],0,h)){
            const q=at(a,f,t),along=(q.x-x1)*tx+(q.y-y1)*ty;
            if(along>=0&&along<=len&&(q.vx*nx+q.vy*ny)*sign< -1e-7)take(t,{type:'rail',a,n:[-sign*nx,-sign*ny]});
          }
          for(const [x,y]of[[x1,y1],[x2,y2]]){const t=circleTime(a,f,station(x,y),zero,p.R,h);if(t!==null){const q=at(a,f,t),d=Math.hypot(x-q.x,y-q.y);take(t,{type:'rail',a,n:[(x-q.x)/d,(y-q.y)/d]});}}
        }
      }
    }
    for(let i=0;i<live.length;i++)for(let j=i+1;j<live.length;j++)take(circleTime(live[i],ph[i],live[j],ph[j],2*p.R,h),{type:'ball',a:live[i],b:live[j]});
    lastEvent={h,left,type:event?.type,a:event?.a.n,b:event?.b?.n,minPhase:Math.min(...ph.map(f=>f.time))};
    for(const b of live)advance(b,h,p);left-=h;
    if(event){const {a}=event;
      if(event.type==='pocket'){a.pocket=true;a.hole=event.hole;a.drop=0;a.vx=a.vy=a.wx=a.wy=a.wz=0;}
      else if(event.type==='rail'){cushion(a,event.n,p);a.x-=event.n[0]*1e-7;a.y-=event.n[1]*1e-7;}
      else{const b=event.b;
        // Resolve touching groups before separating an isolated pair. Moving
        // only the chosen pair biases simultaneous impacts toward that pair.
        if(!resolveCluster(live,a,b,p)){
          const d=Math.hypot(b.x-a.x,b.y-a.y),nx=(b.x-a.x)/d,ny=(b.y-a.y)/d;
          if(d<2*p.R+.002){const fix=(2*p.R+.002-d)/2;a.x-=nx*fix;a.y-=ny*fix;b.x+=nx*fix;b.y+=ny*fix;}
          const out=collide({r_i:[a.x,a.y,0],r_j:[b.x,b.y,0],v_i:[a.vx,a.vy,0],v_j:[b.vx,b.vy,0],w_i:[a.wx,a.wy,a.wz],w_j:[b.wx,b.wy,b.wz],params:{R:p.R,M:p.M,u_s1:p.us,u_s2:p.us,u_b:.05,e_b:.93}});
          [a.vx,a.vy]=out[0];[a.wx,a.wy,a.wz]=out[1];[b.vx,b.vy]=out[2];[b.wx,b.wy,b.wz]=out[3];}}
      notify(event);
    }
  }
  for(const b of balls)if(![b.x,b.y,b.vx,b.vy,b.wx,b.wy,b.wz].every(Number.isFinite))throw new Error('Non-finite physics state');
}
export const moving=balls=>balls.some(b=>!b.pocket&&(Math.hypot(b.vx,b.vy)>1e-6||phase(b).type==='slide'));
export function idle(balls,dt){for(const b of balls)if(b.pocket)b.drop=Math.min(1,(b.drop||0)+dt*4);else if(phase(b).type==='rest')advance(b,dt);}
export function predict(balls,angle,speed,spin){
  const copy=balls.map(b=>({...b})),cue=copy.find(b=>b.n===0);if(!cue)return null;
  strike(cue,angle,speed,spin);let hit=null;
  for(let i=0;i<1440&&!hit&&moving(copy);i++)step(copy,1/120,e=>{
    if(hit||e.a.n!==0&&e.b?.n!==0)return;
    const other=e.type==='ball'?(e.a.n===0?e.b:e.a):null;
    hit={x:cue.x,y:cue.y,type:e.type,target:other?{x:other.x,y:other.y,vx:other.vx,vy:other.vy}:null};
  });
  return hit||{x:cue.x,y:cue.y,type:'stop',target:null};
}
