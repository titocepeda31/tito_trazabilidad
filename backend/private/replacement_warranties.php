<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function replacementCoverageLatestSql():string {
    return "SELECT r.id,r.exchange_id,r.kind,r.action,r.decision,r.starts_on,r.ends_on,r.terms,r.manufacturer_name,r.activation_status,r.notified_on,r.confirmed_on,r.event_on,r.note,r.evidence_reference,r.created_at,e.sale_id,e.client_id,e.root_unit_id,e.replacement_unit_id,x.serial_number,p.name AS product_name FROM replacement_coverage_events r JOIN warranty_free_exchanges e ON e.id=r.exchange_id AND e.business_id=r.business_id JOIN serial_units x ON x.id=e.replacement_unit_id AND x.business_id=e.business_id JOIN products p ON p.id=x.product_id AND p.business_id=e.business_id WHERE NOT EXISTS(SELECT 1 FROM replacement_coverage_events n WHERE n.business_id=r.business_id AND n.exchange_id=r.exchange_id AND n.kind=r.kind AND n.id>r.id)";
}
function replacementCoverageSummary(PDO $db,int $biz,int $exchange):array {
    $s=$db->prepare(replacementCoverageLatestSql().' AND r.business_id=? AND r.exchange_id=?');$s->execute([$biz,$exchange]);$rows=$s->fetchAll();$s->closeCursor();
    $by=[];foreach($rows as $r)$by[$r['kind']]=$r;
    $out=[];foreach(['legal','satisfaction','manufacturer'] as $k)$out[]=$by[$k]??['id'=>null,'exchange_id'=>$exchange,'kind'=>$k,'decision'=>'pending','activation_status'=>$k==='manufacturer'?'unknown':'not_required','starts_on'=>null,'ends_on'=>null,'terms'=>'Condiciones pendientes de revisión documentada.'];
    return $out;
}
function replacementCoveragesForUnit(PDO $db,int $biz,int $sale,int $physical):array {
    $s=$db->prepare('SELECT id FROM warranty_free_exchanges WHERE business_id=? AND sale_id=? AND replacement_unit_id=? ORDER BY id DESC LIMIT 1');$s->execute([$biz,$sale,$physical]);$id=$s->fetchColumn();$s->closeCursor();return $id?replacementCoverageSummary($db,$biz,(int)$id):[];
}
function registerReplacementWarrantyRoutes(RouteCollectorProxy $group):void {
    $group->get('/warranties/replacements',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$q=$p['q']??'';$filter=$p['filter']??'all';$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if($page===false||!is_string($q)||mb_strlen($q)>120||!in_array($filter,['all','review','activation'],true))fail('Filtro inválido.',422);
        $where=' WHERE e.business_id=?';$params=[$biz];
        if($filter==='review')$where.=" AND (SELECT COUNT(*) FROM replacement_coverage_events r WHERE r.business_id=e.business_id AND r.exchange_id=e.id AND r.decision<>'pending' AND NOT EXISTS(SELECT 1 FROM replacement_coverage_events n WHERE n.business_id=r.business_id AND n.exchange_id=r.exchange_id AND n.kind=r.kind AND n.id>r.id))<3";
        if($filter==='activation')$where.=" AND y.status='replacement_delivered' AND NOT EXISTS(SELECT 1 FROM warranty_free_exchanges later WHERE later.business_id=e.business_id AND later.sale_id=e.sale_id AND later.root_unit_id=e.root_unit_id AND later.returned_unit_id=e.replacement_unit_id) AND COALESCE((SELECT r.activation_status FROM replacement_coverage_events r WHERE r.business_id=e.business_id AND r.exchange_id=e.id AND r.kind='manufacturer' ORDER BY r.id DESC LIMIT 1),'unknown') IN ('unknown','pending','reported','observed')";
        if(trim($q)!==''){$where.=" AND (LOCATE(?,x.serial_number)>0 OR LOCATE(?,y.serial_number)>0 OR CAST(e.sale_id AS CHAR)=? OR LOCATE(?,h.name)>0)";$params=array_merge($params,[mb_strtoupper(trim($q),'UTF-8'),mb_strtoupper(trim($q),'UTF-8'),trim($q),trim($q)]);}
        $db=db();$s=$db->prepare(freeExchangeSql().$where.' ORDER BY e.id DESC LIMIT 26 OFFSET '.(($page-1)*25));$s->execute($params);$rows=$s->fetchAll();$s->closeCursor();$more=count($rows)>25;$rows=array_slice($rows,0,25);foreach($rows as &$row)$row['coverages']=replacementCoverageSummary($db,$biz,(int)$row['id']);unset($row);
        return respond($response,['rows'=>$rows,'more'=>$more]);
    });
    $group->get('/warranties/replacements/{id:[0-9]+}',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$id=(int)$args['id'];$db=db();
        $s=$db->prepare(freeExchangeSql().' WHERE e.business_id=? AND e.id=?');$s->execute([$biz,$id]);$e=$s->fetch();$s->closeCursor();if(!$e)fail('Cambio no encontrado.',404);
        $s=$db->prepare('SELECT r.id,r.kind,r.action,r.decision,r.starts_on,r.ends_on,r.terms,r.manufacturer_name,r.activation_status,r.notified_on,r.confirmed_on,r.event_on,r.note,r.evidence_reference,r.created_at,u.email AS actor_email FROM replacement_coverage_events r JOIN users u ON u.id=r.actor_id AND u.business_id=r.business_id WHERE r.business_id=? AND r.exchange_id=? ORDER BY r.id DESC');$s->execute([$biz,$id]);$events=$s->fetchAll();$s->closeCursor();
        $active=freeExchangeAssignment($db,$biz,(int)$e['sale_id'],(int)$e['replacement_unit_id']);
        return respond($response,['exchange'=>$e,'coverages'=>replacementCoverageSummary($db,$biz,$id),'original_coverages'=>warrantyForUnit($db,$biz,(int)$e['sale_id'],(int)$e['root_unit_id']),'events'=>$events,'active'=>($active!==null&&$active['sale_status']==='completed'&&(int)$active['root_unit_id']===(int)$e['root_unit_id']),'today'=>quoteToday()]);
    });
    $group->post('/warranties/replacements/{id:[0-9]+}/events',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$id=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','expected_event_id','kind','action','decision','starts_on','ends_on','terms','manufacturer_name','notification','event_on','note','evidence_reference']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);$expected=originId($b['expected_event_id']??null,'expected_event_id');$kind=$b['kind']??null;$action=$b['action']??null;$day=reservationDate($b['event_on']??null);$note=warrantyText($b,'note',1000);$ref=warrantyText($b,'evidence_reference',250);
        if(!in_array($kind,['legal','satisfaction','manufacturer'],true)||!in_array($action,['review','reported','confirmed','observed'],true)||$day>quoteToday())fail('Evento inválido.',422);
        $hash=hash('sha256',json_encode([$uid,$id,$b],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $s=$db->prepare('SELECT id,payload_hash FROM replacement_coverage_events WHERE business_id=? AND request_id=?');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();if($old){if(!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'event_id'=>(int)$old['id'],'already_recorded'=>true]);}
            $s=$db->prepare('SELECT id,sale_id,root_unit_id,replacement_unit_id,delivered_on FROM warranty_free_exchanges WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$id]);$e=$s->fetch();$s->closeCursor();if(!$e)fail('Cambio no encontrado.',404);
            if($day<$e['delivered_on'])fail('Evento anterior a la entrega del sustituto.',422);
            $s=$db->prepare('SELECT * FROM replacement_coverage_events WHERE business_id=? AND exchange_id=? AND kind=? ORDER BY id DESC LIMIT 1 FOR UPDATE');$s->execute([$biz,$id,$kind]);$last=$s->fetch();$s->closeCursor();if(($last?(int)$last['id']:null)!==$expected)fail('Cobertura modificada por otro usuario.',409);
            if($last&&$day<$last['event_on'])fail('Fecha anterior al último evento; registrar aclaración con fecha actual.',422);
            $notified=$last['notified_on']??null;$confirmed=$last['confirmed_on']??null;
            if($action==='review'){
                $decision=$b['decision']??null;$terms=warrantyText($b,'terms',1000);$manufacturer=$kind==='manufacturer'?serviceText($b,'manufacturer_name',150,false):null;
                if(!in_array($decision,['pending','continues','specific','not_offered'],true)||($kind==='legal'&&$decision==='not_offered'))fail('Decisión inválida; no se permite denegar derechos legales desde esta configuración.',422);
                $start=$end=null;
                if($decision==='specific'){$start=reservationDate($b['starts_on']??null);$end=reservationDate($b['ends_on']??null);if($start<$e['delivered_on']||$end<$start)fail('Fechas específicas inválidas.',422);}
                elseif(($b['starts_on']??null)!==null||($b['ends_on']??null)!==null)fail('Las fechas específicas requieren esa decisión.',422);
                $activation='not_required';
                if($kind==='manufacturer'){
                    $notification=$b['notification']??null;if(!in_array($notification,['unknown','pending','not_required'],true))fail('Define requisito de comunicación.',422);
                    if($decision==='not_offered'&&$notification==='pending')fail('Revisa condiciones de cobertura y comunicación.',422);
                    if($notification==='pending'&&!$manufacturer)fail('Identifica fabricante receptor.',422);
                    if($notified!==null){
                        if($notification!=='pending'||$manufacturer!==$last['manufacturer_name']||$decision==='not_offered')fail('Existe comunicación registrada; conserva su receptor y requisito. Conciliar correcciones por separado.',409);
                        $activation=$last['activation_status'];
                    }else $activation=$notification;
                }
            }else{
                $active=freeExchangeAssignment($db,$biz,(int)$e['sale_id'],(int)$e['replacement_unit_id'],true);
                if(!$active||$active['sale_status']!=='completed'||(int)$active['root_unit_id']!==(int)$e['root_unit_id'])fail('El sustituto ya no está asignado a esta venta; conservar historial.',409);
                $allowed=['pending'=>['reported'],'reported'=>['confirmed','observed'],'observed'=>['reported']];
                if($kind!=='manufacturer'||!$last||!$last['manufacturer_name']||!in_array($action,$allowed[$last['activation_status']]??[],true))fail('Comunicación no corresponde al estado actual.',409);
                $decision=$last['decision'];$start=$last['starts_on'];$end=$last['ends_on'];$terms=$last['terms'];$manufacturer=$last['manufacturer_name'];$activation=$action;
                if($action==='reported'){$notified=$day;$confirmed=null;}elseif($action==='confirmed')$confirmed=$day;
            }
            $s=$db->prepare('INSERT INTO replacement_coverage_events(business_id,exchange_id,kind,action,decision,starts_on,ends_on,terms,manufacturer_name,activation_status,notified_on,confirmed_on,event_on,note,evidence_reference,actor_id,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$biz,$id,$kind,$action,$decision,$start,$end,$terms,$manufacturer,$activation,$notified,$confirmed,$day,$note,$ref,$uid,$rid,$hash]);$event=(int)$db->lastInsertId();$db->commit();return respond($response,['ok'=>true,'event_id'=>$event],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
