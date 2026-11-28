<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

// Create-only import. No credit permissions, debt, or historical sales are changed.
function clientImportInput(array $b):array {
    if(array_diff(array_keys($b),['rows','request_id','preview_hash']))fail('Campos no permitidos.',422);
    $rows=$b['rows']??null;
    if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>500)fail('Importa entre 1 y 500 clientes por archivo.',422);
    return $rows;
}
function clientImportReview(PDO $db,int $biz,array $rows,bool $lock=false):array {
    $out=[];$seen=[];$counts=['new'=>0,'existing'=>0,'invalid'=>0];
    $find=$db->prepare('SELECT id,name,is_active FROM habitual_clients WHERE business_id=? AND rut=?'.($lock?' FOR UPDATE':''));
    $legacy=$db->prepare("SELECT id FROM habitual_clients WHERE business_id=? AND (rut IS NULL OR TRIM(rut)='') AND name=? LIMIT 1".($lock?' FOR UPDATE':''));
    foreach($rows as $i=>$raw){
        $item=['row'=>$i+1,'status'=>'invalid','client_id'=>null,'message'=>'','fields'=>null];
        try{
            if(!is_array($raw)||array_is_list($raw)||array_diff(array_keys($raw),['name','rut','client_type','business_activity','address','commune','contact_name','phone','billing_email','notes']))fail('Campos de cliente inválidos.',422);
            $fields=titoCommercialFields($raw+['is_active'=>true]);
            if(!$fields[4])fail('RUT obligatorio para importación masiva.',422);
            $item['fields']=$fields;$rut=$fields[4];
            if(isset($seen[$rut]))fail('RUT repetido en el archivo (registro '.$seen[$rut].').',422);
            $seen[$rut]=$i+1;
            $find->execute([$biz,$rut]);$old=$find->fetch();$find->closeCursor();
            if($old){$item['status']='existing';$item['client_id']=(int)$old['id'];$item['message']='Existe: '.$old['name'].((int)$old['is_active']?'':' (inactivo)').'. No se modificará.';}
            else{
                $legacy->execute([$biz,$fields[0]]);$match=$legacy->fetchColumn();$legacy->closeCursor();
                if($match)fail('Existe un cliente sin RUT con el mismo nombre. Revisa y completa su ficha antes de importar.',409);
                $item['status']='new';$item['message']='Cliente nuevo.';
            }
        }catch(HttpError $e){$item['message']=$e->getMessage();}
        $counts[$item['status']]++;$out[]=$item;
    }
    $hash=hash('sha256',json_encode(array_map(static fn($r)=>[$r['fields'],$r['status'],$r['client_id']],$out),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    return ['items'=>$out,'counts'=>$counts,'preview_hash'=>$hash];
}
function registerClientImportRoutes(RouteCollectorProxy $group):void {
    $group->post('/clients/import/preview',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$rows=clientImportInput(requestBody($request));
        $result=clientImportReview(db(),(int)$a['business_id'],$rows);
        // Return only the review data needed in the interface.
        $result['items']=array_map(static fn($r)=>['row'=>$r['row'],'status'=>$r['status'],'client_id'=>$r['client_id'],'rut'=>$r['fields'][4]??null,'name'=>$r['fields'][0]??null,'message'=>$r['message']],$result['items']);
        return respond($response,$result);
    });
    $group->get('/clients/import/batches',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$page=filter_var($request->getQueryParams()['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if($page===false)fail('Página inválida.',422);
        $s=db()->prepare('SELECT b.id,b.total_rows,b.created_count,b.skipped_count,b.created_at,u.email AS actor_email FROM client_import_batches b LEFT JOIN users u ON u.id=b.actor_id AND u.business_id=b.business_id WHERE b.business_id=? ORDER BY b.id DESC LIMIT 26 OFFSET '.(($page-1)*25));$s->execute([$biz]);$r=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($r,0,25),'more'=>count($r)>25]);
    });
    $group->post('/clients/import',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$b=requestBody($request);$rows=clientImportInput($b);$rid=titoCreditUuid($b['request_id']??null);$preview=$b['preview_hash']??null;
        if(!is_string($preview)||!preg_match('/^[a-f0-9]{64}$/D',$preview))fail('Revisión previa obligatoria.',422);
        $hash=hash('sha256',json_encode([$uid,$preview,$rows],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $s=$db->prepare('SELECT id,payload_hash,result_json FROM client_import_batches WHERE business_id=? AND request_id=?');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();
            if($old){if(!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada con otros datos.',409);$result=json_decode($old['result_json'],true,512,JSON_THROW_ON_ERROR);$db->commit();return respond($response,['ok'=>true,'batch_id'=>(int)$old['id'],'already_recorded'=>true]+$result);}
            $review=clientImportReview($db,$biz,$rows,true);
            if($review['counts']['invalid']||!hash_equals($review['preview_hash'],$preview))fail('Los datos o coincidencias cambiaron. Revisa el archivo nuevamente. No se importó nada.',409);
            if(!$review['counts']['new'])fail('No hay clientes nuevos para importar.',422);
            $insert=$db->prepare('INSERT INTO habitual_clients(business_id,name,phone,notes,client_type,rut,business_activity,address,commune,contact_name,billing_email,is_active,credit_enabled,credit_limit_clp) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,0,0)');
            $items=[];foreach($review['items'] as $r){
                if($r['status']==='new'){$insert->execute([$biz,...$r['fields']]);$id=(int)$db->lastInsertId();}
                else $id=$r['client_id'];
                $items[]=['row'=>$r['row'],'client_id'=>$id,'status'=>$r['status']==='new'?'created':'skipped'];
            }
            $result=['created'=>$review['counts']['new'],'skipped'=>$review['counts']['existing'],'items'=>$items];
            $s=$db->prepare('INSERT INTO client_import_batches(business_id,actor_id,request_id,payload_hash,total_rows,created_count,skipped_count,result_json) VALUES(?,?,?,?,?,?,?,?)');$s->execute([$biz,$uid,$rid,$hash,count($rows),$result['created'],$result['skipped'],json_encode($result,JSON_THROW_ON_ERROR)]);$id=(int)$db->lastInsertId();
            $db->commit();return respond($response,['ok'=>true,'batch_id'=>$id]+$result,201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062)fail('Se detectó un RUT duplicado durante la importación. Revisa nuevamente; no se guardó el lote.',409);throw $e;}
    });
}
