<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

// Todas las mutaciones de reservas y salidas serializadas toman bloqueo de empresa.
function serialReservedQuantity(PDO $db,int $biz,int $pid,int $wid,?int $except=null): int {
    $s=$db->prepare("SELECT i.quantity FROM serial_reservation_items i JOIN serial_reservations r ON r.id=i.reservation_id AND r.business_id=i.business_id WHERE i.business_id=? AND i.product_id=? AND r.warehouse_id=? AND r.status='pending' AND (? IS NULL OR r.id<>?) FOR UPDATE");
    $s->execute([$biz,$pid,$wid,$except,$except]);$n=0;foreach($s->fetchAll() as $row)$n+=(int)$row['quantity'];$s->closeCursor();return $n;
}
function serialRequireFree(PDO $db,int $biz,int $pid,int $wid,float $qty,?int $except=null): void {
    $s=$db->prepare('SELECT qty FROM warehouse_stocks WHERE business_id=? AND product_id=? AND warehouse_id=? FOR UPDATE');$s->execute([$biz,$pid,$wid]);$physical=$s->fetchColumn();$s->closeCursor();
    if($physical===false||$qty>(float)$physical-serialReservedQuantity($db,$biz,$pid,$wid,$except)+0.00001)fail('Stock libre insuficiente: existen unidades comprometidas en reservas.',409);
}
function reservationId(mixed $v): int {if(!is_int($v)||$v<1)fail('Reserva inválida.',422);return $v;}
function reservationLockForSale(PDO $db,int $biz,int $rid,int $client,int $wid,array $wanted): void {
    $s=$db->prepare('SELECT client_id,warehouse_id,status,quote_id FROM serial_reservations WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$rid]);$row=$s->fetch();$s->closeCursor();
    if(!$row||$row['status']!=='pending'||(int)$row['client_id']!==$client||(int)$row['warehouse_id']!==$wid)fail('Reserva no vigente o no corresponde a cliente y bodega.',409);
    if($row['quote_id']!==null)fail('Despacha esta reserva desde la cotización #'.$row['quote_id'].' para conservar sus precios.',409);
    $s=$db->prepare('SELECT product_id,quantity FROM serial_reservation_items WHERE business_id=? AND reservation_id=? ORDER BY product_id FOR UPDATE');$s->execute([$biz,$rid]);$lines=$s->fetchAll();$s->closeCursor();
    if(count($lines)!==count($wanted))fail('Despacha exactamente los productos reservados.',422);
    foreach($lines as $l){$p=(int)$l['product_id'];$v=$wanted[$p]??null;if(!$v||!isset($v['serial_numbers'])||count($v['serial_numbers'])!==(int)$l['quantity']||(float)$v['quantity']!==(float)$l['quantity'])fail('Cantidad y series deben coincidir con la reserva.',422);}
}
function reservationComplete(PDO $db,int $biz,int $rid,int $sale,int $uid): void {
    $s=$db->prepare("UPDATE serial_reservations SET status='dispatched',sale_id=?,closed_by=?,closed_at=UTC_TIMESTAMP() WHERE id=? AND business_id=? AND status='pending'");$s->execute([$sale,$uid,$rid,$biz]);if($s->rowCount()!==1)fail('Reserva no disponible.',409);
}
function registerReservationRoutes(RouteCollectorProxy $group): void {
    $group->get('/sales/reservations',static function(Request $request,Response $response): Response {
        $u=$request->getAttribute('currentUser');$biz=(int)$u['business_id'];$q=$request->getQueryParams();$wid=filter_var($q['warehouse_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($wid===false)fail('Bodega inválida.',422);$db=db();$wid=titoSaleLocation($db,$u,$wid,true,$request);
        $s=$db->prepare("SELECT r.id,r.quote_id,r.client_id,r.client_name,r.warehouse_id,r.status,r.note,r.sale_id,r.created_at,r.closed_at,r.cancel_reason,(r.created_by=? OR ?=1) AS can_cancel FROM serial_reservations r WHERE r.business_id=? AND r.warehouse_id=? ORDER BY (r.status='pending') DESC,r.id DESC LIMIT 100");
        $s->execute([(int)$u['id'],($u['role']??'')==='admin'?1:0,$biz,$wid]);$rows=$s->fetchAll();$s->closeCursor();
        $s=$db->prepare('SELECT i.product_id,i.quantity,p.name,p.sku FROM serial_reservation_items i JOIN products p ON p.id=i.product_id AND p.business_id=i.business_id WHERE i.business_id=? AND i.reservation_id=? ORDER BY i.product_id');
        foreach($rows as &$r){$s->execute([$biz,$r['id']]);$r['items']=$s->fetchAll();$s->closeCursor();}unset($r);
        return respond($response,['reservations'=>$rows]);
    });
    $group->post('/sales/reservations',static function(Request $request,Response $response): Response {
        $u=$request->getAttribute('currentUser');$biz=(int)$u['business_id'];$uid=(int)$u['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','warehouse_id','client_id','note','items']))fail('Campos inválidos.',422);
        $rid=$b['request_id']??null;if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$rid))fail('Identificador inválido.',422);$rid=strtolower($rid);
        $wid=originId($b['warehouse_id']??null,'warehouse_id');$cid=originId($b['client_id']??null,'client_id');if($wid===null||$cid===null)fail('Selecciona bodega y cliente.',422);
        $note=$b['note']??'';if(!is_string($note)||mb_strlen($note)>250)fail('Observación inválida.',422);$note=trim($note);
        $items=$b['items']??null;if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>50)fail('Selecciona entre 1 y 50 productos serializados.',422);
        $spec=[];foreach($items as $i){if(!is_array($i)||array_diff(array_keys($i),['product_id','quantity']))fail('Línea inválida.',422);$pid=originId($i['product_id']??null,'product_id');$qty=$i['quantity']??null;if(!$pid||isset($spec[$pid])||!is_int($qty)||$qty<1||$qty>500)fail('Cantidad inválida (1 a 500 unidades por producto).',422);$spec[$pid]=$qty;}ksort($spec,SORT_NUMERIC);
        $hash=hash('sha256',json_encode([$uid,$wid,$cid,$note,$spec],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$db=db();
        try{
            $db->beginTransaction();originLockCompany($db,$biz);
            $s=$db->prepare('SELECT id,payload_hash FROM serial_reservations WHERE business_id=? AND request_id=? FOR UPDATE');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();
            if($old){if(!hash_equals((string)$old['payload_hash'],$hash))fail('Identificador reutilizado con otros datos.',409);$db->commit();return respond($response,['ok'=>true,'reservation_id'=>(int)$old['id'],'already_recorded'=>true]);}
            $wid=titoSaleLocation($db,$u,$wid,true,$request);
            $s=$db->prepare('SELECT name FROM habitual_clients WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');$s->execute([$biz,$cid]);$name=$s->fetchColumn();$s->closeCursor();if($name===false)fail('Cliente no disponible.',422);
            $lock=$db->prepare('SELECT stock_qty,sale_unit FROM products WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');
            foreach($spec as $pid=>$qty){$lock->execute([$biz,$pid]);$p=$lock->fetch();$lock->closeCursor();if(!$p||$p['sale_unit']!=='unidad'||!serialControlled($db,$biz,$pid))fail('Solo equipos activos con control de serie.',422);serialReconcile($db,$biz,$pid,(float)$p['stock_qty']);serialRequireFree($db,$biz,$pid,$wid,(float)$qty);}
            $s=$db->prepare('INSERT INTO serial_reservations(business_id,warehouse_id,client_id,client_name,created_by,request_id,payload_hash,note) VALUES(?,?,?,?,?,?,?,?)');$s->execute([$biz,$wid,$cid,$name,$uid,$rid,$hash,$note]);$id=(int)$db->lastInsertId();
            $s=$db->prepare('INSERT INTO serial_reservation_items(reservation_id,business_id,product_id,quantity) VALUES(?,?,?,?)');foreach($spec as $pid=>$qty)$s->execute([$id,$biz,$pid,$qty]);
            $db->commit();return respond($response,['ok'=>true,'reservation_id'=>$id],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/sales/reservations/{id:[0-9]+}/cancel',static function(Request $request,Response $response,array $args): Response {
        $u=$request->getAttribute('currentUser');$biz=(int)$u['business_id'];$uid=(int)$u['id'];$id=(int)$args['id'];$b=requestBody($request);$reason=requireString($b,'reason',250);if(mb_strlen($reason)<3)fail('Indica motivo.',422);
        $db=db();try{$db->beginTransaction();originLockCompany($db,$biz);
            $s=$db->prepare('SELECT warehouse_id,created_by,status,quote_id FROM serial_reservations WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$id]);$r=$s->fetch();$s->closeCursor();
            if(!$r)fail('Reserva no encontrada.',404);titoSaleLocation($db,$u,(int)$r['warehouse_id'],true,$request);
            if($r['quote_id']!==null)fail('Cancela la reserva desde su cotización #'.$r['quote_id'].'.',409);
            if((int)$r['created_by']!==$uid&&($u['role']??'')!=='admin')fail('Solo autor o administrador puede cancelar.',403);
            if($r['status']==='cancelled'){$db->commit();return respond($response,['ok'=>true]);}if($r['status']!=='pending')fail('Reserva ya despachada.',409);
            $s=$db->prepare("UPDATE serial_reservations SET status='cancelled',closed_by=?,closed_at=UTC_TIMESTAMP(),cancel_reason=? WHERE business_id=? AND id=? AND status='pending'");$s->execute([$uid,$reason,$biz,$id]);$db->commit();return respond($response,['ok'=>true]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
