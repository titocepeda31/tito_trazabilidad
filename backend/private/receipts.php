<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function receiptSupplierReady(PDO $db): void {
    $s=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='receipt_supplier_snapshots'");
    if((int)$s->fetchColumn()!==1)fail('Falta instalar la migración 008 de proveedores en recepciones.',503);
}
function receiptWarehouseReady(PDO $db): void {
    $s=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='receipt_warehouse_snapshots'");
    if((int)$s->fetchColumn()!==1)fail('Falta instalar la migración 010 de ubicación de recepción.',503);
}
function receiptSupplierAssociate(PDO $db,int $biz,int $pid,?int $sid): void {
    if($sid===null)return;
    // El producto y proveedor ya se verificaron en esta transacción y empresa.
    $s=$db->prepare('INSERT INTO product_suppliers(product_id,business_id,supplier_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE supplier_id=VALUES(supplier_id)');
    $s->execute([$pid,$biz,$sid]);
}
/** Recepciones de mercadería: solo administradores. Nunca cambia el precio de venta. */
function registerGoodsReceiptRoutes(RouteCollectorProxy $group): void {
    $group->get('/receipts/suppliers',static function(Request $request, Response $response): Response {
        $u=adminOnly($request);$db=db();receiptSupplierReady($db);
        $s=$db->prepare('SELECT id,name,tax_country,tax_id FROM suppliers WHERE business_id=? AND is_active=1 ORDER BY name,id');$s->execute([(int)$u['business_id']]);
        return respond($response,['suppliers'=>$s->fetchAll()]);
    });
    $group->get('/receipts/supplier-costs',static function(Request $request, Response $response): Response {
        $u=adminOnly($request);$db=db();receiptSupplierReady($db);$biz=(int)$u['business_id'];
        $q=$request->getQueryParams();
        $sid=filter_var($q['supplier_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $pid=isset($q['product_id'])&&$q['product_id']!==''?filter_var($q['product_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):null;
        if($sid===false||$pid===false||array_diff(array_keys($q),['supplier_id','product_id']))fail('Filtros de costo inválidos.',422);
        $s=$db->prepare('SELECT id FROM suppliers WHERE business_id=? AND id=?');$s->execute([$biz,$sid]);if(!$s->fetchColumn())fail('Proveedor no encontrado.',404);
        if($pid!==null){$s=$db->prepare('SELECT id FROM products WHERE business_id=? AND id=?');$s->execute([$biz,$pid]);if(!$s->fetchColumn())fail('Producto no encontrado.',404);}
        $sql='SELECT r.id AS receipt_id,r.received_at,r.document_type,r.document_number,h.supplier_name,i.id AS line_id,i.product_id,i.product_name_snapshot,p.sku,i.quantity,i.unit_cost_clp,i.line_total_clp FROM receipt_supplier_snapshots h JOIN goods_receipts r ON r.id=h.receipt_id AND r.business_id=h.business_id JOIN goods_receipt_items i ON i.receipt_id=r.id JOIN products p ON p.id=i.product_id AND p.business_id=r.business_id WHERE h.business_id=? AND h.supplier_id=?';
        $params=[$biz,$sid];if($pid!==null){$sql.=' AND i.product_id=?';$params[]=$pid;}
        $s=$db->prepare($sql.' ORDER BY r.received_at DESC,r.id DESC,i.id DESC LIMIT 100');$s->execute($params);
        return respond($response,['costs'=>$s->fetchAll()]);
    });
    $group->get('/receipts',static function(Request $request, Response $response): Response {
        $admin=adminOnly($request);
        $db=db();receiptSupplierReady($db);receiptWarehouseReady($db);
        $st=$db->prepare('SELECT r.id,r.supplier_name,h.supplier_id,h.tax_id AS supplier_tax_id,h.tax_country AS supplier_tax_country,loc.warehouse_id,loc.warehouse_name,loc.warehouse_code,r.document_type,r.document_number,r.received_at,r.total_cost_clp,r.created_at,r.entered_by,u.email AS entered_by_email,(SELECT COUNT(*) FROM goods_receipt_items i WHERE i.receipt_id=r.id) AS item_count FROM goods_receipts r JOIN users u ON u.id=r.entered_by LEFT JOIN receipt_supplier_snapshots h ON h.receipt_id=r.id AND h.business_id=r.business_id LEFT JOIN receipt_warehouse_snapshots loc ON loc.receipt_id=r.id AND loc.business_id=r.business_id WHERE r.business_id=? ORDER BY r.id DESC LIMIT 100');
        $st->execute([(int)$admin['business_id']]);
        return respond($response,['receipts'=>$st->fetchAll()]);
    });

    $group->get('/receipts/{id:[0-9]+}',static function(Request $request, Response $response,array $args): Response {
        $admin=adminOnly($request);
        $db=db();receiptSupplierReady($db);receiptWarehouseReady($db);$biz=(int)$admin['business_id'];$id=(int)$args['id'];
        $st=$db->prepare('SELECT r.id,r.supplier_name,h.supplier_id,h.tax_id AS supplier_tax_id,h.tax_country AS supplier_tax_country,loc.warehouse_id,loc.warehouse_name,loc.warehouse_code,r.document_type,r.document_number,r.received_at,r.total_cost_clp,r.created_at,r.entered_by,u.email AS entered_by_email FROM goods_receipts r JOIN users u ON u.id=r.entered_by LEFT JOIN receipt_supplier_snapshots h ON h.receipt_id=r.id AND h.business_id=r.business_id LEFT JOIN receipt_warehouse_snapshots loc ON loc.receipt_id=r.id AND loc.business_id=r.business_id WHERE r.id=? AND r.business_id=?');
        $st->execute([$id,$biz]);$receipt=$st->fetch();$st->closeCursor();
        if(!$receipt)fail('Recepción no encontrada.',404);
        $st=$db->prepare('SELECT i.id,i.product_id,i.product_name_snapshot,i.quantity,i.unit_cost_clp,i.line_total_clp,i.stock_before,i.stock_after,p.sku FROM goods_receipt_items i JOIN products p ON p.id=i.product_id WHERE i.receipt_id=? AND p.business_id=? ORDER BY i.id');
        $st->execute([$id,$biz]);
        $items=$st->fetchAll();$s=$db->prepare('SELECT product_id,serial_number FROM serial_units WHERE business_id=? AND receipt_id=? ORDER BY serial_number');$s->execute([$biz,$id]);$map=[];foreach($s->fetchAll() as $r)$map[$r['product_id']][]=$r['serial_number'];foreach($items as &$i)$i['serial_numbers']=$map[$i['product_id']]??[];unset($i);
        return respond($response,['receipt'=>$receipt,'items'=>$items]);
    });

    $group->post('/receipts',static function(Request $request, Response $response): Response {
        $admin=adminOnly($request);
        $db=db();$biz=(int)$admin['business_id'];$uid=(int)$admin['id'];
        receiptSupplierReady($db);receiptWarehouseReady($db);$b=requestBody($request);
        foreach(array_keys($b) as $key){if(!in_array($key,['request_id','supplier_id','supplier_name','warehouse_id','document_type','document_number','received_at','items'],true))fail('Campo desconocido: '.$key,422);}
        $rid=$b['request_id']??null;
        if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$rid))fail('Identificador de recepción inválido.',422);
        // ID para nuevas recepciones. Conserva el protocolo de intentos heredados pendientes.
        $supplierId=array_key_exists('supplier_id',$b)?originId($b['supplier_id'],'supplier_id'):null;
        if(array_key_exists('supplier_id',$b)&&$supplierId===null)throw new HttpError('Selecciona un proveedor.',422,'supplier_id');
        if($supplierId!==null&&array_key_exists('supplier_name',$b))fail('Usa el identificador de proveedor o el nombre heredado, no ambos.',422);
        $supplier=$supplierId===null?requireString($b,'supplier_name',150):'';
        $requestedWarehouse=array_key_exists('warehouse_id',$b)?originId($b['warehouse_id'],'warehouse_id'):null;
        if(array_key_exists('warehouse_id',$b)&&$requestedWarehouse===null)throw new HttpError('Selecciona una bodega.',422,'warehouse_id');

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
            $allowed=['product_id','new_product','quantity','unit_cost_clp','serial_numbers'];
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
                if(array_key_exists('serial_numbers',$item))$specs[$pid]['serial_numbers']=serialNormalizeList($item['serial_numbers']);
            }else{
                if(array_key_exists('serial_numbers',$item))fail('Crea primero el producto y activa su control de serie.',422);
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
        if($supplierId!==null){unset($canonical['supplier_name']);$canonical=['supplier_id'=>$supplierId]+$canonical;}
        if($requestedWarehouse!==null)$canonical['warehouse_id']=$requestedWarehouse;
        $hash=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        try{
            $db->beginTransaction();
            originLockCompany($db,$biz);
            // Reintento confirmado antes de validar proveedor activo o precios actuales.
            $st=$db->prepare('SELECT id,payload_hash,total_cost_clp FROM goods_receipts WHERE business_id=? AND request_id=? FOR UPDATE');
            $st->execute([$biz,strtolower($rid)]);$prior=$st->fetch();$st->closeCursor();
            if($prior){
                if(!hash_equals((string)$prior['payload_hash'],$hash))fail('El identificador ya fue utilizado con datos diferentes.',409);
                $db->commit();return respond($response,['ok'=>true,'receipt_id'=>(int)$prior['id'],'total_cost_clp'=>(int)$prior['total_cost_clp'],'already_recorded'=>true]);
            }
            $warehouseId=$requestedWarehouse??titoLocalLocationId($db,$biz);
            $s=$db->prepare('SELECT id,name,code FROM warehouses WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');$s->execute([$biz,$warehouseId]);$warehouse=$s->fetch();$s->closeCursor();
            if(!$warehouse)throw new HttpError('Selecciona una bodega activa de tu empresa.',422,'warehouse_id');
            $supplierRow=null;
            if($supplierId!==null){$supplierRow=originRow($db,'suppliers',$biz,$supplierId,'supplier_id');$supplier=$supplierRow['name'];}
            titoProductCreationActor($db,$biz,$uid,'mercaderia');
            $st=$db->prepare('INSERT INTO goods_receipts(business_id,entered_by,request_id,payload_hash,supplier_name,document_type,document_number,received_at,total_cost_clp) VALUES(?,?,?,?,?,?,?,?,0)');
            $st->execute([$biz,$uid,strtolower($rid),$hash,$supplier,$dtype,$doc,$date]);
            $receiptId=(int)$db->lastInsertId();
            $s=$db->prepare('INSERT INTO receipt_warehouse_snapshots(receipt_id,business_id,warehouse_id,warehouse_name,warehouse_code) VALUES(?,?,?,?,?)');$s->execute([$receiptId,$biz,$warehouseId,$warehouse['name'],$warehouse['code']]);
            if($supplierRow){$st=$db->prepare('INSERT INTO receipt_supplier_snapshots(receipt_id,business_id,supplier_id,supplier_name,tax_country,tax_id) VALUES(?,?,?,?,?,?)');$st->execute([$receiptId,$biz,$supplierId,$supplier,$supplierRow['tax_country'],$supplierRow['tax_id']]);}
            $lock=$db->prepare('SELECT id,name,stock_qty,sale_unit FROM products WHERE id=? AND business_id=? AND is_active=1 FOR UPDATE');
            $insert=$db->prepare('INSERT INTO goods_receipt_items(receipt_id,product_id,product_name_snapshot,quantity,unit_cost_clp,line_total_clp,stock_before,stock_after) VALUES(?,?,?,?,?,?,?,?)');
            $total=0;
            foreach($specs as $pid=>$spec){
                $lock->execute([$pid,$biz]);$product=$lock->fetch();$lock->closeCursor();
                if(!$product)fail('Producto inexistente o inactivo: '.$pid,422);
                $qty=decimalQuantity($spec['quantity'],false,$product['sale_unit']!=='unidad');
                if((float)$qty<=0)fail('Cantidad debe ser mayor que cero.',422);
                $prior=(float)$product['stock_qty'];
                $controlled=serialControlled($db,$biz,$pid);$numbers=$spec['serial_numbers']??[];
                if($controlled){
                    if($supplierId===null||$product['sale_unit']!=='unidad'||(float)$qty!== (float)count($numbers))throw new HttpError('Para recibir '.$product['name'].' selecciona proveedor y registra una serie por unidad.',422,'serial_numbers');
                    serialReconcile($db,$biz,$pid,$prior);
                }elseif(array_key_exists('serial_numbers',$spec))fail('Activa el control de serie para este producto antes de recibir series.',422);
                $after=$prior+(float)$qty;
                if(!is_finite($after)||$after>1000000000)fail('Stock excede el máximo permitido.',422);
                $previous=number_format($prior,3,'.','');
                $balance=number_format($after,3,'.','');
                $line=(int)round((float)$qty*$spec['unit_cost_clp'],0,PHP_ROUND_HALF_UP);
                $total+=$line;
                if($total>1000000000000)fail('Recepción demasiado grande.',422);
                $balance=titoReceiveLocationStock($db,$biz,$pid,$warehouseId,(float)$qty,$controlled);
                $insert->execute([$receiptId,$pid,$product['name'],$qty,$spec['unit_cost_clp'],$line,$previous,$balance]);
                if($controlled){serialReceive($db,$admin,$pid,$receiptId,$supplierId,$warehouseId,$spec['unit_cost_clp'],$date,$numbers);serialReconcile($db,$biz,$pid,$after);}
                receiptSupplierAssociate($db,$biz,$pid,$supplierId);
                move($db,$biz,$pid,null,$uid,'receipt',$qty,$balance,mb_substr('Recepción #'.$receiptId.' · '.$supplier.' · '.$warehouse['name'],0,250));
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
                $balance=titoReceiveLocationStock($db,$biz,$newId,$warehouseId,$value,false);
                $insert->execute([$receiptId,$newId,$np['name'],$qty,$spec['unit_cost_clp'],$line,'0.000',$balance]);
                receiptSupplierAssociate($db,$biz,$newId,$supplierId);
                move($db,$biz,$newId,null,$uid,'receipt',$qty,$balance,mb_substr('Recepción #'.$receiptId.' · '.$supplier.' · '.$warehouse['name'],0,250));
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
