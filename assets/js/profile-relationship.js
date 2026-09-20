// Private request controls share the normal authenticated chat/account session.
(() => {
  const base=document.body?.dataset.appBase||'', csrf=document.body?.dataset.csrf||'';
  const views=new Set();let state=null,signature='',notice=null,openProfile=null,busy=false;
  async function request(body,search) {
    const response=await fetch(base+'/api/profile_relationship.php'+(search===undefined?'':'?search='+encodeURIComponent(search)),body?{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({...body,_csrf:csrf})}:{credentials:'same-origin',cache:'no-store'});
    const data=await response.json();if(!response.ok||data.error)throw Error(data.error||'Relationship request unavailable.');return data;
  }
  function node(tag,text){const el=document.createElement(tag);if(text)el.textContent=text;return el;}
  function button(text,handler){const el=node('button',text);el.type='button';el.className='btn';el.addEventListener('click',handler);return el;}
  function member(m){return `${m.displayName} (@${m.username})`;}
  function sync(next){const nextSignature=JSON.stringify(next);state=next;if(signature!==nextSignature){signature=nextSignature;for(const view of views){if(view.isConnected)render(view);else views.delete(view);}}if(notice){notice.hidden=!state.incoming.length;notice.querySelector('span').textContent=`You have ${state.incoming.length===1?'a relationship request':state.incoming.length+' relationship requests'} to consider in your profile. Only you can see this notice.`;}}
  async function refresh(){const data=await request();sync(data.relationship);}
  function render(container) {
    container.replaceChildren();if(container.id==='account-profile-relationship')container.append(node('strong','In a relationship with'));
    const status=node('p');status.className='minor';status.setAttribute('role','status');
    async function act(action,data={}){
      if(busy)return;busy=true;status.textContent='Saving…';container.querySelectorAll('button').forEach(b=>b.disabled=true);
      try{const result=await request({action,...data});sync(result.relationship);render(container);}
      catch(e){status.textContent=e.message;container.querySelectorAll('button').forEach(b=>b.disabled=false);}
      finally{busy=false;}
    }
    if(state.partner){container.append(node('p',member(state.partner.member)),button('Remove relationship',()=>act('remove',{id:state.partner.id})),node('p','Either person can remove this relationship; it will clear both profiles.'));}
    else {
      container.append(node('p','Not in a relationship'));
      if(!state.outgoing.length){
        const label=node('label','Choose a username');const input=document.createElement('input');input.type='text';input.autocomplete='off';input.maxLength=32;input.placeholder='Type a username';input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-expanded','false');input.style.cssText='display:block;width:100%;box-sizing:border-box;';label.append(input);container.append(label);
        const list=node('div');list.setAttribute('role','listbox');list.id='relationship-options-'+Math.random().toString(36).slice(2);input.setAttribute('aria-controls',list.id);list.style.cssText='display:grid;gap:4px;';container.append(list);
        let timer,sequence=0;
        function close(){list.replaceChildren();input.setAttribute('aria-expanded','false');}
        input.addEventListener('input',()=>{clearTimeout(timer);const seq=++sequence;close();if(!input.value.trim())return;timer=setTimeout(async()=>{try{const data=await request(null,input.value.trim());if(seq!==sequence||!container.isConnected)return;close();for(const m of data.members){const option=button(member(m),()=>{input.value=m.username;++sequence;close();input.focus();});option.setAttribute('role','option');list.append(option);}input.setAttribute('aria-expanded',String(list.children.length>0));}catch(e){if(seq===sequence)status.textContent=e.message;}},250);});
        input.addEventListener('keydown',e=>{if(e.key==='ArrowDown'&&list.firstElementChild){e.preventDefault();list.firstElementChild.focus();}if(e.key==='Escape'){++sequence;close();}if(e.key==='Enter'){e.preventDefault();void act('request',{username:input.value.trim()});}});
        list.addEventListener('keydown',e=>{if(e.key==='Escape'){close();input.focus();}else if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();const next=e.key==='ArrowDown'?e.target.nextElementSibling:e.target.previousElementSibling;(next||input).focus();}});
        container.append(button('Send relationship request',()=>act('request',{username:input.value.trim()})),node('p','The other member must approve before either profile changes.'));
      }
    }
    for(const item of state.outgoing){const row=node('div');row.append(node('p','Awaiting approval from '+member(item.member)),button('Cancel request',()=>act('cancel',{id:item.id})));container.append(row);}
    for(const item of state.incoming){const row=node('div');row.append(node('p',member(item.member)+' wants to be in a relationship with you.'));if(!state.partner)row.append(button('Accept request',()=>act('accept',{id:item.id})));row.append(button('Decline request',()=>act('decline',{id:item.id})));container.append(row);}
    container.append(status);
  }
  function mount(container){views.add(container);container.textContent='Loading relationship…';if(state)render(container);refresh().catch(e=>container.textContent=e.message);}
  function startNotice(onOpen){openProfile=onOpen;if(notice)return;const pane=document.querySelector('.chat-pane');if(!pane)return;notice=node('div');notice.className='profile-relationship-notice';notice.hidden=true;notice.style.cssText='flex:0 0 auto;padding:8px 12px;border-bottom:1px solid #7188ab66;';notice.setAttribute('role','status');notice.append(node('span'),button('Review in my profile',()=>openProfile?.()));pane.insertBefore(notice,document.getElementById('messages')||pane.firstChild);refresh().catch(()=>{});setInterval(()=>{if(!document.hidden)refresh().catch(()=>{});},15000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh().catch(()=>{});});}
  window.CoreChatProfileRelationship={mount,startNotice};
  const editor=document.getElementById('account-profile-relationship');if(editor)mount(editor);
})();
