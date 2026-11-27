<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function serialControlled(PDO $db,int $biz,int $pid): bool {
    // Llamar con producto bloqueado en operaciones de stock.
    $s=$db->prepare('SELECT business_id FROM serial_product_controls WHERE product_id=? FOR UPDATE');$s->execute([$pid]);$owner=$s->fetchColumn();$s->closeCursor();
    if($owner!==false&&(int)$owner!==$biz)fail('Control de series inconsistente.',409);
    return $owner!==false;
}
function serialRejectLegacy(PDO $db,int $biz,int $pid): void {
    if(serialControlled($db,$biz,$pid))fail('Este producto requiere identificar números de serie. La operación todavía no admite unidades serializadas.',409);
}
function serialNormalizeList(mixed $raw): array {
    if(!is_array($raw)||!array_is_list($raw)||count($raw)>500)throw new HttpError('Ingresa hasta 500 números de serie por producto.',422,'serial_numbers');
    $out=[];
    foreach($raw as $v){
        if(!is_string($v)||!mb_check_encoding($v,'UTF-8'))throw new HttpError('Número de serie inválido.',422,'serial_numbers');
        $v=mb_strtoupper(trim($v),'UTF-8');
        if($v===''||mb_strlen($v)>120||preg_match('/[\p{Cc}\p{Cf}]/u',$v))throw new HttpError('Número de serie inválido (máximo 120 caracteres).',422,'serial_numbers');
        if(in_array($v,$out,true))throw new HttpError('Número de serie duplicado: '.$v,422,'serial_numbers');
        $out[]=$v;
    }
    sort($out,SORT_STRING);return $out;
}
function serialReconcile(PDO $db,int $biz,int $pid,float $global): void {
    $s=$db->prepare('SELECT w.id,w.business_id AS owner,ws.qty FROM warehouse_stocks ws JOIN warehouses w ON w.id=ws.warehouse_id WHERE ws.business_id=? AND ws.product_id=? ORDER BY w.id FOR UPDATE');$s->execute([$biz,$pid]);$balances=[];
    foreach($s->fetchAll() as $r){if((int)$r['owner']!==$biz||(float)$r['qty']<0)fail('Bodega inconsistente.',409);$balances[(int)$r['id']]=(float)$r['qty'];}
    $s=$db->prepare("SELECT warehouse_id,COUNT(*) AS n FROM serial_units WHERE business_id=? AND product_id=? AND status='in_stock' GROUP BY warehouse_id");$s->execute([$biz,$pid]);$counts=[];
    foreach($s->fetchAll() as $r)$counts[(int)$r['warehouse_id']]=(int)$r['n'];
    foreach($balances as $wid=>$qty)if(abs($qty-($counts[$wid]??0))>0.0005)fail('El stock no coincide con las unidades serializadas. Operación detenida.',409);
    if(array_diff_key($counts,$balances)||abs(array_sum($balances)-$global)>0.0005)fail('Inventario serializado sin conciliar.',409);
}
function serialReceive(PDO $db,array $actor,int $pid,int $receipt,int $supplier,int $warehouse,int $cost,string $date,array $numbers): void {
    $biz=(int)$actor['business_id'];$uid=(int)$actor['id'];
    $s=$db->prepare('INSERT INTO serial_units(business_id,product_id,serial_number,receipt_id,supplier_id,warehouse_id,unit_cost_clp,received_at) VALUES(?,?,?,?,?,?,?,?)');
    $event=$db->prepare("INSERT INTO serial_unit_events(business_id,unit_id,actor_id,event_type,details) VALUES(?,?,?,'received',?)");
    foreach($numbers as $number){
        try{$s->execute([$biz,$pid,$number,$receipt,$supplier,$warehouse,$cost,$date]);}
        catch(PDOException $e){if((int)($e->errorInfo[1]??0)===1062)throw new HttpError('El número de serie ya está registrado para este producto: '.$number,409,'serial_numbers');throw $e;}
        $id=(int)$db->lastInsertId();
        $event->execute([$biz,$id,$uid,json_encode(['receipt_id'=>$receipt,'supplier_id'=>$supplier,'warehouse_id'=>$warehouse,'unit_cost_clp'=>$cost],JSON_THROW_ON_ERROR)]);
    }
}
function serialTransfer(PDO $db,array $actor,int $pid,int $source,int $target,int $transfer,string $note,array $numbers): void {
    $biz=(int)$actor['business_id'];$uid=(int)$actor['id'];
    $get=$db->prepare("SELECT id FROM serial_units WHERE business_id=? AND product_id=? AND serial_number=? AND warehouse_id=? AND status='in_stock' FOR UPDATE");
    $update=$db->prepare("UPDATE serial_units SET warehouse_id=? WHERE business_id=? AND id=? AND warehouse_id=? AND status='in_stock'");
    $event=$db->prepare("INSERT INTO serial_unit_events(business_id,unit_id,actor_id,event_type,details) VALUES(?,?,?,'transferred',?)");
    $s=$db->prepare('SELECT id,name FROM warehouses WHERE business_id=? AND id IN (?,?)');$s->execute([$biz,$source,$target]);$names=[];foreach($s->fetchAll() as $r)$names[(int)$r['id']]=$r['name'];
    if(count($names)!==2)fail('Ubicaciones inconsistentes.',409);
    $details=json_encode(['transfer_id'=>$transfer,'source_warehouse_id'=>$source,'source_name'=>$names[$source],'target_warehouse_id'=>$target,'target_name'=>$names[$target],'note'=>$note],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    foreach($numbers as $number){
        $get->execute([$biz,$pid,$number,$source]);$id=$get->fetchColumn();$get->closeCursor();
        if($id===false)throw new HttpError('Serie no disponible en la bodega de origen: '.$number,409,'serial_numbers');
        $update->execute([$target,$biz,$id,$source]);if($update->rowCount()!==1)fail('La ubicación de la unidad cambió.',409);
        $event->execute([$biz,$id,$uid,$details]);
    }
}
function serialSaleRecord(PDO $db,array $actor,int $pid,int $warehouse,int $sale,int $line,int $price,array $numbers): void {
    $biz=(int)$actor['business_id'];$uid=(int)$actor['id'];
    $get=$db->prepare("SELECT id FROM serial_units WHERE business_id=? AND product_id=? AND warehouse_id=? AND serial_number=? AND status='in_stock' FOR UPDATE");
    $upd=$db->prepare("UPDATE serial_units SET status='sold' WHERE id=? AND business_id=? AND status='in_stock'");
    $ins=$db->prepare('INSERT INTO serial_sale_units(unit_id,business_id,sale_id,sale_item_id,warehouse_id,unit_price_clp) VALUES(?,?,?,?,?,?)');
    $event=$db->prepare("INSERT INTO serial_unit_events(business_id,unit_id,actor_id,event_type,details) VALUES(?,?,?,'sold',?)");
    foreach($numbers as $n){
        $get->execute([$biz,$pid,$warehouse,$n]);$id=$get->fetchColumn();$get->closeCursor();
        if($id===false)throw new HttpError('Serie no disponible en bodega: '.$n,409,'serial_numbers');
        $upd->execute([$id,$biz]);if($upd->rowCount()!==1)fail('La serie ya no está disponible.',409);
        $ins->execute([$id,$biz,$sale,$line,$warehouse,$price]);
        $event->execute([$biz,$id,$uid,json_encode(['sale_id'=>$sale,'sale_item_id'=>$line,'warehouse_id'=>$warehouse],JSON_THROW_ON_ERROR)]);
    }
}
function registerSerialRoutes(RouteCollectorProxy $group): void {
    $group->get('/sales/serial-clients',static function(Request $request,Response $response): Response {
        $u=$request->getAttribute('currentUser');$biz=(int)$u['business_id'];
        $q=$request->getQueryParams()['q']??'';if(!is_string($q)||mb_strlen($q)>120)fail('Búsqueda inválida.',422);
        $s=db()->prepare('SELECT id,name FROM habitual_clients WHERE business_id=? AND is_active=1 AND name LIKE ? ORDER BY name,id LIMIT 100');$s->execute([$biz,'%'.trim($q).'%']);
        return respond($response,['clients'=>$s->fetchAll()]);
    });
    $group->get('/sales/serial-available',static function(Request $request,Response $response): Response {
        $u=$request->getAttribute('currentUser');$biz=(int)$u['business_id'];$q=$request->getQueryParams();
        $pid=filter_var($q['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $wid=filter_var($q['warehouse_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $search=$q['q']??'';if($pid===false||$wid===false||!is_string($search)||mb_strlen($search)>120)fail('Filtros inválidos.',422);
        $db=db();$loc=titoSaleLocation($db,$u,$wid,true,$request);
        $s=$db->prepare("SELECT x.id,x.serial_number FROM serial_units x JOIN products p ON p.id=x.product_id AND p.business_id=x.business_id AND p.is_active=1 WHERE x.business_id=? AND x.product_id=? AND x.warehouse_id=? AND x.status='in_stock' AND x.serial_number LIKE ? ORDER BY x.serial_number LIMIT 501");
        $s->execute([$biz,$pid,$loc,'%'.mb_strtoupper(trim($search),'UTF-8').'%']);$rows=$s->fetchAll();
        return respond($response,['units'=>array_slice($rows,0,500),'more'=>count($rows)>500]);
    });
    $group->get('/serials/available',static function(Request $request,Response $response): Response {
        $u=adminOnly($request);$biz=(int)$u['business_id'];$q=$request->getQueryParams();
        $pid=filter_var($q['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $wid=filter_var($q['warehouse_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $search=$q['q']??'';
        if($pid===false||$wid===false||!is_string($search)||mb_strlen($search)>120)fail('Filtros inválidos.',422);
        $s=db()->prepare("SELECT x.id,x.serial_number FROM serial_units x JOIN products p ON p.id=x.product_id AND p.business_id=x.business_id AND p.is_active=1 JOIN warehouses w ON w.id=x.warehouse_id AND w.business_id=x.business_id AND w.is_active=1 WHERE x.business_id=? AND x.product_id=? AND x.warehouse_id=? AND x.status='in_stock' AND x.serial_number LIKE ? ORDER BY x.serial_number LIMIT 501");
        $s->execute([$biz,$pid,$wid,'%'.mb_strtoupper(trim($search),'UTF-8').'%']);$rows=$s->fetchAll();$more=count($rows)>500;
        return respond($response,['units'=>array_slice($rows,0,500),'more'=>$more]);
    });
    $group->get('/serials/products',static function(Request $request,Response $response): Response {
        $u=adminOnly($request);$biz=(int)$u['business_id'];$q=$request->getQueryParams()['q']??'';
        if(!is_string($q)||mb_strlen($q)>120)fail('Búsqueda inválida.',422);
        $term='%'.trim($q).'%';
        $s=db()->prepare('SELECT p.id,p.name,p.sku,p.sale_unit,p.stock_qty,p.is_active,(c.product_id IS NOT NULL) AS serial_control FROM products p LEFT JOIN serial_product_controls c ON c.product_id=p.id AND c.business_id=p.business_id WHERE p.business_id=? AND (p.name LIKE ? OR p.sku LIKE ?) ORDER BY p.name,p.id LIMIT 100');
        $s->execute([$biz,$term,$term]);return respond($response,['products'=>$s->fetchAll()]);
    });
    $group->post('/serials/products/{id:[0-9]+}/enable',static function(Request $request,Response $response,array $args): Response {
        $u=adminOnly($request);$db=db();$biz=(int)$u['business_id'];$pid=(int)$args['id'];
        $db->beginTransaction();
        try{
            $s=$db->prepare('SELECT sale_unit,stock_qty,is_active FROM products WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$pid]);$p=$s->fetch();
            if(!$p||(int)$p['is_active']!==1)fail('Producto activo no encontrado.',404);
            if(serialControlled($db,$biz,$pid)){$db->commit();return respond($response,['ok'=>true]);}
            if($p['sale_unit']!=='unidad'||(float)$p['stock_qty']!==0.0)fail('El control de series requiere un producto por unidad y sin stock previo.',409);
            // Evitar activar sobre productos con ventas antiguas que podrían necesitar reversas.
            $s=$db->prepare('SELECT si.id FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.business_id=? AND si.product_id=? LIMIT 1');$s->execute([$biz,$pid]);if($s->fetchColumn())fail('El producto tiene historial de ventas. Requiere una migración de series revisada.',409);
            serialReconcile($db,$biz,$pid,0.0);
            $s=$db->prepare('INSERT INTO serial_product_controls(product_id,business_id,enabled_by) VALUES(?,?,?)');$s->execute([$pid,$biz,(int)$u['id']]);
            $db->commit();return respond($response,['ok'=>true]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->get('/serials/units',static function(Request $request,Response $response): Response {
        $u=adminOnly($request);$biz=(int)$u['business_id'];$q=$request->getQueryParams()['q']??'';
        if(!is_string($q)||mb_strlen($q)>120)fail('Búsqueda inválida.',422);
        $term='%'.trim($q).'%';
        $s=db()->prepare('SELECT x.id,x.serial_number,x.status,x.receipt_id,x.received_at,x.unit_cost_clp,p.name AS product_name,p.sku,w.name AS warehouse_name,h.supplier_name,ss.sale_id,sc.client_name FROM serial_units x JOIN products p ON p.id=x.product_id AND p.business_id=x.business_id JOIN warehouses w ON w.id=x.warehouse_id AND w.business_id=x.business_id JOIN receipt_supplier_snapshots h ON h.receipt_id=x.receipt_id AND h.business_id=x.business_id AND h.supplier_id=x.supplier_id LEFT JOIN serial_sale_units ss ON ss.unit_id=x.id AND ss.business_id=x.business_id LEFT JOIN serial_sale_snapshots sc ON sc.sale_id=ss.sale_id AND sc.business_id=x.business_id WHERE x.business_id=? AND (x.serial_number LIKE ? OR p.name LIKE ? OR p.sku LIKE ?) ORDER BY x.id DESC LIMIT 100');
        $s->execute([$biz,$term,$term,$term]);return respond($response,['units'=>$s->fetchAll()]);
    });
    $group->get('/serials/units/{id:[0-9]+}/events',static function(Request $request,Response $response,array $args): Response {
        $u=adminOnly($request);$biz=(int)$u['business_id'];$id=(int)$args['id'];
        $s=db()->prepare('SELECT id FROM serial_units WHERE business_id=? AND id=?');$s->execute([$biz,$id]);if(!$s->fetchColumn())fail('Unidad no encontrada.',404);
        $s=db()->prepare('SELECT event_type,details,created_at,actor_id FROM serial_unit_events WHERE business_id=? AND unit_id=? ORDER BY id');$s->execute([$biz,$id]);return respond($response,['events'=>$s->fetchAll()]);
    });
}
