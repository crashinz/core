/* CoreChat Five Dice strategy table generator, independently authored 2026-09-16.
 * Full-scorecard Bellman recurrence; no upstream implementation is copied.
 * Score transitions implement includes/five_dice_extension.php exactly.
 * First-party source under the repository LICENSE.md. Offline tool only. Dimensions: open-category mask, capped upper total,
 * and whether the already-filled Yahtzee box scored 50. Past points are sunk.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>
#include <time.h>
#define N 462
#define FIRST 210
#define Y (1<<12)
#define LOWER 4032
static int counts[N][6], size[N], add[N][6], sub[N][6], lookup[46656], nodes;
static int points[252][13], yf[252];
static double table[8192*128];
static int key(int *c){int k=0;for(int f=0;f<6;f++)k=k*6+c[f];return k;}
static void enumerate(int *c,int f,int left,int total){
 if(f==5){c[f]=left;memcpy(counts[nodes],c,6*sizeof(int));size[nodes]=total;lookup[key(c)]=nodes++;return;}
 for(int i=0;i<=left;i++){c[f]=i;enumerate(c,f+1,left-i,total);}
}
static void init(void){
 int c[6]={0};for(int n=0;n<=5;n++)enumerate(c,0,n,n);
 for(int h=0;h<N;h++)for(int f=0;f<6;f++){
  memcpy(c,counts[h],sizeof(c));add[h][f]=sub[h][f]=-1;
  if(size[h]<5){c[f]++;add[h][f]=lookup[key(c)];c[f]--;}
  if(c[f]){c[f]--;sub[h][f]=lookup[key(c)];}
 }
 for(int i=0;i<252;i++){
  int *a=counts[i+FIRST],sum=0,max=0,pair=0,triple=0,run=0,longest=0;yf[i]=-1;
  for(int f=0;f<6;f++){
   points[i][f]=a[f]*(f+1);sum+=points[i][f];if(a[f]>max)max=a[f];
   pair|=a[f]==2;triple|=a[f]==3;if(a[f]==5)yf[i]=f;
   run=a[f]?run+1:0;if(run>longest)longest=run;
  }
  points[i][6]=max>=3?sum:0;points[i][7]=max>=4?sum:0;
  points[i][8]=pair&&triple?25:0;points[i][9]=longest>=4?30:0;
  points[i][10]=longest>=5?40:0;points[i][11]=sum;points[i][12]=max==5?50:0;
 }
}
static int allowed(int mask,int i,int force){
 int f=yf[i];if(!force||f<0||(mask&Y))return mask;
 if(mask&(1<<f))return 1<<f;
 return (mask&LOWER)?mask&LOWER:mask&63;
}
static int score(int mask,int i,int cat){
 if(yf[i]>=0&&!(mask&Y)&&!(mask&(1<<yf[i]))){
  if(cat==8)return 25;if(cat==9)return 30;if(cat==10)return 40;
 }
 return points[i][cat];
}
static void expectation(double *a){
 for(int h=FIRST-1;h>=0;h--){
  double x=0;for(int f=0;f<6;f++)x+=a[add[h][f]];a[h]=x/6.0;
 }
}
static double solve(int mask,int upper,int positive,int force){
 double a[N],b[N];int cats[13],length=0;
 for(int c=0;c<13;c++)if(mask&(1<<c))cats[length++]=c;
 for(int i=0;i<252;i++){
  int legal=allowed(mask,i,force);double best=-1;
  int bonus=yf[i]>=0&&positive?100:0;
  for(int j=0;j<length;j++){
   int c=cats[j];if(!(legal&(1<<c)))continue;
   int p=score(mask,i,c),u=upper+(c<6?p:0);if(u>63)u=63;
   int nextPositive=c==12?(p==50):positive;
   double v=p+bonus+table[(mask^(1<<c))*128+u*2+nextPositive];
   if(v>best)best=v;
  }
  a[FIRST+i]=best;
 }
 // Every reroll: random-fill expectation then best submultiset of each roll.
 // Lower-cardinality nodes precede their supersets in the lattice.
 for(int step=0;step<2;step++){
  expectation(a);memcpy(b,a,sizeof(a));
  for(int h=1;h<N;h++)for(int f=0;f<6;f++){
   int s=sub[h][f];if(s>=0&&b[s]>b[h])b[h]=b[s];
  }
  memcpy(a+FIRST,b+FIRST,252*sizeof(double));
 }
 expectation(a);return a[0];
}
int main(int argc,char**argv){
 init();if(nodes!=N)return 2;
 if(argc>1&&!strcmp(argv[1],"verify")){
  FILE *out=fopen("rules.tsv","w");if(!out)return 3;
  for(int i=0;i<252;i++)for(int c=0;c<13;c++)fprintf(out,"N\t%d\t%d\t%d\n",i,c,points[i][c]);
  for(int m=1;m<8192;m++)for(int i=0;i<252;i++)if(yf[i]>=0){
   int a=allowed(m,i,1);for(int c=0;c<13;c++)if(a&(1<<c))fprintf(out,"J\t%d\t%d\t%d\t%d\n",m,yf[i]+1,c,score(m,i,c));
  }
  fclose(out);return 0;
 }
 int force=argc<2||strcmp(argv[1],"unrestricted");
 for(int u=0;u<64;u++)for(int p=0;p<2;p++)table[u*2+p]=u==63?35:0;
 clock_t start=clock();
 for(int m=1;m<8192;m++){
  for(int u=0;u<64;u++)for(int p=0;p<((m&Y)?1:2);p++)table[m*128+u*2+p]=solve(m,u,p,force);
  if(m%512==0)fprintf(stderr,"mask %d/8191 %.1fs\n",m,(double)(clock()-start)/CLOCKS_PER_SEC);
 }
 const char *path=force?"values.bin":"unrestricted-values.bin";
 FILE*out=fopen(path,"wb");if(!out)return 3;
 fwrite("FDV1LE64",1,8,out);fwrite(table,sizeof(double),8192*128,out);fclose(out);
 printf("{\"forceJoker\":%s,\"initialExpectedScore\":%.12f,\"tableBytes\":%zu,\"seconds\":%.3f}\n",force?"true":"false",table[8191*128],sizeof(table)+8,(double)(clock()-start)/CLOCKS_PER_SEC);
 return 0;
}
