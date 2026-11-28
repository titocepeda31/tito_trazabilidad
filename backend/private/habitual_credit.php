<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Ventas al fiado: no son pagos recibidos, no son documentos SII. */
function titoCreditUuid(mixed $value): string {
    if(!is_string($value)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$value))fail('Identificador de operación inválido.',422);
    return strtolower($value);
}
function titoCreditDate(mixed $value): ?string {
    if($value===null||$value==='')return null;
    if(!is_string($value)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))fail('Fecha de compromiso inválida.',422);
    return $value;
}
function titoCreditString(array $body,string $key,int $max,bool $required=false): ?string {
    $v=$body[$key]??null;
    if($v===null||$v===''){
        if($required)fail('Falta '.$key,422);
        return null;
    }
    if(!is_string($v)||mb_strlen(trim($v))>$max)fail('Texto inválido en '.$key,422);
    $v=trim($v);
    if($required&&$v==='')fail('Campo obligatorio: '.$key,422);
    return $v?:null;
}
function titoCreditUnsigned(mixed $v,string $label,int $min=0,int $max=1000000000000): int {
    if(!is_int($v)||$v<$min||$v>$max)fail('Monto no válido en '.$label,422);
    return $v;
}
function titoCreditLines(mixed $rows): array {
    if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>80)fail('Se requiere entre 1 y 80 productos.',422);
    $normalized=[];
    foreach($rows as $row){
        if(!is_array($row)||array_diff(array_keys($row),['product_id','quantity','unit_price_clp','discount_reason']))fail('Campos de producto no permitidos.',422);
        $pid=filter_var($row['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($pid===false||isset($normalized[$pid]))fail('Producto repetido o inválido.',422);
        $qtyRaw=$row['quantity']??null;
        if(!is_int($qtyRaw)&&!is_float($qtyRaw))fail('Cantidad inválida.',422);
        $qty=(float)$qtyRaw;
        if(!is_finite($qty)||$qty<=0||$qty>1000000000||abs($qty*1000-round($qty*1000))>0.000001)fail('Cantidad fuera de rango.',422);
        $price=titoCreditUnsigned($row['unit_price_clp']??null,'precio unitario',0,1000000000);
        $reason=titoCreditString($row,'discount_reason',120);
        $normalized[(int)$pid]=['product_id'=>(int)$pid,'quantity'=>number_format($qty,3,'.',''),'unit_price_clp'=>$price,'discount_reason'=>$reason];
    }
    ksort($normalized,SORT_NUMERIC);
    return array_values($normalized);
}
function titoCreditAccountListSql(): string {
    return "SELECT a.id,a.business_id,a.client_id,c.name AS client_name,a.sale_id,a.status,
    a.original_total_clp,
    COALESCE(adj.adjusted_clp,0) AS adjusted_clp,
    a.original_total_clp-COALESCE(adj.adjusted_clp,0) AS net_total_clp,
    COALESCE(pay.paid_clp,0) AS paid_clp,
    COALESCE(ref.refunded_clp,0) AS refunded_clp,
    GREATEST(0,a.original_total_clp-COALESCE(adj.adjusted_clp,0)-COALESCE(pay.paid_clp,0)) AS balance_clp,
    GREATEST(0,COALESCE(pay.paid_clp,0)-(a.original_total_clp-COALESCE(adj.adjusted_clp,0))-COALESCE(ref.refunded_clp,0)) AS refund_due_clp,
    a.due_date,a.notes,a.created_at,u.email AS created_by_email
    FROM credit_accounts a
    JOIN habitual_clients c ON c.id=a.client_id AND c.business_id=a.business_id
    JOIN users u ON u.id=a.created_by
    LEFT JOIN (SELECT account_id,SUM(amount_clp) AS paid_clp FROM credit_payments GROUP BY account_id) pay ON pay.account_id=a.id
    LEFT JOIN (SELECT account_id,SUM(amount_clp) AS adjusted_clp FROM credit_adjustments GROUP BY account_id) adj ON adj.account_id=a.id
    LEFT JOIN (SELECT account_id,SUM(amount_clp) AS refunded_clp FROM credit_refunds GROUP BY account_id) ref ON ref.account_id=a.id";
}
/** RUT chileno canónico (sin puntos, con guion y dígito verificador). */
function titoCommercialRut(mixed $value): ?string {
    if($value===null||$value==='')return null;
    if(!is_string($value)||mb_strlen($value)>25)fail('RUT inválido.',422);
    $clean=strtoupper(preg_replace('/[.\s-]+/u','',trim($value))??'');
    if(!preg_match('/^[0-9]{1,8}[0-9K]$/D',$clean))fail('RUT inválido.',422);
    $body=ltrim(substr($clean,0,-1),'0');$dv=substr($clean,-1);
    if($body==='')fail('RUT inválido.',422);
    $sum=0;$factor=2;
    for($i=strlen($body)-1;$i>=0;$i--){$sum+=(int)$body[$i]*$factor;$factor=$factor===7?2:$factor+1;}
    $n=11-($sum%11);$expected=$n===11?'0':($n===10?'K':(string)$n);
    if($dv!==$expected)fail('El dígito verificador del RUT no coincide.',422);
    return $body.'-'.$dv;
}
function titoCommercialFields(array $b): array {
    if(array_diff(array_keys($b),['name','phone','notes','client_type','rut','business_activity','address','commune','contact_name','billing_email','is_active']))fail('Campos comerciales no permitidos.',422);
    $type=$b['client_type']??null;if($type==='')$type=null;
    if($type!==null&&!in_array($type,['person','business'],true))fail('Tipo de cliente inválido.',422);
    $email=titoCreditString($b,'billing_email',254);
    if($email!==null&&!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Correo de facturación inválido.',422);
    $active=$b['is_active']??true;if(!is_bool($active))fail('Estado de cliente inválido.',422);
    return [titoCreditString($b,'name',150,true),titoCreditString($b,'phone',80),titoCreditString($b,'notes',300),$type,titoCommercialRut($b['rut']??null),titoCreditString($b,'business_activity',150),titoCreditString($b,'address',200),titoCreditString($b,'commune',100),titoCreditString($b,'contact_name',150),$email,$active?1:0];
}
function registerHabitualCreditRoutes(RouteCollectorProxy $group): void {
    // Registro comercial separado de crédito, con la misma identidad de cliente.
    $group->get('/clients/commercial',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$query=$request->getQueryParams();
        $q=$query['q']??'';$state=$query['state']??'all';$page=filter_var($query['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if(!is_string($q)||mb_strlen($q)>150||!in_array($state,['all','active','inactive'],true)||$page===false)fail('Filtro inválido.',422);
        $q=trim($q);$rutSearch=strtoupper(preg_replace('/[.\s-]+/u','',$q)??'');
        $where=' WHERE business_id=?';$params=[$biz];
        if($state!=='all'){$where.=' AND is_active=?';$params[]=$state==='active'?1:0;}
        if($q!==''){
            $where.=" AND (LOCATE(?,name)>0 OR LOCATE(?,COALESCE(contact_name,''))>0 OR LOCATE(?,COALESCE(phone,''))>0 OR LOCATE(?,COALESCE(billing_email,''))>0 OR (?<>'' AND LOCATE(?,REPLACE(COALESCE(rut,''),'-',''))>0))";
            array_push($params,$q,$q,$q,$q,$rutSearch,$rutSearch);
        }
        $db=db();$st=$db->prepare('SELECT COUNT(*) FROM habitual_clients'.$where);$st->execute($params);$total=(int)$st->fetchColumn();$st->closeCursor();
        $page=min($page,max(1,(int)ceil($total/25)));$offset=($page-1)*25;
        $st=$db->prepare('SELECT id,name,phone,notes,client_type,rut,business_activity,address,commune,contact_name,billing_email,is_active,created_at FROM habitual_clients'.$where.' ORDER BY name,id LIMIT 25 OFFSET '.$offset);
        $st->execute($params);return respond($response,['clients'=>$st->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/25))]);
    });
    // Historial por identidad de cliente; nunca inferir vínculos por nombre o RUT.
    $group->get('/clients/commercial/{id:[0-9]+}/history',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$cid=(int)$args['id'];$db=db();$q=$request->getQueryParams();
        $tab=$q['tab']??'quotes';$page=filter_var($q['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if(!is_string($tab)||!in_array($tab,['quotes','reservations','sales','products','series','returns','assessments','followups','receivables'],true)||$page===false)fail('Filtro inválido.',422);
        $s=$db->prepare('SELECT id,name,rut,is_active FROM habitual_clients WHERE business_id=? AND id=?');$s->execute([$biz,$cid]);$client=$s->fetch();$s->closeCursor();if(!$client)fail('Cliente no encontrado.',404);
        // Para ventas históricas sin client_id, usar únicamente un vínculo explícito existente (serie o cuenta de crédito).
        $saleScope="s.business_id=? AND (s.client_id=? OR (s.client_id IS NULL AND (EXISTS(SELECT 1 FROM serial_sale_snapshots ss WHERE ss.business_id=s.business_id AND ss.sale_id=s.id AND ss.client_id=?) OR EXISTS(SELECT 1 FROM credit_accounts ca WHERE ca.business_id=s.business_id AND ca.sale_id=s.id AND ca.client_id=?))))";
        $params=[$biz,$cid,$cid,$cid];$offset=($page-1)*25;
        switch($tab){
            case 'receivables':
                $sql=titoCreditAccountListSql().' WHERE a.business_id=? AND a.client_id=? ORDER BY a.id DESC';
                $params=[$biz,$cid];break;
            case 'followups':
                $sql="SELECT f.id,f.quote_id,f.kind,f.channel,f.note,f.status,f.due_on,f.created_at,f.close_note,u.email AS assigned_email FROM quote_followups f JOIN quotes q ON q.id=f.quote_id AND q.business_id=f.business_id AND q.client_id=f.client_id JOIN users u ON u.id=f.assigned_to AND u.business_id=f.business_id WHERE f.business_id=? AND f.client_id=? ORDER BY f.id DESC";
                $params=[$biz,$cid];break;
            case 'quotes':
                $sql="SELECT q.id,q.created_at,q.valid_until,q.status,q.total_clp,q.converted_sale_id,(SELECT r.expires_on FROM serial_reservations r WHERE r.business_id=q.business_id AND r.quote_id=q.id AND r.status='pending' ORDER BY r.id DESC LIMIT 1) AS reservation_expires_on FROM quotes q WHERE q.business_id=? AND q.client_id=? ORDER BY q.id DESC";
                $params=[$biz,$cid];break;
            case 'reservations':
                $sql="SELECT r.id,r.quote_id,r.created_at,r.status,r.expires_on,r.sale_id,w.name AS warehouse_name,(SELECT COUNT(*) FROM serial_reservation_items i WHERE i.business_id=r.business_id AND i.reservation_id=r.id AND i.quantity>i.dispatched_quantity) AS remaining_lines FROM serial_reservations r LEFT JOIN warehouses w ON w.id=r.warehouse_id AND w.business_id=r.business_id WHERE r.business_id=? AND r.client_id=? ORDER BY r.id DESC";
                $params=[$biz,$cid];break;
            case 'sales':
                $sql="SELECT s.id,s.created_at,s.status,s.total_clp,s.payment_method,w.name AS warehouse_name,COALESCE(d.quote_id,(SELECT q.id FROM quotes q WHERE q.business_id=s.business_id AND q.converted_sale_id=s.id ORDER BY q.id DESC LIMIT 1)) AS quote_id,d.reservation_id FROM sales s LEFT JOIN warehouses w ON w.id=s.warehouse_id AND w.business_id=s.business_id LEFT JOIN quote_dispatches d ON d.sale_id=s.id AND d.business_id=s.business_id WHERE ".$saleScope." ORDER BY s.id DESC";
                break;
            case 'products':
                $sql="SELECT si.id,si.sale_id,s.created_at,s.status AS sale_status,si.product_id,COALESCE(si.product_name_snapshot,p.name) AS product_name,p.sku,si.quantity,si.unit_price_clp,si.subtotal_clp,(SELECT COALESCE(SUM(ri.quantity),0) FROM customer_return_items ri JOIN customer_returns cr ON cr.id=ri.return_id AND cr.business_id=s.business_id AND cr.sale_id=s.id WHERE ri.sale_item_id=si.id) AS returned_quantity FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id AND p.business_id=s.business_id WHERE ".$saleScope." ORDER BY s.id DESC,si.id DESC";
                break;
            case 'series':
                $sql="SELECT su.unit_id,su.sale_id,s.created_at,s.status AS sale_status,x.serial_number,x.product_id,COALESCE(si.product_name_snapshot,p.name) AS product_name,rv.reversal_kind,rv.return_id FROM serial_sale_units su JOIN sales s ON s.id=su.sale_id AND s.business_id=su.business_id JOIN serial_units x ON x.id=su.unit_id AND x.business_id=su.business_id JOIN sale_items si ON si.id=su.sale_item_id AND si.sale_id=s.id JOIN products p ON p.id=x.product_id AND p.business_id=su.business_id LEFT JOIN serial_sale_reversals rv ON rv.unit_id=su.unit_id AND rv.sale_id=su.sale_id AND rv.business_id=su.business_id WHERE ".$saleScope." ORDER BY s.id DESC,su.unit_id DESC";
                break;
            case 'returns':
                $sql="SELECT r.id,r.sale_id,r.created_at,r.return_kind,r.item_condition,r.resolution,r.reason,r.amount_clp,r.refund_status FROM customer_returns r JOIN sales s ON s.id=r.sale_id AND s.business_id=r.business_id WHERE ".$saleScope." ORDER BY r.id DESC";
                break;
            case 'assessments':
                $sql="SELECT a.id,a.sale_id,a.created_at,a.outcome,a.observations,a.custody FROM customer_return_assessments a JOIN sales s ON s.id=a.sale_id AND s.business_id=a.business_id WHERE ".$saleScope." ORDER BY a.id DESC";
                break;
        }
        $s=$db->prepare($sql.' LIMIT 26 OFFSET '.$offset);$s->execute($params);$rows=$s->fetchAll();$s->closeCursor();$more=count($rows)>25;$rows=array_slice($rows,0,25);
        if($tab==='quotes')$rows=array_map('quoteEffective',$rows);
        if($tab==='reservations')$rows=array_map('reservationEffective',$rows);
        return respond($response,['client'=>$client,'tab'=>$tab,'rows'=>$rows,'page'=>$page,'more'=>$more]);
    });
    $group->post('/clients/commercial',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$fields=titoCommercialFields(requestBody($request));$db=db();
        try{
            $st=$db->prepare('INSERT INTO habitual_clients(business_id,name,phone,notes,client_type,rut,business_activity,address,commune,contact_name,billing_email,is_active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([(int)$admin['business_id'],...$fields]);
            return respond($response,['ok'=>true,'client_id'=>(int)$db->lastInsertId()],201);
        }catch(PDOException $e){if((int)($e->errorInfo[1]??0)===1062)fail('Ya existe un cliente con ese RUT en la empresa.',409);throw $e;}
    });
    $group->post('/clients/commercial/{id:[0-9]+}/update',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$id=(int)$args['id'];$fields=titoCommercialFields(requestBody($request));$db=db();
        try{
            $st=$db->prepare('UPDATE habitual_clients SET name=?,phone=?,notes=?,client_type=?,rut=?,business_activity=?,address=?,commune=?,contact_name=?,billing_email=?,is_active=? WHERE id=? AND business_id=?');
            $st->execute([...$fields,$id,$biz]);
            $check=$db->prepare('SELECT id FROM habitual_clients WHERE id=? AND business_id=?');$check->execute([$id,$biz]);if(!$check->fetchColumn())fail('Cliente no encontrado.',404);
            return respond($response,['ok'=>true,'client_id'=>$id]);
        }catch(PDOException $e){if((int)($e->errorInfo[1]??0)===1062)fail('Ya existe otro cliente con ese RUT en la empresa.',409);throw $e;}
    });

    $group->get('/clients',static function(Request $request,Response $response): Response {
        $admin=titoStaffRequire($request,'can_sell_credit');$biz=(int)$admin['business_id'];
        $st=db()->prepare('SELECT id,name,phone,notes,credit_enabled,credit_limit_clp,is_active,created_at FROM habitual_clients WHERE business_id=? ORDER BY name,id LIMIT 500');
        $st->execute([$biz]);$clients=$st->fetchAll();
        $db=db();
        $accounts=$db->prepare('SELECT a.client_id,COALESCE(SUM(GREATEST(0,a.original_total_clp-COALESCE(adj.adjusted,0)-COALESCE(p.paid_clp,0))),0) AS outstanding_clp FROM credit_accounts a LEFT JOIN (SELECT account_id,SUM(amount_clp) paid_clp FROM credit_payments GROUP BY account_id) p ON p.account_id=a.id LEFT JOIN (SELECT account_id,SUM(amount_clp) adjusted FROM credit_adjustments GROUP BY account_id) adj ON adj.account_id=a.id WHERE a.business_id=? GROUP BY a.client_id');
        $accounts->execute([$biz]);$owed=[];
        foreach($accounts->fetchAll() as $row)$owed[(int)$row['client_id']]=(int)$row['outstanding_clp'];
        foreach($clients as &$c)$c['outstanding_clp']=$owed[(int)$c['id']]??0;
        unset($c);
        return respond($response,['clients'=>$clients]);
    });
    $group->post('/clients',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$b=requestBody($request);
        if(array_diff(array_keys($b),['name','phone','notes','credit_enabled','credit_limit_clp']))fail('Campos no permitidos.',422);
        $name=titoCreditString($b,'name',150,true);$phone=titoCreditString($b,'phone',80);$notes=titoCreditString($b,'notes',300);
        $enabled=$b['credit_enabled']??false;
        if(!is_bool($enabled))fail('Autorización de crédito inválida.',422);
        $limit=titoCreditUnsigned($b['credit_limit_clp']??0,'límite de crédito');
        if($enabled&&$limit===0)fail('El cliente requiere un límite de crédito mayor a cero.',422);
        $st=db()->prepare('INSERT INTO habitual_clients(business_id,name,phone,notes,credit_enabled,credit_limit_clp) VALUES(?,?,?,?,?,?)');
        $st->execute([(int)$admin['business_id'],$name,$phone,$notes,$enabled?1:0,$limit]);
        return respond($response,['ok'=>true,'client_id'=>(int)db()->lastInsertId()],201);
    });
    $group->post('/clients/{id:[0-9]+}/update',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$id=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['name','phone','notes','credit_enabled','credit_limit_clp','is_active']))fail('Campos no permitidos.',422);
        $enabled=$b['credit_enabled']??false;$active=$b['is_active']??true;
        if(!is_bool($enabled)||!is_bool($active))fail('Estado inválido.',422);
        $limit=titoCreditUnsigned($b['credit_limit_clp']??0,'límite de crédito');
        if($enabled&&$limit===0)fail('Define un límite de crédito positivo.',422);
        $name=titoCreditString($b,'name',150,true);$phone=titoCreditString($b,'phone',80);$notes=titoCreditString($b,'notes',300);
        $st=db()->prepare('UPDATE habitual_clients SET name=?,phone=?,notes=?,credit_enabled=?,credit_limit_clp=?,is_active=? WHERE id=? AND business_id=?');
        $st->execute([$name,$phone,$notes,$enabled?1:0,$limit,$active?1:0,$id,$biz]);
        $check=db()->prepare('SELECT id FROM habitual_clients WHERE id=? AND business_id=?');$check->execute([$id,$biz]);
        if(!$check->fetch())fail('Cliente no encontrado.',404);
        return respond($response,['ok'=>true,'client_id'=>$id]);
    });

    $group->get('/credit/accounts',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];
        $st=db()->prepare(titoCreditAccountListSql().' WHERE a.business_id=? ORDER BY a.id DESC LIMIT 200');
        $st->execute([$biz]);
        return respond($response,['accounts'=>$st->fetchAll()]);
    });
    $group->get('/credit/accounts/{id:[0-9]+}',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$id=(int)$args['id'];$db=db();
        $st=$db->prepare(titoCreditAccountListSql().' WHERE a.business_id=? AND a.id=? LIMIT 1');
        $st->execute([$biz,$id]);$account=$st->fetch();
        if(!$account)fail('Cuenta por cobrar no encontrada.',404);
        $st=$db->prepare('SELECT p.id,p.amount_clp,p.method,p.reference,p.created_at,u.email AS received_by_email FROM credit_payments p JOIN users u ON u.id=p.created_by WHERE p.business_id=? AND p.account_id=? ORDER BY p.id DESC');
        $st->execute([$biz,$id]);
        return respond($response,['account'=>$account,'payments'=>$st->fetchAll()]);
    });

    $group->post('/credit/sales',static function(Request $request,Response $response): Response {
        $admin=titoStaffRequire($request,'can_sell_credit');$biz=(int)$admin['business_id'];$uid=(int)$admin['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','client_id','due_date','notes','items','warehouse_id']))fail('Campos de venta a crédito no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);
        $clientId=filter_var($b['client_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($clientId===false)fail('Cliente inválido.',422);
        $due=titoCreditDate($b['due_date']??null);
        $notes=titoCreditString($b,'notes',250);
        $items=titoCreditLines($b['items']??null);
        $db=db();
        $saleLocation=titoSaleLocation($db,$admin,$b['warehouse_id']??null,true,$request);
        $hash=hash('sha256',json_encode([$clientId,$due,$notes,$items,$saleLocation],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        try{
            $db->beginTransaction();
            $cashSessionId=titoCashSessionForSale($db,$admin,$saleLocation);
            // Toda venta para un cliente bloquea su ficha: dos cajas no pueden
            // aprobar simultáneamente límites de crédito incompatibles.
            $st=$db->prepare('SELECT id,name,credit_enabled,credit_limit_clp,is_active FROM habitual_clients WHERE id=? AND business_id=? FOR UPDATE');
            $st->execute([$clientId,$biz]);$client=$st->fetch();$st->closeCursor();
            if(!$client)fail('Cliente no encontrado.',404);
            $st=$db->prepare('SELECT id,sale_id,original_total_clp,payload_hash FROM credit_accounts WHERE business_id=? AND request_id=?');
            $st->execute([$biz,$rid]);$prior=$st->fetch();$st->closeCursor();
            if($prior){
                if(!hash_equals((string)$prior['payload_hash'],$hash))fail('Identificador reutilizado con contenido diferente.',409);
                $db->commit();
                return respond($response,['ok'=>true,'sale_id'=>(int)$prior['sale_id'],'account_id'=>(int)$prior['id'],'total_clp'=>(int)$prior['original_total_clp'],'already_recorded'=>true]);
            }
            if((int)$client['is_active']!==1||(int)$client['credit_enabled']!==1)fail('El cliente no está habilitado para comprar a crédito.',409);
            $stock=$db->prepare('SELECT id,name,stock_qty,price_clp,sale_unit FROM products WHERE id=? AND business_id=? AND is_active=1 FOR UPDATE');
            $validated=[];$total=0;
            foreach($items as $item){
                $pid=$item['product_id'];$qty=(float)$item['quantity'];$agreed=$item['unit_price_clp'];
                $stock->execute([$pid,$biz]);$p=$stock->fetch();$stock->closeCursor();
                if(!$p)fail('Producto no disponible para venta.',409);
                if($p['sale_unit']==='unidad'&&floor($qty)!==$qty)fail('El producto exige cantidades enteras.',422);
                $list=(int)$p['price_clp'];
                if($agreed>$list||($list>0&&$agreed===0))fail('El precio acordado debe ser positivo y no superar el catálogo.',422);
                if($agreed<$list&&!$item['discount_reason'])fail('Indica el motivo del precio negociado.',422);
                if($agreed<$list&&!titoStaffCan($db,$admin,'can_approve_discounts'))fail('No tienes permiso para descuentos en ventas a crédito.',403);
                if((float)$p['stock_qty']+0.000001<$qty)fail('Stock insuficiente para '.$p['name'],409);
                // La validación específica se realiza bajo bloqueo al descontar de la sucursal.
                $subtotal=(int)round($qty*$agreed,0,PHP_ROUND_HALF_UP);
                $total+=$subtotal;
                if($total>1000000000000)fail('Venta fuera de rango.',422);
                $validated[]=['p'=>$p,'pid'=>$pid,'qty'=>$qty,'quantity'=>$item['quantity'],'price'=>$agreed,'list'=>$list,'subtotal'=>$subtotal,'reason'=>$agreed<$list?$item['discount_reason']:null];
            }
            if($total<=0)fail('Una venta a crédito debe tener importe positivo.',422);
            $st=$db->prepare('SELECT COALESCE(SUM(GREATEST(0,a.original_total_clp-COALESCE(adj.adjusted,0)-COALESCE(p.paid_clp,0))),0) FROM credit_accounts a LEFT JOIN (SELECT account_id,SUM(amount_clp) AS paid_clp FROM credit_payments GROUP BY account_id) p ON p.account_id=a.id LEFT JOIN (SELECT account_id,SUM(amount_clp) adjusted FROM credit_adjustments GROUP BY account_id) adj ON adj.account_id=a.id WHERE a.business_id=? AND a.client_id=?');
            $st->execute([$biz,$clientId]);$outstanding=(int)$st->fetchColumn();$st->closeCursor();
            if($outstanding+$total>(int)$client['credit_limit_clp'])fail('El crédito supera el límite autorizado. Revisa abonos o actualiza el límite del cliente.',409);
            // payment_method='otro' para compatibilidad con el esquema actual.
            // La fuente de verdad de "pendiente" es credit_accounts, NO sales.payment_method.
            $st=$db->prepare("INSERT INTO sales(business_id,sold_by,request_id,payment_method,total_clp,warehouse_id,cash_session_id) VALUES(?,?,?,'otro',?,?,?)");
            $st->execute([$biz,$uid,$rid,$total,$saleLocation,$cashSessionId]);$saleId=(int)$db->lastInsertId();
            salesPointRecord($db,$admin,$request,$saleId,$saleLocation);
            $saleItem=$db->prepare('INSERT INTO sale_items(sale_id,product_id,product_name_snapshot,quantity,unit_price_clp,list_price_clp,discount_reason,subtotal_clp) VALUES(?,?,?,?,?,?,?,?)');
            foreach($validated as $v){
                $p=$v['p'];$next=number_format(max(0,(float)$p['stock_qty']-$v['qty']),3,'.','');
                $next=titoApplyLocationStock($db,$biz,$v['pid'],$saleLocation,-$v['qty']);
                $saleItem->execute([$saleId,$v['pid'],$p['name'],$v['quantity'],$v['price'],$v['list'],$v['reason'],$v['subtotal']]);
                move($db,$biz,$v['pid'],$saleId,$uid,'sale',number_format(-$v['qty'],3,'.',''),$next,'Venta a crédito · Cliente #'.$clientId);
            }
            $st=$db->prepare('INSERT INTO credit_accounts(business_id,client_id,sale_id,created_by,request_id,payload_hash,original_total_clp,due_date,notes) VALUES(?,?,?,?,?,?,?,?,?)');
            $st->execute([$biz,$clientId,$saleId,$uid,$rid,$hash,$total,$due,$notes]);
            $accountId=(int)$db->lastInsertId();
            if(($admin['role']??'')==='cashier'){
                titoStaffEvent($db,$biz,$uid,'credit_sale',$saleId,$clientId,$total,'Venta a crédito delegada, cliente #'.$clientId);
            }
            $db->commit();
            if(($admin['role']??'')==='cashier'){
                titoStaffNotifyAdmins($db,$biz,'Tito POS - Fiado registrado','El cajero registró la venta a crédito #'.$saleId.'. Monto CLP: '.number_format($total,0,',','.').'. Revisa cuentas por cobrar.');
            }
            return respond($response,['ok'=>true,'sale_id'=>$saleId,'account_id'=>$accountId,'total_clp'=>$total,'balance_clp'=>$total,'warehouse_id'=>$saleLocation],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    });
    $group->post('/credit/accounts/{id:[0-9]+}/payments',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$uid=(int)$admin['id'];$accountId=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','amount_clp','method','reference']))fail('Campos de abono no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);
        $amount=titoCreditUnsigned($b['amount_clp']??null,'abono',1);
        $method=$b['method']??null;
        if(!in_array($method,['efectivo','transferencia','tarjeta','otro'],true))fail('Medio de abono inválido.',422);
        $ref=titoCreditString($b,'reference',120);
        $hash=hash('sha256',json_encode([$accountId,$amount,$method,$ref],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();
        try{
            $db->beginTransaction();
            // Todos los abonos a una cuenta se serializan por su fila.
            $st=$db->prepare('SELECT id,original_total_clp,status FROM credit_accounts WHERE id=? AND business_id=? FOR UPDATE');
            $st->execute([$accountId,$biz]);$account=$st->fetch();$st->closeCursor();
            if(!$account)fail('Cuenta no encontrada.',404);
            $st=$db->prepare('SELECT id,payload_hash FROM credit_payments WHERE business_id=? AND request_id=?');
            $st->execute([$biz,$rid]);$prior=$st->fetch();$st->closeCursor();
            if($prior){
                if(!hash_equals((string)$prior['payload_hash'],$hash))fail('Abono reutilizado con distinto importe.',409);
                $db->commit();return respond($response,['ok'=>true,'payment_id'=>(int)$prior['id'],'already_recorded'=>true]);
            }
            if($account['status']!=='active')fail('Cuenta anulada; no puede registrar nuevos abonos.',409);
            $totals=titoCreditTotals($db,$biz,$accountId,(int)$account['original_total_clp']);
            $balance=$totals['balance_clp'];
            if($amount>$balance||$balance<=0)fail('El abono supera el saldo pendiente o la cuenta ya está pagada.',409);
            $cashSessionId=titoCashSessionForReceipt($db,$admin,$request);
            $st=$db->prepare('INSERT INTO credit_payments(business_id,account_id,created_by,request_id,payload_hash,amount_clp,method,reference,cash_session_id) VALUES(?,?,?,?,?,?,?,?,?)');
            $st->execute([$biz,$accountId,$uid,$rid,$hash,$amount,$method,$ref,$cashSessionId]);
            $paymentId=(int)$db->lastInsertId();
            if($method==='efectivo')
                titoCashRegisterExternal($db,$biz,$uid,$cashSessionId,'credit_payment',$paymentId,$amount);
            $db->commit();
            return respond($response,['ok'=>true,'payment_id'=>$paymentId,'account_id'=>$accountId,'amount_clp'=>$amount,'balance_clp'=>$balance-$amount],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    });
}

