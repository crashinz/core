import {choose,ENGINE} from './bot-engine.js?v=b8a08933f16b';
self.onmessage=({data})=>{try{if(data?.type!=='search')return;self.postMessage({type:'result',...choose(data.task,{ms:Math.min(data.task.difficulty==='expert'?30000:data.task.difficulty==='easy'?600:1800,data.task.searchBudgetMs??30000)})});}catch(error){self.postMessage({type:'error',engine:ENGINE,error:String(error)});}};
