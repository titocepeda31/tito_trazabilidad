<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Huella estable de una venta: la autorización no sirve para otro carrito, pago o petición. */
function titoSaleDiscountFingerprint(array $sale): string {
    $rid=$sale['request_id']??null;
    if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$rid))fail('Identificador de venta inválido.',422);
    $payment=$sale['payment_method']??null;
    if(!in_array($payment,['efectivo','transferencia','tarjeta','otro'],true))fail('Medio de pago inválido.',422);
    if(array_key_exists('warehouse_id',$sale) && (!is_int($sale['warehouse_id'])||$sale['warehouse_id']<1))fail('Sucursal inválida en el carrito.',422);
    $items=$sale['items']??null;
    if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>50)fail('Carrito inválido.',422);
    $normalized=[];
    foreach($items as $line){
        if(!is_array($line)||array_diff(array_keys($line),['product_id','quantity','unit_price_clp','discount_reason']))fail('Campos de línea no permitidos.',422);
        $pid=filter_var($line['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($pid===false||isset($normalized[$pid]))fail('Producto repetido o inválido.',422);
        $quantity=$line['quantity']??null;
        if(!is_int($quantity)&&!is_float($quantity))fail('Cantidad inválida.',422);
        if(!is_finite((float)$quantity)||(float)$quantity<=0||(float)$quantity>1000000000||abs((float)$quantity*1000-round((float)$quantity*1000))>0.00001)fail('Cantidad inválida.',422);
        $price=array_key_exists('unit_price_clp',$line)?integerPrice($line['unit_price_clp']):null;
        $reason=$line['discount_reason']??null;
        if($reason!==null&&(!is_string($reason)||mb_strlen(trim($reason))>120))fail('Motivo inválido.',422);
        $normalized[$pid]=[
            'product_id'=>$pid,
            'quantity'=>number_format((float)$quantity,3,'.',''),
            'unit_price_clp'=>$price,
            'discount_reason'=>$reason===null?null:trim($reason)
        ];
    }
    ksort($normalized,SORT_NUMERIC);
    return hash('sha256',json_encode([
        'request_id'=>strtolower($rid),
        'payment_method'=>$payment,
        'warehouse_id'=>$sale['warehouse_id']??null,
        'items'=>array_values($normalized)
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}

/** Se ejecuta dentro de la transacción de POST /sales, antes de modificar existencias. */
function titoConsumeDiscountApproval(PDO $db,array $user,array $sale,int $saleId): ?array {
    if(($user['role']??'')==='admin')return null;
    if(array_key_exists('discount_request_id',$sale))return titoConsumeRemoteDiscountApproval($db,$user,$sale,$saleId);
    $token=$sale['discount_approval_token']??null;
    if($token===null)return null;
    if(!is_string($token)||!preg_match('/^[0-9a-f]{64}$/i',$token))fail('Autorización de descuento inválida.',403);
    $fp=titoSaleDiscountFingerprint($sale);
    $st=$db->prepare('SELECT id,supervisor_id,payload_hash,price_snapshot_json FROM sale_discount_approvals WHERE business_id=? AND cashier_id=? AND sale_request_id=? AND token_hash=? AND used_sale_id IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
    $st->execute([(int)$user['business_id'],(int)$user['id'],strtolower($sale['request_id']),hash('sha256',$token)]);
    $approval=$st->fetch();$st->closeCursor();
    if(!$approval||!hash_equals((string)$approval['payload_hash'],$fp))fail('La autorización no corresponde a esta venta o ya venció.',403);
    if((int)$approval['supervisor_id']===(int)$user['id']&&!titoStaffCan($db,$user,'can_approve_discounts'))fail('La delegación fue revocada. Solicita nueva autorización.',403);
    $snap=json_decode((string)$approval['price_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
    if(!is_array($snap)||!$snap)fail('Precios de autorización inválidos.',409);
    $st=$db->prepare('UPDATE sale_discount_approvals SET used_sale_id=? WHERE id=? AND used_sale_id IS NULL');
    $st->execute([$saleId,(int)$approval['id']]);
    if($st->rowCount()!==1)fail('Autorización ya utilizada.',409);
    return $snap;
}

function registerDiscountApprovalRoutes(RouteCollectorProxy $group): void {
    $group->post('/sales/discount-approve',static function(Request $request,Response $response): Response {
        $user=$request->getAttribute('currentUser');
        if(!is_array($user)||($user['role']??'')!=='cashier')fail('Solo los cajeros requieren autorización.',403);
        $biz=(int)$user['business_id'];$uid=(int)$user['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['sale','supervisor_email','supervisor_password']))fail('Campos inesperados.',422);
        $sale=$b['sale']??null;
        if(!is_array($sale)||array_diff(array_keys($sale),['request_id','payment_method','items','warehouse_id']))fail('Venta a autorizar inválida.',422);
        $email=$b['supervisor_email']??null;$password=$b['supervisor_password']??null;
        if(!is_string($email)||!filter_var($email,FILTER_VALIDATE_EMAIL)||!is_string($password)||$password==='')fail('Credenciales de supervisor inválidas.',422);
        $email=strtolower(trim($email));
        $db=db();
        titoSaleLocation($db,$user,$sale['warehouse_id']??null,true,$request);
        $fingerprint=titoSaleDiscountFingerprint($sale);
        // Límite de cinco contraseñas incorrectas en quince minutos, por cajero y negocio.
        $st=$db->prepare('SELECT COUNT(*) FROM discount_authorization_failures WHERE business_id=? AND cashier_id=? AND attempted_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)');
        $st->execute([$biz,$uid]);
        if((int)$st->fetchColumn()>=5)fail('Demasiados intentos. Intenta nuevamente después de 15 minutos.',429);
        $st=$db->prepare("SELECT id,password_hash FROM users WHERE business_id=? AND email=? AND role='admin' AND is_active=1 LIMIT 1");
        $st->execute([$biz,$email]);$supervisor=$st->fetch();$st->closeCursor();
        if(!$supervisor||!password_verify($password,(string)$supervisor['password_hash'])){
            $st=$db->prepare('INSERT INTO discount_authorization_failures(business_id,cashier_id,attempted_at) VALUES(?,?,UTC_TIMESTAMP())');
            $st->execute([$biz,$uid]);
            fail('No se pudo comprobar la autorización del administrador.',403);
        }
        $snap=[];$hasDiscount=false;$total=0;
        $st=$db->prepare('SELECT id,name,price_clp,sale_unit FROM products WHERE business_id=? AND id=? AND is_active=1');
        foreach($sale['items'] as $item){
            $pid=(int)$item['product_id'];
            $st->execute([$biz,$pid]);$p=$st->fetch();$st->closeCursor();
            if(!$p)fail('Producto no disponible: '.$pid,409);
            $qty=decimalQuantity($item['quantity'],false,$p['sale_unit']!=='unidad');
            if((float)$qty<=0)fail('Cantidad inválida.',422);
            $list=(int)$p['price_clp'];
            $price=isset($item['unit_price_clp'])?integerPrice($item['unit_price_clp']):$list;
            if($price>$list||($list>0&&$price===0))fail('Precio negociado inválido.',422);
            if($price<$list){
                $reason=$item['discount_reason']??null;
                if(!is_string($reason)||trim($reason)===''||mb_strlen(trim($reason))>120)fail('Debe indicarse el motivo de cada descuento.',422);
                $hasDiscount=true;
            }
            $snap[$pid]=$list;
            $total+=(int)round((float)$qty*$price,0,PHP_ROUND_HALF_UP);
            if($total>1000000000000)fail('Total demasiado alto.',422);
        }
        if(!$hasDiscount)fail('La venta no contiene ningún descuento que autorizar.',422);
        $st=$db->prepare('SELECT id FROM sales WHERE business_id=? AND request_id=? LIMIT 1');
        $st->execute([$biz,strtolower($sale['request_id'])]);
        if($st->fetchColumn())fail('Esta venta ya fue registrada. Revisa el historial.',409);
        $token=bin2hex(random_bytes(32));
        $st=$db->prepare('INSERT INTO sale_discount_approvals(business_id,cashier_id,supervisor_id,sale_request_id,token_hash,payload_hash,price_snapshot_json,created_at,expires_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))');
        $st->execute([$biz,$uid,(int)$supervisor['id'],strtolower($sale['request_id']),hash('sha256',$token),$fingerprint,json_encode($snap,JSON_THROW_ON_ERROR)]);
        return respond($response,['ok'=>true,'discount_approval_token'=>$token,'expires_in_seconds'=>300,'authorized_by'=>$email],201);
    });

    $group->get('/sales/{id:[0-9]+}/discount-approval',static function(Request $request,Response $response,array $args): Response {
        $user=$request->getAttribute('currentUser');
        $biz=(int)$user['business_id'];$sid=(int)$args['id'];
        $st=db()->prepare('SELECT a.id,a.cashier_id,a.supervisor_id,u.email AS supervisor_email,a.created_at,s.sold_by FROM sale_discount_approvals a JOIN sales s ON s.id=a.used_sale_id AND s.business_id=a.business_id JOIN users u ON u.id=a.supervisor_id WHERE a.business_id=? AND a.used_sale_id=? LIMIT 1');
        $st->execute([$biz,$sid]);$a=$st->fetch();
        if(!$a)return respond($response,['approval'=>null]);
        if(($user['role']??'')!=='admin'&&(int)$a['sold_by']!==(int)$user['id'])fail('Sin permisos.',403);
        return respond($response,['approval'=>['supervisor_email'=>$a['supervisor_email'],'approved_at'=>$a['created_at']]]);
    });
}

