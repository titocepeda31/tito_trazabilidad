<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

// Only the explicit latest sale of an equipment unit may open a service case.
function serviceSold(PDO $db,int $biz,int $unit,int $sale): array {
    $s=$db->prepare("SELECT su.unit_id,su.sale_id,x.serial_number,x.status AS unit_status,s.status AS sale_status,ss.client_id,ss.client_name,p.name AS product_name,m.name AS manufacturer_name,DATE(s.created_at) AS sale_date FROM serial_sale_units su JOIN serial_units x ON x.id=su.unit_id AND x.business_id=su.business_id JOIN serial_sale_snapshots ss ON ss.sale_id=su.sale_id AND ss.business_id=su.business_id JOIN sales s ON s.id=su.sale_id AND s.business_id=su.business_id JOIN products p ON p.id=x.product_id AND p.business_id=x.business_id LEFT JOIN product_origins po ON po.product_id=p.id AND po.business_id=p.business_id LEFT JOIN manufacturers m ON m.id=po.manufacturer_id AND m.business_id=p.business_id WHERE su.business_id=? AND su.unit_id=? AND su.sale_id=? AND NOT EXISTS(SELECT 1 FROM serial_sale_units later WHERE later.unit_id=su.unit_id AND later.sale_id>su.sale_id) FOR UPDATE");
    $s->execute([$biz,$unit,$sale]);$r=$s->fetch();$s->closeCursor();
    if(!$r||$r['sale_status']!=='completed'||!in_array($r['unit_status'],['sold','warranty_hold'],true))fail('La serie no corresponde a una venta vigente o equipo en garantía.',409);
    return $r;
}
function serviceText(array $b,string $key,int $max,bool $required=true): ?string {
    $v=$b[$key]??null;if(!$required&&($v===null||$v===''))return null;
    if(!is_string($v)||mb_strlen(trim($v))<3||mb_strlen(trim($v))>$max)fail('Campo inválido: '.$key,422);
    return trim($v);
}
function registerServiceRoutes(RouteCollectorProxy $group): void {
    $group->get('/service/equipment',static function(Request $request,Response $response): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$q=$request->getQueryParams()['q']??'';
        if(!is_string($q)||mb_strlen($q)>120||mb_strlen(trim($q))<2)fail('Ingresa al menos dos caracteres de la serie.',422);
        $s=db()->prepare("SELECT su.unit_id,su.sale_id,x.serial_number,x.status AS unit_status,p.name AS product_name,ss.client_id,ss.client_name,DATE(s.created_at) AS sale_date,m.name AS manufacturer_name,w.id AS warranty_id,w.starts_on,w.ends_on,w.terms,w.manufacturer_name AS warranty_manufacturer FROM serial_sale_units su JOIN serial_units x ON x.id=su.unit_id AND x.business_id=su.business_id JOIN serial_sale_snapshots ss ON ss.sale_id=su.sale_id AND ss.business_id=su.business_id JOIN sales s ON s.id=su.sale_id AND s.business_id=su.business_id JOIN products p ON p.id=x.product_id AND p.business_id=x.business_id LEFT JOIN product_origins po ON po.product_id=p.id AND po.business_id=p.business_id LEFT JOIN manufacturers m ON m.id=po.manufacturer_id AND m.business_id=p.business_id LEFT JOIN unit_warranties w ON w.unit_id=su.unit_id AND w.sale_id=su.sale_id AND w.business_id=su.business_id WHERE su.business_id=? AND LOCATE(?,x.serial_number)>0 AND s.status='completed' AND x.status IN ('sold','warranty_hold') AND NOT EXISTS(SELECT 1 FROM serial_sale_units later WHERE later.unit_id=su.unit_id AND later.sale_id>su.sale_id) ORDER BY x.serial_number,su.unit_id LIMIT 51");
        $s->execute([$biz,mb_strtoupper(trim($q),'UTF-8')]);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($rows,0,50),'more'=>count($rows)>50,'today'=>quoteToday()]);
    });
    $group->post('/service/warranties',static function(Request $request,Response $response): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['unit_id','sale_id','manufacturer_name','starts_on','ends_on','terms','evidence_reference']))fail('Campos no permitidos.',422);
        $unit=originId($b['unit_id']??null,'unit_id');$sale=originId($b['sale_id']??null,'sale_id');if(!$unit||!$sale)fail('Selecciona equipo y venta.',422);
        $manufacturer=serviceText($b,'manufacturer_name',150);$start=reservationDate($b['starts_on']??null);$end=reservationDate($b['ends_on']??null);$terms=serviceText($b,'terms',1000);$ref=serviceText($b,'evidence_reference',250,false);
        if($end<$start)fail('Fecha final anterior a inicio.',422);
        $db=db();$db->beginTransaction();try{
            originLockCompany($db,$biz);$sold=serviceSold($db,$biz,$unit,$sale);
            $s=$db->prepare('SELECT id,manufacturer_name,starts_on,ends_on,terms,evidence_reference FROM unit_warranties WHERE business_id=? AND unit_id=? AND sale_id=? FOR UPDATE');$s->execute([$biz,$unit,$sale]);$old=$s->fetch();$s->closeCursor();
            if($old){if([$old['manufacturer_name'],$old['starts_on'],$old['ends_on'],$old['terms'],$old['evidence_reference']]!==[$manufacturer,$start,$end,$terms,$ref])fail('Ya existe una garantía registrada. No se sobrescriben condiciones históricas.',409);$db->commit();return respond($response,['ok'=>true,'warranty_id'=>(int)$old['id'],'already_recorded'=>true]);}
            $s=$db->prepare('INSERT INTO unit_warranties(business_id,sale_id,unit_id,client_id,manufacturer_name,starts_on,ends_on,terms,evidence_reference,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)');$s->execute([$biz,$sale,$unit,$sold['client_id'],$manufacturer,$start,$end,$terms,$ref,$uid]);$id=(int)$db->lastInsertId();$db->commit();return respond($response,['ok'=>true,'warranty_id'=>$id],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->get('/service/cases',static function(Request $request,Response $response): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);$filter=$p['filter']??'open';
        if($page===false||!in_array($filter,['open','closed','all'],true))fail('Filtro inválido.',422);
        $where=$filter==='open'?" AND c.status<>'closed'":($filter==='closed'?" AND c.status='closed'":"");
        $offset=($page-1)*25;$s=db()->prepare("SELECT c.id,c.sale_id,c.unit_id,c.status,c.custody,c.problem,c.revision,c.created_at,x.serial_number,p.name AS product_name,h.name AS client_name FROM service_cases c JOIN serial_units x ON x.id=c.unit_id AND x.business_id=c.business_id JOIN products p ON p.id=x.product_id AND p.business_id=c.business_id JOIN habitual_clients h ON h.id=c.client_id AND h.business_id=c.business_id WHERE c.business_id=?".$where.' ORDER BY c.id DESC LIMIT 26 OFFSET '.$offset);$s->execute([$biz]);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25]);
    });
    $group->get('/service/cases/{id:[0-9]+}',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$id=(int)$args['id'];$db=db();
        $s=$db->prepare('SELECT c.*,x.serial_number,p.name AS product_name,h.name AS client_name,w.manufacturer_name,w.starts_on,w.ends_on,w.terms FROM service_cases c JOIN serial_units x ON x.id=c.unit_id AND x.business_id=c.business_id JOIN products p ON p.id=x.product_id AND p.business_id=c.business_id JOIN habitual_clients h ON h.id=c.client_id AND h.business_id=c.business_id LEFT JOIN unit_warranties w ON w.id=c.warranty_id AND w.business_id=c.business_id WHERE c.business_id=? AND c.id=?');$s->execute([$biz,$id]);$c=$s->fetch();$s->closeCursor();if(!$c)fail('Caso no encontrado.',404);unset($c['payload_hash'],$c['request_id']);
        $s=$db->prepare('SELECT e.id,e.from_status,e.to_status,e.custody,e.note,e.evidence_reference,e.created_at,u.email AS actor_email FROM service_case_events e JOIN users u ON u.id=e.actor_id AND u.business_id=e.business_id WHERE e.business_id=? AND e.case_id=? ORDER BY e.id DESC');$s->execute([$biz,$id]);$events=$s->fetchAll();$s->closeCursor();return respond($response,['case'=>$c,'events'=>$events]);
    });
    $group->post('/service/cases',static function(Request $request,Response $response): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','unit_id','sale_id','problem','custody','evidence_reference']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);$unit=originId($b['unit_id']??null,'unit_id');$sale=originId($b['sale_id']??null,'sale_id');$problem=serviceText($b,'problem',1000);$ref=serviceText($b,'evidence_reference',250,false);$custody=$b['custody']??null;if(!$unit||!$sale||!in_array($custody,['customer','service','manufacturer'],true))fail('Equipo o custodia inválidos.',422);
        $hash=hash('sha256',json_encode([$uid,$unit,$sale,$problem,$custody,$ref],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$db=db();$db->beginTransaction();try{
            originLockCompany($db,$biz);$s=$db->prepare('SELECT id,payload_hash FROM service_cases WHERE business_id=? AND request_id=?');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();if($old){if(!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'case_id'=>(int)$old['id'],'already_recorded'=>true]);}
            $sold=serviceSold($db,$biz,$unit,$sale);
            $s=$db->prepare("SELECT id FROM service_cases WHERE business_id=? AND unit_id=? AND status<>'closed' FOR UPDATE");$s->execute([$biz,$unit]);$open=$s->fetchColumn();$s->closeCursor();if($open)fail('Ya existe un caso abierto para este equipo.',409);
            $s=$db->prepare('SELECT id FROM unit_warranties WHERE business_id=? AND unit_id=? AND sale_id=?');$s->execute([$biz,$unit,$sale]);$w=$s->fetchColumn();$s->closeCursor();
            $s=$db->prepare('INSERT INTO service_cases(business_id,sale_id,unit_id,client_id,warranty_id,custody,problem,created_by,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');$s->execute([$biz,$sale,$unit,$sold['client_id'],$w?:null,$custody,$problem,$uid,$rid,$hash]);$id=(int)$db->lastInsertId();
            $s=$db->prepare("INSERT INTO service_case_events(business_id,case_id,actor_id,from_status,to_status,custody,note,evidence_reference,request_id,payload_hash) VALUES(?,?,?,NULL,'received',?,?,?,?,?)");$s->execute([$biz,$id,$uid,$custody,$problem,$ref,$rid,$hash]);$db->commit();return respond($response,['ok'=>true,'case_id'=>$id],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/service/cases/{id:[0-9]+}/events',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$id=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','revision','status','custody','note','evidence_reference']))fail('Campos no permitidos.',422);
        [$rid,$revision,$hash]=quoteCommand($b);$hash=hash('sha256',$id.':'.$uid.':'.$hash);
        $to=$b['status']??null;$custody=$b['custody']??null;$note=serviceText($b,'note',1000);$ref=serviceText($b,'evidence_reference',250,false);if(!in_array($custody,['customer','service','manufacturer'],true)||!in_array($to,['received','diagnosis','repair','ready','closed'],true))fail('Estado o custodia inválidos.',422);
        $db=db();$db->beginTransaction();try{
            originLockCompany($db,$biz);$s=$db->prepare('SELECT id,status,revision FROM service_cases WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$id]);$c=$s->fetch();$s->closeCursor();if(!$c)fail('Caso no encontrado.',404);
            $s=$db->prepare('SELECT case_id,payload_hash FROM service_case_events WHERE business_id=? AND request_id=?');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();if($old){if((int)$old['case_id']!==$id||!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'already_recorded'=>true]);}
            $allowed=['received'=>['received','diagnosis','closed'],'diagnosis'=>['diagnosis','repair','ready','closed'],'repair'=>['repair','diagnosis','ready'],'ready'=>['ready','repair','closed'],'closed'=>[]];
            if((int)$c['revision']!==$revision||!in_array($to,$allowed[$c['status']],true))fail('Estado cambió o transición no permitida.',409);
            if($to==='closed'&&$custody!=='customer')fail('Confirma entrega o custodia final del cliente antes de cerrar. Las devoluciones financieras se gestionan por separado.',422);
            $s=$db->prepare("UPDATE service_cases SET status=?,custody=?,revision=revision+1,closed_at=CASE WHEN ?='closed' THEN UTC_TIMESTAMP() ELSE NULL END WHERE business_id=? AND id=?");$s->execute([$to,$custody,$to,$biz,$id]);
            $s=$db->prepare('INSERT INTO service_case_events(business_id,case_id,actor_id,from_status,to_status,custody,note,evidence_reference,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');$s->execute([$biz,$id,$uid,$c['status'],$to,$custody,$note,$ref,$rid,$hash]);$db->commit();return respond($response,['ok'=>true],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
