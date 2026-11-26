<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Resumen confiable: no acepta nombres, descuentos ni montos inventados por el cliente. */
function titoRemoteDiscountSnapshot(PDO $db,int $biz,array $sale): array {
    $fingerprint=titoSaleDiscountFingerprint($sale);
    $st=$db->prepare('SELECT id,name,price_clp,sale_unit,stock_qty FROM products WHERE business_id=? AND id=? AND is_active=1');
    $rows=[];$prices=[];$gross=0;$net=0;$discounted=false;
    foreach($sale['items'] as $item){
        $pid=(int)$item['product_id'];
        $st->execute([$biz,$pid]);$p=$st->fetch();$st->closeCursor();
        if(!$p)fail('Producto no disponible: '.$pid,409);
        $qty=decimalQuantity($item['quantity'],false,$p['sale_unit']!=='unidad');
        if((float)$qty<=0)fail('Cantidad inválida.',422);
        $price=(int)$p['price_clp'];
        $agreed=array_key_exists('unit_price_clp',$item)?integerPrice($item['unit_price_clp']):$price;
        if($agreed>$price||($price>0&&$agreed===0))fail('Precio negociado inválido.',422);
        $reason=$item['discount_reason']??null;
        if($agreed<$price){
            if(!is_string($reason)||trim($reason)===''||mb_strlen(trim($reason))>120)fail('El descuento necesita un motivo.',422);
            $discounted=true;
        }
        $prices[$pid]=$price;
        $rows[]=['product_id'=>$pid,'name'=>$p['name'],'quantity'=>(float)$qty,'unit'=>$p['sale_unit'],'list_price_clp'=>$price,'unit_price_clp'=>$agreed,'discount_reason'=>$agreed<$price?trim($reason):null];
        $gross+=(int)round((float)$qty*$price,0,PHP_ROUND_HALF_UP);
        $net+=(int)round((float)$qty*$agreed,0,PHP_ROUND_HALF_UP);
        if($gross>1000000000000||$net>1000000000000)fail('Monto fuera de rango.',422);
    }
    if(!$discounted)fail('Esta venta no tiene descuentos que autorizar.',422);
    return ['fingerprint'=>$fingerprint,'prices'=>$prices,'summary'=>['items'=>$rows,'list_total_clp'=>$gross,'total_clp'=>$net,'discount_clp'=>$gross-$net,'warehouse_id'=>$sale['warehouse_id']??null]];
}

/** Consumida desde la misma transacción de POST /sales, antes de descontar stock. */
function titoConsumeRemoteDiscountApproval(PDO $db,array $user,array $sale,int $saleId): array {
    if(($user['role']??'')!=='cashier')fail('La autorización remota requiere sesión de cajero.',403);
    $id=filter_var($sale['discount_request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($id===false||$id===null)fail('Solicitud de descuento inválida.',403);
    $fp=titoSaleDiscountFingerprint($sale);
    $st=$db->prepare('SELECT id,payload_hash,price_snapshot_json,summary_json FROM remote_discount_requests WHERE id=? AND business_id=? AND cashier_id=? AND sale_request_id=? AND status=? AND expires_at>UTC_TIMESTAMP() AND used_sale_id IS NULL FOR UPDATE');
    $st->execute([$id,(int)$user['business_id'],(int)$user['id'],strtolower($sale['request_id']),'approved']);
    $row=$st->fetch();$st->closeCursor();
    if(!$row||!hash_equals((string)$row['payload_hash'],$fp))fail('La autorización remota no está aprobada, ya venció o no corresponde a esta venta.',403);
    $snap=json_decode((string)$row['price_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
    if(!is_array($snap)||!$snap)fail('El registro de precios está incompleto.',409);
    // Verificar la unidad original también al convertir: pudo cambiar tras la aprobación.
    $summary=json_decode((string)$row['summary_json'],true,512,JSON_THROW_ON_ERROR);
    if(!is_array($summary)||!isset($summary['items'])||!is_array($summary['items'])||!$summary['items'])fail('Resumen de venta incompleto.',409);
    $st=$db->prepare('SELECT sale_unit FROM products WHERE business_id=? AND id=? AND is_active=1');
    foreach($summary['items'] as $line){
        $pid=(int)$line['product_id'];
        if(!array_key_exists($pid,$snap))fail('Producto fuera de la autorización.',409);
        $st->execute([(int)$user['business_id'],$pid]);
        $unit=$st->fetchColumn();$st->closeCursor();
        if($unit===false||(string)$unit!==(string)$line['unit'])fail('Un producto cambió de unidad o está inactivo. Solicita una nueva autorización.',409);
    }
    $st=$db->prepare("UPDATE remote_discount_requests SET status='completed',used_sale_id=? WHERE id=? AND status='approved' AND used_sale_id IS NULL");
    $st->execute([$saleId,$id]);
    if($st->rowCount()!==1)fail('La autorización remota ya fue utilizada.',409);
    return $snap;
}

/** Paginación real en MySQL, con fechas interpretadas en horario de Chile. */
function titoRemoteDate(string $day): DateTimeImmutable {
    if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$day))fail('Fecha inválida. Usa AAAA-MM-DD.',422);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day,new DateTimeZone('America/Santiago'));
    if(!$date||$date->format('Y-m-d')!==$day)fail('Fecha inválida.',422);
    return $date;
}

function titoRemotePaginatedList(PDO $db,int $businessId,array $params): array {
    $page=filter_var($params['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
    $perPage=filter_var($params['per_page']??10,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>50]]);
    if($page===false||$perPage===false)fail('Página o tamaño de página inválido.',422);
    $status=(string)($params['status']??'all');
    if(!in_array($status,['all','pending','approved','rejected','completed','expired','cancelled'],true))fail('Estado no permitido.',422);
    $from=isset($params['from'])&&$params['from']!==''?titoRemoteDate((string)$params['from']):null;
    $to=isset($params['to'])&&$params['to']!==''?titoRemoteDate((string)$params['to']):null;
    if($from&&$to&&$from>$to)fail('La fecha inicial no puede ser posterior a la final.',422);

    $effective="CASE WHEN r.status IN ('pending','approved') AND r.expires_at<=UTC_TIMESTAMP() AND r.used_sale_id IS NULL THEN 'expired' ELSE r.status END";
    $where=['r.business_id=?'];
    $values=[$businessId];
    if($status!=='all'){$where[]="($effective)=?";$values[]=$status;}
    if($from){$where[]='r.created_at>=?';$values[]=$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
    if($to){$where[]='r.created_at<?';$values[]=$to->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
    $clause=implode(' AND ',$where);

    $count=$db->prepare("SELECT COUNT(*) FROM remote_discount_requests r WHERE $clause");
    $count->execute($values);$total=(int)$count->fetchColumn();$count->closeCursor();
    $pages=max(1,(int)ceil($total/$perPage));
    $page=min($page,$pages);
    $offset=($page-1)*$perPage;

    $sql="SELECT r.id,r.cashier_id,u.email AS cashier_email,r.summary_json,r.created_at,r.expires_at,$effective AS status
      FROM remote_discount_requests r
      JOIN users u ON u.id=r.cashier_id AND u.business_id=r.business_id
      WHERE $clause
      ORDER BY CASE WHEN r.status='pending' AND r.expires_at>UTC_TIMESTAMP() THEN 0 ELSE 1 END,r.id DESC
      LIMIT ? OFFSET ?";
    $statement=$db->prepare($sql);
    $index=1;
    foreach($values as $value){
        $statement->bindValue($index++,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    }
    $statement->bindValue($index++,$perPage,PDO::PARAM_INT);
    $statement->bindValue($index,$offset,PDO::PARAM_INT);
    $statement->execute();
    $rows=[];
    foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $row){
        $row['summary']=json_decode((string)$row['summary_json'],true);
        unset($row['summary_json']);
        $rows[]=$row;
    }
    $statement->closeCursor();
    $pending=$db->prepare("SELECT COUNT(*) FROM remote_discount_requests WHERE business_id=? AND status='pending' AND expires_at>UTC_TIMESTAMP()");
    $pending->execute([$businessId]);$pendingCount=(int)$pending->fetchColumn();
    return ['requests'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>$pages,'pending_count'=>$pendingCount];
}


function registerRemoteDiscountRoutes(RouteCollectorProxy $group): void {
    $group->post('/sales/discount-requests',static function(Request $request,Response $response): Response {
        $user=$request->getAttribute('currentUser');
        if(!is_array($user)||($user['role']??'')!=='cashier')fail('Solo un cajero puede solicitar autorización remota.',403);
        $body=requestBody($request);
        if(array_diff(array_keys($body),['sale'])||!isset($body['sale'])||!is_array($body['sale']))fail('Solicitud inválida.',422);
        $sale=$body['sale'];
        if(array_diff(array_keys($sale),['request_id','payment_method','items','warehouse_id']))fail('La solicitud contiene campos no permitidos.',422);
        $biz=(int)$user['business_id'];$uid=(int)$user['id'];$db=db();
        titoSaleLocation($db,$user,$sale['warehouse_id']??null,true,$request);
        $snapshot=titoRemoteDiscountSnapshot($db,$biz,$sale);
        $rid=strtolower($sale['request_id']);
        $st=$db->prepare('SELECT id,cashier_id,payload_hash,status,expires_at FROM remote_discount_requests WHERE business_id=? AND sale_request_id=? LIMIT 1');
        $st->execute([$biz,$rid]);$found=$st->fetch();$st->closeCursor();
        if($found){
            if((int)$found['cashier_id']!==$uid||!hash_equals((string)$found['payload_hash'],$snapshot['fingerprint']))fail('Ya existe una solicitud diferente para esta venta.',409);
            return respond($response,['ok'=>true,'request_id'=>(int)$found['id'],'status'=>$found['status'],'already_recorded'=>true]);
        }
        $st=$db->prepare("INSERT INTO remote_discount_requests(business_id,cashier_id,sale_request_id,payload_hash,price_snapshot_json,summary_json,status,created_at,expires_at) VALUES(?,?,?,?,?,?,'pending',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))");
        try{
            $st->execute([$biz,$uid,$rid,$snapshot['fingerprint'],json_encode($snapshot['prices'],JSON_THROW_ON_ERROR),json_encode($snapshot['summary'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        }catch(PDOException $e){
            if((int)($e->errorInfo[1]??0)===1062){
                $st=$db->prepare('SELECT id,cashier_id,payload_hash,status FROM remote_discount_requests WHERE business_id=? AND sale_request_id=?');
                $st->execute([$biz,$rid]);$found=$st->fetch();
                if($found&&(int)$found['cashier_id']===$uid&&hash_equals((string)$found['payload_hash'],$snapshot['fingerprint']))return respond($response,['ok'=>true,'request_id'=>(int)$found['id'],'status'=>$found['status'],'already_recorded'=>true]);
                fail('Solicitud duplicada con datos diferentes.',409);
            }
            throw $e;
        }
        $newRequestId=(int)$db->lastInsertId();
        // onesignal:managed v1: notificar solo nuevas solicitudes; el push jamás decide una venta.
        if(function_exists('titoOneSignalNotifyNewDiscount'))titoOneSignalNotifyNewDiscount($db,$biz,$newRequestId);
        return respond($response,['ok'=>true,'request_id'=>$newRequestId,'status'=>'pending','expires_in_seconds'=>900],201);
    });

    $group->get('/sales/discount-requests',static function(Request $request,Response $response): Response {
        $admin=titoStaffRequire($request,'can_approve_discounts');
        $params=$request->getQueryParams();
        if(array_intersect(['page','per_page','status','from','to'],array_keys($params))){
            return respond($response,titoRemotePaginatedList(db(),(int)$admin['business_id'],$params));
        }
        // Compatibilidad con el contador anterior: sin parámetros, 50 filas recientes.
        $st=db()->prepare("SELECT r.id,r.cashier_id,u.email AS cashier_email,r.summary_json,r.created_at,r.expires_at,CASE WHEN r.status IN ('pending','approved') AND r.expires_at<=UTC_TIMESTAMP() THEN 'expired' ELSE r.status END AS status FROM remote_discount_requests r JOIN users u ON u.id=r.cashier_id AND u.business_id=r.business_id WHERE r.business_id=? AND r.status IN ('pending','approved','rejected','completed') ORDER BY CASE WHEN r.status='pending' AND r.expires_at>UTC_TIMESTAMP() THEN 0 ELSE 1 END,r.id DESC LIMIT 50");
        $st->execute([(int)$admin['business_id']]);
        $items=[];
        foreach($st->fetchAll() as $r){
            $r['summary']=json_decode($r['summary_json'],true);unset($r['summary_json']);
            $items[]=$r;
        }
        return respond($response,['requests'=>$items,'pending_count'=>count(array_filter($items,static fn($r)=>$r['status']==='pending'))]);
    });

    $group->get('/sales/discount-requests/{id:[0-9]+}',static function(Request $request,Response $response,array $args): Response {
        $user=$request->getAttribute('currentUser');
        $st=db()->prepare("SELECT r.id,r.cashier_id,CASE WHEN r.status IN ('pending','approved') AND r.expires_at<=UTC_TIMESTAMP() AND r.used_sale_id IS NULL THEN 'expired' ELSE r.status END AS effective_status,r.used_sale_id,r.decision_note,r.decided_at,r.summary_json FROM remote_discount_requests r WHERE r.id=? AND r.business_id=? LIMIT 1");
        $st->execute([(int)$args['id'],(int)$user['business_id']]);$r=$st->fetch();
        if(!$r)fail('Solicitud no encontrada.',404);
        if(!titoStaffCan(db(),$user,'can_approve_discounts')&&(int)$r['cashier_id']!==(int)$user['id'])fail('No puedes ver esta solicitud.',403);
        $status=$r['effective_status'];
        return respond($response,['request'=>[
            'id'=>(int)$r['id'],'status'=>$status,'used_sale_id'=>$r['used_sale_id']===null?null:(int)$r['used_sale_id'],
            'decision_note'=>$r['decision_note'],'decided_at'=>$r['decided_at'],'summary'=>json_decode($r['summary_json'],true)
        ]]);
    });

    $group->post('/sales/discount-requests/{id:[0-9]+}/decision',static function(Request $request,Response $response,array $args): Response {
        $admin=titoStaffRequire($request,'can_approve_discounts');$body=requestBody($request);
        if(array_diff(array_keys($body),['decision','reason']))fail('Campos no permitidos.',422);
        $decision=$body['decision']??null;
        if(!in_array($decision,['approve','reject'],true))fail('Decisión inválida.',422);
        $reason=$body['reason']??'';
        if(!is_string($reason)||mb_strlen(trim($reason))>250)fail('Motivo demasiado largo.',422);
        $db=db();
        try{
            $db->beginTransaction();
            $st=$db->prepare('SELECT id,status,expires_at,expires_at<=UTC_TIMESTAMP() AS is_expired,summary_json,price_snapshot_json FROM remote_discount_requests WHERE business_id=? AND id=? FOR UPDATE');
            $st->execute([(int)$admin['business_id'],(int)$args['id']]);$r=$st->fetch();$st->closeCursor();
            if(!$r)fail('Solicitud no encontrada.',404);
            if($r['status']!=='pending')fail('La solicitud ya fue atendida.',409);
            if((int)$r['is_expired']===1)fail('La solicitud venció. El cajero debe generar una nueva.',409);
            if($decision==='approve'){
                // El precio de la solicitud es inmutable. Revisar disponibilidad y unidad,
                // NO exigir que el catálogo siga teniendo el mismo precio.
                $summary=json_decode((string)$r['summary_json'],true,512,JSON_THROW_ON_ERROR);
                if(!is_array($summary)||!isset($summary['items'])||!is_array($summary['items'])||!$summary['items'])fail('El resumen de la autorización no está disponible.',409);
                $st=$db->prepare('SELECT sale_unit FROM products WHERE business_id=? AND id=? AND is_active=1');
                foreach($summary['items'] as $line){
                    $st->execute([(int)$admin['business_id'],(int)$line['product_id']]);
                    $currentUnit=$st->fetchColumn();$st->closeCursor();
                    if($currentUnit===false||(string)$currentUnit!==(string)$line['unit'])fail('Un producto ya no está activo o cambió de unidad. Solicita una nueva autorización.',409);
                }
            }
            $new=$decision==='approve'?'approved':'rejected';
            $st=$db->prepare('UPDATE remote_discount_requests SET status=?,decided_by=?,decided_at=UTC_TIMESTAMP(),decision_note=? WHERE id=? AND business_id=? AND status=?');
            $st->execute([$new,(int)$admin['id'],trim($reason)?:null,(int)$args['id'],(int)$admin['business_id'],'pending']);
            if($st->rowCount()!==1)fail('Solicitud ya fue atendida.',409);
            if(($admin['role']??'')==='cashier'){
                $summary=json_decode((string)$r['summary_json'],true);
                titoStaffEvent($db,(int)$admin['business_id'],(int)$admin['id'],$decision==='approve'?'discount_approved':'discount_rejected',null,null,isset($summary['discount_clp'])?(int)$summary['discount_clp']:null,'Solicitud remota #'.(int)$args['id']);
            }
            $db->commit();
            if(($admin['role']??'')==='cashier'){
                titoStaffNotifyAdmins($db,(int)$admin['business_id'],'Tito POS · Autorización delegada','Un cajero habilitado '.($decision==='approve'?'aprobó':'rechazó').' la solicitud de descuento #'.(int)$args['id'].'.');
            }
            return respond($response,['ok'=>true,'request_id'=>(int)$args['id'],'status'=>$new]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });

    $group->get('/sales/{id:[0-9]+}/remote-discount-approval',static function(Request $request,Response $response,array $args): Response {
        $user=$request->getAttribute('currentUser');
        $st=db()->prepare('SELECT r.id,r.decided_at,a.email AS supervisor_email,s.sold_by FROM remote_discount_requests r JOIN sales s ON s.id=r.used_sale_id AND s.business_id=r.business_id JOIN users a ON a.id=r.decided_by AND a.business_id=r.business_id WHERE r.business_id=? AND r.used_sale_id=? AND r.status=? LIMIT 1');
        $st->execute([(int)$user['business_id'],(int)$args['id'],'completed']);
        $record=$st->fetch();$st->closeCursor();
        if(!$record)return respond($response,['approval'=>null]);
        if(($user['role']??'')!=='admin'&&(int)$record['sold_by']!==(int)$user['id'])fail('No puedes ver esta autorización.',403);
        return respond($response,['approval'=>[
            'supervisor_email'=>$record['supervisor_email'],
            'approved_at'=>$record['decided_at'],
            'remote'=>true
        ]]);
    });

    $group->post('/sales/discount-requests/{id:[0-9]+}/cancel',static function(Request $request,Response $response,array $args): Response {
        $user=$request->getAttribute('currentUser');
        if(($user['role']??'')!=='cashier')fail('Solo el cajero puede cancelar su solicitud.',403);
        $db=db();
        $st=$db->prepare("UPDATE remote_discount_requests SET status='cancelled' WHERE id=? AND business_id=? AND cashier_id=? AND status IN ('pending','approved') AND used_sale_id IS NULL");
        $st->execute([(int)$args['id'],(int)$user['business_id'],(int)$user['id']]);
        if($st->rowCount()!==1)fail('La solicitud ya fue atendida, no existe o ya tiene una venta.',409);
        return respond($response,['ok'=>true,'status'=>'cancelled']);
    });
}

