from pathlib import Path
import subprocess,sys,os,json,hashlib
H=Path(__file__).resolve().parent;S=Path(sys.argv[1]).resolve();O=Path(sys.argv[2]).resolve();O.mkdir(parents=True,exist_ok=True)
SDK=Path(os.environ['EMSDK'])
sources=[str(p.relative_to(S)).replace('\\','/') for glob in ['gnubg/*.c','gnubg/lib/*.c','glib/glib-2.62.0/glib/*.c','glib/glib-2.62.0/glib/libcharset/*.c'] for p in S.glob(glob)]
args=sources+[str(H/'corechat_api.c'),'-O2','-sINVOKE_RUN=0','-sMODULARIZE=1','-sEXPORT_NAME=createGnuBG','-sENVIRONMENT=worker,node','-sALLOW_MEMORY_GROWTH=1','-sMAXIMUM_MEMORY=256MB','-sSTACK_SIZE=4MB','-sEXPORTED_FUNCTIONS=_core_init,_core_board,_core_result,_core_eval,_core_moves,_core_generated','-sEXPORTED_RUNTIME_METHODS=HEAPU32,HEAPF32','-DGLIB_COMPILATION=1','-DWEB=1','--embed-file','packaged_files/gnubg.wd@/gnubg.wd','--embed-file','packaged_files/gnubg_os0.bd@/gnubg_os0.bd']
for p in ['glib/glib-2.62.0/glib','glib/glib-2.62.0','glib/glib-2.62.0/_build/glib','gnubg/lib','gnubg','glib/glib-2.62.0/_build','glib/glib-2.62.0/glib/libcharset']:args+=['-I',p]
args+=['-o',str(O/'gnubg.js')]
# A response file avoids Windows' command-line length limit; no shell execution.
rsp=O/'emcc.args';rsp.write_text('\n'.join(json.dumps(x) for x in args))
env=os.environ.copy();env['EM_CONFIG']=str(SDK/'.emscripten')
with (O/'build.log').open('w') as log:
 r=subprocess.run([sys.executable,str(SDK/'upstream/emscripten/emcc.py'),'@'+str(rsp)],cwd=S,env=env,stdout=log,stderr=subprocess.STDOUT)
record={'exitCode':r.returncode,'upstream':subprocess.check_output(['git','rev-parse','HEAD'],cwd=S,text=True).strip(),'args':args}
(O/'BUILD.json').write_text(json.dumps(record,indent=2));print(json.dumps({'exitCode':r.returncode,'log':str(O/'build.log')}));sys.exit(r.returncode)
