import {choose,simulate,evaluate,safetyQuality,ENGINE,profiles} from '../games/eight-ball/bot-engine.js';
import fs from 'node:fs';import assert from 'node:assert/strict';
const fixtures=JSON.parse(fs.readFileSync(new URL('./pool_bot_defense.json',import.meta.url)));const results=[];
for(const f of fixtures){const s=f.state,d=choose({engine:ENGINE,difficulty:'expert',position:s,positionKey:f.name});const path=simulate(s,d.payload),v=evaluate(s,d.payload,path);
const cue=s.balls.find(b=>b.n===0),target=s.balls.find(b=>b.n===1);const plain={angle:Math.atan2(target.y-cue.y,target.x-cue.x),power:64,spinX:0,spinY:0};const pv=evaluate(s,plain,simulate(s,plain));
assert(!v.foul&&!v.losing);
// Stronger search may now discover a legal pot in a former safety-only case.
// Require deliberate defense when no continuing/winning shot was selected.
if(v.continues||v.winning)assert(!d.expected.defensive);else assert(d.expected.defensive&&d.reason.startsWith('safety-'));
assert(v.score>pv.score+30);assert(d.elapsedMs<31000&&d.simulations<=5000,'Expert stays within its search allowance');results.push({name:f.name,score:v.score,plainScore:pv.score,reason:d.reason,elapsedMs:d.elapsedMs,simulations:d.simulations});}
assert(!profiles.easy.position&&profiles.expert.position&&profiles.expert.banks===4&&profiles.expert.combinations);
console.log(JSON.stringify({status:'PASS',defensiveCases:results}));
