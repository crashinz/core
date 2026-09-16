const packPanel = document.getElementById('reference-pack');
if (packPanel) {
  const status = packPanel.querySelector('[role="status"]');
  const buttons = [...packPanel.querySelectorAll('button')];
  const form = document.getElementById('reference-pack-upload');
  const progress = packPanel.querySelector('progress');
  const names = {'backgammon-first-party':'Backgammon','acey-deucy':'Acey Deucy',checkers:'Checkers',chess:'Chess',battleship:'Battleship',spades:'Spades','five-dice':'Five Dice'};
  let busy = false;
  const request = async (action, fields = {}) => {
    const body = new FormData(); body.set('_csrf', form.elements._csrf.value); body.set('action',action);
    for (const [key,value] of Object.entries(fields)) body.set(key,value);
    let response;
    for(let attempt=0;attempt<3;attempt++){
      try{response=await fetch('api/game_review_pack.php',{method:'POST',body,credentials:'same-origin'});break;}
      catch(error){if(attempt===2 || !['chunk','finish'].includes(action))throw error;}
    }
    let data;
    try {data=await response.json();} catch {throw new Error('The server could not accept the upload. Check its upload limits and available storage.');}
    if(!response.ok || data.error) throw new Error(data.error || 'Reference operation failed.');
    return data;
  };
  const describe = item => item.changed ? `${item.changed} changed; restore their backup` : item.missing ? `${item.ready} installed, ${item.missing} missing` : 'Installed and verified';
  const refresh = async () => {
    const response=await fetch('api/game_review_pack.php',{credentials:'same-origin',cache:'no-store'});
    const data=await response.json(); if(!response.ok || data.error) throw new Error(data.error || 'Unable to check references.');
    const list=document.getElementById('reference-pack-status'); list.replaceChildren();
    for(const [name,item] of [['Built-in and shared files',data.base],...Object.entries(data.classic).map(([key,value])=>[names[key]+' Classic',value])]) {
      const line=document.createElement('li');line.textContent=`${name}: ${describe(item)}`;list.append(line);
    }
  };
  const run = async task => {
    if(busy)return;busy=true;buttons.forEach(b=>b.disabled=true);
    try {await task();await refresh();}catch(error){status.textContent=error.message;}
    finally{busy=false;buttons.forEach(b=>b.disabled=false);progress.hidden=true;}
  };
  document.getElementById('reference-pack-check').addEventListener('click',()=>run(async()=>{status.textContent='Checking installed reference files…';await refresh();status.textContent='Reference check complete.';}));
  form.addEventListener('submit',event=>{
    event.preventDefault();run(async()=>{
      const file=form.elements.pack_file.files[0];if(!file)throw new Error('Choose the downloaded reference ZIP.');
      if(file.size!==Number(form.dataset.bytes))throw new Error('Choose the linked reference pack v1 ZIP.');
      const {token}=await request('begin',{size:file.size});progress.hidden=false;progress.max=file.size;progress.value=0;
      try{
        const chunkSize=Number(form.dataset.chunk);
        for(let offset=0;offset<file.size;){
          status.textContent=`Uploading reference pack: ${Math.floor(offset/file.size*100)}%`;
          const result=await request('chunk',{token,offset,chunk:file.slice(offset,offset+chunkSize)});
          offset=result.offset;progress.value=offset;
        }
        status.textContent='Verifying and installing reference files…';
        const result=await request('finish',{token});
        status.textContent=`Reference pack installed: ${result.added} files added, ${result.reused} reused. Use Copy installed Classic media below for Classic comparisons. Reload the example when ready.`;
      }catch(error){try{await request('cancel',{token});}catch{/* Expired or already finalized upload. */}throw error;}
    });
  });
  document.getElementById('reference-pack-classic').addEventListener('click',()=>run(async()=>{
    const reports=[];
    for(const [game,name] of Object.entries(names)){
      status.textContent=`Checking ${name} Classic media…`;
      const result=await request('hydrate',{game});
      reports.push(`${name}: ${result.copied} copied, ${result.reused} reused, ${result.missing.length} unavailable, ${result.different.length} different, ${result.conflicts.length} reference conflicts`);
    }
    status.textContent=reports.join('\n')+'\nOnly exact matches were copied. Missing or different media affects its Classic comparison only. Reload the example when ready.';
  }));
}
