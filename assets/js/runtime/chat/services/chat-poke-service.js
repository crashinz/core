// Small private notices, delivered by the ordinary authenticated event transport.
export class ChatPokeService {
    #context; #enabled = false; #seen = new Set(); #started = 0; #timer;
    destroy(){clearTimeout(this.#timer);this.#context=null;this.#seen.clear();}
    configure(context) {
        this.#context=context;
        this.#started=context.now()/1000;
        const toggle=context.document.getElementById('poke-toggle');
        if(toggle)toggle.onclick=async()=>{toggle.disabled=true;try{const result=await context.request('preferences',{enabled:!this.#enabled});this.#enabled=result.enabled;this.#sync();}catch(e){context.warn(e.message);}finally{toggle.disabled=false;}};
        context.request('state').then(result=>{this.#enabled=result.enabled;this.#sync();}).catch(()=>{if(toggle){toggle.textContent='Unavailable';toggle.disabled=true;}});
    }
    #sync(){const toggle=this.#context.document.getElementById('poke-toggle');if(toggle){toggle.textContent=this.#enabled?'On':'Off';toggle.setAttribute('aria-pressed',String(this.#enabled));toggle.disabled=false;}}
    async send(participant){try{await this.#context.request('send',{target_participant_id:Number(participant.id)});this.#notice(`Poke sent to ${participant.display_name}.`);}catch(e){this.#context.warn(e.message);}}
    #notice(text){const doc=this.#context.document;let notice=doc.getElementById('poke-status');if(!notice){notice=doc.createElement('p');notice.id='poke-status';notice.setAttribute('role','status');doc.querySelector('.chat-pane')?.append(notice);}notice.textContent=text;clearTimeout(this.#timer);this.#timer=setTimeout(()=>notice.remove(),10000);}
    receive(payload){
        const c=this.#context;if(!c||!this.#enabled||Number(payload.recipient_id)!==Number(c.userId)||payload.at<this.#started-5||c.now()/1000-payload.at>60||!payload.id||this.#seen.has(payload.id)||c.muted(payload.sender_id))return;
        this.#seen.add(payload.id);if(this.#seen.size>128)this.#seen.delete(this.#seen.values().next().value);
        this.#notice(`${payload.sender_name} poked you.`);
        c.document.dispatchEvent(new CustomEvent('corechat:chat-attention',{detail:{action:'unread',channel:`poke:${payload.sender_id}`,senderName:payload.sender_name}}));
    }
}
