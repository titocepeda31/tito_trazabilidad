<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Cambios de contraseña propios y ajustes por ubicación, ambos autenticados. */
function registerPilotCompletionRoutes(RouteCollectorProxy $group): void {
    $group->post('/account/password',static function(Request $request,Response $response): Response {
        $user=$request->getAttribute('currentUser');
        if(!is_array($user)||empty($user['id'])||empty($user['business_id']))fail('Acceso no autorizado.',401);
        $body=requestBody($request);
        if(array_diff(array_keys($body),['current_password','new_password']))fail('Campos no permitidos.',422);
        $current=$body['current_password']??null;
        $new=$body['new_password']??null;
        if(!is_string($current)||$current===''||!is_string($new))fail('Debes ingresar ambas contraseñas.',422);
        // Respeta exactamente la regla de usuarios ya vigente (12–72 caracteres).
        $newHash=newPasswordHash($new);
        $db=db();
        try{
            $db->beginTransaction();
            $st=$db->prepare('SELECT password_hash,is_active FROM users WHERE id=? AND business_id=? FOR UPDATE');
            $st->execute([(int)$user['id'],(int)$user['business_id']]);
            $account=$st->fetch();$st->closeCursor();
            if(!$account||(int)$account['is_active']!==1)fail('Cuenta no disponible.',401);
            if(!password_verify($current,(string)$account['password_hash']))fail('La contraseña actual no es correcta.',422);
            if(password_verify($new,(string)$account['password_hash']))fail('La nueva contraseña debe ser distinta.',422);
            $st=$db->prepare('UPDATE users SET password_hash=? WHERE id=? AND business_id=?');
            $st->execute([$newHash,(int)$user['id'],(int)$user['business_id']]);
            // Invalida todas las sesiones, incluida la actual, para que deba iniciar nuevamente.
            $st=$db->prepare('DELETE FROM sessions WHERE user_id=?');
            $st->execute([(int)$user['id']]);
            $db->commit();
            return respond($response,['ok'=>true,'relogin_required'=>true]);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    });

    $group->get('/warehouse-adjustments',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);
        $st=db()->prepare('SELECT a.id,a.product_id,p.name AS product_name,a.warehouse_code,a.delta_qty,a.qty_before,a.qty_after,a.total_before,a.total_after,a.reason,a.created_at,u.email AS actor_email FROM warehouse_adjustments a JOIN products p ON p.id=a.product_id AND p.business_id=a.business_id JOIN users u ON u.id=a.created_by WHERE a.business_id=? ORDER BY a.id DESC LIMIT 100');
        $st->execute([(int)$admin['business_id']]);
        return respond($response,['adjustments'=>$st->fetchAll()]);
    });

    $group->post('/stock/adjust-by-location',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);
        $biz=(int)$admin['business_id'];$uid=(int)$admin['id'];$body=requestBody($request);
        if(array_diff(array_keys($body),['request_id','product_id','warehouse_code','delta_qty','reason']))fail('Campos no permitidos.',422);
        $rid=$body['request_id']??null;
        if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$rid))fail('Identificador de operación inválido.',422);
        $rid=strtolower($rid);
        $pid=filter_var($body['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($pid===false)fail('Producto inválido.',422);
        $code=$body['warehouse_code']??null;
        if(!in_array($code,['local','bodega'],true))fail('Ubicación no válida.',422);
        $reason=requireString($body,'reason',250);
        $raw=$body['delta_qty']??null;
        if(!is_int($raw)&&!is_float($raw))fail('Variación inválida.',422);
        $delta=(float)$raw;
        if(!is_finite($delta)||abs($delta)<0.0005||abs($delta)>1000000000||abs($delta*1000-round($delta*1000))>0.000001)fail('Variación fuera de rango.',422);
        $delta=round($delta,3);
        $deltaFormatted=number_format($delta,3,'.','');
        $hash=hash('sha256',json_encode([$pid,$code,$deltaFormatted,$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();
        try{
            $db->beginTransaction();
            // Bloqueo por solicitud, evitando reintentos duplicados o simultáneos.
            $st=$db->prepare('INSERT INTO warehouse_adjustments(business_id,created_by,request_id,payload_hash,product_id,warehouse_code,delta_qty,qty_before,qty_after,total_before,total_after,reason) VALUES(?,?,?,?,?,?,?,0,0,0,0,?)');
            $st->execute([$biz,$uid,$rid,$hash,$pid,$code,$deltaFormatted,$reason]);
            $id=(int)$db->lastInsertId();
            $st=$db->prepare('SELECT id,name,sale_unit,stock_qty FROM products WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');
            $st->execute([$biz,$pid]);$p=$st->fetch();$st->closeCursor();
            if(!$p)fail('Producto no disponible.',422);
            serialRejectLegacy($db,$biz,$pid);
            if($p['sale_unit']==='unidad'&&floor(abs($delta))!==abs($delta))fail('Este producto requiere cantidades enteras.',422);
            $st=$db->prepare('SELECT w.id AS warehouse_id,w.code,ws.qty FROM warehouse_stocks ws JOIN warehouses w ON w.id=ws.warehouse_id AND w.business_id=ws.business_id WHERE ws.business_id=? AND ws.product_id=? AND w.code IN (\'local\',\'bodega\') ORDER BY w.id FOR UPDATE');
            $st->execute([$biz,$pid]);$locs=$st->fetchAll();$st->closeCursor();
            $byCode=[];foreach($locs as $l)$byCode[$l['code']]=$l;
            if(count($byCode)!==2)fail('Stock por ubicación no está configurado.',409);
            $local=(float)$byCode['local']['qty'];
            $locBefore=(float)$byCode[$code]['qty'];
            $globalBefore=(float)$p['stock_qty'];
            $locAfter=$locBefore+$delta;$globalAfter=$globalBefore+$delta;
            if($locAfter< -0.000001)fail('No hay suficiente stock en '.($code==='local'?'Local de venta':'Bodega de respaldo').'.',409);
            if($globalAfter< -0.000001||$globalAfter>1000000000||$locAfter>1000000000)fail('Stock final fuera de rango.',422);
            $updLocation=$db->prepare('UPDATE warehouse_stocks SET qty=? WHERE business_id=? AND product_id=? AND warehouse_id=?');
            $updProduct=$db->prepare('UPDATE products SET stock_qty=? WHERE id=? AND business_id=?');
            if($code==='bodega'&&$delta<0){
                // El trigger de products descuenta primero del local. Traslado temporal
                // dentro de la misma transacción para no tocar su saldo final.
                $updLocation->execute([number_format(max(0,$locAfter),3,'.',''),$biz,$pid,(int)$byCode['bodega']['warehouse_id']]);
                $updLocation->execute([number_format($local-$delta,3,'.',''),$biz,$pid,(int)$byCode['local']['warehouse_id']]);
            }
            $updProduct->execute([number_format(max(0,$globalAfter),3,'.',''),$pid,$biz]);
            if($code==='bodega'&&$delta>0){
                // El trigger sumó en el local: transferimos esa suma a la bodega.
                $updLocation->execute([number_format($local,3,'.',''),$biz,$pid,(int)$byCode['local']['warehouse_id']]);
                $updLocation->execute([number_format($locAfter,3,'.',''),$biz,$pid,(int)$byCode['bodega']['warehouse_id']]);
            }
            $final=$db->prepare('SELECT w.code,ws.qty FROM warehouse_stocks ws JOIN warehouses w ON w.id=ws.warehouse_id WHERE ws.business_id=? AND ws.product_id=? ORDER BY w.id');
            $final->execute([$biz,$pid]);$finalRows=$final->fetchAll();$final->closeCursor();
            $balances=[];foreach($finalRows as $row)$balances[$row['code']]=(float)$row['qty'];
            if(!isset($balances['local'],$balances['bodega'])||abs(array_sum($balances)-$globalAfter)>0.0005||abs($balances[$code]-$locAfter)>0.0005)throw new RuntimeException('No coinciden los saldos por ubicación.');
            $st=$db->prepare('UPDATE warehouse_adjustments SET qty_before=?,qty_after=?,total_before=?,total_after=? WHERE id=? AND business_id=?');
            $st->execute([number_format($locBefore,3,'.',''),number_format($locAfter,3,'.',''),number_format($globalBefore,3,'.',''),number_format($globalAfter,3,'.',''),$id,$biz]);
            move($db,$biz,$pid,null,$uid,'adjustment',$deltaFormatted,number_format($globalAfter,3,'.',''),$reason.' · '.($code==='local'?'Local de venta':'Bodega de respaldo'));
            $db->commit();
            return respond($response,['ok'=>true,'adjustment_id'=>$id,'product_id'=>$pid,'warehouse_code'=>$code,'location_stock_after'=>$locAfter,'total_stock_after'=>$globalAfter],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062){
                $st=$db->prepare('SELECT id,payload_hash,qty_after,total_after FROM warehouse_adjustments WHERE business_id=? AND request_id=?');
                $st->execute([$biz,$rid]);$old=$st->fetch();
                if($old){
                    if(!hash_equals((string)$old['payload_hash'],$hash))fail('Solicitud reutilizada con datos diferentes.',409);
                    return respond($response,['ok'=>true,'adjustment_id'=>(int)$old['id'],'location_stock_after'=>(float)$old['qty_after'],'total_stock_after'=>(float)$old['total_after'],'already_recorded'=>true]);
                }
            }
            throw $e;
        }
    });
}

