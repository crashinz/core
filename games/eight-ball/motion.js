/* 8 Ball physics. Cloth and Han 2005 cushion equations adapted from Pooltool
 * bce1788cddb7650e3e324439d4fe670723e69332 (Evan Kiefl and contributors).
 * Apache-2.0, full license in ../../THIRD_PARTY_NOTICES.md.
 * Changes: scalar JavaScript, planar state, explicit state transitions/guards.
 */
// Rolling loss calibrated separately from launch power using clear, center-hit
// practice flights. Keep EightBallPhysics::UR in sync; slide/spin losses unchanged.
export const defaults={R:15.5,M:.17,g:9.81*12.5/.028575,us:.2,ur:.0105,usp:.028575*4/9,ec:.85,fc:.2};
export function init(b){b.wx??=0;b.wy??=0;b.wz??=0;return b;}
export function phase(b,p=defaults){
  init(b);const ux=b.vx-p.R*b.wy,uy=b.vy+p.R*b.wx,u=Math.hypot(ux,uy),v=Math.hypot(b.vx,b.vy);
  if(u>1e-7)return{type:'slide',time:2*u/(7*p.us*p.g),ax:-p.us*p.g*ux/u,ay:-p.us*p.g*uy/u};
  if(v>1e-7)return{type:'roll',time:v/(p.ur*p.g),ax:-p.ur*p.g*b.vx/v,ay:-p.ur*p.g*b.vy/v};
  return{type:'rest',time:Infinity,ax:0,ay:0};
}
export function advance(b,t,p=defaults){
  init(b);let left=t;
  for(let i=0;left>1e-14&&i<5;i++){
    const f=phase(b,p),dt=Math.min(left,f.time),oldx=b.x,oldy=b.y;
    b.x+=b.vx*dt+.5*f.ax*dt*dt;b.y+=b.vy*dt+.5*f.ay*dt*dt;
    b.vx+=f.ax*dt;b.vy+=f.ay*dt;
    if(f.type==='slide'){b.wx+=2.5/p.R*f.ay*dt;b.wy-=2.5/p.R*f.ax*dt;}
    if(f.type==='roll'||dt>=f.time-1e-14){b.wx=-b.vy/p.R;b.wy=b.vx/p.R;}
    if(f.type==='roll'&&dt>=f.time-1e-14){b.vx=b.vy=b.wx=b.wy=0;}
    const dw=2.5*p.usp*p.g/p.R*dt;b.wz=Math.sign(b.wz)*Math.max(0,Math.abs(b.wz)-dw);
    b.roll=(b.roll||0)+Math.hypot(b.x-oldx,b.y-oldy)/p.R;left-=dt;
  }
}
export function cushion(b,n,p=defaults){
  init(b);const [nx,ny]=n,c=nx,s=ny;
  let vx=c*b.vx+s*b.vy,vy=-s*b.vx+c*b.vy,wx=c*b.wx+s*b.wy,wy=-s*b.wx+c*b.wy,wz=b.wz;
  if(vx<=1e-9)return;
  const sn=.28,cs=Math.sqrt(1-sn*sn),R=p.R,m=p.M;
  const sx=vx*sn+R*wy,sy=-vy-R*wz*cs+R*wx*sn;
  const A=3.5/m,I=.4*m*R*R,pz=(1+p.ec)*vx*cs*m,slip=Math.hypot(sx,sy);
  const factor=slip/A<=p.fc*pz?1/A:p.fc*pz/slip;
  const px=sx*factor,py=sy*factor,PX=-px*sn-pz*cs,PY=py,PZ=px*cs-pz*sn;
  vx+=PX/m;vy+=PY/m;wx+=-R/I*PY*sn;wy+=R/I*(PX*sn-PZ*cs);wz+=R/I*PY*cs;
  b.vx=c*vx-s*vy;b.vy=s*vx+c*vy;b.wx=c*wx-s*wy;b.wy=s*wx+c*wy;b.wz=wz;
}
export function strike(b,angle,speed,spin,p=defaults){
  init(b);const top=-spin.y*.7,side=spin.x*.7;
  // Horizontal impulse on a uniform solid sphere: omega = 5/(2R) v * offset/R.
  // UI limits cue-tip offsets to 70% of radius. No elevated-cue masse/jump model.
  b.vx=Math.cos(angle)*speed;b.vy=Math.sin(angle)*speed;
  b.wx=-Math.sin(angle)*speed*2.5*top/p.R;b.wy=Math.cos(angle)*speed*2.5*top/p.R;b.wz=-speed*2.5*side/p.R;
}
