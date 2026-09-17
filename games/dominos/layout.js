/* Deterministic presentation only. Rules address arms, never screen coordinates. */
(function () {
  'use strict';
  function rawLayout(s) {
    const out=[],ends={};
    if(!s.root)return {out,ends:{start:{x:0,y:0,w:32,h:64}}};
    const root={tile:s.root,x:0,y:0,w:s.spinner?32:64,h:s.spinner?64:32,spinner:s.spinner};out.push(root);
    // Opposite arms share the same path rotated 180 degrees. Each pair owns
    // separate quadrants; elbows join the side of the preceding outer half.
    for(const side of ['west','east','north','south']) {
      const vertical=side==='north'||side==='south',flip=side==='east'||side==='south'?-1:1;
      let x=vertical?0:-root.w/2,y=vertical?-root.h/2:0,dx=vertical?0:-1,dy=vertical?-1:0;
      let pendingTurn=vertical?'first':null;
      const branch=s.branches?.[side]||[];
      const reach=640;
      function place(n,placeholder=false) {
        const double=!placeholder&&n.near===n.far,len=double?32:64,cross=double?64:32;
        const q={tile:n.tile,x:x+dx*len/2,y:y+dy*len/2,w:dx?len:cross,h:dy?len:cross,side,near:n.near,far:n.far,reverse:(dx||dy)*flip<0};
        x+=dx*len;y+=dy*len;
        // Turn only at a non-double. Its far half has an unambiguous side.
        if(!double) {
          let nx=dx,ny=dy;
          if(pendingTurn==='first'){nx=1;ny=0;pendingTurn=null;}
          else if(dy){nx=vertical?(x>150?-1:1):(x< -150?1:-1);ny=0;}
          else if((dx<0 && x-64<(vertical?64:-reach)) || (dx>0 && x+64>(vertical?reach:-96))){nx=0;ny=-1;}
          if(nx!==dx||ny!==dy){x-=dx*16;y-=dy*16;x+=nx*cross/2;y+=ny*cross/2;dx=nx;dy=ny;}
        }
        q.x*=flip;q.y*=flip;return q;
      }
      for(const n of branch)out.push(place(n));
      if(!vertical||(s.spinner&&s.branches.west.length&&s.branches.east.length))ends[side]=place({},true);
    }
    return {out,ends};
  }
  function layout(s) {
    const g=rawLayout(s),all=[...g.out,...Object.values(g.ends)];let minX=-70,maxX=70,minY=-45,maxY=45;
    for(const q of all){minX=Math.min(minX,q.x-q.w/2);maxX=Math.max(maxX,q.x+q.w/2);minY=Math.min(minY,q.y-q.h/2);maxY=Math.max(maxY,q.y+q.h/2);}
    const scale=Math.min(1.2,780/(maxX-minX),270/(maxY-minY)),cx=(minX+maxX)/2,cy=(minY+maxY)/2;
    for(const q of all){q.x=550+(q.x-cx)*scale;q.y=320+(q.y-cy)*scale;q.w*=scale;q.h*=scale;}
    return g;
  }
  if(typeof module!=='undefined')module.exports={layout,rawLayout};else window.DominosLayout=layout;
})();


