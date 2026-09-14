/* CoreChat GNU Backgammon bridge; GPL-3.0-or-later, like upstream. */
#include "config.h"
#include "backgammon.h"
#include "positionid.h"
#include <string.h>
static TanBoard board;
static float result[5];
static unsigned int generated[MAX_MOVES][50];
int core_init(void) { EvalInitialise(NULL, "/gnubg.wd", 0, NULL); return 1; }
unsigned int *core_board(void) { return &board[0][0]; }
float *core_result(void) { return result; }
int core_eval(int plies) {
    if(plies < 0 || plies > 1) return -1;
    for(int side=0;side<2;side++) {
        unsigned int total=0;
        for(int i=0;i<25;i++) { if(board[side][i]>15)return -2; total+=board[side][i]; }
        if(total>15)return -2;
    }
    for(int i=0;i<24;i++)if(board[0][i]&&board[1][23-i])return -2;
    cubeinfo ci; evalcontext ec={0};
    SetCubeInfoMoney(&ci,1,-1,0,0,0,VARIATION_STANDARD);
    ec.nPlies=plies;ec.fDeterministic=1;ec.fUsePrune=0;
    return EvaluatePosition(NULL,board,result,&ci,&ec);
}
int core_moves(int d0,int d1) {
    if(d0<1||d0>6||d1<1||d1>6)return -1;
    movelist ml;memset(&ml,0,sizeof(ml));
    GenerateMoves(&ml,board,d0,d1,0);
    for(unsigned int i=0;i<ml.cMoves;i++) {
        TanBoard next;PositionFromKey(next,&ml.amMoves[i].key);
        memcpy(generated[i],next,sizeof(TanBoard));
    }
    return ml.cMoves;
}
unsigned int *core_generated(void) { return &generated[0][0]; }
