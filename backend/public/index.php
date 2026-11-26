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
require __DIR__ . '/../private/organization.php';
require __DIR__ . '/../private/platform.php';
require __DIR__ . '/../private/catalog_maintenance.php';
require __DIR__ . '/../private/receipts.php';
require __DIR__ . '/../private/inventory_reports.php';
require __DIR__ . '/../private/quotes.php';
require __DIR__ . '/../private/customer_returns.php';
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
    $response=respond($response,['error'=>$message],$status);
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
    $token=bin2hex(random_bytes(32));
    $st=$db->prepare('INSERT INTO sessions(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 4 HOUR))');
    $st->execute([(int)$user['id'],hash('sha256',$token)]);
    unset($user['password_hash']);
    return respond($response,['token'=>$token,'user'=>$user]);
});

$authMiddleware=static function(Request $request,RequestHandlerInterface $handler): Response {
    return $handler->handle($request->withAttribute('currentUser',authUser($request,db())));
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
        $st=db()->prepare('SELECT id,name,sku,barcode,price_clp,sale_unit,stock_qty,is_active FROM products WHERE business_id=? AND is_active=1 ORDER BY name,id LIMIT 1000');
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
        $sql='SELECT s.id,s.status,s.voided_at,s.total_clp,s.payment_method,s.created_at,s.sold_by,s.warehouse_id,w.name AS warehouse_name,u.email AS seller_email FROM sales s JOIN users u ON u.id=s.sold_by LEFT JOIN warehouses w ON w.id=s.warehouse_id AND w.business_id=s.business_id WHERE s.business_id=?';
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
        }
        ksort($wanted,SORT_NUMERIC);
        $db=db();
        try{
            $db->beginTransaction();
            $saleLocation=titoSaleLocation($db,$user,$b['warehouse_id']??null,true,$request);
            $cashSessionId=titoCashSessionForSale($db,$user,$saleLocation);
            $st=$db->prepare('INSERT INTO sales(business_id,sold_by,request_id,payment_method,total_clp,warehouse_id,cash_session_id) VALUES (?,?,?,?,0,?,?)');
            $st->execute([$biz,$uid,$requestId,$payment,$saleLocation,$cashSessionId]);
            $saleId=(int)$db->lastInsertId();
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
                $next=titoApplyLocationStock($db,$biz,$id,$saleLocation,-(float)$qty);
                $insert->execute([$saleId,$id,$product['name'],$qty,$unitPrice,$listPrice,$reason,$sub]);
                move($db,$biz,$id,$saleId,$uid,'sale',number_format(-(float)$qty,3,'.',''),$next,'Venta registrada');
            }
            $st=$db->prepare('UPDATE sales SET total_clp=? WHERE id=? AND business_id=?');
            $st->execute([$total,$saleId,$biz]);
            titoCashRegisterSale($db,$biz,$uid,$cashSessionId,$saleId,$requestId,$total,$payment);
            $db->commit();
            return respond($response,['ok'=>true,'sale_id'=>$saleId,'total_clp'=>$total,'warehouse_id'=>$saleLocation],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062){
                $st=$db->prepare('SELECT id,total_clp FROM sales WHERE business_id=? AND request_id=?');
                $st->execute([$biz,$requestId]);$prior=$st->fetch();
                if($prior)return respond($response,['ok'=>true,'sale_id'=>(int)$prior['id'],'total_clp'=>(int)$prior['total_clp'],'already_recorded'=>true]);
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
            $db->beginTransaction();
            $st=$db->prepare('SELECT id,name,stock_qty,sale_unit FROM products WHERE id=? AND business_id=? AND is_active=1 FOR UPDATE');
            $st->execute([$pid,$biz]);$product=$st->fetch();$st->closeCursor();
            if(!$product)fail('Producto no encontrado.',404);
            $delta=decimalQuantity($b['delta_qty']??null,true,$product['sale_unit']!=='unidad');
            if((float)$delta===0.0)fail('El ajuste no puede ser cero.',422);
            $next=(float)$product['stock_qty']+(float)$delta;
            if($next< -0.00001 || $next>1000000000)fail('Stock resultante inválido.',422);
            if((float)$delta<0)titoCheckStoreStock($db,$biz,$pid,abs((float)$delta),$product['name']);
            $balance=number_format(max(0,$next),3,'.','');
            $st=$db->prepare('UPDATE products SET stock_qty=? WHERE id=? AND business_id=?');
            $st->execute([$balance,$pid,$biz]);
            move($db,$biz,$pid,null,$uid,'adjustment',$delta,$balance,$reason);
            $db->commit();
            return respond($response,['ok'=>true,'product_id'=>$pid,'stock_qty'=>$balance]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    registerUserAndSaleDetailRoutes($group);
    registerOrganizationRoutes($group);
    registerCatalogMaintenanceRoutes($group);
    registerGoodsReceiptRoutes($group);
    registerInventoryReportRoutes($group);
    registerQuoteRoutes($group);
    registerCustomerReturnRoutes($group);
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
