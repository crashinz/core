// One shared administrator control for all first-party game renderers.
export function botAvatarOptions(csrf) {
  const root=document.createElement('details');root.hidden=true;
  // Review seats are synthetic; global avatar editing does not belong here.
  if ((new URLSearchParams(location.search).get('game_session_id') || '').startsWith('review-')) return root;
  const title=document.createElement('summary');title.textContent='Bot avatars (administrator)';root.append(title);
  const endpoint=new URL('../api/game_bot_avatars.php',import.meta.url);
  fetch(endpoint,{credentials:'same-origin'}).then(async response=>{
    if(!response.ok)return;const data=await response.json();if(!data.canManage)return;root.hidden=false;
    const note=document.createElement('p');note.textContent='Choose community avatars for bot seats. Saved choices apply to all games until changed.';root.append(note);
    const seat=document.createElement('select');seat.setAttribute('aria-label','Bot seat');for(const row of data.seats)seat.add(new Option('Bot seat '+row.seat,row.seat));root.append(seat);
    const reset=document.createElement('button');reset.type='button';reset.textContent='Use default avatar';root.append(reset);
    const status=document.createElement('p');status.role='status';root.append(status);
    const gallery=document.createElement('div');gallery.style.cssText='display:flex;flex-wrap:wrap;gap:8px;max-height:300px;overflow:auto';root.append(gallery);
    const more=document.createElement('button');more.type='button';more.textContent='Load community avatars';root.append(more);let page=1;
    async function save(avatar){const form=new FormData();form.set('_csrf',csrf);form.set('seat',seat.value);form.set('avatar',avatar);try{const response=await fetch(endpoint,{method:'POST',body:form,credentials:'same-origin'});const reply=await response.json();if(!response.ok)throw new Error(reply.error||'Unable to save avatar');status.textContent='Saved. Bot portraits update with the next game refresh.';}catch(error){status.textContent=error.message;}}
    reset.onclick=()=>save('');
    more.onclick=async()=>{more.disabled=true;try{const url=new URL('../api/avatar_library.php',import.meta.url);url.search=new URLSearchParams({view:'community',kind:'avatar',page:String(page)});const response=await fetch(url);const reply=await response.json();if(!response.ok)throw new Error(reply.error||'Unable to load library');for(const item of reply.items){const button=document.createElement('button');button.type='button';button.title=item.name;button.setAttribute('aria-label','Use '+item.name);const image=document.createElement('img');image.src=new URL(`../api/avatar_library.php?action=image&id=${encodeURIComponent(item.id)}`,import.meta.url);image.alt=item.name;image.width=64;image.height=72;image.style.objectFit='contain';button.append(image);button.onclick=()=>save(item.id);gallery.append(button);}page++;more.hidden=!reply.hasMore;more.textContent='More avatars';}catch(error){status.textContent=error.message;}finally{more.disabled=false;}};
  }).catch(()=>{});
  return root;
}
