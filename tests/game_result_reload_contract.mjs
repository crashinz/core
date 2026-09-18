import assert from 'node:assert/strict';
import {GameLifecycleService} from '../assets/js/runtime/game/services/game-lifecycle-service.js';

const results=[];
async function check(name,fn){try{await fn();results.push({name,pass:true});}catch(e){results.push({name,pass:false,error:e.stack});}}
function game(type='eight-ball',id='last',status='active'){
 return {lobby_code:id,game_type:type,players:[{participant_id:12,user_id:22}],framework:{publicId:id,gameKey:type,sourceRoomSessionId:1,stateVersion:1,status,viewerRole:'master',viewerMembershipStatus:'active',members:[{participantId:12,userId:22,role:'master',membershipStatus:'active'}]}};
}
function fixture(storage=new Map(),config={},pathname='/core/chatroom.php'){
 const state={games:[],recentGames:[],chat:'room',posts:0};
 const host={location:{pathname},sessionStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},addEventListener(){},removeEventListener(){},setTimeout(){return 1;},clearTimeout(){}};
 const context={window:host,document:{hidden:false},getConfig:()=>({sessionId:1,myParticipantId:12,myUserId:22,...config}),fetchGames:async()=>({games:state.games,recentGames:state.recentGames}),fetchFramework:async q=>state.recentGames.find(g=>g.lobby_code===new URLSearchParams(q).get('game_session_id'))?.framework,apiPost:async()=>{state.posts++;return{ok:true};},gameChatKey:id=>'game:'+id,activeChatKey:()=>state.chat,switchChat:v=>state.chat=v};
 const service=new GameLifecycleService({},new Proxy({},{get:()=>()=>{}}));service.configure(context);
 return {state,host,service,storage,context};
}
async function seed(type='eight-ball'){
 const f=fixture();f.state.games=[game(type)];await f.service.loadGames();assert.equal(f.service.getActiveGame()?.lobby_code,'last');f.service.destroy();return f.storage;
}
for(const type of ['eight-ball','chess','checkers','backgammon','acey-deucy','spades','hearts','five-dice','dominos'])await check(type+' restores completed game and chat after reload',async()=>{
 const f=fixture(await seed(type));f.state.recentGames=[game(type,'last','completed')];await f.service.loadGames();assert.equal(f.service.getActiveGame()?.lobby_code,'last');assert.equal(f.state.chat,'game:last');assert.equal(f.state.posts,0);f.service.destroy();
});
for(const status of ['forfeited','abandoned'])await check(status+' restores result',async()=>{const f=fixture(await seed());f.state.recentGames=[game('eight-ball','last',status)];await f.service.loadGames();assert.equal(f.service.getActiveGame()?.lobby_code,'last');f.service.destroy();});
await check('other active room games do not displace the tab result',async()=>{const f=fixture(await seed());f.state.games=[game('chess','other')];f.state.recentGames=[game('eight-ball','last','completed')];await f.service.loadGames();assert.equal(f.service.getActiveGame()?.lobby_code,'last');assert.deepEqual([...f.storage.values()],['last']);f.service.destroy();});
await check('explicit hide clears hint; result does not reopen',async()=>{const f=fixture(await seed());f.state.recentGames=[game('eight-ball','last','completed')];await f.service.loadGames();f.service.hideGameOverlay();const next=fixture(f.storage);next.state.recentGames=f.state.recentGames;await next.service.loadGames();assert.equal(next.service.getActiveGame(),null);next.service.destroy();f.service.destroy();});
await check('opening a different game replaces the hint',async()=>{const f=fixture(await seed());f.state.games=[game('chess','new')];await f.service.openGame(f.state.games[0],{skipJoin:true});assert.deepEqual([...f.storage.values()],['new']);f.service.destroy();});
for(const [name,config,path] of [['other user',{myUserId:23},'/core/chatroom.php'],['other participant',{myParticipantId:13},'/core/chatroom.php'],['other room',{sessionId:2},'/core/chatroom.php'],['other installation',{},'/another/chatroom.php']])await check(name+' cannot restore hint',async()=>{const f=fixture(await seed(),config,path);f.state.recentGames=[game('eight-ball','last','completed')];await f.service.loadGames();assert.equal(f.service.getActiveGame(),null);f.service.destroy();});
for(const [name,patch] of [['spectator',{viewerRole:'spectator'}],['departed',{viewerMembershipStatus:'departed'}],['missing membership',{members:[]}],['wrong participant',{members:[{participantId:99,role:'master',membershipStatus:'active'}]}],['wrong id',{publicId:'other'}],['nonterminal result',{status:'ended'}]])await check(name+' is not restored',async()=>{const f=fixture(await seed());const g=game('eight-ball','last','completed');Object.assign(g.framework,patch);f.state.recentGames=[g];await f.service.loadGames();assert.equal(f.service.getActiveGame(),null);f.service.destroy();});
await check('no hint does not open arbitrary recent game',async()=>{const f=fixture();f.state.recentGames=[game('eight-ball','last','completed')];await f.service.loadGames();assert.equal(f.service.getActiveGame(),null);f.service.destroy();});
await check('storage unavailable leaves active gameplay working',async()=>{const f=fixture();Object.defineProperty(f.host,'sessionStorage',{get(){throw new Error('blocked');}});f.state.games=[game()];await f.service.loadGames();assert.equal(f.service.getActiveGame()?.lobby_code,'last');f.service.hideGameOverlay();f.service.destroy();});
await check('exit during in-flight list wins over restore',async()=>{const f=fixture(await seed());let release;f.context.fetchGames=()=>new Promise(r=>release=r);const pending=f.service.loadGames();f.service.hideGameOverlay();release({games:[],recentGames:[game('eight-ball','last','completed')]});await pending;assert.equal(f.service.getActiveGame(),null);assert.equal(f.storage.size,0);f.service.destroy();});
console.log(JSON.stringify({passed:results.filter(r=>r.pass).length,failed:results.filter(r=>!r.pass).length,results},null,2));if(results.some(r=>!r.pass))process.exitCode=1;
