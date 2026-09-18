import {createRollingBallArt} from './ball-art.js';
// Solo practice editing and named libraries. The server validates every loaded table.
export function installPractice(api){
 const $=s=>document.querySelector(s),canvas=api.canvas,holes=[[82,77],[596,66],[1110,77],[82,598],[596,609],[1110,598]],radius=15.5;
 let draft=null,selected=0,drag=null,version='',baseVersion='',saved=[],shared=[],canManage=false,csrf='',busy=false,ready=false,request=0,lastDeleted=null;
 const previewArt=createRollingBallArt(api.colors,{fixedOrientation:true});
 const live=()=>api.network(),solo=()=>live()?.state?.settings?.tableMode==='solo';
 const key=()=>`corechat.pool.setups.v1.${live()?.currentUserId||0}`;
 const message=t=>$('#practiceEditStatus').textContent=t,saveMessage=t=>$('#practiceSaveStatus').textContent=t;
 const simple=balls=>balls.filter(b=>!b.pocket).map(({n,x,y})=>({n,x,y}));
 function valid(b,list){return Number.isInteger(b.n)&&b.n>=0&&b.n<=15&&Number.isFinite(b.x)&&Number.isFinite(b.y)&&b.x>=83+radius&&b.x<=1110-radius&&b.y>=85+radius&&b.y<=590-radius&&!holes.some(h=>Math.hypot(b.x-h[0],b.y-h[1])<radius+25)&&!list.some(o=>o!==b&&o.n!==b.n&&Math.hypot(b.x-o.x,b.y-o.y)<radius*2+.003);}
 function allValid(list){return Array.isArray(list)&&list.every(b=>b&&typeof b==='object')&&list.length<=16&&list.some(b=>b.n===0)&&new Set(list.map(b=>b.n)).size===list.length&&list.every(b=>valid(b,list));}
 function current(){return draft||simple(api.balls());}
 function refreshEditor(){
  $('#practiceEditor').hidden=!draft;for(const id of ['editPractice','openPracticeSaves','practiceRack','practiceRackNine','rewindPractice','replayPractice','replaySlow'])if(draft)$('#'+id).disabled=true;if(!draft)return;
  for(const b of $('#practicePalette').children){const n=Number(b.dataset.ball);b.hidden=live()?.state?.settings?.variant==='nine-ball'&&n>9;b.setAttribute('aria-pressed',String(n===selected));b.classList.toggle('on-table',draft.some(o=>o.n===n));}
  $('#removePracticeBall').disabled=selected===0||!draft.some(b=>b.n===selected);$('#applyPractice').disabled=!allValid(draft)||!api.canAct();api.update();
 }
 function cancel(){draft=null;drag=null;$('#practiceEditor').hidden=true;api.restore();api.update();}
 function begin(){if(!solo()||!api.canAct())return;api.close();api.cancelGesture();draft=simple(api.balls());if(!draft.some(b=>b.n===0))draft.push({n:0,x:330,y:337});selected=0;baseVersion=version;message('Choose a ball or drag one already on the table.');refreshEditor();$('#practiceEditor').scrollIntoView({block:'start'});}
 function move(n,p){if(!draft||!api.canAct())return;let b=draft.find(b=>b.n===n),next={n,x:p.x,y:p.y};if(!valid(next,draft)){message('Keep balls apart and clear of pockets.');return;}
  if(b)Object.assign(b,next);else draft.push(next);message(`Ball ${n===0?'cue':n} placed.`);refreshEditor();}
 function remove(){if(!draft||selected===0)return;draft=draft.filter(b=>b.n!==selected);refreshEditor();message('Ball removed.');}
 for(let n=0;n<=15;n++){
  const b=document.createElement('button');b.type='button';b.dataset.ball=String(n);b.setAttribute('aria-label',n?'Place ball '+n:'Place cue ball');b.style.setProperty('--ball',api.colors[n]);const label=document.createElement('span');label.textContent=n?String(n):'●';b.append(label);$('#practicePalette').append(b);
  b.addEventListener('click',()=>{selected=n;refreshEditor();message('Click on the cloth to place '+(n?'ball '+n:'the cue ball')+'.');});
  b.addEventListener('pointerdown',e=>{if(e.button!==0||!draft)return;selected=n;drag={id:e.pointerId,n,palette:true};b.setPointerCapture(e.pointerId);refreshEditor();});
  b.addEventListener('pointerup',e=>{if(drag?.id!==e.pointerId)return;const p=api.point(e);if(p.x>=83&&p.x<=1110&&p.y>=85&&p.y<=590)move(n,p);drag=null;});
  b.addEventListener('pointercancel',()=>drag=null);
 }
 canvas.addEventListener('pointerdown',e=>{if(!draft)return;e.stopImmediatePropagation();e.preventDefault();if(e.button!==0||!api.canAct())return;const p=api.point(e),b=draft.find(o=>Math.hypot(o.x-p.x,o.y-p.y)<radius+5);if(b)selected=b.n;else move(selected,p);drag={id:e.pointerId,n:selected};canvas.setPointerCapture(e.pointerId);canvas.focus({preventScroll:true});refreshEditor();},true);
 canvas.addEventListener('pointermove',e=>{if(!draft)return;e.stopImmediatePropagation();if(drag?.id===e.pointerId&&!drag.palette)move(drag.n,api.point(e));},true);
 for(const ev of ['pointerup','pointercancel','lostpointercapture'])canvas.addEventListener(ev,e=>{if(!draft)return;e.stopImmediatePropagation();drag=null;},true);
 canvas.addEventListener('keydown',e=>{if(!draft)return;e.stopImmediatePropagation();if(e.key==='Escape'){e.preventDefault();cancel();}else if(e.key==='Delete'||e.key==='Backspace'){e.preventDefault();remove();}else{const d={ArrowLeft:[-1,0],ArrowRight:[1,0],ArrowUp:[0,-1],ArrowDown:[0,1]}[e.key],b=draft.find(b=>b.n===selected);if(d&&b){e.preventDefault();move(selected,{x:b.x+d[0]*(e.shiftKey?1:5),y:b.y+d[1]*(e.shiftKey?1:5)});}}},true);
 $('#editPractice').onclick=begin;$('#cancelPractice').onclick=cancel;$('#removePracticeBall').onclick=remove;
 $('#clearPractice').onclick=()=>{draft=[{n:0,x:330,y:337}];selected=0;message('Object balls cleared.');refreshEditor();};
 $('#applyPractice').onclick=()=>{if(!draft||!allValid(draft)||!api.canAct())return;api.post('practice-edit',{balls:draft,setup:api.setup()});message('Saving practice table...');};
 $('#rewindPractice').onclick=()=>{if(api.canAct())api.post('practice-rewind',{});};
 $('#practiceRack').onclick=()=>{if(api.canAct())api.post('rack',{variant:'eight-ball'});};
 $('#practiceRackNine').onclick=()=>{if(api.canAct())api.post('rack',{variant:'nine-ball'});};
 function readLegacy(){try{const a=JSON.parse(localStorage.getItem(key())||'[]');return Array.isArray(a)?a.filter(v=>v&&typeof v.id==='string'&&typeof v.name==='string'&&v.setup):[];}catch{return [];}}
 function selectedEntry(){const [scope,id]=$('#practiceSavedList').value.split(':');return {scope,entry:(scope==='shared'?shared:saved).find(v=>v.id===id)};}
 function list(preferred=$('#practiceSavedList').value){
  const select=$('#practiceSavedList'),deleted=$('#showDeletedSetups').checked;select.replaceChildren();
  for(const [scope,title,items]of [['mine','My account setups',saved],['shared','Shared by administrator',shared]]){
   const g=document.createElement('optgroup');g.label=title;
   for(const v of items){if(!!v.deletedAt!==deleted)continue;const o=document.createElement('option');o.value=scope+':'+v.id;o.textContent=v.name;g.append(o);}select.append(g);
  }
  if([...select.options].some(o=>o.value===preferred))select.value=preferred;selection();
 }
 function preview(){
  const {entry}=selectedEntry(),c=$('#practiceSetupPreview'),g=c.getContext('2d');g.clearRect(0,0,c.width,c.height);c.hidden=!entry;if(!entry||!allValid(entry.balls))return;
  g.save();g.scale(c.width/1200,c.height/680);g.fillStyle='#192b3c';g.fillRect(55,45,1080,595);g.fillStyle='#256c71';g.fillRect(83,85,1027,505);
  g.fillStyle='#070d12';for(const [x,y]of holes){g.beginPath();g.arc(x,y,27,0,Math.PI*2);g.fill();}
  const cue=entry.balls.find(b=>b.n===0),setup=entry.setup;
  if(cue){g.beginPath();g.moveTo(cue.x,cue.y);g.lineTo(cue.x+Math.cos(setup.angle)*120,cue.y+Math.sin(setup.angle)*120);g.strokeStyle='#eed8a1';g.lineWidth=3;g.stroke();}
  for(const b of entry.balls){g.save();g.translate(b.x,b.y);previewArt.draw(g,b,radius,0);g.restore();}g.restore();
  c.setAttribute('aria-label',entry.name+': '+entry.balls.length+' balls. Power '+Math.round(setup.power)+' percent.');
 }
 function selection(){const {entry}=selectedEntry();if(entry)$('#practiceSetupName').value=entry.name;preview();buttons();}
 function testingValues(entry){
  const panel=$('#practiceTestingValues'),values=$('#practiceTestingNumbers');
  panel.hidden=!ready||!canManage||!entry;values.replaceChildren();if(panel.hidden)return;
  const s=entry.setup,percent=v=>String(Number((Math.abs(v)*100).toPrecision(15))),axis=(v,negative,positive)=>v===0?'Center (0)':`${percent(v)}% ${v<0?negative:positive} (${v})`;
  for(const [label,value]of [
   ['Power',`${s.power}%`],
   ['Horizontal spin',axis(s.spin.x,'left','right')],
   ['Vertical spin',axis(s.spin.y,'topspin','backspin')],
   ['Aim',`${((s.angle*180/Math.PI%360+360)%360).toFixed(6)}°`],
   ['Exact angle',`${s.angle} radians`],
  ]){const term=document.createElement('dt'),description=document.createElement('dd');term.textContent=label;description.textContent=value;values.append(term,description);}
 }
 function buttons(){
  const {scope,entry}=selectedEntry(),canChange=ready&&!!entry&&(scope!=='shared'||canManage),deleted=!!entry?.deletedAt;
  testingValues(entry);
  $('#loadPracticeSetup').disabled=busy||!ready||!entry||deleted||!api.canAct();
  for(const id of ['renamePracticeSetup','deletePracticeSetup'])$('#'+id).disabled=busy||!canChange||deleted;
  $('#updatePracticeSetup').disabled=busy||!canChange||deleted||!api.canAct();
  $('#restorePracticeSetup').hidden=!$('#showDeletedSetups').checked;$('#restorePracticeSetup').disabled=busy||!canChange||!deleted;
  $('#undoPracticeDelete').hidden=!lastDeleted;$('#undoPracticeDelete').disabled=busy||!ready;
  $('#savePracticeSetup').disabled=busy||!ready||!api.canAct();$('#publishPracticeSetup').hidden=!canManage;$('#publishPracticeSetup').disabled=busy||!ready||!api.canAct();$('#reloadPracticeSetups').disabled=busy;
 }
 function applyCatalog(data){saved=data.mine||[];shared=data.setups||[];canManage=data.canManage===true;}
 async function call(body){if(live()?.review)throw new Error("Account libraries are unavailable in isolated review examples.");const r=await fetch(new URL('../../api/pool_practice_setups.php',import.meta.url),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});const data=await r.json();if(!r.ok)throw new Error(data.error||'Could not update setup.');return data;}
 async function catalog(){if(live()?.review)return;
  if(busy)return;const id=++request;busy=true;ready=false;buttons();
  try{
   const r=await fetch(new URL('../../api/pool_practice_setups.php',import.meta.url),{credentials:'same-origin',cache:'no-store'}),data=await r.json();if(!r.ok)throw new Error(data.error||'Saved setups unavailable.');if(id!==request)return;
   applyCatalog(data);csrf=data.csrf;let imported=0,failed=0;
   for(const v of readLegacy()){
    if(saved.some(s=>s.legacyId===v.id))continue;
    try{applyCatalog(await call({scope:'mine',action:'import',legacyId:v.id,name:v.name,balls:v.balls,setup:v.setup}));imported++;}catch{failed++;}
   }
   ready=true;list();saveMessage(failed?'Some browser setups could not be imported. Their browser backups are unchanged; Reload list retries.':imported?'Browser setups copied to your account. Browser backups are retained.':'');
  }catch(error){saveMessage(error.message+' Use Reload list to retry.');}finally{busy=false;buttons();}
 }
 async function change(body){
  if(busy||!ready)return;busy=true;buttons();
  try{
   const data=await call(body);applyCatalog(data);
   if(body.action==='delete'){lastDeleted={scope:body.scope,id:body.id};$('#showDeletedSetups').checked=false;}
   if(body.action==='restore'){if(lastDeleted?.id===body.id&&lastDeleted?.scope===body.scope)lastDeleted=null;$('#showDeletedSetups').checked=false;}
   list(data.scope+':'+data.id);saveMessage(body.action==='delete'?'Setup deleted. Use Undo delete or Show deleted setups to restore it.':body.action==='restore'?'Setup restored.':body.action==='update'?'Selected setup updated.':'Setup saved to '+(body.scope==='shared'?'the shared library.':'your account.'));
  }catch(error){saveMessage(error.message);}finally{busy=false;buttons();}
 }
 function name(){const n=$('#practiceSetupName').value.trim();if(!n){saveMessage('Enter a name for the setup.');return null;}return n.slice(0,60);}
 function snapshot(){const balls=current();if(!allValid(balls)){saveMessage('Use Edit table to place the cue ball and separate any overlapping balls before saving.');return null;}return {balls:structuredClone(balls),setup:api.setup(),ballRadius:15.5,variant:live()?.state?.settings?.variant||'eight-ball'};}
 function save(scope){const n=name(),data=snapshot();if((scope==='shared'?shared:saved).some(v=>!v.deletedAt&&v.name.toLowerCase()===n?.toLowerCase())){saveMessage('That name already exists. Select it and use Update selected, or choose another name.');return;}if(n&&data)void change({scope,action:'save',name:n,...data});}
 function modify(action){const {scope,entry}=selectedEntry();if(!entry)return;let content={};if(action==='rename'||action==='update'){const n=name();if(!n)return;content.name=n;}if(action==='update'){const data=snapshot();if(!data)return;Object.assign(content,data);}void change({scope,action,id:entry.id,revision:entry.revision,...content});}
 $('#openPracticeSaves').onclick=()=>{saveMessage('Loading setups...');api.open($('#practiceSavesPanel'),$('#openPracticeSaves'));void catalog();};
 $('#practiceSavedList').onchange=selection;$('#showDeletedSetups').onchange=()=>list();$('#reloadPracticeSetups').onclick=()=>void catalog();
 $('#savePracticeSetup').onclick=()=>save('mine');$('#publishPracticeSetup').onclick=()=>save('shared');
 $('#loadPracticeSetup').onclick=()=>{const {entry}=selectedEntry();if(!entry||entry.deletedAt||!api.canAct())return;if(!allValid(entry.balls)){saveMessage('This setup has invalid or overlapping ball positions.');return;}api.post('practice-edit',{balls:entry.balls,setup:entry.setup,variant:entry.variant||'eight-ball'});api.close();};
 for(const [id,action]of [['renamePracticeSetup','rename'],['updatePracticeSetup','update'],['deletePracticeSetup','delete'],['restorePracticeSetup','restore']])$('#'+id).onclick=()=>modify(action);
 $('#undoPracticeDelete').onclick=()=>{if(!lastDeleted)return;const {scope,id}=lastDeleted,entry=(scope==='shared'?shared:saved).find(v=>v.id===id);if(entry?.deletedAt)void change({scope,action:'restore',id,revision:entry.revision});};
 const openLibrary=$('#openPracticeSaves').onclick;$('#openPracticeSaves').onclick=(event)=>{if(!live()?.review)return openLibrary(event);};
 return {get editing(){return !!draft;},get balls(){return draft;},get selected(){return selected;},update(){const n=live(),next=n?.sessionId+':'+n?.state?.sequence;version=next;const available=solo()&&n?.state?.turnOrder?.includes(n.currentUserId);$('#practiceControls').hidden=!available;$('#openPracticeSaves').hidden=!!n?.review;if(draft&&(!available||baseVersion!==next)){cancel();}for(const id of ['editPractice','openPracticeSaves','practiceRack','practiceRackNine'])$('#'+id).disabled=!api.canAct()||!!draft;$('#rewindPractice').disabled=!api.canAct()||!!draft||!n?.state?.practiceUndo;if(draft)refreshEditor();buttons();}};
}
