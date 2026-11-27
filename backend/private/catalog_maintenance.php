<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Edición sin tocar cantidades, auditada por administrador. */
function registerCatalogMaintenanceRoutes(RouteCollectorProxy $group): void {
    $group->post('/products/{id:[0-9]+}/update', static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);
        $biz=(int)$admin['business_id']; $uid=(int)$admin['id'];$id=(int)$args['id'];
        $b=requestBody($request);
        $allowed=['name','sku','barcode','price_clp'];
        if(!array_intersect($allowed,array_keys($b)))fail('No hay cambios.',422);
        foreach(array_keys($b) as $field){if(!in_array($field,$allowed,true))fail('Campo de producto no editable: '.$field,422);}
        $db=db();
        try{
            $db->beginTransaction();
            $st=$db->prepare('SELECT id,name,sku,barcode,price_clp FROM products WHERE id=? AND business_id=? AND is_active=1 FOR UPDATE');
            $st->execute([$id,$biz]);$original=$st->fetch();$st->closeCursor();
            if(!$original)fail('Producto no encontrado.',404);
            $old=['name'=>(string)$original['name'],'sku'=>$original['sku'],'barcode'=>$original['barcode'],'price_clp'=>(int)$original['price_clp']];
            $next=$old;
            if(array_key_exists('name',$b))$next['name']=requireString($b,'name',120);
            if(array_key_exists('price_clp',$b))$next['price_clp']=integerPrice($b['price_clp']);
            foreach(['sku','barcode'] as $field){
                if(!array_key_exists($field,$b))continue;
                $v=$b[$field];
                if($v!==null && !is_string($v))fail('Código inválido: '.$field,422);
                if($v!==null && mb_strlen(trim($v))>50)fail('Código demasiado largo: '.$field,422);
                $next[$field]=$v===null?null:(trim($v)===''?null:trim($v));
            }
            if($next['sku']===null && $next['barcode']===null)fail('Ingresa SKU o código de barras.',422);
            if($old===$next){$db->commit();return respond($response,['ok'=>true,'changed'=>false,'product_id'=>$id]);}
            $st=$db->prepare('UPDATE products SET name=?,sku=?,barcode=?,price_clp=? WHERE id=? AND business_id=?');
            $st->execute([$next['name'],$next['sku'],$next['barcode'],$next['price_clp'],$id,$biz]);
            $st=$db->prepare('INSERT INTO product_changes(business_id,product_id,changed_by,old_values_json,new_values_json) VALUES(?,?,?,?,?)');
            $st->execute([$biz,$id,$uid,json_encode($old,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($next,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
            $db->commit();
            return respond($response,['ok'=>true,'changed'=>true,'product_id'=>$id]);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062)fail('SKU o código de barras duplicado.',409);
            throw $e;
        }
    });

    /** Anulación íntegra: una sola vez, con restitución de stock y sin borrar la venta. */
    $group->post('/sales/{id:[0-9]+}/void', static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);
        $biz=(int)$admin['business_id'];$uid=(int)$admin['id'];$id=(int)$args['id'];
        $b=requestBody($request);
        if(array_diff(array_keys($b),['reason','serial_inspection_confirmed']))fail('Campos no permitidos.',422);
        $reason=requireString($b,'reason',250);
        if(mb_strlen($reason)<5)fail('Indica un motivo de al menos 5 caracteres.',422);
        $db=db();
        try{
            $db->beginTransaction();originLockCompany($db,$biz);
            $st=$db->prepare('SELECT id,status,warehouse_id FROM sales WHERE id=? AND business_id=? FOR UPDATE');
            $st->execute([$id,$biz]);$sale=$st->fetch();$st->closeCursor();
            if(!$sale)fail('Venta no encontrada.',404);
            if($sale['status']!=='completed')fail('Esta venta ya fue anulada.',409);
            $check=$db->prepare('SELECT id FROM customer_returns WHERE business_id=? AND sale_id=? LIMIT 1');$check->execute([$biz,$id]);if($check->fetchColumn())fail('Venta con devoluciones previas; no se puede anular íntegramente.',409);$check->closeCursor();
            $check=$db->prepare('SELECT id FROM credit_accounts WHERE business_id=? AND sale_id=? LIMIT 1');$check->execute([$biz,$id]);if($check->fetchColumn())fail('Anula esta venta mediante el módulo de cuentas por cobrar.',409);$check->closeCursor();
            $saleLocation=$sale['warehouse_id']===null?titoLocalLocationId($db,$biz):(int)$sale['warehouse_id'];
            $st=$db->prepare('SELECT id,product_id,quantity FROM sale_items WHERE sale_id=? ORDER BY product_id,id');
            $st->execute([$id]);$items=$st->fetchAll();$st->closeCursor();
            if(!$items)fail('Esta venta no tiene productos.',409);
            $lock=$db->prepare('SELECT stock_qty,is_active,sale_unit FROM products WHERE id=? AND business_id=? FOR UPDATE');
            foreach($items as $item){
                $pid=(int)$item['product_id'];$qty=(float)$item['quantity'];
                if(!is_finite($qty)||$qty<=0)fail('Cantidad histórica de venta inválida.',409);
                $lock->execute([$pid,$biz]);$product=$lock->fetch();$lock->closeCursor();
                if(!$product)fail('Producto histórico no encontrado.',409);
                $next=(float)$product['stock_qty']+$qty;
                if(!is_finite($next)||$next>1000000000)fail('El stock resultante supera el máximo permitido.',409);
                $balance=number_format($next,3,'.','');
                $units=serialSaleLineUnits($db,$biz,$id,(int)$item['id']);
                if($units){
                    if(($b['serial_inspection_confirmed']??false)!==true)fail('Confirma recepción física y buen estado de todas las series vendidas.',422);
                    if((int)$product['is_active']!==1||$product['sale_unit']!=='unidad'||!serialControlled($db,$biz,$pid)||(float)count($units)!==$qty)fail('Inventario serializado no válido para anulación.',409);
                    serialReconcile($db,$biz,$pid,(float)$product['stock_qty']);
                    $numbers=array_column($units,'serial_number');
                    serialRestoreUnits($db,$admin,$id,(int)$item['id'],$pid,$saleLocation,$numbers,'void',null,$reason);
                    $balance=titoRestoreSerialLocationStock($db,$biz,$pid,$saleLocation,$qty);
                    serialReconcile($db,$biz,$pid,(float)$balance);
                }else $balance=titoApplyLocationStock($db,$biz,$pid,$saleLocation,$qty);
                move($db,$biz,$pid,$id,$uid,'reversal',number_format($qty,3,'.',''),$balance,mb_substr('Anulación venta #'.$id.': '.$reason,0,250));
            }
            $st=$db->prepare("UPDATE sales SET status='voided',voided_by=?,voided_at=UTC_TIMESTAMP(),void_reason=? WHERE id=? AND business_id=? AND status='completed'");
            $st->execute([$uid,$reason,$id,$biz]);
            if($st->rowCount()!==1)throw new RuntimeException('No se pudo finalizar la anulación.');
            $db->commit();
            return respond($response,['ok'=>true,'sale_id'=>$id,'status'=>'voided','restored_items'=>count($items),'money_refunded'=>false,'tax_document_voided'=>false]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
