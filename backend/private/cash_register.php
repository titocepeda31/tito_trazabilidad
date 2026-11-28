<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * Caja MVP: sesión de custodia vinculada al cajero y gaveta, NO al terminal.
 * Todas las escrituras de venta/caja se ejecutan bajo transacción MySQL.
 * No representa un documento tributario ni confirma cobros bancarios.
 */
function titoCashInt(mixed $v,string $label,int $limit=100000000000): int {
    if(!is_int($v)||$v<0||$v>$limit)fail($label.' debe ser un entero CLP entre cero y el máximo permitido.',422);
    return $v;
}
function titoCashUuid(mixed $v): string {
    if(!is_string($v)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',$v))fail('Identificador de operación inválido.',422);
    return strtolower($v);
}
function titoCashReady(PDO $db): bool {
    static $ready=null;
    if($ready!==null)return $ready;
    $st=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cash_sessions','cash_drawers','cash_movements','cash_policy')");
    return $ready=((int)$st->fetchColumn()===4);
}
function titoCashRequired(PDO $db,int $businessId): bool {
    if(!titoCashReady($db))return false;
    $st=$db->prepare('SELECT require_open_session FROM cash_policy WHERE business_id=?');
    $st->execute([$businessId]);$v=$st->fetchColumn();$st->closeCursor();
    return $v!==false&&(int)$v===1;
}
/**
 * Debe llamarse dentro de transacción, después de titoSaleLocation.
 * Si el cajero posee sesión en otra sucursal, se bloquea siempre, incluso
 * con el modo obligatorio desactivado: nunca mezclar efectivo entre locales.
 */
function titoCashSessionForSale(PDO $db,array $user,int $warehouseId): ?int {
    if(!titoCashReady($db))return null;
    if(!$db->inTransaction())throw new RuntimeException('Las ventas con caja requieren transacción.');
    $businessId=(int)$user['business_id'];$userId=(int)$user['id'];
    $st=$db->prepare("SELECT cs.id,cs.warehouse_id,cd.is_active,cd.assigned_cashier_id
       FROM cash_sessions cs JOIN cash_drawers cd ON cd.id=cs.drawer_id AND cd.business_id=cs.business_id
       WHERE cs.business_id=? AND cs.cashier_id=? AND cs.status='open' FOR UPDATE");
    $st->execute([$businessId,$userId]);$session=$st->fetch();$st->closeCursor();
    if(!$session){
        if(titoCashRequired($db,$businessId))fail('Abre una sesión de caja antes de registrar esta venta.',409);
        return null; // Compatibilidad gradual con cajas anteriores al módulo.
    }
    if((int)$session['warehouse_id']!==$warehouseId)fail('La gaveta activa pertenece a otra sucursal. Cierra o entrega tu turno antes de vender aquí.',409);
    if((int)$session['is_active']!==1||(int)$session['assigned_cashier_id']!==$userId)fail('Gaveta inactiva o asignada a otra persona.',409);
    return (int)$session['id'];
}
/** El ingreso de efectivo solo se reconoce cuando una venta se confirma realmente. */
function titoCashRegisterSale(PDO $db,int $businessId,int $cashierId,?int $sessionId,int $saleId,string $requestId,int $amount,string $payment): void {
    if($sessionId===null)return;
    if(!$db->inTransaction())throw new RuntimeException('Registro monetario sin transacción.');
    if($payment!=='efectivo')return; // Tarjetas/transferencias NO ingresan a la gaveta.
    if($amount<0||$amount>100000000000)fail('Monto de venta inválido.',422);
    $st=$db->prepare("INSERT INTO cash_movements(business_id,cash_session_id,request_id,movement_type,amount_delta_clp,source_sale_id,actor_id,reason)
       VALUES(?,?,?,'cash_sale',?,?,?,'Venta en efectivo confirmada')");
    $st->execute([$businessId,$sessionId,$requestId,$amount,$saleId,$cashierId]);
}
/**
 * Genera un identificador de movimiento estable y separado del UUID de
 * origen. Permite reintentos sin contabilizar dos veces un abono/reembolso.
 */
function titoCashSourceRequestId(string $kind,int $sourceId): string {
    $h=hash('sha256','tito-cash-ledger-v1|'.$kind.'|'.$sourceId);
    return substr($h,0,8).'-'.substr($h,8,4).'-5'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);
}
/** Se invoca después de bloquear la cuenta/devolución, dentro de la misma transacción. */
function titoCashSessionForReceipt(PDO $db,array $user,Request $request): ?int {
    if(!titoCashReady($db))return null;
    if(!$db->inTransaction())throw new RuntimeException('La recaudación requiere transacción.');
    $location=titoSaleLocation($db,$user,null,false,$request);
    return titoCashSessionForSale($db,$user,$location);
}
/** Efectivo real: abono suma; devoluciones y reembolsos restan. */
function titoCashRegisterExternal(PDO $db,int $businessId,int $actorId,?int $sessionId,string $kind,int $sourceId,int $amount): void {
    if($sessionId===null)return; // Modo compatible: nunca atribuir a un turno inexistente.
    if(!$db->inTransaction())throw new RuntimeException('Movimiento monetario sin transacción.');
    if($sourceId<1||$amount<=0||$amount>100000000000)fail('Movimiento de caja inválido.',422);
    $sources=[
      'credit_payment'=>['source_credit_payment_id',1,'Abono de fiado #'],
      'customer_refund'=>['source_customer_return_id',-1,'Devolución a cliente #'],
      'credit_refund'=>['source_credit_refund_id',-1,'Devolución de crédito #'],
      'quote_advance'=>['source_quote_advance_id',1,'Anticipo cotización #'],
      'quote_advance_refund'=>['source_quote_advance_id',-1,'Reembolso anticipo #']
    ];
    if(!isset($sources[$kind]))throw new RuntimeException('Fuente de movimiento desconocida.');
    [$source,$sign,$label]=$sources[$kind];
    if($sign<0){
        // Bloquear la sesión para impedir cierre concurrente y comprobar liquidez.
        $balance=titoCashBalance($db,$businessId,$sessionId);
        if((int)$balance['current_expected_clp']<$amount)
            fail('La gaveta no tiene efectivo esperado suficiente para este reembolso; ingresa fondo documentado antes de pagar.',409);
    }
    $sql="INSERT INTO cash_movements
       (business_id,cash_session_id,request_id,movement_type,amount_delta_clp,$source,actor_id,reason)
       VALUES(?,?,?,?,?,?,?,?)";
    $st=$db->prepare($sql);
    $st->execute([$businessId,$sessionId,titoCashSourceRequestId($kind,$sourceId),
       $kind,$amount*$sign,$sourceId,$actorId,$label.$sourceId]);
}
function titoCashBalance(PDO $db,int $businessId,int $sessionId): array {
    $st=$db->prepare("SELECT cs.id,cs.business_id,cs.warehouse_id,cs.drawer_id,cs.cashier_id,cs.status,
      cs.opening_clp,cs.opened_at,cs.closed_at,cs.closing_counted_clp,cs.closing_expected_clp,
      cs.closing_difference_clp,cs.closing_reason,cd.code AS drawer_code,cd.name AS drawer_name,
      w.name AS warehouse_name,u.email AS cashier_email
      FROM cash_sessions cs JOIN cash_drawers cd ON cd.id=cs.drawer_id AND cd.business_id=cs.business_id
      JOIN warehouses w ON w.id=cs.warehouse_id AND w.business_id=cs.business_id
      JOIN users u ON u.id=cs.cashier_id AND u.business_id=cs.business_id
      WHERE cs.business_id=? AND cs.id=?");
    $st->execute([$businessId,$sessionId]);$session=$st->fetch();$st->closeCursor();
    if(!$session)fail('Sesión de caja no encontrada.',404);
    $st=$db->prepare("SELECT movement_type,COALESCE(SUM(amount_delta_clp),0) AS delta
      FROM cash_movements WHERE business_id=? AND cash_session_id=? GROUP BY movement_type");
    $st->execute([$businessId,$sessionId]);$movements=$st->fetchAll();$st->closeCursor();
    $advanceCash=0;$advanceRefund=0;$cash=0;$creditReceived=0;$customerRefunds=0;$creditRefunds=0;$withdrawals=0;$additions=0;
    foreach($movements as $movement){
       $v=(int)$movement['delta'];
       if($movement['movement_type']==='cash_sale')$cash+=$v;
       elseif($movement['movement_type']==='credit_payment')$creditReceived+=$v;
       elseif($movement['movement_type']==='customer_refund')$customerRefunds-= $v;
       elseif($movement['movement_type']==='credit_refund')$creditRefunds-= $v;
       elseif($movement['movement_type']==='quote_advance')$advanceCash+=$v;
       elseif($movement['movement_type']==='quote_advance_refund')$advanceRefund-=$v;
       elseif($movement['movement_type']==='withdrawal')$withdrawals-= $v;
       elseif($movement['movement_type']==='addition')$additions+=$v;
    }
    $st=$db->prepare("SELECT s.payment_method,COUNT(*) AS n,COALESCE(SUM(s.total_clp),0) AS total
      FROM sales s WHERE s.business_id=? AND s.cash_session_id=? GROUP BY s.payment_method");
    $st->execute([$businessId,$sessionId]);$methodTotals=$st->fetchAll();$st->closeCursor();
    $expected=(int)$session['opening_clp']+$cash+$creditReceived+$advanceCash+$additions-$withdrawals-$customerRefunds-$creditRefunds-$advanceRefund;
    $session['quote_advance_cash_clp']=$advanceCash;$session['quote_advance_refund_cash_clp']=$advanceRefund;
    $session['cash_sales_clp']=$cash;
    $session['credit_received_cash_clp']=$creditReceived;
    $session['customer_refunds_cash_clp']=$customerRefunds;
    $session['credit_refunds_cash_clp']=$creditRefunds;
    $session['withdrawals_clp']=$withdrawals;
    $session['additions_clp']=$additions;
    $session['current_expected_clp']=$expected;
    $session['sales_by_method']=$methodTotals;
    $st=$db->prepare("SELECT method,COUNT(*) AS n,COALESCE(SUM(amount_clp),0) AS total
      FROM credit_payments WHERE business_id=? AND cash_session_id=? GROUP BY method");
    $st->execute([$businessId,$sessionId]);$session['credit_payments_by_method']=$st->fetchAll();$st->closeCursor();
    $st=$db->prepare("SELECT refund_method AS method,COUNT(*) AS n,COALESCE(SUM(amount_clp),0) AS total
      FROM customer_returns WHERE business_id=? AND refund_cash_session_id=? AND refund_status='confirmed' GROUP BY refund_method");
    $st->execute([$businessId,$sessionId]);$session['customer_refunds_by_method']=$st->fetchAll();$st->closeCursor();
    $st=$db->prepare("SELECT method,COUNT(*) AS n,COALESCE(SUM(amount_clp),0) AS total
      FROM credit_refunds WHERE business_id=? AND cash_session_id=? GROUP BY method");
    $st->execute([$businessId,$sessionId]);$session['credit_refunds_by_method']=$st->fetchAll();$st->closeCursor();
    return $session;
}
function registerCashRoutes(RouteCollectorProxy $group): void {
    $group->get('/cash/current',static function(Request $request,Response $response): Response {
        $u=$request->getAttribute('currentUser');$db=db();$biz=(int)$u['business_id'];
        if(!titoCashReady($db))fail('Gestión de caja no instalada.',503);
        $warehouseId=titoSaleLocation($db,$u,null,false,$request);
        $st=$db->prepare("SELECT id FROM cash_sessions WHERE business_id=? AND cashier_id=? AND status='open'");
        $st->execute([$biz,(int)$u['id']]);$sid=$st->fetchColumn();$st->closeCursor();
        $st=$db->prepare('SELECT id,code,name FROM cash_drawers WHERE business_id=? AND warehouse_id=? AND assigned_cashier_id=? AND is_active=1 ORDER BY name');
        $st->execute([$biz,$warehouseId,(int)$u['id']]);$drawers=$st->fetchAll();$st->closeCursor();
        return respond($response,[
          'warehouse_id'=>$warehouseId,'required'=>titoCashRequired($db,$biz),
          'drawers'=>$drawers,'session'=>$sid===false?null:titoCashBalance($db,$biz,(int)$sid)
        ]);
    });
    $group->get('/cash/history',static function(Request $request,Response $response): Response {
        $u=$request->getAttribute('currentUser');$biz=(int)$u['business_id'];
        $sql="SELECT cs.id,cs.opened_at,cs.closed_at,cs.status,cs.opening_clp,
          cs.closing_expected_clp,cs.closing_counted_clp,cs.closing_difference_clp,
          cd.name AS drawer_name,w.name AS warehouse_name,us.email AS cashier_email
          FROM cash_sessions cs JOIN cash_drawers cd ON cd.id=cs.drawer_id
          JOIN warehouses w ON w.id=cs.warehouse_id JOIN users us ON us.id=cs.cashier_id
          WHERE cs.business_id=?";
        $args=[$biz];
        if(($u['role']??'')!=='admin'){$sql.=' AND cs.cashier_id=?';$args[]=(int)$u['id'];}
        $st=db()->prepare($sql.' ORDER BY cs.id DESC LIMIT 100');$st->execute($args);
        return respond($response,['sessions'=>$st->fetchAll()]);
    });
    $group->get('/cash/sessions/{id:[0-9]+}',static function(Request $request,Response $response,array $args): Response {
        $u=$request->getAttribute('currentUser');$biz=(int)$u['business_id'];$sid=(int)$args['id'];$db=db();
        $session=titoCashBalance($db,$biz,$sid);
        if(($u['role']??'')!=='admin'&&(int)$session['cashier_id']!==(int)$u['id'])fail('No puedes consultar la caja de otro cajero.',403);
        $st=$db->prepare("SELECT id,movement_type,amount_delta_clp,source_sale_id,source_credit_payment_id,source_customer_return_id,source_credit_refund_id,source_quote_advance_id,actor_id,reason,reference,created_at
          FROM cash_movements WHERE business_id=? AND cash_session_id=? ORDER BY id DESC LIMIT 200");
        $st->execute([$biz,$sid]);$entries=$st->fetchAll();$st->closeCursor();
        return respond($response,['session'=>$session,'movements'=>$entries]);
    });
    $group->get('/cash/drawers',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);
        $st=db()->prepare("SELECT cd.id,cd.warehouse_id,cd.code,cd.name,cd.is_active,cd.assigned_cashier_id,
            w.name AS warehouse_name,u.email AS assigned_email,
            (SELECT COUNT(*) FROM cash_sessions cs WHERE cs.drawer_id=cd.id AND cs.business_id=cd.business_id AND cs.status='open') AS active_sessions
            FROM cash_drawers cd JOIN warehouses w ON w.id=cd.warehouse_id AND w.business_id=cd.business_id
            LEFT JOIN users u ON u.id=cd.assigned_cashier_id AND u.business_id=cd.business_id
            WHERE cd.business_id=? ORDER BY cd.id DESC LIMIT 100");
        $st->execute([(int)$admin['business_id']]);
        return respond($response,['drawers'=>$st->fetchAll()]);
    });
    $group->post('/cash/drawers',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$b=requestBody($request);
        if(array_diff(array_keys($b),['warehouse_id','code','name','cashier_id']))fail('Campos de gaveta inesperados.',422);
        $warehouseId=$b['warehouse_id']??null;
        if(!is_int($warehouseId)||$warehouseId<1)fail('Sucursal inválida.',422);
        $code=requireString($b,'code',32);$name=requireString($b,'name',100);$cashierId=$b['cashier_id']??null;
        if(!is_int($cashierId)||$cashierId<1)fail('Selecciona un cajero.',422);
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/D',$code))fail('Código de gaveta inválido.',422);
        $db=db();$biz=(int)$admin['business_id'];
        $st=$db->prepare('SELECT id FROM warehouses WHERE id=? AND business_id=? AND is_sales_location=1 AND is_active=1');
        $st->execute([$warehouseId,$biz]);if($st->fetchColumn()===false)fail('La gaveta debe pertenecer a un local de venta activo.',422);
        $st=$db->prepare("SELECT id FROM users WHERE id=? AND business_id=? AND role IN ('admin','cashier') AND is_active=1");
        $st->execute([$cashierId,$biz]);if($st->fetchColumn()===false)fail('Cajero inexistente o inactivo en esta empresa.',422);
        try{
            $st=$db->prepare('INSERT INTO cash_drawers(business_id,warehouse_id,code,name,assigned_cashier_id,created_by) VALUES(?,?,?,?,?,?)');
            $st->execute([$biz,$warehouseId,strtoupper($code),$name,$cashierId,(int)$admin['id']]);
        }catch(PDOException $e){if((int)($e->errorInfo[1]??0)===1062)fail('Ya existe ese código de gaveta en el local.',409);throw $e;}
        return respond($response,['ok'=>true,'drawer_id'=>(int)$db->lastInsertId()],201);
    });
    $group->post('/cash/drawers/{id:[0-9]+}/assign',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$drawerId=(int)$args['id'];
        $b=requestBody($request);
        if(array_keys($b)!==['cashier_id']||!is_int($b['cashier_id'])||$b['cashier_id']<1)fail('Cajero inválido.',422);
        $db=db();
        try{
            $db->beginTransaction();
            $st=$db->prepare('SELECT id FROM businesses WHERE id=? FOR UPDATE');
            $st->execute([$biz]);if($st->fetchColumn()===false)fail('Empresa inexistente.',404);
            $st=$db->prepare('SELECT id FROM cash_drawers WHERE id=? AND business_id=? FOR UPDATE');
            $st->execute([$drawerId,$biz]);if($st->fetchColumn()===false)fail('Gaveta no encontrada.',404);
            $st=$db->prepare("SELECT id FROM cash_sessions WHERE business_id=? AND drawer_id=? AND status='open' FOR UPDATE");
            $st->execute([$biz,$drawerId]);if($st->fetchColumn()!==false)fail('Esta gaveta está en uso. Cierra el turno antes de reasignar.',409);
            $st=$db->prepare("SELECT id FROM users WHERE id=? AND business_id=? AND role IN ('admin','cashier') AND is_active=1");
            $st->execute([$b['cashier_id'],$biz]);if($st->fetchColumn()===false)fail('Cajero inexistente o inactivo.',422);
            $st=$db->prepare('UPDATE cash_drawers SET assigned_cashier_id=? WHERE id=? AND business_id=?');
            $st->execute([$b['cashier_id'],$drawerId,$biz]);
            $db->commit();return respond($response,['ok'=>true,'drawer_id'=>$drawerId,'cashier_id'=>$b['cashier_id']]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/cash/sessions/open',static function(Request $request,Response $response): Response {
        $u=$request->getAttribute('currentUser');$biz=(int)$u['business_id'];$uid=(int)$u['id'];
        $b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','drawer_id','opening_clp']))fail('Campos de apertura inesperados.',422);
        $rid=titoCashUuid($b['request_id']??null);$drawerId=$b['drawer_id']??null;
        if(!is_int($drawerId)||$drawerId<1)fail('Gaveta inválida.',422);
        $opening=titoCashInt($b['opening_clp']??null,'Fondo inicial');
        $db=db();$warehouseId=titoSaleLocation($db,$u,null,false,$request);
        try{
            $db->beginTransaction();
            // Bloquear el negocio serializa apertura concurrente antes de evaluar
            // la unicidad de cajero y gaveta; los índices únicos son la segunda barrera.
            $st=$db->prepare('SELECT id FROM businesses WHERE id=? FOR UPDATE');
            $st->execute([$biz]);if($st->fetchColumn()===false)fail('Empresa no encontrada.',404);
            $st=$db->prepare('SELECT id,warehouse_id,is_active,assigned_cashier_id FROM cash_drawers WHERE id=? AND business_id=? FOR UPDATE');
            $st->execute([$drawerId,$biz]);$drawer=$st->fetch();$st->closeCursor();
            if(!$drawer||(int)$drawer['is_active']!==1||(int)$drawer['warehouse_id']!==$warehouseId||(int)$drawer['assigned_cashier_id']!==$uid)
                fail('La gaveta no pertenece a la sucursal activa de esta caja.',409);
            $st=$db->prepare('SELECT id,drawer_id,opening_clp,cashier_id FROM cash_sessions WHERE business_id=? AND open_request_id=?');
            $st->execute([$biz,$rid]);$prior=$st->fetch();$st->closeCursor();
            if($prior){
                if((int)$prior['drawer_id']!==$drawerId||(int)$prior['opening_clp']!==$opening||(int)$prior['cashier_id']!==$uid)
                    fail('Identificador de apertura usado con otros datos.',409);
                $db->commit();
                return respond($response,['ok'=>true,'session_id'=>(int)$prior['id'],'already_recorded'=>true]);
            }
            $st=$db->prepare("INSERT INTO cash_sessions(business_id,warehouse_id,drawer_id,cashier_id,opened_by,open_request_id,opening_clp)
              VALUES(?,?,?,?,?,?,?)");
            $st->execute([$biz,$warehouseId,$drawerId,$uid,$uid,$rid,$opening]);
            $sid=(int)$db->lastInsertId();$db->commit();
            return respond($response,['ok'=>true,'session_id'=>$sid,'warehouse_id'=>$warehouseId],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062)
                fail('La gaveta ya está abierta o el cajero tiene otro turno activo.',409);
            throw $e;
        }
    });
    $group->post('/cash/sessions/{id:[0-9]+}/withdraw',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$sid=(int)$args['id'];
        $b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','amount_clp','receiver','reason']))fail('Campos de recaudación no permitidos.',422);
        $rid=titoCashUuid($b['request_id']??null);
        $amount=titoCashInt($b['amount_clp']??null,'Recaudación');
        if($amount===0)fail('El retiro debe ser mayor a cero.',422);
        $receiver=requireString($b,'receiver',120);
        $reason=requireString($b,'reason',250);
        $db=db();
        try{
          $db->beginTransaction();
          $st=$db->prepare('SELECT id,status FROM cash_sessions WHERE business_id=? AND id=? FOR UPDATE');
          $st->execute([$biz,$sid]);$session=$st->fetch();$st->closeCursor();
          if(!$session||$session['status']!=='open')fail('La gaveta no está abierta.',409);
          $st=$db->prepare('SELECT id,cash_session_id,amount_delta_clp,reference,reason FROM cash_movements WHERE business_id=? AND request_id=?');
          $st->execute([$biz,$rid]);$prior=$st->fetch();$st->closeCursor();
          if($prior){
             if((int)$prior['cash_session_id']!==$sid||(int)$prior['amount_delta_clp']!==-$amount||
                (string)$prior['reference']!==$receiver||(string)$prior['reason']!==$reason)
                fail('Identificador de recaudación reutilizado con datos diferentes.',409);
             $db->commit();
             return respond($response,['ok'=>true,'movement_id'=>(int)$prior['id'],'already_recorded'=>true]);
          }
          $balance=titoCashBalance($db,$biz,$sid);
          if($amount>(int)$balance['current_expected_clp'])fail('El retiro excede el efectivo esperado en la gaveta.',409);
          $st=$db->prepare("INSERT INTO cash_movements(business_id,cash_session_id,request_id,movement_type,amount_delta_clp,actor_id,reference,reason) VALUES(?,?,?,'withdrawal',?,?,?,?)");
          $st->execute([$biz,$sid,$rid,-$amount,(int)$admin['id'],$receiver,$reason]);
          $id=(int)$db->lastInsertId();
          $db->commit();
          return respond($response,['ok'=>true,'movement_id'=>$id,'cash_expected_clp'=>(int)$balance['current_expected_clp']-$amount],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/cash/sessions/{id:[0-9]+}/close',static function(Request $request,Response $response,array $args): Response {
        $u=$request->getAttribute('currentUser');$biz=(int)$u['business_id'];$uid=(int)$u['id'];$sid=(int)$args['id'];
        $b=requestBody($request);
        if(array_diff(array_keys($b),['counted_clp','reason']))fail('Campos de cierre no permitidos.',422);
        $count=titoCashInt($b['counted_clp']??null,'Efectivo contado');
        $reason=$b['reason']??null;
        if($reason!==null&&(!is_string($reason)||mb_strlen(trim($reason))>250))fail('Justificación inválida.',422);
        $reason=is_string($reason)?trim($reason):'';
        $db=db();
        try{
          $db->beginTransaction();
          $st=$db->prepare('SELECT id,status,cashier_id FROM cash_sessions WHERE business_id=? AND id=? FOR UPDATE');
          $st->execute([$biz,$sid]);$locked=$st->fetch();$st->closeCursor();
          if(!$locked||$locked['status']!=='open')fail('Este turno ya está cerrado o no existe.',409);
          if(($u['role']??'')!=='admin'&&(int)$locked['cashier_id']!==$uid)fail('No puedes cerrar el turno de otro cajero.',403);
          $session=titoCashBalance($db,$biz,$sid);
          $expected=(int)$session['current_expected_clp'];
          $difference=$count-$expected;
          if($difference!==0&&mb_strlen($reason)<5)fail('Explica el faltante o sobrante antes de cerrar.',422);
          $st=$db->prepare("UPDATE cash_sessions SET status='closed',closed_at=UTC_TIMESTAMP(),
            closing_counted_clp=?,closing_expected_clp=?,closing_difference_clp=?,closing_reason=?,closed_by=?
            WHERE id=? AND business_id=? AND status='open'");
          $st->execute([$count,$expected,$difference,$reason?:null,$uid,$sid,$biz]);
          if($st->rowCount()!==1)throw new RuntimeException('No fue posible finalizar el cierre.');
          $db->commit();
          return respond($response,['ok'=>true,'session_id'=>$sid,'expected_clp'=>$expected,'counted_clp'=>$count,'difference_clp'=>$difference]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/cash/policy',static function(Request $request,Response $response): Response {
       $admin=adminOnly($request);$b=requestBody($request);
       if(array_keys($b)!==['required']||!is_bool($b['required']))fail('Política inválida.',422);
       $db=db();$biz=(int)$admin['business_id'];
       try{
         $db->beginTransaction();
         $st=$db->prepare('SELECT id FROM businesses WHERE id=? FOR UPDATE');
         $st->execute([$biz]);if($st->fetchColumn()===false)fail('Empresa inexistente.',404);
         if($b['required']){
           $st=$db->prepare("SELECT COUNT(*) FROM users u WHERE u.business_id=? AND u.is_active=1
             AND u.role IN ('admin','cashier') AND NOT EXISTS (
                SELECT 1 FROM cash_drawers d
                WHERE d.business_id=u.business_id AND d.assigned_cashier_id=u.id AND d.is_active=1
             )");
           $st->execute([$biz]);if((int)$st->fetchColumn()!==0)
             fail('Asigna una gaveta activa a cada usuario antes de exigir turnos de caja.',409);
           $st=$db->prepare("SELECT COUNT(*) FROM cash_sessions WHERE business_id=? AND status='open'");
           $st->execute([$biz]);if((int)$st->fetchColumn()===0)fail('Abre y prueba al menos una caja antes de hacer obligatorio el turno.',409);
         }
         $st=$db->prepare('INSERT INTO cash_policy(business_id,require_open_session,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE require_open_session=VALUES(require_open_session),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()');
         $st->execute([$biz,$b['required']?1:0,(int)$admin['id']]);
         $db->commit();
         return respond($response,['ok'=>true,'required'=>$b['required']]);
       }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
