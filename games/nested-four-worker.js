import {chooseMove,ENGINE} from './nested-four-engine.js?v=20966cef92c2';
self.onmessage=({data})=>{if(data?.type!=='search')return;try{self.postMessage({type:'result',...chooseMove(data.task)});}catch{self.postMessage({type:'error',engine:ENGINE});}};
