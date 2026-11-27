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

    // Search current business only; an exact serial resolves to its latest unreversed sale.
    $group->get('/returns/assessments',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];
        $st=db()->prepare('SELECT a.id,a.sale_id,a.outcome,a.observations,a.evidence_reference,a.custody,a.items_json,a.created_at,u.email AS actor_email FROM customer_return_assessments a JOIN users u ON u.id=a.created_by AND u.business_id=a.business_id WHERE a.business_id=? ORDER BY a.id DESC LIMIT 100');
        $st->execute([$biz]);$rows=$st->fetchAll();
        foreach($rows as &$r){$r['items']=json_decode($r['items_json'],true,512,JSON_THROW_ON_ERROR);unset($r['items_json']);}unset($r);
        return respond($response,['assessments'=>$rows]);
    });

    $group->post('/sales/{id:[0-9]+}/return-assessments',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$uid=(int)$admin['id'];$saleId=(int)$args['id'];
        $body=requestBody($request);
        if(array_diff(array_keys($body),['request_id','outcome','observations','evidence_reference','custody','inspection_confirmed','items']))fail('Campos de evaluación no permitidos.',422);
        $rid=$body['request_id']??null;
        if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$rid))fail('Identificador inválido.',422);
        $rid=strtolower($rid);$outcome=$body['outcome']??null;$custody=$body['custody']??null;
        if(!in_array($outcome,['pending_review','rejected','technical_service'],true))fail('Resultado inválido.',422);
        if(!in_array($custody,['customer','evaluation'],true))fail('Indica quién conserva el producto.',422);
        if($outcome==='rejected'&&$custody!=='customer')fail('Para cerrar el rechazo, registra la entrega del producto al cliente.',422);
        if(($body['inspection_confirmed']??false)!==true)fail('Confirma la inspección y la situación registrada.',422);
        $obs=requireString($body,'observations',1000);if(mb_strlen(trim($obs))<10)fail('Describe la evaluación con al menos 10 caracteres.',422);
        $ref=$body['evidence_reference']??'';if(!is_string($ref)||!mb_check_encoding($ref,'UTF-8')||mb_strlen(trim($ref))>250)fail('Referencia de evidencia inválida.',422);$ref=trim($ref);
        if($outcome==='rejected'&&$ref==='')fail('Indica la referencia de evidencia o informe técnico que sustenta el rechazo.',422);
        $rows=$body['items']??null;
        if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>80)fail('Selecciona entre 1 y 80 productos.',422);
        $wanted=[];
        foreach($rows as $r){
            if(!is_array($r)||array_diff(array_keys($r),['sale_item_id','quantity','serial_numbers']))fail('Producto inválido.',422);
            $line=filter_var($r['sale_item_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $qty=$r['quantity']??null;
            if($line===false||isset($wanted[$line])||(!is_int($qty)&&!is_float($qty))||!is_finite((float)$qty)||$qty<=0||$qty>1000000000||abs($qty*1000-round($qty*1000))>0.000001)fail('Cantidad o línea inválida.',422);
            $numbers=array_key_exists('serial_numbers',$r)?serialNormalizeList($r['serial_numbers']):[];sort($numbers,SORT_STRING);
            $wanted[$line]=['quantity'=>number_format($qty,3,'.',''),'serial_numbers'=>$numbers];
        }
        ksort($wanted,SORT_NUMERIC);
        $hash=hash('sha256',json_encode([$saleId,$outcome,$custody,$obs,$ref,$wanted],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();try{
            $db->beginTransaction();originLockCompany($db,$biz);
            $st=$db->prepare('SELECT id,status FROM sales WHERE id=? AND business_id=? FOR UPDATE');$st->execute([$saleId,$biz]);$sale=$st->fetch();$st->closeCursor();if(!$sale)fail('Venta no encontrada.',404);
            $st=$db->prepare('SELECT id,payload_hash FROM customer_return_assessments WHERE business_id=? AND request_id=?');$st->execute([$biz,$rid]);$previous=$st->fetch();$st->closeCursor();
            if($previous){if(!hash_equals((string)$previous['payload_hash'],$hash))fail('Identificador reutilizado con datos distintos.',409);$db->commit();return respond($response,['assessment_id'=>(int)$previous['id'],'already_recorded'=>true]);}
            if($sale['status']!=='completed')fail('La venta debe estar vigente.',409);
            $st=$db->prepare('SELECT id FROM credit_accounts WHERE business_id=? AND sale_id=?');$st->execute([$biz,$saleId]);if($st->fetchColumn())fail('Venta a crédito: usa su flujo correspondiente.',409);$st->closeCursor();
            $st=$db->prepare('SELECT si.id,si.product_name_snapshot,si.quantity,p.sale_unit,COALESCE(SUM(ri.quantity),0) AS returned_qty FROM sale_items si JOIN products p ON p.id=si.product_id AND p.business_id=? LEFT JOIN customer_return_items ri ON ri.sale_item_id=si.id WHERE si.sale_id=? GROUP BY si.id,si.product_name_snapshot,si.quantity,p.sale_unit');$st->execute([$biz,$saleId]);$lines=[];foreach($st->fetchAll() as $line)$lines[(int)$line['id']]=$line;$st->closeCursor();
            $items=[];
            foreach($wanted as $lineId=>$row){
                $line=$lines[$lineId]??null;$qty=(float)$row['quantity'];$numbers=$row['serial_numbers'];
                if(!$line||$qty>(float)$line['quantity']-(float)$line['returned_qty']+0.000001||($line['sale_unit']==='unidad'&&floor($qty)!==$qty))fail('Cantidad no disponible en la venta.',409);
                $units=serialSaleLineUnits($db,$biz,$saleId,$lineId);
                if($units){
                    if($qty!==(float)count($numbers))fail('Selecciona una serie por unidad.',422);
                    $valid=[];foreach($units as $u){if($u['status']==='sold'&&!$u['reversal_kind'])$valid[]=$u['serial_number'];}
                    foreach($numbers as $number)if(!in_array($number,$valid,true))fail('Serie no disponible en esta venta.',409);
                }elseif($numbers)fail('La línea no tiene series.',422);
                $items[]=['sale_item_id'=>$lineId,'product_name'=>$line['product_name_snapshot'],'quantity'=>$row['quantity'],'serial_numbers'=>$numbers];
            }
            // No return, financial movement, stock or serial status mutation.
            $st=$db->prepare('INSERT INTO customer_return_assessments(business_id,sale_id,created_by,request_id,payload_hash,outcome,observations,evidence_reference,custody,items_json) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$biz,$saleId,$uid,$rid,$hash,$outcome,$obs,$ref===''?null:$ref,$custody,json_encode($items,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
            $id=(int)$db->lastInsertId();$db->commit();return respond($response,['assessment_id'=>$id],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });

    $group->get('/returns/search',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];
        $q=$request->getQueryParams()['q']??'';
        if(!is_string($q)||!mb_check_encoding($q,'UTF-8')||mb_strlen(trim($q))<1||mb_strlen(trim($q))>120)fail('Ingresa un código, serie, cliente o número de venta válido.',422);
        $q=trim($q);
        $serialSql="SELECT s.id AS sale_id,s.created_at,s.total_clp,ss.client_name,si.product_name_snapshot AS product_name,x.serial_number,su.sale_item_id
            FROM serial_units x JOIN serial_sale_units su ON su.unit_id=x.id AND su.business_id=x.business_id
            JOIN sales s ON s.id=su.sale_id AND s.business_id=su.business_id
            JOIN sale_items si ON si.id=su.sale_item_id AND si.sale_id=s.id
            JOIN serial_sale_snapshots ss ON ss.sale_id=s.id AND ss.business_id=s.business_id
            WHERE x.business_id=? AND x.serial_number=? AND x.status='sold' AND x.warehouse_id=su.warehouse_id AND s.status='completed'
            AND NOT EXISTS(SELECT 1 FROM serial_sale_reversals rr WHERE rr.unit_id=su.unit_id AND rr.sale_id=su.sale_id)
            AND NOT EXISTS(SELECT 1 FROM serial_sale_units later WHERE later.unit_id=su.unit_id AND later.sale_id>su.sale_id)
            AND NOT EXISTS(SELECT 1 FROM credit_accounts ca WHERE ca.business_id=s.business_id AND ca.sale_id=s.id)
            ORDER BY s.id DESC,x.id LIMIT 51";
        $st=db()->prepare($serialSql);$st->execute([$biz,$q]);$series=$st->fetchAll();$st->closeCursor();
        $saleCode=ltrim($q,'#');
        $saleSql="SELECT s.id AS sale_id,s.created_at,s.total_clp,COALESCE(s.client_name_snapshot,ss.client_name) AS client_name
            FROM sales s LEFT JOIN serial_sale_snapshots ss ON ss.sale_id=s.id AND ss.business_id=s.business_id
            WHERE s.business_id=? AND s.status='completed'
            AND NOT EXISTS(SELECT 1 FROM credit_accounts ca WHERE ca.business_id=s.business_id AND ca.sale_id=s.id)
            AND (CAST(s.id AS CHAR)=? OR LOCATE(?,COALESCE(s.client_name_snapshot,ss.client_name,''))>0
            OR EXISTS(SELECT 1 FROM sale_items si JOIN products p ON p.id=si.product_id AND p.business_id=s.business_id
                WHERE si.sale_id=s.id AND (p.sku=? OR p.barcode=? OR LOCATE(?,si.product_name_snapshot)>0)))
            ORDER BY s.id DESC LIMIT 51";
        $st=db()->prepare($saleSql);$st->execute([$biz,$saleCode,$q,$q,$q,$q]);$sales=$st->fetchAll();$st->closeCursor();
        return respond($response,['series'=>array_slice($series,0,50),'sales'=>array_slice($sales,0,50),'more'=>count($series)>50||count($sales)>50]);
    });

    $group->get('/sales/{id:[0-9]+}/returns',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$saleId=(int)$args['id'];
        $st=db()->prepare('SELECT s.id,s.status,s.created_at,s.total_clp,COALESCE(s.client_name_snapshot,ss.client_name) AS client_name FROM sales s LEFT JOIN serial_sale_snapshots ss ON ss.sale_id=s.id AND ss.business_id=s.business_id WHERE s.id=? AND s.business_id=?');
        $st->execute([$saleId,$biz]);$sale=$st->fetch();
        if(!$sale)fail('Venta no encontrada.',404);
        $st=db()->prepare('SELECT si.id AS sale_item_id,si.product_id,si.product_name_snapshot,si.quantity,si.unit_price_clp,si.subtotal_clp,p.sale_unit,COALESCE(SUM(ri.quantity),0) AS returned_qty,COALESCE(SUM(ri.amount_clp),0) AS returned_amount_clp FROM sale_items si JOIN products p ON p.id=si.product_id AND p.business_id=? LEFT JOIN customer_return_items ri ON ri.sale_item_id=si.id WHERE si.sale_id=? GROUP BY si.id,si.product_id,si.product_name_snapshot,si.quantity,si.unit_price_clp,si.subtotal_clp,p.sale_unit ORDER BY si.id');
        $st->execute([$biz,$saleId]);$items=$st->fetchAll();$st->closeCursor();
        foreach($items as &$item)$item['serial_units']=serialSaleLineUnits(db(),$biz,$saleId,(int)$item['sale_item_id']);unset($item);
        $st=db()->prepare('SELECT r.id,r.return_kind,r.item_condition,r.resolution,r.reason,r.amount_clp,r.refund_status,r.refund_method,r.refund_reference,r.created_at,r.refunded_at,u.email AS created_by_email FROM customer_returns r JOIN users u ON u.id=r.created_by AND u.business_id=r.business_id WHERE r.sale_id=? AND r.business_id=? ORDER BY r.id DESC');
        $st->execute([$saleId,$biz]);$returns=$st->fetchAll();$st->closeCursor();
        $s=db()->prepare('SELECT rr.return_id,x.serial_number FROM serial_sale_reversals rr JOIN serial_units x ON x.id=rr.unit_id AND x.business_id=rr.business_id WHERE rr.business_id=? AND rr.sale_id=? AND rr.return_id IS NOT NULL ORDER BY x.serial_number');$s->execute([$biz,$saleId]);$map=[];foreach($s->fetchAll() as $r)$map[$r['return_id']][]=$r['serial_number'];foreach($returns as &$r)$r['serial_numbers']=$map[$r['id']]??[];unset($r);
        return respond($response,['sale_id'=>$saleId,'sale_status'=>$sale['status'],'sale_created_at'=>$sale['created_at'],'sale_total_clp'=>$sale['total_clp'],'client_name'=>$sale['client_name'],'items'=>$items,'returns'=>$returns]);
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
        $wanted=[];$serialSpecs=[];
        foreach($items as $row){
            if(!is_array($row)||array_diff(array_keys($row),['sale_item_id','quantity','serial_numbers']))fail('Producto de devolución no válido.',422);
            $lineId=filter_var($row['sale_item_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($lineId===false||isset($wanted[$lineId]))fail('Producto repetido o inválido.',422);
            $raw=$row['quantity']??null;
            if(!is_int($raw)&&!is_float($raw))fail('Cantidad de devolución inválida.',422);
            $qty=(float)$raw;
            if(!is_finite($qty)||$qty<=0||$qty>1000000000||abs($qty*1000-round($qty*1000))>0.000001)fail('Cantidad fuera de rango.',422);
            $wanted[$lineId]=number_format($qty,3,'.','');
            if(array_key_exists('serial_numbers',$row))$serialSpecs[$lineId]=serialNormalizeList($row['serial_numbers']);
        }
        ksort($wanted,SORT_NUMERIC);
        $payload=['sale_id'=>$saleId,'return_kind'=>$kind,'item_condition'=>$condition,'resolution'=>$resolution,'reason'=>$reason,'inspection_confirmed'=>true,'items'=>$wanted];
        if($serialSpecs){ksort($serialSpecs,SORT_NUMERIC);$payload['serial_numbers']=$serialSpecs;}
        $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();
        try{
            $db->beginTransaction();originLockCompany($db,$biz);
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
            $credit=$db->prepare('SELECT id FROM credit_accounts WHERE business_id=? AND sale_id=? LIMIT 1');$credit->execute([$biz,$saleId]);if($credit->fetchColumn())fail('Usa la devolución de cuentas por cobrar para esta venta a crédito.',409);$credit->closeCursor();
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
                $units=serialSaleLineUnits($db,$biz,$saleId,$lineId);$numbers=$serialSpecs[$lineId]??[];
                if($units){if(count($units)!==(int)$original||$qty!==(float)count($numbers))throw new HttpError('Selecciona una serie por unidad devuelta.',422,'serial_numbers');}
                elseif(array_key_exists($lineId,$serialSpecs))fail('Esta línea no tiene series registradas.',422);
                $oldQty=(float)($already[$lineId]['qty']??0);
                $remaining=$original-$oldQty;
                if($qty>$remaining+0.000001||$remaining<=0)fail('Cantidad excede lo vendido y todavía no devuelto.',409);
                if($line['sale_unit']==='unidad'&&floor($qty)!==$qty)fail('El producto se devuelve en unidades enteras.',422);
                $oldAmount=(int)($already[$lineId]['amount_clp']??0);
                $maxAmount=(int)$line['subtotal_clp']-$oldAmount;
                if($maxAmount<0)fail('Inconsistencia de importes previos.',409);
                $amount=$qty>=$remaining-0.000001?$maxAmount:
                    min($maxAmount,(int)round($qty*(int)$line['unit_price_clp'],0,PHP_ROUND_HALF_UP));
                $validated[]=['line'=>$line,'qty'=>$qty,'qty_formatted'=>$rawQty,'amount'=>$amount,'numbers'=>$numbers];
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
                $restock=$kind==='commercial';$numbers=$v['numbers'];
                if($numbers){
                    $getProduct->execute([$pid,$biz]);$p=$getProduct->fetch();$getProduct->closeCursor();
                    if(!$p||$p['sale_unit']!=='unidad'||!serialControlled($db,$biz,$pid)||($restock&&(int)$p['is_active']!==1))fail('Producto serializado no disponible para esta devolución.',409);
                    serialReconcile($db,$biz,$pid,(float)$p['stock_qty']);
                    serialRestoreUnits($db,$admin,$saleId,(int)$line['id'],$pid,$saleLocation,$numbers,$kind,$returnId,$reason);
                    if($restock){$balance=titoRestoreSerialLocationStock($db,$biz,$pid,$saleLocation,$qty);move($db,$biz,$pid,null,$uid,'adjustment',number_format($qty,3,'.',''),$balance,'Devolución serializada #'.$returnId.' · Venta #'.$saleId);}
                    serialReconcile($db,$biz,$pid,$restock?(float)$balance:(float)$p['stock_qty']);
                }elseif($restock){
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

