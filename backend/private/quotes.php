<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Cotizaciones internas no tributarias. */
function quoteCreationActor(Request $request): array {
    $actor=accessRequire($request,'quotes.create');
    if($actor['role']!=='admin'&&(!in_array('inventory.view',$actor['permissions'],true)||!in_array('quotes.view',$actor['permissions'],true)||$actor['branch_scope']!=='all'))fail('No tienes permisos para crear cotizaciones.',403);
    return $actor;
}
function registerQuoteRoutes(RouteCollectorProxy $group): void {
    $group->get('/quotes/clients',static function(Request $request,Response $response): Response {
        $actor=quoteCreationActor($request);$biz=(int)$actor['business_id'];
        $q=$request->getQueryParams()['q']??'';
        if(!is_string($q)||!mb_check_encoding($q,'UTF-8')||mb_strlen($q)>150)fail('Búsqueda inválida.',422);
        $q=trim($q);$rut=strtoupper(preg_replace('/[.\s-]+/u','',$q)??'');
        $st=db()->prepare("SELECT id,name,rut FROM habitual_clients WHERE business_id=? AND is_active=1 AND (LOCATE(?,name)>0 OR (?<>'' AND LOCATE(?,REPLACE(COALESCE(rut,''),'-',''))>0)) ORDER BY name,id LIMIT 51");
        $st->execute([$biz,$q,$rut,$rut]);$rows=$st->fetchAll();
        return respond($response,['clients'=>array_slice($rows,0,50),'more'=>count($rows)>50]);
    });
    $group->get('/quotes',static function(Request $request,Response $response): Response {
        $admin=accessRequire($request,'quotes.view');
        $st=db()->prepare('SELECT id,client_id,customer_name,customer_rut,customer_contact,valid_until,status,total_clp,created_at,converted_sale_id FROM quotes WHERE business_id=? ORDER BY id DESC LIMIT 100');
        $st->execute([(int)$admin['business_id']]);
        return respond($response,['quotes'=>$st->fetchAll()]);
    });
    $group->get('/quotes/{id:[0-9]+}',static function(Request $request,Response $response,array $args): Response {
        $admin=accessRequire($request,'quotes.view');$biz=(int)$admin['business_id'];$id=(int)$args['id'];
        $st=db()->prepare('SELECT q.id,q.client_id,q.customer_name,q.customer_rut,q.customer_email,q.customer_contact,q.notes,q.valid_until,q.status,q.total_clp,q.created_at,q.converted_sale_id,u.email AS created_by_email FROM quotes q JOIN users u ON u.id=q.created_by WHERE q.business_id=? AND q.id=? LIMIT 1');
        $st->execute([$biz,$id]);$quote=$st->fetch();
        if(!$quote)fail('Cotización no encontrada.',404);
        $st=db()->prepare('SELECT qi.id,qi.product_id,qi.product_name_snapshot,qi.sale_unit,qi.quantity,qi.list_price_clp,qi.unit_price_clp,qi.discount_reason,qi.subtotal_clp,EXISTS(SELECT 1 FROM serial_product_controls sc WHERE sc.product_id=qi.product_id AND sc.business_id=?) AS serial_control FROM quote_items qi WHERE qi.quote_id=? ORDER BY qi.id');
        $st->execute([$biz,$id]);
        return respond($response,['quote'=>$quote,'items'=>$st->fetchAll()]);
    });
    $group->post('/quotes',static function(Request $request,Response $response): Response {
        $admin=quoteCreationActor($request);
        $limited=$admin['role']!=='admin';
        $biz=(int)$admin['business_id'];$uid=(int)$admin['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','client_id','customer_name','customer_contact','valid_until','notes','items']))fail('Campos no permitidos.',422);
        $rid=$b['request_id']??null;
        if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$rid))fail('Identificador inválido.',422);
        $clientId=originId($b['client_id']??null,'client_id');
        // customer_name se mantiene únicamente para reconocer reintentos de cotizaciones antiguas.
        $customer=$clientId===null?requireString($b,'customer_name',150):null;
        if($clientId!==null&&array_key_exists('customer_name',$b))fail('Selecciona el cliente por su identificador, no por texto libre.',422);
        $contact=$b['customer_contact']??null;
        if($contact!==null&&(!is_string($contact)||mb_strlen(trim($contact))>120))fail('Contacto inválido.',422);
        $contact=$contact===null?null:(trim($contact)?:null);
        $notes=$b['notes']??null;
        if($notes!==null&&(!is_string($notes)||mb_strlen(trim($notes))>500))fail('Observaciones demasiado largas.',422);
        $notes=$notes===null?null:(trim($notes)?:null);
        $date=$b['valid_until']??null;
        if(!is_string($date)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$p)||!checkdate((int)$p[2],(int)$p[3],(int)$p[1]))fail('Fecha de validez inválida.',422);
        $items=$b['items']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>50)fail('Ingresa entre 1 y 50 productos.',422);
        $wanted=[];
        foreach($items as $item){
            if(!is_array($item)||array_diff(array_keys($item),['product_id','quantity','unit_price_clp','discount_reason']))fail('Línea inválida.',422);
            $pid=filter_var($item['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($pid===false||isset($wanted[$pid]))fail('Producto duplicado o inválido.',422);
            $wanted[$pid]=[
                'quantity'=>$item['quantity']??null,
                'unit_price_clp'=>array_key_exists('unit_price_clp',$item)?integerPrice($item['unit_price_clp']):null,
                'discount_reason'=>$item['discount_reason']??null
            ];
        }
        ksort($wanted,SORT_NUMERIC);
        $payload=$clientId===null?['customer'=>$customer,'contact'=>$contact,'notes'=>$notes,'valid_until'=>$date,'items'=>$wanted]:['client_id'=>$clientId,'contact'=>$contact,'notes'=>$notes,'valid_until'=>$date,'items'=>$wanted];
        $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();
        try{
            $db->beginTransaction();originLockCompany($db,$biz);
            $st=$db->prepare('SELECT id,total_clp,payload_hash,created_by FROM quotes WHERE business_id=? AND request_id=? FOR UPDATE');
            $st->execute([$biz,strtolower($rid)]);$prior=$st->fetch();$st->closeCursor();
            if($prior){
                if(($limited&&(int)$prior['created_by']!==$uid)||!hash_equals((string)$prior['payload_hash'],$hash))fail('Solicitud reutilizada con datos diferentes.',409);
                $db->commit();return respond($response,['ok'=>true,'quote_id'=>(int)$prior['id'],'total_clp'=>(int)$prior['total_clp'],'already_recorded'=>true]);
            }
            if($clientId===null)fail('Selecciona un cliente registrado para crear una cotización.',422);
            if($date<(new DateTimeImmutable('now',new DateTimeZone('America/Santiago')))->format('Y-m-d'))fail('La validez no puede ser anterior a hoy.',422);
            $st=$db->prepare('SELECT name,rut,billing_email,contact_name,phone FROM habitual_clients WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');
            $st->execute([$biz,$clientId]);$c=$st->fetch();$st->closeCursor();if(!$c)fail('Cliente activo no encontrado en esta empresa.',422);
            $customer=$c['name'];$snapshotContact=$contact??($c['contact_name']?mb_substr($c['contact_name'],0,120):($c['phone']??null));
            $st=$db->prepare('INSERT INTO quotes(business_id,created_by,request_id,payload_hash,client_id,customer_name,customer_rut,customer_email,customer_contact,notes,valid_until,total_clp) VALUES(?,?,?,?,?,?,?,?,?,?,?,0)');
            $st->execute([$biz,$uid,strtolower($rid),$hash,$clientId,$customer,$c['rut'],$c['billing_email'],$snapshotContact,$notes,$date]);
            $id=(int)$db->lastInsertId();
            $st=$db->prepare('SELECT id,name,price_clp,sale_unit FROM products WHERE id=? AND business_id=? AND is_active=1');
            $line=$db->prepare('INSERT INTO quote_items(quote_id,product_id,product_name_snapshot,sale_unit,quantity,list_price_clp,unit_price_clp,discount_reason,subtotal_clp) VALUES(?,?,?,?,?,?,?,?,?)');
            $total=0;
            foreach($wanted as $pid=>$item){
                $st->execute([$pid,$biz]);$p=$st->fetch();$st->closeCursor();
                if(!$p)fail('Producto no encontrado: '.$pid,422);
                $q=decimalQuantity($item['quantity'],false,$p['sale_unit']!=='unidad');
                if((float)$q<=0)fail('Cantidad inválida.',422);
                $list=(int)$p['price_clp'];
                $price=$item['unit_price_clp']===null?$list:$item['unit_price_clp'];
                if($limited&&($price!==$list||$item['discount_reason']!==null))fail('Tu rol solo permite cotizar a precio de lista.',403);
                if($price>$list)fail('El precio pactado no puede superar la lista.',422);
                if($price===0&&$list>0)fail('El precio pactado debe superar cero.',422);
                $reason=null;
                if($price<$list){
                    $reason=$item['discount_reason'];
                    if(!is_string($reason)||trim($reason)===''||mb_strlen(trim($reason))>120)fail('Explica el descuento.',422);
                    $reason=trim($reason);
                }
                $subtotal=(int)round((float)$q*$price,0,PHP_ROUND_HALF_UP);
                $total+=$subtotal;
                if($total>1000000000000)fail('Total demasiado grande.',422);
                $line->execute([$id,$pid,$p['name'],$p['sale_unit'],$q,$list,$price,$reason,$subtotal]);
            }
            $st=$db->prepare('UPDATE quotes SET total_clp=? WHERE id=? AND business_id=?');
            $st->execute([$total,$id,$biz]);$db->commit();
            return respond($response,['ok'=>true,'quote_id'=>$id,'total_clp'=>$total],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062){
                $st=$db->prepare('SELECT id,total_clp,payload_hash,created_by FROM quotes WHERE business_id=? AND request_id=?');
                $st->execute([$biz,strtolower($rid)]);$prior=$st->fetch();
                if($prior){
                    if($limited&&(int)$prior['created_by']!==$uid)fail('Solicitud reutilizada.',409);
                    if(!hash_equals((string)$prior['payload_hash'],$hash))fail('Solicitud reutilizada con datos diferentes.',409);
                    return respond($response,['ok'=>true,'quote_id'=>(int)$prior['id'],'total_clp'=>(int)$prior['total_clp'],'already_recorded'=>true],200);
                }
            }
            throw $e;
        }
    });
    // Conversión sólo administrador: comprueba precios y stock nuevamente y genera una venta normal.
    $group->post('/quotes/{id:[0-9]+}/convert',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$uid=(int)$admin['id'];$qid=(int)$args['id'];
        $b=requestBody($request);
        if(array_diff(array_keys($b),['payment_method','warehouse_id']))fail('Campos inesperados.',422);
        $payment=$b['payment_method']??null;
        if(!in_array($payment,['efectivo','transferencia','tarjeta','otro'],true))fail('Medio de pago inválido.',422);
        $db=db();
        try{
            $db->beginTransaction();originLockCompany($db,$biz);
            $saleLocation=titoSaleLocation($db,$admin,$b['warehouse_id']??null,true,$request);
            $cashSessionId=titoCashSessionForSale($db,$admin,$saleLocation);
            $st=$db->prepare('SELECT id,status,valid_until,converted_sale_id,client_id,customer_name,customer_rut FROM quotes WHERE business_id=? AND id=? FOR UPDATE');
            $st->execute([$biz,$qid]);$quote=$st->fetch();$st->closeCursor();
            if(!$quote)fail('Cotización no encontrada.',404);
            if($quote['status']==='converted'){
                $db->commit();return respond($response,['ok'=>true,'quote_id'=>$qid,'sale_id'=>(int)$quote['converted_sale_id'],'already_recorded'=>true]);
            }
            if($quote['client_id']!==null){
                $st=$db->prepare('SELECT id FROM habitual_clients WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');$st->execute([$biz,(int)$quote['client_id']]);$found=$st->fetchColumn();$st->closeCursor();if(!$found)fail('El cliente de la cotización ya no está activo.',409);
            }
            if($quote['valid_until']<(new DateTimeImmutable('now',new DateTimeZone('America/Santiago')))->format('Y-m-d'))fail('La cotización está vencida. Crea una nueva.',409);
            $st=$db->prepare('SELECT product_id,product_name_snapshot,sale_unit,quantity,list_price_clp,unit_price_clp,discount_reason,subtotal_clp FROM quote_items WHERE quote_id=? ORDER BY product_id');
            $st->execute([$qid]);$items=$st->fetchAll();$st->closeCursor();
            if(!$items)fail('Cotización sin productos.',409);
            // Cotización numerada, una sola venta por conversión (bloqueo de su fila).
            $rid=sprintf('%08x-%04x-4%03x-%04x-%012x',random_int(0,0xffffffff),random_int(0,0xffff),random_int(0,0xfff),random_int(0x8000,0xbfff),random_int(0,0xffffffffffff));
            $st=$db->prepare('INSERT INTO sales(business_id,sold_by,request_id,payment_method,total_clp,warehouse_id,cash_session_id,client_id,client_name_snapshot,client_rut_snapshot,customer_mode) VALUES(?,?,?,?,0,?,?,?,?,?,?)');
            $st->execute([$biz,$uid,$rid,$payment,$saleLocation,$cashSessionId,$quote['client_id'],$quote['client_id']===null?null:$quote['customer_name'],$quote['customer_rut'],$quote['client_id']===null?null:'registered']);$sid=(int)$db->lastInsertId();
            salesPointRecord($db,$admin,$request,$sid,$saleLocation);
            $stock=$db->prepare('SELECT id,name,stock_qty,price_clp,sale_unit FROM products WHERE id=? AND business_id=? AND is_active=1 FOR UPDATE');
             $insert=$db->prepare('INSERT INTO sale_items(sale_id,product_id,product_name_snapshot,quantity,unit_price_clp,list_price_clp,discount_reason,subtotal_clp) VALUES(?,?,?,?,?,?,?,?)');
            $total=0;
            foreach($items as $item){
                $pid=(int)$item['product_id'];$qty=(float)$item['quantity'];
                $stock->execute([$pid,$biz]);$p=$stock->fetch();$stock->closeCursor();
                if(!$p)fail('Un producto ya no está activo.',409);
                if(serialControlled($db,$biz,$pid))fail('Esta cotización contiene productos serializados. La conversión con selección de series aún está pendiente; no se descontó stock.',409);
                if($p['sale_unit']!==$item['sale_unit'])fail('Un producto cambió de unidad. Revisa la cotización.',409);
                // NO comparar price_clp vigente: quote_items contiene los precios acordados y su descuento.
                // Una nueva tarifa del catálogo sólo se aplica a cotizaciones y ventas nuevas.
                if((float)$p['stock_qty']+0.00001<$qty)fail('Stock total insuficiente para '.$p['name'],409);
                 $next=number_format(max(0,(float)$p['stock_qty']-$qty),3,'.','');
                $next=titoApplyLocationStock($db,$biz,$pid,$saleLocation,-$qty);
                $insert->execute([$sid,$pid,$item['product_name_snapshot'],$item['quantity'],$item['unit_price_clp'],$item['list_price_clp'],$item['discount_reason'],$item['subtotal_clp']]);
                move($db,$biz,$pid,$sid,$uid,'sale',number_format(-$qty,3,'.',''),$next,'Venta desde cotización #'.$qid);
                $total+=(int)$item['subtotal_clp'];
            }
            $st=$db->prepare('UPDATE sales SET total_clp=? WHERE id=? AND business_id=?');
            $st->execute([$total,$sid,$biz]);
            $st=$db->prepare("UPDATE quotes SET status='converted',converted_sale_id=? WHERE id=? AND business_id=? AND status='draft'");
            $st->execute([$sid,$qid,$biz]);
            if($st->rowCount()!==1)throw new RuntimeException('No se pudo convertir la cotización.');
            titoCashRegisterSale($db,$biz,$uid,$cashSessionId,$sid,$rid,$total,$payment);
            $db->commit();
            return respond($response,['ok'=>true,'quote_id'=>$qid,'sale_id'=>$sid,'total_clp'=>$total,'warehouse_id'=>$saleLocation],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    });
}

