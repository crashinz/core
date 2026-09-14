// Presentation and bounded input transport only. Server reducers own all results.
import { availableGameViewportHeight } from "../viewport-height-fit.js";
import { SpaceFrameClient, SpaceCoopClient } from "./space-frame-client.js?v=751614b24395";
import { TetrisBoardView } from "./tetris-view.js?v=29fc86bae5b5";
const kind = document.body.dataset.extension === "tetris-versus" ? "tetris" : "space";
const storageKey = `corechat.arcade.${kind}.presentation.v1`;
let preferences = { size:100, fit:false };
try {
  const saved = JSON.parse(localStorage.getItem(storageKey) || "null");
  if (saved && [50,75,100,125,150].includes(saved.size)) preferences.size = saved.size;
  if (typeof saved?.fit === "boolean") preferences.fit = saved.fit;
} catch { /* Storage may be disabled without preventing play. */ }
const colors = { I:"#66e0ff",J:"#718dff",L:"#ffb467",O:"#ffe074",S:"#81e8a7",T:"#c38aff",Z:"#ff7d9c" };
const shapes = { I:[[0,0,0,0],[1,1,1,1],[0,0,0,0],[0,0,0,0]],J:[[1,0,0],[1,1,1],[0,0,0]],L:[[0,0,1],[1,1,1],[0,0,0]],O:[[1,1],[1,1]],S:[[0,1,1],[1,1,0],[0,0,0]],T:[[0,1,0],[1,1,1],[0,0,0]],Z:[[1,1,0],[0,1,1],[0,0,0]] };
let binding = null, state = null, stopped = false, sending = false, snapshotAt = 0;
let commands = [], pending = null, controls = { left:false, right:false, fire:false };
let taps = { left:false, right:false, fire:false };
let nextSendAt = 0, lastControls = "", heartbeatAt = 0;
const spaceView = new SpaceFrameClient();
const spaceCoopView = new SpaceCoopClient();
function currentSpaceView(){return state?.ships?spaceCoopView:spaceView;}
const stage = document.createElement("section");
stage.className = "arcade-stage";
stage.setAttribute("aria-label", kind === "tetris" ? "Tetris Versus board" : "Space Invasion board");
const status = document.createElement("div"); status.className = "arcade-status";
const scroll = document.createElement("div"); scroll.className = "arcade-scroll";
const world = document.createElement("div"); world.className = "arcade-world";
const canvas = document.createElement("canvas"); canvas.width=960; canvas.height=640; canvas.tabIndex=0;
canvas.setAttribute("aria-label", kind === "tetris" ? "Tetris boards. Arrow keys move and rotate, Z rotates back, Space drops." : "Space Invasion. Left and right move, Space fires.");
const ctx = canvas.getContext("2d");
world.append(canvas); scroll.append(world);
const tetrisView=kind === "tetris" ? new TetrisBoardView(document) : null;
if(tetrisView){canvas.hidden=true;world.append(tetrisView.root);}
const spaceScore=document.createElement("span"),spaceLevel=document.createElement("span"),spacePlayerLabel=document.createElement("span");
const spaceScoreTwo=document.createElement("span"),spacePlayerLabelTwo=document.createElement("span");
if(kind === "space") {
  const hud=document.createElement("div");hud.className="arcade-space-hud";
  spaceScore.className="arcade-space-score";spaceLevel.className="arcade-space-level";
  spacePlayerLabel.className="arcade-space-player";
  spaceScoreTwo.className="arcade-space-score-two";spacePlayerLabelTwo.className="arcade-space-player arcade-space-player-two";
  spaceScoreTwo.hidden=true;spacePlayerLabelTwo.hidden=true;
  hud.append(spaceScore,spaceLevel,spacePlayerLabel,spaceScoreTwo,spacePlayerLabelTwo);world.append(hud);
}
const buttons = document.createElement("div"); buttons.className="arcade-buttons";
const summary = document.createElement("p"); summary.className="arcade-summary";
const guide = document.createElement("p"); guide.className="arcade-control-guide";
guide.textContent=kind === "tetris"
  ? "Keyboard: Left/Right move, Down soft-drops, Up rotates, Z rotates back, Space drops. Click the board first. Mouse / touch: use the labeled buttons below. Tetris Versus needs two players."
  : "Move with A/D or Left/Right. Fire with Space, W, Up, or Enter.";
canvas.setAttribute("aria-describedby", "arcade-control-guide"); guide.id="arcade-control-guide";
const history = document.createElement("div"); history.className="arcade-history"; history.hidden=true;
if(kind === "space"){
  world.append(guide);
  const boardFrame=document.createElement("div");boardFrame.className="arcade-space-frame";
  boardFrame.append(scroll);stage.append(boardFrame,buttons,summary,history);
}
else stage.append(status,guide,scroll,buttons,summary,history);
if(kind === "tetris") {
  const info=document.createElement("details"); info.className="arcade-help";
  const label=document.createElement("summary"); label.textContent="Level (i): how speed increases";
  const text=document.createElement("p");
  text.textContent="Level 1 drops one automatic row every 1.2 seconds. Every 10 cleared lines increases your level. Level 20 is the maximum, at one row every 0.32 seconds. Opening this help does not pause play.";
  info.append(label,text); stage.insertBefore(info,scroll);
}
const enemy=new Image(),ship=new Image(),shipTwo=new Image();
const enemySprite=document.createElement("canvas"); enemySprite.width=44; enemySprite.height=32;
if(kind === "space") {
  // Preserve the original silhouette/alpha, but not its invisible black ink.
  enemy.onload=()=>{
    const sprite=enemySprite.getContext("2d");
    sprite.drawImage(enemy,0,0,44,32);
    sprite.globalCompositeOperation="source-in";
    sprite.fillStyle="#a7f58a"; sprite.fillRect(0,0,44,32);
    sprite.globalCompositeOperation="source-over";
  };
  enemy.src=new URL("../spaceinvasion/enemy.png",import.meta.url).href;
  ship.src=new URL("../spaceinvasion/player1.png",import.meta.url).href;
  shipTwo.src=new URL("../spaceinvasion/player2.png",import.meta.url).href;
}
function savePreferences() { try { localStorage.setItem(storageKey,JSON.stringify(preferences)); } catch {} resize(); }
function resize() {
  if(!stage.isConnected) return;
  const available=kind === "space" ? Math.max(0,stage.clientWidth-32) : (scroll.clientWidth || stage.clientWidth);
  const viewport=availableGameViewportHeight(window);
  const inset=scroll.getBoundingClientRect().top+window.scrollY;
  const reserve=buttons.offsetHeight+summary.offsetHeight+(history.hidden?0:history.offsetHeight)+40;
  const height=Math.max(320,viewport-inset-reserve);
  const desired=1080*preferences.size/100;
  const width=Math.max(480,Math.min(desired,available,preferences.fit ? height*(kind === "tetris" ? 1080/638 : 1.5) : Infinity));
  const value=`${Math.round(width)}px`;
  if(world.style.width!==value)world.style.width=value;
  if(kind === "space")stage.style.setProperty("--arcade-board-width",value);
  if(tetrisView)world.style.setProperty("--tetris-scale",String(Math.round(width)/1080));
}
function live() {
  const session=binding?.session;
  const pause=session?.state?._framework?.pause?.mode || "running";
  return !stopped && state?.phase === "playing" && !state.completed && session?.status === "active"
    && ["master","player"].includes(session.viewerRole) && ["running","proposed"].includes(pause)
    && !session.state?._framework?.serviceInterruption?.active
    && !session.state?._framework?.players?.[String(binding.currentUserId())]?.disconnected;
}
function command(value) {
  if(!live() || commands.length>=12 || commands.includes("drop") || pending?.commands?.includes("drop")) return;
  commands.push(value); nextSendAt=Math.min(nextSendAt,performance.now()); paint();
}
function engage(value) {
  if(kind === "space" && !controls[value])currentSpaceView().press(value);
  controls[value]=true; taps[value]=true;
  nextSendAt=Math.min(nextSendAt,performance.now());
}
function release() { controls={left:false,right:false,fire:false}; taps={left:false,right:false,fire:false}; }
for(const [label,value] of (kind === "tetris" ? [["Left","left"],["Right","right"],["Rotate","cw"],["Rotate back","ccw"],["Down","down"],["Drop","drop"]] : [["Left","left"],["Fire","fire"],["Right","right"]])) {
  const button=document.createElement("button"); button.type="button"; button.textContent=label;
  if(kind === "tetris") button.addEventListener("click",()=>command(value));
  else {
    button.addEventListener("pointerdown",event=>{ if(!live())return; button.setPointerCapture(event.pointerId); engage(value); });
    for(const type of ["pointerup","pointercancel","lostpointercapture"]) button.addEventListener(type,()=>{controls[value]=false;});
    button.addEventListener("keydown",event=>{if([" ","Enter"].includes(event.key)){event.preventDefault();if(live())engage(value);}});
    button.addEventListener("keyup",()=>{controls[value]=false;});
    button.addEventListener("blur",()=>{controls[value]=false;});
  }
  buttons.append(button);
}
canvas.addEventListener("pointerdown",()=>canvas.focus({preventScroll:true}));
document.addEventListener("keydown",event=>{
  // Match the original game-wide keys without stealing settings or button input.
  if(event.defaultPrevented || event.target.closest?.("input,textarea,select,button,[contenteditable]:not([contenteditable='false'])"))return;
  const key=event.key.toLowerCase();
  const map=kind === "tetris" ? {arrowleft:"left",arrowright:"right",arrowdown:"down",arrowup:"cw",z:"ccw"," ":"drop"} : {arrowleft:"left",a:"left",arrowright:"right",d:"right",arrowup:"fire",w:"fire",enter:"fire"," ":"fire"};
  if(!map[key]) return;
  event.preventDefault();
  if(kind === "tetris") { if(!event.repeat || ["left","right","down"].includes(map[key])) command(map[key]); }
  else if(live()) engage(map[key]);
});
document.addEventListener("keyup",event=>{
  if(kind !== "space")return;
  const map={arrowleft:"left",a:"left",arrowright:"right",d:"right",arrowup:"fire",w:"fire",enter:"fire"," ":"fire"};
  const key=map[event.key.toLowerCase()]; if(key){event.preventDefault();controls[key]=false;}
});
document.addEventListener("focusin",event=>{if(!stage.contains(event.target))release();});
window.addEventListener("blur",release);
document.addEventListener("visibilitychange",()=>{if(document.hidden)release();});
function matrix(piece) {
  let m=shapes[piece.kind].map(row=>row.slice());
  for(let i=0;i<piece.rotation;i++) m=m[0].map((_,c)=>m.map(row=>row[c]).reverse());
  return m;
}
function fits(board,piece) {
  return matrix(piece).every((row,r)=>row.every((cell,c)=>!cell || (piece.row+r>=0 && piece.row+r<20 && piece.col+c>=0 && piece.col+c<10 && !board.cells[piece.row+r][piece.col+c])));
}
function predictedPiece(board,id) {
  let piece={...board.active};
  if(id !== binding.currentUserId())return piece;
  for(const input of [...(pending?.commands || []),...commands]) {
    const next={...piece};
    if(input === "left")next.col--;
    if(input === "right")next.col++;
    if(input === "down")next.row++;
    if(input === "cw")next.rotation=(next.rotation+1)%4;
    if(input === "ccw")next.rotation=(next.rotation+3)%4;
    if(input === "drop") { while(fits(board,{...next,row:next.row+1}))next.row++; }
    if(fits(board,next))piece=next;
  }
  return piece;
}
function tile(x,y,color,size=28,alpha=1) {
  ctx.globalAlpha=alpha; ctx.fillStyle=color; ctx.fillRect(x+1,y+1,size-2,size-2);
  ctx.fillStyle="rgba(255,255,255,.3)";ctx.fillRect(x+2,y+2,size-4,3);
  ctx.globalAlpha=1;
}
function drawPiece(piece,x,y,alpha=1,size=28) {
  matrix(piece).forEach((row,r)=>row.forEach((cell,c)=>{if(cell)tile(x+(piece.col+c)*size,y+(piece.row+r)*size,colors[piece.kind],size,alpha);}));
}
function drawTetris() {
  tetrisView.render(state,binding,predictedPiece,matrix,fits);
}
function aliens() {
  const elapsed=state.levelElapsedMs+(live()?Math.min(250,performance.now()-snapshotAt):0);
  const step=Math.floor(Math.max(0,elapsed)/Math.max(150,480-(state.level-1)*42));
  const cycle=Math.floor(step/4),phase=step%4;
  const dx=(cycle%2===0?phase:3-phase)*44,dy=cycle*(16+Math.min(14,(state.level-1)*2));
  const sy=Math.max(48,80-Math.min(22,(state.level-1)*4));
  const result=[];
  for(let r=0;r<Math.min(7,5+Math.floor((state.level-1)/2));r++)for(let c=0;c<11;c++){
    if(!state.kills?.[String(r*11+c)])result.push({x:80+c*62+dx,y:sy+r*48+dy});
  }
  return result;
}
function drawSpace() {
  for(let i=0;i<75;i++){ctx.fillStyle=i%3?"#678796":"#b8d9e8";ctx.fillRect((i*173)%960,(i*89)%640,i%3?1:2,2);}
  if(!state.ship)return;
  const view=currentSpaceView().frame(performance.now(),controls,live());
  // Move the whole playfield together so the ship clears the existing footer
  // without separating visible projectiles from their collision coordinates.
  ctx.save();ctx.translate(0,-16);
  for(const alien of view.aliens) {
    if(enemy.complete && enemy.naturalWidth)ctx.drawImage(enemySprite,alien.x,alien.y,44,32);
    else {ctx.fillStyle="#83dc8d";ctx.fillRect(alien.x,alien.y,44,32);}
  }
  const boardWidth=canvas.clientWidth || 960;
  const ships=state.ships?state.turnOrder.map(id=>view.ships?.[id] || state.ships[id]):[{x:view.x}];
  ships.forEach((player,index)=>{
    const label=index?spacePlayerLabelTwo:spacePlayerLabel,sprite=index?shipTwo:ship;
    const labelWidth=label.offsetWidth || 110,x=player.x;
    label.style.left=`${Math.max(labelWidth/2+4,Math.min(boardWidth-labelWidth/2-4,x*boardWidth/960))}px`;
    label.style.top=`${(572+(index&&Math.abs(player.x-ships[0].x)<120?15:0))/640*100}%`;
    if(sprite.complete&&sprite.naturalWidth)ctx.drawImage(sprite,x-32,546,64,48);
    else {ctx.fillStyle=index?"#ff5a6b":"#75cfff";ctx.fillRect(x-32,546,64,48);}
  });
  for(const orb of view.orbs){
    const glow=ctx.createRadialGradient(orb.x,orb.y,1,orb.x,orb.y,8);
    glow.addColorStop(0,state.ships&&Number(orb.by)===Number(state.turnOrder[1])?"#ff5a6b":"#49b3ff");glow.addColorStop(1,"rgba(255,255,255,0)");
    ctx.fillStyle=glow;ctx.beginPath();ctx.arc(orb.x,orb.y,8,0,Math.PI*2);ctx.fill();
  }
  ctx.restore();
  ctx.strokeStyle="#718b9f";ctx.beginPath();ctx.moveTo(0,602);ctx.lineTo(960,602);ctx.stroke();
}
function paint() {
  if(tetrisView){if(stage.isConnected&&binding)drawTetris();return;}
  if(!ctx || !stage.isConnected)return;
  ctx.clearRect(0,0,960,640);ctx.fillStyle="#080e1b";ctx.fillRect(0,0,960,640);
  if(!state?.arcadeKind){ctx.fillStyle="#dcecff";ctx.font="24px Georgia";ctx.fillText(kind === "tetris" ? "Waiting for two players to join and be ready" : "Waiting for the game to start",kind === "tetris" ? 225 : 275,300);return;}
  if(kind === "tetris")drawTetris();else drawSpace();
  if(state.completed){ctx.fillStyle="rgba(0,0,0,.65)";ctx.fillRect(0,270,960,80);ctx.fillStyle="#ffe0a0";ctx.font="bold 28px Georgia";ctx.textAlign="center";ctx.fillText("Game complete",480,320);ctx.textAlign="left";}
}
async function pump() {
  // performAction owns the live busy guard. binding.busy is only a render-time
  // snapshot and may remain true after the request has already finished.
  if(!live() || sending || !stage.isConnected || performance.now()<nextSendAt)return;
  const now=performance.now(),id=binding.currentUserId();
  if(pending && Number(state.inputSequences?.[String(id)] || 0)>=pending.sequence)pending=null;
  if(!pending) {
    const sequence=Number(state.inputSequences?.[String(id)] || 0)+1;
    if(kind === "tetris" && commands.length)pending={sequence,commands:commands.splice(0,12)};
    if(kind === "space") {
      const batch=currentSpaceView().batch();
      if(batch)pending={sequence,...batch};
      // A throttled or resumed frame can have no input batch. Keep the bounded
      // coordinator heartbeat below so a stale prediction can recover.
    }
  }
  const coordinator=Number(state.turnOrder?.find(player=>!state._framework?.players?.[String(player)]?.disconnected))===id;
  if(!pending && (!coordinator || now-heartbeatAt<300))return;
  sending=true;heartbeatAt=now;
  try {
    const ok=await binding.performAction(pending?"arcade-input":"arcade-tick",pending || {});
    // A lost response can still have committed. The refreshed sequence is proof.
    if(pending && Number(state?.inputSequences?.[String(id)] || 0)>=pending.sequence)pending=null;
    nextSendAt=performance.now()+(ok?(kind === "space"?0:100):650);
  } finally {sending=false;}
}
const timer=setInterval(()=>{pump().catch(()=>{nextSendAt=performance.now()+1000;});},50);
let frame=0;
function animate(){if(stopped)return;if(!document.hidden)paint();frame=requestAnimationFrame(animate);}
frame=requestAnimationFrame(animate);
window.addEventListener("resize",resize);
const observer=new ResizeObserver(resize);observer.observe(scroll);
let roomStage=null, previousRoomGutter="", previousRoomGutterPriority="";
try {
  roomStage=window.frameElement?.closest(".room-stage");
  if(roomStage){
    previousRoomGutter=roomStage.style.getPropertyValue("scrollbar-gutter");
    previousRoomGutterPriority=roomStage.style.getPropertyPriority("scrollbar-gutter");
    roomStage.style.setProperty("scrollbar-gutter","stable");
    observer.observe(roomStage);
  }
} catch {}
window.addEventListener("pagehide",()=>{
  stopped=true;clearInterval(timer);cancelAnimationFrame(frame);observer.disconnect();release();
  if(roomStage && roomStage.style.getPropertyValue("scrollbar-gutter")==="stable"){
    if(previousRoomGutter)roomStage.style.setProperty("scrollbar-gutter",previousRoomGutter,previousRoomGutterPriority);
    else roomStage.style.removeProperty("scrollbar-gutter");
  }
},{once:true});
window.CoreChatArcade={
  render(next) {
    const changed=next.session?.publicId!==binding?.session?.publicId;
    binding=next;
    if(changed){pending=null;commands=[];release();lastControls="";spaceView.reset();spaceCoopView.reset();}
    if(state!==next.session?.state){state=next.session?.state;snapshotAt=performance.now();}
    if(kind === "space" && state?.ship){spaceCoopView.setActor(next.currentUserId());currentSpaceView().accept(state,next.session.stateVersion,performance.now());}
    if(kind === "space" && pending?.frames && pending.frameStart < state.elapsedMs
        && Number(state.inputSequences?.[String(next.currentUserId())] || 0)<pending.sequence)pending=null;
    if(pending && Number(state?.inputSequences?.[String(next.currentUserId())] || 0)>=pending.sequence)pending=null;
    if(!live()){release();commands=[];pending=null;}
    for(const button of buttons.children)button.disabled=!live();
    status.replaceChildren();
    if(kind === "tetris")status.textContent=state?.completed?"Game complete":next.session?.status==="paused"||["paused","resuming"].includes(state?._framework?.pause?.mode)?"Game paused":"Game in progress";
    else if(state?.ship){
      spaceScore.textContent=`P1: ${state.ship.score}`;
      spaceLevel.textContent=`Level ${state.level} · ${state.ships?"Co-op fire active":"Solo play"}`;
      spacePlayerLabel.textContent=Number(state.turnOrder[0])===Number(next.currentUserId())?"Player 1 (You)":"Player 1";
      spaceScoreTwo.hidden=!state.ships;spacePlayerLabelTwo.hidden=!state.ships;
      if(state.ships){spaceScoreTwo.textContent=`P2: ${state.ships[state.turnOrder[1]].score}`;spacePlayerLabelTwo.textContent=Number(state.turnOrder[1])===Number(next.currentUserId())?"Player 2 (You)":"Player 2";}
    }
    if(kind === "tetris" && !state?.arcadeKind)status.textContent="Waiting for an opponent. Tetris Versus requires two players.";
    summary.textContent=state?.completed?"Use the shared controls to leave or start a rematch.":
      kind === "tetris" && !state?.arcadeKind?"The game starts after two players have joined and accepted the rules. Controls stay disabled while waiting.":
      ["paused","resuming"].includes(state?._framework?.pause?.mode)?"Paused. Use the shared resume controls when you are ready.":
      "Click the board to use keyboard controls. Pause and leave are in the shared game controls.";
    history.hidden=!state?.runHistory;
    if(state?.runHistory){const h=state.runHistory;history.textContent=`Run history: ${h.score} points${h.playerScores?" total ("+state.turnOrder.map((id,i)=>`P${i+1}: ${h.playerScores[id]}`).join(", ")+")":""}; highest level ${h.highestLevel}; ${Math.floor(h.elapsedMs/1000)} seconds; ${h.terminalReason}. Practice only.`;}
    requestAnimationFrame(resize);paint();return stage;
  },
  appendOptions({grid,make}) {
    const row=make("div","game-setting arcade-option");
    const label=make("label","","Game size ");
    const select=make("select");select.setAttribute("aria-label","Arcade game size");
    for(const value of [50,75,100,125,150]){const option=make("option","",`${value}%`);option.value=String(value);option.selected=value===preferences.size;select.append(option);}
    select.addEventListener("change",()=>{preferences.size=Number(select.value);savePreferences();});label.append(select);
    const toggle=make("button","",`Fit available height: ${preferences.fit?"On":"Off"}`);toggle.type="button";toggle.setAttribute("aria-pressed",String(preferences.fit));
    toggle.addEventListener("click",()=>{preferences.fit=!preferences.fit;toggle.textContent=`Fit available height: ${preferences.fit?"On":"Off"}`;toggle.setAttribute("aria-pressed",String(preferences.fit));savePreferences();});
    row.append(label,toggle);grid.append(row);
  },
};
