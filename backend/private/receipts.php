<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Recepciones de mercadería: solo administradores. Nunca cambia el precio de venta. */
function registerGoodsReceiptRoutes(RouteCollectorProxy $group): void {
    $group->get('/receipts',static function(Request $request, Response $response): Response {
        $admin=adminOnly($request);
        $db=db();
        $st=$db->prepare('SELECT r.id,r.supplier_name,r.document_type,r.document_number,r.received_at,r.total_cost_clp,r.created_at,r.entered_by,u.email AS entered_by_email,(SELECT COUNT(*) FROM goods_receipt_items i WHERE i.receipt_id=r.id) AS item_count FROM goods_receipts r JOIN users u ON u.id=r.entered_by WHERE r.business_id=? ORDER BY r.id DESC LIMIT 100');
        $st->execute([(int)$admin['business_id']]);
        return respond($response,['receipts'=>$st->fetchAll()]);
    });

    $group->get('/receipts/{id:[0-9]+}',static function(Request $request, Response $response,array $args): Response {
        $admin=adminOnly($request);
        $db=db();$biz=(int)$admin['business_id'];$id=(int)$args['id'];
        $st=$db->prepare('SELECT r.id,r.supplier_name,r.document_type,r.document_number,r.received_at,r.total_cost_clp,r.created_at,r.entered_by,u.email AS entered_by_email FROM goods_receipts r JOIN users u ON u.id=r.entered_by WHERE r.id=? AND r.business_id=?');
        $st->execute([$id,$biz]);$receipt=$st->fetch();$st->closeCursor();
        if(!$receipt)fail('Recepción no encontrada.',404);
        $st=$db->prepare('SELECT i.id,i.product_id,i.product_name_snapshot,i.quantity,i.unit_cost_clp,i.line_total_clp,i.stock_before,i.stock_after,p.sku FROM goods_receipt_items i JOIN products p ON p.id=i.product_id WHERE i.receipt_id=? AND p.business_id=? ORDER BY i.id');
        $st->execute([$id,$biz]);
        return respond($response,['receipt'=>$receipt,'items'=>$st->fetchAll()]);
    });

    $group->post('/receipts',static function(Request $request, Response $response): Response {
        $admin=adminOnly($request);
        $db=db();$biz=(int)$admin['business_id'];$uid=(int)$admin['id'];
        $b=requestBody($request);
        foreach(array_keys($b) as $key){if(!in_array($key,['request_id','supplier_name','document_type','document_number','received_at','items'],true))fail('Campo desconocido: '.$key,422);}
        $rid=$b['request_id']??null;
        if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$rid))fail('Identificador de recepción inválido.',422);
        $supplier=requireString($b,'supplier_name',150);
        $dtype=$b['document_type']??'sin_documento';
        if(!in_array($dtype,['factura','guia','boleta','sin_documento'],true))fail('Tipo de documento inválido.',422);
        $doc=$b['document_number']??null;
        if($doc!==null && !is_string($doc))fail('Número de documento inválido.',422);
        $doc=$doc===null?null:(trim($doc)===''?null:trim($doc));
        if($doc!==null && mb_strlen($doc)>80)fail('Número de documento demasiado largo.',422);
        if($dtype==='sin_documento' && $doc!==null)fail('Selecciona el tipo de documento.',422);
        if($dtype!=='sin_documento' && $doc===null)fail('Indica el número del documento.',422);
        $date=$b['received_at']??null;
        if(!is_string($date)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$parts)||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]))fail('Fecha de recepción inválida.',422);
        $items=$b['items']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>80)fail('La recepción debe contener entre 1 y 80 productos.',422);
        // Each line is EITHER an existing product or a new catalog product. The
        // entire reception (catalog creation, stock and audit) is atomic.
        $specs=[];$newSpecs=[];
        foreach($items as $item){
            if(!is_array($item))fail('Línea de recepción inválida.',422);
            $allowed=['product_id','new_product','quantity','unit_cost_clp'];
            if(array_diff(array_keys($item),$allowed))fail('Campos de producto inválidos.',422);
            $existing=array_key_exists('product_id',$item);
            $creating=array_key_exists('new_product',$item);
            if($existing===$creating)fail('Selecciona un producto existente o define uno nuevo, no ambos.',422);
            $cost=integerPrice($item['unit_cost_clp']??null);
            if($cost>1000000000)fail('Costo excesivo.',422);
            if($existing){
                $pid=filter_var($item['product_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if($pid===false||isset($specs[$pid]))fail('Producto duplicado o inválido.',422);
                $specs[$pid]=['quantity'=>$item['quantity']??null,'unit_cost_clp'=>$cost];
            }else{
                $product=$item['new_product'];
                if(!is_array($product)||array_diff(array_keys($product),['name','sku','barcode','price_clp','sale_unit']))fail('Datos del nuevo producto inválidos.',422);
                $name=requireString($product,'name',120);
                $sku=$product['sku']??null;
                $barcode=$product['barcode']??null;
                foreach(['sku'=>&$sku,'barcode'=>&$barcode] as $field=>&$value){
                    if($value!==null && !is_string($value))fail('Código inválido: '.$field,422);
                    $value=$value===null?null:(trim($value)?:null);
                    if($value!==null&&mb_strlen($value)>50)fail('Código demasiado largo: '.$field,422);
                }
                unset($value);
                $price=integerPrice($product['price_clp']??null);
                if($price>1000000000)fail('Precio de venta excesivo.',422);
                $unit=$product['sale_unit']??'unidad';
                if(!in_array($unit,['unidad','kg','litro'],true))fail('Unidad inválida.',422);
                $qty=decimalQuantity($item['quantity']??null,false,$unit!=='unidad');
                if((float)$qty<=0)fail('La cantidad recibida debe superar cero.',422);
                // A named product may exist already with a different (or absent) code.
                // Refuse rather than silently creating two catalog entries.
                $newSpecs[]=['new_product'=>[
                    'name'=>$name,'sku'=>$sku,'barcode'=>$barcode,
                    'price_clp'=>$price,'sale_unit'=>$unit
                ],'quantity'=>$qty,'unit_cost_clp'=>$cost];
            }
        }
        ksort($specs,SORT_NUMERIC);
        $canonical=['supplier_name'=>$supplier,'document_type'=>$dtype,'document_number'=>$doc,'received_at'=>$date,'existing_items'=>$specs,'new_items'=>$newSpecs];
        $hash=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        try{
            $db->beginTransaction();
            titoProductCreationActor($db,$biz,$uid,'mercaderia');
            $st=$db->prepare('INSERT INTO goods_receipts(business_id,entered_by,request_id,payload_hash,supplier_name,document_type,document_number,received_at,total_cost_clp) VALUES(?,?,?,?,?,?,?,?,0)');
            $st->execute([$biz,$uid,strtolower($rid),$hash,$supplier,$dtype,$doc,$date]);
            $receiptId=(int)$db->lastInsertId();
            $lock=$db->prepare('SELECT id,name,stock_qty,sale_unit FROM products WHERE id=? AND business_id=? AND is_active=1 FOR UPDATE');
            $upd=$db->prepare('UPDATE products SET stock_qty=? WHERE id=? AND business_id=?');
            $insert=$db->prepare('INSERT INTO goods_receipt_items(receipt_id,product_id,product_name_snapshot,quantity,unit_cost_clp,line_total_clp,stock_before,stock_after) VALUES(?,?,?,?,?,?,?,?)');
            $total=0;
            foreach($specs as $pid=>$spec){
                $lock->execute([$pid,$biz]);$product=$lock->fetch();$lock->closeCursor();
                if(!$product)fail('Producto inexistente o inactivo: '.$pid,422);
                $qty=decimalQuantity($spec['quantity'],false,$product['sale_unit']!=='unidad');
                if((float)$qty<=0)fail('Cantidad debe ser mayor que cero.',422);
                $prior=(float)$product['stock_qty'];
                $after=$prior+(float)$qty;
                if(!is_finite($after)||$after>1000000000)fail('Stock excede el máximo permitido.',422);
                $previous=number_format($prior,3,'.','');
                $balance=number_format($after,3,'.','');
                $line=(int)round((float)$qty*$spec['unit_cost_clp'],0,PHP_ROUND_HALF_UP);
                $total+=$line;
                if($total>1000000000000)fail('Recepción demasiado grande.',422);
                $upd->execute([$balance,$pid,$biz]);
                $insert->execute([$receiptId,$pid,$product['name'],$qty,$spec['unit_cost_clp'],$line,$previous,$balance]);
                move($db,$biz,$pid,null,$uid,'receipt',$qty,$balance,mb_substr('Recepción #'.$receiptId.' · '.$supplier,0,250));
            }
            // Products created from the reception start at zero and get stock
            // through the SAME receipt line and inventory movement as existing ones.
            $newProductIds=[];
            $checkName=$db->prepare('SELECT id FROM products WHERE business_id=? AND name=? LIMIT 1');
            $checkCodes=$db->prepare('SELECT id FROM products WHERE business_id=? AND ((? IS NOT NULL AND sku=?) OR (? IS NOT NULL AND barcode=?)) LIMIT 1');
            $create=$db->prepare('INSERT INTO products(business_id,name,sku,barcode,price_clp,sale_unit,stock_qty) VALUES (?,?,?,?,?,?,0)');
            $assignSku=$db->prepare('UPDATE products SET sku=? WHERE id=? AND business_id=?');
            foreach($newSpecs as $spec){
                $np=$spec['new_product'];
                $checkName->execute([$biz,$np['name']]);$matched=$checkName->fetchColumn();$checkName->closeCursor();
                if($matched!==false)fail('Ya existe un producto con el nombre '.$np['name'].'. Selecciónalo de Inventario.',409);
                if($np['sku']!==null||$np['barcode']!==null){
                    $checkCodes->execute([$biz,$np['sku'],$np['sku'],$np['barcode'],$np['barcode']]);
                    $matchedCode=$checkCodes->fetchColumn();$checkCodes->closeCursor();
                    if($matchedCode!==false)fail('SKU o código de barras ya registrado. Selecciona el producto existente.',409);
                }
                $create->execute([$biz,$np['name'],$np['sku'],$np['barcode'],$np['price_clp'],$np['sale_unit']]);
                $newId=(int)$db->lastInsertId();
                if($np['sku']===null&&$np['barcode']===null){
                    $internalSku='TPOS-'.$biz.'-'.$newId;
                    $assignSku->execute([$internalSku,$newId,$biz]);
                }
                $qty=$spec['quantity'];
                $value=(float)$qty;
                if(!is_finite($value)||$value>1000000000)fail('Stock nuevo excede el máximo.',422);
                $balance=number_format($value,3,'.','');
                $line=(int)round($value*$spec['unit_cost_clp'],0,PHP_ROUND_HALF_UP);
                $total+=$line;
                if($total>1000000000000)fail('Recepción demasiado grande.',422);
                $upd->execute([$balance,$newId,$biz]);
                $insert->execute([$receiptId,$newId,$np['name'],$qty,$spec['unit_cost_clp'],$line,'0.000',$balance]);
                move($db,$biz,$newId,null,$uid,'receipt',$qty,$balance,mb_substr('Recepción #'.$receiptId.' · '.$supplier,0,250));
                $newProductIds[]=$newId;
            }
            $st=$db->prepare('UPDATE goods_receipts SET total_cost_clp=? WHERE id=? AND business_id=?');
            $st->execute([$total,$receiptId,$biz]);
            $db->commit();
            return respond($response,['ok'=>true,'receipt_id'=>$receiptId,'total_cost_clp'=>$total,'received_items'=>count($specs)+count($newSpecs),'new_products_created'=>count($newSpecs)],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062){
                $st=$db->prepare('SELECT id,payload_hash,total_cost_clp FROM goods_receipts WHERE business_id=? AND request_id=?');
                $st->execute([$biz,strtolower($rid)]);$prior=$st->fetch();
                if($prior){
                    if(!hash_equals((string)$prior['payload_hash'],$hash))fail('El identificador ya fue utilizado con datos diferentes.',409);
                    return respond($response,['ok'=>true,'receipt_id'=>(int)$prior['id'],'total_cost_clp'=>(int)$prior['total_cost_clp'],'already_recorded'=>true],200);
                }
                fail('Documento de recepción o código de producto duplicado. Revisa los datos.',409);
            }
            throw $e;
        }
    });
}
