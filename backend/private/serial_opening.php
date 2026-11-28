<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function serialOpeningRows(PDO $db,int $biz,int $pid,int $warehouse,array $numbers):array {
    $s=$db->prepare('SELECT p.id,p.name,p.sku,p.stock_qty,p.sale_unit,p.is_active,(c.product_id IS NOT NULL) AS serial_control FROM products p LEFT JOIN serial_product_controls c ON c.product_id=p.id AND c.business_id=p.business_id WHERE p.business_id=? AND p.id=?');$s->execute([$biz,$pid]);$p=$s->fetch();$s->closeCursor();
    if(!$p||!(int)$p['is_active']||$p['sale_unit']!=='unidad'||!(int)$p['serial_control'])fail('Activa el control de serie del producto antes de la apertura.',409);
    $s=$db->prepare('SELECT id,name FROM warehouses WHERE business_id=? AND id=? AND is_active=1');$s->execute([$biz,$warehouse]);$w=$s->fetch();$s->closeCursor();if(!$w)fail('Bodega no disponible.',409);
    // Opening is not an adjustment or a substitute for a missing purchase receipt.
    $s=$db->prepare('SELECT id FROM serial_units WHERE business_id=? AND product_id=? AND opening_id IS NULL LIMIT 1');$s->execute([$biz,$pid]);$normal=$s->fetchColumn();$s->closeCursor();
    $s=$db->prepare('SELECT si.id FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.business_id=? AND si.product_id=? LIMIT 1');$s->execute([$biz,$pid]);$sold=$s->fetchColumn();$s->closeCursor();
    if($normal||$sold)fail('El producto ya tiene recepciones o ventas. Usa el flujo normal o una conciliación revisada, no apertura.',409);
    $s=$db->prepare('SELECT id,serial_number,status FROM serial_units WHERE business_id=? AND product_id=? AND serial_number IN ('.implode(',',array_fill(0,count($numbers),'?')).')');$s->execute(array_merge([$biz,$pid],$numbers));$duplicates=$s->fetchAll();$s->closeCursor();
    return ['product'=>$p,'warehouse'=>$w,'quantity'=>count($numbers),'duplicates'=>$duplicates,'serial_numbers'=>$numbers];
}
function serialOpeningInput(array $b):array {
    if(array_diff(array_keys($b),['request_id','product_id','warehouse_id','serial_numbers','expected_quantity','counted_on','unit_cost_clp','note','evidence_reference','physical_confirmed']))fail('Campos no permitidos.',422);
    $pid=originId($b['product_id']??null,'product_id');$warehouse=originId($b['warehouse_id']??null,'warehouse_id');$numbers=serialNormalizeList($b['serial_numbers']??null);$count=$b['expected_quantity']??null;
    if(!$pid||!$warehouse||!$numbers||!is_int($count)||$count!==count($numbers))fail('El conteo físico debe coincidir con las series únicas.',422);
    return [$pid,$warehouse,$numbers,$count];
}
function registerSerialOpeningRoutes(RouteCollectorProxy $group):void {
    $group->post('/serials/opening/preview',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$b=requestBody($request);[$pid,$warehouse,$numbers,$count]=serialOpeningInput($b);
        return respond($response,serialOpeningRows(db(),$biz,$pid,$warehouse,$numbers)+['today'=>quoteToday()]);
    });
    $group->get('/serials/opening/batches',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if($page===false)fail('Página inválida.',422);
        $s=db()->prepare('SELECT o.id,o.product_id,p.name AS product_name,p.sku,o.warehouse_id,o.warehouse_name,o.counted_on,o.quantity,o.unit_cost_clp,o.evidence_reference,o.note,o.created_at FROM serial_opening_batches o JOIN products p ON p.id=o.product_id AND p.business_id=o.business_id WHERE o.business_id=? ORDER BY o.id DESC LIMIT 26 OFFSET '.(($page-1)*25));$s->execute([$biz]);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25]);
    });
    $group->post('/serials/opening',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$b=requestBody($request);[$pid,$warehouse,$numbers,$count]=serialOpeningInput($b);
        $rid=titoCreditUuid($b['request_id']??null);$day=reservationDate($b['counted_on']??null);$cost=$b['unit_cost_clp']??null;
        if($cost!==null&&(!is_int($cost)||$cost<0||$cost>1000000000))fail('Costo histórico inválido. Déjalo vacío si no se conoce.',422);
        $note=warrantyText($b,'note',1000);$ref=warrantyText($b,'evidence_reference',250);
        if(($b['physical_confirmed']??null)!==true)fail('Confirma el conteo físico de apertura.',422);
        $hash=hash('sha256',json_encode([$uid,$pid,$warehouse,$numbers,$count,$day,$cost,$note,$ref],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $s=$db->prepare('SELECT id,payload_hash,quantity FROM serial_opening_batches WHERE business_id=? AND request_id=?');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();
            if($old){if(!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada con datos diferentes.',409);$db->commit();return respond($response,['ok'=>true,'opening_id'=>(int)$old['id'],'quantity'=>(int)$old['quantity'],'already_recorded'=>true]);}
            if($day!==quoteToday())fail('La apertura se contabiliza en el día del conteo registrado. Actualiza la revisión.',422);
            $s=$db->prepare('SELECT stock_qty FROM products WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$pid]);$global=$s->fetchColumn();$s->closeCursor();if($global===false)fail('Producto no encontrado.',404);
            $s=$db->prepare('SELECT id FROM warehouses WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');$s->execute([$biz,$warehouse]);$wid=$s->fetchColumn();$s->closeCursor();if(!$wid)fail('Bodega no disponible.',409);
            $view=serialOpeningRows($db,$biz,$pid,$warehouse,$numbers);
            if($view['duplicates'])fail('Serie ya registrada: '.$view['duplicates'][0]['serial_number'].'. No se guardó el lote.',409);
            if(!serialControlled($db,$biz,$pid))fail('Producto sin control de series.',409);
            serialReconcile($db,$biz,$pid,(float)$global);
            $s=$db->prepare('INSERT INTO serial_opening_batches(business_id,product_id,warehouse_id,warehouse_name,counted_on,quantity,unit_cost_clp,evidence_reference,note,actor_id,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$biz,$pid,$warehouse,$view['warehouse']['name'],$day,$count,$cost,$ref,$note,$uid,$rid,$hash]);$id=(int)$db->lastInsertId();
            $after=titoReceiveLocationStock($db,$biz,$pid,$warehouse,(float)$count,true);
            $ins=$db->prepare("INSERT INTO serial_units(business_id,product_id,serial_number,receipt_id,supplier_id,warehouse_id,unit_cost_clp,received_at,opening_id,status) VALUES(?,?,?,NULL,NULL,?,?,?,?,'in_stock')");
            $event=$db->prepare("INSERT INTO serial_unit_events(business_id,unit_id,actor_id,event_type,details) VALUES(?,?,?,'opening',?)");
            $details=json_encode(['opening_id'=>$id,'warehouse_id'=>$warehouse,'counted_on'=>$day,'unit_cost_clp'=>$cost,'note'=>$note,'evidence_reference'=>$ref],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            foreach($numbers as $number){$ins->execute([$biz,$pid,$number,$warehouse,$cost,$day,$id]);$event->execute([$biz,(int)$db->lastInsertId(),$uid,$details]);}
            move($db,$biz,$pid,null,$uid,'initial',number_format($count,3,'.',''),$after,'Apertura serializada #'.$id);
            serialReconcile($db,$biz,$pid,(float)$after);$db->commit();return respond($response,['ok'=>true,'opening_id'=>$id,'quantity'=>$count],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062)fail('Serie o lote duplicado. No se guardó la operación.',409);throw $e;}
    });
}
