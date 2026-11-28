<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Cotizaciones internas no tributarias. */
function quoteCreationActor(Request $request): array {
    $actor=accessRequire($request,'quotes.create');
    if($actor['role']!=='admin'&&(!in_array('inventory.view',$actor['permissions'],true)||!in_array('quotes.view',$actor['permissions'],true)||$actor['branch_scope']!=='all'))fail('No tienes permisos para crear cotizaciones.',403);
    return $actor;
}
function quoteToday(): string {
    return (new DateTimeImmutable('now',new DateTimeZone('America/Santiago')))->format('Y-m-d');
}
function quoteEffective(array $q): array {
    $q['effective_status']=in_array($q['status'],['sent','accepted'],true)&&$q['valid_until']<quoteToday()?'expired':$q['status'];
    if($q['status']==='reserved'&&reservationExpired($q['reservation_expires_on']??null))$q['effective_status']='reservation_expired';
    return $q;
}
function quoteEvent(PDO $db,int $biz,int $qid,int $uid,string $type,?string $from,string $to,?string $note=null,?array $details=null,?string $rid=null,?string $hash=null): void {
    $s=$db->prepare('INSERT INTO quote_events(business_id,quote_id,actor_id,event_type,from_status,to_status,note,details_json,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$biz,$qid,$uid,$type,$from,$to,$note,$details===null?null:json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$rid,$hash]);
}
function quoteLock(PDO $db,int $biz,int $qid): array {
    $s=$db->prepare('SELECT * FROM quotes WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$qid]);$q=$s->fetch();$s->closeCursor();
    if(!$q)fail('Cotización no encontrada.',404);
    return $q;
}
function quoteCommand(array $b): array {
    $rid=$b['request_id']??null;$revision=$b['revision']??null;
    if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$rid)||!is_int($revision)||$revision<1)fail('Identificador o revisión inválidos.',422);
    return [strtolower($rid),$revision,hash('sha256',json_encode($b,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))];
}
function quoteReplay(PDO $db,int $biz,int $qid,int $uid,string $rid,string $hash): bool {
    $s=$db->prepare('SELECT actor_id,payload_hash FROM quote_events WHERE business_id=? AND quote_id=? AND request_id=?');$s->execute([$biz,$qid,$rid]);$r=$s->fetch();$s->closeCursor();
    if(!$r)return false;
    if((int)$r['actor_id']!==$uid||!hash_equals((string)$r['payload_hash'],$hash))fail('Solicitud reutilizada con datos diferentes.',409);
    return true;
}
function quoteDraftLines(PDO $db,int $biz,array $items,array $old): array {
    if(!array_is_list($items)||count($items)<1||count($items)>50)fail('Ingresa entre 1 y 50 productos.',422);
    $rows=[];$total=0;
    $s=$db->prepare('SELECT id,name,price_clp,sale_unit FROM products WHERE id=? AND business_id=? AND is_active=1 FOR UPDATE');
    foreach($items as $i){
        if(!is_array($i)||array_diff(array_keys($i),['product_id','quantity','unit_price_clp','discount_reason']))fail('Línea inválida.',422);
        $pid=originId($i['product_id']??null,'product_id');
        if($pid===null||isset($rows[$pid]))fail('Producto inválido o repetido.',422);
        $s->execute([$pid,$biz]);$p=$s->fetch();$s->closeCursor();if(!$p)fail('Producto inactivo o inexistente.',422);
        $prev=$old[$pid]??null;
        if($prev&&$prev['sale_unit']!==$p['sale_unit'])fail('Cambió la unidad de un producto; retíralo del borrador antes de volver a agregarlo.',409);
        $qty=decimalQuantity($i['quantity']??null,false,$p['sale_unit']!=='unidad');
        if((float)$qty<=0)fail('Cantidad inválida.',422);
        $list=$prev?(int)$prev['list_price_clp']:(int)$p['price_clp'];
        $price=array_key_exists('unit_price_clp',$i)?integerPrice($i['unit_price_clp']):$list;
        if($price>$list||($list>0&&$price===0))fail('Precio pactado inválido.',422);
        $reason=null;
        if($price<$list){$v=$i['discount_reason']??null;if(!is_string($v)||trim($v)===''||mb_strlen(trim($v))>120)fail('Explica el descuento.',422);$reason=trim($v);}
        $subtotal=(int)round((float)$qty*$price,0,PHP_ROUND_HALF_UP);$total+=$subtotal;
        if($total>1000000000000)fail('Total demasiado grande.',422);
        $rows[$pid]=['product_id'=>$pid,'product_name_snapshot'=>$prev?$prev['product_name_snapshot']:$p['name'],'sale_unit'=>$p['sale_unit'],'quantity'=>$qty,'list_price_clp'=>$list,'unit_price_clp'=>$price,'discount_reason'=>$reason,'subtotal_clp'=>$subtotal];
    }
    return [array_values($rows),$total];
}

function registerQuoteRoutes(RouteCollectorProxy $group): void {
    // Agenda comercial: solo administradores de la empresa en esta fase.
    $group->get('/quotes/followups',static function(Request $request,Response $response): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$db=db();$p=$request->getQueryParams();
        $qid=isset($p['quote_id'])?filter_var($p['quote_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):null;
        $page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        $filter=$p['filter']??($qid===null?'pending':'all');
        if($qid===false||$page===false||!in_array($filter,['all','pending','overdue','done','cancelled','recorded'],true))fail('Filtro inválido.',422);
        if($qid!==null){$s=$db->prepare('SELECT id FROM quotes WHERE business_id=? AND id=?');$s->execute([$biz,$qid]);$exists=$s->fetchColumn();$s->closeCursor();if(!$exists)fail('Cotización no encontrada.',404);}
        $where=' WHERE f.business_id=?';$params=[$biz];
        if($qid!==null){$where.=' AND f.quote_id=?';$params[]=$qid;}
        if($filter==='overdue'){$where.=" AND f.status='pending' AND f.due_on<?";$params[]=quoteToday();}
        elseif($filter!=='all'){$where.=' AND f.status=?';$params[]=$filter;}
        $order=$qid===null?" ORDER BY (f.status='pending') DESC,f.due_on ASC,f.id DESC":" ORDER BY f.id DESC";
        $offset=($page-1)*25;
        $s=$db->prepare('SELECT f.id,f.quote_id,f.client_id,f.kind,f.channel,f.note,f.due_on,f.status,f.created_at,f.closed_at,f.close_note,q.customer_name,q.status AS quote_status,u.email AS assigned_email,c.email AS created_email,cl.email AS closed_email FROM quote_followups f JOIN quotes q ON q.id=f.quote_id AND q.business_id=f.business_id JOIN users u ON u.id=f.assigned_to AND u.business_id=f.business_id JOIN users c ON c.id=f.created_by AND c.business_id=f.business_id LEFT JOIN users cl ON cl.id=f.closed_by AND cl.business_id=f.business_id'.$where.$order.' LIMIT 26 OFFSET '.$offset);
        $s->execute($params);$rows=$s->fetchAll();$s->closeCursor();
        $s=$db->prepare("SELECT id,email FROM users WHERE business_id=? AND role='admin' AND is_active=1 ORDER BY email,id LIMIT 200");$s->execute([$biz]);$users=$s->fetchAll();$s->closeCursor();
        return respond($response,['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25,'page'=>$page,'today'=>quoteToday(),'assignees'=>$users,'actor_id'=>(int)$a['id']]);
    });
    $group->post('/quotes/{id:[0-9]+}/followups',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$qid=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','kind','channel','note','due_on','assigned_to']))fail('Campos no permitidos.',422);
        $rid=$b['request_id']??null;if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$rid))fail('Solicitud inválida.',422);$rid=strtolower($rid);
        $kind=$b['kind']??null;$channel=$b['channel']??null;
        if(!in_array($kind,['contact','task'],true)||!in_array($channel,['phone','email','whatsapp','meeting','other'],true))fail('Tipo o canal inválido.',422);
        $note=requireString($b,'note',1000);if(mb_strlen($note)<3)fail('Describe la gestión comercial.',422);
        $assigned=originId($b['assigned_to']??null,'assigned_to');if(!$assigned)fail('Selecciona responsable.',422);
        $due=$kind==='task'?reservationDate($b['due_on']??null):null;
        if($kind==='contact'&&isset($b['due_on']))fail('Un contacto registrado no tiene fecha pendiente.',422);
        $hash=hash('sha256',json_encode([$qid,$uid,$kind,$channel,$note,$assigned,$due],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();$db->beginTransaction();
        try{
            originLockCompany($db,$biz);$q=quoteLock($db,$biz,$qid);
            $s=$db->prepare('SELECT id,payload_hash FROM quote_followups WHERE business_id=? AND request_id=? FOR UPDATE');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();
            if($old){if(!hash_equals((string)$old['payload_hash'],$hash))fail('Solicitud reutilizada con otros datos.',409);$db->commit();return respond($response,['ok'=>true,'followup_id'=>(int)$old['id'],'already_recorded'=>true]);}
            if($q['status']==='draft'||$q['client_id']===null)fail('Registra primero el envío de una cotización vinculada a cliente.',409);
            if($due!==null&&$due<quoteToday())fail('La próxima acción no puede programarse en el pasado.',422);
            $s=$db->prepare("SELECT id FROM users WHERE business_id=? AND id=? AND role='admin' AND is_active=1 FOR UPDATE");$s->execute([$biz,$assigned]);$ok=$s->fetchColumn();$s->closeCursor();if(!$ok)fail('Responsable no disponible.',422);
            $s=$db->prepare('INSERT INTO quote_followups(business_id,quote_id,client_id,kind,channel,note,due_on,assigned_to,created_by,request_id,payload_hash,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$biz,$qid,$q['client_id'],$kind,$channel,$note,$due,$assigned,$uid,$rid,$hash,$kind==='task'?'pending':'recorded']);$id=(int)$db->lastInsertId();
            $db->commit();return respond($response,['ok'=>true,'followup_id'=>$id],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/quotes/{id:[0-9]+}/followups/{fid:[0-9]+}/close',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$qid=(int)$args['id'];$fid=(int)$args['fid'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','status','note']))fail('Campos no permitidos.',422);
        $rid=$b['request_id']??null;if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$rid))fail('Solicitud inválida.',422);$rid=strtolower($rid);
        $to=$b['status']??null;if(!in_array($to,['done','cancelled'],true))fail('Estado inválido.',422);
        $note=requireString($b,'note',500);if(mb_strlen($note)<3)fail('Indica resultado o motivo.',422);
        $hash=hash('sha256',json_encode([$qid,$fid,$uid,$to,$note],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();$db->beginTransaction();
        try{
            originLockCompany($db,$biz);quoteLock($db,$biz,$qid);
            $s=$db->prepare('SELECT id,close_payload_hash FROM quote_followups WHERE business_id=? AND close_request_id=? FOR UPDATE');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();
            if($old){if((int)$old['id']!==$fid||!hash_equals((string)$old['close_payload_hash'],$hash))fail('Solicitud reutilizada con otros datos.',409);$db->commit();return respond($response,['ok'=>true,'already_recorded'=>true]);}
            $s=$db->prepare("UPDATE quote_followups SET status=?,closed_by=?,closed_at=UTC_TIMESTAMP(),close_note=?,close_request_id=?,close_payload_hash=? WHERE business_id=? AND quote_id=? AND id=? AND kind='task' AND status='pending'");
            $s->execute([$to,$uid,$note,$rid,$hash,$biz,$qid,$fid]);if($s->rowCount()!==1)fail('Acción no disponible o ya cerrada.',409);
            $db->commit();return respond($response,['ok'=>true]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->get('/quotes/clients',static function(Request $request,Response $response): Response {
        $actor=quoteCreationActor($request);$biz=(int)$actor['business_id'];
        $q=$request->getQueryParams()['q']??'';
        if(!is_string($q)||!mb_check_encoding($q,'UTF-8')||mb_strlen($q)>150)fail('Búsqueda inválida.',422);
        $q=trim($q);$rut=strtoupper(preg_replace('/[.\s-]+/u','',$q)??'');
        $st=db()->prepare("SELECT id,name,rut FROM habitual_clients WHERE business_id=? AND is_active=1 AND (LOCATE(?,name)>0 OR (?<>'' AND LOCATE(?,REPLACE(COALESCE(rut,''),'-',''))>0)) ORDER BY name,id LIMIT 51");
        $st->execute([$biz,$q,$rut,$rut]);$rows=$st->fetchAll();
        return respond($response,['clients'=>array_slice($rows,0,50),'more'=>count($rows)>50]);
    });
    $group->get('/quotes',static function(Request $request,Response $response): Response {
        $admin=accessRequire($request,'quotes.view');
        $st=db()->prepare('SELECT q.id,q.client_id,q.customer_name,q.customer_rut,q.customer_contact,q.valid_until,q.status,q.revision,q.total_clp,q.created_at,q.converted_sale_id,(SELECT r.expires_on FROM serial_reservations r WHERE r.business_id=q.business_id AND r.quote_id=q.id AND r.status=\'pending\' ORDER BY r.id DESC LIMIT 1) AS reservation_expires_on FROM quotes q WHERE q.business_id=? ORDER BY q.id DESC LIMIT 100');
        $st->execute([(int)$admin['business_id']]);
        return respond($response,['quotes'=>array_map('quoteEffective',$st->fetchAll())]);
    });
    $group->get('/quotes/{id:[0-9]+}',static function(Request $request,Response $response,array $args): Response {
        $admin=accessRequire($request,'quotes.view');$biz=(int)$admin['business_id'];$id=(int)$args['id'];
        $st=db()->prepare('SELECT q.id,q.client_id,q.customer_name,q.customer_rut,q.customer_email,q.customer_contact,q.notes,q.valid_until,q.status,q.revision,q.total_clp,q.created_at,q.converted_sale_id,u.email AS created_by_email FROM quotes q JOIN users u ON u.id=q.created_by WHERE q.business_id=? AND q.id=? LIMIT 1');
        $st->execute([$biz,$id]);$quote=$st->fetch();
        if(!$quote)fail('Cotización no encontrada.',404);
        $st=db()->prepare('SELECT qi.id,qi.product_id,qi.product_name_snapshot,qi.sale_unit,qi.quantity,qi.list_price_clp,qi.unit_price_clp,qi.discount_reason,qi.subtotal_clp,EXISTS(SELECT 1 FROM serial_product_controls sc WHERE sc.product_id=qi.product_id AND sc.business_id=?) AS serial_control FROM quote_items qi WHERE qi.quote_id=? ORDER BY qi.id');
        $st->execute([$biz,$id]);
        $items=$st->fetchAll();$st->closeCursor();
        $events=[];
        if($admin['role']==='admin'){
            $st=db()->prepare('SELECT e.id,e.event_type,e.from_status,e.to_status,e.note,e.details_json,e.created_at,u.email AS actor_email FROM quote_events e JOIN users u ON u.id=e.actor_id AND u.business_id=e.business_id WHERE e.business_id=? AND e.quote_id=? ORDER BY e.id DESC LIMIT 100');
            $st->execute([$biz,$id]);$events=$st->fetchAll();
            foreach($events as &$event){$event['details']=$event['details_json']===null?null:json_decode($event['details_json'],true,512,JSON_THROW_ON_ERROR);unset($event['details_json']);}unset($event);
        }
        $s=db()->prepare('SELECT r.id,r.status,r.warehouse_id,w.name AS warehouse_name,r.note,r.sale_id,r.created_at,r.closed_at,r.cancel_reason,r.expires_on FROM serial_reservations r JOIN warehouses w ON w.id=r.warehouse_id AND w.business_id=r.business_id WHERE r.business_id=? AND r.quote_id=? ORDER BY r.id DESC LIMIT 1');
        $s->execute([$biz,$id]);$reservation=$s->fetch();$s->closeCursor();
        $dispatches=[];
        if($reservation){
            $reservation=reservationEffective($reservation);
            $quote['reservation_expires_on']=$reservation['expires_on'];
            $s=db()->prepare('SELECT product_id,quantity,dispatched_quantity,(quantity-dispatched_quantity) AS remaining_quantity FROM serial_reservation_items WHERE business_id=? AND reservation_id=? ORDER BY product_id');
            $s->execute([$biz,$reservation['id']]);$reservation['items']=$s->fetchAll();$s->closeCursor();
        }
        $s=db()->prepare('SELECT d.id,d.reservation_id,d.sale_id,d.total_clp,d.created_at,s.status AS sale_status FROM quote_dispatches d JOIN sales s ON s.id=d.sale_id AND s.business_id=d.business_id WHERE d.business_id=? AND d.quote_id=? ORDER BY d.id');
        $s->execute([$biz,$id]);$dispatches=$s->fetchAll();$s->closeCursor();
        return respond($response,['quote'=>quoteEffective($quote),'items'=>$items,'events'=>$events,'reservation'=>$reservation?:null,'dispatches'=>$dispatches]);
    });
    $group->post('/quotes',static function(Request $request,Response $response): Response {
        $admin=quoteCreationActor($request);
        $limited=$admin['role']!=='admin';
        $biz=(int)$admin['business_id'];$uid=(int)$admin['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','client_id','customer_name','customer_contact','valid_until','notes','items']))fail('Campos no permitidos.',422);
        $rid=$b['request_id']??null;
        if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$rid))fail('Identificador inválido.',422);
        $clientId=originId($b['client_id']??null,'client_id');
        // customer_name se mantiene únicamente para reconocer reintentos de cotizaciones antiguas.
        $customer=$clientId===null?requireString($b,'customer_name',150):null;
        if($clientId!==null&&array_key_exists('customer_name',$b))fail('Selecciona el cliente por su identificador, no por texto libre.',422);
        $contact=$b['customer_contact']??null;
        if($contact!==null&&(!is_string($contact)||mb_strlen(trim($contact))>120))fail('Contacto inválido.',422);
        $contact=$contact===null?null:(trim($contact)?:null);
        $notes=$b['notes']??null;
        if($notes!==null&&(!is_string($notes)||mb_strlen(trim($notes))>500))fail('Observaciones demasiado largas.',422);
        $notes=$notes===null?null:(trim($notes)?:null);
        $date=$b['valid_until']??null;
        if(!is_string($date)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$p)||!checkdate((int)$p[2],(int)$p[3],(int)$p[1]))fail('Fecha de validez inválida.',422);
        $items=$b['items']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>50)fail('Ingresa entre 1 y 50 productos.',422);
        $wanted=[];
        foreach($items as $item){
            if(!is_array($item)||array_diff(array_keys($item),['product_id','quantity','unit_price_clp','discount_reason']))fail('Línea inválida.',422);
            $pid=filter_var($item['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($pid===false||isset($wanted[$pid]))fail('Producto duplicado o inválido.',422);
            $wanted[$pid]=[
                'quantity'=>$item['quantity']??null,
                'unit_price_clp'=>array_key_exists('unit_price_clp',$item)?integerPrice($item['unit_price_clp']):null,
                'discount_reason'=>$item['discount_reason']??null
            ];
        }
        ksort($wanted,SORT_NUMERIC);
        $payload=$clientId===null?['customer'=>$customer,'contact'=>$contact,'notes'=>$notes,'valid_until'=>$date,'items'=>$wanted]:['client_id'=>$clientId,'contact'=>$contact,'notes'=>$notes,'valid_until'=>$date,'items'=>$wanted];
        $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();
        try{
            $db->beginTransaction();originLockCompany($db,$biz);
            $st=$db->prepare('SELECT id,total_clp,payload_hash,created_by FROM quotes WHERE business_id=? AND request_id=? FOR UPDATE');
            $st->execute([$biz,strtolower($rid)]);$prior=$st->fetch();$st->closeCursor();
            if($prior){
                if(($limited&&(int)$prior['created_by']!==$uid)||!hash_equals((string)$prior['payload_hash'],$hash))fail('Solicitud reutilizada con datos diferentes.',409);
                $db->commit();return respond($response,['ok'=>true,'quote_id'=>(int)$prior['id'],'total_clp'=>(int)$prior['total_clp'],'already_recorded'=>true]);
            }
            if($clientId===null)fail('Selecciona un cliente registrado para crear una cotización.',422);
            if($date<(new DateTimeImmutable('now',new DateTimeZone('America/Santiago')))->format('Y-m-d'))fail('La validez no puede ser anterior a hoy.',422);
            $st=$db->prepare('SELECT name,rut,billing_email,contact_name,phone FROM habitual_clients WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');
            $st->execute([$biz,$clientId]);$c=$st->fetch();$st->closeCursor();if(!$c)fail('Cliente activo no encontrado en esta empresa.',422);
            $customer=$c['name'];$snapshotContact=$contact??($c['contact_name']?mb_substr($c['contact_name'],0,120):($c['phone']??null));
            $st=$db->prepare('INSERT INTO quotes(business_id,created_by,request_id,payload_hash,client_id,customer_name,customer_rut,customer_email,customer_contact,notes,valid_until,total_clp) VALUES(?,?,?,?,?,?,?,?,?,?,?,0)');
            $st->execute([$biz,$uid,strtolower($rid),$hash,$clientId,$customer,$c['rut'],$c['billing_email'],$snapshotContact,$notes,$date]);
            $id=(int)$db->lastInsertId();
            $st=$db->prepare('SELECT id,name,price_clp,sale_unit FROM products WHERE id=? AND business_id=? AND is_active=1');
            $line=$db->prepare('INSERT INTO quote_items(quote_id,product_id,product_name_snapshot,sale_unit,quantity,list_price_clp,unit_price_clp,discount_reason,subtotal_clp) VALUES(?,?,?,?,?,?,?,?,?)');
            $total=0;
            foreach($wanted as $pid=>$item){
                $st->execute([$pid,$biz]);$p=$st->fetch();$st->closeCursor();
                if(!$p)fail('Producto no encontrado: '.$pid,422);
                $q=decimalQuantity($item['quantity'],false,$p['sale_unit']!=='unidad');
                if((float)$q<=0)fail('Cantidad inválida.',422);
                $list=(int)$p['price_clp'];
                $price=$item['unit_price_clp']===null?$list:$item['unit_price_clp'];
                if($limited&&($price!==$list||$item['discount_reason']!==null))fail('Tu rol solo permite cotizar a precio de lista.',403);
                if($price>$list)fail('El precio pactado no puede superar la lista.',422);
                if($price===0&&$list>0)fail('El precio pactado debe superar cero.',422);
                $reason=null;
                if($price<$list){
                    $reason=$item['discount_reason'];
                    if(!is_string($reason)||trim($reason)===''||mb_strlen(trim($reason))>120)fail('Explica el descuento.',422);
                    $reason=trim($reason);
                }
                $subtotal=(int)round((float)$q*$price,0,PHP_ROUND_HALF_UP);
                $total+=$subtotal;
                if($total>1000000000000)fail('Total demasiado grande.',422);
                $line->execute([$id,$pid,$p['name'],$p['sale_unit'],$q,$list,$price,$reason,$subtotal]);
            }
            $st=$db->prepare('UPDATE quotes SET total_clp=? WHERE id=? AND business_id=?');
            $st->execute([$total,$id,$biz]);quoteEvent($db,$biz,$id,$uid,'created',null,'draft',null,['total_clp'=>$total]);$db->commit();
            return respond($response,['ok'=>true,'quote_id'=>$id,'total_clp'=>$total],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062){
                $st=$db->prepare('SELECT id,total_clp,payload_hash,created_by FROM quotes WHERE business_id=? AND request_id=?');
                $st->execute([$biz,strtolower($rid)]);$prior=$st->fetch();
                if($prior){
                    if($limited&&(int)$prior['created_by']!==$uid)fail('Solicitud reutilizada.',409);
                    if(!hash_equals((string)$prior['payload_hash'],$hash))fail('Solicitud reutilizada con datos diferentes.',409);
                    return respond($response,['ok'=>true,'quote_id'=>(int)$prior['id'],'total_clp'=>(int)$prior['total_clp'],'already_recorded'=>true],200);
                }
            }
            throw $e;
        }
    });
    $group->post('/quotes/{id:[0-9]+}/edit',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$qid=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','revision','client_id','customer_contact','notes','valid_until','items']))fail('Campos no permitidos.',422);
        [$rid,$revision,$hash]=quoteCommand($b);
        $cid=originId($b['client_id']??null,'client_id');if($cid===null)fail('Selecciona cliente registrado.',422);
        $contact=$b['customer_contact']??null;$notes=$b['notes']??null;$date=$b['valid_until']??null;
        if($contact!==null&&(!is_string($contact)||mb_strlen(trim($contact))>120))fail('Contacto inválido.',422);
        if($notes!==null&&(!is_string($notes)||mb_strlen(trim($notes))>500))fail('Observaciones inválidas.',422);
        if(!is_string($date)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))fail('Vigencia inválida.',422);
        if(!is_array($b['items']??null))fail('Productos inválidos.',422);
        $db=db();$db->beginTransaction();
        try{
            originLockCompany($db,$biz);$q=quoteLock($db,$biz,$qid);
            if(quoteReplay($db,$biz,$qid,$uid,$rid,$hash)){$db->commit();return respond($response,['ok'=>true,'quote_id'=>$qid,'already_recorded'=>true]);}
            if($q['status']!=='draft'||(int)$q['revision']!==$revision)fail('El borrador cambió o ya no es editable. Recarga la cotización.',409);
            if($date<quoteToday())fail('La vigencia no puede ser anterior a hoy.',422);
            $s=$db->prepare('SELECT name,rut,billing_email,contact_name,phone FROM habitual_clients WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');$s->execute([$biz,$cid]);$c=$s->fetch();$s->closeCursor();
            if(!$c)fail('Cliente activo no encontrado.',422);
            // El mismo cliente conserva su identidad histórica; cambiarlo toma un nuevo snapshot.
            $same=$q['client_id']!==null&&(int)$q['client_id']===$cid;
            $name=$same?$q['customer_name']:$c['name'];$rut=$same?$q['customer_rut']:$c['rut'];$email=$same?$q['customer_email']:$c['billing_email'];
            $contact=$contact===null?null:(trim($contact)?:null);$notes=$notes===null?null:(trim($notes)?:null);
            $s=$db->prepare('SELECT product_id,product_name_snapshot,sale_unit,quantity,list_price_clp,unit_price_clp,discount_reason,subtotal_clp FROM quote_items WHERE quote_id=? ORDER BY product_id FOR UPDATE');$s->execute([$qid]);$before=$s->fetchAll();$s->closeCursor();
            $old=[];foreach($before as $i)$old[(int)$i['product_id']]=$i;
            [$rows,$total]=quoteDraftLines($db,$biz,$b['items'],$old);
            $previous=array_intersect_key($q,array_flip(['client_id','customer_name','customer_rut','customer_email','customer_contact','notes','valid_until','total_clp']));
            $next=['client_id'=>$cid,'customer_name'=>$name,'customer_rut'=>$rut,'customer_email'=>$email,'customer_contact'=>$contact,'notes'=>$notes,'valid_until'=>$date,'total_clp'=>$total];
            $s=$db->prepare('UPDATE quotes SET client_id=?,customer_name=?,customer_rut=?,customer_email=?,customer_contact=?,notes=?,valid_until=?,total_clp=?,revision=revision+1 WHERE business_id=? AND id=?');
            $s->execute([$cid,$name,$rut,$email,$contact,$notes,$date,$total,$biz,$qid]);
            $s=$db->prepare('DELETE FROM quote_items WHERE quote_id=?');$s->execute([$qid]);
            $s=$db->prepare('INSERT INTO quote_items(quote_id,product_id,product_name_snapshot,sale_unit,quantity,list_price_clp,unit_price_clp,discount_reason,subtotal_clp) VALUES(?,?,?,?,?,?,?,?,?)');
            foreach($rows as $i)$s->execute([$qid,...array_values($i)]);
            quoteEvent($db,$biz,$qid,$uid,'edited','draft','draft',null,['before'=>$previous,'after'=>$next,'items_before'=>$before,'items_after'=>$rows],$rid,$hash);
            $db->commit();return respond($response,['ok'=>true,'quote_id'=>$qid,'revision'=>$revision+1]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/quotes/{id:[0-9]+}/status',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$qid=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','revision','status','note']))fail('Campos no permitidos.',422);
        [$rid,$revision,$hash]=quoteCommand($b);$to=$b['status']??null;$note=$b['note']??null;
        if(!in_array($to,['sent','accepted','rejected'],true))fail('Estado inválido.',422);
        if($note!==null&&(!is_string($note)||mb_strlen(trim($note))>500))fail('Observación inválida.',422);
        $note=$note===null?null:(trim($note)?:null);
        if($to==='rejected'&&$note===null)fail('Registra el motivo del rechazo.',422);
        $db=db();$db->beginTransaction();
        try{
            originLockCompany($db,$biz);$q=quoteLock($db,$biz,$qid);
            if(quoteReplay($db,$biz,$qid,$uid,$rid,$hash)){$db->commit();return respond($response,['ok'=>true,'quote_id'=>$qid,'already_recorded'=>true]);}
            if((int)$q['revision']!==$revision)fail('Cotización modificada; recarga.',409);
            $allowed=['draft'=>['sent'],'sent'=>['accepted','rejected']];
            if(!in_array($to,$allowed[$q['status']]??[],true))fail('Cambio de estado no permitido.',409);
            if($q['valid_until']<quoteToday())fail('Cotización vencida; no puede enviarse ni aceptarse/rechazarse en este flujo.',409);
            if($q['client_id']===null)fail('Vincula un cliente registrado antes de enviar.',422);
            $s=$db->prepare('SELECT id FROM habitual_clients WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');$s->execute([$biz,(int)$q['client_id']]);$active=$s->fetchColumn();$s->closeCursor();if(!$active)fail('Cliente inactivo.',409);
            $s=$db->prepare('UPDATE quotes SET status=?,revision=revision+1 WHERE business_id=? AND id=?');$s->execute([$to,$biz,$qid]);
            quoteEvent($db,$biz,$qid,$uid,$to,$q['status'],$to,$note,null,$rid,$hash);
            $db->commit();return respond($response,['ok'=>true,'quote_id'=>$qid,'revision'=>$revision+1]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    // Reserva completa de cotización normal, serializada o mixta. Sin caja, salida física ni selección de series.
    $group->post('/quotes/{id:[0-9]+}/reserve',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$qid=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','revision','warehouse_id','note','expires_on']))fail('Campos no permitidos.',422);
        [$rid,$revision,$hash]=quoteCommand($b);
        $wid=originId($b['warehouse_id']??null,'warehouse_id');if($wid===null)fail('Selecciona bodega.',422);
        $note=$b['note']??'';if(!is_string($note)||mb_strlen(trim($note))>250)fail('Observación inválida.',422);$note=trim($note);
        $db=db();$db->beginTransaction();
        try{
            originLockCompany($db,$biz);$q=quoteLock($db,$biz,$qid);
            if(quoteReplay($db,$biz,$qid,$uid,$rid,$hash)){
                $s=$db->prepare('SELECT id FROM serial_reservations WHERE business_id=? AND quote_id=? AND request_id=?');$s->execute([$biz,$qid,$rid]);$existing=$s->fetchColumn();$s->closeCursor();if(!$existing)fail('Registro de reserva inconsistente.',409);
                $db->commit();return respond($response,['ok'=>true,'reservation_id'=>(int)$existing,'quote_id'=>$qid,'already_recorded'=>true]);
            }
            if($q['status']!=='accepted'||(int)$q['revision']!==$revision||$q['valid_until']<quoteToday())fail('Se requiere cotización aceptada y vigente. Recarga su estado.',409);
            $expiry=reservationDate($b['expires_on']??$q['valid_until']);
            if(reservationExpired($expiry))fail('La fecha límite no puede ser anterior a hoy.',422);
            if($q['client_id']===null)fail('Selecciona un cliente registrado.',422);
            $s=$db->prepare('SELECT id FROM habitual_clients WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');$s->execute([$biz,(int)$q['client_id']]);$active=$s->fetchColumn();$s->closeCursor();if(!$active)fail('Cliente inactivo.',409);
            $wid=titoSaleLocation($db,$a,$wid,true,$request);
            $s=$db->prepare("SELECT id FROM serial_reservations WHERE business_id=? AND quote_id=? AND status='pending' FOR UPDATE");$s->execute([$biz,$qid]);$pending=$s->fetchColumn();$s->closeCursor();if($pending)fail('Esta cotización ya tiene reserva pendiente.',409);
            $s=$db->prepare('SELECT product_id,quantity,sale_unit FROM quote_items WHERE quote_id=? ORDER BY product_id');$s->execute([$qid]);$items=$s->fetchAll();$s->closeCursor();if(!$items)fail('Cotización sin productos.',409);
            $lock=$db->prepare('SELECT stock_qty,sale_unit FROM products WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');
            foreach($items as $i){
                $pid=(int)$i['product_id'];$qty=(float)$i['quantity'];$lock->execute([$biz,$pid]);$p=$lock->fetch();$lock->closeCursor();
                if(!$p||$i['sale_unit']!==$p['sale_unit'])fail('Producto inactivo o unidad de venta modificada.',409);
                $controlled=serialControlled($db,$biz,$pid);
                if($qty<=0||$qty>1000000000||abs($qty*1000-round($qty*1000))>0.00001||($p['sale_unit']==='unidad'&&floor($qty)!==$qty))fail('Cantidad de reserva inválida.',422);
                if($controlled){
                    if($p['sale_unit']!=='unidad'||$qty>500)fail('Los productos serializados requieren cantidades enteras de hasta 500 unidades por línea.',422);
                    serialReconcile($db,$biz,$pid,(float)$p['stock_qty']);
                }
                serialRequireFree($db,$biz,$pid,$wid,$qty);
            }
            $s=$db->prepare('INSERT INTO serial_reservations(business_id,warehouse_id,client_id,client_name,created_by,request_id,payload_hash,note,quote_id,expires_on) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$biz,$wid,$q['client_id'],$q['customer_name'],$uid,$rid,$hash,$note,$qid,$expiry]);$resId=(int)$db->lastInsertId();
            $s=$db->prepare('INSERT INTO serial_reservation_items(reservation_id,business_id,product_id,quantity) VALUES(?,?,?,?)');
            foreach($items as $i)$s->execute([$resId,$biz,(int)$i['product_id'],$i['quantity']]);
            $s=$db->prepare("UPDATE quotes SET status='reserved',revision=revision+1 WHERE business_id=? AND id=? AND status='accepted'");$s->execute([$biz,$qid]);if($s->rowCount()!==1)fail('La cotización cambió.',409);
            quoteEvent($db,$biz,$qid,$uid,'reserved','accepted','reserved',$note?:null,['reservation_id'=>$resId,'warehouse_id'=>$wid,'total_clp'=>(int)$q['total_clp'],'expires_on'=>$expiry],$rid,$hash);
            $db->commit();return respond($response,['ok'=>true,'quote_id'=>$qid,'reservation_id'=>$resId],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/quotes/{id:[0-9]+}/cancel-reservation',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$qid=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','revision','reservation_id','reason']))fail('Campos no permitidos.',422);
        [$rid,$revision,$hash]=quoteCommand($b);$resId=reservationId($b['reservation_id']??null);$reason=requireString($b,'reason',250);if(mb_strlen($reason)<3)fail('Indica motivo.',422);
        $db=db();$db->beginTransaction();
        try{
            originLockCompany($db,$biz);$q=quoteLock($db,$biz,$qid);
            if(quoteReplay($db,$biz,$qid,$uid,$rid,$hash)){$db->commit();return respond($response,['ok'=>true,'quote_id'=>$qid,'already_recorded'=>true]);}
            if($q['status']!=='reserved'||(int)$q['revision']!==$revision)fail('La cotización cambió o ya no tiene reserva pendiente.',409);
            $s=$db->prepare("SELECT warehouse_id,expires_on FROM serial_reservations WHERE business_id=? AND quote_id=? AND id=? AND status='pending' FOR UPDATE");
            $s->execute([$biz,$qid,$resId]);$reservation=$s->fetch();$s->closeCursor();if(!$reservation)fail('Reserva no disponible.',409);
            titoSaleLocation($db,$a,(int)$reservation['warehouse_id'],true,$request);
            $expired=reservationExpired($reservation['expires_on']);$resStatus=$expired?'expired':'cancelled';
            $s=$db->prepare("UPDATE serial_reservations SET status=?,closed_by=?,closed_at=UTC_TIMESTAMP(),cancel_reason=? WHERE business_id=? AND quote_id=? AND id=? AND status='pending'");
            $s->execute([$resStatus,$uid,$reason,$biz,$qid,$resId]);if($s->rowCount()!==1)fail('Reserva no disponible.',409);
            $s=$db->prepare('SELECT COALESCE(SUM(dispatched_quantity),0) FROM serial_reservation_items WHERE business_id=? AND reservation_id=?');$s->execute([$biz,$resId]);$partial=(float)$s->fetchColumn()>0;$s->closeCursor();
            // Nunca volver a aceptar y reservar la cantidad original si ya hubo entregas.
            $to=$partial?'partial_closed':($expired?'reservation_expired':'accepted');
            $s=$db->prepare("UPDATE quotes SET status=?,revision=revision+1 WHERE business_id=? AND id=? AND status='reserved'");$s->execute([$to,$biz,$qid]);if($s->rowCount()!==1)fail('Cotización cambió.',409);
            quoteEvent($db,$biz,$qid,$uid,$expired?'reservation_expired':'reservation_cancelled','reserved',$to,$reason,['reservation_id'=>$resId,'partial'=>$partial,'expires_on'=>$reservation['expires_on']],$rid,$hash);
            $db->commit();return respond($response,['ok'=>true,'quote_id'=>$qid]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    // Cambiar fecha de una reserva vigente (o histórica sin fecha); jamás reactivar una vencida.
    $group->post('/quotes/{id:[0-9]+}/reservation-expiry',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$qid=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','revision','reservation_id','expires_on','reason']))fail('Campos no permitidos.',422);
        [$rid,$revision,$hash]=quoteCommand($b);$resId=reservationId($b['reservation_id']??null);$expiry=reservationDate($b['expires_on']??null);$reason=requireString($b,'reason',250);if(mb_strlen($reason)<3)fail('Indica motivo.',422);
        $db=db();$db->beginTransaction();
        try{
            originLockCompany($db,$biz);$q=quoteLock($db,$biz,$qid);
            if(quoteReplay($db,$biz,$qid,$uid,$rid,$hash)){$db->commit();return respond($response,['ok'=>true,'already_recorded'=>true]);}
            if($q['status']!=='reserved'||(int)$q['revision']!==$revision)fail('La cotización cambió.',409);
            $s=$db->prepare("SELECT warehouse_id,expires_on FROM serial_reservations WHERE business_id=? AND quote_id=? AND id=? AND status='pending' FOR UPDATE");
            $s->execute([$biz,$qid,$resId]);$r=$s->fetch();$s->closeCursor();
            if(!$r||reservationExpired($r['expires_on'])||reservationExpired($expiry))fail('Reserva vencida o fecha límite inválida. No se puede reactivar stock liberado.',409);
            titoSaleLocation($db,$a,(int)$r['warehouse_id'],true,$request);
            if($r['expires_on']===$expiry)fail('La fecha no cambió.',422);
            $s=$db->prepare('UPDATE serial_reservations SET expires_on=? WHERE business_id=? AND id=?');$s->execute([$expiry,$biz,$resId]);
            $s=$db->prepare("UPDATE quotes SET revision=revision+1 WHERE business_id=? AND id=? AND status='reserved'");$s->execute([$biz,$qid]);
            quoteEvent($db,$biz,$qid,$uid,'reservation_date_changed','reserved','reserved',$reason,['reservation_id'=>$resId,'before'=>$r['expires_on'],'after'=>$expiry],$rid,$hash);
            $db->commit();return respond($response,['ok'=>true]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    // Conversión sólo administrador: una venta por cotización aceptada, con precios históricos y series cuando corresponda.
    $group->post('/quotes/{id:[0-9]+}/convert',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$uid=(int)$admin['id'];$qid=(int)$args['id'];
        $b=requestBody($request);
        if(array_diff(array_keys($b),['payment_method','warehouse_id','serial_items','reservation_id','request_id','revision','dispatch_items']))fail('Campos inesperados.',422);
        $payment=$b['payment_method']??null;
        if(!in_array($payment,['efectivo','transferencia','tarjeta','otro'],true))fail('Medio de pago inválido.',422);
        $warehouse=originId($b['warehouse_id']??null,'warehouse_id');
        $resId=array_key_exists('reservation_id',$b)?reservationId($b['reservation_id']):null;
        $dispatch=null;$dispatchRequest=null;$dispatchRevision=null;$dispatchHash=null;
        if(array_key_exists('dispatch_items',$b)||array_key_exists('request_id',$b)||array_key_exists('revision',$b)){
            if($resId===null)fail('El despacho parcial requiere reserva vinculada.',422);
            [$dispatchRequest,$dispatchRevision,$dispatchHash]=quoteCommand($b);
            $raw=$b['dispatch_items']??null;
            if(!is_array($raw)||!array_is_list($raw)||count($raw)<1||count($raw)>50)fail('Selecciona productos a despachar.',422);
            $dispatch=[];
            foreach($raw as $line){
                if(!is_array($line)||array_diff(array_keys($line),['product_id','quantity']))fail('Línea de despacho inválida.',422);
                $pid=originId($line['product_id']??null,'product_id');
                if(!$pid||isset($dispatch[$pid]))fail('Producto repetido o inválido.',422);
                $qty=decimalQuantity($line['quantity']??null,false,true);
                if((float)$qty<=0)fail('Cantidad de despacho inválida.',422);
                $dispatch[$pid]=(float)$qty;
            }
            ksort($dispatch,SORT_NUMERIC);
        }
        $selection=$b['serial_items']??[];
        if(!is_array($selection)||!array_is_list($selection)||count($selection)>50)fail('Selección de series inválida.',422);
        $series=[];
        foreach($selection as $line){
            if(!is_array($line)||array_diff(array_keys($line),['product_id','serial_numbers']))fail('Línea de series inválida.',422);
            $pid=originId($line['product_id']??null,'product_id');
            if($pid===null||isset($series[$pid]))fail('Producto inválido o repetido en las series.',422);
            $series[$pid]=serialNormalizeList($line['serial_numbers']??null);
            if(!$series[$pid])fail('Selecciona al menos una serie por línea serializada.',422);
        }
        ksort($series,SORT_NUMERIC);
        $canonical=[$warehouse,$payment,$series];if($resId!==null)$canonical[]=$resId;
        $conversionHash=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();
        try{
            $db->beginTransaction();originLockCompany($db,$biz);
            $quote=quoteLock($db,$biz,$qid);
            if($dispatch!==null){
                $s=$db->prepare('SELECT quote_id,reservation_id,sale_id,actor_id,payload_hash,total_clp FROM quote_dispatches WHERE business_id=? AND request_id=?');
                $s->execute([$biz,$dispatchRequest]);$old=$s->fetch();$s->closeCursor();
                if($old){
                    if((int)$old['quote_id']!==$qid||(int)$old['reservation_id']!==$resId||(int)$old['actor_id']!==$uid||!hash_equals((string)$old['payload_hash'],$dispatchHash))fail('Identificador de despacho reutilizado con otros datos.',409);
                    $db->commit();return respond($response,['ok'=>true,'quote_id'=>$qid,'sale_id'=>(int)$old['sale_id'],'total_clp'=>(int)$old['total_clp'],'already_recorded'=>true]);
                }
            }
            if($quote['status']==='converted'){
                if($dispatch!==null)fail('Cotización completamente despachada.',409);
                $st=$db->prepare("SELECT payload_hash FROM quote_events WHERE business_id=? AND quote_id=? AND event_type='converted' ORDER BY id DESC LIMIT 1");
                $st->execute([$biz,$qid]);$prior=$st->fetchColumn();$st->closeCursor();
                if(($prior!==false&&$prior!==null&&!hash_equals((string)$prior,$conversionHash))||(($prior===false||$prior===null)&&($series||$resId!==null)))fail('La cotización ya fue convertida con otras condiciones. Consulta la venta #'.$quote['converted_sale_id'].'.',409);
                $db->commit();return respond($response,['ok'=>true,'quote_id'=>$qid,'sale_id'=>(int)$quote['converted_sale_id'],'already_recorded'=>true]);
            }
            $from=$resId===null?'accepted':'reserved';
            if($quote['status']!==$from)fail('Se requiere cotización aceptada o su reserva pendiente, según la operación.',409);
            $reserved=[];$delivered=[];$remaining=[];$isFinal=true;
            if($resId!==null){
                if($dispatch===null)fail('Actualiza la página para seleccionar las cantidades pendientes del despacho.',409);
                if((int)$quote['revision']!==$dispatchRevision)fail('La reserva cambió. Recarga la cotización antes de despachar.',409);
                $s=$db->prepare("SELECT warehouse_id,client_id,expires_on FROM serial_reservations WHERE business_id=? AND quote_id=? AND id=? AND status='pending' FOR UPDATE");
                $s->execute([$biz,$qid,$resId]);$r=$s->fetch();$s->closeCursor();
                if(!$r||$warehouse!==(int)$r['warehouse_id']||(int)$r['client_id']!==(int)$quote['client_id'])fail('La reserva no corresponde a cotización, cliente o bodega.',409);
                if(reservationExpired($r['expires_on']))fail('Reserva vencida. Su saldo fue liberado; no se puede despachar.',409);
                $s=$db->prepare('SELECT product_id,quantity,dispatched_quantity FROM serial_reservation_items WHERE business_id=? AND reservation_id=? ORDER BY product_id FOR UPDATE');
                $s->execute([$biz,$resId]);foreach($s->fetchAll() as $r){$pid=(int)$r['product_id'];$reserved[$pid]=(float)$r['quantity'];$delivered[$pid]=(float)$r['dispatched_quantity'];$remaining[$pid]=round($reserved[$pid]-$delivered[$pid],3);if($remaining[$pid]<0)fail('Saldo de reserva inconsistente.',409);}$s->closeCursor();
                if(!$reserved)fail('Reserva sin productos.',409);
            }
            if($quote['client_id']===null)fail('La cotización debe tener un cliente registrado.',422);
            if($quote['client_id']!==null){
                $st=$db->prepare('SELECT id FROM habitual_clients WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');$st->execute([$biz,(int)$quote['client_id']]);$found=$st->fetchColumn();$st->closeCursor();if(!$found)fail('El cliente de la cotización ya no está activo.',409);
            }
            // Reserva confirmada durante vigencia conserva acuerdo hasta su propio vencimiento o cierre.
            if($resId===null&&$quote['valid_until']<quoteToday())fail('La cotización está vencida. Crea una nueva.',409);
            $st=$db->prepare('SELECT product_id,product_name_snapshot,sale_unit,quantity,list_price_clp,unit_price_clp,discount_reason,subtotal_clp FROM quote_items WHERE quote_id=? ORDER BY product_id');
            $st->execute([$qid]);$items=$st->fetchAll();$st->closeCursor();
            if(!$items)fail('Cotización sin productos.',409);
            $saleLocation=titoSaleLocation($db,$admin,$warehouse,true,$request);
            $cashSessionId=titoCashSessionForSale($db,$admin,$saleLocation);
            // Los productos se bloquean en el orden de la cotización (product_id).
            // Se comprueba el conjunto de líneas completo antes de crear la venta.
            $stock=$db->prepare('SELECT id,name,stock_qty,price_clp,sale_unit FROM products WHERE id=? AND business_id=? AND is_active=1 FOR UPDATE');
            if($resId!==null){
                if(count($reserved)!==count($items)||array_diff_key($dispatch,$reserved))fail('La reserva no coincide con la cotización.',409);
                $selectedItems=[];
                foreach($items as $item){
                    $pid=(int)$item['product_id'];$original=(float)$item['quantity'];$qty=$dispatch[$pid]??0.0;
                    if(!isset($reserved[$pid])||abs($reserved[$pid]-$original)>0.00001||$qty>$remaining[$pid]+0.00001)fail('Cantidad superior al saldo reservado o reserva inconsistente.',409);
                    if($remaining[$pid]-$qty>0.00001)$isFinal=false;
                    if($qty<=0)continue;
                    // Redondeo acumulado: la suma de entregas completas coincide con el subtotal pactado.
                    $before=(int)round($delivered[$pid]*(int)$item['unit_price_clp'],0,PHP_ROUND_HALF_UP);
                    $afterQty=round($delivered[$pid]+$qty,3);
                    $after=abs($afterQty-$original)<0.00001?(int)$item['subtotal_clp']:(int)round($afterQty*(int)$item['unit_price_clp'],0,PHP_ROUND_HALF_UP);
                    $item['quantity']=number_format($qty,3,'.','');$item['subtotal_clp']=$after-$before;
                    if($item['subtotal_clp']<0)fail('Subtotal histórico inconsistente.',409);
                    $selectedItems[]=$item;
                }
                if(!$selectedItems)fail('No hay cantidades pendientes seleccionadas.',422);
                $items=$selectedItems;
            }
            $controlledIds=[];
            foreach($items as $item){
                $pid=(int)$item['product_id'];$qty=(float)$item['quantity'];
                $stock->execute([$pid,$biz]);$p=$stock->fetch();$stock->closeCursor();
                if(!$p)fail('Un producto ya no está activo.',409);
                if($p['sale_unit']!==$item['sale_unit'])fail('Un producto cambió de unidad. Revisa la cotización.',409);
                if($qty<=0||(float)$p['stock_qty']+0.00001<$qty)fail('Stock total insuficiente para '.$p['name'],409);
                $controlled=serialControlled($db,$biz,$pid);
                if($p['sale_unit']==='unidad'&&floor($qty)!==$qty)fail('El producto requiere cantidades enteras.',422);
                if($controlled){
                    $controlledIds[$pid]=true;
                    if($p['sale_unit']!=='unidad'||$qty!==(float)count($series[$pid]??[]))throw new HttpError('Selecciona exactamente una serie por unidad de '.$p['name'].'.',422,'serial_numbers');
                    serialReconcile($db,$biz,$pid,(float)$p['stock_qty']);
                }
                serialRequireFree($db,$biz,$pid,$saleLocation,$qty,$resId);
            }
            if(array_diff_key($series,$controlledIds))fail('Se enviaron series para productos no serializados o ajenos a la cotización.',422);
            // Cada entrega registra una venta independiente; cotización y reserva están bloqueadas.
            $rid=sprintf('%08x-%04x-4%03x-%04x-%012x',random_int(0,0xffffffff),random_int(0,0xffff),random_int(0,0xfff),random_int(0x8000,0xbfff),random_int(0,0xffffffffffff));
            $st=$db->prepare('INSERT INTO sales(business_id,sold_by,request_id,payment_method,total_clp,warehouse_id,cash_session_id,client_id,client_name_snapshot,client_rut_snapshot,customer_mode) VALUES(?,?,?,?,0,?,?,?,?,?,?)');
            $st->execute([$biz,$uid,$rid,$payment,$saleLocation,$cashSessionId,$quote['client_id'],$quote['client_id']===null?null:$quote['customer_name'],$quote['customer_rut'],$quote['client_id']===null?null:'registered']);$sid=(int)$db->lastInsertId();
            if($controlledIds){
                $st=$db->prepare('INSERT INTO serial_sale_snapshots(sale_id,business_id,client_id,client_name,payload_hash) VALUES(?,?,?,?,?)');
                $st->execute([$sid,$biz,(int)$quote['client_id'],$quote['customer_name'],$conversionHash]);
            }
            salesPointRecord($db,$admin,$request,$sid,$saleLocation);
             $insert=$db->prepare('INSERT INTO sale_items(sale_id,product_id,product_name_snapshot,quantity,unit_price_clp,list_price_clp,discount_reason,subtotal_clp) VALUES(?,?,?,?,?,?,?,?)');
            $total=0;
            foreach($items as $item){
                $pid=(int)$item['product_id'];$qty=(float)$item['quantity'];
                // El precio se obtiene exclusivamente de quote_items, no de la solicitud o del catálogo.
                $next=isset($controlledIds[$pid])?titoSellSerialLocationStock($db,$biz,$pid,$saleLocation,$qty,$resId):titoApplyLocationStock($db,$biz,$pid,$saleLocation,-$qty,$resId);
                $insert->execute([$sid,$pid,$item['product_name_snapshot'],$item['quantity'],$item['unit_price_clp'],$item['list_price_clp'],$item['discount_reason'],$item['subtotal_clp']]);
                $lineId=(int)$db->lastInsertId();
                if(isset($controlledIds[$pid])){
                    serialSaleRecord($db,$admin,$pid,$saleLocation,$sid,$lineId,(int)$item['unit_price_clp'],$series[$pid]);
                    serialReconcile($db,$biz,$pid,(float)$next);
                }
                move($db,$biz,$pid,$sid,$uid,'sale',number_format(-$qty,3,'.',''),$next,'Venta desde cotización #'.$qid);
                $total+=(int)$item['subtotal_clp'];
            }
            $st=$db->prepare('UPDATE sales SET total_clp=? WHERE id=? AND business_id=?');
            $st->execute([$total,$sid,$biz]);
            $to=$isFinal?'converted':'reserved';
            // converted_sale_id conserva compatibilidad: última venta al completar; historial en quote_dispatches.
            $st=$db->prepare("UPDATE quotes SET status=?,converted_sale_id=?,revision=revision+1 WHERE id=? AND business_id=? AND status=?");
            $st->execute([$to,$isFinal?$sid:null,$qid,$biz,$from]);
            if($st->rowCount()!==1)throw new RuntimeException('No se pudo convertir la cotización.');
            titoCashRegisterSale($db,$biz,$uid,$cashSessionId,$sid,$rid,$total,$payment);
            if($resId!==null){
                $s=$db->prepare('UPDATE serial_reservation_items SET dispatched_quantity=dispatched_quantity+? WHERE business_id=? AND reservation_id=? AND product_id=? AND dispatched_quantity+?<=quantity');
                foreach($items as $item){$s->execute([$item['quantity'],$biz,$resId,$item['product_id'],$item['quantity']]);if($s->rowCount()!==1)fail('Saldo de reserva cambió.',409);}
                $s=$db->prepare('INSERT INTO quote_dispatches(business_id,quote_id,reservation_id,sale_id,actor_id,request_id,payload_hash,total_clp) VALUES(?,?,?,?,?,?,?,?)');
                $s->execute([$biz,$qid,$resId,$sid,$uid,$dispatchRequest,$dispatchHash,$total]);
                if($isFinal)reservationComplete($db,$biz,$resId,$sid,$uid);
            }
            quoteEvent($db,$biz,$qid,$uid,$isFinal?'converted':'partial_dispatch',$from,$to,null,['sale_id'=>$sid,'reservation_id'=>$resId,'total_clp'=>$total,'warehouse_id'=>$saleLocation,'payment_method'=>$payment,'serial_items'=>$series,'items'=>array_map(static fn($i)=>['product_id'=>$i['product_id'],'quantity'=>$i['quantity'],'subtotal_clp'=>$i['subtotal_clp']],$items)],$dispatchRequest,$dispatchHash??$conversionHash);
            $db->commit();
            return respond($response,['ok'=>true,'quote_id'=>$qid,'sale_id'=>$sid,'total_clp'=>$total,'warehouse_id'=>$saleLocation,'complete'=>$isFinal],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    });
}

