// Original Tetris presentation, fed only by the framework's board projection.
export class TetrisBoardView {
  constructor(document) {
    const make=(tag,cls,text)=>{const e=document.createElement(tag);e.className=cls;if(text!==undefined)e.textContent=text;return e;};
    this.root=make('div','arcade-tetris-versus');
    this.root.tabIndex=0;
    this.root.setAttribute('aria-label','Tetris boards. Arrow keys move and rotate, Z rotates back, Space drops.');
    this.root.addEventListener('pointerdown',()=>this.root.focus({preventScroll:true}));
    this.sides=[1,2].map(number=>{
      const side=make('section','arcade-tetris-side');
      const label=make('div','arcade-tetris-player');
      const name=make('span','',`Player ${number}`),role=make('span','arcade-tetris-role');label.append(name,role);
      const wrap=make('div','arcade-tetris-wrap'),board=make('div','arcade-tetris-board');
      const cells=Array.from({length:200},()=>{const cell=make('div','arcade-tetris-cell');const tile=make('div','arcade-tetris-tile');tile.hidden=true;cell.append(tile);board.append(cell);return {cell,tile,key:''};});
      const hud=make('div','arcade-tetris-hud'),nextPanel=make('div','arcade-tetris-panel');
      const next=make('div','arcade-tetris-next');
      const previews=Array.from({length:16},()=>{const cell=make('div','arcade-tetris-cell');const tile=make('div','arcade-tetris-tile');tile.hidden=true;cell.append(tile);next.append(cell);return {cell,tile,key:''};});
      nextPanel.append(make('h4','','Next'),next);
      const stats=make('div','arcade-tetris-panel');stats.append(make('h4','','Stats'));
      const values={};
      for(const [key,title] of [['score','Score'],['lines','Lines'],['level','Level']]){const row=make('div','arcade-tetris-kv');values[key]=make('strong','',key==='level'?'1':'0');row.append(make('span','',title),values[key]);stats.append(row);}
      const notice=make('div','arcade-tetris-notice');notice.hidden=true;
      hud.append(nextPanel,stats,notice);wrap.append(board,hud);side.append(label,wrap);this.root.append(side);
      return {side,role,cells,previews,values,notice};
    });
  }
  render(state,binding,predictedPiece,matrix,fits) {
    this.sides.forEach((side,index)=>{
      const id=state?.turnOrder?.[index],board=state?.boards?.[String(id)];
      side.side.hidden=!!state?.boards&&id===undefined;
      side.role.textContent=id===undefined?'Waiting':Number(id)===Number(binding.currentUserId())?'You':'Opponent';
      const occupied=Array(200).fill(''),ghosts=new Set();
      if(board){
        board.cells.forEach((row,r)=>row.forEach((cell,c)=>{if(cell)occupied[r*10+c]=cell;}));
        if(board.active&&board.alive){
          const piece=predictedPiece(board,Number(id)),ghost={...piece};
          while(fits(board,{...ghost,row:ghost.row+1}))ghost.row++;
          matrix(ghost).forEach((row,r)=>row.forEach((cell,c)=>{if(cell)ghosts.add((ghost.row+r)*10+ghost.col+c);}));
          matrix(piece).forEach((row,r)=>row.forEach((cell,c)=>{const y=piece.row+r,x=piece.col+c;if(cell&&y>=0&&y<20&&x>=0&&x<10)occupied[y*10+x]=piece.kind;}));
        }
      }
      side.cells.forEach((entry,i)=>this.updateCell(entry,occupied[i],ghosts.has(i)&&!occupied[i]));
      const next=board?.queue?.[0],shape=next?matrix({kind:next,rotation:0}):[];
      side.previews.forEach((entry,i)=>this.updateCell(entry,shape[Math.floor(i/4)]?.[i%4]?next:'',false));
      for(const key of ['score','lines','level'])side.values[key].textContent=String(board?.[key]??(key==='level'?1:0));
      side.notice.hidden=!board||board.alive;
      side.notice.textContent='Top out';
    });
  }
  updateCell(entry,kind,ghost) {
    const key=`${kind}:${ghost}`;if(entry.key===key)return;entry.key=key;
    entry.cell.className='arcade-tetris-cell'+(ghost?' arcade-tetris-ghost':'');
    entry.tile.hidden=!kind;entry.tile.className='arcade-tetris-tile'+(kind?` arcade-tetris-${kind}`:'');
  }
}
