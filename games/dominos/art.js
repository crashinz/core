(function(){let uid=0;
const dots=[[],[[0,0]],[[-1,-1],[1,1]],[[-1,-1],[0,0],[1,1]],[[-1,-1],[1,-1],[-1,1],[1,1]],[[-1,-1],[1,-1],[0,0],[-1,1],[1,1]],[[-1,-1],[1,-1],[-1,0],[1,0],[-1,1],[1,1]]];
function art(a,b,horizontal=false){
 const id='d'+(++uid);
 const pips=(n,y)=>dots[n].map(([x,z])=>`<circle cx="${27+x*12}" cy="${y+z*12}" r="5.05" fill="#ffffff" opacity=".65" transform="translate(0 .65)"/><circle cx="${27+x*12}" cy="${y+z*12}" r="4.65" fill="url(#${id}p)"/><ellipse cx="${26+x*12}" cy="${y-1.7+z*12}" rx="1.7" ry=".8" fill="#aeb0ae" opacity=".34"/>`).join('');
 const content=`<defs><linearGradient id="${id}f" x2="1" y2="1"><stop stop-color="#fffffc"/><stop offset=".38" stop-color="#f5f0df"/><stop offset=".7" stop-color="#fffef7"/><stop offset="1" stop-color="#d5ccaf"/></linearGradient><linearGradient id="${id}s" x2="0" y2="1"><stop stop-color="#d7ccb1"/><stop offset=".55" stop-color="#b1a488"/><stop offset="1" stop-color="#7f725a"/></linearGradient><radialGradient id="${id}p" cx=".65" cy=".7"><stop stop-color="#343a3c"/><stop offset=".7" stop-color="#151a1e"/><stop offset="1" stop-color="#030607"/></radialGradient></defs><rect x="1" y="5" width="52" height="101" rx="6" fill="url(#${id}s)" stroke="#73664e" stroke-width=".7"/><rect x="1" y="1" width="52" height="100" rx="6" fill="url(#${id}f)" stroke="#e7dfca"/><rect x="2.7" y="2.6" width="48.6" height="96.8" rx="4.9" fill="none" stroke="#fff" stroke-opacity=".75"/><path d="M5 51H49" stroke="#948873" stroke-width=".85"/><path d="M5 52H49" stroke="#fff"/><circle cx="27" cy="51.5" r="2.4" fill="#876a37"/><circle cx="26.6" cy="50.9" r="1.65" fill="#d6b77a"/>${pips(a,26)}${pips(b,76)}`;
 return horizontal?`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 106 54"><g transform="translate(0 54) rotate(-90)">${content}</g></svg>`:`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 54 106">${content}</svg>`;
}

window.DominosArtwork=art;})();
