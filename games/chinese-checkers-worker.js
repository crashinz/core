import {chooseMove,ENGINE} from './chinese-checkers-engine.js?v=e09d678cc45f';
self.onmessage=({data})=>{
  if(data?.type!=='search')return;
  try{self.postMessage({type:'result',...chooseMove(data.task)});}
  catch{self.postMessage({type:'error',engine:ENGINE});}
};
