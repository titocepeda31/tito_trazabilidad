<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Todas las rutas aquí se registran DENTRO del grupo que exige Bearer. */
function adminOnly(Request $request): array {
    $user = $request->getAttribute('currentUser');
    if (!is_array($user) || ($user['role']??'') !== 'admin') fail('Se requiere autorización de administrador.',403);
    return $user;
}

function newPasswordHash(mixed $password): string {
    if (!is_string($password) || strlen($password)<12 || strlen($password)>72) {
        fail('La contraseña debe tener entre 12 y 72 bytes.',422);
    }
    return password_hash($password,PASSWORD_DEFAULT);
}

function registerUserAndSaleDetailRoutes(RouteCollectorProxy $group): void {
    $group->get('/users',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);
        $st=db()->prepare('SELECT id,email,role,is_active,sales_warehouse_id,created_at FROM users WHERE business_id=? ORDER BY id DESC LIMIT 200');
        $st->execute([(int)$admin['business_id']]);
        return respond($response,['users'=>$st->fetchAll()]);
    });

    $group->post('/users',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);
        $b=requestBody($request);
        $email=strtolower(requireString($b,'email',190));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) fail('Correo electrónico inválido.',422);
        $role=$b['role']??null;
        if (!in_array($role,['admin','cashier'],true)) fail('Rol inválido.',422);
        $hash=newPasswordHash($b['password']??null);
        try {
            $st=db()->prepare('INSERT INTO users(business_id,email,password_hash,role,is_active) VALUES(?,?,?,?,1)');
            $st->execute([(int)$admin['business_id'],$email,$hash,$role]);
        } catch(PDOException $e) {
            if((int)($e->errorInfo[1]??0)===1062)fail('El correo ya está registrado.',409);
            throw $e;
        }
        return respond($response,['ok'=>true,'user_id'=>(int)db()->lastInsertId()],201);
    });

    // Operación POST para no requerir nuevos métodos CORS en el hosting compartido.
    $group->post('/users/{id:[0-9]+}/update',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);
        $biz=(int)$admin['business_id'];
        $id=(int)$args['id'];
        $b=requestBody($request);
        if (!array_key_exists('is_active',$b) && !array_key_exists('role',$b) && !array_key_exists('password',$b)) fail('No hay cambios.',422);
        if (array_key_exists('role',$b) && !in_array($b['role'],['admin','cashier'],true))fail('Rol inválido.',422);
        if (array_key_exists('is_active',$b) && !is_bool($b['is_active']))fail('Estado inválido.',422);
        $hash=array_key_exists('password',$b)?newPasswordHash($b['password']):null;
        $db=db();
        try {
            $db->beginTransaction();
            $st=$db->prepare('SELECT id,role,is_active FROM users WHERE id=? AND business_id=? FOR UPDATE');
            $st->execute([$id,$biz]);$target=$st->fetch();
            if (!$target)fail('Usuario no encontrado.',404);
            $newRole=$b['role']??$target['role'];
            if($newRole==='admin'&&$target['role']!=='admin'){
                $st=$db->prepare('SELECT role_id FROM access_user_roles WHERE user_id=?');$st->execute([$id]);
                if($st->fetchColumn())fail('Retira el rol personalizado antes de promover el usuario a administrador.',409);
            }
            $newActive=array_key_exists('is_active',$b)?(int)$b['is_active']:(int)$target['is_active'];
            if ($id===(int)$admin['id'] && ($newRole!=='admin'||!$newActive))fail('No puedes quitarte tus propios permisos.',409);
            if ($target['role']==='admin' && (int)$target['is_active']===1 && ($newRole!=='admin'||!$newActive)) {
                // Bloquea administradores actuales para evitar retirar el último por concurrencia.
                $st=$db->prepare("SELECT id FROM users WHERE business_id=? AND role='admin' AND is_active=1 FOR UPDATE");
                $st->execute([$biz]);
                if(count($st->fetchAll())<=1)fail('No se puede desactivar el último administrador.',409);
            }
            $st=$db->prepare('UPDATE users SET role=?,is_active=?'.($hash!==null?',password_hash=?':'').' WHERE id=? AND business_id=?');
            $params=[$newRole,$newActive];if($hash!==null)$params[]=$hash;
            array_push($params,$id,$biz);$st->execute($params);
            if (!$newActive || $hash!==null || $newRole!==$target['role']) {
                $st=$db->prepare('DELETE FROM sessions WHERE user_id=?');$st->execute([$id]);
            }
            $db->commit();
            return respond($response,['ok'=>true,'user_id'=>$id]);
        } catch(Throwable $e) { if($db->inTransaction())$db->rollBack();throw $e; }
    });

    $group->get('/sales/{id:[0-9]+}',static function(Request $request,Response $response,array $args): Response {
        $user=$request->getAttribute('currentUser');
        $biz=(int)$user['business_id'];$id=(int)$args['id'];
        $admin=($user['role']??'')==='admin';
        $sql='SELECT s.id,s.status,s.voided_at,s.void_reason,s.total_clp,s.payment_method,s.created_at,s.sold_by,s.warehouse_id,w.name AS warehouse_name,u.email AS seller_email,vu.email AS voided_by_email FROM sales s JOIN users u ON u.id=s.sold_by LEFT JOIN users vu ON vu.id=s.voided_by LEFT JOIN warehouses w ON w.id=s.warehouse_id AND w.business_id=s.business_id WHERE s.id=? AND s.business_id=?';
        $params=[$id,$biz];
        if(!$admin){$sql.=' AND s.sold_by=?';$params[]=(int)$user['id'];}
        $st=db()->prepare($sql.' LIMIT 1');$st->execute($params);$sale=$st->fetch();
        if(!$sale)fail('Venta no encontrada.',404);
        $st=db()->prepare('SELECT si.id,si.product_id,COALESCE(si.product_name_snapshot,p.name) AS product_name,p.sku,si.quantity,si.unit_price_clp,COALESCE(si.list_price_clp,si.unit_price_clp) AS list_price_clp,si.discount_reason,si.subtotal_clp FROM sale_items si JOIN products p ON p.id=si.product_id AND p.business_id=? WHERE si.sale_id=? ORDER BY si.id');
        $st->execute([$biz,$id]);$items=$st->fetchAll();
        return respond($response,['sale'=>$sale,'items'=>$items]);
    });
}
