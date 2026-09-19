<?php
declare(strict_types=1);

/** Planar PHP adaptation of the qualified Pooltool-derived browser model.
 * Pooltool bce1788cddb7650e3e324439d4fe670723e69332, Evan Kiefl and contributors.
 * Apache-2.0; see THIRD_PARTY_NOTICES.md.
 * Changes: PHP scalar implementation, bounded authoritative shot simulation.
 */
final class EightBallPhysics
{
    // Rolling loss matches the independently calibrated browser motion model.
    public const R=15.5, M=.17, G=4291.338582677166, US=.2, UR=.0105;
    private static float $radius=15.5;
    public const HOLES=[[82,77],[596,66],[1110,77],[82,598],[596,609],[1110,598]];
    // Same widened corner mouths/jaws as the browser; unchanged centers/capture radius.
    public const RAILS=[[122.392,85,569,85],[631,85,1070.608,85],[122.392,590,569,590],[631,590,1070.608,590],[83,125.014,83,549.986],[1110,125.014,1110,549.986],[122.392,85,101.289,64.303],[569,85,575,64],[631,85,625,64],[1070.608,85,1091.711,64.303],[122.392,590,101.289,610.697],[569,590,575,614],[631,590,625,614],[1070.608,590,1091.711,610.697],[83,125.014,63.304,105.697],[83,549.986,63.304,569.303],[1110,125.014,1129.696,105.697],[1110,549.986,1129.696,569.303]];
    /** Shared approved power curve; match games/eight-ball/table.js. */
    public static function speed(float $power): float {
        $p=max(5,min(100,is_finite($power)?$power:5));
        $base=125+9*$p+1055*(max(0,$p-45)/55)**2;
        if($p<20){$t=($p-5)/15;return $base-108*(1-$t*$t*(3-2*$t));}
        $knots=[[5,1],[35,1],[50,1.38],[65,1.28],[100,1.25]];
        for($i=1;$i<count($knots);$i++) {
            [$hi,$b]=$knots[$i];[$lo,$a]=$knots[$i-1];
            if($p<=$hi){$t=($p-$lo)/($hi-$lo);$smooth=$t*$t*(3-2*$t);return $base*($a+($b-$a)*$smooth);}
        }
        return $base*1.25;
    }
    public static function ball(int $n,float $x,float $y): object {return (object)['n'=>$n,'x'=>$x,'y'=>$y,'vx'=>0.,'vy'=>0.,'wx'=>0.,'wy'=>0.,'wz'=>0.,'pocket'=>false,'drop'=>0.,'roll'=>0.];}
    public static function phase(object $b): array {
        $ux=$b->vx-self::$radius*$b->wy;$uy=$b->vy+self::$radius*$b->wx;$u=hypot($ux,$uy);$v=hypot($b->vx,$b->vy);
        if($u>1e-7)return ['type'=>'slide','time'=>2*$u/(7*self::US*self::G),'ax'=>-self::US*self::G*$ux/$u,'ay'=>-self::US*self::G*$uy/$u];
        if($v>1e-7)return ['type'=>'roll','time'=>$v/(self::UR*self::G),'ax'=>-self::UR*self::G*$b->vx/$v,'ay'=>-self::UR*self::G*$b->vy/$v];
        return ['type'=>'rest','time'=>INF,'ax'=>0.,'ay'=>0.];
    }
    public static function advance(object $b,float $t): void {
        for($i=0;$t>1e-14&&$i<5;$i++){
            $f=self::phase($b);$dt=min($t,$f['time']);$ox=$b->x;$oy=$b->y;
            $b->x+=$b->vx*$dt+.5*$f['ax']*$dt*$dt;$b->y+=$b->vy*$dt+.5*$f['ay']*$dt*$dt;
            $b->vx+=$f['ax']*$dt;$b->vy+=$f['ay']*$dt;
            if($f['type']==='slide'){$b->wx+=2.5/self::$radius*$f['ay']*$dt;$b->wy-=2.5/self::$radius*$f['ax']*$dt;}
            if($f['type']==='roll'||$dt>=$f['time']-1e-14){$b->wx=-$b->vy/self::$radius;$b->wy=$b->vx/self::$radius;}
            if($f['type']==='roll'&&$dt>=$f['time']-1e-14)$b->vx=$b->vy=$b->wx=$b->wy=0.;
            $dw=2.5*(.028575*4/9)*self::G/self::$radius*$dt;$b->wz=($b->wz<=>0)*max(0,abs($b->wz)-$dw);
            $b->roll=($b->roll??0)+hypot($b->x-$ox,$b->y-$oy)/self::$radius;$t-=$dt;
        }
    }
    public static function strike(object $b,float $angle,float $speed,array $spin): void {
        $top=-$spin['y']*.7;$side=$spin['x']*.7;$b->vx=cos($angle)*$speed;$b->vy=sin($angle)*$speed;
        $b->wx=-sin($angle)*$speed*2.5*$top/self::$radius;$b->wy=cos($angle)*$speed*2.5*$top/self::$radius;$b->wz=-$speed*2.5*$side/self::$radius;
    }
    public static function cushion(object $b,array $n): void {
        [$c,$s]=$n;$vx=$c*$b->vx+$s*$b->vy;$vy=-$s*$b->vx+$c*$b->vy;$wx=$c*$b->wx+$s*$b->wy;$wy=-$s*$b->wx+$c*$b->wy;$wz=$b->wz;
        if($vx<=1e-9)return;$sn=.28;$cs=sqrt(1-$sn*$sn);$r=self::$radius;$m=self::M;
        $sx=$vx*$sn+$r*$wy;$sy=-$vy-$r*$wz*$cs+$r*$wx*$sn;$a=3.5/$m;$i=.4*$m*$r*$r;$pz=1.85*$vx*$cs*$m;$slip=hypot($sx,$sy);
        $factor=$slip/$a<=.2*$pz?1/$a:.2*$pz/$slip;$px=$sx*$factor;$py=$sy*$factor;$PX=-$px*$sn-$pz*$cs;$PY=$py;$PZ=$px*$cs-$pz*$sn;
        $vx+=$PX/$m;$vy+=$PY/$m;$wx+=-$r/$i*$PY*$sn;$wy+=$r/$i*($PX*$sn-$PZ*$cs);$wz+=$r/$i*$PY*$cs;
        $b->vx=$c*$vx-$s*$vy;$b->vy=$s*$vx+$c*$vy;$b->wx=$c*$wx-$s*$wy;$b->wy=$s*$wx+$c*$wy;$b->wz=$wz;
    }
    public static function collide(object $a,object $b): void {
        $d=hypot($b->x-$a->x,$b->y-$a->y);if($d<1e-12)throw new RuntimeException('Coincident pool balls.');
        $yx=($b->x-$a->x)/$d;$yy=($b->y-$a->y)/$d;$xx=$yy;$xy=-$yx;$r=self::$radius;$m=self::M;
        $vix=$a->vx*$xx+$a->vy*$xy;$viy=$a->vx*$yx+$a->vy*$yy;$vjx=$b->vx*$xx+$b->vy*$xy;$vjy=$b->vx*$yx+$b->vy*$yy;
        $wix=$a->wx*$xx+$a->wy*$xy;$wiy=$a->wx*$yx+$a->wy*$yy;$wiz=$a->wz;$wjx=$b->wx*$xx+$b->wy*$xy;$wjy=$b->wx*$yx+$b->wy*$yy;$wjz=$b->wz;
        $rel=$vjy-$viy;if($rel>=-1e-12)return;$dp=.5*1.93*$m*abs($rel)/1000;$C=5/(2*$m*$r);
        $ix=$vix+$r*$wiy;$iy=$viy-$r*$wix;$jx=$vjx+$r*$wjy;$jy=$vjy-$r*$wjx;$im=hypot($ix,$iy);$jm=hypot($jx,$jy);
        $cx=$vix-$vjx-$r*($wiz+$wjz);$cz=$r*($wix+$wjx);$cm=hypot($cx,$cz);$work=0.;$compression=null;$final=INF;$iterations=0;
        while($rel<0||$work<$final){
            if(++$iterations>20000)throw new RuntimeException('Pool collision work limit.');$p1=$p2=$pix=$piy=$pjx=$pjy=0.;
            if($cm>=1e-16){$p1=-.05*$dp*$cx/$cm;if(abs($cz)>=1e-16){$p2=-.05*$dp*$cz/$cm;if($p2>0){if($jm!=0){$pjx=-self::US*($jx/$jm)*$p2;$pjy=-self::US*($jy/$jm)*$p2;}}elseif($im!=0){$pix=self::US*($ix/$im)*$p2;$piy=self::US*($iy/$im)*$p2;}}}
            $vix+=($p1+$pix)/$m;$viy+=(-$dp+$piy)/$m;$vjx+=(-$p1+$pjx)/$m;$vjy+=($dp+$pjy)/$m;
            $wix+=$C*($p2+$piy);$wiy+=$C*(-$pix);$wiz+=$C*(-$p1);$wjx+=$C*($p2+$pjy);$wjy+=$C*(-$pjx);$wjz+=$C*(-$p1);
            $ix=$vix+$r*$wiy;$iy=$viy-$r*$wix;$jx=$vjx+$r*$wjy;$jy=$vjy-$r*$wjx;$im=hypot($ix,$iy);$jm=hypot($jx,$jy);
            $cx=$vix-$vjx-$r*($wiz+$wjz);$cz=$r*($wix+$wjx);$cm=hypot($cx,$cz);$previous=$rel;$rel=$vjy-$viy;$work+=.5*$dp*abs($previous+$rel);
            if($compression===null&&$rel>0){$compression=$work;$final=(1+.93**2)*$compression;}
        }
        $a->vx=$xx*$vix+$yx*$viy;$a->vy=$xy*$vix+$yy*$viy;$a->wx=$xx*$wix+$yx*$wiy;$a->wy=$xy*$wix+$yy*$wiy;$a->wz=$wiz;
        $b->vx=$xx*$vjx+$yx*$vjy;$b->vy=$xy*$vjx+$yy*$vjy;$b->wx=$xx*$wjx+$yx*$wjy;$b->wy=$xy*$wjx+$yy*$wjy;$b->wz=$wjz;
    }
    private static function value(array $c,float $t): float {$v=0.;for($i=count($c)-1;$i>=0;$i--)$v=$v*$t+$c[$i];return $v;}
    public static function roots(array $c,float $lo,float $hi): array {
        while(count($c)>1&&abs($c[count($c)-1])<1e-18)array_pop($c);if(count($c)===1)return [];
        if(count($c)===2){$t=-$c[0]/$c[1];return $t>=$lo&&$t<=$hi?[$t]:[];}
        $d=[];for($i=1;$i<count($c);$i++)$d[]=$c[$i]*$i;$cuts=[$lo,...self::roots($d,$lo,$hi),$hi];$out=[];
        for($i=0;$i<count($cuts)-1;$i++){$a=$cuts[$i];$b=$cuts[$i+1];$fa=self::value($c,$a);$fb=self::value($c,$b);if(abs($fa)<1e-10)$out[]=$a;
            if($fa*$fb<0){for($j=0;$j<42;$j++){$m=($a+$b)/2;$fm=self::value($c,$m);if($fa*$fm<=0){$b=$m;$fb=$fm;}else{$a=$m;$fa=$fm;}}$out[]=($a+$b)/2;}}
        if(abs(self::value($c,$hi))<1e-10)$out[]=$hi;sort($out,SORT_NUMERIC);return $out;
    }
    private static function at(object $b,array $f,float $t): array {return [$b->x+$b->vx*$t+.5*$f['ax']*$t*$t,$b->y+$b->vy*$t+.5*$f['ay']*$t*$t,$b->vx+$f['ax']*$t,$b->vy+$f['ay']*$t];}
    private static function circle(object $a,array $fa,object $b,array $fb,float $r,float $dt): ?float {
        $x=$b->x-$a->x;$y=$b->y-$a->y;$vx=$b->vx-$a->vx;$vy=$b->vy-$a->vy;$ax=.5*($fb['ax']-$fa['ax']);$ay=.5*($fb['ay']-$fa['ay']);$gap=hypot($x,$y)-$r;
        if($gap>hypot($vx,$vy)*$dt+hypot($ax,$ay)*$dt*$dt+1e-7)return null;if($gap<=1e-7&&$x*$vx+$y*$vy<-$r*.02)return 0.;
        foreach(self::roots([$x*$x+$y*$y-$r*$r,2*($x*$vx+$y*$vy),$vx*$vx+$vy*$vy+2*($x*$ax+$y*$ay),2*($vx*$ax+$vy*$ay),$ax*$ax+$ay*$ay],0,$dt)as$t)
            if(($x+$vx*$t+$ax*$t*$t)*($vx+2*$ax*$t)+($y+$vy*$t+$ay*$t*$t)*($vy+2*$ay*$t)<-$r*.02)return $t;
        return null;
    }
    public static function step(array $balls,float $dt,callable $notify): void {
        $left=$dt;$events=0;$zero=['ax'=>0.,'ay'=>0.];
        while($left>1e-12){if(++$events>160)throw new RuntimeException('Pool contact limit.');$live=array_values(array_filter($balls,static fn($b)=>!$b->pocket));$ph=array_map([self::class,'phase'],$live);$h=$left;foreach($ph as$f)$h=min($h,$f['time']);
            if($h<1e-12){foreach($live as$i=>$b)if($ph[$i]['time']<1e-12)self::advance($b,1e-12);$h=min($left,1e-9);}
            $event=null;$take=static function(?float $t,array $e)use(&$h,&$event){if($t!==null&&$t<=$h){$h=$t;$event=$e;}};
            foreach($live as$i=>$a){$f=$ph[$i];if(hypot($a->vx,$a->vy)<1e-9&&hypot($f['ax'],$f['ay'])<1e-9)continue;
                foreach(self::HOLES as$index=>$hole){if(hypot($a->x-$hole[0],$a->y-$hole[1])<18.999){$take(0.,['type'=>'pocket','a'=>$a,'hole'=>$hole,'pocketIndex'=>$index]);break;}
                    $take(self::circle($a,$f,self::ball(-1,$hole[0],$hole[1]),$zero,19,$h),['type'=>'pocket','a'=>$a,'hole'=>$hole,'pocketIndex'=>$index]);}
                foreach(self::RAILS as[$x1,$y1,$x2,$y2]){$dx=$x2-$x1;$dy=$y2-$y1;$len=hypot($dx,$dy);$tx=$dx/$len;$ty=$dy/$len;$nx=-$ty;$ny=$tx;$distance=($a->x-$x1)*$nx+($a->y-$y1)*$ny;
                    $reach=hypot($a->vx,$a->vy)*$h+.5*hypot($f['ax'],$f['ay'])*$h*$h+self::$radius+1e-6;
                    if(abs($distance)<=$reach){foreach([-1,1]as$sign)foreach(self::roots([$distance-$sign*self::$radius,$a->vx*$nx+$a->vy*$ny,.5*($f['ax']*$nx+$f['ay']*$ny)],0,$h)as$t){[$qx,$qy,$qvx,$qvy]=self::at($a,$f,$t);$along=($qx-$x1)*$tx+($qy-$y1)*$ty;if($along>=0&&$along<=$len&&($qvx*$nx+$qvy*$ny)*$sign< -1e-7)$take($t,['type'=>'rail','a'=>$a,'n'=>[-$sign*$nx,-$sign*$ny]]);}
                        foreach([[$x1,$y1],[$x2,$y2]]as[$x,$y]){$t=self::circle($a,$f,self::ball(-1,$x,$y),$zero,self::$radius,$h);if($t!==null){[$qx,$qy]=self::at($a,$f,$t);$d=hypot($x-$qx,$y-$qy);$take($t,['type'=>'rail','a'=>$a,'n'=>[($x-$qx)/$d,($y-$qy)/$d]]);}}}
                }
            }
            for($i=0;$i<count($live);$i++)for($j=$i+1;$j<count($live);$j++)$take(self::circle($live[$i],$ph[$i],$live[$j],$ph[$j],2*self::$radius,$h),['type'=>'ball','a'=>$live[$i],'b'=>$live[$j]]);
            foreach($live as$b)self::advance($b,$h);$left-=$h;
            if($event){$a=$event['a'];$event['speed']=$event['type']==='ball'?hypot($a->vx-$event['b']->vx,$a->vy-$event['b']->vy):($event['type']==='rail'?abs($a->vx*$event['n'][0]+$a->vy*$event['n'][1]):hypot($a->vx,$a->vy));if($event['type']==='pocket'){$a->pocket=true;$a->hole=$event['hole'];$a->drop=0.;$a->vx=$a->vy=$a->wx=$a->wy=$a->wz=0.;}
                elseif($event['type']==='rail'){self::cushion($a,$event['n']);$a->x-=$event['n'][0]*1e-7;$a->y-=$event['n'][1]*1e-7;}
                else{$b=$event['b'];
                    // Preserve simultaneous geometry; separate only isolated pairs.
                    if(!self::cluster($live,$a,$b)){$d=hypot($b->x-$a->x,$b->y-$a->y);$nx=($b->x-$a->x)/$d;$ny=($b->y-$a->y)/$d;if($d<2*self::$radius+.002){$fix=(2*self::$radius+.002-$d)/2;$a->x-=$nx*$fix;$a->y-=$ny*$fix;$b->x+=$nx*$fix;$b->y+=$ny*$fix;}self::collide($a,$b);}
                }$notify($event);}
        }
    }

    /** Finite-duration normal contact for a touching group. Local compliant
     * coordinates resolve the impact together; ordinary isolated contacts keep
     * the Mathavan model. This is an instantaneous, frictionless group impulse
     * approximation, not an exact model of every real rack deformation. */
    public static function cluster(array $live,object $a,object $b): bool {
        $group=[$a,$b];for($k=0;$k<count($group);$k++)foreach($live as$c)if(!in_array($c,$group,true)&&hypot($c->x-$group[$k]->x,$c->y-$group[$k]->y)<2*self::$radius+.02)$group[]=$c;
        if(count($group)<3)return false;
        $energy=0.;$mx=0.;$my=0.;foreach($group as$g){$energy+=$g->vx**2+$g->vy**2;$mx+=$g->vx;$my+=$g->vy;}$copy=array_map(fn($b)=>clone $b,$group);$tau=.0003;$dt=$tau/80;$me=self::M/2;$ln=log(.93);$z=-$ln/sqrt(M_PI**2+$ln**2);$k=$me*(M_PI/$tau)**2/(1-$z*$z);$c=2*$z*sqrt($k*$me);
        for($step=0;$step<1600;$step++){
            $forces=array_fill(0,count($copy),[0.,0.]);$pending=false;
            for($i=0;$i<count($copy);$i++)for($j=$i+1;$j<count($copy);$j++){$u=$copy[$i];$v=$copy[$j];$dx=$v->x-$u->x;$dy=$v->y-$u->y;$d=hypot($dx,$dy);$nx=$dx/$d;$ny=$dy/$d;$rel=($v->vx-$u->vx)*$nx+($v->vy-$u->vy)*$ny;$gap=2*self::$radius-$d;
                // Match browser numerical-contact damping at the root boundary.
                $over=abs($gap)<1e-8?0.:$gap;
                if($over>1e-8||($rel<-.02&&$over<=0&&-$over / -$rel<$tau))$pending=true;
                if($over<0)continue;$f=max(0,$k*$over-$c*$rel);$forces[$i][0]-=$nx*$f;$forces[$i][1]-=$ny*$f;$forces[$j][0]+=$nx*$f;$forces[$j][1]+=$ny*$f;
            }
            if($step>0&&!$pending)break;
            foreach($copy as$i=>$u){$u->vx+=$forces[$i][0]*$dt/self::M;$u->vy+=$forces[$i][1]*$dt/self::M;$u->x+=$u->vx*$dt;$u->y+=$u->vy*$dt;}
        }
        if($step===1600)throw new RuntimeException('Pool group contact did not settle.');
        // The passive contact must never add translational energy. Correct only
        // numerical excess around center-of-mass velocity, preserving momentum.
        $cx=$mx/count($group);$cy=$my/count($group);$before=max(0,$energy-count($group)*($cx*$cx+$cy*$cy));$after=0.;foreach($copy as$u)$after+=($u->vx-$cx)**2+($u->vy-$cy)**2;$scale=$after>$before&&$after>0?sqrt($before/$after):1.;
        foreach($copy as$i=>$u){$group[$i]->vx=$cx+($u->vx-$cx)*$scale;$group[$i]->vy=$cy+($u->vy-$cy)*$scale;}
        // Match the browser residual-contact projection at the real centers.
        // Pair impulses preserve momentum and only remove inward normal energy.
        for($pass=0;$pass<80;$pass++){
            $closing=false;
            for($i=0;$i<count($group);$i++)for($j=$i+1;$j<count($group);$j++){
                $u=$group[$i];$v=$group[$j];$dx=$v->x-$u->x;$dy=$v->y-$u->y;$d=hypot($dx,$dy);
                if($d>2*self::$radius+1e-7||$d<1e-12)continue;
                $nx=$dx/$d;$ny=$dy/$d;$rel=($v->vx-$u->vx)*$nx+($v->vy-$u->vy)*$ny;
                if($rel>=-1e-8)continue;$closing=true;
                $impulse=-$rel/2;$u->vx-=$nx*$impulse;$u->vy-=$ny*$impulse;$v->vx+=$nx*$impulse;$v->vy+=$ny*$impulse;
            }
            if(!$closing)break;
        }
        return true;
    }
    public static function moving(array $balls): bool {foreach($balls as$b)if(!$b->pocket&&(hypot($b->vx,$b->vy)>1e-6||self::phase($b)['type']==='slide'))return true;return false;}
    public static function simulate(array $input,float $angle,float $power,array $spin,float $radius=15.5): array {
        if(!in_array($radius,[12.5,15.5],true))throw new RuntimeException('Invalid ball size.');
        $previous=self::$radius;self::$radius=$radius;
        try{return self::simulateCurrent($input,$angle,$power,$spin);}finally{self::$radius=$previous;}
    }
    private static function simulateCurrent(array $input,float $angle,float $power,array $spin): array {
        $balls=array_map(static fn($b)=>(object)$b,$input);$cue=null;foreach($balls as$b)if($b->n===0)$cue=$b;
        if(!$cue||$cue->pocket)throw new RuntimeException('Cue ball must be placed.');self::strike($cue,$angle,self::speed($power),$spin);
        $events=[];$frames=[];$t=0.;$crossed=false;$firstContactCrossed=null;$ticks=0;$started=microtime(true);
        $frame=static fn()=>array_map(static fn($b)=>[$b->n,round($b->x,5),round($b->y,5),$b->pocket?1:0,round($b->roll??0,5)],$balls);
        $frames[]=$frame();
        while(self::moving($balls)&&$t<45){self::step($balls,1/120,static function($e)use(&$events,&$t,&$crossed,&$firstContactCrossed,$cue){$crossed=$crossed||$cue->x>335;if($firstContactCrossed===null&&$e['type']==='ball'&&($e['a']->n===0||$e['b']->n===0))$firstContactCrossed=$crossed;$events[]=['type'=>$e['type'],'a'=>$e['a']->n,'b'=>$e['b']->n??null,'pocket'=>$e['pocketIndex']??null,'time'=>$t,'speed'=>round($e['speed']??0,3)];});$t+=1/120;$crossed=$crossed||$cue->x>335;
            if(++$ticks%4===0)$frames[]=$frame();if(count($events)>2048||microtime(true)-$started>12)throw new RuntimeException('Pool simulation budget exceeded.');}
        if(self::moving($balls))throw new RuntimeException('Pool shot did not settle.');
        foreach($balls as$b){foreach(['x','y','vx','vy','wx','wy','wz']as$k)if(!is_finite((float)$b->$k))throw new RuntimeException('Non-finite pool result.');if(!$b->pocket&&($b->x<45||$b->x>1147||$b->y<30||$b->y>650))throw new RuntimeException('Pool ball escaped.');$b->vx=$b->vy=$b->wx=$b->wy=$b->wz=0.;}
        $frames[]=$frame();return ['balls'=>array_map(static fn($b)=>(array)$b,$balls),'events'=>$events,'frames'=>$frames,'duration'=>$t,'frameStep'=>1/30,'firstContactCrossedHeadString'=>$firstContactCrossed];
    }
}
