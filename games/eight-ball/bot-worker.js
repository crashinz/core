import {choose,ENGINE} from './bot-engine.js?v=b8a08933f16b';
self.onmessage=({data})=>{try{if(data?.type!=='search')return;self.postMessage({type:'result',...choose(data.task)});}catch(error){self.postMessage({type:'error',engine:ENGINE,error:String(error)});}};
