import assert from 'node:assert/strict';
import fs from 'node:fs';
import {choose,ENGINE,profiles} from '../games/eight-ball/bot-engine.js';
const fixtures=JSON.parse(fs.readFileSync(new URL('./pool_advanced_fixtures.json',import.meta.url)));
const task={engine:ENGINE,difficulty:'expert',position:fixtures.find(f=>f.id==='four-rail-bank').state,positionKey:'deadline-check'};
const original=Object.getOwnPropertyDescriptor(globalThis,'performance');
let reads=0,result;
try{
 // Emulate a busy device. Once the deadline has passed, keep the clock there
 // so bookkeeping after search does not create an artificial overrun.
 Object.defineProperty(globalThis,'performance',{configurable:true,value:{now:()=>Math.min(reads++*100,30001)}});
 result=choose(task);
}finally{Object.defineProperty(globalThis,'performance',original);}
assert(profiles.expert.ms===30000);
assert(result.elapsedMs>7000&&result.elapsedMs<=30001,'Expert uses the longer deadline and stops there');
assert(result.simulations>0&&result.simulations<5000,'Time deadline stops search before its work ceiling');
assert(result.action==='shot'&&Number.isFinite(result.payload.angle),'Deadline returns its best shot');
assert(profiles.easy.ms===600&&profiles.normal.ms===1800,'Other difficulties retain their time budgets');
console.log(JSON.stringify({status:'PASS',simulatedElapsedMs:result.elapsedMs,simulations:result.simulations}));
