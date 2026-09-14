/* CoreChat Marcher bridge: authoritative state and selected movement rules. */
(function(root){
  'use strict';
  function encode(s){
    const masks={a:0n,b:0n,A:0n,B:0n};
    if(!Array.isArray(s.board)||s.board.length!==8)throw Error('Invalid board');
    s.board.forEach((row,r)=>{
      if(!Array.isArray(row)||row.length!==8)throw Error('Invalid board row');
      row.forEach((p,c)=>{if(p!==null){if(!(p in masks)||(r+c)%2!==1)throw Error('Invalid piece');masks[p]|=1n<<BigInt(r*8+c);}});
    });
    const side=s.sideAssignments?.[String(s.turnOrder?.[s.turnIndex])];
    if(side!=='a'&&side!=='b')throw Error('Missing side assignment');
    return [masks.b,masks.a,masks.B,masks.A,side==='a'?2:1];
  }
  function prepare(M,s){
    // Invalidate any previous prepared context, including when validation fails.
    M._wasm_context_reset(-1,0);
    if(s.completed||s.drawOfferBy!=null)throw Error('Game is not awaiting a move');
    const rules=(s.settings.backwardMovement?1:0)|(s.settings.backwardCapture?2:0)|(s.settings.flyingKings?4:0);
    const automatic=s.settings.drawHandling==='automatic';
    if(!automatic&&s.settings.drawHandling!=='proposal-only')throw Error('Unknown draw mode');
    if(!Number.isInteger(s.quietKingPlies)||s.quietKingPlies<0||s.quietKingPlies>1000000)throw Error('Invalid quiet counter');
    const a=encode(s), forced=s.forcedFrom===null?-1:s.forcedFrom?.[0]*8+s.forcedFrom?.[1];
    if(!Number.isInteger(forced)||forced< -1||forced>63)throw Error('Invalid forced square');
    if(automatic&&s.positionHistoryComplete!==true)throw Error('Complete saved history required; start a new round');
    const entries=Object.entries(s.positionCounts||{}), snapshots=s.positionSnapshots||{};
    if(automatic&&(!entries.length||entries.length>256||Object.keys(snapshots).length!==entries.length))throw Error('Incomplete saved positions');
    const history=automatic?entries.map(([key,count])=>{
      const p=snapshots[key];
      if(!p||p.forcedFrom!==null||!Number.isInteger(count)||count<1||count>2)throw Error('Invalid saved position count');
      return [...encode({...s,...p}),count];
    }):[];
    if(automatic&&forced<0&&!history.some(h=>a.every((v,i)=>v===h[i])))throw Error('Current position missing from history');
    if(automatic&&forced<0&&s.quietKingPlies>=80)throw Error('Automatic draw already due');
    if(M._wasm_context_reset(automatic?1:0,s.quietKingPlies)!==1)throw Error('Context reset failed');
    for(const h of history)if(M._wasm_context_add(...h)!==1)throw Error('History import failed');
    if(M._wasm_variant_configure(rules)!==1)throw Error('Rule configuration failed');
    return {a,forced,rules};
  }
  root.MarcherContext={encode,prepare};
  if(typeof module!=='undefined')module.exports=root.MarcherContext;
})(globalThis);
