<?php
require_once __DIR__ . '/../includes/base.php';
$me=require_staff();if((string)($me['role']??'')!=='admin')json_out(['error'=>'Administrator required'],403);$pdo=db();
if($_SERVER['REQUEST_METHOD']==='GET'){
    if (($_GET['action'] ?? '') === 'details') { $details = limit_event_details($pdo, (string)($_GET['public_id'] ?? '')); json_out($details, isset($details['error']) ? 404 : 200); }
    $filters=['search'=>$_GET['search']??'','outcome'=>$_GET['outcome']??'','setting_id'=>$_GET['setting_id']??'','scope_kind'=>$_GET['scope_kind']??'','audit_run_public_id'=>$_GET['audit_run_public_id']??''];$result=limit_event_list($pdo,$filters,(int)($_GET['page']??1),(int)($_GET['page_size']??25));
    if((string)($_GET['action']??'')==='export'){
        $format=strtolower((string)($_GET['format']??'json'));
        if($format==='csv'){header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="corechat-limit-events.csv"');$out=fopen('php://output','wb');fputcsv($out,['setting_id','limit_name','threshold','unit','scope_kind','outcome','occurrence_count','first_reached_at','last_reached_at','audit_run_public_id'], ',', '"', '\\');foreach(limit_event_export_rows($pdo,$filters)as$item)fputcsv($out,array_map('limit_event_csv_cell',[$item['settingId'],$item['limitName'],json_encode($item['threshold']),$item['unit'],$item['scopeKind'],$item['outcome'],$item['occurrenceCount'],$item['firstReachedAt'],$item['lastReachedAt'],$item['auditRunPublicId']]), ',', '"', '\\');fclose($out);exit;}
        if($format!=='json')json_out(['error'=>'Unsupported export format'],400);
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="corechat-limit-events.json"');
        $header=['schemaId'=>'corechat.limit-events.export','privacySafe'=>true];
        echo substr(json_encode($header,JSON_UNESCAPED_SLASHES),0,-1),',"limitEvents":{"items":[';
        $count=0;foreach(limit_event_export_rows($pdo,$filters)as$item){if($count++)echo ',';echo json_encode($item,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);}
        echo '],"total":',$count,',"exported":',$count,',"complete":true}}';exit;
    }json_out(['limitEvents'=>$result]);
}
if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['error'=>'Method not allowed'],405);csrf_protect_post();$input=json_decode((string)file_get_contents('php://input'),true)?:$_POST;$action=(string)($input['action']??'');
if($action==='delete')json_out(['ok'=>limit_event_delete($pdo,(string)($input['public_id']??''))]);if($action==='cleanup')json_out(['ok'=>true,'deleted'=>limit_event_cleanup($pdo)]);json_out(['error'=>'Unknown action'],400);
