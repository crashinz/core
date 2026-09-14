/* Named CoreChat search budgets; not calibrated Elo ratings. */
(function(root){
  const levels=Object.freeze({
    easy:Object.freeze({label:'Easy',depth:1,moveTimeMs:100}),
    normal:Object.freeze({label:'Normal',depth:6,moveTimeMs:400}),
    expert:Object.freeze({label:'Expert',depth:12,moveTimeMs:1000}),
  });
  root.MarcherLevels=levels;
  if(typeof module!=='undefined')module.exports=levels;
})(globalThis);
