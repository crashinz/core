import {drawPlacementHint} from './placement-hint.js';
'use strict';
import {createRollingBallArt,roll} from './ball-art.js';
import {shotFrames,sampleShot} from './playback.js';
import {createPoolAudio} from './audio.js?v=0905101bc65c';
import {installPractice} from './practice.js';
import {holes as pockets,railPolygons,aimBoundary} from './table.js';
(() => {
const $=s=>document.querySelector(s), canvas=$('#table'), ctx=canvas.getContext('2d');
let R=15.5,practice=null;
const W=1200,H=680,box={l:83,r:1110,t:85,b:590};
// Extra drawing and pointer space around the unchanged physical table.
const cueSpace={left:440,right:440,top:240,bottom:200},viewW=W+cueSpace.left+cueSpace.right,viewH=H+cueSpace.top+cueSpace.bottom;
const colors=['#edead9','#eeb31e','#2852a6','#ce313b','#73459c','#e96d22','#248e64','#963339','#111920','#eeb31e','#2852a6','#ce313b','#73459c','#e96d22','#248e64','#963339'];
const cues=[
 {name:'Midnight brass',base:'#172635',light:'#59738a',inlay:'#d8b66c',wrap:'#141c26',wood:'#e8c995',design:0},
 {name:'Heritage maple',base:'#865229',light:'#ce9a54',inlay:'#f3dda6',wrap:'#432c22',wood:'#e9cc98',design:1},
 {name:'Ivory pearl',base:'#d4d5cb',light:'#ffffff',inlay:'#4da9ab',wrap:'#4e6067',wood:'#eee1c0',design:2},
 {name:'Royal sapphire',base:'#172e65',light:'#457ed2',inlay:'#becbdf',wrap:'#1a2344',wood:'#e5c796',design:3},
 {name:'Crimson rosewood',base:'#561d2b',light:'#b45753',inlay:'#e1b675',wrap:'#311d25',wood:'#ebce9e',design:4},
 {name:'Emerald crown',base:'#123f38',light:'#459d7c',inlay:'#dcbe75',wrap:'#15302b',wood:'#e5d2a8',design:5},
 {name:'Crimson eclipse',base:'#090b10',light:'#383e49',inlay:'#f22c43',wrap:'#090a0e',wood:'#171b23',design:6}
];
// Personal rendering choices; never included in shared game actions or state.
const tableFinishes={
 walnut:{colors:['#4b3530','#775044','#51352e','#211d1c','#52382b','#704b38','#281e1b'],texture:'wood',trim:'#e3bf7c55'},
 rosewood:{colors:['#552430','#9b4951','#622e38','#25151c','#652836','#9b414a','#321821'],texture:'wood',trim:'#f1be8955'},
 maple:{colors:['#bd945e','#f0d6a0','#c39962','#634b32','#b78b56','#e6c58b','#765636'],texture:'wood',trim:'#fff0bc66'},
 carbon:{colors:['#454a51','#666e78','#353b43','#10151c','#333b46','#66727e','#151c25'],texture:'carbon',trim:'#c7d8ee77'},
 steel:{colors:['#a4b0bd','#e7edf4','#8e9ba9','#465260','#93a3b5','#dae7f1','#657280'],texture:'brushed',trim:'#e6f2ff99'},
 midnight:{colors:['#142e58','#466799','#203f6e','#0b152d','#193459','#3c6097','#0d1b36'],texture:'gloss',trim:'#a7c9ff77'}
};
const clothSchemes={teal:['#26838a','#14606c','#0c424e'],blue:['#34659c','#234574','#152e53'],green:['#357e5b','#226247','#143f34'],red:['#a83e4b','#7b2536','#4b1625']};
const appearanceKey='corechat.pool.appearance.v1';
function saveAppearance(){try{localStorage.setItem(appearanceKey,JSON.stringify({cloth,frame:tableFinish}));}catch{}}
function loadAppearance(){try{const p=JSON.parse(localStorage.getItem(appearanceKey)||'{}');if(Object.hasOwn(clothSchemes,p.cloth))cloth=p.cloth;if(Object.hasOwn(tableFinishes,p.frame))tableFinish=p.frame;}catch{}$('#cloth').value=cloth;$('#tableFinish').value=tableFinish;}
const rollingBallArt=createRollingBallArt(colors),trayBallArt=createRollingBallArt(colors,{fixedOrientation:true});
let balls=[],returned=[],angle=-2.73,power=45,cueIndex=6,activePlayer=0,playerCues=[6,6],moving=false,shot=1,guide=true,sound=false,cloth='red',tableFinish='carbon',spin={x:0,y:0},keys=new Set(),gesture=null,hold=null,activePanel=null,opener=null,drag=null,last=0,accumulator=0,elapsed=0,layout='practice',placement=null,hoverPoint=null;
let network={status:'loading',state:{turnOrder:[]},currentUserId:0,busy:true},playback=null,networkVersion='',playedShot='',sending=false,pendingShots=[];
let turnGuide={key:'',startedAt:0,ids:[]};
let alwaysHighlightTargets=false;
const isNine=()=>network?.state?.settings?.variant==='nine-ball';
const lowestBall=st=>Math.min(...(st?.balls||[]).filter(b=>b.n>0&&!b.pocket).map(b=>b.n));
const canAct=()=>network&&network.status==='active'&&!network.busy&&!sending&&!playback&&Number(network.state.turnOrder?.[network.state.turnIndex])===network.currentUserId&&!network.state.completed;
const postAction=(action,payload={})=>{if(!network||sending||network.busy||playback)return;sending=true;parent.postMessage({type:'pool-action',action,payload},location.origin);updateUI();};
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const point=e=>{const r=canvas.getBoundingClientRect();return{x:(e.clientX-r.left)*viewW/r.width-cueSpace.left,y:(e.clientY-r.top)*viewH/r.height-cueSpace.top};};
const cueBall=()=>balls.find(b=>b.n===0&&!b.pocket);
function roundRect(x,y,w,h,r,fill,stroke){ctx.beginPath();ctx.roundRect(x,y,w,h,r);if(fill){ctx.fillStyle=fill;ctx.fill();}if(stroke){ctx.strokeStyle=stroke;ctx.stroke();}}
function circle(x,y,r,fill){ctx.beginPath();ctx.arc(x,y,r,0,Math.PI*2);ctx.fillStyle=fill;ctx.fill();}
let seed=417;function rnd(){seed=(Math.imul(seed,1664525)+1013904223)>>>0;return seed/4294967296;}
const grain=document.createElement('canvas');grain.width=grain.height=160;const gc=grain.getContext('2d'),pixels=gc.createImageData(160,160);for(let i=0;i<pixels.data.length;i+=4){const v=rnd()>.5?255:0;pixels.data[i]=pixels.data[i+1]=pixels.data[i+2]=v;pixels.data[i+3]=Math.floor(rnd()*15);}gc.putImageData(pixels,0,0);
const carbonWeave=document.createElement('canvas');carbonWeave.width=carbonWeave.height=12;const wc=carbonWeave.getContext('2d');wc.fillStyle='#03080c45';wc.fillRect(0,0,6,6);wc.fillRect(6,6,6,6);wc.strokeStyle='#c5d3e81c';wc.lineWidth=1;for(let i=1;i<6;i+=2){wc.beginPath();wc.moveTo(i,0);wc.lineTo(i,6);wc.moveTo(6,6+i);wc.lineTo(12,6+i);wc.stroke();}
function makeBall(n,x,y){return {n,x,y,vx:0,vy:0,roll:0,wx:0,wy:0,wz:0,pocket:false,drop:0};}
function status(s){$('#status').textContent=s;}
function reset(mode=layout){rollingBallArt.reset();layout=mode;placement=null;moving=false;updateCursor();elapsed=0;accumulator=0;keys.clear();gesture=null;hold=null;shot=1;returned=[];spin={x:0,y:0};updateSpin();
 if(mode==='break'){balls=[makeBall(0,330,337)];let n=1;for(let row=0;row<5;row++)for(let k=0;k<=row;k++){let id=n++;if(id===5)id=8;else if(id===8)id=5;balls.push(makeBall(id,798+row*(2*R+.004)*Math.sqrt(3)/2,337+(k-row/2)*(2*R+.004)));}angle=0;power=80;}
 else if(mode==='pocket'){balls=[makeBall(0,590,335),makeBall(3,596,155),makeBall(8,860,400)];angle=-Math.PI/2+.0333;power=38;}
 else if(['center','follow','draw','left','right'].includes(mode)){balls=['left','right'].includes(mode)?[makeBall(0,820,337)]:[makeBall(0,450,337),makeBall(1,550,337)];angle=0;power=['left','right'].includes(mode)?55:65;spin={x:mode==='left'?-1:mode==='right'?1:0,y:mode==='follow'?-1:mode==='draw'?1:0};updateSpin();}
 else {balls=[makeBall(0,420,317),makeBall(1,980,139),makeBall(2,290,252),makeBall(3,408,448),makeBall(4,887,478),makeBall(5,812,541),makeBall(6,704,211),makeBall(7,235,425),makeBall(8,898,326),makeBall(9,243,505),makeBall(10,750,151),makeBall(11,733,433),makeBall(12,572,384),makeBall(13,957,471),makeBall(14,982,242),makeBall(15,490,158)];angle=Math.atan2(252-317,290-420);power=45;}
 status(mode==='break'?'Break layout ready. Aim, set power, and shoot.':mode==='pocket'?'Pocket practice. Aim at the red 3 and try the top middle pocket.':'Your shot. Drag near the cue or aiming line, or use the arrow keys.');updateUI();updateBallLists();draw();}
function updateBallLists(){const st=network?.state,own=st?.turnOrder?.includes(network?.currentUserId)?network.currentUserId:st?.turnOrder?.[0],other=st?.turnOrder?.find(id=>id!==own);const list=id=>isNine()?[1,2,3,4,5,6,7,8,9]:st?.groups?.[id]==='stripes'?[9,10,11,12,13,14,15]:st?.groups?.[id]==='solids'?[1,2,3,4,5,6,7]:[];for(const [sel,ids] of [['#solids',list(own)],['#stripes',list(other)]]){$(sel).setAttribute('aria-label',isNine()?'Remaining numbered balls':sel==='#solids'?'Your remaining balls':'Opponent remaining balls');$(sel).innerHTML=ids.map(n=>`<span class="mini-ball ${n>8?'striped':''} ${balls.find(b=>b.n===n&&!b.pocket)?'':'potted'}" style="--ball:${colors[n]}" title="${n}"><span>${n}</span></span>`).join('');}}
function updateUI(){$('#power').value=Math.round(power);$('#powerValue').textContent=Math.round(power)+'%';$('#angleValue').textContent=((angle*180/Math.PI+360)%360).toFixed(1)+'°';$('#shotCount').textContent='SHOT '+String(shot).padStart(2,'0');$('#shoot').hidden=!!placement;$('#shoot').disabled=moving||!!placement;$('#placeBall').hidden=!placement;$('#placeBall').disabled=!placement||!placement.valid;$('#power').disabled=moving||!!placement;$('#previewPlayer').disabled=moving;$('#spinTarget').disabled=moving;$('#resetSpin').disabled=moving;document.querySelectorAll('[data-adjust]').forEach(b=>b.disabled=moving||!!placement);if(network){const disabled=!canAct()||!!practice?.editing;$('#shoot').disabled=disabled||!!placement||network.state.phase!=='aim'||power<=0||!callReady();$('#placeBall').disabled=disabled||!placement?.valid;$('#power').disabled=disabled||!!placement;$('#spinTarget').disabled=disabled;$('#resetSpin').disabled=disabled;document.querySelectorAll('[data-adjust]').forEach(b=>b.disabled=disabled||!!placement);}}
const tableAudio=createPoolAudio(()=>({enabled:sound&&network?.options?.effectsEnabled!==false,volume:network?.options?.masterVolume??100}));
window.addEventListener('pointerdown',()=>void tableAudio.unlock(),true);
window.addEventListener('keydown',()=>void tableAudio.unlock(),true);
function noiseHit(speed=100,type='ball'){tableAudio.play(type,speed);}

const pocketNames=['Top left','Top middle','Top right','Bottom left','Bottom middle','Bottom right'];
function callValues(){const safety=$('#safetyShot')?.checked||false;return {calledBall:safety?0:Number($('#calledBall')?.value||0),calledPocket:safety?-1:($('#calledPocket')?.value===''?-1:Number($('#calledPocket')?.value??-1)),safety};}
function callNeeded(){const st=network?.state;if(st?.settings?.variant==='nine-ball'||!st||st.settings?.tableMode!=='match'||st.phase!=='aim'||st.breakShot||$('#safetyShot')?.checked)return false;const mode=st.settings.pocketCalls||'all',actor=st.turnOrder?.[st.turnIndex],group=st.groups?.[actor];return mode==='all'||mode==='eight'&&(Number($('#calledBall')?.value)===8||!!group&&!st.balls.some(b=>!b.pocket&&b.n!==0&&b.n!==8&&(group==='solids'?b.n<8:b.n>8)));}
function callReady(){if(!callNeeded())return true;const v=callValues(),c=network.state.calledShot;return v.calledBall>0&&v.calledPocket>=0&&!!c&&c.actor===network.currentUserId&&c.calledBall===v.calledBall&&c.calledPocket===v.calledPocket&&!c.safety;}
function shareCall(){if(!canAct())return;postAction('call',callValues());}
function pocketHit(p){if(!p||!canAct()||!callNeeded()||gesture||placement)return -1;return pockets.findIndex(h=>Math.hypot(p.x-h[0],p.y-h[1])<=34);}
function selectPocket(n){const select=$('#calledPocket');if(!select||select.disabled||!canAct())return;select.value=String(n);shareCall();updateUI();}
function drawPocketCall(){
 const st=network?.state,c=playback?.last?.calledShot||st?.calledShot,selected=c&&!c.safety?c.calledPocket:-1;
 const choosing=!moving&&canAct()&&callNeeded();if(selected<0&&!choosing)return;
 // A completed shot's marker lasts through its playback, then clears.
 if(!playback&&(st?.phase!=='aim'||st?.breakShot))return;
 ctx.save();pockets.forEach((h,i)=>{if(i!==selected&&!choosing)return;ctx.beginPath();ctx.arc(h[0],h[1],i===selected?29:27,0,Math.PI*2);ctx.strokeStyle=i===selected?'#ff354d':'#ffffffa0';ctx.lineWidth=i===selected?5:2;ctx.shadowColor=i===selected?'#ff203a':'#000';ctx.shadowBlur=i===selected?12:3;ctx.stroke();});ctx.restore();
}
function shoot(){if(practice?.editing)return;if(network){if(!canAct()||network.state.phase!=='aim'||power<=0||placement||gesture?.type==='pull'||!callReady())return;postAction('shot',{angle,power,spinX:spin.x,spinY:spin.y,...callValues()});return;}if(moving||activePanel||placement||gesture?.type==='pull'||!cueBall())return;const b=cueBall();window.PoolPhysics.strike(b,angle,window.PoolPhysics.shotSpeed(power),spin);moving=true;elapsed=0;gesture=null;keys.clear();hold=null;status('Shot in motion…');noiseHit(600,'cue');updateUI();}
function step(dt){if(network){playNetwork(dt);return;}elapsed+=dt;
 try{window.PoolPhysics.step(balls,dt,e=>{if(e.type==='pocket'&&e.a.n)returned.push(e.a.n);noiseHit(e.speed||500,e.type);});}
 catch(error){balls.forEach(b=>{b.vx=b.vy=b.wx=b.wy=b.wz=0;});moving=false;status('Physics preview stopped: '+error.message);updateUI();return;}
 if(!window.PoolPhysics.moving(balls)){moving=false;shot++;const white=balls.find(b=>b.n===0);
 if(white?.pocket){let x=330,y=337;for(let i=0;i<100&&balls.some(b=>!b.pocket&&Math.hypot(b.x-x,b.y-y)<2*R+2);i++){x=160+(i%10)*60;y=190+Math.floor(i/10)*30;}Object.assign(white,makeBall(0,x,y));status('Cue ball pocketed. Replaced for this free-shooting preview.');}
 else status('Ready. Spin is active; use Options to repeat a comparison shot.');updateBallLists();updateUI();}
}
function tableBackground(){ctx.clearRect(-cueSpace.left,-cueSpace.top,viewW,viewH);
 ctx.save();ctx.shadowColor='#000b';ctx.shadowBlur=30;ctx.shadowOffsetY=17;roundRect(36,31,1120,617,40,'#080e16');ctx.restore();
 const frame=ctx.createLinearGradient(0,30,0,649);frame.addColorStop(0,'#69778a');frame.addColorStop(.025,'#141e2b');frame.addColorStop(.09,'#364352');frame.addColorStop(.5,'#182332');frame.addColorStop(.95,'#0b111a');frame.addColorStop(1,'#5b6573');roundRect(39,34,1114,609,37,frame,'#aebbd047');
 const finish=tableFinishes[tableFinish],wood=ctx.createLinearGradient(0,44,0,630);[0,.05,.08,.45,.9,.97,1].forEach((stop,i)=>wood.addColorStop(stop,finish.colors[i]));roundRect(48,43,1096,591,31,wood,finish.trim);
 ctx.save();ctx.beginPath();ctx.roundRect(48,43,1096,591,31);ctx.clip();ctx.lineWidth=1;
 if(finish.texture==='wood'){ctx.strokeStyle='#dfb97c16';for(let i=0;i<10;i++){ctx.beginPath();ctx.moveTo(93,48+i*2);ctx.bezierCurveTo(450,52+i*1.6,830,43+i*3,1100,48+i*2);ctx.stroke();ctx.beginPath();ctx.moveTo(85,612+i*1.5);ctx.bezierCurveTo(350,610+i*1.5,850,619+i,1103,612+i*1.5);ctx.stroke();}}
 else if(finish.texture==='carbon'){ctx.fillStyle=ctx.createPattern(carbonWeave,'repeat');ctx.fillRect(48,43,1096,591);}
 else if(finish.texture==='brushed'){ctx.strokeStyle='#eff7ff12';for(let y=44;y<634;y+=3){ctx.beginPath();ctx.moveTo(48,y);ctx.lineTo(1144,y);ctx.stroke();}}
 else {ctx.strokeStyle='#b7d7ff40';ctx.lineWidth=2;ctx.beginPath();ctx.moveTo(88,50);ctx.lineTo(1106,50);ctx.stroke();ctx.strokeStyle='#ffffff10';ctx.lineWidth=5;ctx.beginPath();ctx.moveTo(63,106);ctx.lineTo(63,574);ctx.stroke();}ctx.restore();
 const s=clothSchemes[cloth],felt=ctx.createRadialGradient(550,315,30,590,339,640);felt.addColorStop(0,s[0]);felt.addColorStop(.65,s[1]);felt.addColorStop(1,s[2]);roundRect(76,72,1040,532,13,felt);
 ctx.save();ctx.beginPath();ctx.roundRect(77,73,1038,530,12);ctx.clip();ctx.fillStyle=ctx.createPattern(grain,'repeat');ctx.fillRect(77,73,1038,530);ctx.restore();
 const rail=ctx.createLinearGradient(0,68,0,90);rail.addColorStop(0,'#081e26');rail.addColorStop(.55,s[1]);rail.addColorStop(1,s[0]);ctx.lineWidth=1;
 // Geometry-driven artwork keeps the visible cushion edges on the collision boundary.
 for(const [i,polygon]of railPolygons.entries()){ctx.beginPath();polygon.forEach(([x,y],j)=>j?ctx.lineTo(x,y):ctx.moveTo(x,y));ctx.closePath();
  if(i<4)ctx.fillStyle=i<2?rail:s[1];else{const x=i===4?63:1110,g=ctx.createLinearGradient(x,0,x+20,0);g.addColorStop(0,'#0b2731');g.addColorStop(.65,s[1]);g.addColorStop(1,s[0]);ctx.fillStyle=g;}
  ctx.fill();ctx.strokeStyle='#8bcecc30';ctx.stroke();
 }
 for(const [x,y]of pockets){ctx.save();ctx.shadowBlur=8;ctx.shadowColor='#000';circle(x,y,28,'#7a5b40');const pg=ctx.createRadialGradient(x,y-9,0,x,y,27);pg.addColorStop(0,'#17232a');pg.addColorStop(.5,'#05070a');pg.addColorStop(1,'#010103');circle(x,y,25.5,pg);ctx.restore();ctx.beginPath();ctx.arc(x,y,26.5,Math.PI,Math.PI*2);ctx.strokeStyle='#d6b78155';ctx.stroke();}
 for(const y of[54,624])for(const x of[216,342,469,724,850,976]){ctx.save();ctx.translate(x,y);ctx.rotate(Math.PI/4);roundRect(-2.5,-2.5,5,5,1,'#d5c29c');ctx.restore();}for(const x of[60,1131])for(const y of[202,337,472])circle(x,y,2.7,'#d2bb97');
 ctx.strokeStyle='#d1dada20';ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(335,88);ctx.lineTo(335,588);ctx.stroke();circle(335,337,2,'#d5e7df44');circle(854,337,2,'#d5e7df44');
 // A small polished return track sits inside the right frame, away from play.
 roundRect(1166,165,23,361,10,'#07111c','#58718466');ctx.strokeStyle='#87949b44';ctx.beginPath();ctx.moveTo(1171,181);ctx.lineTo(1171,510);ctx.stroke();returned.slice(-12).forEach((n,i)=>ballDraw({n,x:1177.5,y:509-i*27,roll:0},10,true));
}
function ballDraw(b,r=R,inTray=false){let x=b.x,y=b.y,scale=1;if(b.pocket){if(b.drop>=1)return;scale=1-b.drop;x+=(b.hole[0]-x)*b.drop;y+=(b.hole[1]-y)*b.drop;}
 ctx.save();ctx.translate(x,y);ctx.scale(scale,scale);ctx.shadowColor='#0009';ctx.shadowBlur=5;ctx.shadowOffsetX=2;ctx.shadowOffsetY=4;circle(0,0,r,b.n>8?'#f2eddc':colors[b.n]);ctx.shadowColor='transparent';if(b.n)(inTray?trayBallArt:rollingBallArt).draw(ctx,b,r,performance.now());
 let light=ctx.createRadialGradient(-r*.35,-r*.45,0,0,0,r);light.addColorStop(0,'#ffffff8a');light.addColorStop(.3,'#ffffff0a');light.addColorStop(.62,'#00000000');light.addColorStop(.91,'#00000066');light.addColorStop(1,'#000000aa');circle(0,0,r,light);ctx.save();ctx.translate(-r*.48,-r*.55);ctx.scale(1,.55);circle(0,0,r*.16,'#ffffffe0');ctx.restore();ctx.beginPath();ctx.arc(0,0,r-.5,0,Math.PI*2);ctx.strokeStyle='#ffffff33';ctx.lineWidth=.7;ctx.stroke();ctx.restore();}
function cueDraw(c,x,y,a,length=360,width=9,context=ctx){const g=context;g.save();g.translate(x,y);g.rotate(a);g.shadowColor='#0009';g.shadowBlur=4;g.shadowOffsetY=3;
 const taper=(from,to,w1,w2,fill)=>{g.beginPath();g.moveTo(from,-w1/2);g.lineTo(to,-w2/2);g.lineTo(to,w2/2);g.lineTo(from,w1/2);g.closePath();g.fillStyle=fill;g.fill();};
 taper(0,length,width*.3,width,c.base);g.shadowColor='transparent';const shaft=g.createLinearGradient(0,-width/2,0,width/2);shaft.addColorStop(0,c.design===6?'#050609':'#83694c');shaft.addColorStop(.35,c.wood);shaft.addColorStop(.55,c.design===6?'#727887':'#fff0c8');shaft.addColorStop(1,c.design===6?'#080a10':'#987551');taper(3,length*.56,width*.3,width*.66,shaft);
 // Original full-shaft inlay: polished colored panels, metal rings and tapered points.
 g.save();g.beginPath();g.moveTo(10,-width*.17);g.lineTo(length*.55,-width*.33);g.lineTo(length*.55,width*.33);g.lineTo(10,width*.17);g.closePath();g.clip();
 const lacquer=g.createLinearGradient(0,-width*.35,0,width*.35);lacquer.addColorStop(0,c.base);lacquer.addColorStop(.3,c.light);lacquer.addColorStop(.5,c.inlay);lacquer.addColorStop(.68,c.base);lacquer.addColorStop(1,'#101825');
 g.fillStyle=lacquer;g.fillRect(length*.075,-width*.4,length*.48,width*.8);
 for(let k=0;k<7;k++){const at=length*(.09+k*.067),w=width*(.19+k*.016);g.fillStyle=c.inlay;g.fillRect(at,-width*.4,length*.005,width*.8);
 g.beginPath();g.moveTo(at+length*.008,-w);g.lineTo(at+length*.055,0);g.lineTo(at+length*.008,w);g.lineTo(at+length*.021,0);g.closePath();g.fillStyle=k%2?c.wood:c.light;g.fill();
 if(c.design%2){g.strokeStyle=c.inlay;g.lineWidth=.65;g.stroke();}}
 g.restore();
 const butt=g.createLinearGradient(0,-width/2,0,width/2);butt.addColorStop(0,c.base);butt.addColorStop(.3,c.light);butt.addColorStop(.5,c.base);butt.addColorStop(1,'#0c111b');taper(length*.565,length,width*.66,width,butt);
 const band=(at,w,col)=>{g.fillStyle=col;g.fillRect(at,-width*(.3+at/length*.7)/2,w,width*(.3+at/length*.7));};band(0,3,'#57a5ac');band(4,5,'#f3e8cc');band(length*.56,4,'#d4d5cf');band(length*.59,2,c.inlay);band(length*.945,3,c.inlay);band(length*.987,5,'#11171e');
 // Crimson eclipse: full-length black lacquer, scarlet blade inlays and dark metal rings.
 if(c.design===6){g.fillStyle=c.inlay;for(const [from,to,w]of[[.61,.745,.30],[.80,.91,.36]]){g.beginPath();g.moveTo(length*from,-width*w);g.lineTo(length*to,0);g.lineTo(length*from,width*w);g.lineTo(length*(from+.025),0);g.closePath();g.fill();}}
 taper(length*.76,length*.93,width*.83,width*.95,c.wrap);for(let k=0;k<30;k++){const px=length*(.765+k*.0054);g.strokeStyle='#aeb3bb26';g.lineWidth=.7;g.beginPath();g.moveTo(px,-width*.44);g.lineTo(px+2,width*.44);g.stroke();}
 if(c.design===6){g.strokeStyle='#d521398c';g.lineWidth=.85;for(let k=0;k<9;k++){const px=length*(.778+k*.016);g.beginPath();g.moveTo(px,-width*.36);g.lineTo(px+length*.012,width*.36);g.stroke();}}
 g.fillStyle=c.inlay;for(let k=0;k<3;k++){const px=length*(.625+k*.041);g.beginPath();g.moveTo(px,-width*.29);g.lineTo(px+length*.025,0);g.lineTo(px,width*.29);g.lineTo(px+length*.009,0);g.closePath();g.fill();}g.strokeStyle='#ffffff44';g.lineWidth=.6;g.beginPath();g.moveTo(length*.57,-width*.25);g.lineTo(length*.75,-width*.32);g.stroke();g.restore();}
// The aiming guide shows first contact, not the stopping distance of a powered shot.
function aimContact(b,angle,balls){const ux=Math.cos(angle),uy=Math.sin(angle);
 let {distance}=aimBoundary(b,angle,R),target=null;
 for(const o of balls){if(o.n===0||o.pocket)continue;const dx=o.x-b.x,dy=o.y-b.y,proj=dx*ux+dy*uy,side=Math.max(0,dx*dx+dy*dy-proj*proj);
  if(proj>0&&side<4*R*R){const t=Math.max(0,proj-Math.sqrt(4*R*R-side));if(t<distance){distance=t;target=o;}}
 }
 return {x:b.x+ux*distance,y:b.y+uy*distance,distance,target};
}
// Advisory first-contact check, matching eight_ball_resolve_shot. A neutral
// marker does not certify the rest of the shot (scratch, cushion, called pocket).
function illegalAimTarget(st,target,calledBall=0){
 if(!target||!st||st.phase!=='aim'||st.completed||st.settings?.tableMode!=='match')return false;
 if(st.settings?.variant==='nine-ball')return !st.pushOutDeclared&&target.n!==lowestBall(st);
 if(st.breakShot)return false;
 const actor=st.turnOrder?.[st.turnIndex],group=st.groups?.[actor]??null;
 const ballGroup=n=>n>=1&&n<=7?'solids':n>=9&&n<=15?'stripes':null;
 const remaining=g=>(st.balls||[]).some(b=>!b.pocket&&ballGroup(b.n)===g);
 const onEight=group!==null?!remaining(group):(st.settings?.pocketCalls==='none'||Number(calledBall)===8)&&(!remaining('solids')||!remaining('stripes'));
 return group===null?target.n===8&&!onEight:onEight?target.n!==8:ballGroup(target.n)!==group;
}
// Equal-mass contact geometry: target takes the normal component; cue retains
// the tangent component. This is a short direction guide, not a spin/stop prediction.
function aimDirections(angle,x,y,target){
 const ux=Math.cos(angle),uy=Math.sin(angle),d=Math.hypot(target.x-x,target.y-y);
 if(d<1e-9)return null;
 const nx=(target.x-x)/d,ny=(target.y-y)/d,normal=Math.max(0,Math.min(1,ux*nx+uy*ny)),length=R*5.44;
 // Android reference: the object-ball stub shortens markedly on thin cuts.
 // The squared normal fraction is a visual length response, not travel distance.
 return {target:{x:nx*length*normal*normal,y:ny*length*normal*normal},cue:{x:(ux-nx*normal)*length,y:(uy-ny*normal)*length}};
}
function aimDraw(){const b=cueBall();if(!b||moving||placement)return;const ux=Math.cos(angle),uy=Math.sin(angle);
 if(guide){const {x,y,distance,target}=aimContact(b,angle,balls);ctx.save();ctx.strokeStyle='#07161bc0';ctx.lineWidth=5;
 if(distance>R+6.5){ctx.beginPath();ctx.moveTo(b.x+ux*(R+6.5),b.y+uy*(R+6.5));ctx.lineTo(x,y);ctx.stroke();ctx.strokeStyle='#f4f2dccc';ctx.lineWidth=1.6;ctx.stroke();}
 const illegal=illegalAimTarget(network?.state,target,Number($('#calledBall')?.value||0));
 ctx.strokeStyle=illegal?'#ff555b':'#f4f2dccc';ctx.lineWidth=illegal?3:1.6;ctx.beginPath();ctx.arc(x,y,R,0,Math.PI*2);ctx.stroke();
 if(illegal){const d=R/Math.SQRT2;ctx.beginPath();ctx.moveTo(x-d,y+d);ctx.lineTo(x+d,y-d);ctx.stroke();}
 else if(target){const dirs=aimDirections(angle,x,y,target);if(dirs){ctx.lineCap='round';ctx.beginPath();ctx.moveTo(target.x,target.y);ctx.lineTo(target.x+dirs.target.x,target.y+dirs.target.y);ctx.stroke();
 // A head-on hit has no geometric sideways departure. Avoid a misleading stub.
 if(Math.hypot(dirs.cue.x,dirs.cue.y)>R+2){ctx.beginPath();ctx.moveTo(x,y);ctx.lineTo(x+dirs.cue.x,y+dirs.cue.y);ctx.stroke();}}}ctx.restore();}
 const {gap,length}=cueGeometry(b);
 cueDraw(cues[cueIndex],b.x-ux*gap,b.y-uy*gap,angle+Math.PI,length,13);
}

function placementValid(p){return p.x>=box.l+R&&p.x<=box.r-R&&p.y>=box.t+R&&p.y<=box.b-R&&(!placement||placement.mode!=='break'||p.x<=335)&&!pockets.some(h=>Math.hypot(p.x-h[0],p.y-h[1])<R+25)&&!balls.some(b=>b.n!==0&&!b.pocket&&Math.hypot(p.x-b.x,p.y-b.y)<2*R+1);}
function placementHit(p){return placement&&Math.hypot(p.x-placement.x,p.y-placement.y)<=Math.max(R+9,12*viewW/canvas.getBoundingClientRect().width);}
function placementMessage(){status(placement.valid?(placement.mode==='break'?'Before the break: place the cue ball in the highlighted starting area.':'After a foul: place the cue ball anywhere clear on the cloth.')+' Drag to move; double-click, Enter or Place ball confirms.':'That position is not available. Choose clear cloth inside the highlighted area.');}
function movePlacement(p){if(!placement)return;placement.x=clamp(p.x,box.l+R,box.r-R);placement.y=clamp(p.y,box.t+R,box.b-R);placement.valid=placementValid(placement);placementMessage();updateUI();}
function cancelPlacementDrag(){if(!placement)return;if(placement.dragStart)movePlacement(placement.dragStart);placement.pointer=null;placement.dragStart=null;placement.dragOffset=null;}
function beginPlacement(mode){if(network){if(!canAct()||network.state.settings?.tableMode!=='solo')return;const b=balls.find(b=>b.n===0);placement={mode,x:b?.x||330,y:b?.y||337,valid:false,pointer:null};placement.valid=placementValid(placement);updateUI();closePanel();return;}reset(mode==='break'?'break':'practice');closePanel();const b=cueBall();placement={mode,x:b.x,y:b.y,valid:true,pointer:null,dragStart:null};updateCursor();canvas.focus({preventScroll:true});movePlacement(placement);}
function confirmPlacement(){if(network){if(canAct()&&placement?.valid)postAction('place',{x:placement.x,y:placement.y});return;}if(!placement||!placement.valid)return;const b=cueBall();b.x=placement.x;b.y=placement.y;placement=null;updateCursor();status('Cue ball placed. Aim and set power for your shot.');updateUI();canvas.focus({preventScroll:true});}
function placementArea(){if(!placement)return;ctx.save();ctx.fillStyle='#8ce9e91b';const right=placement.mode==='break'?335:box.r-R;ctx.fillRect(box.l+R,box.t+R,right-box.l-R,box.b-box.t-2*R);ctx.strokeStyle='#9ce9deaa';ctx.lineWidth=2;ctx.setLineDash([8,7]);ctx.strokeRect(box.l+R,box.t+R,right-box.l-R,box.b-box.t-2*R);ctx.setLineDash([]);ctx.fillStyle='#dcfff1';ctx.font='600 13px system-ui';ctx.textAlign='left';ctx.fillText(placement.mode==='break'?'STARTING AREA':'BALL IN HAND',box.l+R+14,box.t+R+25);ctx.restore();}
function placementBall(){if(!placement)return;const p=placement;ctx.save();ctx.shadowColor=p.valid?'#86f3de':'#ff6666';ctx.shadowBlur=18;ctx.strokeStyle=p.valid?'#a4ffed':'#ff8585';ctx.lineWidth=2;ctx.beginPath();ctx.arc(p.x,p.y,R+9,0,Math.PI*2);ctx.stroke();ctx.shadowBlur=0;ballDraw({n:0,x:p.x,y:p.y,roll:0});ctx.strokeStyle=p.valid?'#ddfff6':'#ff8585';for(const [dx,dy]of[[1,0],[-1,0],[0,1],[0,-1]]){const x=p.x+dx*32,y=p.y+dy*32;ctx.beginPath();ctx.moveTo(x-dx*6-dy*4,y-dy*6+dx*4);ctx.lineTo(x,y);ctx.lineTo(x-dx*6+dy*4,y-dy*6-dx*4);ctx.stroke();}ctx.restore();}
$('#physicsExample').addEventListener('change',e=>{if(network){postAction(e.target.value==='break'?'rack':'layout',{layout:e.target.value});closePanel();return;}reset(e.target.value);closePanel();canvas.focus({preventScroll:true});});
$('#placeBeforeBreak').addEventListener('click',()=>beginPlacement('break'));
$('#placeAfterFoul').addEventListener('click',()=>beginPlacement('foul'));
$('#placeBall').addEventListener('click',confirmPlacement);
canvas.addEventListener('dblclick',e=>{if(e.button!==0||!placement||moving||activePanel)return;if(Number.isFinite(e.clientX)&&Number.isFinite(e.clientY)&&!onCloth(point(e)))return;e.preventDefault();confirmPlacement();});

// One reminder per playable shot, after playback/placement. Polls and cue changes
// must not restart it. These are legal targets, not guaranteed clear shot paths.
function updateTurnGuide(){
 const st=network?.state;
 if(!canAct()||moving||placement||st?.phase!=='aim'||st.breakShot)return;
 const key=JSON.stringify([network.sessionId,network.currentUserId,st.shotNumber||0,st.layoutVersion||0,st.practiceVersion||0,st.settings?.variant,st.pushOutDeclared]);
 if(turnGuide.key===key)return;
 const available=(st.balls||[]).filter(b=>!b.pocket&&b.n!==0),group=st.groups?.[network.currentUserId];
 const own=available.filter(b=>group==='solids'?b.n>=1&&b.n<=7:group==='stripes'?b.n>=9&&b.n<=15:b.n!==8);
 let targets=own;
 if(st.settings?.variant==='nine-ball')targets=st.pushOutDeclared?[]:available.filter(b=>b.n===lowestBall(st));
 else if(st.settings?.tableMode==='solo')targets=available;
 else if(group&&!own.length)targets=available.filter(b=>b.n===8);
 else if(!group&&[available.some(b=>b.n>=1&&b.n<=7),available.some(b=>b.n>=9&&b.n<=15)].includes(false))targets=available;
 turnGuide={key,startedAt:performance.now(),ids:targets.map(b=>b.n)};
}
function drawTurnGuide(){
 const age=(performance.now()-turnGuide.startedAt)/1000;
 if(!canAct()||moving||placement||network.state.phase!=='aim'||network.state.breakShot||age<0||(!alwaysHighlightTargets&&age>=3))return;
 ctx.save();ctx.globalAlpha=alwaysHighlightTargets?1:Math.min(1,age/.12,(3-age)/.6);
 for(const b of balls){if(b.pocket||!turnGuide.ids.includes(b.n))continue;
  ctx.beginPath();ctx.arc(b.x,b.y,R+3.5,0,Math.PI*2);ctx.strokeStyle='#102330b3';ctx.lineWidth=4.5;ctx.stroke();ctx.strokeStyle='#fff6d9';ctx.lineWidth=2;ctx.stroke();
 }ctx.restore();
}
function draw(){tableBackground();if(practice?.editing){for(const b of practice.balls)ballDraw(makeBall(b.n,b.x,b.y),15.5);const b=practice.balls.find(b=>b.n===practice.selected);if(b){ctx.strokeStyle='#f4d478';ctx.lineWidth=2;ctx.beginPath();ctx.arc(b.x,b.y,19,0,Math.PI*2);ctx.stroke();}return;}placementArea();balls.filter(b=>b.n!==0).forEach(b=>ballDraw(b));const white=balls.find(b=>b.n===0);if(white&&!placement)ballDraw(white);drawTurnGuide();placementBall();drawPlacementHint(ctx,canvas,placement,box,viewW);aimDraw();drawPocketCall();}
const aimStep=Math.PI/3600; // 0.05 degrees per tap.
let aimKeyStarted=0;
function adjust(action,amount=1){if(network&&!canAct())return;if(practice?.editing||moving||placement||(gesture?.type==='pull'&&action.startsWith('aim-')))return;const previousAngle=angle;
 if(action==='aim-left')angle-=aimStep*amount;if(action==='aim-right')angle+=aimStep*amount;
 if(action==='power-up')power=clamp(power+amount,5,100);if(action==='power-down'){power=clamp(power-amount,0,100);if(power<5)power=0;}
 if(gesture){const change=angle-previousAngle;
  if(gesture.type==='pull'){
   if(action.startsWith('power-'))gesture.pull=power/.6;
   gesture.shotAngle=angle;gesture.start=gesture.lastPoint||gesture.start;gesture.basePull=gesture.pull;
  }else if(gesture.type==='cue-aim')gesture.angleOffset+=change;
  else gesture.keyboardOffset=(gesture.keyboardOffset||0)+change;
 }
 updateUI();}

function loop(now){const dt=Math.min(.035,(now-last)/1000||.016);last=now;if(!activePanel&&!moving&&!placement&&document.activeElement===canvas){const fine=keys.has('Shift')?.25:1;if(now-aimKeyStarted>=300){if(keys.has('ArrowLeft'))adjust('aim-left',dt*88*fine);if(keys.has('ArrowRight'))adjust('aim-right',dt*88*fine);}if(keys.has('ArrowUp'))adjust('power-up',dt*28);if(keys.has('ArrowDown'))adjust('power-down',dt*28);if(keys.size)updateUI();}if(hold&&!moving&&now-hold.start>300){adjust(hold.action,dt*(hold.action.startsWith('aim-')?88:22));}if(moving){accumulator+=dt;while(accumulator>=1/240&&moving){step(1/240);accumulator-=1/240;}}else window.PoolPhysics.idle(balls,dt);updateCursor();draw();requestAnimationFrame(loop);}
canvas.addEventListener('keydown',e=>{if(activePanel||network&&!canAct())return;if(placement){const d={ArrowLeft:[-1,0],ArrowRight:[1,0],ArrowUp:[0,-1],ArrowDown:[0,1]}[e.key];if(d){e.preventDefault();const step=e.shiftKey?1:5;movePlacement({x:placement.x+d[0]*step,y:placement.y+d[1]*step});}else if(e.key==='Enter'){e.preventDefault();confirmPlacement();}else if(e.key==='Escape'){e.preventDefault();cancelPlacementDrag();}return;}if(['ArrowLeft','ArrowRight','ArrowUp','ArrowDown',' ','Shift','Escape'].includes(e.key)){e.preventDefault();if(e.key==='Escape'){cancelGesture();return;}if(e.key===' '){if(!e.repeat)shoot();return;}if(gesture?.type==='pull'&&['ArrowLeft','ArrowRight'].includes(e.key))return;if(!e.repeat){const action={ArrowLeft:'aim-left',ArrowRight:'aim-right',ArrowUp:'power-up',ArrowDown:'power-down'}[e.key];if(action?.startsWith('aim-'))aimKeyStarted=performance.now();if(action)adjust(action,(e.shiftKey&&e.key.startsWith('Arrow')&&['ArrowLeft','ArrowRight'].includes(e.key))?.25:1);}keys.add(e.key);}});
document.addEventListener('keyup',e=>keys.delete(e.key));
// All power inputs share the same visible cue position and grab area.
function cueGap(){return R+3+Math.max(0,Math.min(100,power))*1.4;}
function cueGeometry(){return {gap:cueGap(),length:360};}
function cueHit(p,b){const dx=p.x-b.x,dy=p.y-b.y,along=-dx*Math.cos(angle)-dy*Math.sin(angle),across=Math.abs(dx*Math.sin(angle)-dy*Math.cos(angle));const tolerance=14*viewW/canvas.getBoundingClientRect().width,{gap,length}=cueGeometry(b);return along>=gap-4&&along<=gap+length&&across<=Math.max(12,tolerance);}
function cancelGesture(){if(placement){cancelPlacementDrag();keys.clear();hold=null;return;}if(gesture){power=gesture.oldPower;const cue=gesture.fromCue;if(cue)angle=gesture.oldAngle;gesture=null;updateCursor();status(cue?'Cue adjustment canceled. Your aim is unchanged.':'Pull-back canceled. Your shot is unchanged.');updateUI();}keys.clear();hold=null;}
canvas.addEventListener('blur',cancelGesture);window.addEventListener('blur',()=>{cancelGesture();drag=null;});document.addEventListener('visibilitychange',()=>{if(document.hidden)cancelGesture();});
function insideTableCanvas(p){return p&&Number.isFinite(p.x)&&Number.isFinite(p.y)&&p.x>=-cueSpace.left&&p.x<=W+cueSpace.right&&p.y>=-cueSpace.top&&p.y<=H+cueSpace.bottom;}
function aimHit(p,b){if(!insideTableCanvas(p))return false;if(cueHit(p,b))return true;const dx=p.x-b.x,dy=p.y-b.y,along=dx*Math.cos(angle)+dy*Math.sin(angle),across=Math.abs(dx*Math.sin(angle)-dy*Math.cos(angle));return along>=-R&&along<=350&&across<=Math.max(14,16*viewW/canvas.getBoundingClientRect().width);}
function onCloth(p){return p.x>=box.l+R&&p.x<=box.r-R&&p.y>=box.t+R&&p.y<=box.b-R&&!pockets.some(h=>Math.hypot(p.x-h[0],p.y-h[1])<R+25);}
function updateCursor(p=hoverPoint){hoverPoint=p;const b=cueBall();const cursor=moving||activePanel?'default':gesture?'crosshair':pocketHit(p)>=0?'crosshair':placement?(placement.pointer!=null||p&&(onCloth(p)||placementHit(p))?'move':'default'):p&&b&&aimHit(p,b)?'crosshair':'default';if(canvas.style.cursor!==cursor)canvas.style.cursor=cursor;}
function startPull(p){if(!gesture||gesture.type==='pull')return;gesture.resumeType=gesture.type;gesture.type='pull';gesture.button=2;gesture.start=p;gesture.shotAngle=angle;gesture.oldPower=power;gesture.oldAngle=angle;gesture.fromCue=false;gesture.pull=0;gesture.basePull=0;gesture.lastPoint=p;status('Aim locked. Pull back for power; release right mouse to shoot. Esc cancels.');updateCursor(p);}
function finishGesture(p,buttons=0){if(!gesture)return;const g=gesture,fire=g.type==='pull'&&g.pull>12;gesture=null;
 if(g.type==='pull'&&!fire)power=g.oldPower;
 if(fire)shoot();else{status('Aim set. Hold right mouse and pull back for power.');if(g.type==='pull'&&(buttons&1)){const b=cueBall(),type=g.resumeType|| (cueHit(p,b)?'cue-aim':'aim');gesture={type,button:0,start:p,oldPower:power,oldAngle:angle,fromCue:type==='cue-aim',angleOffset:angle-Math.atan2(p.y-b.y,p.x-b.x)-Math.PI,pull:0,id:g.id,shotAngle:angle};}}
 if(!gesture&&canvas.hasPointerCapture(g.id))canvas.releasePointerCapture(g.id);updateUI();updateCursor(p);
}
canvas.addEventListener('contextmenu',e=>e.preventDefault());
// This standalone page is entirely the game surface, including margins and panels.
// Suppress only the menu event; right-button aiming/power gestures stay active.
document.addEventListener('contextmenu',e=>e.preventDefault(),true);
// Wheel events do not bubble across the nested table iframe. Let the existing
// framework scroll owner handle them, while retaining local popup scrolling.
document.addEventListener('wheel',e=>{
 if(e.ctrlKey||activePanel?.contains(e.target)||e.target.closest?.('.match-actions')||parent===window)return;
 try{if(!parent.document.getElementById('corechat-single-scroll-sizing'))return;
  const forwarded=new parent.WheelEvent('wheel',{deltaX:e.deltaX,deltaY:e.deltaY,deltaMode:e.deltaMode,shiftKey:e.shiftKey,cancelable:true,bubbles:true});
  parent.document.dispatchEvent(forwarded);if(forwarded.defaultPrevented)e.preventDefault();
 }catch{/* Standalone or cross-origin presentation keeps native scrolling. */}
},{passive:false});
canvas.addEventListener('pointerleave',()=>{if(!gesture&&placement?.pointer==null)updateCursor(null);});
canvas.addEventListener('pointerdown',e=>{
 // Ball-in-hand still works when a scratch has removed the cue ball from play.
 const p=point(e),b=cueBall();updateCursor(p);if(network&&!canAct()||moving||activePanel||![0,2].includes(e.button)||(!placement&&!b))return;
 if(!insideTableCanvas(p))return;
 const calledPocket=e.button===0?pocketHit(p):-1;if(calledPocket>=0){e.preventDefault();selectPocket(calledPocket);return;}
 if(gesture){if(e.button===2&&!placement)startPull(p);return;}
 if(placement?(e.button!==0||(!onCloth(p)&&!placementHit(p))):!aimHit(p,b))return;
 e.preventDefault();canvas.focus({preventScroll:true});canvas.setPointerCapture(e.pointerId);
 if(placement){const grabbed=placementHit(p);placement.dragStart={x:placement.x,y:placement.y};placement.dragOffset=grabbed?{x:placement.x-p.x,y:placement.y-p.y}:{x:0,y:0};placement.pointer=e.pointerId;if(!grabbed)movePlacement(p);updateCursor(p);return;}
 const type=e.button===2?'pull':cueHit(p,b)?'cue-aim':'aim';
 gesture={type,button:e.button,start:p,oldPower:power,oldAngle:angle,fromCue:type==='cue-aim',angleOffset:angle-Math.atan2(p.y-b.y,p.x-b.x)-Math.PI,pull:0,id:e.pointerId};
 if(type==='aim'&&Math.hypot(p.x-b.x,p.y-b.y)>R+6)angle=Math.atan2(p.y-b.y,p.x-b.x);
 gesture.shotAngle=angle;gesture.lastPoint=p;updateCursor(p);
 status(type==='pull'?'Aim locked. Pull back for power; release right mouse to shoot. Esc cancels.':'Drag to aim. Hold right mouse and pull back for power.');updateUI();
});
// A second mouse button produces pointermove, not another pointerdown.
canvas.addEventListener('pointermove',e=>{
 const p=point(e),b=cueBall();updateCursor(p);if(network&&!canAct()||moving||activePanel||(!placement&&!b))return;
 if(placement){if(placement.pointer===e.pointerId)movePlacement({x:p.x+(placement.dragOffset?.x||0),y:p.y+(placement.dragOffset?.y||0)});return;}
 if(!gesture||e.pointerId!==gesture.id)return;
 
 if(gesture.type!=='pull'&&(e.buttons&2)){startPull(p);return;}
 if(gesture.type==='pull'&&typeof e.buttons==='number'&&!(e.buttons&2)){finishGesture(p,e.buttons);return;}
 gesture.lastPoint=p;
 if(gesture.type==='pull'){angle=gesture.shotAngle;gesture.pull=clamp((gesture.basePull||0)+(gesture.start.x-p.x)*Math.cos(angle)+(gesture.start.y-p.y)*Math.sin(angle),0,100/.6);power=clamp(gesture.pull*.6,5,100);}
 else if(Math.hypot(p.x-b.x,p.y-b.y)>R+6){angle=Math.atan2(p.y-b.y,p.x-b.x)+(gesture.type==='cue-aim'?Math.PI+gesture.angleOffset:(gesture.keyboardOffset||0));}
 updateUI();
});
// Mouse button events also cover stationary press/release with another button held.
canvas.addEventListener('mousedown',e=>{if(e.button===2&&gesture&&!placement&&!moving&&!activePanel){e.preventDefault();startPull(point(e));}});
canvas.addEventListener('mouseup',e=>{if(e.button===2&&gesture?.type==='pull')finishGesture(point(e),e.buttons||0);});
canvas.addEventListener('pointerup',e=>{const p=point(e);if(placement){if(placement.pointer===e.pointerId){placement.pointer=null;placement.dragStart=null;placement.dragOffset=null;if(canvas.hasPointerCapture(e.pointerId))canvas.releasePointerCapture(e.pointerId);}updateCursor(p);return;}if(!gesture||e.pointerId!==gesture.id||e.button!==gesture.button)return;finishGesture(p,e.buttons||0);});
canvas.addEventListener('pointercancel',cancelGesture);canvas.addEventListener('lostpointercapture',()=>{if(gesture)cancelGesture();});
document.querySelectorAll('[data-adjust]').forEach(button=>{button.addEventListener('pointerdown',e=>{if(e.button!==0||moving)return;e.preventDefault();canvas.focus({preventScroll:true});button.setPointerCapture(e.pointerId);adjust(button.dataset.adjust);hold={action:button.dataset.adjust,start:performance.now()};});for(const name of['pointerup','pointercancel','lostpointercapture'])button.addEventListener(name,()=>hold=null);button.addEventListener('click',e=>{if(e.detail===0)adjust(button.dataset.adjust);});});
$('#power').addEventListener('input',e=>{power=+e.target.value;if(power>0&&power<5)power=5;updateUI();});
// Preserve the existing Up=increase / Down=decrease keyboard convention.
$('#power').addEventListener('keydown',e=>{if(e.key!=='ArrowUp'&&e.key!=='ArrowDown')return;e.preventDefault();if(moving||placement||network&&!canAct())return;adjust(e.key==='ArrowUp'?'power-up':'power-down');});
$('#shoot').addEventListener('click',()=>{canvas.focus({preventScroll:true});shoot();});
function closePanel(){if(!activePanel)return;activePanel.hidden=true;activePanel=null;keys.clear();drag=null;opener?.focus({preventScroll:true});}
function openPanel(panel,button){closePanel();cancelGesture();activePanel=panel;opener=button;panel.hidden=false;const rect=button.getBoundingClientRect(),pw=panel.offsetWidth,ph=panel.offsetHeight;panel.style.left=clamp(rect.left,12,Math.max(12,innerWidth-pw-12))+'px';panel.style.top=clamp(rect.top-ph-10,12,Math.max(12,innerHeight-ph-12))+'px';panel.querySelector('[data-close]').focus({preventScroll:true});}
for(const [button,panel]of[['#openCues','#cuePanel'],['#openSettings','#settingsPanel']])$(button).addEventListener('click',()=>{if(button==='#openCues')buildCues();openPanel($(panel),$(button));});
document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',closePanel));
document.addEventListener('pointerdown',e=>{if(activePanel&&!activePanel.contains(e.target)&&!e.target.closest('#openCues,#openSettings,#openPracticeSaves')){closePanel();}} ,true);
document.addEventListener('keydown',e=>{if(activePanel&&e.key==='Escape'){e.preventDefault();closePanel();}});
document.querySelectorAll('.panel-head').forEach(head=>{head.addEventListener('pointerdown',e=>{if(e.target.closest('button'))return;const p=head.parentElement,r=p.getBoundingClientRect();drag={p,x:e.clientX-r.left,y:e.clientY-r.top};head.setPointerCapture(e.pointerId);});head.addEventListener('pointermove',e=>{if(!drag)return;drag.p.style.left=clamp(e.clientX-drag.x,8,Math.max(8,innerWidth-drag.p.offsetWidth-8))+'px';drag.p.style.top=clamp(e.clientY-drag.y,8,Math.max(8,innerHeight-drag.p.offsetHeight-8))+'px';});['pointerup','pointercancel','lostpointercapture'].forEach(type=>head.addEventListener(type,()=>drag=null));});
window.addEventListener('resize',()=>{if(activePanel){const r=activePanel.getBoundingClientRect();activePanel.style.left=clamp(r.left,8,Math.max(8,innerWidth-activePanel.offsetWidth-8))+'px';activePanel.style.top=clamp(r.top,8,Math.max(8,innerHeight-activePanel.offsetHeight-8))+'px';}resizeCanvas();});
function updatePlayerCues(){$('#yourCueLabel').textContent=cues[playerCues[0]].name;$('#partnerCueLabel').textContent=cues[playerCues[1]].name;$('#yourTurn').hidden=activePlayer!==0;$('#partnerTurn').hidden=activePlayer!==1;$('.you').classList.toggle('active',activePlayer===0);$('.opponent').classList.toggle('active',activePlayer===1);$('#cueOwner').textContent=activePlayer===0?'YOUR CUE':"PARTNER'S CUE";$('#cueName').textContent=cues[cueIndex].name;}
function buildCues(){updatePlayerCues();const parent=$('#cueChoices');parent.innerHTML='';cues.forEach((c,i)=>{const button=document.createElement('button');button.className='cue-choice';button.setAttribute('aria-pressed',String(i===(network?.state?.cues?.[network.currentUserId]??cueIndex)));button.setAttribute('aria-label',c.name);button.innerHTML=`<span class="choice-top"><span>${c.name}</span><span class="selected-label">${i===(network?.state?.cues?.[network.currentUserId]??cueIndex)?'Selected':''}</span></span><canvas width="580" height="56" aria-hidden="true"></canvas>`;parent.append(button);const cx=button.querySelector('canvas').getContext('2d');cueDraw(c,560,27,Math.PI,540,15,cx);button.addEventListener('click',()=>{if(network){postAction('cue',{cue:i});closePanel();return;}cueIndex=i;playerCues[activePlayer]=i;buildCues();const chosen=parent.children[i];chosen.focus({preventScroll:true});});});}
$('#previewPlayer').addEventListener('change',e=>{if(moving){e.target.value=String(activePlayer);return;}cancelGesture();activePlayer=Number(e.target.value)===1?1:0;cueIndex=playerCues[activePlayer];buildCues();status(activePlayer===0?'Your preview turn. Your own cue is selected.':"Partner preview turn. Their own cue is selected.");});
let spinPointer=null,spinStart=null;
function updateSpin(){$('#spinMark').style.left=(50+spin.x*38)+'%';$('#spinMark').style.top=(50+spin.y*38)+'%';const parts=[];if(Math.abs(spin.y)>.04)parts.push((spin.y<0?'Top ':'Back ')+Math.round(Math.abs(spin.y)*100)+'%');if(Math.abs(spin.x)>.04)parts.push((spin.x<0?'L ':'R ')+Math.round(Math.abs(spin.x)*100)+'%');$('#spinReadout').textContent=parts.join(' · ')||'Center hit';}
function selectSpin(e){const r=$('#spinTarget').getBoundingClientRect();let x=(e.clientX-r.left-r.width/2)/(r.width*.38),y=(e.clientY-r.top-r.height/2)/(r.height*.38),d=Math.hypot(x,y);if(d>1){x/=d;y/=d;}spin={x,y};updateSpin();}
function cancelSpin(){if(spinPointer!==null&&spinStart){spin={...spinStart};updateSpin();}spinPointer=null;spinStart=null;}
$('#spinTarget').addEventListener('pointerdown',e=>{if(e.button!==0||moving||gesture)return;e.preventDefault();$('#spinTarget').focus({preventScroll:true});spinStart={...spin};spinPointer=e.pointerId;$('#spinTarget').setPointerCapture(e.pointerId);selectSpin(e);});
$('#spinTarget').addEventListener('pointermove',e=>{if(spinPointer===e.pointerId&&!moving)selectSpin(e);});
$('#spinTarget').addEventListener('pointerup',e=>{if(spinPointer!==e.pointerId)return;spinPointer=null;spinStart=null;if($('#spinTarget').hasPointerCapture(e.pointerId))$('#spinTarget').releasePointerCapture(e.pointerId);});
for(const ev of ['pointercancel','lostpointercapture','blur'])$('#spinTarget').addEventListener(ev,cancelSpin);
window.addEventListener('blur',cancelSpin);
$('#spinTarget').addEventListener('keydown',e=>{if(moving||gesture)return;const delta={ArrowLeft:[-.1,0],ArrowRight:[.1,0],ArrowUp:[0,-.1],ArrowDown:[0,.1]}[e.key];if(e.key==='Escape'){e.preventDefault();cancelSpin();return;}if(e.key==='Home'){e.preventDefault();spin={x:0,y:0};}else if(delta){e.preventDefault();spin.x+=delta[0];spin.y+=delta[1];const d=Math.hypot(spin.x,spin.y);if(d>1){spin.x/=d;spin.y/=d;}}updateSpin();});
$('#resetSpin').addEventListener('click',()=>{if(moving||gesture)return;cancelSpin();spin={x:0,y:0};updateSpin();});
$('#guideToggle').addEventListener('change',e=>guide=e.target.checked);$('#soundToggle').addEventListener('change',e=>{sound=e.target.checked&&network?.options?.effectsEnabled!==false;if(sound)void tableAudio.unlock();else tableAudio.stop();});$('#cloth').addEventListener('change',e=>{if(Object.hasOwn(clothSchemes,e.target.value)){cloth=e.target.value;saveAppearance();}});
$('#tableFinish').addEventListener('change',e=>{if(Object.hasOwn(tableFinishes,e.target.value)){tableFinish=e.target.value;saveAppearance();}});
loadAppearance();
$('#alwaysHighlightTargets').addEventListener('change',e=>{alwaysHighlightTargets=e.target.checked;draw();});
let availableTableWidth=0;
function layoutTable(){const base=availableTableWidth||Math.max(1,document.documentElement.clientWidth-2),tableWidth=Math.min(base,1120*Number($('#boardSize').value)/100);$('.table-wrap').style.setProperty('--table-width',tableWidth*viewW/W+'px');parent.postMessage({type:'pool-width',extraWidth:tableWidth*(viewW-W)/W},location.origin);resizeCanvas();}
function sizeChange(v){$('#boardSize').value=v;$('#sizeValue').textContent=v+'%';layoutTable();}
$('#boardSize').addEventListener('input',e=>sizeChange(+e.target.value));document.querySelectorAll('[data-size]').forEach(b=>{let timer;const stop=()=>clearInterval(timer);b.addEventListener('pointerdown',e=>{if(e.button!==0)return;e.preventDefault();b.setPointerCapture(e.pointerId);sizeChange(clamp(+$('#boardSize').value+ +b.dataset.size,50,200));timer=setInterval(()=>sizeChange(clamp(+$('#boardSize').value+ +b.dataset.size,50,200)),180);});['pointerup','pointercancel','lostpointercapture'].forEach(t=>b.addEventListener(t,stop));window.addEventListener('blur',stop);b.addEventListener('click',e=>{if(e.detail===0)sizeChange(clamp(+$('#boardSize').value+ +b.dataset.size,50,200));});});
for(const [id,mode]of[['#resetShot',null],['#rackBalls','break'],['#pocketLayout','pocket']])$(id).addEventListener('click',()=>{if(network){postAction((mode||layout)==='break'?'rack':'layout',{layout:mode||layout});closePanel();return;}reset(mode||layout);closePanel();canvas.focus({preventScroll:true});});
function resizeCanvas(){const ratio=Math.min(window.devicePixelRatio||1,2),w=Math.round(Math.max(600,canvas.clientWidth)*ratio),h=Math.round(w*viewH/viewW);if(canvas.width!==w||canvas.height!==h){canvas.width=w;canvas.height=h;}ctx.setTransform(w/viewW,0,0,h/viewH,cueSpace.left*w/viewW,cueSpace.top*h/viewH);draw();}
// Read-only diagnostics for isolated preview verification. Not a gameplay API.

// Live arrivals play from the first saved frame; reconnects seek to server time.
function startPlayback(last,age=0,replayRate=0){
 balls=structuredClone(last.before);returned=balls.filter(b=>b.pocket&&b.n).map(b=>b.n);rollingBallArt.reset();updateBallLists();
 playback={last,frames:shotFrames(last,R),time:age,startAge:age,clientStart:performance.now(),rate:replayRate||1,replay:!!replayRate};
 moving=true;placement=null;if(age===0)noiseHit(window.PoolPhysics.shotSpeed(last.power),'cue');
 matchActions();status(replayRate?'Replaying last shot'+(replayRate<1?' — slow motion.':'.'):'Shot in motion...');
}
let replaySetup=null;
function updateReplay(){const eligible=network?.state?.settings?.tableMode==='solo'&&network?.state?.turnOrder?.includes(network.currentUserId),active=!!playback?.replay;
 for(const id of ['replayPractice','replaySlow']){const b=$('#'+id);b.hidden=!eligible;b.disabled=!canAct()||!!practice?.editing||!network?.state?.lastShot;}
 $('#stopReplay').hidden=!active;
}
function endReplay(){if(!playback?.replay)return;playback=null;moving=false;tableAudio.stop();if(replaySetup){({angle,power,spin}=replaySetup);replaySetup=null;updateSpin();}settledNetwork();practice?.update();updateReplay();}
function replayShot(rate){if(!canAct()||practice?.editing||network.state.settings.tableMode!=='solo'||!network.state.lastShot)return;
 cancelGesture();keys.clear();hold=null;closePanel();replaySetup={angle,power,spin:{...spin}};startPlayback(network.state.lastShot,0,rate);updateUI();practice?.update();updateReplay();
}
$('#replayPractice').onclick=()=>replayShot(1);$('#replaySlow').onclick=()=>replayShot(.35);$('#stopReplay').onclick=endReplay;
window.addEventListener('keydown',e=>{if(e.key==='Escape'&&playback?.replay){e.preventDefault();endReplay();}},true);
function acceptPlayback(last,previous,data){
 const age=Math.max(0,(data.state.serverNow||0)-last.startedAt);
 const live=previous?.sessionId===data.sessionId&&previous?.state?.schemaVersion&&Number(last.id)===Number(previous.state.shotNumber||0)+1&&age<last.duration+2;
 if(live&&playback&&pendingShots.length<4){pendingShots.push(last);return;}
 if(live){pendingShots=[];startPlayback(last);}
 else {pendingShots=[];if(age<last.duration)startPlayback(last,age);else{playback=null;moving=false;settledNetwork();}}
}
function playNetwork(dt){if(!playback){moving=false;return;}const p=playback,previousTime=p.time;
 p.time=Math.max(p.time+dt*p.rate,p.startAge+(performance.now()-p.clientStart)/1000*p.rate);
 // Do not emit a burst of old impacts after a hidden tab resumes.
 if(p.time-previousTime<.3)for(const e of p.last.events)if(e.time>=previousTime&&e.time<p.time)noiseHit(e.speed??500,e.type);
 const sampled=sampleShot(p.frames,p.time,R);
 for(const b of sampled){const event=p.last.events.find(e=>e.type==='pocket'&&e.a===b.n);b.drop=b.pocket&&event?clamp((p.time-event.time)/.22,0,1):b.pocket?1:0;if(event)b.hole=pockets[event.pocket];}
 balls=sampled;const nextReturned=balls.filter(b=>b.n&&b.pocket&&b.drop>=1).map(b=>b.n);if(String(nextReturned)!==String(returned)){returned=nextReturned;updateBallLists();}
 if(p.time*30>=p.last.frameCount-1){if(p.replay){endReplay();return;}playback=null;if(pendingShots.length)startPlayback(pendingShots.shift());else{moving=false;settledNetwork();practice?.update();updateReplay();}}
}

function settledNetwork(){if(!network)return;balls=structuredClone(network.state.balls||[]);
 // Old saves lack surface rotations. Recover their last recorded path once per
 // snapshot, with the same identity starting point used by the server upgrade.
 const legacy=network.state.lastShot&&balls.some(b=>!b.orientation)?shotFrames(network.state.lastShot,R).at(-1):null;
 for(const b of balls){b.drop=b.pocket?1:0;const old=legacy?.find(o=>o.n===b.n);if(!b.orientation&&old)b.orientation=roll(old.orientation,b.x-old.x,b.y-old.y,R);}
 returned=balls.filter(b=>b.pocket&&b.n).map(b=>b.n);shot=(network.state.shotNumber||0)+1;placement=null;
 if(canAct()&&network.state.phase==='placement'){const b=balls.find(b=>b.n===0);placement={mode:network.state.placement==='break'?'break':'foul',x:b?.x||330,y:b?.y||337,valid:false,pointer:null};placement.valid=placementValid(placement);}
 status(network.state.statusText||'Waiting for the table.');updateBallLists();matchActions();updateUI();updateTurnGuide();draw();}
function matchActions(){const host=$('#matchActions'),st=network.state,signature=JSON.stringify([network.sessionId,st.phase,st.settings?.pocketCalls,st.settings?.variant,st.pushOutAvailable,st.pushOutDeclared,st.groups,st.turnIndex,st.balls?.filter(b=>!b.pocket).map(b=>b.n),st.choice,st.stalemateRequests,st.calledShot,network.currentUserId,canAct()]);if(host.dataset.signature===signature)return;host.dataset.signature=signature;host.replaceChildren();
 const button=(name,action,payload)=>{const b=document.createElement('button');b.textContent=name;b.onclick=()=>postAction(action,payload);host.append(b);};
 if(st.phase==='aim'&&st.settings.tableMode==='match'&&st.turnOrder.includes(network.currentUserId)&&!network.busy&&!sending&&!playback)button(st.stalemateRequests?.includes(network.currentUserId)?'Withdraw stalemate':st.stalemateRequests?.length?'Agree to stalemate':'Request stalemate','stalemate',{});
 if(!canAct())return;
 if(['rack','rerack'].includes(st.phase))button('Rack the table','rack',{});
 if(st.phase==='choice'&&st.choice==='push-out'){button('Take shot','choice',{choice:'take'});button('Return shot','choice',{choice:'return'});return;}
 if(isNine()){if(st.phase==='aim'&&st.settings.tableMode==='match'&&st.pushOutAvailable)button(st.pushOutDeclared?'Cancel push out':'Push out','push-out',{});return;}
 if(st.phase==='choice'){const illegal=st.choice.startsWith('illegal');button(illegal?'Accept table':'Spot the 8','choice',{choice:illegal?'accept':'spot'});button('Re-rack: I break','choice',{choice:'rerack-self'});if(illegal)button('Re-rack: opponent breaks','choice',{choice:'rerack-other'});}
 if(st.phase==='aim'&&!st.breakShot&&st.settings.tableMode!=='solo'){
  const group=st.groups[network.currentUserId],remaining=st.balls.filter(b=>!b.pocket&&b.n!==0&&b.n!==8&&(!group||(group==='solids'?b.n<8:b.n>8))),eligible=remaining.length?remaining:st.balls.filter(b=>!b.pocket&&b.n===8);
  if(!group&&['solids','stripes'].some(g=>!st.balls.some(b=>!b.pocket&&b.n!==0&&b.n!==8&&(g==='solids'?b.n<8:b.n>8)))&&!eligible.some(b=>b.n===8)){const eight=st.balls.find(b=>b.n===8&&!b.pocket);if(eight)eligible.push(eight);}
  const callRule=st.settings.pocketCalls||'all',canCallEight=eligible.some(b=>b.n===8);
  if(callRule==='all'||(callRule==='eight'&&canCallEight)){
   const choices=callRule==='all'?eligible.map(b=>[b.n,String(b.n)]):remaining.length?[[0,'Normal shot'],[8,'8 ball']]:[[8,'8 ball']];
   for(const [id,label,entries]of[['calledBall',callRule==='eight'?'Shot':'Call ball',choices],['calledPocket',callRule==='eight'?'8-ball pocket':'Pocket',[['','Choose pocket'],[0,'Top left'],[1,'Top middle'],[2,'Top right'],[3,'Bottom left'],[4,'Bottom middle'],[5,'Bottom right']]]]){const l=document.createElement('label');l.textContent=label+' ';const select=document.createElement('select');select.id=id;for(const [v,t]of entries){const o=document.createElement('option');o.value=v;o.textContent=t;select.append(o);}l.append(select);host.append(l);}
   const confirmed=st.calledShot;
   if(confirmed){if([...$('#calledBall').options].some(o=>Number(o.value)===confirmed.calledBall))$('#calledBall').value=String(confirmed.calledBall);$('#calledPocket').value=confirmed.calledPocket>=0?String(confirmed.calledPocket):'';}
   $('#calledBall').addEventListener('change',()=>{$('#calledPocket').value='';shareCall();updateUI();});
   $('#calledPocket').addEventListener('change',()=>{shareCall();updateUI();});
   const hint=document.createElement('span');hint.textContent='Select a pocket on the table or use the pocket list.';hint.id='callHint';host.append(hint);
   if(callRule==='eight'){const ball=$('#calledBall'),pocket=$('#calledPocket');const sync=()=>{pocket.disabled=ball.value!=='8';pocket.closest('label').hidden=ball.value!=='8';};ball.addEventListener('change',sync);sync();}
  }
  const l=document.createElement('label'),c=document.createElement('input');c.type='checkbox';c.id='safetyShot';c.checked=!!st.calledShot?.safety;c.addEventListener('change',()=>{shareCall();updateUI();});l.append(c,' Safety');host.append(l);
 }
}
function receiveNetwork(data){const previous=network;if(playback?.replay&&(data.sessionId!==previous?.sessionId||data.state?.sequence!==previous?.state?.sequence||data.status!=='active'||data.state?.completed))endReplay();network=data;sending=false;const st=data.state||{};R=st.ballRadius===15.5?15.5:12.5;if(previous?.sessionId!==data.sessionId||!st.schemaVersion||(!st.lastShot&&previous?.state?.lastShot)){playback=null;pendingShots=[];playedShot='';networkVersion='';turnGuide={key:'',startedAt:0,ids:[]};rollingBallArt.reset();}if(!st.schemaVersion){moving=false;balls=[];status('Start the game in the waiting lobby.');draw();return;}
 const notice=$('#nineBallNotice');notice.hidden=!isNine();notice.textContent=isNine()&&st.settings?.tableMode==='match'?(st.turnOrder||[]).map(id=>{const count=st.foulCounts?.[id]||0,name=data.members.find(m=>m.userId===id)?.name||'Player';return name+': '+count+' consecutive fouls'+(count===2?' — next foul loses':'');}).join(' · '):'';
 const label=isNine()?'9 Ball':'8 Ball';$('.preview-tag').textContent=label;document.title='Pool — '+label;$('.pool-room').setAttribute('aria-label',label+' table');
 const ownIndex=st.turnOrder.indexOf(data.currentUserId),otherIndex=ownIndex===0?1:0;const ownId=ownIndex<0?st.turnOrder[0]:data.currentUserId,otherId=st.turnOrder.find(id=>id!==ownId);
 for(const [sel,id]of[['.you',ownId],['.opponent',otherId]]){const node=$(sel),member=data.members.find(m=>m.userId===id);node.hidden=!id;const image=node.querySelector('img');image.onerror=()=>{image.onerror=null;image.src='../../assets/images/baghead.png';};if(member?.avatar&&image.src!==member.avatar)image.src=member.avatar;node.querySelector('strong').textContent=member?.name||(id?'Player':'Solo');node.classList.toggle('active',id===st.turnOrder[st.turnIndex]);const turn=node.querySelector('.turn');turn.hidden=false;turn.textContent=st.settings.tableMode==='solo'?'Solo practice':(isNine()?'Lowest: '+(Number.isFinite(lowestBall(st))?lowestBall(st):'—'):(st.groups[id]||'Open table'));}
 playerCues=[st.cues[ownId]??6,st.cues[otherId]??6];activePlayer=st.turnOrder[st.turnIndex]===ownId?0:1;cueIndex=st.cues[st.turnOrder[st.turnIndex]]??6;updatePlayerCues();$('#cueName').textContent=cues[st.cues[data.currentUserId]??6].name;$('#cueOwner').textContent='YOUR CUE';$('#openCues').disabled=ownIndex<0;
 const solo=st.settings.tableMode==='solo';$('#previewPlayer').closest('label').hidden=true;for(const id of ['physicsExample','resetShot','rackBalls','pocketLayout','placeBeforeBreak','placeAfterFoul']){const node=$('#'+id);(node.closest('label')||node).hidden=!solo;}$('#settingsPanel .panel-hint').hidden=true;
 sound=data.options?.effectsEnabled!==false&&$('#soundToggle').checked;if(!sound||data.options?.masterVolume===0)tableAudio.stop();const key=data.sessionId+':'+st.sequence;const oldLayout=previous?.state?.layout;if(st.layout&&(st.layout!==oldLayout||st.layoutVersion!==previous?.state?.layoutVersion)){rollingBallArt.reset();layout=st.layout;$('#physicsExample').value=layout;angle=layout==='practice'?Math.atan2(-65,-130):layout==='pocket'?-Math.PI/2+.0333:0;power=layout==='pocket'?38:layout==='practice'?45:['left','right'].includes(layout)?55:65;spin={x:layout==='left'?-1:layout==='right'?1:0,y:layout==='follow'?-1:layout==='draw'?1:0};updateSpin();}if(st.breakShot&&st.phase==='placement'&&(data.sessionId!==previous?.sessionId||st.sequence!==previous?.state?.sequence)){angle=0;power=0;layout='break';$('#physicsExample').value='break';}
 if(networkVersion!==key){networkVersion=key;gesture=null;keys.clear();placement=null;const last=st.lastShot,shotKey=data.sessionId+':'+last?.id;
  if(last&&shotKey!==playedShot){playedShot=shotKey;power=0;acceptPlayback(last,previous,data);}
  else if(!playback){moving=false;settledNetwork();}
 }
 if(st.practiceSetup&&!st.lastShot&&st.practiceVersion!==previous?.state?.practiceVersion){angle=st.practiceSetup.angle;power=st.practiceSetup.power;spin={...st.practiceSetup.spin};updateSpin();}
 if(playback){const actor=playback.last.actor;cueIndex=st.cues[actor]??6;activePlayer=actor===ownId?0:1;$('.you').classList.toggle('active',actor===ownId);$('.opponent').classList.toggle('active',actor===otherId);}
 if(!playback&&canAct()&&st.phase==='placement'&&!placement)settledNetwork();
 if(!playback&&st.botAim&&st.botAim.actor===st.turnOrder?.[st.turnIndex]){angle=st.botAim.angle;power=st.botAim.power;spin={x:st.botAim.spinX,y:st.botAim.spinY};updateSpin();}
 if(!playback){status(data.busy?'Saving your action...':st.statusText||'Ready.');matchActions();}practice?.update();updateUI();updateReplay();updateBallLists();updateTurnGuide();
}
window.addEventListener('message',e=>{if(e.source!==parent||e.origin!==location.origin)return;if(e.data?.type==='pool-snapshot')receiveNetwork(e.data);if(e.data?.type==='pool-viewport'&&Number.isFinite(e.data.width)&&e.data.width>0){availableTableWidth=e.data.width;layoutTable();}});
parent.postMessage({type:'pool-ready'},location.origin);

window.CoreChatPoolPlayback={isActive:()=>!!playback||moving||pendingShots.length>0};
window.poolPreview={state:()=>({placement:placement?{mode:placement.mode,x:placement.x,y:placement.y,valid:placement.valid}:null,angle,power,cue:cues[cueIndex].name,activePlayer,playerCues:playerCues.map(i=>cues[i].name),moving,shot,playbackTime:playback?.time??null,replaying:!!playback?.replay,replayRate:playback?.rate??null,queuedShots:pendingShots.length,spin:{...spin},layout,returned:[...returned],balls:balls.map(b=>({...b})),keys:[...keys],panel:activePanel?.id||null,pull:gesture?.type==='pull'?gesture.pull:0})};
new ResizeObserver(()=>parent.postMessage({type:'pool-height',height:Math.ceil($('.game-shell').getBoundingClientRect().height)},location.origin)).observe($('.game-shell'));
practice=installPractice({canvas,colors,network:()=>network,balls:()=>balls,point,canAct,post:postAction,setup:()=>({angle,power,spin:{...spin}}),update:updateUI,restore:settledNetwork,open:openPanel,close:closePanel,cancelGesture});
buildCues();updateUI();resizeCanvas();requestAnimationFrame(loop);
})();
