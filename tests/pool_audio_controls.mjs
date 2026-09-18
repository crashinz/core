import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {impactSamples,createPoolAudio,impactLevel} from '../games/eight-ball/audio.js';
const checks=[];const ck=(value,name)=>{assert.ok(value,name);checks.push(name);};
for(const rate of [44100,48000])for(const kind of ['cue','ball','rail','pocket','pocketAlt']){
 const x=impactSamples(kind,rate);
 ck(x.length>1000&&x.every(Number.isFinite)&&x.some(v=>Math.abs(v)>.1)&&x.every(v=>Math.abs(v)<.73)&&Math.abs(x[0])<.0001&&Math.abs(x.at(-1))<.0001,`${kind} ${rate} Hz: audible PCM, no clipping or boundary clicks`);
}
let context;
class AudioContext{
 constructor(){context=this;this.state='running';this.sampleRate=48000;this.currentTime=0;this.started=[];this.destination={};this.gains=[];}
 createBuffer(ch,length,rate){assert.equal(ch,1);return {length,rate,copyToChannel(x){this.samples=x;}};}
 createBufferSource(){const c=this;return {connect(){return this;},disconnect(){},start(){c.started.push(this);},stop(){this.onended?.();}};}
 createGain(){const g={gain:{value:0},connect(){return this;},disconnect(){}};this.gains.push(g);return g;}
}
globalThis.window={AudioContext};let opts={enabled:true,volume:100};const audio=createPoolAudio(()=>opts);await audio.unlock();
const play=(kind,speed)=>{context.currentTime+=.1;audio.play(kind,speed);};
for(const kind of ['cue','ball','rail','pocket','pocket','pocket'])play(kind,500);
assert.deepEqual(context.started.map(s=>s.buffer.length),['cue','ball','rail','pocket','pocketAlt','pocket'].map(k=>impactSamples(k,48000).length));
ck(context.started[3].buffer===context.started[5].buffer&&context.started[3].buffer!==context.started[4].buffer,'Pocket clips alternate with distinct cached buffers');
const played=context.started.length;opts.enabled=false;play('pocket',500);opts.enabled=true;opts.volume=0;play('pocket',500);opts.volume=100;play('ball',0);ck(context.started.length===played,'Mute, zero volume and zero speed suppress playback');
play('pocket',500);ck(context.started.at(-1).buffer===context.started[4].buffer,'Silent events do not consume alternate pocket clip');
play('rail',100);const soft=context.gains.at(-1).gain.value;play('rail',1800);const hard=context.gains.at(-1).gain.value;opts.volume=50;play('rail',1800);
ck(hard>soft&&context.gains.at(-1).gain.value===hard/2&&impactLevel(1800)===1,'Strength and master volume scale recordings');audio.stop();
const src=fs.readFileSync(new URL('../games/eight-ball/pool.js',import.meta.url),'utf8');
let sends=[],updates=0;
const c={network:{state:{settings:{tableMode:'solo'}}},sending:false,playback:null,location:{origin:'https://example.test'},parent:{postMessage:(a,o)=>sends.push({a,o})},updateUI:()=>updates++};
vm.createContext(c);vm.runInContext(src.match(/^const postAction=.*$/m)[0]+';this.submit=postAction;',c);
for(const playback of [{replay:false},{replay:true}]){c.playback=playback;c.submit('rack');ck(sends.length===0,'Playback blocks table mutation: replay='+playback.replay);}
c.playback=null;c.submit('rack');c.submit('rack');ck(sends.length===1&&updates===1&&sends[0].o===c.location.origin,'Idle action submits once and preserves origin');
const p={network:{state:{settings:{tableMode:'solo'}}},canAct:()=>true,balls:[{n:0,x:400,y:300},{n:1,x:400,y:300}],placement:null,box:{l:83,r:1110,t:85,b:590},R:15.5,pockets:[],updateUI(){},closePanel(){}};
vm.createContext(p);vm.runInContext(src.match(/^function placementValid.*$/m)[0]+'\n'+src.match(/^function beginPlacement.*$/m)[0]+';this.begin=beginPlacement;',p);
p.begin('foul');ck(!p.placement.valid,'Initial overlapping cue placement is invalid before pointer movement');
p.balls[1].x=700;p.begin('foul');ck(p.placement.valid,'Initial clear cue placement is valid');
p.begin('break');ck(p.placement.mode==='break'&&!p.placement.valid,'Break placement retains requested restricted area');
p.balls[0].x=300;p.begin('break');ck(p.placement.valid,'Valid behind-line break placement is accepted');
const old=p.placement;p.canAct=()=>false;p.begin('foul');ck(p.placement===old,'Placement cannot start during inactive turn or motion');
const calls=[];const s={balls:[],returned:[],placement:{},moving:false,playback:null,R:15.5,structuredClone,performance:{now:()=>123},rollingBallArt:{reset(){}},updateBallLists(){},shotFrames:()=>[],window:{PoolPhysics:{shotSpeed:p=>p*10}},noiseHit:(speed,type)=>calls.push(type),status:()=>calls.push('status')};
s.matchActions=()=>{assert.ok(s.playback&&s.moving);assert.equal(s.placement,null);calls.push('controls');};vm.createContext(s);
vm.runInContext(src.slice(src.indexOf('function startPlayback('),src.indexOf('let replaySetup='))+';this.start=startPlayback;',s);
s.start({before:[],power:40},0,0);ck(calls.join(',')==='cue,controls,status','Shot playback refreshes controls after setting motion state');
console.log(JSON.stringify({status:'PASS',checks:checks.length,details:checks},null,2));
