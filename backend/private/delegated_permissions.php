<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Delegaciones por caja: permisos por usuario, nunca equivalentes al rol administrador. */
function titoStaffCan(PDO $db,array $user,string $permission): bool {
    if(($user['role']??'')==='admin')return true;
    if(($user['role']??'')!=='cashier'||!in_array($permission,['can_approve_discounts','can_sell_credit'],true))return false;
    $st=$db->prepare("SELECT p.can_approve_discounts,p.can_sell_credit FROM pos_staff_permissions p JOIN users u ON u.id=p.user_id AND u.business_id=p.business_id WHERE p.business_id=? AND p.user_id=? AND u.role='cashier' AND u.is_active=1 LIMIT 1");
    $st->execute([(int)$user['business_id'],(int)$user['id']]);
    $row=$st->fetch();$st->closeCursor();
    return $row&&(int)$row[$permission]===1;
}
function titoStaffRequire(Request $request,string $permission): array {
    $user=$request->getAttribute('currentUser');
    if(!is_array($user)||!titoStaffCan(db(),$user,$permission))fail('El administrador no ha habilitado este permiso para tu cuenta.',403);
    return $user;
}
function titoStaffEvent(PDO $db,int $biz,int $actor,string $type,?int $saleId,?int $clientId,?int $amount,string $details): void {
    $st=$db->prepare('INSERT INTO pos_staff_events(business_id,actor_id,event_type,sale_id,client_id,amount_clp,description,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())');
    $st->execute([$biz,$actor,$type,$saleId,$clientId,$amount,$details]);
}
/** El push es informativo y no participa en el resultado de la venta. */
function titoStaffNotifyAdmins(PDO $db,int $biz,string $title,string $message): void {
    if(!function_exists('titoOneSignalSend'))return;
    try {
        $st=$db->prepare("SELECT s.subscription_id FROM onesignal_push_subscriptions s JOIN users u ON u.id=s.admin_user_id AND u.business_id=s.business_id WHERE s.business_id=? AND u.role='admin' AND u.is_active=1 ORDER BY s.id LIMIT 100");
        $st->execute([$biz]);
        $ids=array_column($st->fetchAll(PDO::FETCH_ASSOC),'subscription_id');
        if(!$ids)return;
        $result=titoOneSignalSend($ids,$title,$message,TITO_PUSH_ORIGIN.'/');
        if(!($result['accepted']??false))error_log('Tito POS staff: push no entregado por proveedor o sin configuración.');
    }catch(Throwable $e){error_log('Tito POS staff: push no disponible.');}
}

function registerDelegatedPermissionsRoutes(RouteCollectorProxy $group): void {
    $group->get('/account/staff-permissions',static function(Request $request,Response $response): Response {
        $user=$request->getAttribute('currentUser');
        $db=db();
        return respond($response,['can_approve_discounts'=>titoStaffCan($db,$user,'can_approve_discounts'),'can_sell_credit'=>titoStaffCan($db,$user,'can_sell_credit')]);
    });
    $group->get('/staff/permissions',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);
        $st=db()->prepare("SELECT u.id,u.email,u.role,u.is_active,COALESCE(p.can_approve_discounts,0) AS can_approve_discounts,COALESCE(p.can_sell_credit,0) AS can_sell_credit FROM users u LEFT JOIN pos_staff_permissions p ON p.user_id=u.id AND p.business_id=u.business_id WHERE u.business_id=? AND u.role='cashier' ORDER BY u.email");
        $st->execute([(int)$admin['business_id']]);
        return respond($response,['staff'=>$st->fetchAll()]);
    });
    $group->post('/staff/permissions/{id:[0-9]+}',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$body=requestBody($request);
        if(array_diff(array_keys($body),['can_approve_discounts','can_sell_credit'])||count($body)!==2)fail('Permisos inválidos.',422);
        $discount=$body['can_approve_discounts']??null;$credit=$body['can_sell_credit']??null;
        if(!is_bool($discount)||!is_bool($credit))fail('Los permisos deben ser verdaderos o falsos.',422);
        $db=db();$biz=(int)$admin['business_id'];$target=(int)$args['id'];
        try{
            $db->beginTransaction();
            $st=$db->prepare("SELECT id,email,is_active FROM users WHERE id=? AND business_id=? AND role='cashier' FOR UPDATE");
            $st->execute([$target,$biz]);$cashier=$st->fetch();$st->closeCursor();
            if(!$cashier)fail('Cajero no encontrado en este negocio.',404);
            if((int)$cashier['is_active']!==1&&($discount||$credit))fail('No puedes habilitar permisos a una cuenta desactivada.',409);
            $st=$db->prepare('SELECT can_approve_discounts,can_sell_credit FROM pos_staff_permissions WHERE business_id=? AND user_id=? FOR UPDATE');
            $st->execute([$biz,$target]);$old=$st->fetch();$st->closeCursor();
            $beforeD=(int)($old['can_approve_discounts']??0);$beforeC=(int)($old['can_sell_credit']??0);
            if($beforeD!==(int)$discount||$beforeC!==(int)$credit){
                $st=$db->prepare('INSERT INTO pos_staff_permissions(business_id,user_id,can_approve_discounts,can_sell_credit,updated_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE can_approve_discounts=VALUES(can_approve_discounts),can_sell_credit=VALUES(can_sell_credit),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()');
                $st->execute([$biz,$target,$discount?1:0,$credit?1:0,(int)$admin['id']]);
                $st=$db->prepare('INSERT INTO pos_staff_permission_changes(business_id,target_user_id,changed_by,old_discounts,new_discounts,old_credit,new_credit,changed_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())');
                $st->execute([$biz,$target,(int)$admin['id'],$beforeD,$discount?1:0,$beforeC,$credit?1:0]);
            }
            $db->commit();
            return respond($response,['ok'=>true,'user_id'=>$target,'can_approve_discounts'=>$discount,'can_sell_credit'=>$credit]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->get('/staff/events',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);
        $st=db()->prepare('SELECT e.id,e.event_type,e.sale_id,e.client_id,e.amount_clp,e.description,e.created_at,u.email AS actor_email FROM pos_staff_events e JOIN users u ON u.id=e.actor_id AND u.business_id=e.business_id WHERE e.business_id=? ORDER BY e.id DESC LIMIT 50');
        $st->execute([(int)$admin['business_id']]);
        return respond($response,['events'=>$st->fetchAll()]);
    });
    /** El cajero expresamente delegado puede aprobar SU venta, pero jamás alterar el catálogo. */
    $group->post('/sales/delegated-discount-approve',static function(Request $request,Response $response): Response {
        $user=titoStaffRequire($request,'can_approve_discounts');
        if(($user['role']??'')!=='cashier')fail('Solo la caja delegada usa esta ruta.',403);
        $body=requestBody($request);
        if(array_diff(array_keys($body),['sale'])||count($body)!==1)fail('Datos inválidos.',422);
        $sale=$body['sale']??null;
        if(!is_array($sale)||array_diff(array_keys($sale),['request_id','payment_method','items','warehouse_id']))fail('Venta inválida.',422);
        $db=db();$biz=(int)$user['business_id'];$uid=(int)$user['id'];
        titoSaleLocation($db,$user,$sale['warehouse_id']??null,true,$request);
        $snapshot=titoRemoteDiscountSnapshot($db,$biz,$sale);
        $st=$db->prepare('SELECT id FROM sales WHERE business_id=? AND request_id=? LIMIT 1');
        $st->execute([$biz,strtolower($sale['request_id'])]);
        if($st->fetchColumn())fail('La venta ya está registrada. Consulta el historial.',409);
        $token=bin2hex(random_bytes(32));
        $st=$db->prepare('INSERT INTO sale_discount_approvals(business_id,cashier_id,supervisor_id,sale_request_id,token_hash,payload_hash,price_snapshot_json,created_at,expires_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))');
        $st->execute([$biz,$uid,$uid,strtolower($sale['request_id']),hash('sha256',$token),$snapshot['fingerprint'],json_encode($snapshot['prices'],JSON_THROW_ON_ERROR)]);
        // El evento expresa autorización, no venta cobrada. La venta puede fallar luego.
        titoStaffEvent($db,$biz,$uid,'discount_authorized',null,null,(int)$snapshot['summary']['discount_clp'],'Autorización delegada para venta en proceso #'.strtolower($sale['request_id']));
        titoStaffNotifyAdmins($db,$biz,'Tito POS · Descuento delegado','Un cajero habilitado autorizó un descuento de $'.number_format((int)$snapshot['summary']['discount_clp'],0,',','.').' para una venta en proceso. Revisa el historial para confirmar su cierre.');
        return respond($response,['ok'=>true,'discount_approval_token'=>$token,'expires_in_seconds'=>300],201);
    });
}
