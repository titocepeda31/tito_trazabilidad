<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Inventario por ubicación. products.stock_qty mantiene el total de empresa.
 * Las mutaciones existentes se reflejan en el LOCAL mediante triggers SQL.
 * Las transferencias nunca alteran el total y dejan trazabilidad propia.
 */
function titoCheckStoreStock(PDO $db,int $biz,int $pid,float $needed,string $name): void {
    $st=$db->prepare("SELECT ws.qty FROM warehouse_stocks ws JOIN warehouses w ON w.id=ws.warehouse_id WHERE w.business_id=? AND w.code='local' AND ws.product_id=?");
    $st->execute([$biz,$pid]);$local=$st->fetchColumn();$st->closeCursor();
    if($local===false)fail('No existe balance en local para '.$name,409);
    if((float)$local+0.00001<$needed)fail('Stock insuficiente en el local de venta para '.$name.'. Traslada desde bodega antes de vender.',409);
}

/** Resuelve la sucursal efectiva desde la cuenta activa, nunca desde el navegador por sí solo.
 * Los cajeros existentes sin asignación se mantienen en el local original.
 */

/**
 * Registro de dispositivo navegador / turno móvil.
 * Token aleatorio de 256 bits; MySQL solo guarda hash, nunca el secreto.
 * No es una prueba criptográfica de ubicación física: requiere activación admin.
 */
function titoTerminalSchemaReady(PDO $db): bool {
    static $ready=null;
    if($ready!==null)return $ready;
    $s=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('pos_terminal_policy','pos_terminal_pairings','pos_terminals')");
    return $ready=((int)$s->fetchColumn()===3);
}
function titoTerminalEnforced(PDO $db,int $biz): bool {
    if(!titoTerminalSchemaReady($db))return false;
    $s=$db->prepare('SELECT enforce_registered_terminal FROM pos_terminal_policy WHERE business_id=?');
    $s->execute([$biz]);$v=$s->fetchColumn();$s->closeCursor();
    return $v!==false&&(int)$v===1;
}
function titoTerminalFromRequest(PDO $db,array $user,?Request $request): ?array {
    if(!titoTerminalSchemaReady($db)||!$request)return null;
    // En los tests históricos el doble de PSR Request no expone cabeceras.
    // Esto se trata como caja NO registrada, nunca como autorización.
    if(!method_exists($request,'getHeaderLine'))return null;
    $header=trim($request->getHeaderLine('X-Tito-Terminal'));
    if($header==='')return null;
    if(!preg_match('/^[a-f0-9]{64}$/D',$header))fail('La caja registrada tiene una credencial inválida.',403);
    $s=$db->prepare("SELECT t.id,t.warehouse_id,t.kind,t.label,t.expires_at,w.name AS warehouse_name
      FROM pos_terminals t JOIN warehouses w ON w.id=t.warehouse_id AND w.business_id=t.business_id
      WHERE t.business_id=? AND t.token_hash=? AND t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP()
      AND w.is_sales_location=1 AND w.is_active=1 LIMIT 1 FOR UPDATE");
    $s->execute([(int)$user['business_id'],hash('sha256',$header)]);
    $terminal=$s->fetch();$s->closeCursor();
    if(!$terminal)fail('Esta caja no está autorizada, fue revocada o su turno venció. Vuelve a activarla.',403);
    return $terminal;
}
/** En modo estricto no se permite vender sin token registrado, tampoco al administrador. */
function titoResolveTerminal(PDO $db,array $user,?Request $request): ?array {
    $terminal=titoTerminalFromRequest($db,$user,$request);
    if(!$terminal&&titoTerminalEnforced($db,(int)$user['business_id']))
        fail('Debes activar esta caja desde Más → Identificación de caja antes de registrar ventas.',403);
    return $terminal;
}

function titoSaleLocation(PDO $db,array $user,mixed $requested=null,bool $requireExplicitForNewBranch=false,?Request $request=null): int {
    $biz=(int)($user['business_id']??0);$uid=(int)($user['id']??0);
    if($biz<1||$uid<1)fail('Sesión no válida.',401);
    $st=$db->prepare('SELECT role,sales_warehouse_id FROM users WHERE id=? AND business_id=? AND is_active=1');
    $st->execute([$uid,$biz]);$actor=$st->fetch();$st->closeCursor();
    if(!$actor)fail('Usuario inactivo o ajeno a la empresa.',403);
    $role=(string)$actor['role'];
    if(!in_array($role,['admin','cashier'],true))fail('Rol no autorizado.',403);
    $local=titoLocalLocationId($db,$biz);
    $assigned=$actor['sales_warehouse_id']!==null?(int)$actor['sales_warehouse_id']:$local;
    $terminal=titoResolveTerminal($db,$user,$request);
    if($terminal){
        $deviceLocation=(int)$terminal['warehouse_id'];
        if($requested!==null && (!is_int($requested)||$requested!==$deviceLocation))
            fail('La sucursal solicitada no coincide con la caja registrada.',403);
        return $deviceLocation;
    }
    if($requireExplicitForNewBranch && $role==='cashier' && $assigned!==$local && $requested===null)
        fail('Actualiza Tito POS para vender desde tu sucursal asignada.',409);
    if($requested!==null){
        if(!is_int($requested)||$requested<1)fail('Sucursal de venta inválida.',422);
        if($role!=='admin'&&$requested!==$assigned)fail('No puedes vender en otra sucursal.',403);
        $selected=$requested;
    }else{
        $selected=$assigned;
    }
    $st=$db->prepare('SELECT id FROM warehouses WHERE id=? AND business_id=? AND is_sales_location=1 AND is_active=1');
    $st->execute([$selected,$biz]);$valid=$st->fetchColumn();$st->closeCursor();
    if($valid===false)fail('Sucursal de venta inactiva o no autorizada.',409);
    return $selected;
}
function titoLocalLocationId(PDO $db,int $biz): int {
    $st=$db->prepare("SELECT id FROM warehouses WHERE business_id=? AND code='local' LIMIT 1");
    $st->execute([$biz]);$id=$st->fetchColumn();$st->closeCursor();
    if($id===false)fail('Falta la ubicación de venta original.',409);
    return (int)$id;
}
/** Identificador histórico de una venta: importante para devoluciones y reversas. */
function titoSaleHistoricalLocation(PDO $db,int $biz,int $saleId): int {
    $st=$db->prepare('SELECT warehouse_id FROM sales WHERE id=? AND business_id=?');
    $st->execute([$saleId,$biz]);$wid=$st->fetchColumn();$st->closeCursor();
    if($wid===false)fail('Venta no encontrada.',404);
    return $wid===null?titoLocalLocationId($db,$biz):(int)$wid;
}
/** Cambia el stock global y de una ubicación de forma atómica.
 * Mantiene el trigger existente de products, que siempre modifica el local original:
 * - salida desde otra sucursal: mueve provisionalmente unidades a local, luego trigger resta de local;
 * - entrada a otra sucursal: trigger suma al local, luego mueve esas unidades a destino.
 * Debe invocarse DENTRO de la transacción del movimiento; no crea un traslado comercial.
 */
function titoApplyLocationStock(PDO $db,int $biz,int $pid,int $warehouseId,float $delta): string {
    return titoApplyLocationStockCore($db,$biz,$pid,$warehouseId,$delta,'legacy');
}
/** Solo para recepción: quien llama debe insertar las unidades y conciliar en la misma transacción. */
function titoReceiveLocationStock(PDO $db,int $biz,int $pid,int $warehouseId,float $quantity,bool $serialReceipt): string {
    if($quantity<=0)fail('Cantidad recibida inválida.',422);
    return titoApplyLocationStockCore($db,$biz,$pid,$warehouseId,$quantity,$serialReceipt?'receipt':'legacy');
}
function titoSellSerialLocationStock(PDO $db,int $biz,int $pid,int $warehouseId,float $quantity): string {
    if($quantity<=0)fail('Cantidad vendida inválida.',422);
    return titoApplyLocationStockCore($db,$biz,$pid,$warehouseId,-$quantity,'sale');
}
function titoApplyLocationStockCore(PDO $db,int $biz,int $pid,int $warehouseId,float $delta,string $mode): string {
    if(!$db->inTransaction())throw new RuntimeException('Se requiere transacción de inventario.');
    if(!is_finite($delta)||abs($delta)<0.0005||abs($delta)>1000000000
        ||abs($delta*1000-round($delta*1000))>0.00001)fail('Variación de stock inválida.',422);
    $p=$db->prepare('SELECT stock_qty FROM products WHERE id=? AND business_id=? FOR UPDATE');
    $p->execute([$pid,$biz]);$global=$p->fetchColumn();$p->closeCursor();
    if($global===false)fail('Producto ajeno a la empresa o inexistente.',404);
    if($mode==='receipt'||$mode==='sale'){
        if(($mode==='receipt'&&$delta<=0)||($mode==='sale'&&$delta>=0)||!serialControlled($db,$biz,$pid))fail('Movimiento serializado inválido.',409);
    }elseif($mode==='legacy')serialRejectLegacy($db,$biz,$pid);else throw new RuntimeException('Modo inválido.');
    $st=$db->prepare('SELECT ws.warehouse_id,ws.qty,w.code,w.business_id FROM warehouse_stocks ws JOIN warehouses w ON w.id=ws.warehouse_id AND w.business_id=ws.business_id WHERE ws.product_id=? AND ws.business_id=? ORDER BY ws.warehouse_id FOR UPDATE');
    $st->execute([$pid,$biz]);$rows=$st->fetchAll();$st->closeCursor();
    $balances=[];$localId=null;$sum=0.0;
    foreach($rows as $row){
        $wid=(int)$row['warehouse_id'];$n=(float)$row['qty'];
        $balances[$wid]=$n;$sum+=$n;
        if($row['code']==='local')$localId=$wid;
    }
    if($localId===null||!array_key_exists($warehouseId,$balances))fail('Inventario de sucursal no configurado.',409);
    if(abs($sum-(float)$global)>0.0005)fail('Inventario sin conciliar. Operación detenida.',409);
    $result=(float)$global+$delta;
    $resultLocation=$balances[$warehouseId]+$delta;
    if($result< -0.000001||$result>1000000000||$resultLocation< -0.000001||$resultLocation>1000000000)
        fail('Stock insuficiente o fuera de rango en la sucursal.',409);
    $upd=$db->prepare('UPDATE warehouse_stocks SET qty=? WHERE business_id=? AND product_id=? AND warehouse_id=?');
    $change=$db->prepare('UPDATE products SET stock_qty=? WHERE id=? AND business_id=?');
    if($warehouseId!==$localId && $delta<0){
        $n=abs($delta);
        $upd->execute([number_format(max(0,$balances[$warehouseId]-$n),3,'.',''),$biz,$pid,$warehouseId]);
        $upd->execute([number_format($balances[$localId]+$n,3,'.',''),$biz,$pid,$localId]);
    }
    $after=number_format(max(0,$result),3,'.','');
    $change->execute([$after,$pid,$biz]);
    if($warehouseId!==$localId && $delta>0){
        $upd->execute([number_format($balances[$localId],3,'.',''),$biz,$pid,$localId]);
        $upd->execute([number_format($resultLocation,3,'.',''),$biz,$pid,$warehouseId]);
    }
    $st=$db->prepare('SELECT ws.warehouse_id,ws.qty FROM warehouse_stocks ws WHERE ws.product_id=? AND ws.business_id=?');
    $st->execute([$pid,$biz]);$newRows=$st->fetchAll();$st->closeCursor();
    $sumAfter=0.0;$actual=[];
    foreach($newRows as $row){$actual[(int)$row['warehouse_id']]=(float)$row['qty'];$sumAfter+=(float)$row['qty'];}
    if(abs($sumAfter-$result)>0.0005||abs(($actual[$warehouseId]??-1)-$resultLocation)>0.0005
       ||abs(($actual[$localId]??-1)-($balances[$localId]+($warehouseId===$localId?$delta:0)))>0.0005)
        throw new RuntimeException('Fallo de conciliación del stock por sucursal.');
    if(array_diff_key($balances,$actual)||array_diff_key($actual,$balances))throw new RuntimeException('Cambió el conjunto de ubicaciones durante la operación.');
    foreach($balances as $wid=>$before){
        if(abs($actual[$wid]-($before+($wid===$warehouseId?$delta:0)))>0.0005)throw new RuntimeException('Fallo de conciliación de una ubicación.');
    }
    return $after;
}


function registerTerminalRoutes(RouteCollectorProxy $group): void {
    // Consultar estado no cambia inventario y funciona también con política estricta.
    $group->get('/account/terminal-status',static function(Request $request,Response $response): Response {
        $user=$request->getAttribute('currentUser');$db=db();
        $ready=titoTerminalSchemaReady($db);$enforced=titoTerminalEnforced($db,(int)$user['business_id']);
        // Si el terminal guardado fue revocado, la API lo comunica claramente.
        $terminal=null;$invalid=false;
        try{$terminal=titoTerminalFromRequest($db,$user,$request);}
        catch(HttpError $e){if($e->status!==403)throw $e;$invalid=true;}
        return respond($response,['feature_ready'=>$ready,'enforced'=>$enforced,'terminal'=>$terminal?:null,'invalid_terminal'=>$invalid]);
    });
    $group->get('/terminals',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$db=db();
        $st=$db->prepare("SELECT t.id,t.kind,t.label,t.activated_at,t.expires_at,t.revoked_at,
            w.name AS warehouse_name,t.warehouse_id,
            (t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND w.is_active=1) AS is_active
            FROM pos_terminals t JOIN warehouses w ON w.id=t.warehouse_id AND w.business_id=t.business_id
            WHERE t.business_id=? ORDER BY t.id DESC LIMIT 100");
        $st->execute([(int)$admin['business_id']]);
        return respond($response,['terminals'=>$st->fetchAll(),'enforced'=>titoTerminalEnforced($db,(int)$admin['business_id'])]);
    });
    $group->post('/terminals/pair',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['warehouse_id','kind','label']))fail('Campos inesperados.',422);
        $wid=$b['warehouse_id']??null;$kind=$b['kind']??null;
        if(!is_int($wid)||$wid<1||!in_array($kind,['fixed','shift'],true))fail('Sucursal o tipo de caja inválido.',422);
        $label=requireString($b,'label',80);
        $db=db();
        try{
            $db->beginTransaction();
            $st=$db->prepare('SELECT id FROM businesses WHERE id=? FOR UPDATE');$st->execute([$biz]);
            if($st->fetchColumn()===false)fail('Empresa no encontrada.',404);
            $st=$db->prepare('SELECT id FROM warehouses WHERE business_id=? AND id=? AND is_sales_location=1 AND is_active=1');
            $st->execute([$biz,$wid]);if($st->fetchColumn()===false)fail('La sucursal no existe o está inactiva.',422);
            $st=$db->prepare("SELECT COUNT(*) FROM pos_terminal_pairings WHERE business_id=? AND redeemed_at IS NULL AND expires_at>UTC_TIMESTAMP()");
            $st->execute([$biz]);if((int)$st->fetchColumn()>=5)fail('Hay demasiadas activaciones pendientes. Utiliza o deja vencer las existentes.',409);
            $code=bin2hex(random_bytes(16));$hash=hash('sha256',$code);
            $st=$db->prepare("INSERT INTO pos_terminal_pairings(business_id,warehouse_id,created_by,kind,label,code_hash,expires_at) VALUES(?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))");
            $st->execute([$biz,$wid,(int)$admin['id'],$kind,$label,$hash]);
            $db->commit();
            return respond($response,['ok'=>true,'code'=>$code,'expires_in_seconds'=>300,'warehouse_id'=>$wid,'kind'=>$kind],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/account/terminal-activate',static function(Request $request,Response $response): Response {
        $user=$request->getAttribute('currentUser');$biz=(int)$user['business_id'];
        $b=requestBody($request);
        if(array_keys($b)!==['code']||!is_string($b['code'])||!preg_match('/^[a-f0-9]{32}$/D',$b['code']))
            fail('Código de activación inválido.',422);
        $db=db();
        try{
            $db->beginTransaction();
            $hash=hash('sha256',$b['code']);
            $st=$db->prepare('SELECT id,warehouse_id,kind,label,expires_at,redeemed_at FROM pos_terminal_pairings WHERE business_id=? AND code_hash=? FOR UPDATE');
            $st->execute([$biz,$hash]);$pair=$st->fetch();$st->closeCursor();
            if(!$pair||$pair['redeemed_at']!==null||strtotime($pair['expires_at'].' UTC')<=time())
                fail('El código no existe, ya fue utilizado o venció.',403);
            $st=$db->prepare('SELECT id,name FROM warehouses WHERE business_id=? AND id=? AND is_sales_location=1 AND is_active=1');
            $st->execute([$biz,(int)$pair['warehouse_id']]);$location=$st->fetch();$st->closeCursor();
            if(!$location)fail('Sucursal de activación no disponible.',409);
            // Si este mismo navegador estaba vinculado a otra sucursal,
            // revocar su token anterior al activar el nuevo; no deja dos
            // sucursales vigentes desde el mismo almacenamiento local.
            $previous=trim($request->getHeaderLine('X-Tito-Terminal'));
            if(preg_match('/^[a-f0-9]{64}$/D',$previous)){
                $st=$db->prepare('UPDATE pos_terminals SET revoked_at=UTC_TIMESTAMP(),revoked_by=? WHERE business_id=? AND token_hash=? AND revoked_at IS NULL');
                $st->execute([(int)$user['id'],$biz,hash('sha256',$previous)]);
            }
            $token=bin2hex(random_bytes(32));$hours=$pair['kind']==='fixed'?24*90:12;
            $st=$db->prepare("INSERT INTO pos_terminals(business_id,warehouse_id,activated_by,kind,label,token_hash,expires_at)
                VALUES(?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL $hours HOUR))");
            $st->execute([$biz,(int)$pair['warehouse_id'],(int)$user['id'],$pair['kind'],$pair['label'],hash('sha256',$token)]);
            $terminalId=(int)$db->lastInsertId();
            $st=$db->prepare('UPDATE pos_terminal_pairings SET redeemed_at=UTC_TIMESTAMP(),redeemed_by=? WHERE id=? AND redeemed_at IS NULL');
            $st->execute([(int)$user['id'],(int)$pair['id']]);
            if($st->rowCount()!==1)throw new RuntimeException('El código ya fue utilizado.');
            $db->commit();
            return respond($response,['ok'=>true,'terminal_token'=>$token,'terminal'=>[
                'id'=>$terminalId,'warehouse_id'=>(int)$pair['warehouse_id'],'warehouse_name'=>$location['name'],
                'label'=>$pair['label'],'kind'=>$pair['kind'],'expires_in_hours'=>$hours
            ]],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/account/terminal-revoke-self',static function(Request $request,Response $response): Response {
        $user=$request->getAttribute('currentUser');$db=db();
        $terminal=titoTerminalFromRequest($db,$user,$request);
        if(!$terminal)fail('No hay caja registrada en este navegador.',409);
        $st=$db->prepare('UPDATE pos_terminals SET revoked_at=UTC_TIMESTAMP(),revoked_by=? WHERE id=? AND business_id=? AND revoked_at IS NULL');
        $st->execute([(int)$user['id'],(int)$terminal['id'],(int)$user['business_id']]);
        return respond($response,['ok'=>true]);
    });
    $group->post('/terminals/{id:[0-9]+}/revoke',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$db=db();
        $st=$db->prepare('UPDATE pos_terminals SET revoked_at=UTC_TIMESTAMP(),revoked_by=? WHERE id=? AND business_id=? AND revoked_at IS NULL');
        $st->execute([(int)$admin['id'],(int)$args['id'],(int)$admin['business_id']]);
        if($st->rowCount()!==1)fail('Caja no encontrada o ya revocada.',404);
        return respond($response,['ok'=>true]);
    });
    $group->post('/terminals/enforcement',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$b=requestBody($request);
        if(array_keys($b)!==['enabled']||!is_bool($b['enabled']))fail('Configuración inválida.',422);
        $biz=(int)$admin['business_id'];$db=db();
        try{
            $db->beginTransaction();
            $st=$db->prepare('SELECT id FROM businesses WHERE id=? FOR UPDATE');
            $st->execute([$biz]);if($st->fetchColumn()===false)fail('Empresa no encontrada.',404);
            if($b['enabled']){
                $st=$db->prepare("SELECT COUNT(*) FROM pos_terminals t JOIN warehouses w ON w.id=t.warehouse_id AND w.business_id=t.business_id
                    WHERE t.business_id=? AND t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND w.is_sales_location=1 AND w.is_active=1");
                $st->execute([$biz]);if((int)$st->fetchColumn()===0)
                    fail('Activa al menos una caja antes de exigir la identificación en todas las ventas.',409);
            }
            $st=$db->prepare('INSERT INTO pos_terminal_policy(business_id,enforce_registered_terminal,changed_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE enforce_registered_terminal=VALUES(enforce_registered_terminal),changed_by=VALUES(changed_by),changed_at=UTC_TIMESTAMP()');
            $st->execute([$biz,$b['enabled']?1:0,(int)$admin['id']]);
            $db->commit();
            return respond($response,['ok'=>true,'enforced'=>$b['enabled']]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}

function registerWarehouseRoutes(RouteCollectorProxy $group): void {
    registerTerminalRoutes($group);

    $group->get('/account/sales-location',static function(Request $request,Response $response): Response {
        $user=$request->getAttribute('currentUser');$biz=(int)$user['business_id'];$db=db();
        $selected=titoSaleLocation($db,$user,null,false,$request);
        $sql='SELECT id,name,code FROM warehouses WHERE business_id=? AND is_sales_location=1 AND is_active=1';
        $params=[$biz];
        if(($user['role']??'')!=='admin'){$sql.=' AND id=?';$params[]=$selected;}
        $st=$db->prepare($sql.' ORDER BY id');$st->execute($params);$items=$st->fetchAll();
        return respond($response,['warehouse_id'=>$selected,'locations'=>$items]);
    });
    $group->post('/users/{id:[0-9]+}/sales-location',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$uid=(int)$args['id'];
        $body=requestBody($request);
        if(array_keys($body)!==['warehouse_id']||!is_int($body['warehouse_id'])||$body['warehouse_id']<1)
            fail('Sucursal inválida.',422);
        $warehouseId=$body['warehouse_id'];$db=db();
        try{
            $db->beginTransaction();
            $st=$db->prepare("SELECT id,sales_warehouse_id FROM users WHERE id=? AND business_id=? AND role='cashier' AND is_active=1 FOR UPDATE");
            $st->execute([$uid,$biz]);$cashier=$st->fetch();$st->closeCursor();
            if(!$cashier)fail('Cajero no encontrado o inactivo.',404);
            $st=$db->prepare('SELECT id FROM warehouses WHERE id=? AND business_id=? AND is_sales_location=1 AND is_active=1');
            $st->execute([$warehouseId,$biz]);$ok=$st->fetchColumn();$st->closeCursor();
            if($ok===false)fail('Solo puedes asignar una sucursal activa de este negocio.',422);
            if((int)$cashier['sales_warehouse_id']!==$warehouseId){
                $st=$db->prepare('UPDATE users SET sales_warehouse_id=? WHERE id=? AND business_id=?');
                $st->execute([$warehouseId,$uid,$biz]);
                // Evita que la caja siga vendiendo con una sesión de otra sucursal.
                $st=$db->prepare('DELETE FROM sessions WHERE user_id=?');$st->execute([$uid]);
            }
            $db->commit();
            return respond($response,['ok'=>true,'user_id'=>$uid,'warehouse_id'=>$warehouseId,'relogin_required'=>true]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    // Listado de ubicaciones sin permisos de mutación: el cajero puede consultar
    // stock por local o bodega, sin administrar ni ajustar existencias.
    $group->get('/warehouses/locations',static function(Request $request,Response $response): Response {
        $user=$request->getAttribute('currentUser');
        $st=db()->prepare('SELECT id,name,code,is_sales_location FROM warehouses WHERE business_id=? AND is_active=1 ORDER BY is_sales_location DESC,name,id');
        $st->execute([(int)$user['business_id']]);
        return respond($response,['locations'=>$st->fetchAll()]);
    });
    $group->get('/warehouses',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);
        $st=db()->prepare('SELECT w.id,w.name,w.code,w.is_sales_location,w.is_active FROM warehouses w WHERE w.business_id=? ORDER BY w.is_sales_location DESC,w.id');
        $st->execute([(int)$admin['business_id']]);
        return respond($response,['warehouses'=>$st->fetchAll()]);
    });
    // Alta de sucursales/bodegas, stock inicial cero y caja habilitable mediante asignación de cajeros.
    $group->post('/warehouses',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);
        $biz=(int)$admin['business_id'];
        $b=requestBody($request);
        if(array_diff(array_keys($b),['name','type']))fail('Campos no permitidos.',422);
        $name=requireString($b,'name',120);
        $type=$b['type']??'storage';
        if($type!=='storage')fail('Este módulo solo permite crear ubicaciones de inventario.',422);
        $db=db();
        try {
            $db->beginTransaction();
            $st=$db->prepare('SELECT id FROM businesses WHERE id=? FOR UPDATE');
            $st->execute([$biz]);
            if(!$st->fetchColumn())fail('Empresa no encontrada.',404);
            $st=$db->prepare('SELECT COUNT(*) FROM warehouses WHERE business_id=?');
            $st->execute([$biz]);
            if((int)$st->fetchColumn()>=30)fail('Máximo 30 ubicaciones por empresa.',409);
            $st=$db->prepare('SELECT id FROM warehouses WHERE business_id=? AND LOWER(name)=LOWER(?) LIMIT 1');
            $st->execute([$biz,$name]);
            if($st->fetchColumn())fail('Ya existe una ubicación con ese nombre.',409);
            $code='ub_'.bin2hex(random_bytes(10));
            $st=$db->prepare('INSERT INTO warehouses(business_id,code,name,is_sales_location,is_active) VALUES(?,?,?,?,1)');
            $st->execute([$biz,$code,$name,0]);
            $warehouseId=(int)$db->lastInsertId();
            // Los productos anteriores comienzan con cero en la nueva ubicación.
            // Las altas simultáneas están cubiertas por el trigger de products.
            $st=$db->prepare('INSERT IGNORE INTO warehouse_stocks(business_id,warehouse_id,product_id,qty) SELECT business_id,?,id,0 FROM products WHERE business_id=?');
            $st->execute([$warehouseId,$biz]);
            $st=$db->prepare('SELECT COUNT(*) FROM products p LEFT JOIN warehouse_stocks ws ON ws.product_id=p.id AND ws.warehouse_id=? AND ws.business_id=? WHERE p.business_id=? AND ws.product_id IS NULL');
            $st->execute([$warehouseId,$biz,$biz]);
            if((int)$st->fetchColumn()!==0)throw new RuntimeException('No se crearon todos los saldos iniciales.');
            $db->commit();
            return respond($response,['ok'=>true,'warehouse'=>['id'=>$warehouseId,'code'=>$code,'name'=>$name,'is_sales_location'=>0,'is_active'=>1],'sales_enabled'=>false],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/warehouses/{id:[0-9]+}/rename',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$id=(int)$args['id'];
        $body=requestBody($request);
        if(array_diff(array_keys($body),['name']))fail('Campos no permitidos.',422);
        $name=requireString($body,'name',120);
        if(mb_strlen($name)<2)fail('Indica un nombre de al menos 2 caracteres.',422);
        $db=db();
        try{
            $db->beginTransaction();
            // El alta de ubicaciones usa el mismo bloqueo por empresa.
            $st=$db->prepare('SELECT id FROM businesses WHERE id=? FOR UPDATE');$st->execute([$biz]);
            if(!$st->fetchColumn())fail('Empresa no encontrada.',404);
            $st=$db->prepare('SELECT id FROM warehouses WHERE id=? AND business_id=? FOR UPDATE');$st->execute([$id,$biz]);
            if(!$st->fetchColumn())fail('Ubicación de inventario no encontrada.',404);
            $st=$db->prepare('SELECT id FROM warehouses WHERE business_id=? AND id<>? AND LOWER(name)=LOWER(?) LIMIT 1');$st->execute([$biz,$id,$name]);
            if($st->fetchColumn())fail('Ya existe una ubicación con ese nombre.',409);
            $st=$db->prepare('UPDATE warehouses SET name=? WHERE id=? AND business_id=?');$st->execute([$name,$id,$biz]);
            $db->commit();
            return respond($response,['ok'=>true,'warehouse'=>['id'=>$id,'name'=>$name]]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->get('/warehouses/stock',static function(Request $request,Response $response): Response {
        $user=$request->getAttribute('currentUser');$biz=(int)$user['business_id'];
        $st=db()->prepare("SELECT p.id AS product_id,p.name,p.sku,p.sale_unit,p.stock_qty AS total_qty,p.is_active,EXISTS(SELECT 1 FROM serial_product_controls c WHERE c.product_id=p.id AND c.business_id=p.business_id) AS serial_control,COALESCE(SUM(CASE WHEN w.code='local' THEN ws.qty ELSE 0 END),0) AS local_qty,COALESCE(SUM(CASE WHEN w.code='bodega' THEN ws.qty ELSE 0 END),0) AS bodega_qty FROM products p LEFT JOIN warehouse_stocks ws ON ws.product_id=p.id AND ws.business_id=p.business_id LEFT JOIN warehouses w ON w.id=ws.warehouse_id AND w.business_id=p.business_id WHERE p.business_id=? GROUP BY p.id,p.business_id,p.name,p.sku,p.sale_unit,p.stock_qty,p.is_active ORDER BY p.name,p.id LIMIT 3000");
        $st->execute([$biz]);
        $stocks=$st->fetchAll();
        // Exponer saldos por cualquier sucursal/bodega, manteniendo local_qty
        // y bodega_qty para clientes antiguos durante la transición.
        $indexes=[];
        foreach($stocks as $index=>$product){
            $stocks[$index]['locations']=[];
            $indexes[(int)$product['product_id']]=$index;
        }
        if($indexes){
            $ids=array_keys($indexes);
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $sql="SELECT ws.product_id,ws.warehouse_id,ws.qty FROM warehouse_stocks ws JOIN warehouses w ON w.id=ws.warehouse_id AND w.business_id=ws.business_id WHERE ws.business_id=? AND ws.product_id IN ($placeholders)";
            $rows=db()->prepare($sql);
            $rows->execute(array_merge([$biz],$ids));
            foreach($rows->fetchAll() as $row){
                $index=$indexes[(int)$row['product_id']];
                $stocks[$index]['locations'][(string)$row['warehouse_id']]=(float)$row['qty'];
            }
        }
        return respond($response,['stocks'=>$stocks]);
    });
    $group->get('/warehouse-transfers',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];
        $st=db()->prepare('SELECT t.id,t.source_warehouse_id,t.target_warehouse_id,t.note,t.created_at,u.email AS actor_email,src.name AS source_name,dst.name AS target_name,(SELECT COUNT(*) FROM warehouse_transfer_items ti WHERE ti.transfer_id=t.id) AS item_count FROM warehouse_transfers t JOIN users u ON u.id=t.created_by JOIN warehouses src ON src.id=t.source_warehouse_id JOIN warehouses dst ON dst.id=t.target_warehouse_id WHERE t.business_id=? ORDER BY t.id DESC LIMIT 100');
        $st->execute([$biz]);return respond($response,['transfers'=>$st->fetchAll()]);
    });
    $group->get('/warehouse-transfers/{id:[0-9]+}',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$id=(int)$args['id'];
        $st=db()->prepare('SELECT t.id,t.note,t.created_at,src.name AS source_name,dst.name AS target_name,u.email AS actor_email FROM warehouse_transfers t JOIN warehouses src ON src.id=t.source_warehouse_id JOIN warehouses dst ON dst.id=t.target_warehouse_id JOIN users u ON u.id=t.created_by WHERE t.business_id=? AND t.id=? LIMIT 1');
        $st->execute([$biz,$id]);$transfer=$st->fetch();
        if(!$transfer)fail('Traslado no encontrado.',404);
        $st=db()->prepare('SELECT ti.product_id,p.name,ti.quantity,ti.source_before,ti.source_after,ti.target_before,ti.target_after,p.sale_unit FROM warehouse_transfer_items ti JOIN products p ON p.id=ti.product_id WHERE ti.transfer_id=? AND p.business_id=? ORDER BY ti.id');
        $st->execute([$id,$biz]);
        $items=$st->fetchAll();
        $st=db()->prepare("SELECT x.product_id,x.serial_number FROM serial_unit_events e JOIN serial_units x ON x.id=e.unit_id AND x.business_id=e.business_id WHERE e.business_id=? AND e.event_type='transferred' AND JSON_UNQUOTE(JSON_EXTRACT(e.details,'$.transfer_id'))=? ORDER BY x.serial_number");
        $st->execute([$biz,(string)$id]);$map=[];foreach($st->fetchAll() as $r)$map[$r['product_id']][]=$r['serial_number'];
        foreach($items as &$item)$item['serial_numbers']=$map[$item['product_id']]??[];unset($item);
        return respond($response,['transfer'=>$transfer,'items'=>$items]);
    });
    $group->post('/warehouse-transfers',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$uid=(int)$admin['id'];
        $b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','source_warehouse_id','target_warehouse_id','note','items']))fail('Campos no permitidos.',422);
        $rid=$b['request_id']??null;
        if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$rid))fail('Identificador inválido.',422);
        $source=filter_var($b['source_warehouse_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $target=filter_var($b['target_warehouse_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($source===false||$target===false||$source===$target)fail('Selecciona bodegas diferentes.',422);
        $note=requireString($b,'note',250);
        $items=$b['items']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>50)fail('Ingresa entre 1 y 50 productos.',422);
        $specs=[];$serialSpecs=[];
        foreach($items as $item){
            if(!is_array($item)||array_diff(array_keys($item),['product_id','quantity','serial_numbers']))fail('Datos de traslado inválidos.',422);
            $pid=filter_var($item['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($pid===false||isset($specs[$pid]))fail('Producto duplicado o inválido.',422);
            $specs[$pid]=$item['quantity']??null;
            if(array_key_exists('serial_numbers',$item))$serialSpecs[$pid]=serialNormalizeList($item['serial_numbers']);
        }
        ksort($specs,SORT_NUMERIC);
        ksort($serialSpecs,SORT_NUMERIC);$canonical=[$source,$target,$note,$specs];if($serialSpecs)$canonical[]=$serialSpecs;
        $hash=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR));
        $db=db();
        try{
            $db->beginTransaction();
            originLockCompany($db,$biz);
            $st=$db->prepare('SELECT id,payload_hash FROM warehouse_transfers WHERE business_id=? AND request_id=? FOR UPDATE');$st->execute([$biz,strtolower($rid)]);$old=$st->fetch();$st->closeCursor();
            if($old){if(!hash_equals((string)$old['payload_hash'],$hash))fail('Solicitud reutilizada con otros datos.',409);$db->commit();return respond($response,['ok'=>true,'transfer_id'=>(int)$old['id'],'already_recorded'=>true,'global_stock_unchanged'=>true]);}
            $st=$db->prepare('SELECT id FROM warehouses WHERE business_id=? AND is_active=1 AND id IN (?,?)');
            $st->execute([$biz,$source,$target]);$valid=$st->fetchAll();
            if(count($valid)!==2)fail('Ubicaciones no válidas.',422);
            $st=$db->prepare('INSERT INTO warehouse_transfers(business_id,created_by,request_id,payload_hash,source_warehouse_id,target_warehouse_id,note) VALUES(?,?,?,?,?,?,?)');
            $st->execute([$biz,$uid,strtolower($rid),$hash,$source,$target,$note]);$tid=(int)$db->lastInsertId();
            // Mismo orden de bloqueo que la venta: primero producto, luego ubicaciones.
            $pLock=$db->prepare('SELECT id,name,sale_unit,stock_qty FROM products WHERE id=? AND business_id=? AND is_active=1 FOR UPDATE');
            $bal=$db->prepare('SELECT warehouse_id,qty FROM warehouse_stocks WHERE business_id=? AND product_id=? AND warehouse_id IN (?,?) ORDER BY warehouse_id FOR UPDATE');
            $upd=$db->prepare('UPDATE warehouse_stocks SET qty=? WHERE business_id=? AND product_id=? AND warehouse_id=?');
            $ins=$db->prepare('INSERT INTO warehouse_transfer_items(transfer_id,product_id,quantity,source_before,source_after,target_before,target_after) VALUES(?,?,?,?,?,?,?)');
            foreach($specs as $pid=>$qtyRaw){
                $pLock->execute([$pid,$biz]);$p=$pLock->fetch();$pLock->closeCursor();
                if(!$p)fail('Producto no disponible: '.$pid,422);
                $controlled=serialControlled($db,$biz,$pid);$numbers=$serialSpecs[$pid]??[];
                $qty=decimalQuantity($qtyRaw,false,$p['sale_unit']!=='unidad');
                if((float)$qty<=0)fail('La cantidad debe ser positiva.',422);
                if($controlled){
                    if((float)$qty!==(float)count($numbers)||$p['sale_unit']!=='unidad')throw new HttpError('Selecciona una serie por unidad de '.$p['name'].'.',422,'serial_numbers');
                    serialReconcile($db,$biz,$pid,(float)$p['stock_qty']);
                }elseif(array_key_exists($pid,$serialSpecs))fail('Este producto no tiene control de serie.',422);
                $bal->execute([$biz,$pid,$source,$target]);
                $rows=$bal->fetchAll();$bal->closeCursor();
                $stocks=[];
                foreach($rows as $row)$stocks[(int)$row['warehouse_id']]=(float)$row['qty'];
                if(count($stocks)!==2)fail('Stock por ubicación incompleto para '.$p['name'],409);
                if($stocks[$source]+0.00001<(float)$qty)fail('Stock insuficiente en bodega de origen para '.$p['name'],409);
                $from=number_format(max(0,$stocks[$source]-(float)$qty),3,'.','');
                $to=number_format($stocks[$target]+(float)$qty,3,'.','');
                if((float)$to>1000000000)fail('Stock destino fuera de rango.',422);
                $upd->execute([$from,$biz,$pid,$source]);$upd->execute([$to,$biz,$pid,$target]);
                $ins->execute([$tid,$pid,$qty,number_format($stocks[$source],3,'.',''),$from,number_format($stocks[$target],3,'.',''),$to]);
                if($controlled){serialTransfer($db,$admin,$pid,$source,$target,$tid,$note,$numbers);serialReconcile($db,$biz,$pid,(float)$p['stock_qty']);}
            }
            $db->commit();
            return respond($response,['ok'=>true,'transfer_id'=>$tid,'moved_products'=>count($specs),'global_stock_unchanged'=>true],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062){
                $st=$db->prepare('SELECT id,payload_hash FROM warehouse_transfers WHERE business_id=? AND request_id=?');
                $st->execute([$biz,strtolower($rid)]);$old=$st->fetch();
                if($old){
                    if(!hash_equals((string)$old['payload_hash'],$hash))fail('Solicitud reutilizada con otros datos.',409);
                    return respond($response,['ok'=>true,'transfer_id'=>(int)$old['id'],'already_recorded'=>true,'global_stock_unchanged'=>true]);
                }
            }
            throw $e;
        }
    });
}

