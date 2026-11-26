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
function registerSerialRoutes(RouteCollectorProxy $group): void {
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
        $s=db()->prepare('SELECT x.id,x.serial_number,x.status,x.receipt_id,x.received_at,x.unit_cost_clp,p.name AS product_name,p.sku,w.name AS warehouse_name,h.supplier_name FROM serial_units x JOIN products p ON p.id=x.product_id AND p.business_id=x.business_id JOIN warehouses w ON w.id=x.warehouse_id AND w.business_id=x.business_id JOIN receipt_supplier_snapshots h ON h.receipt_id=x.receipt_id AND h.business_id=x.business_id AND h.supplier_id=x.supplier_id WHERE x.business_id=? AND (x.serial_number LIKE ? OR p.name LIKE ? OR p.sku LIKE ?) ORDER BY x.id DESC LIMIT 100');
        $s->execute([$biz,$term,$term,$term]);return respond($response,['units'=>$s->fetchAll()]);
    });
    $group->get('/serials/units/{id:[0-9]+}/events',static function(Request $request,Response $response,array $args): Response {
        $u=adminOnly($request);$biz=(int)$u['business_id'];$id=(int)$args['id'];
        $s=db()->prepare('SELECT id FROM serial_units WHERE business_id=? AND id=?');$s->execute([$biz,$id]);if(!$s->fetchColumn())fail('Unidad no encontrada.',404);
        $s=db()->prepare('SELECT event_type,details,created_at,actor_id FROM serial_unit_events WHERE business_id=? AND unit_id=? ORDER BY id');$s->execute([$biz,$id]);return respond($response,['events'=>$s->fetchAll()]);
    });
}
