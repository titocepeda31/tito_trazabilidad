<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Devoluciones de clientes; registro interno, no reversa bancaria ni documento tributario. */
function registerCustomerReturnRoutes(RouteCollectorProxy $group): void {
    $group->get('/returns',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);
        $st=db()->prepare('SELECT r.id,r.sale_id,r.return_kind,r.item_condition,r.resolution,r.reason,r.amount_clp,r.refund_status,r.created_at,u.email AS created_by_email FROM customer_returns r JOIN users u ON u.id=r.created_by AND u.business_id=r.business_id WHERE r.business_id=? ORDER BY r.id DESC LIMIT 100');
        $st->execute([(int)$admin['business_id']]);
        return respond($response,['returns'=>$st->fetchAll()]);
    });

    $group->get('/sales/{id:[0-9]+}/returns',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$saleId=(int)$args['id'];
        $st=db()->prepare('SELECT id,status FROM sales WHERE id=? AND business_id=?');
        $st->execute([$saleId,$biz]);$sale=$st->fetch();
        if(!$sale)fail('Venta no encontrada.',404);
        $st=db()->prepare('SELECT si.id AS sale_item_id,si.product_id,si.product_name_snapshot,si.quantity,si.unit_price_clp,si.subtotal_clp,p.sale_unit,COALESCE(SUM(ri.quantity),0) AS returned_qty,COALESCE(SUM(ri.amount_clp),0) AS returned_amount_clp FROM sale_items si JOIN products p ON p.id=si.product_id AND p.business_id=? LEFT JOIN customer_return_items ri ON ri.sale_item_id=si.id WHERE si.sale_id=? GROUP BY si.id,si.product_id,si.product_name_snapshot,si.quantity,si.unit_price_clp,si.subtotal_clp,p.sale_unit ORDER BY si.id');
        $st->execute([$biz,$saleId]);$items=$st->fetchAll();
        $st=db()->prepare('SELECT r.id,r.return_kind,r.item_condition,r.resolution,r.reason,r.amount_clp,r.refund_status,r.refund_method,r.refund_reference,r.created_at,r.refunded_at,u.email AS created_by_email FROM customer_returns r JOIN users u ON u.id=r.created_by AND u.business_id=r.business_id WHERE r.sale_id=? AND r.business_id=? ORDER BY r.id DESC');
        $st->execute([$saleId,$biz]);$returns=$st->fetchAll();
        return respond($response,['sale_id'=>$saleId,'sale_status'=>$sale['status'],'items'=>$items,'returns'=>$returns]);
    });

    $group->post('/sales/{id:[0-9]+}/returns',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$uid=(int)$admin['id'];$saleId=(int)$args['id'];
        $body=requestBody($request);
        if(array_diff(array_keys($body),['request_id','return_kind','item_condition','resolution','reason','inspection_confirmed','items']))fail('Campos de devolución no permitidos.',422);
        $rid=$body['request_id']??null;
        if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$rid))fail('Identificador de devolución inválido.',422);
        $rid=strtolower($rid);
        $kind=$body['return_kind']??null;$condition=$body['item_condition']??null;$resolution=$body['resolution']??null;
        if(!in_array($kind,['commercial','warranty'],true))fail('Motivo de devolución inválido.',422);
        if(($kind==='commercial'&&$condition!=='resellable')||($kind==='warranty'&&$condition!=='defective')){
            fail('El producto debe estar apto para reventa en una devolución comercial. Los reclamos por falla de origen se gestionan como garantía, sin sumar stock vendible.',422);
        }
        if(!in_array($resolution,['refund','exchange','repair'],true))fail('Solución solicitada inválida.',422);
        if(($body['inspection_confirmed']??false)!==true)fail('El administrador debe confirmar la inspección física del producto.',422);
        $reason=requireString($body,'reason',250);
        if(mb_strlen($reason)<5)fail('Describe el motivo con al menos 5 caracteres.',422);
        $items=$body['items']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>80)fail('Selecciona entre 1 y 80 productos.',422);
        $wanted=[];
        foreach($items as $row){
            if(!is_array($row)||array_diff(array_keys($row),['sale_item_id','quantity']))fail('Producto de devolución no válido.',422);
            $lineId=filter_var($row['sale_item_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($lineId===false||isset($wanted[$lineId]))fail('Producto repetido o inválido.',422);
            $raw=$row['quantity']??null;
            if(!is_int($raw)&&!is_float($raw))fail('Cantidad de devolución inválida.',422);
            $qty=(float)$raw;
            if(!is_finite($qty)||$qty<=0||$qty>1000000000||abs($qty*1000-round($qty*1000))>0.000001)fail('Cantidad fuera de rango.',422);
            $wanted[$lineId]=number_format($qty,3,'.','');
        }
        ksort($wanted,SORT_NUMERIC);
        $payload=['sale_id'=>$saleId,'return_kind'=>$kind,'item_condition'=>$condition,'resolution'=>$resolution,'reason'=>$reason,'inspection_confirmed'=>true,'items'=>$wanted];
        $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();
        try{
            $db->beginTransaction();
            // La fila de la venta serializa devoluciones simultáneas, y también su anulación.
            $st=$db->prepare('SELECT id,status,warehouse_id FROM sales WHERE id=? AND business_id=? FOR UPDATE');
            $st->execute([$saleId,$biz]);$sale=$st->fetch();$st->closeCursor();
            if(!$sale)fail('Venta no encontrada.',404);
            $st=$db->prepare('SELECT id,payload_hash,amount_clp FROM customer_returns WHERE business_id=? AND request_id=? LIMIT 1');
            $st->execute([$biz,$rid]);$previous=$st->fetch();$st->closeCursor();
            if($previous){
                if(!hash_equals((string)$previous['payload_hash'],$hash))fail('Identificador de devolución reutilizado con datos diferentes.',409);
                $db->commit();
                return respond($response,['ok'=>true,'return_id'=>(int)$previous['id'],'amount_clp'=>(int)$previous['amount_clp'],'already_recorded'=>true],200);
            }
            if($sale['status']!=='completed')fail('Solo se pueden devolver productos de ventas vigentes.',409);
            $saleLocation=$sale['warehouse_id']===null?titoLocalLocationId($db,$biz):(int)$sale['warehouse_id'];
            $st=$db->prepare('SELECT si.id,si.product_id,si.quantity,si.unit_price_clp,si.subtotal_clp,p.sale_unit,p.is_active,p.stock_qty,p.name FROM sale_items si JOIN products p ON p.id=si.product_id AND p.business_id=? WHERE si.sale_id=? ORDER BY si.id');
            $st->execute([$biz,$saleId]);$saleLines=$st->fetchAll();$st->closeCursor();
            $byId=[];foreach($saleLines as $line)$byId[(int)$line['id']]=$line;
            $st=$db->prepare('SELECT ri.sale_item_id,COALESCE(SUM(ri.quantity),0) AS qty,COALESCE(SUM(ri.amount_clp),0) AS amount_clp FROM customer_return_items ri JOIN customer_returns r ON r.id=ri.return_id AND r.business_id=? WHERE r.sale_id=? GROUP BY ri.sale_item_id');
            $st->execute([$biz,$saleId]);$taken=$st->fetchAll();$st->closeCursor();
            $already=[];foreach($taken as $line)$already[(int)$line['sale_item_id']]=$line;
            $validated=[];$sum=0;
            foreach($wanted as $lineId=>$rawQty){
                if(!isset($byId[$lineId]))fail('La línea no pertenece a esta venta.',422);
                $line=$byId[$lineId];$qty=(float)$rawQty;$original=(float)$line['quantity'];
                $oldQty=(float)($already[$lineId]['qty']??0);
                $remaining=$original-$oldQty;
                if($qty>$remaining+0.000001||$remaining<=0)fail('Cantidad excede lo vendido y todavía no devuelto.',409);
                if($line['sale_unit']==='unidad'&&floor($qty)!==$qty)fail('El producto se devuelve en unidades enteras.',422);
                $oldAmount=(int)($already[$lineId]['amount_clp']??0);
                $maxAmount=(int)$line['subtotal_clp']-$oldAmount;
                if($maxAmount<0)fail('Inconsistencia de importes previos.',409);
                $amount=$qty>=$remaining-0.000001?$maxAmount:
                    min($maxAmount,(int)round($qty*(int)$line['unit_price_clp'],0,PHP_ROUND_HALF_UP));
                $validated[]=['line'=>$line,'qty'=>$qty,'qty_formatted'=>$rawQty,'amount'=>$amount];
                $sum+=$amount;
                if($sum>1000000000000)fail('Monto de devolución fuera de rango.',422);
            }
            $refundStatus=$resolution==='refund'?'pending':'not_applicable';
            $insert=$db->prepare('INSERT INTO customer_returns(business_id,sale_id,created_by,request_id,payload_hash,return_kind,item_condition,resolution,reason,amount_clp,refund_status) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([$biz,$saleId,$uid,$rid,$hash,$kind,$condition,$resolution,$reason,$sum,$refundStatus]);
            $returnId=(int)$db->lastInsertId();
            $insertItem=$db->prepare('INSERT INTO customer_return_items(return_id,sale_item_id,product_id,quantity,unit_price_clp,amount_clp,restocked) VALUES(?,?,?,?,?,?,?)');
            $getProduct=$db->prepare('SELECT id,stock_qty,is_active,sale_unit,name FROM products WHERE id=? AND business_id=? FOR UPDATE');
            foreach($validated as $v){
                $line=$v['line'];$pid=(int)$line['product_id'];$qty=$v['qty'];
                $restock=$kind==='commercial';
                if($restock){
                    $getProduct->execute([$pid,$biz]);$product=$getProduct->fetch();$getProduct->closeCursor();
                    if(!$product||(int)$product['is_active']!==1)fail('Producto inactivo; no puede volver a stock vendible. Revisar manualmente.',409);
                    if($product['sale_unit']!==$line['sale_unit'])fail('Cambió la unidad del producto; no se puede restituir stock automáticamente.',409);
                    $next=(float)$product['stock_qty']+$qty;
                    if($next>1000000000)fail('Stock resultante fuera de rango.',422);
                    $nextFormatted=number_format($next,3,'.','');
                    $nextFormatted=titoApplyLocationStock($db,$biz,$pid,$saleLocation,$qty);
                    // La etiqueta de devolución distingue este ajuste en el historial sin cambiar
                    // el ENUM actual de stock_movements; el stock retorna al local histórico de la venta.
                    move($db,$biz,$pid,null,$uid,'adjustment',number_format($qty,3,'.',''),$nextFormatted,'Devolución cliente #'.$returnId.' · Venta #'.$saleId.' · Producto apto para reventa');
                }
                $insertItem->execute([$returnId,(int)$line['id'],$pid,$v['qty_formatted'],(int)$line['unit_price_clp'],$v['amount'],$restock?1:0]);
            }
            $db->commit();
            return respond($response,['ok'=>true,'return_id'=>$returnId,'amount_clp'=>$sum,'restocked'=>$kind==='commercial','refund_status'=>$refundStatus],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    });

    $group->post('/returns/{id:[0-9]+}/confirm-refund',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$uid=(int)$admin['id'];$returnId=(int)$args['id'];
        $body=requestBody($request);
        if(array_diff(array_keys($body),['method','reference']))fail('Campos de reembolso no permitidos.',422);
        $method=$body['method']??null;
        if(!in_array($method,['efectivo','transferencia','tarjeta','otro'],true))fail('Medio de reembolso inválido.',422);
        $reference=$body['reference']??'';
        if(!is_string($reference)||mb_strlen(trim($reference))>120)fail('Referencia inválida.',422);
        $reference=trim($reference)?:null;
        $db=db();
        try{
            $db->beginTransaction();
            $st=$db->prepare('SELECT id,resolution,refund_status,refund_method,refund_reference,amount_clp FROM customer_returns WHERE id=? AND business_id=? FOR UPDATE');
            $st->execute([$returnId,$biz]);$r=$st->fetch();$st->closeCursor();
            if(!$r)fail('Devolución no encontrada.',404);
            if($r['resolution']!=='refund')fail('Este caso no contempla reembolso.',409);
            if($r['refund_status']==='confirmed'){
                if((string)$r['refund_method']!==$method||($r['refund_reference']??null)!==$reference)
                    fail('Reembolso confirmado con medio o referencia distintos; revisa el historial.',409);
                $db->commit();return respond($response,['ok'=>true,'return_id'=>$returnId,'already_recorded'=>true]);
            }
            $cashSessionId=titoCashSessionForReceipt($db,$admin,$request);
            $st=$db->prepare("UPDATE customer_returns SET refund_status='confirmed',refund_method=?,refund_reference=?,refunded_by=?,refund_cash_session_id=?,refunded_at=UTC_TIMESTAMP() WHERE id=? AND business_id=? AND refund_status='pending'");
            $st->execute([$method,$reference,$uid,$cashSessionId,$returnId,$biz]);
            if($st->rowCount()!==1)fail('Estado de reembolso inválido.',409);
            if($method==='efectivo'&&(int)$r['amount_clp']>0)
                titoCashRegisterExternal($db,$biz,$uid,$cashSessionId,'customer_refund',$returnId,(int)$r['amount_clp']);
            $db->commit();
            return respond($response,['ok'=>true,'return_id'=>$returnId,'refund_status'=>'confirmed'],200);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}

