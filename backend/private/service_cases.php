<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

// Only the explicit latest sale of an equipment unit may open a service case.
function serviceSold(PDO $db,int $biz,int $unit,int $sale): array {
    $replacement=freeExchangeAssignment($db,$biz,$sale,$unit,true);
    if($replacement){if($replacement['sale_status']!=='completed')fail('Venta original no vigente.',409);return $replacement;}
    $check=$db->prepare('SELECT 1 FROM warranty_free_exchanges WHERE business_id=? AND sale_id=? AND returned_unit_id=? LIMIT 1');$check->execute([$biz,$sale,$unit]);$used=$check->fetchColumn();$check->closeCursor();if($used)fail('Equipo sustituido; selecciona la serie entregada al cliente.',409);
    $s=$db->prepare("SELECT su.unit_id,su.sale_id,x.serial_number,x.status AS unit_status,s.status AS sale_status,ss.client_id,ss.client_name,p.name AS product_name,m.name AS manufacturer_name,DATE(s.created_at) AS sale_date FROM serial_sale_units su JOIN serial_units x ON x.id=su.unit_id AND x.business_id=su.business_id JOIN serial_sale_snapshots ss ON ss.sale_id=su.sale_id AND ss.business_id=su.business_id JOIN sales s ON s.id=su.sale_id AND s.business_id=su.business_id JOIN products p ON p.id=x.product_id AND p.business_id=x.business_id LEFT JOIN product_origins po ON po.product_id=p.id AND po.business_id=p.business_id LEFT JOIN manufacturers m ON m.id=po.manufacturer_id AND m.business_id=p.business_id WHERE su.business_id=? AND su.unit_id=? AND su.sale_id=? AND NOT EXISTS(SELECT 1 FROM serial_sale_units later WHERE later.unit_id=su.unit_id AND later.sale_id>su.sale_id) FOR UPDATE");
    $s->execute([$biz,$unit,$sale]);$r=$s->fetch();$s->closeCursor();
    if(!$r||$r['sale_status']!=='completed'||!in_array($r['unit_status'],['sold','warranty_hold'],true))fail('La serie no corresponde a una venta vigente o equipo en garantía.',409);
    $r['root_unit_id']=$unit;return $r;
}
function serviceText(array $b,string $key,int $max,bool $required=true): ?string {
    $v=$b[$key]??null;if(!$required&&($v===null||$v===''))return null;
    if(!is_string($v)||mb_strlen(trim($v))<3||mb_strlen(trim($v))>$max)fail('Campo inválido: '.$key,422);
    return trim($v);
}
function repairWarrantySql(): string {
    return "SELECT r.*,c.sale_id,c.unit_id,c.client_id,c.prior_repair_id,x.serial_number,p.name AS product_name,h.name AS client_name FROM service_repair_warranties r JOIN service_cases c ON c.id=r.case_id AND c.business_id=r.business_id JOIN serial_units x ON x.id=COALESCE(c.physical_unit_id,c.unit_id) AND x.business_id=c.business_id JOIN products p ON p.id=x.product_id AND p.business_id=c.business_id JOIN habitual_clients h ON h.id=c.client_id AND h.business_id=c.business_id";
}
function repairWarrantiesForUnit(PDO $db,int $biz,int $sale,int $unit): array {
    $s=$db->prepare(repairWarrantySql().' WHERE r.business_id=? AND c.sale_id=? AND COALESCE(c.physical_unit_id,c.unit_id)=? ORDER BY r.id DESC');
    $s->execute([$biz,$sale,$unit]);$rows=$s->fetchAll();$s->closeCursor();return $rows;
}
// One delivered intervention per case. A later intervention is a new case.
function repairWarrantyRecord(PDO $db,int $biz,int $uid,array $c,mixed $data): void {
    if(!is_array($data)||array_diff(array_keys($data),['provider_kind','provider_name','technician_name','work_description','components','billing_kind','completed_on','delivered_on','commercial_duration','commercial_unit','legal_review_basis','legal_review_until','legal_review_note','terms','evidence_reference']))fail('Datos de reparación inválidos.',422);
    $provider=$data['provider_kind']??null;$billing=$data['billing_kind']??null;
    if(!in_array($provider,['internal','external','manufacturer'],true)||!in_array($billing,['warranty','paid','other'],true))fail('Prestador o tipo de atención inválido.',422);
    $name=warrantyText($data,'provider_name',150);$tech=warrantyText($data,'technician_name',150);$work=warrantyText($data,'work_description',1000);$parts=warrantyText($data,'components',1000);$terms=warrantyText($data,'terms',1000);$ref=warrantyText($data,'evidence_reference',250);
    $completed=reservationDate($data['completed_on']??null);$delivered=reservationDate($data['delivered_on']??null);
    // Database timestamps are not used to invent a delivery date.
    if($completed>$delivered||$delivered>quoteToday())fail('Fechas de término o entrega inválidas.',422);
    $n=$data['commercial_duration']??null;$u=$data['commercial_unit']??null;
    if($n!==null){
        if(!is_int($n)||$n<1||$n>3650||!in_array($u,['days','months','years'],true)||($u==='years'&&$n>20)||($u==='months'&&$n>240))fail('Plazo comercial inválido.',422);
    }elseif($u!==null)fail('Indica duración y unidad juntas.',422);
    $basis=$data['legal_review_basis']??null;$until=$data['legal_review_until']??null;$review=$data['legal_review_note']??null;
    if($until!==null){
        if(!in_array($basis,['completion','delivery'],true))fail('Indica la base revisada del plazo legal.',422);
        $until=reservationDate($until);$review=warrantyText($data,'legal_review_note',1000);
        if($until<($basis==='completion'?$completed:$delivered))fail('Fecha revisada anterior a la base.',422);
    }elseif($basis!==null||$review!==null)fail('Completa todos los datos de revisión legal o déjalos pendientes.',422);
    $s=$db->prepare('INSERT INTO service_repair_warranties(business_id,case_id,provider_kind,provider_name,technician_name,work_description,components,billing_kind,completed_on,delivered_on,commercial_duration,commercial_unit,commercial_ends_on,legal_review_basis,legal_review_until,legal_review_note,terms,evidence_reference,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$biz,$c['id'],$provider,$name,$tech,$work,$parts,$billing,$completed,$delivered,$n,$u,warrantyEnd($delivered,$n,$u),$basis,$until,$review,$terms,$ref,$uid]);
}
function replacementSql(): string {
    return "SELECT r.id,r.original_sale_id,r.original_unit_id,r.replacement_sale_id,r.replacement_unit_id,r.client_id,r.case_id,r.delivered_on,r.reason,r.coverage_review,r.evidence_reference,r.created_at,x.serial_number AS original_serial,y.serial_number AS replacement_serial,p.name AS original_product,n.name AS replacement_product,h.name AS client_name,os.status AS original_sale_status,ns.status AS replacement_sale_status,rv.reversal_kind,rv.return_id,rv.credit_adjustment_id FROM warranty_replacements r JOIN serial_units x ON x.id=r.original_unit_id AND x.business_id=r.business_id JOIN serial_units y ON y.id=r.replacement_unit_id AND y.business_id=r.business_id JOIN products p ON p.id=x.product_id AND p.business_id=r.business_id JOIN products n ON n.id=y.product_id AND n.business_id=r.business_id JOIN habitual_clients h ON h.id=r.client_id AND h.business_id=r.business_id JOIN sales os ON os.id=r.original_sale_id AND os.business_id=r.business_id JOIN sales ns ON ns.id=r.replacement_sale_id AND ns.business_id=r.business_id JOIN serial_sale_reversals rv ON rv.unit_id=r.original_unit_id AND rv.sale_id=r.original_sale_id AND rv.business_id=r.business_id";
}
function replacementsForUnit(PDO $db,int $biz,int $sale,int $unit): array {
    $s=$db->prepare(replacementSql().' WHERE r.business_id=? AND ((r.original_sale_id=? AND r.original_unit_id=?) OR (r.replacement_sale_id=? AND r.replacement_unit_id=?)) ORDER BY r.id DESC');
    $s->execute([$biz,$sale,$unit,$sale,$unit]);$rows=$s->fetchAll();$s->closeCursor();return $rows;
}
function replacementSaleUnit(PDO $db,int $biz,int $sale,int $unit): array {
    $s=$db->prepare("SELECT su.unit_id,su.sale_id,x.product_id,x.status AS unit_status,ss.client_id,s.status AS sale_status,s.created_at,rv.reversal_kind,rv.return_id,rv.credit_adjustment_id,cr.resolution FROM serial_sale_units su JOIN serial_units x ON x.id=su.unit_id AND x.business_id=su.business_id JOIN sales s ON s.id=su.sale_id AND s.business_id=su.business_id JOIN serial_sale_snapshots ss ON ss.sale_id=s.id AND ss.business_id=s.business_id LEFT JOIN serial_sale_reversals rv ON rv.unit_id=su.unit_id AND rv.sale_id=su.sale_id AND rv.business_id=su.business_id LEFT JOIN customer_returns cr ON cr.id=rv.return_id AND cr.business_id=su.business_id WHERE su.business_id=? AND su.sale_id=? AND su.unit_id=? FOR UPDATE");
    $s->execute([$biz,$sale,$unit]);$r=$s->fetch();$s->closeCursor();if(!$r)fail('Venta y serie no corresponden.',404);return $r;
}
// A free replacement is an inventory operation against an existing sale entitlement.
// No extra serial_sale_units rows, sale items, receivables or cash movements.
function freeExchangeSql(): string {
    return "SELECT e.id,e.sale_id,e.root_unit_id,e.returned_unit_id,e.replacement_unit_id,e.client_id,e.warehouse_id,e.case_id,e.delivered_on,e.reason,e.coverage_review,e.evidence_reference,e.created_at,x.serial_number AS original_serial,y.serial_number AS replacement_serial,p.name AS product_name,h.name AS client_name,w.name AS warehouse_name,y.status AS replacement_status FROM warranty_free_exchanges e JOIN serial_units x ON x.id=e.returned_unit_id AND x.business_id=e.business_id JOIN serial_units y ON y.id=e.replacement_unit_id AND y.business_id=e.business_id JOIN products p ON p.id=y.product_id AND p.business_id=e.business_id JOIN habitual_clients h ON h.id=e.client_id AND h.business_id=e.business_id JOIN warehouses w ON w.id=e.warehouse_id AND w.business_id=e.business_id";
}
function freeExchangesForUnit(PDO $db,int $biz,int $sale,int $root):array {
    $s=$db->prepare(freeExchangeSql().' WHERE e.business_id=? AND e.sale_id=? AND e.root_unit_id=? ORDER BY e.id DESC');$s->execute([$biz,$sale,$root]);$r=$s->fetchAll();$s->closeCursor();return $r;
}
function freeExchangeAssignment(PDO $db,int $biz,int $sale,int $unit,bool $lock=false): ?array {
    $s=$db->prepare("SELECT e.root_unit_id,e.client_id,e.delivered_on,x.product_id,x.warehouse_id,x.status AS unit_status,s.status AS sale_status FROM warranty_free_exchanges e JOIN serial_units x ON x.id=e.replacement_unit_id AND x.business_id=e.business_id JOIN sales s ON s.id=e.sale_id AND s.business_id=e.business_id WHERE e.business_id=? AND e.sale_id=? AND e.replacement_unit_id=? AND x.status='replacement_delivered' AND NOT EXISTS(SELECT 1 FROM warranty_free_exchanges n WHERE n.business_id=e.business_id AND n.sale_id=e.sale_id AND n.root_unit_id=e.root_unit_id AND n.returned_unit_id=e.replacement_unit_id) ORDER BY e.id DESC LIMIT 1".($lock?' FOR UPDATE':''));
    $s->execute([$biz,$sale,$unit]);$r=$s->fetch();$s->closeCursor();return $r?:null;
}
function freeExchangeOrigin(PDO $db,int $biz,int $sale,int $unit):array {
    $a=freeExchangeAssignment($db,$biz,$sale,$unit,true);
    if($a){if($a['sale_status']!=='completed')fail('Venta original no vigente.',409);return $a;}
    $a=replacementSaleUnit($db,$biz,$sale,$unit);
    if($a['sale_status']!=='completed'||$a['unit_status']!=='sold'||$a['reversal_kind']!==null||!$a['client_id'])fail('Se requiere una unidad vendida vigente, no devuelta ni reembolsada.',409);
    $s=$db->prepare('SELECT 1 FROM serial_sale_units WHERE business_id=? AND unit_id=? AND sale_id>? LIMIT 1');$s->execute([$biz,$unit,$sale]);$later=$s->fetchColumn();$s->closeCursor();if($later)fail('La serie tiene una venta posterior.',409);
    $a['root_unit_id']=$unit;$a['delivered_on']=substr($a['created_at'],0,10);return $a;
}
function registerServiceRoutes(RouteCollectorProxy $group): void {
    $group->get('/service/free-exchanges',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$q=$p['q']??'';$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if($page===false||!is_string($q)||mb_strlen($q)>120)fail('Filtro inválido.',422);
        $where=' WHERE e.business_id=?';$params=[$biz];if(trim($q)!==''){$where.=" AND (LOCATE(?,x.serial_number)>0 OR LOCATE(?,y.serial_number)>0 OR CAST(e.sale_id AS CHAR)=? OR LOCATE(?,h.name)>0)";$params=array_merge($params,[mb_strtoupper(trim($q),'UTF-8'),mb_strtoupper(trim($q),'UTF-8'),trim($q),trim($q)]);}
        $s=db()->prepare(freeExchangeSql().$where.' ORDER BY e.id DESC LIMIT 26 OFFSET '.(($page-1)*25));$s->execute($params);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25,'today'=>quoteToday()]);
    });
    $group->get('/service/free-exchange-stock',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$q=$p['q']??'';
        if(!is_string($q)||mb_strlen(trim($q))<2||mb_strlen($q)>120)fail('Busca serie o producto.',422);
        $s=db()->prepare("SELECT x.id AS unit_id,x.serial_number,x.product_id,x.warehouse_id,p.name AS product_name,w.name AS warehouse_name FROM serial_units x JOIN products p ON p.id=x.product_id AND p.business_id=x.business_id AND p.is_active=1 JOIN warehouses w ON w.id=x.warehouse_id AND w.business_id=x.business_id AND w.is_active=1 AND w.is_sales_location=1 WHERE x.business_id=? AND x.status='in_stock' AND (LOCATE(?,x.serial_number)>0 OR LOCATE(?,p.name)>0) ORDER BY x.serial_number,x.id LIMIT 51");
        $s->execute([$biz,mb_strtoupper(trim($q),'UTF-8'),trim($q)]);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($rows,0,50),'more'=>count($rows)>50]);
    });
    $group->post('/service/free-exchanges',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','sale_id','returned_unit_id','replacement_unit_id','warehouse_id','case_id','delivered_on','reason','coverage_review','evidence_reference','receipt_confirmed']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);$sale=originId($b['sale_id']??null,'sale_id');$out=originId($b['returned_unit_id']??null,'returned_unit_id');$in=originId($b['replacement_unit_id']??null,'replacement_unit_id');$warehouse=originId($b['warehouse_id']??null,'warehouse_id');$case=originId($b['case_id']??null,'case_id');
        $day=reservationDate($b['delivered_on']??null);$reason=warrantyText($b,'reason',1000);$review=warrantyText($b,'coverage_review',1000);$ref=warrantyText($b,'evidence_reference',250);
        if(!$sale||!$out||!$in||$out===$in||!$warehouse||($b['receipt_confirmed']??null)!==true)fail('Confirma recepción y entrega efectiva de hoy, series distintas y bodega.',422);
        $hash=hash('sha256',json_encode([$uid,$sale,$out,$in,$warehouse,$case,$day,$reason,$review,$ref],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $s=$db->prepare('SELECT id,payload_hash FROM warranty_free_exchanges WHERE business_id=? AND request_id=?');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();if($old){if(!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'exchange_id'=>(int)$old['id'],'already_recorded'=>true]);}
            if($day!==quoteToday())fail('La entrega física se registra en la fecha actual; no retrofechar movimientos de inventario.',422);
            $warehouse=titoSaleLocation($db,$a,$warehouse,true,$request);
            $original=freeExchangeOrigin($db,$biz,$sale,$out);$root=(int)$original['root_unit_id'];$pid=(int)$original['product_id'];
            if($day<$original['delivered_on'])fail('Entrega anterior a la operación de origen.',422);
            $s=$db->prepare('SELECT stock_qty,sale_unit,is_active FROM products WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$pid]);$product=$s->fetch();$s->closeCursor();if(!$product||!(int)$product['is_active']||$product['sale_unit']!=='unidad'||!serialControlled($db,$biz,$pid))fail('Producto serializado no disponible.',409);
            serialReconcile($db,$biz,$pid,(float)$product['stock_qty']);
            $s=$db->prepare("SELECT id FROM serial_units WHERE business_id=? AND id=? AND product_id=? AND warehouse_id=? AND status='in_stock' AND received_at<=? FOR UPDATE");$s->execute([$biz,$in,$pid,$warehouse,$day]);$new=$s->fetchColumn();$s->closeCursor();if(!$new)fail('Sustituto no disponible o de otro producto; el piloto admite cambio por mismo producto.',409);
            $s=$db->prepare("SELECT id,status,revision,client_id,sale_id FROM service_cases WHERE business_id=? AND COALESCE(physical_unit_id,unit_id)=? AND status<>'closed' FOR UPDATE");$s->execute([$biz,$out]);$open=$s->fetch();$s->closeCursor();
            if($open&&($case===null||(int)$open['id']!==$case))fail('Existe un caso abierto; selecciónalo para cerrar por reemplazo.',409);
            if($case!==null&&(!$open||(int)$open['sale_id']!==$sale||(int)$open['client_id']!==(int)$original['client_id']))fail('Caso abierto no corresponde a la unidad y venta.',409);
            $s=$db->prepare('SELECT 1 FROM warranty_free_exchanges WHERE business_id=? AND sale_id=? AND root_unit_id=? AND (returned_unit_id=? OR replacement_unit_id=?) LIMIT 1');$s->execute([$biz,$sale,$root,$out,$in]);$used=$s->fetchColumn();$s->closeCursor();if($used)fail('Equipo ya cambiado o sustituto anteriormente utilizado en esta cadena.',409);
            // This uses the existing stock path, including protection for reservations.
            $after=titoSellSerialLocationStock($db,$biz,$pid,$warehouse,1.0);
            $s=$db->prepare("UPDATE serial_units SET status='warranty_hold',warehouse_id=? WHERE business_id=? AND id=? AND status IN ('sold','replacement_delivered')");$s->execute([$warehouse,$biz,$out]);if($s->rowCount()!==1)fail('Cambió el estado del equipo recibido.',409);
            $s=$db->prepare("UPDATE serial_units SET status='replacement_delivered' WHERE business_id=? AND id=? AND status='in_stock' AND warehouse_id=?");$s->execute([$biz,$in,$warehouse]);if($s->rowCount()!==1)fail('Cambió el estado del sustituto.',409);
            $s=$db->prepare('INSERT INTO warranty_free_exchanges(business_id,sale_id,root_unit_id,returned_unit_id,replacement_unit_id,client_id,warehouse_id,case_id,delivered_on,reason,coverage_review,evidence_reference,actor_id,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$biz,$sale,$root,$out,$in,$original['client_id'],$warehouse,$case,$day,$reason,$review,$ref,$uid,$rid,$hash]);$id=(int)$db->lastInsertId();
            move($db,$biz,$pid,null,$uid,'adjustment','-1.000',$after,'Cambio gratuito por garantía #'.$id.' · venta #'.$sale);
            $event=$db->prepare('INSERT INTO serial_unit_events(business_id,unit_id,actor_id,event_type,details) VALUES(?,?,?,?,?)');
            $details=json_encode(['exchange_id'=>$id,'sale_id'=>$sale,'root_unit_id'=>$root,'returned_unit_id'=>$out,'replacement_unit_id'=>$in,'warehouse_id'=>$warehouse,'reason'=>$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $event->execute([$biz,$out,$uid,'exchange_received',$details]);$event->execute([$biz,$in,$uid,'exchange_delivered',$details]);
            if($open){
                $s=$db->prepare("UPDATE service_cases SET status='closed',custody='service',closure_outcome='replaced',revision=revision+1,closed_at=UTC_TIMESTAMP() WHERE business_id=? AND id=? AND revision=?");$s->execute([$biz,$case,$open['revision']]);if($s->rowCount()!==1)fail('Cambió el caso.',409);
                $s=$db->prepare("INSERT INTO service_case_events(business_id,case_id,actor_id,from_status,to_status,custody,note,evidence_reference,request_id,payload_hash) VALUES(?,?,?,?,'closed','service',?,?,?,?)");$s->execute([$biz,$case,$uid,$open['status'],'Cambio gratuito #'.$id.' · equipo original retenido',$ref,$rid,$hash]);
            }
            serialReconcile($db,$biz,$pid,(float)$after);$db->commit();return respond($response,['ok'=>true,'exchange_id'=>$id],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->get('/service/replacements',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);$q=$p['q']??'';
        if($page===false||!is_string($q)||mb_strlen($q)>120)fail('Filtro inválido.',422);
        $where=' WHERE r.business_id=?';$params=[$biz];
        if(trim($q)!==''){$where.=" AND (LOCATE(?,x.serial_number)>0 OR LOCATE(?,y.serial_number)>0 OR CAST(r.original_sale_id AS CHAR)=? OR CAST(r.replacement_sale_id AS CHAR)=? OR LOCATE(?,h.name)>0)";$params=array_merge($params,[mb_strtoupper(trim($q),'UTF-8'),mb_strtoupper(trim($q),'UTF-8'),trim($q),trim($q),trim($q)]);}
        $s=db()->prepare(replacementSql().$where.' ORDER BY r.id DESC LIMIT 26 OFFSET '.(($page-1)*25));$s->execute($params);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25,'today'=>quoteToday()]);
    });
    $group->get('/service/replacement-units',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$q=$p['q']??'';
        if(!is_string($q)||mb_strlen(trim($q))<2||mb_strlen($q)>120)fail('Busca por serie o número de venta.',422);
        $s=db()->prepare("SELECT su.unit_id,su.sale_id,x.serial_number,p.name AS product_name,ss.client_name,ss.client_id,s.status AS sale_status,rv.reversal_kind,rv.return_id,rv.credit_adjustment_id,cr.resolution FROM serial_sale_units su JOIN serial_units x ON x.id=su.unit_id AND x.business_id=su.business_id JOIN products p ON p.id=x.product_id AND p.business_id=x.business_id JOIN sales s ON s.id=su.sale_id AND s.business_id=su.business_id JOIN serial_sale_snapshots ss ON ss.sale_id=s.id AND ss.business_id=s.business_id LEFT JOIN serial_sale_reversals rv ON rv.unit_id=su.unit_id AND rv.sale_id=su.sale_id AND rv.business_id=su.business_id LEFT JOIN customer_returns cr ON cr.id=rv.return_id AND cr.business_id=su.business_id WHERE su.business_id=? AND (LOCATE(?,x.serial_number)>0 OR CAST(su.sale_id AS CHAR)=?) ORDER BY su.sale_id DESC,x.serial_number LIMIT 51");
        $s->execute([$biz,mb_strtoupper(trim($q),'UTF-8'),trim($q)]);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($rows,0,50),'more'=>count($rows)>50]);
    });
    $group->post('/service/replacements',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','original_sale_id','original_unit_id','replacement_sale_id','replacement_unit_id','case_id','delivered_on','reason','coverage_review','evidence_reference']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);$os=originId($b['original_sale_id']??null,'original_sale_id');$ou=originId($b['original_unit_id']??null,'original_unit_id');$ns=originId($b['replacement_sale_id']??null,'replacement_sale_id');$nu=originId($b['replacement_unit_id']??null,'replacement_unit_id');$case=originId($b['case_id']??null,'case_id');
        $day=reservationDate($b['delivered_on']??null);$reason=warrantyText($b,'reason',1000);$review=warrantyText($b,'coverage_review',1000);$ref=warrantyText($b,'evidence_reference',250);
        if(!$os||!$ou||!$ns||!$nu||$ou===$nu||$ns<=$os||$day>quoteToday())fail('Selecciona dos equipos distintos y una venta posterior real.',422);
        $hash=hash('sha256',json_encode([$uid,$os,$ou,$ns,$nu,$case,$day,$reason,$review,$ref],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $s=$db->prepare('SELECT id,payload_hash FROM warranty_replacements WHERE business_id=? AND request_id=?');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();if($old){if(!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'replacement_id'=>(int)$old['id'],'already_recorded'=>true]);}
            $original=replacementSaleUnit($db,$biz,$os,$ou);$new=replacementSaleUnit($db,$biz,$ns,$nu);
            if($original['sale_status']!=='completed'||$new['sale_status']!=='completed'||!$original['client_id']||(int)$original['client_id']!==(int)$new['client_id']||!in_array($original['reversal_kind'],['commercial','warranty'],true)||$new['reversal_kind']!==null||$new['unit_status']!=='sold')fail('Requiere devolución registrada y equipo sustituto vendido al mismo cliente, sin reversa.',409);
            // A normal exchange must be documented as exchange, not a recorded refund.
            if($original['return_id']!==null&&$original['resolution']!=='exchange')fail('La devolución original no está registrada como cambio.',409);
            if($original['return_id']===null&&$original['credit_adjustment_id']===null)fail('No existe devolución comercial o ajuste de crédito verificable.',409);
            $s=$db->prepare('SELECT 1 FROM serial_sale_units WHERE business_id=? AND unit_id=? AND sale_id>? LIMIT 1');$s->execute([$biz,$nu,$ns]);$later=$s->fetchColumn();$s->closeCursor();if($later)fail('El equipo sustituto tiene una venta posterior.',409);
            if($case!==null){$s=$db->prepare('SELECT id FROM service_cases WHERE business_id=? AND id=? AND sale_id=? AND unit_id=? AND client_id=?');$s->execute([$biz,$case,$os,$ou,$original['client_id']]);$ok=$s->fetchColumn();$s->closeCursor();if(!$ok)fail('Caso no corresponde al equipo original.',422);}
            $s=$db->prepare('SELECT id FROM warranty_replacements WHERE business_id=? AND ((original_unit_id=? AND original_sale_id=?) OR (replacement_unit_id=? AND replacement_sale_id=?)) LIMIT 1 FOR UPDATE');$s->execute([$biz,$ou,$os,$nu,$ns]);$duplicate=$s->fetchColumn();$s->closeCursor();if($duplicate)fail('La devolución o el reemplazo ya está vinculado.',409);
            $s=$db->prepare('INSERT INTO warranty_replacements(business_id,original_sale_id,original_unit_id,replacement_sale_id,replacement_unit_id,client_id,case_id,delivered_on,reason,coverage_review,evidence_reference,actor_id,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$biz,$os,$ou,$ns,$nu,$original['client_id'],$case,$day,$reason,$review,$ref,$uid,$rid,$hash]);$id=(int)$db->lastInsertId();$db->commit();return respond($response,['ok'=>true,'replacement_id'=>$id],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->get('/service/repair-warranties',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);$q=$p['q']??'';
        if($page===false||!is_string($q)||mb_strlen($q)>120)fail('Filtro inválido.',422);
        $where=' WHERE r.business_id=?';$params=[$biz];
        if(trim($q)!==''){$where.=" AND (LOCATE(?,x.serial_number)>0 OR LOCATE(?,p.name)>0 OR CAST(c.sale_id AS CHAR)=? OR LOCATE(?,h.name)>0)";$params[]=mb_strtoupper(trim($q),'UTF-8');$params[]=trim($q);$params[]=trim($q);$params[]=trim($q);}
        $s=db()->prepare(repairWarrantySql().$where.' ORDER BY r.id DESC LIMIT 26 OFFSET '.(($page-1)*25));$s->execute($params);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25]);
    });
    $group->get('/service/equipment',static function(Request $request,Response $response): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$q=$request->getQueryParams()['q']??'';
        if(!is_string($q)||mb_strlen($q)>120||mb_strlen(trim($q))<2)fail('Ingresa al menos dos caracteres de la serie.',422);
        $s=db()->prepare("SELECT su.unit_id,su.sale_id,x.serial_number,x.status AS unit_status,p.name AS product_name,ss.client_id,ss.client_name,DATE(s.created_at) AS sale_date,m.name AS manufacturer_name,w.id AS warranty_id,w.starts_on,w.ends_on,w.terms,w.manufacturer_name AS warranty_manufacturer FROM serial_sale_units su JOIN serial_units x ON x.id=su.unit_id AND x.business_id=su.business_id JOIN serial_sale_snapshots ss ON ss.sale_id=su.sale_id AND ss.business_id=su.business_id JOIN sales s ON s.id=su.sale_id AND s.business_id=su.business_id JOIN products p ON p.id=x.product_id AND p.business_id=x.business_id LEFT JOIN product_origins po ON po.product_id=p.id AND po.business_id=p.business_id LEFT JOIN manufacturers m ON m.id=po.manufacturer_id AND m.business_id=p.business_id LEFT JOIN unit_warranties w ON w.unit_id=su.unit_id AND w.sale_id=su.sale_id AND w.business_id=su.business_id WHERE su.business_id=? AND LOCATE(?,x.serial_number)>0 AND s.status='completed' AND x.status IN ('sold','warranty_hold') AND NOT EXISTS(SELECT 1 FROM warranty_free_exchanges ex WHERE ex.business_id=su.business_id AND ex.sale_id=su.sale_id AND ex.returned_unit_id=su.unit_id) AND NOT EXISTS(SELECT 1 FROM serial_sale_units later WHERE later.unit_id=su.unit_id AND later.sale_id>su.sale_id) ORDER BY x.serial_number,su.unit_id LIMIT 51");
        $s->execute([$biz,mb_strtoupper(trim($q),'UTF-8')]);$rows=$s->fetchAll();$s->closeCursor();$s=db()->prepare("SELECT x.id AS unit_id,e.root_unit_id,e.sale_id,x.serial_number,x.status AS unit_status,p.name AS product_name,e.client_id,h.name AS client_name,DATE(s.created_at) AS sale_date,NULL AS warranty_id FROM warranty_free_exchanges e JOIN serial_units x ON x.id=e.replacement_unit_id AND x.business_id=e.business_id JOIN sales s ON s.id=e.sale_id AND s.business_id=e.business_id JOIN products p ON p.id=x.product_id AND p.business_id=e.business_id JOIN habitual_clients h ON h.id=e.client_id AND h.business_id=e.business_id WHERE e.business_id=? AND s.status='completed' AND x.status='replacement_delivered' AND LOCATE(?,x.serial_number)>0 AND NOT EXISTS(SELECT 1 FROM warranty_free_exchanges n WHERE n.business_id=e.business_id AND n.sale_id=e.sale_id AND n.root_unit_id=e.root_unit_id AND n.returned_unit_id=x.id) ORDER BY x.serial_number LIMIT 51");$s->execute([$biz,mb_strtoupper(trim($q),'UTF-8')]);$rows=array_merge($rows,$s->fetchAll());$s->closeCursor();$rows=array_slice($rows,0,51);foreach(array_slice($rows,0,50) as $k=>$row){$root=(int)($row['root_unit_id']??$row['unit_id']);$rows[$k]['free_exchanges']=freeExchangesForUnit(db(),$biz,(int)$row['sale_id'],$root);$rows[$k]['coverages']=warrantyForUnit(db(),$biz,(int)$row['sale_id'],$root);$rows[$k]['repairs']=repairWarrantiesForUnit(db(),$biz,(int)$row['sale_id'],(int)$row['unit_id']);$rows[$k]['replacements']=replacementsForUnit(db(),$biz,(int)$row['sale_id'],(int)$row['unit_id']);}return respond($response,['rows'=>array_slice($rows,0,50),'more'=>count($rows)>50,'today'=>quoteToday()]);
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
            fail('Registra coberturas en el módulo Garantías; las garantías anteriores se conservan.',409);

        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->get('/service/cases',static function(Request $request,Response $response): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);$filter=$p['filter']??'open';
        if($page===false||!in_array($filter,['open','closed','all'],true))fail('Filtro inválido.',422);
        $where=$filter==='open'?" AND c.status<>'closed'":($filter==='closed'?" AND c.status='closed'":"");
        $offset=($page-1)*25;$s=db()->prepare("SELECT c.id,c.sale_id,c.unit_id,c.status,c.custody,c.problem,c.revision,c.created_at,x.serial_number,p.name AS product_name,h.name AS client_name FROM service_cases c JOIN serial_units x ON x.id=COALESCE(c.physical_unit_id,c.unit_id) AND x.business_id=c.business_id JOIN products p ON p.id=x.product_id AND p.business_id=c.business_id JOIN habitual_clients h ON h.id=c.client_id AND h.business_id=c.business_id WHERE c.business_id=?".$where.' ORDER BY c.id DESC LIMIT 26 OFFSET '.$offset);$s->execute([$biz]);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25]);
    });
    $group->get('/service/cases/{id:[0-9]+}',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$id=(int)$args['id'];$db=db();
        $s=$db->prepare('SELECT c.*,x.serial_number,p.name AS product_name,h.name AS client_name,w.manufacturer_name,w.starts_on,w.ends_on,w.terms FROM service_cases c JOIN serial_units x ON x.id=COALESCE(c.physical_unit_id,c.unit_id) AND x.business_id=c.business_id JOIN products p ON p.id=x.product_id AND p.business_id=c.business_id JOIN habitual_clients h ON h.id=c.client_id AND h.business_id=c.business_id LEFT JOIN unit_warranties w ON w.id=c.warranty_id AND w.business_id=c.business_id WHERE c.business_id=? AND c.id=?');$s->execute([$biz,$id]);$c=$s->fetch();$s->closeCursor();if(!$c)fail('Caso no encontrado.',404);unset($c['payload_hash'],$c['request_id']);
        $s=$db->prepare('SELECT e.id,e.from_status,e.to_status,e.custody,e.note,e.evidence_reference,e.created_at,u.email AS actor_email FROM service_case_events e JOIN users u ON u.id=e.actor_id AND u.business_id=e.business_id WHERE e.business_id=? AND e.case_id=? ORDER BY e.id DESC');$s->execute([$biz,$id]);$events=$s->fetchAll();$s->closeCursor();return respond($response,['case'=>$c,'events'=>$events,'coverages'=>warrantyForUnit($db,$biz,(int)$c['sale_id'],(int)$c['unit_id']),'repairs'=>repairWarrantiesForUnit($db,$biz,(int)$c['sale_id'],(int)($c['physical_unit_id']??$c['unit_id'])),'free_exchanges'=>freeExchangesForUnit($db,$biz,(int)$c['sale_id'],(int)$c['unit_id']),'replacements'=>replacementsForUnit($db,$biz,(int)$c['sale_id'],(int)$c['unit_id']),'today'=>quoteToday()]);
    });
    $group->post('/service/cases',static function(Request $request,Response $response): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','unit_id','sale_id','problem','custody','evidence_reference','prior_repair_id']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);$unit=originId($b['unit_id']??null,'unit_id');$sale=originId($b['sale_id']??null,'sale_id');$problem=serviceText($b,'problem',1000);$ref=serviceText($b,'evidence_reference',250,false);$custody=$b['custody']??null;if(!$unit||!$sale||!in_array($custody,['customer','service','manufacturer'],true))fail('Equipo o custodia inválidos.',422);
        $prior=originId($b['prior_repair_id']??null,'prior_repair_id');
        $canonical=[$uid,$unit,$sale,$problem,$custody,$ref];if($prior!==null)$canonical[]=$prior;
        $hash=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$db=db();$db->beginTransaction();try{
            originLockCompany($db,$biz);$s=$db->prepare('SELECT id,payload_hash FROM service_cases WHERE business_id=? AND request_id=?');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();if($old){if(!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'case_id'=>(int)$old['id'],'already_recorded'=>true]);}
            $sold=serviceSold($db,$biz,$unit,$sale);$root=(int)$sold['root_unit_id'];
            if($prior!==null){
                $s=$db->prepare('SELECT r.id FROM service_repair_warranties r JOIN service_cases c ON c.id=r.case_id AND c.business_id=r.business_id WHERE r.business_id=? AND r.id=? AND c.sale_id=? AND COALESCE(c.physical_unit_id,c.unit_id)=? AND c.client_id=? AND c.status=?');
                $s->execute([$biz,$prior,$sale,$unit,$sold['client_id'],'closed']);$ok=$s->fetchColumn();$s->closeCursor();if(!$ok)fail('Reparación previa no corresponde al equipo y venta.',422);
            }
            $s=$db->prepare("SELECT id FROM service_cases WHERE business_id=? AND COALESCE(physical_unit_id,unit_id)=? AND status<>'closed' FOR UPDATE");$s->execute([$biz,$unit]);$open=$s->fetchColumn();$s->closeCursor();if($open)fail('Ya existe un caso abierto para este equipo.',409);
            $s=$db->prepare('SELECT id FROM unit_warranties WHERE business_id=? AND unit_id=? AND sale_id=?');$s->execute([$biz,$root,$sale]);$w=$s->fetchColumn();$s->closeCursor();
            $s=$db->prepare('INSERT INTO service_cases(business_id,sale_id,unit_id,client_id,warranty_id,custody,problem,created_by,request_id,payload_hash,prior_repair_id,physical_unit_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$biz,$sale,$root,$sold['client_id'],$w?:null,$custody,$problem,$uid,$rid,$hash,$prior,$unit===$root?null:$unit]);$id=(int)$db->lastInsertId();
            $s=$db->prepare("INSERT INTO service_case_events(business_id,case_id,actor_id,from_status,to_status,custody,note,evidence_reference,request_id,payload_hash) VALUES(?,?,?,NULL,'received',?,?,?,?,?)");$s->execute([$biz,$id,$uid,$custody,$problem,$ref,$rid,$hash]);$db->commit();return respond($response,['ok'=>true,'case_id'=>$id],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/service/cases/{id:[0-9]+}/events',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$id=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','revision','status','custody','note','evidence_reference','closure_outcome','repair']))fail('Campos no permitidos.',422);
        [$rid,$revision,$hash]=quoteCommand($b);$hash=hash('sha256',$id.':'.$uid.':'.$hash);
        $to=$b['status']??null;$custody=$b['custody']??null;$note=serviceText($b,'note',1000);$ref=serviceText($b,'evidence_reference',250,false);if(!in_array($custody,['customer','service','manufacturer'],true)||!in_array($to,['received','diagnosis','repair','ready','closed'],true))fail('Estado o custodia inválidos.',422);
        $db=db();$db->beginTransaction();try{
            originLockCompany($db,$biz);$s=$db->prepare('SELECT id,status,revision FROM service_cases WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$id]);$c=$s->fetch();$s->closeCursor();if(!$c)fail('Caso no encontrado.',404);
            $s=$db->prepare('SELECT case_id,payload_hash FROM service_case_events WHERE business_id=? AND request_id=?');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();if($old){if((int)$old['case_id']!==$id||!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'already_recorded'=>true]);}
            $allowed=['received'=>['received','diagnosis','closed'],'diagnosis'=>['diagnosis','repair','ready','closed'],'repair'=>['repair','diagnosis','ready'],'ready'=>['ready','repair','closed'],'closed'=>[]];
            if((int)$c['revision']!==$revision||!in_array($to,$allowed[$c['status']],true))fail('Estado cambió o transición no permitida.',409);
            if($to==='closed'&&$custody!=='customer')fail('Confirma entrega o custodia final del cliente antes de cerrar. Las devoluciones financieras se gestionan por separado.',422);
            $outcome=$b['closure_outcome']??null;
            if($to==='closed'){
                if(!in_array($outcome,['repaired','not_repaired'],true))fail('Indica si se entregó efectivamente reparado o se cerró sin reparación.',422);
                if($outcome==='repaired'){
                    if($c['status']!=='ready')fail('Registra el equipo como listo antes de confirmar reparación y entrega.',409);
                    repairWarrantyRecord($db,$biz,$uid,$c,$b['repair']??null);
                }elseif(isset($b['repair']))fail('No corresponde garantía de reparación sin reparación efectiva.',422);
            }elseif($outcome!==null||isset($b['repair']))fail('La garantía de reparación se registra al entregar y cerrar.',422);
            $s=$db->prepare("UPDATE service_cases SET status=?,custody=?,revision=revision+1,closed_at=CASE WHEN ?='closed' THEN UTC_TIMESTAMP() ELSE NULL END,closure_outcome=? WHERE business_id=? AND id=?");$s->execute([$to,$custody,$to,$outcome,$biz,$id]);
            $s=$db->prepare('INSERT INTO service_case_events(business_id,case_id,actor_id,from_status,to_status,custody,note,evidence_reference,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');$s->execute([$biz,$id,$uid,$c['status'],$to,$custody,$note,$ref,$rid,$hash]);$db->commit();return respond($response,['ok'=>true],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
