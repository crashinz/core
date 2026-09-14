/* CoreChat rules bridge. Both rows run from home point 0 to bar 24;
 * row 1 is on move. GNU's evaluator never supplies game dice. */
(function(root){
  const copy=b=>b.map(r=>r.slice()),key=b=>b.flat().join(',');
  function moves(b,d){
    const out=[],a=b[1],opp=b[0],bar=a[24]>0;
    const home=!bar&&a.slice(6,24).every(n=>n===0);
    for(let from=bar?24:0;from<25;from++){
      if(!a[from]||(!bar&&from===24))continue;
      const to=from-d;
      if(to>=0){if(opp[23-to]<2)out.push({from,to,die:d});}
      else if(home&&(to===-1||a.slice(from+1,24).every(n=>n===0)))out.push({from,to:-1,die:d});
    }return out;
  }
  function step(b,m){const n=copy(b);n[1][m.from]--;if(m.to>=0){if(n[0][23-m.to]===1){n[0][23-m.to]=0;n[0][24]++;}n[1][m.to]++;}return n;}
  function turns(board,dice,rule='standard'){
    const finals=new Map(),seen=new Set();
    function visit(b,ds,path){
      const visitKey=key(b)+'/'+ds.slice().sort().join('');if(seen.has(visitKey))return;seen.add(visitKey);
      let any=false;
      for(const d of new Set(ds))for(const m of moves(b,d)){
        any=true;const rest=ds.slice();rest.splice(rest.indexOf(d),1);visit(step(b,m),rest,[...path,m]);
      }
      if(!any){const k=key(b),old=finals.get(k);if(!old||old.path.length<path.length||old.path.reduce((s,m)=>s+m.die,0)<path.reduce((s,m)=>s+m.die,0))finals.set(k,{board:b,path});}
    }
    visit(board,dice,[]);let list=[...finals.values()];
    if(rule==='standard'){
      const max=Math.max(...list.map(x=>x.path.length));list=list.filter(x=>x.path.length===max);
      if(max===1&&dice.length===2&&dice[0]!==dice[1]){const d=Math.max(...list.map(x=>x.path[0].die));list=list.filter(x=>x.path[0].die===d);}
    }
    return list;
  }
  function unpack(s,actor){
    const ids=s.turnOrder.map(Number),side=ids.indexOf(Number(actor));if(side<0)throw Error('Invalid actor');
    const other=ids[1-side],b=[Array(25).fill(0),Array(25).fill(0)];
    for(let p=0;p<24;p++){b[1][side===0?23-p:p]=Number(s.points[actor][p]);b[0][side===0?p:23-p]=Number(s.points[other][p]);}
    b[1][24]=Number(s.bar[actor]);b[0][24]=Number(s.bar[other]);
    for(const r of b)if(r.some(n=>!Number.isInteger(n)||n<0)||r.reduce((s,n)=>s+n,0)>15)throw Error('Invalid board');
    return {board:b,side};
  }
  function payload(m,side){return {from:m.from===24?'bar':side===0?23-m.from:m.from,die:m.die};}
  root.BackgammonRules={copy,key,moves,step,turns,unpack,payload};
})(globalThis);
