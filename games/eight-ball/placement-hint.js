// A placement-only caption. Drawing it on the canvas leaves dragging unobstructed.
export function drawPlacementHint(ctx,canvas,p,box,viewWidth){
 if(!p)return;
 const scale=Math.max(.15,canvas.clientWidth/viewWidth),font=13/scale,pad=10/scale,height=28/scale;
 ctx.save();ctx.font=`500 ${font}px system-ui`;ctx.textAlign='center';ctx.textBaseline='middle';
 const text=p.valid?'Double-click to place ball':'Choose a clear spot',width=ctx.measureText(text).width+2*pad;
 const x=Math.max(box.l+8,Math.min(box.r-width-8,p.x-width/2)),gap=40+6/scale;
 const below=p.y+gap,y=below+height<=box.b-8?below:Math.max(box.t+8,p.y-gap-height);
 ctx.shadowColor='#0005';ctx.shadowBlur=8/scale;ctx.fillStyle='#101923ed';ctx.strokeStyle=p.valid?'#9ad9cb99':'#ff8585aa';ctx.lineWidth=1/scale;
 ctx.beginPath();ctx.roundRect(x,y,width,height,7/scale);ctx.fill();ctx.shadowBlur=0;ctx.stroke();
 ctx.fillStyle=p.valid?'#edfff9':'#ffd0d0';ctx.fillText(text,x+width/2,y+height/2);ctx.restore();
}
