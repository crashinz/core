import fs from 'node:fs';import assert from 'node:assert/strict';
import {simulate,shotFeatures,routes,choose,evaluate,ENGINE} from '../games/eight-ball/bot-engine.js';
const cases=JSON.parse(fs.readFileSync(new URL('./pool_advanced_fixtures.json',import.meta.url)));
const signature=events=>events.map(e=>[e.type,...(e.type==='ball'?[e.a,e.b].sort((a,b)=>a-b):[e.a,e.b??null]),e.pocket??null]);
let runs=0,maxDrift=0;
for(const f of cases){let base;
 for(const dt of [1/60,1/120,1/240]){
  const o=simulate(f.state,f.shot,dt);assert.deepEqual(signature(o.events),signature(f.events.map(([type,a,b,pocket])=>({type,a,b,pocket}))),f.id+' contact order');
  assert(!o.balls.find(b=>b.n===0).pocket,f.id+' no scratch');base??=o;
  for(const b of o.balls){const a=base.balls.find(a=>a.n===b.n);maxDrift=Math.max(maxDrift,Math.hypot(a.x-b.x,a.y-b.y));}runs++;
 }
}
assert(maxDrift<.01);assert.equal(cases.length,23);
const open=cases.find(f=>f.id==='four-rail-bank').state;
const generated=routes(open,'expert');for(const kind of ['3-cushion-bank','4-cushion-bank','2-cushion-kick','3-cushion-kick'])assert(generated.some(r=>r.kind===kind),kind+' generated from geometry');
const simple=structuredClone(cases.find(f=>f.id==='two-in-pocket').state);simple.balls=simple.balls.filter(b=>b.n!==2);simple.balls.find(b=>b.n===1).n=8;simple.settings.pocketCalls='eight';
const d=choose({engine:ENGINE,difficulty:'expert',position:simple,positionKey:'simple-called-eight'}),o=simulate(simple,d.payload),v=evaluate(simple,d.payload,o),features=shotFeatures(o);
assert(v.winning&&!v.foul&&d.payload.calledBall===8&&d.payload.calledPocket>=0);assert(!features.railsByBall[8]&&features.maxRepeatedContact===1,'Simple winning pot preferred to a fancy route');
assert(d.elapsedMs<8500&&d.simulations<=5000&&d.robustness?.successful===4,'Simple win finishes early with full robustness checks');
console.log(JSON.stringify({status:'PASS',cases:cases.length,physicsRuns:runs,maxDrift,generated:generated.length,simpleWin:d.reason,robustness:d.robustness}));
