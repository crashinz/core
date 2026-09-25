// Native 80ms animation clock. Coordinates and frames are original OCX pixels.
const mic = (id, frames) => ({ id, frames, x:384, y:21, width:12, height:13 });
export const reactionStrips = Object.freeze({
  "celebration-sound": [{id:523,frames:40,x:331,y:16,width:24,height:24}],
  "upper-crash-sound": [
    {id:524,frames:33,x:213,y:122,width:64,height:52},
    {id:525,frames:15,x:262,y:164,width:54,height:56},
  ],
  "upper-all-right-sound": [mic(526,10)],
  "yahtzee-way-to-go-sound": [mic(527,7)],
  "yahtzee-your-on-sound": [mic(528,11)],
  "win-sound": [mic(529,16)],
  "repeat-yahtzee-sound": [{id:530,frames:19,x:0,y:0,toX:162,width:36,height:66}],
  "idle-roll-sound": [mic(531,20)],
  "idle-feeling-sound": [mic(532,10)],
  "ready-sound": [mic(533,8)],
  "idle-something-sound": [mic(534,10)],
  "idle-yawn-sound": [{id:535,frames:20,x:361,y:26,width:34,height:48}],
});

export function reactionFrame(strip, elapsed) {
  const frame = Math.floor(Math.max(0,elapsed)/80);
  if (frame >= strip.frames) return null;
  return {frame, x:strip.x + (strip.toX == null ? 0 : Math.trunc((strip.toX-strip.x)/(strip.frames-1))*frame), y:strip.y};
}
