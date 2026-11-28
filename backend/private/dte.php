<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function dteOne(PDO $db,string $sql,array $args):?array{
    $s=$db->prepare($sql);$s->execute($args);$r=$s->fetch();$s->closeCursor();return $r?:null;
}
function dteConfig(PDO $db,int $biz):?array{
    $r=dteOne($db,'SELECT id,provider,issuer_json,price_basis,evidence_reference,created_at FROM dte_config_versions WHERE business_id=? ORDER BY id DESC LIMIT 1',[$biz]);
    if($r){$r['issuer']=json_decode($r['issuer_json'],true,512,JSON_THROW_ON_ERROR);unset($r['issuer_json']);}return $r;
}
function dteProfile(PDO $db,int $biz):array{
    $r=dteOne($db,'SELECT legal_name,tax_id,address,city,email FROM business_profiles WHERE business_id=?',[$biz]);return $r??[];
}
function dteText(array $b,string $key,int $max,bool $required=true):?string{
    return titoCreditString($b,$key,$max,$required);
}
function dteSaleSnapshot(PDO $db,int $biz,int $sale,int $type,array $config):array{
    $s=dteOne($db,"SELECT s.id,s.status,s.created_at,s.total_clp,s.payment_method,s.client_id,s.client_name_snapshot,s.client_rut_snapshot,s.customer_mode,h.name AS current_name,h.rut AS current_rut,h.business_activity,h.address,h.commune,h.billing_email,a.id AS credit_account_id,a.due_date FROM sales s LEFT JOIN habitual_clients h ON h.business_id=s.business_id AND h.id=s.client_id LEFT JOIN credit_accounts a ON a.business_id=s.business_id AND a.sale_id=s.id WHERE s.business_id=? AND s.id=? FOR UPDATE",[$biz,$sale]);
    if(!$s)fail('Venta no encontrada.',404);
    if($s['status']!=='completed')fail('Solo se preparan documentos para ventas vigentes.',409);
    if(dteOne($db,'SELECT id FROM customer_returns WHERE business_id=? AND sale_id=? LIMIT 1',[$biz,$sale])||dteOne($db,'SELECT ca.id FROM credit_adjustments ca JOIN credit_accounts a ON a.id=ca.account_id AND a.business_id=ca.business_id WHERE a.business_id=? AND a.sale_id=? LIMIT 1',[$biz,$sale]))fail('Venta con ajustes o devoluciones: requiere conciliación tributaria específica.',409);
    $recipient=null;
    if($s['client_id']!==null){
        $rut=titoCommercialRut($s['current_rut']);
        $historical=titoCommercialRut($s['client_rut_snapshot']);
        if($historical!==null&&$rut!==$historical)fail('El RUT actual no coincide con el de la venta. Conciliar identidad.',409);
        if($s['current_name']===null)fail('Ficha de cliente no disponible.',409);
        $recipient=['client_id'=>(int)$s['client_id'],'name'=>$s['current_name'],'rut'=>$rut,'business_activity'=>$s['business_activity'],'address'=>$s['address'],'commune'=>$s['commune'],'billing_email'=>$s['billing_email'],'sale_name_snapshot'=>$s['client_name_snapshot'],'sale_rut_snapshot'=>$s['client_rut_snapshot']];
    }
    if($type===33){
        if(!$recipient||!$recipient['rut']||!trim((string)$recipient['name'])||!trim((string)$recipient['business_activity'])||!trim((string)$recipient['address'])||!trim((string)$recipient['commune']))fail('Factura: completa RUT, razón social/nombre, giro, dirección y comuna del cliente.',422);
        if($recipient['sale_rut_snapshot']===null)fail('Venta sin RUT histórico: confirmar identidad tributaria antes de preparar factura.',409);
    }
    $st=$db->prepare('SELECT si.id AS sale_item_id,si.product_id,COALESCE(si.product_name_snapshot,p.name) AS product_name,p.sku,si.quantity,si.unit_price_clp,si.subtotal_clp FROM sale_items si JOIN products p ON p.id=si.product_id AND p.business_id=? WHERE si.sale_id=? ORDER BY si.id');
    $st->execute([$biz,$sale]);$lines=$st->fetchAll();$st->closeCursor();
    if(!$lines||count($lines)>80)fail('Detalle de venta no admitido.',409);
    $sum=0;foreach($lines as &$line){
        $sum+=(int)$line['subtotal_clp'];$line['serial_numbers']=serialSaleLineUnits($db,$biz,$sale,(int)$line['sale_item_id']);
    }unset($line);
    if($sum!==(int)$s['total_clp'])fail('Detalle de venta no concilia con su total.',409);
    return ['version'=>1,'purpose'=>'internal_draft_not_tax_document','document_type'=>$type,'prepared_on'=>quoteToday(),'sale_id'=>$sale,'sale_date'=>$s['created_at'],'issuer'=>$config['issuer'],'config_id'=>(int)$config['id'],'recipient'=>$recipient,'payment'=>['commercial_method'=>$s['payment_method'],'credit_account_id'=>$s['credit_account_id'],'due_date'=>$s['due_date']],'lines'=>$lines,'commercial_total_clp'=>$sum,'price_basis'=>$config['price_basis'],'tax_status'=>'requires_validation','official_folio'=>null];
}
function openFacturaDevelopmentProbe(int $biz):array{
    // No user-controlled URL, no production endpoint, no secrets in DB/browser/logs.
    $key=getenv('TITO_OPENFACTURA_DEV_KEY_'.$biz);
    if(!is_string($key)||trim($key)==='')return ['status'=>'not_configured'];
    if(!function_exists('curl_init'))return ['status'=>'curl_unavailable'];
    if(preg_match('/[\r\n]/',$key))return ['status'=>'invalid_server_configuration'];
    $ch=curl_init('https://dev-api.haulmer.com/v2/dte/organization');
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Accept: application/json','apikey: '.$key],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($raw===false||$code===0)return ['status'=>'unreachable'];
    if($code!==200)return ['status'=>'provider_error','http_status'=>$code];
    $data=json_decode($raw,true);if(!is_array($data)||!isset($data['rut'])||!is_scalar($data['rut']))return ['status'=>'unexpected_response'];
    return ['status'=>'connected','sandbox_rut'=>(string)$data['rut']];
}
function registerDteRoutes(RouteCollectorProxy $group):void{
    $group->get('/dte/config',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];return respond($response,['profile'=>dteProfile(db(),$biz),'config'=>dteConfig(db(),$biz),'environment'=>'development','production_enabled'=>false,'sandbox_key_configured'=>(bool)getenv('TITO_OPENFACTURA_DEV_KEY_'.$biz)]);
    });
    $group->post('/dte/config',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','expected_config_id','business_activity','activity_code','commune','price_basis','evidence_reference']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);$expected=originId($b['expected_config_id']??null,'expected_config_id');$basis=$b['price_basis']??'unknown';
        if(!in_array($basis,['unknown','gross','net'],true))fail('Base de precios inválida.',422);
        $activity=dteText($b,'business_activity',80);$code=dteText($b,'activity_code',6);$commune=dteText($b,'commune',20);$ref=dteText($b,'evidence_reference',250);
        if(!preg_match('/^[0-9]{6}$/D',$code))fail('Código de actividad SII de seis dígitos.',422);
        $hash=hash('sha256',json_encode([$uid,$expected,$activity,$code,$commune,$basis,$ref],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $old=dteOne($db,'SELECT id,payload_hash FROM dte_config_versions WHERE business_id=? AND request_id=?',[$biz,$rid]);
            if($old){if(!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'config_id'=>(int)$old['id'],'already_recorded'=>true]);}
            $current=dteConfig($db,$biz);if(($current?(int)$current['id']:null)!==$expected)fail('Configuración modificada por otro usuario.',409);
            $profile=dteProfile($db,$biz);$rut=titoCommercialRut($profile['tax_id']??null);
            if(!$rut||!trim((string)($profile['legal_name']??''))||!trim((string)($profile['address']??''))||!trim((string)($profile['city']??'')))fail('Completa la ficha de empresa: RUT válido, razón social, dirección y ciudad.',422);
            $issuer=['rut'=>$rut,'legal_name'=>$profile['legal_name'],'address'=>$profile['address'],'city'=>$profile['city'],'email'=>$profile['email'],'business_activity'=>$activity,'activity_code'=>$code,'commune'=>$commune];
            $s=$db->prepare('INSERT INTO dte_config_versions(business_id,actor_id,issuer_json,price_basis,evidence_reference,request_id,payload_hash) VALUES(?,?,?,?,?,?,?)');$s->execute([$biz,$uid,json_encode($issuer,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$basis,$ref,$rid,$hash]);$id=(int)$db->lastInsertId();$db->commit();return respond($response,['ok'=>true,'config_id'=>$id],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/dte/sandbox/check',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$result=openFacturaDevelopmentProbe($biz);
        return respond($response,$result+['environment'=>'development','production_enabled'=>false]);
    });
    $group->get('/dte/documents',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);$sale=originId($p['sale_id']??null,'sale_id');
        if($page===false)fail('Página inválida.',422);
        $sql='SELECT d.id,d.sale_id,d.document_type,d.status,d.commercial_total_clp,d.created_at,d.discarded_at,d.discard_reason,s.status AS sale_status FROM dte_documents d JOIN sales s ON s.id=d.sale_id AND s.business_id=d.business_id WHERE d.business_id=?';$args=[$biz];if($sale!==null){$sql.=' AND d.sale_id=?';$args[]=$sale;}
        $s=db()->prepare($sql.' ORDER BY d.id DESC LIMIT 26 OFFSET '.(($page-1)*25));$s->execute($args);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25]);
    });
    $group->get('/dte/documents/{id:[0-9]+}',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$r=dteOne(db(),'SELECT d.id,d.sale_id,d.document_type,d.status,d.snapshot_json,d.created_at,d.discarded_at,d.discard_reason,s.status AS sale_status FROM dte_documents d JOIN sales s ON s.id=d.sale_id AND s.business_id=d.business_id WHERE d.business_id=? AND d.id=?',[$biz,(int)$args['id']]);if(!$r)fail('Borrador no encontrado.',404);
        $r['snapshot']=json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR);unset($r['snapshot_json']);return respond($response,['document'=>$r]);
    });
    $group->post('/dte/documents',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','sale_id','document_type','config_id']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);$sale=originId($b['sale_id']??null,'sale_id');$cid=originId($b['config_id']??null,'config_id');$type=$b['document_type']??null;
        if(!$sale||!$cid||!in_array($type,[33,39],true))fail('Selecciona venta y factura o boleta.',422);
        $hash=hash('sha256',json_encode([$uid,$sale,$cid,$type],JSON_THROW_ON_ERROR));$db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $old=dteOne($db,'SELECT id,payload_hash FROM dte_documents WHERE business_id=? AND request_id=?',[$biz,$rid]);if($old){if(!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'document_id'=>(int)$old['id'],'already_recorded'=>true]);}
            $config=dteConfig($db,$biz);if(!$config||(int)$config['id']!==$cid)fail('Actualiza la configuración tributaria.',409);
            $profile=dteProfile($db,$biz);if(titoCommercialRut($profile['tax_id']??null)!==$config['issuer']['rut']||($profile['legal_name']??null)!==$config['issuer']['legal_name']||($profile['address']??null)!==$config['issuer']['address']||($profile['city']??null)!==$config['issuer']['city'])fail('La ficha de empresa cambió: registra una nueva versión tributaria.',409);
            if(dteOne($db,'SELECT id FROM dte_documents WHERE business_id=? AND active_sale_id=?',[$biz,$sale]))fail('Ya hay un borrador activo para esta venta. Revísalo o descártalo.',409);
            $snapshot=dteSaleSnapshot($db,$biz,$sale,$type,$config);
            $s=$db->prepare('INSERT INTO dte_documents(business_id,sale_id,active_sale_id,config_id,document_type,snapshot_json,commercial_total_clp,actor_id,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$biz,$sale,$sale,$cid,$type,json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$snapshot['commercial_total_clp'],$uid,$rid,$hash]);$id=(int)$db->lastInsertId();$db->commit();return respond($response,['ok'=>true,'document_id'=>$id],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062)fail('Ya existe un borrador activo o solicitud duplicada.',409);throw $e;}
    });
    $group->post('/dte/documents/{id:[0-9]+}/discard',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$id=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['reason']))fail('Campos no permitidos.',422);$reason=dteText($b,'reason',500);$db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $old=dteOne($db,'SELECT status,discard_reason,discarded_by FROM dte_documents WHERE business_id=? AND id=? FOR UPDATE',[$biz,$id]);if(!$old)fail('Borrador no encontrado.',404);
            if($old['status']==='discarded'){if($old['discard_reason']!==$reason||(int)$old['discarded_by']!==$uid)fail('Borrador previamente descartado.',409);$db->commit();return respond($response,['ok'=>true,'already_recorded'=>true]);}
            $s=$db->prepare("UPDATE dte_documents SET status='discarded',active_sale_id=NULL,discard_reason=?,discarded_by=?,discarded_at=UTC_TIMESTAMP() WHERE business_id=? AND id=? AND status='draft'");$s->execute([$reason,$uid,$biz,$id]);if($s->rowCount()!==1)fail('Estado modificado.',409);
            $db->commit();return respond($response,['ok'=>true]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
