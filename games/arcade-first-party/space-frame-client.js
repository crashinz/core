import {spaceSnapshot,spaceStep,spaceAliens,spaceKey,spaceCoopStep} from './space-simulation.js?v=4ca638202daf';

// Keep acknowledged-in-flight frames plus newer local input; a request and its
// reply can together occupy more than the one-second server input allowance.
export class SpaceFrameClient {
  constructor(){this.reset();}
  reset(){this.state=null;this.authoritative=null;this.queue=[];this.version=null;this.previous=null;this.credit=0;this.updated=0;this.pulses={};this.resyncs=0;}
  press(key){this.pulses[key]=true;}
  accept(state,version,now){
    const key=`${version ?? ''}:${state.elapsedMs || 0}:${JSON.stringify(state.inputSequences || {})}`;
    if(key===this.version)return;
    this.version=key;this.updated=now;this.authoritative=spaceSnapshot(state);
    const expected=this.queue.find(frame=>frame.after.elapsedMs===state.elapsedMs)?.after;
    const unchanged=this.state&&state.elapsedMs===(this.queue[0]?.start ?? this.state.elapsedMs);
    if(!this.state||state.completed||(!unchanged&&(!expected||spaceKey(expected)!==spaceKey(state)))){
      if(this.state&&!state.completed)this.resyncs++;
      this.state=spaceSnapshot(state);this.queue=[];this.previous=now;this.credit=0;
    }else this.queue=this.queue.filter(frame=>frame.start>=state.elapsedMs);
  }
  frame(now,controls,active){
    if(!this.state)return {x:268.8,orbs:[],aliens:[]};
    const dt=Math.max(0,Math.min(100,now-(this.previous ?? now)));this.previous=now;
    if(!active){this.state=spaceSnapshot(this.authoritative);this.queue=[];this.credit=0;this.pulses={};}
    else if(now-this.updated<=1000&&this.queue.length<100){
      this.credit+=dt;
      while(this.credit>=20&&!this.state.completed&&this.queue.length<100){
        const input={left:!!(controls.left||this.pulses.left),right:!!(controls.right||this.pulses.right),fire:!!(controls.fire||this.pulses.fire)};
        const start=this.state.elapsedMs;spaceStep(this.state,input);
        this.queue.push({start,input,after:spaceSnapshot(this.state)});
        this.credit-=20;this.pulses={};
      }
    }
    return {x:this.state.ship.x,orbs:this.state.orbs,aliens:spaceAliens(this.state)};
  }
  batch(){
    if(this.queue.length<5&&!this.state?.completed)return null;
    const frames=[];
    for(const frame of this.queue.slice(0,50)){
      const last=frames.at(-1),input=frame.input;
      if(last&&last.left===input.left&&last.right===input.right&&last.fire===input.fire)last.ticks++;
      else frames.push({ticks:1,...input});
    }
    return frames.length?{frameStart:this.queue[0].start,frames}:null;
  }
}

// Rebase unplayed local controls on each shared snapshot. A peer's packet must
// never reset the local ship to an older snapshot and discard newer controls.
export class SpaceCoopClient extends SpaceFrameClient {
  setActor(actor){this.actor=Number(actor);}
  accept(state,version,now){
    const key=`${version ?? ''}:${state.elapsedMs}:${JSON.stringify(state.inputSequences || {})}`;
    if(key===this.version)return;
    const first=!this.state;
    this.version=key;this.updated=now;this.through=state.spaceInputThrough?.[this.actor] || 0;
    this.authoritative=spaceSnapshot(state);this.state=spaceSnapshot(state);
    this.queue=state.completed?[]:this.queue.filter(frame=>frame.start>=state.elapsedMs);
    if(this.queue.length&&this.queue[0].start!==state.elapsedMs)this.queue=[];
    // A late-loading peer must begin new controls at the server's current
    // input frontier, not behind its missing-input grace window. Fill that
    // existing time with acknowledged controls (reload) or neutral controls.
    // Never apply a newly pressed key retroactively to the loading interval.
    if(!state.completed&&!this.queue.length){
      const pending=Math.max(0,Math.min(1000,Number(state.realtime?.pendingMs)||0));
      const end=state.elapsedMs+Math.floor(pending/20)*20;
      for(let start=state.elapsedMs;start<end;start+=20){
        const saved=state.spacePendingInputs?.[start];
        this.queue.push({start,input:{left:!!saved?.left,right:!!saved?.right,fire:!!saved?.fire}});
      }
    }
    for(const frame of this.queue)spaceCoopStep(this.state,this.actor,frame.input);
    if(first||!this.queue.length){this.previous=now;this.credit=0;}
  }
  frame(now,controls,active){
    if(!this.state)return {x:268.8,ships:{},orbs:[],aliens:[]};
    const dt=Math.max(0,Math.min(100,now-(this.previous ?? now)));this.previous=now;
    if(!active){this.state=spaceSnapshot(this.authoritative);this.queue=[];this.credit=0;this.pulses={};}
    else if(now-this.updated<=1000&&this.queue.length<100){
      this.credit+=dt;
      while(this.credit>=20&&!this.state.completed&&this.queue.length<100){
        const input={left:!!(controls.left||this.pulses.left),right:!!(controls.right||this.pulses.right),fire:!!(controls.fire||this.pulses.fire)};
        const start=this.state.elapsedMs;spaceCoopStep(this.state,this.actor,input);
        this.queue.push({start,input});this.credit-=20;this.pulses={};
      }
    }
    return {x:this.state.ships[this.actor]?.x ?? this.state.ship.x,ships:this.state.ships,orbs:this.state.orbs,aliens:spaceAliens(this.state)};
  }
  batch(){
    const queue=this.queue.filter(frame=>frame.start>=Math.max(this.through || 0,this.authoritative?.elapsedMs || 0));
    if(queue.length<5&&!this.state?.completed)return null;
    const frames=[];
    for(const frame of queue.slice(0,50)){
      const last=frames.at(-1),input=frame.input;
      if(last&&last.left===input.left&&last.right===input.right&&last.fire===input.fire)last.ticks++;
      else frames.push({ticks:1,...input});
    }
    return frames.length?{frameStart:queue[0].start,frames}:null;
  }
}
