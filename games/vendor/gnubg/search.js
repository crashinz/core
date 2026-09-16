(function(root){
 const R=root.BackgammonRules;
 function search(M,task){
  const started=performance.now(),{board,side}=R.unpack(task.position,task.actor),rule=task.position.moveUseRule;
  if(!['easy','normal','expert'].includes(task.difficulty)||!['standard','legacy-ocx'].includes(rule))throw Error('Invalid task');
  const budget=Math.min(1800,Math.max(50,Number(task.moveTimeMs)||0));
  let evaluations=0;
  function value(b){
   M.HEAPU32.set(b.flat(),M._core_board()/4);
   if(M._core_eval(0)!==0)throw Error('Evaluation unavailable');
   const p=Array.from(M.HEAPF32.subarray(M._core_result()/4,M._core_result()/4+5));
   if(p.some(x=>!Number.isFinite(x)||x < -1e-6 || x > 1+1e-6))throw Error('Invalid evaluation');
   for(let i=0;i<5;i++)p[i]=Math.max(0,Math.min(1,p[i]));
   evaluations++;return 2*p[0]-1+p[1]+p[2]-p[3]-p[4];
  }
  const all=R.turns(board,task.position.remainingDice,rule);
  if(!all.length||!all[0].path.length)throw Error('No move available');
  const ranked=all.map(t=>({...t,score:-value([t.board[1],t.board[0]])})).sort((a,b)=>b.score-a.score);
  let best=ranked[0],completeReplies=0;
  if(task.difficulty==='easy'){
   // Stable modest evaluation noise makes Easy forgiving without changing dice.
   let seed=2166136261;for(const c of task.positionKey)seed=Math.imul(seed^c.charCodeAt(0),16777619)>>>0;
   let highest=-Infinity;
   for(const t of ranked){seed=(Math.imul(seed,1664525)+1013904223)>>>0;const score=t.score+(seed/4294967296-.5)*.30;if(score>highest){highest=score;best=t;}}
  }else if(task.difficulty==='expert'&&ranked.length>1){
   const candidates=ranked.slice(0,3),scores=[];let complete=true;
   // Compare whole replies for every possible roll. A partial roll set never
   // replaces the complete static ranking, avoiding time-order bias.
   for(const t of candidates){
    if(t.board[1].every(n=>n===0)){scores.push({t,score:t.score});continue;}
    let total=0;
    for(let a=1;a<=6&&complete;a++)for(let b=1;b<=a;b++){
     if(performance.now()-started>=budget){complete=false;break;}
     const replies=R.turns([t.board[1],t.board[0]],a===b?[a,a,a,a]:[a,b],rule);
     let worst=Infinity;
     for(const reply of replies)worst=Math.min(worst,value([reply.board[1],reply.board[0]]));
     total+=worst*(a===b?1:2);
    }
    if(!complete)break;scores.push({t,score:total/36});
   }
   if(complete){scores.sort((a,b)=>b.score-a.score);best=scores[0].t;completeReplies=21;}
  }
  return {...R.payload(best.path[0],side),elapsedMs:Math.round(performance.now()-started),evaluations,candidates:ranked.length,replyRolls:completeReplies};
 }
 root.BackgammonSearch={search};
})(globalThis);
