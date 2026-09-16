import {chooseMove,ENGINE} from './acey-deucy-engine.js?v=cd68c0b07811';
onmessage=({data})=>{try{if(data.type!=='search'||data.task.engine!==ENGINE)throw Error('Invalid engine request');postMessage({type:'result',...chooseMove(data.task)});}catch(e){postMessage({type:'error',engine:ENGINE,error:String(e)});}};
