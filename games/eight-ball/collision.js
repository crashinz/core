/*
 * 8 Ball collision model.
 * Mathavan collision kernel adapted from Pooltool by Evan Kiefl and contributors,
 * commit bce1788cddb7650e3e324439d4fe670723e69332, Apache License 2.0.
 * Original: pooltool/physics/resolve/ball_ball/frictional_mathavan/__init__.py
 * License: ../../THIRD_PARTY_NOTICES.md. Modifications: JavaScript scalar port,
 * finite/planar input checks, non-approach early return and bounded iteration.
 */
const dot = (a,b) => a[0]*b[0]+a[1]*b[1]+a[2]*b[2];
const magnitude = (x,y) => Math.sqrt(x*x+y*y);
// Original compliant group-contact approximation. Isolated contacts retain the
// attributed Mathavan kernel below. Virtual deformation resolves simultaneous
// normal forces; spin/tangential friction remains unchanged in this group model.
export function resolveCluster(live,a,b,p) {
  const group=[a,b];
  for(let k=0;k<group.length;k++)for(const c of live)
    if(!group.includes(c)&&Math.hypot(c.x-group[k].x,c.y-group[k].y)<2*p.R+.02)group.push(c);
  if(group.length<3)return false;
  let energy=0,mx=0,my=0;
  for(const g of group){energy+=g.vx*g.vx+g.vy*g.vy;mx+=g.vx;my+=g.vy;}
  const copy=group.map(b=>({...b})),tau=.0003,dt=tau/80,me=p.M/2,ln=Math.log(.93),z=-ln/Math.sqrt(Math.PI**2+ln**2),k=me*(Math.PI/tau)**2/(1-z*z),c=2*z*Math.sqrt(k*me);
  let step=0;
  for(;step<1600;step++){
    const forces=copy.map(()=>[0,0]);let pending=false;
    for(let i=0;i<copy.length;i++)for(let j=i+1;j<copy.length;j++){
      const u=copy[i],v=copy[j],dx=v.x-u.x,dy=v.y-u.y,d=Math.hypot(dx,dy),nx=dx/d,ny=dy/d,rel=(v.vx-u.vx)*nx+(v.vy-u.vy)*ny,gap=2*p.R-d;
      // Root rounding must not add/remove an entire compliant integration step.
      // Treat numerical contact as contact, including its compressive damping.
      const over=Math.abs(gap)<1e-8?0:gap;
      if(over>1e-8||(rel<-.02&&over<=0&&-over / -rel<tau))pending=true;
      if(over<0)continue;
      const f=Math.max(0,k*over-c*rel);forces[i][0]-=nx*f;forces[i][1]-=ny*f;forces[j][0]+=nx*f;forces[j][1]+=ny*f;
    }
    if(step>0&&!pending)break;
    for(let i=0;i<copy.length;i++){const u=copy[i];u.vx+=forces[i][0]*dt/p.M;u.vy+=forces[i][1]*dt/p.M;u.x+=u.vx*dt;u.y+=u.vy*dt;}
  }
  if(step===1600)throw new Error('Pool group contact did not settle.');
  const cx=mx/group.length,cy=my/group.length,before=Math.max(0,energy-group.length*(cx*cx+cy*cy)),after=copy.reduce((s,u)=>s+(u.vx-cx)**2+(u.vy-cy)**2,0),scale=after>before&&after>0?Math.sqrt(before/after):1;
  for(let i=0;i<copy.length;i++){group[i].vx=cx+(copy[i].vx-cx)*scale;group[i].vy=cy+(copy[i].vy-cy)*scale;}
  // The compliant solve returns velocities at virtually displaced centers,
  // while the event scheduler retains the real contact positions. Remove any
  // residual inward velocity at those real contacts before resuming time.
  // Equal, opposite normal impulses preserve momentum and cannot add energy.
  for(let pass=0;pass<80;pass++){
    let closing=false;
    for(let i=0;i<group.length;i++)for(let j=i+1;j<group.length;j++){
      const u=group[i],v=group[j],dx=v.x-u.x,dy=v.y-u.y,d=Math.hypot(dx,dy);
      if(d>2*p.R+1e-7||d<1e-12)continue;
      const nx=dx/d,ny=dy/d,rel=(v.vx-u.vx)*nx+(v.vy-u.vy)*ny;
      if(rel>=-1e-8)continue;closing=true;
      const impulse=-rel/2;u.vx-=nx*impulse;u.vy-=ny*impulse;v.vx+=nx*impulse;v.vy+=ny*impulse;
    }
    if(!closing)break;
  }
  return true;
}
export function collide(c) {
  const {r_i:ri,r_j:rj,v_i:vi,v_j:vj,w_i:wi,w_j:wj} = c;
  const {R,M,u_s1=.21,u_s2=.21,u_b=.05,e_b=.89,N=1000} = c.params;
  for (const a of [ri,rj,vi,vj,wi,wj]) {
    if (!Array.isArray(a)||a.length!==3||!a.every(Number.isFinite)) throw new Error('Finite 3-vectors required');
  }
  if (![R,M,u_s1,u_s2,u_b,e_b,N].every(Number.isFinite)||R<=0||M<=0||u_s1<0||u_s2<0||u_b<0||e_b<0||e_b>1||!Number.isInteger(N)||N<10||N>10000) throw new Error('Invalid collision parameters');
  if (Math.abs(ri[2]-rj[2])>1e-10||vi[2]!==0||vj[2]!==0) throw new Error('Planar contact required');
  const d=rj.map((x,i)=>x-ri[i]), len=Math.sqrt(dot(d,d));
  if (len<1e-12) throw new Error('Coincident ball centers');
  const y=d.map(x=>x/len), x=[y[1],-y[0],0];
  let vix=dot(vi,x), viy=dot(vi,y), vjx=dot(vj,x), vjy=dot(vj,y);
  let wix=dot(wi,x), wiy=dot(wi,y), wiz=wi[2], wjx=dot(wj,x), wjy=dot(wj,y), wjz=wj[2];
  let rel=vjy-viy;
  if (rel>=-1e-12) return [vi.slice(),wi.slice(),vj.slice(),wj.slice()];
  const dp=.5*(1+e_b)*M*Math.abs(rel)/N, C=5/(2*M*R);
  let ix=vix+R*wiy, iy=viy-R*wix, jx=vjx+R*wjy, jy=vjy-R*wjx;
  let im=magnitude(ix,iy), jm=magnitude(jx,jy);
  let cx=vix-vjx-R*(wiz+wjz), cz=R*(wix+wjx), cm=magnitude(cx,cz);
  let work=0, compression=null, finalWork=Infinity, iterations=0;
  while (rel<0||work<finalWork) {
    if (++iterations>20*N) throw new Error('Collision iteration limit');
    let p1=0,p2=0,pix=0,piy=0,pjx=0,pjy=0;
    if (cm>=1e-16) {
      p1=-u_b*dp*cx/cm;
      if (Math.abs(cz)>=1e-16) {
        p2=-u_b*dp*cz/cm;
        if (p2>0) {
          if (jm!==0) {pjx=-u_s2*(jx/jm)*p2;pjy=-u_s2*(jy/jm)*p2;}
        } else if (im!==0) {pix=u_s1*(ix/im)*p2;piy=u_s1*(iy/im)*p2;}
      }
    }
    vix+=(p1+pix)/M; viy+=(-dp+piy)/M;
    vjx+=(-p1+pjx)/M; vjy+=(dp+pjy)/M;
    wix+=C*(p2+piy); wiy+=C*(-pix); wiz+=C*(-p1);
    wjx+=C*(p2+pjy); wjy+=C*(-pjx); wjz+=C*(-p1);
    ix=vix+R*wiy; iy=viy-R*wix; jx=vjx+R*wjy; jy=vjy-R*wjx;
    im=magnitude(ix,iy); jm=magnitude(jx,jy);
    cx=vix-vjx-R*(wiz+wjz); cz=R*(wix+wjx); cm=magnitude(cx,cz);
    const previous=rel; rel=vjy-viy;
    work+=.5*dp*Math.abs(previous+rel);
    if (compression===null&&rel>0) {compression=work;finalWork=(1+e_b**2)*compression;}
  }
  const global=(a,b,z)=>[x[0]*a+y[0]*b,x[1]*a+y[1]*b,z];
  const out=[global(vix,viy,0),global(wix,wiy,wiz),global(vjx,vjy,0),global(wjx,wjy,wjz)];
  if (!out.flat().every(Number.isFinite)) throw new Error('Non-finite collision output');
  return out;
}

// Independently implemented constant-velocity disc contact timing. Full cloth
// acceleration, cushions, pockets and simultaneous rack contacts are not handled.
export function contactTime(a,b,diameter,horizon=Infinity) {
  const dx=b.x-a.x,dy=b.y-a.y,vx=b.vx-a.vx,vy=b.vy-a.vy;
  const A=vx*vx+vy*vy,B=dx*vx+dy*vy,C=dx*dx+dy*dy-diameter*diameter;
  if (A===0||B>=0) return null;
  if (C<=0) return 0;
  const disc=B*B-A*C;
  if (disc<=0) return null; // A tangent does not exchange normal impulse.
  const t=C/(-B+Math.sqrt(disc)); // Stable smaller quadratic root.
  return t<=horizon?t:null;
}

export function elasticContact(a,b) {
  const dx=b.x-a.x,dy=b.y-a.y,len=Math.hypot(dx,dy),nx=dx/len,ny=dy/len;
  const normal=(a.vx-b.vx)*nx+(a.vy-b.vy)*ny;
  if (normal<=0) return;
  a.vx-=normal*nx;a.vy-=normal*ny;b.vx+=normal*nx;b.vy+=normal*ny;
}
