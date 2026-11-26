<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

// onesignal:managed v1
// El App ID es público. La REST API Key solo puede estar en el entorno
// del servidor o en private/onesignal_local.php (fuera de public_html).
const TITO_ONESIGNAL_APP_ID='00000000-0000-0000-0000-000000000000';
const TITO_PUSH_ORIGIN='https://tito-pos-react.vercel.app';

function titoOneSignalServerKey(): string {
    $key=getenv('TITO_ONESIGNAL_REST_API_KEY');
    if(is_string($key)&&trim($key)!=='')return trim($key);
    $path=__DIR__.'/onesignal_local.php';
    if(!is_file($path))return '';
    $local=require $path;
    return is_array($local)&&is_string($local['rest_api_key']??null)?trim($local['rest_api_key']):'';
}

function titoOneSignalSubscriptionId(mixed $id): string {
    if(!is_string($id)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$id)){
        fail('Suscripción push inválida.',422);
    }
    return strtolower($id);
}

function titoOneSignalSend(array $ids,string $title,string $message,string $url): array {
    $ids=array_values(array_unique($ids));
    if(!$ids)return ['accepted'=>false,'reason'=>'no_subscribers'];
    $key=titoOneSignalServerKey();
    if($key==='')return ['accepted'=>false,'reason'=>'not_configured'];
    if(!function_exists('curl_init'))return ['accepted'=>false,'reason'=>'curl_unavailable'];
    $payload=[
        'app_id'=>TITO_ONESIGNAL_APP_ID,
        'include_subscription_ids'=>$ids,
        'headings'=>['es'=>$title,'en'=>$title],
        'contents'=>['es'=>$message,'en'=>$message],
        'url'=>$url,
        'ttl'=>900,
        'priority'=>10
    ];
    $ch=curl_init('https://api.onesignal.com/notifications');
    if($ch===false)return ['accepted'=>false,'reason'=>'transport_unavailable'];
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Key '.$key,'Content-Type: application/json','Accept: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT=>2,
        CURLOPT_TIMEOUT=>5,
    ]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data=is_string($raw)?json_decode($raw,true):null;
    $accepted=$status>=200&&$status<300&&is_array($data)&&is_string($data['id']??null)&&$data['id']!=='';
    return ['accepted'=>$accepted,'reason'=>$accepted?'accepted':'provider_rejected','http_status'=>$status];
}

/** Este aviso NO participa en la transacción de venta ni modifica el resultado. */
function titoOneSignalNotifyNewDiscount(PDO $db,int $businessId,int $requestId): void {
    try{
        $st=$db->prepare("SELECT s.subscription_id FROM onesignal_push_subscriptions s JOIN users u ON u.id=s.admin_user_id AND u.business_id=s.business_id WHERE s.business_id=? AND u.role='admin' AND u.is_active=1 ORDER BY s.id LIMIT 100");
        $st->execute([$businessId]);
        $ids=array_column($st->fetchAll(PDO::FETCH_ASSOC),'subscription_id');
        if(!$ids)return;
        $result=titoOneSignalSend($ids,'Tito POS · Autorización pendiente','Un cajero solicita aprobar un descuento. Toca para revisar.',TITO_PUSH_ORIGIN.'/?approval_request='.$requestId);
        if(!$result['accepted'])error_log('Tito POS push: aviso no enviado ('.$result['reason'].').');
    }catch(Throwable $e){
        // No revelar detalles sensibles ni hacer fallar la solicitud de descuento.
        error_log('Tito POS push: no fue posible enviar aviso.');
    }
}

function registerOneSignalPushRoutes(RouteCollectorProxy $group): void {
    $group->post('/account/push-subscriptions/register',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$body=requestBody($request);
        if(array_diff(array_keys($body),['subscription_id']))fail('Campos no permitidos.',422);
        $id=titoOneSignalSubscriptionId($body['subscription_id']??null);
        $biz=(int)$admin['business_id'];$uid=(int)$admin['id'];$db=db();
        $st=$db->prepare('SELECT business_id,admin_user_id FROM onesignal_push_subscriptions WHERE subscription_id=? LIMIT 1');
        $st->execute([$id]);$owner=$st->fetch();$st->closeCursor();
        if($owner){
            if((int)$owner['business_id']!==$biz||(int)$owner['admin_user_id']!==$uid)fail('El dispositivo ya está vinculado a otra cuenta. Desactiva las notificaciones allí antes de usarlo.',409);
            $st=$db->prepare('UPDATE onesignal_push_subscriptions SET updated_at=UTC_TIMESTAMP() WHERE subscription_id=? AND business_id=? AND admin_user_id=?');
            $st->execute([$id,$biz,$uid]);
            return respond($response,['ok'=>true,'already_registered'=>true]);
        }
        $st=$db->prepare('SELECT COUNT(*) FROM onesignal_push_subscriptions WHERE business_id=? AND admin_user_id=?');
        $st->execute([$biz,$uid]);
        if((int)$st->fetchColumn()>=10)fail('Límite de 10 dispositivos por administrador.',409);
        try{
            $st=$db->prepare('INSERT INTO onesignal_push_subscriptions(business_id,admin_user_id,subscription_id,created_at,updated_at) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $st->execute([$biz,$uid,$id]);
        }catch(PDOException $e){
            if((int)($e->errorInfo[1]??0)===1062)fail('Suscripción ya vinculada a otra cuenta.',409);
            throw $e;
        }
        return respond($response,['ok'=>true],201);
    });
    $group->post('/account/push-subscriptions/unregister',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$body=requestBody($request);
        if(array_diff(array_keys($body),['subscription_id']))fail('Campos no permitidos.',422);
        $id=titoOneSignalSubscriptionId($body['subscription_id']??null);
        $st=db()->prepare('DELETE FROM onesignal_push_subscriptions WHERE subscription_id=? AND business_id=? AND admin_user_id=?');
        $st->execute([$id,(int)$admin['business_id'],(int)$admin['id']]);
        return respond($response,['ok'=>true,'unregistered'=>$st->rowCount()===1]);
    });
    $group->post('/account/push-subscriptions/test',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$body=requestBody($request);
        if(array_diff(array_keys($body),['subscription_id']))fail('Campos no permitidos.',422);
        $id=titoOneSignalSubscriptionId($body['subscription_id']??null);
        $st=db()->prepare('SELECT id FROM onesignal_push_subscriptions WHERE subscription_id=? AND business_id=? AND admin_user_id=? LIMIT 1');
        $st->execute([$id,(int)$admin['business_id'],(int)$admin['id']]);
        if(!$st->fetchColumn())fail('Activa y vincula primero este dispositivo.',403);
        $result=titoOneSignalSend([$id],'Tito POS · Prueba de notificación','Si recibes este aviso, el dispositivo está registrado para las alertas.',TITO_PUSH_ORIGIN.'/?push_test=1');
        if(!$result['accepted'])fail('No se pudo enviar la prueba. Comprueba la REST API Key y la configuración web de OneSignal.',503);
        return respond($response,['ok'=>true,'accepted'=>true]);
    });
}

