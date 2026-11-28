<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Slim\Exception\HttpException;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../private/bootstrap.php';
require __DIR__ . '/../private/http.php';
require __DIR__ . '/../private/import_products.php';
require __DIR__ . '/../private/users_sales.php';
require __DIR__ . '/../private/access_roles.php';
require __DIR__ . '/../private/organization.php';
require __DIR__ . '/../private/sales_points.php';
require __DIR__ . '/../private/origin_catalog.php';
require __DIR__ . '/../private/serials.php';
require __DIR__ . '/../private/reservations.php';
require __DIR__ . '/../private/platform.php';
require __DIR__ . '/../private/catalog_maintenance.php';
require __DIR__ . '/../private/receipts.php';
require __DIR__ . '/../private/inventory_reports.php';
require __DIR__ . '/../private/quotes.php';
require __DIR__ . '/../private/customer_returns.php';
require __DIR__ . '/../private/service_cases.php';
require __DIR__ . '/../private/warranties.php';
require __DIR__ . '/../private/replacement_warranties.php';
require __DIR__ . '/../private/traceability.php';
require __DIR__ . '/../private/habitual_credit.php';
require __DIR__ . '/../private/credit_operations.php';
require __DIR__ . '/../private/product_creations.php';
require __DIR__ . '/../private/warehouses.php';
require __DIR__ . '/../private/cash_register.php';
require __DIR__ . '/../private/pilot_completion.php';
require __DIR__ . '/../private/discount_approvals.php';
require __DIR__ . '/../private/remote_discount_requests.php';
require __DIR__ . '/../private/onesignal_push.php';
require __DIR__ . '/../private/delegated_permissions.php';

$app=AppFactory::create();
$app->setBasePath('/tito-trazabilidad-api');
$app->addRoutingMiddleware();
$app->add(function(Request $request, RequestHandlerInterface $handler): Response {
    $origin=$request->getHeaderLine('Origin');
    $allowed=config()['allowed_origins']??[];
    if($origin!=='' && !in_array($origin,$allowed,true)){
        fail('Origen no autorizado.',403);
    }
    $response=$handler->handle($request);
    $response=$response->withHeader('X-Content-Type-Options','nosniff')
        ->withHeader('Referrer-Policy','no-referrer')
        ->withHeader('Cache-Control','no-store');
    if($origin!==''){
        $response=$response->withHeader('Access-Control-Allow-Origin',$origin)
            ->withHeader('Vary','Origin')
            ->withHeader('Access-Control-Allow-Headers','Authorization, Content-Type, X-Tito-Terminal')
            ->withHeader('Access-Control-Allow-Methods','GET, POST, OPTIONS')
            ->withHeader('Access-Control-Max-Age','600');
    }
    return $response;
});
$errorMiddleware=$app->addErrorMiddleware(false,true,true);
$errorMiddleware->setDefaultErrorHandler(function(Request $request, Throwable $error, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails) use($app): Response {
    if($error instanceof HttpError){
        $status=$error->status;
        $message=$error->getMessage();
    }elseif($error instanceof HttpException){
        $status=$error->getCode()>=400 && $error->getCode()<600 ? $error->getCode() : 404;
        $message=$status===404?'Ruta no encontrada.':'Solicitud no admitida.';
    }else{
        error_log('TitoPOS API: '.$error->getMessage());
        $status=503;
        $message='No fue posible completar la operación.';
    }
    $response=$app->getResponseFactory()->createResponse();
    $payload=['error'=>$message];
    if($error instanceof HttpError && $error->field!==null)$payload['field']=$error->field;
    $response=respond($response,$payload,$status);
    // El middleware de error está fuera de CORS; devolver el error legible solo al origen permitido.
    $origin=$request->getHeaderLine('Origin');
    try {
        if($origin!=='' && in_array($origin,config()['allowed_origins']??[],true)){
            $response=$response->withHeader('Access-Control-Allow-Origin',$origin)->withHeader('Vary','Origin');
        }
    }catch(Throwable){}
    return $response;
});

$app->options('/{routes:.*}', static function(Request $request,Response $response): Response {
    return $response->withStatus(204);
});

$app->get('/health',static function(Request $request,Response $response): Response {
    db()->query('SELECT 1')->fetchColumn();
    return respond($response,['ok'=>true,'service'=>'tito-pos-slim4','database'=>'connected']);
});

$app->post('/auth/login',static function(Request $request,Response $response): Response {
    $b=requestBody($request);
    $email=strtolower(requireString($b,'email',190));
    $password=$b['password']??null;
    if(!is_string($password)||strlen($password)>512)fail('Credenciales incorrectas.',401);
    throttleLogin($email,false);
    $db=db();
    $st=$db->prepare('SELECT users.id,users.business_id,users.email,users.role,users.password_hash,businesses.name AS business_name FROM users JOIN businesses ON businesses.id=users.business_id WHERE users.email=? AND users.is_active=1 LIMIT 1');
    $st->execute([$email]);$user=$st->fetch();
    if(!$user||!password_verify($password,$user['password_hash'])){
        throttleLogin($email,true);
        fail('Credenciales incorrectas.',401);
    }
    throttleLogin($email,null);
    // Serializar el inicio de sesión con la suspensión de empresa.
    $db->beginTransaction();
    try{
        $st=$db->prepare('SELECT id FROM businesses WHERE id=? FOR UPDATE');$st->execute([(int)$user['business_id']]);
        if(!$st->fetchColumn())fail('Credenciales incorrectas.',401);
        $st=$db->prepare('SELECT is_active FROM business_access_state WHERE business_id=? FOR UPDATE');$st->execute([(int)$user['business_id']]);
        $state=$st->fetchColumn();
        if($state!==false&&(int)$state!==1)fail('Empresa desactivada. Contacta al administrador de la plataforma.',403);
        $st=$db->prepare('SELECT password_hash,is_active,role,email FROM users WHERE id=? AND business_id=? FOR UPDATE');
        $st->execute([(int)$user['id'],(int)$user['business_id']]);$current=$st->fetch();
        if(!$current||(int)$current['is_active']!==1||!hash_equals($user['password_hash'],$current['password_hash']))fail('Credenciales incorrectas.',401);
        $user['role']=$current['role'];$user['email']=$current['email'];
        $token=bin2hex(random_bytes(32));
        $st=$db->prepare('INSERT INTO sessions(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 4 HOUR))');
        $st->execute([(int)$user['id'],hash('sha256',$token)]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    unset($user['password_hash']);
    $user=accessIdentity($db,$user);
    return respond($response,['token'=>$token,'user'=>$user]);
});

$authMiddleware=static function(Request $request,RequestHandlerInterface $handler): Response {
    $db=db();$user=accessIdentity($db,authUser($request,$db));
    accessEnforce($request,$user);
    return $handler->handle($request->withAttribute('currentUser',$user));
};

$app->group('', static function(RouteCollectorProxy $group): void {
    $group->get('/me', static function(Request $request,Response $response): Response {
        return respond($response,['user'=>$request->getAttribute('currentUser')]);
    });
    $group->post('/auth/logout', static function(Request $request,Response $response): Response {
        $token=substr($request->getHeaderLine('Authorization'),7);
        $st=db()->prepare('DELETE FROM sessions WHERE token_hash=?');
        $st->execute([hash('sha256',strtolower($token))]);
        return respond($response,['ok'=>true]);
    });
    $group->get('/products',static function(Request $request,Response $response): Response {
        $biz=(int)$request->getAttribute('currentUser')['business_id'];
        $st=db()->prepare('SELECT p.id,p.name,p.sku,p.barcode,p.price_clp,p.sale_unit,p.stock_qty,p.is_active,(c.product_id IS NOT NULL) AS serial_control FROM products p LEFT JOIN serial_product_controls c ON c.product_id=p.id AND c.business_id=p.business_id WHERE p.business_id=? AND p.is_active=1 ORDER BY p.name,p.id LIMIT 1000');
        $st->execute([$biz]);
        return respond($response,['products'=>$st->fetchAll()]);
    });
    $group->post('/products/import',static function(Request $request,Response $response): Response {
        $user=adminOnly($request);
        $db=db();
        titoProductCreationActor($db,(int)$user['business_id'],(int)$user['id'],'excel');
        $data=importProducts($db,(int)$user['business_id'],(int)$user['id'],requestBody($request));
        return respond($response,$data,empty($data['already_recorded'])?201:200);
    });
    $group->post('/products',static function(Request $request,Response $response): Response {
        $user=adminOnly($request);
        $biz=(int)$user['business_id'];$uid=(int)$user['id'];
        $b=requestBody($request);
        $name=requireString($b,'name');
        $price=integerPrice($b['price_clp']??null);
        $unit=$b['sale_unit']??'unidad';
        if(!in_array($unit,['unidad','kg','litro'],true))fail('Unidad inválida.',422);
        $stock=decimalQuantity($b['stock_qty']??0,false,$unit!=='unidad');
        $sku=$b['sku']??null;$barcode=$b['barcode']??null;
        foreach(['sku'=>&$sku,'barcode'=>&$barcode] as $key=>&$value){
            if($value!==null && (!is_string($value)||strlen($value)>50))fail('Código inválido: '.$key,422);
            $value=$value===null?null:(trim($value)?:null);
        }
        unset($value);
        if($sku===null&&$barcode===null)fail('Ingresa SKU o código de barras.',422);
        $db=db();
        try{
            $db->beginTransaction();
            titoProductCreationActor($db,$biz,$uid,'manual');
            $st=$db->prepare('INSERT INTO products(business_id,name,sku,barcode,price_clp,sale_unit,stock_qty) VALUES (?,?,?,?,?,?,?)');
            $st->execute([$biz,$name,$sku,$barcode,$price,$unit,$stock]);
            $id=(int)$db->lastInsertId();
            if((float)$stock>0)move($db,$biz,$id,null,$uid,'initial',$stock,$stock,'Stock inicial');
            $db->commit();
            return respond($response,['ok'=>true,'product_id'=>$id],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062)fail('SKU o código de barras duplicado.',409);
            throw $e;
        }
    });
    $group->get('/sales',static function(Request $request,Response $response): Response {
        $user=$request->getAttribute('currentUser');
        $biz=(int)$user['business_id'];
        $sql='SELECT s.id,s.status,s.voided_at,s.total_clp,s.payment_method,s.created_at,s.sold_by,s.warehouse_id,w.name AS warehouse_name,u.email AS seller_email,sp.point_name AS sales_point_name,sp.branch_name AS commercial_branch_name,s.client_id,s.client_name_snapshot AS client_name,s.client_rut_snapshot AS client_rut,s.customer_mode FROM sales s JOIN users u ON u.id=s.sold_by LEFT JOIN warehouses w ON w.id=s.warehouse_id AND w.business_id=s.business_id LEFT JOIN sale_point_snapshots sp ON sp.sale_id=s.id AND sp.business_id=s.business_id WHERE s.business_id=?';
        $params=[$biz];
        if(($user['role']??'')!=='admin'){$sql.=' AND s.sold_by=?';$params[]=(int)$user['id'];}
        $st=db()->prepare($sql.' ORDER BY s.id DESC LIMIT 100');
        $st->execute($params);
        return respond($response,['sales'=>$st->fetchAll()]);
    });
    $group->post('/sales',static function(Request $request,Response $response): Response {
        $user=$request->getAttribute('currentUser');
        $biz=(int)$user['business_id'];$uid=(int)$user['id'];
        $b=requestBody($request);
        $payment=$b['payment_method']??null;
        if(!in_array($payment,['efectivo','transferencia','tarjeta','otro'],true))fail('Medio de pago inválido.',422);
        $requestId=$b['request_id']??null;
        if(!is_string($requestId)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$requestId))fail('Identificador de venta inválido.',422);
        $items=$b['items']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>50)fail('Ingresa entre 1 y 50 productos.',422);
        $wanted=[];
        foreach($items as $item){
            if(!is_array($item)||!isset($item['product_id'],$item['quantity'])||filter_var($item['product_id'],FILTER_VALIDATE_INT)===false)fail('Producto inválido.',422);
            $id=(int)$item['product_id'];
            if($id<1||isset($wanted[$id]))fail('No repitas productos en la venta.',422);
            $wanted[$id]=[
                'quantity'=>$item['quantity'],
                'unit_price_clp'=>$item['unit_price_clp']??null,
                'discount_reason'=>$item['discount_reason']??null,
            ];
            if(array_key_exists('serial_numbers',$item))$wanted[$id]['serial_numbers']=serialNormalizeList($item['serial_numbers']);
        }
        ksort($wanted,SORT_NUMERIC);
        $hasSerial=false;foreach($wanted as $spec)if(array_key_exists('serial_numbers',$spec))$hasSerial=true;
        $reservationId=array_key_exists('reservation_id',$b)?reservationId($b['reservation_id']):null;
        if($reservationId!==null&&!$hasSerial)fail('La reserva requiere series para despachar.',422);
        $clientId=originId($b['client_id']??null,'client_id');$serialHash=null;
        $legacyClient=originId($b['serial_client_id']??null,'serial_client_id');
        if($clientId!==null&&$legacyClient!==null&&$clientId!==$legacyClient)fail('Los clientes indicados no coinciden.',422);
        $clientId=$clientId??$legacyClient;
        $mode=$b['customer_mode']??($clientId===null?'consumer_final':'registered');
        if(!in_array($mode,['registered','consumer_final'],true)||($mode==='registered')!==($clientId!==null))fail('Identificación de cliente inválida.',422);
        if($hasSerial){
            if($clientId===null)throw new HttpError('Selecciona el cliente para la venta de equipos.',422,'serial_client_id');
            if(isset($b['discount_approval_token'])||isset($b['discount_request_id']))fail('La autorización de descuentos en ventas por serie queda pendiente de integración.',422);
            $canonical=[$uid,$payment,$b['warehouse_id']??null,$clientId,$wanted];if($reservationId!==null)$canonical[]=$reservationId;
            $serialHash=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        }elseif(array_key_exists('serial_client_id',$b))fail('Cliente serializado sin series.',422);
        $directHash=hash('sha256',json_encode([$uid,$payment,$b['warehouse_id']??null,$mode,$clientId,$wanted,$reservationId],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();
        try{
            $db->beginTransaction();
            originLockCompany($db,$biz);
            $old=$db->prepare('SELECT s.id,s.sold_by,s.total_clp,s.warehouse_id,s.direct_payload_hash,s.client_id,ss.payload_hash FROM sales s LEFT JOIN serial_sale_snapshots ss ON ss.sale_id=s.id AND ss.business_id=s.business_id WHERE s.business_id=? AND s.request_id=? FOR UPDATE');$old->execute([$biz,$requestId]);$prior=$old->fetch();$old->closeCursor();
            if($prior){
                if((int)$prior['sold_by']!==$uid||($prior['payload_hash']!==null&&(!$hasSerial||!hash_equals((string)$prior['payload_hash'],$serialHash)))||($hasSerial&&$prior['payload_hash']===null))fail('Identificador de venta reutilizado con otros datos.',409);
                if($prior['direct_payload_hash']!==null&&!hash_equals((string)$prior['direct_payload_hash'],$directHash))fail('Identificador de venta reutilizado con otros datos.',409);
                if($prior['direct_payload_hash']===null&&!$hasSerial&&$clientId!==null)fail('No se puede reasignar el cliente de una venta ya registrada.',409);
                $db->commit();return respond($response,['ok'=>true,'sale_id'=>(int)$prior['id'],'total_clp'=>(int)$prior['total_clp'],'warehouse_id'=>$prior['warehouse_id'],'already_recorded'=>true]);
            }
            $clientName=null;$clientRut=null;
            if($clientId!==null){$c=$db->prepare('SELECT name,rut FROM habitual_clients WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');$c->execute([$biz,$clientId]);$customer=$c->fetch();$c->closeCursor();if(!$customer)throw new HttpError('Cliente activo no encontrado.',422,'client_id');$clientName=$customer['name'];$clientRut=$customer['rut'];}
            $saleLocation=titoSaleLocation($db,$user,$b['warehouse_id']??null,true,$request);
            if($reservationId!==null)reservationLockForSale($db,$biz,$reservationId,$clientId,$saleLocation,$wanted);
            $cashSessionId=titoCashSessionForSale($db,$user,$saleLocation);
            $st=$db->prepare('INSERT INTO sales(business_id,sold_by,request_id,payment_method,total_clp,warehouse_id,cash_session_id,client_id,client_name_snapshot,client_rut_snapshot,customer_mode,direct_payload_hash) VALUES (?,?,?,?,0,?,?,?,?,?,?,?)');
            $st->execute([$biz,$uid,$requestId,$payment,$saleLocation,$cashSessionId,$clientId,$clientName,$clientRut,$mode,$directHash]);
            $saleId=(int)$db->lastInsertId();
            if($hasSerial){$s=$db->prepare('INSERT INTO serial_sale_snapshots(sale_id,business_id,client_id,client_name,payload_hash) VALUES(?,?,?,?,?)');$s->execute([$saleId,$biz,$clientId,$clientName,$serialHash]);}
            salesPointRecord($db,$user,$request,$saleId,$saleLocation);
            $discountApproval=titoConsumeDiscountApproval($db,$user,$b,$saleId);
            $lock=$db->prepare('SELECT id,name,price_clp,stock_qty,sale_unit FROM products WHERE id=? AND business_id=? AND is_active=1 FOR UPDATE');
            $insert=$db->prepare('INSERT INTO sale_items(sale_id,product_id,product_name_snapshot,quantity,unit_price_clp,list_price_clp,discount_reason,subtotal_clp) VALUES(?,?,?,?,?,?,?,?)');
            $total=0;
            foreach($wanted as $id=>$spec){
                $lock->execute([$id,$biz]);$product=$lock->fetch();$lock->closeCursor();
                if(!$product)fail('Producto no disponible: '.$id,422);
                $qty=decimalQuantity($spec['quantity'],false,$product['sale_unit']!=='unidad');
                if((float)$qty<=0)fail('Cantidad inválida.',422);
                $remaining=(float)$product['stock_qty']-(float)$qty;
                if($remaining< -0.00001)fail('Stock insuficiente para '.$product['name'],409);
                $next=number_format(max(0,$remaining),3,'.','');
                $listPrice=(int)$product['price_clp'];
                if($discountApproval!==null){
                    if(!array_key_exists($id,$discountApproval))fail('Producto no autorizado para esta venta.',409);
                    if(array_key_exists('discount_request_id',$b)){
                        // Solicitud remota vigente: precios históricos del carrito firmado.
                        $listPrice=(int)$discountApproval[$id];
                    }elseif((int)$discountApproval[$id]!==$listPrice){
                        // Conserva la regla original de la autorización presencial.
                        fail('Cambió el precio del catálogo. Solicita otra autorización.',409);
                    }
                }
                $unitPrice=$spec['unit_price_clp']===null ? $listPrice : integerPrice($spec['unit_price_clp']);
                if($unitPrice>$listPrice)fail('El precio acordado no puede superar el de lista.',422);
                if($unitPrice===0 && $listPrice>0)fail('El precio acordado debe ser mayor que cero.',422);
                $reason=null;
                if($unitPrice<$listPrice){
                    // Solo propietarios/administradores pueden aplicar rebajas.
                    if(($user['role']??'')!=='admin' && $discountApproval===null)fail('El descuento requiere autorización de administrador.',403);
                    $reason=$spec['discount_reason'];
                    if(!is_string($reason)||trim($reason)===''||mb_strlen(trim($reason))>120)fail('Indica el motivo del precio especial.',422);
                    $reason=trim($reason);
                }
                $sub=(int)round((float)$qty*$unitPrice,0,PHP_ROUND_HALF_UP);
                $total+=$sub;
                if($total>1000000000000)fail('Venta demasiado grande.',422);
                $controlled=serialControlled($db,$biz,$id);$numbers=$spec['serial_numbers']??[];
                if($controlled){
                    if(!$hasSerial||$product['sale_unit']!=='unidad'||(float)$qty!==(float)count($numbers))throw new HttpError('Selecciona una serie disponible por unidad de '.$product['name'].'.',422,'serial_numbers');
                    serialReconcile($db,$biz,$id,(float)$product['stock_qty']);
                    serialRequireFree($db,$biz,$id,$saleLocation,(float)$qty,$reservationId);
                    $next=titoSellSerialLocationStock($db,$biz,$id,$saleLocation,(float)$qty,$reservationId);
                }else{
                    if(array_key_exists('serial_numbers',$spec))fail('El producto no tiene control de series.',422);
                    $next=titoApplyLocationStock($db,$biz,$id,$saleLocation,-(float)$qty);
                }
                $insert->execute([$saleId,$id,$product['name'],$qty,$unitPrice,$listPrice,$reason,$sub]);
                $lineId=(int)$db->lastInsertId();
                if($controlled){serialSaleRecord($db,$user,$id,$saleLocation,$saleId,$lineId,$unitPrice,$numbers);serialReconcile($db,$biz,$id,(float)$next);}
                move($db,$biz,$id,$saleId,$uid,'sale',number_format(-(float)$qty,3,'.',''),$next,'Venta registrada');
            }
            $st=$db->prepare('UPDATE sales SET total_clp=? WHERE id=? AND business_id=?');
            $st->execute([$total,$saleId,$biz]);
            titoCashRegisterSale($db,$biz,$uid,$cashSessionId,$saleId,$requestId,$total,$payment);
            if($reservationId!==null)reservationComplete($db,$biz,$reservationId,$saleId,$uid);
            warrantyRecordSale($db,$biz,$saleId,$clientId);
            $db->commit();
            return respond($response,['ok'=>true,'sale_id'=>$saleId,'total_clp'=>$total,'warehouse_id'=>$saleLocation],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062){
                $st=$db->prepare('SELECT id,total_clp FROM sales WHERE business_id=? AND request_id=?');
                $st->execute([$biz,$requestId]);$prior=$st->fetch();
                if($prior&&!$hasSerial)fail('Identificador de venta ya registrado; consulta el historial antes de reintentar.',409);
            }
            throw $e;
        }
    });
    $group->post('/stock/adjust',static function(Request $request,Response $response): Response {
        $user=adminOnly($request);$biz=(int)$user['business_id'];$uid=(int)$user['id'];
        $b=requestBody($request);$pid=(int)($b['product_id']??0);
        $reason=requireString($b,'reason',250);
        if($pid<1)fail('Producto inválido.',422);
        $db=db();
        try{
            $db->beginTransaction();originLockCompany($db,$biz);
            $st=$db->prepare('SELECT id,name,stock_qty,sale_unit FROM products WHERE id=? AND business_id=? AND is_active=1 FOR UPDATE');
            $st->execute([$pid,$biz]);$product=$st->fetch();$st->closeCursor();
            if(!$product)fail('Producto no encontrado.',404);
            $delta=decimalQuantity($b['delta_qty']??null,true,$product['sale_unit']!=='unidad');
            if((float)$delta===0.0)fail('El ajuste no puede ser cero.',422);
            $next=(float)$product['stock_qty']+(float)$delta;
            if($next< -0.00001 || $next>1000000000)fail('Stock resultante inválido.',422);
            serialRejectLegacy($db,$biz,$pid);
            if((float)$delta<0)serialRequireFree($db,$biz,$pid,titoLocalLocationId($db,$biz),abs((float)$delta));
            $balance=number_format(max(0,$next),3,'.','');
            $st=$db->prepare('UPDATE products SET stock_qty=? WHERE id=? AND business_id=?');
            $st->execute([$balance,$pid,$biz]);
            move($db,$biz,$pid,null,$uid,'adjustment',$delta,$balance,$reason);
            $db->commit();
            return respond($response,['ok'=>true,'product_id'=>$pid,'stock_qty'=>$balance]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    registerUserAndSaleDetailRoutes($group);
    registerAccessRoleRoutes($group);
    registerOrganizationRoutes($group);
    registerSalesPointRoutes($group);
    registerOriginCatalogRoutes($group);
    registerSerialRoutes($group);
    registerTraceabilityRoutes($group);
    registerReservationRoutes($group);
    registerCatalogMaintenanceRoutes($group);
    registerGoodsReceiptRoutes($group);
    registerInventoryReportRoutes($group);
    registerQuoteRoutes($group);
    registerCustomerReturnRoutes($group);
    registerServiceRoutes($group);
    registerReplacementWarrantyRoutes($group);
    registerWarrantyRoutes($group);
    registerHabitualCreditRoutes($group);
    registerCreditOperationsRoutes($group);
    registerProductCreationAuditRoutes($group);
    registerWarehouseRoutes($group);
    registerCashRoutes($group);
    registerPilotCompletionRoutes($group);
    registerDiscountApprovalRoutes($group);
    registerRemoteDiscountRoutes($group);
    registerOneSignalPushRoutes($group);
    registerDelegatedPermissionsRoutes($group);
})->add($authMiddleware);

registerPlatformRoutes($app);

$app->run();
