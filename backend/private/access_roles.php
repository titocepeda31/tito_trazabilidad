<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;
use Slim\Routing\RouteContext;

function accessCatalog(): array {
    return [
        'inventory.view'=>['module'=>'Inventario','action'=>'Ver','description'=>'Productos, precios y stock total de la empresa.'],
        'sales.own_view'=>['module'=>'Ventas','action'=>'Ver propias','description'=>'Consultar únicamente las ventas realizadas por el usuario.'],
        'quotes.view'=>['module'=>'Cotizaciones','action'=>'Ver','description'=>'Consultar las cotizaciones de la empresa.'],
    ];
}
function accessIdentity(PDO $db,array $user): array {
    $user['custom_role']=null;$user['permissions']=[];
    $s=$db->prepare('SELECT a.business_id,r.id,r.name,r.is_active FROM access_user_roles a LEFT JOIN access_roles r ON r.id=a.role_id AND r.business_id=a.business_id WHERE a.user_id=?');
    $s->execute([(int)$user['id']]);$row=$s->fetch();
    if(!$row)return $user;
    if((int)$row['business_id']!==(int)$user['business_id']||!$row['id']||(int)$row['is_active']!==1||$user['role']!=='cashier')fail('Asignación de rol inválida. Contacta al administrador.',403);
    $s=$db->prepare('SELECT permission_code FROM access_role_permissions WHERE business_id=? AND role_id=? ORDER BY permission_code');
    $s->execute([(int)$user['business_id'],(int)$row['id']]);
    $user['custom_role']=['id'=>(int)$row['id'],'name'=>$row['name']];
    $user['permissions']=array_values(array_intersect($s->fetchAll(PDO::FETCH_COLUMN),array_keys(accessCatalog())));
    return $user;
}
function accessRoutePermission(string $method,string $pattern): ?string {
    return match($method.' '.$pattern){
        'GET /me','POST /auth/logout','POST /account/password'=>'account.self',
        'GET /products','GET /warehouses/locations','GET /warehouses/stock'=>'inventory.view',
        'GET /sales','GET /sales/{id:[0-9]+}'=>'sales.own_view',
        'GET /quotes','GET /quotes/{id:[0-9]+}'=>'quotes.view',
        default=>null
    };
}
function accessEnforce(Request $request,array $user): void {
    if($user['custom_role']===null)return;
    $route=RouteContext::fromRequest($request)->getRoute();
    $permission=$route?accessRoutePermission($request->getMethod(),$route->getPattern()):null;
    if($permission==='account.self')return;
    if($permission===null||!in_array($permission,$user['permissions'],true))fail('Tu rol no permite realizar esta operación.',403);
}
function accessRequire(Request $request,string $permission): array {
    $user=$request->getAttribute('currentUser');
    if(is_array($user)&&($user['role']==='admin'||(!empty($user['custom_role'])&&in_array($permission,$user['permissions']??[],true))))return $user;
    fail('Tu rol no permite realizar esta operación.',403);
}
function accessEvent(PDO $db,array $actor,string $type,?int $role,?int $target,array $details): void {
    $s=$db->prepare('INSERT INTO access_role_events(business_id,actor_id,event_type,role_id,target_user_id,details) VALUES(?,?,?,?,?,?)');
    $s->execute([(int)$actor['business_id'],(int)$actor['id'],$type,$role,$target,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
}
function accessRoleInput(array $b): array {
    if(array_diff(array_keys($b),['name','description','permissions','is_active']))fail('Campos no permitidos.',422);
    $name=$b['name']??null;$description=$b['description']??'';$permissions=$b['permissions']??null;$active=$b['is_active']??true;
    if(!is_string($name)||trim($name)===''||mb_strlen(trim($name))>100)throw new HttpError('Ingresa un nombre de rol válido.',422,'name');
    if(!is_string($description)||mb_strlen(trim($description))>250)throw new HttpError('Descripción demasiado extensa.',422,'description');
    if(!is_array($permissions)||!array_is_list($permissions)||count($permissions)>count(accessCatalog()))throw new HttpError('Permisos inválidos.',422,'permissions');
    foreach($permissions as $p)if(!is_string($p)||!isset(accessCatalog()[$p]))throw new HttpError('Permiso no habilitado.',422,'permissions');
    if(!is_bool($active))fail('Estado inválido.',422);
    return [trim($name),trim($description),array_values(array_unique($permissions)),(int)$active];
}
function accessRoleLock(PDO $db,int $biz,int $id): array {
    $s=$db->prepare('SELECT id,name,is_active FROM access_roles WHERE id=? AND business_id=? FOR UPDATE');$s->execute([$id,$biz]);$row=$s->fetch();
    if(!$row)fail('Rol no encontrado.',404);
    return $row;
}
function registerAccessRoleRoutes(RouteCollectorProxy $group): void {
    $group->get('/access/roles',static function(Request $request,Response $response): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$db=db();
        $s=$db->prepare('SELECT r.id,r.name,r.description,r.is_active,(SELECT COUNT(*) FROM access_user_roles ar WHERE ar.business_id=r.business_id AND ar.role_id=r.id) AS assigned_count FROM access_roles r WHERE r.business_id=? ORDER BY r.name');$s->execute([$biz]);$roles=$s->fetchAll();
        $s=$db->prepare('SELECT role_id,permission_code FROM access_role_permissions WHERE business_id=?');$s->execute([$biz]);$map=[];
        foreach($s->fetchAll() as $p)$map[$p['role_id']][]=$p['permission_code'];
        foreach($roles as &$r)$r['permissions']=$map[$r['id']]??[];unset($r);
        $s=$db->prepare('SELECT u.id,u.email,u.role,u.is_active,ar.role_id FROM users u LEFT JOIN access_user_roles ar ON ar.user_id=u.id AND ar.business_id=u.business_id WHERE u.business_id=? ORDER BY u.email');$s->execute([$biz]);
        return respond($response,['catalog'=>accessCatalog(),'roles'=>$roles,'users'=>$s->fetchAll()]);
    });
    $save=static function(Request $request,Response $response,array $args=[]): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$db=db();
        [$name,$desc,$permissions,$active]=accessRoleInput(requestBody($request));$id=isset($args['id'])?(int)$args['id']:null;
        $db->beginTransaction();
        try{
            if($id===null){
                $s=$db->prepare('INSERT INTO access_roles(business_id,name,description,is_active) VALUES(?,?,?,?)');$s->execute([$biz,$name,$desc,$active]);$id=(int)$db->lastInsertId();$type='role_created';
            }else{
                accessRoleLock($db,$biz,$id);
                if(!$active){$s=$db->prepare('SELECT user_id FROM access_user_roles WHERE business_id=? AND role_id=? LIMIT 1');$s->execute([$biz,$id]);if($s->fetchColumn())fail('Reasigna los usuarios antes de desactivar el rol.',409);}
                $s=$db->prepare('UPDATE access_roles SET name=?,description=?,is_active=? WHERE id=? AND business_id=?');$s->execute([$name,$desc,$active,$id,$biz]);$type='role_updated';
                $s=$db->prepare('DELETE FROM access_role_permissions WHERE business_id=? AND role_id=?');$s->execute([$biz,$id]);
            }
            $s=$db->prepare('INSERT INTO access_role_permissions(business_id,role_id,permission_code) VALUES(?,?,?)');
            foreach($permissions as $p)$s->execute([$biz,$id,$p]);
            $s=$db->prepare('DELETE s FROM sessions s JOIN access_user_roles ar ON ar.user_id=s.user_id JOIN users u ON u.id=ar.user_id AND u.business_id=ar.business_id WHERE ar.business_id=? AND ar.role_id=?');$s->execute([$biz,$id]);
            accessEvent($db,$a,$type,$id,null,['name'=>$name,'permissions'=>$permissions,'is_active'=>$active]);
            $db->commit();return respond($response,['ok'=>true,'id'=>$id]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062)throw new HttpError('Ya existe un rol con ese nombre.',409,'name');throw $e;}
    };
    $group->post('/access/roles',$save);
    $group->post('/access/roles/{id:[0-9]+}/update',$save);
    $group->post('/access/users/{id:[0-9]+}/role',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$args['id'];$db=db();$b=requestBody($request);
        if(array_keys($b)!==['role_id']||($b['role_id']!==null&&(!is_int($b['role_id'])||$b['role_id']<1)))fail('Rol inválido.',422);
        $rid=$b['role_id'];$db->beginTransaction();
        try{
            // Serializar con edición de rol; la asignación nunca transforma un cajero en administrador.
            if($rid!==null){$r=accessRoleLock($db,$biz,$rid);if((int)$r['is_active']!==1)fail('El rol está desactivado.',409);}
            $s=$db->prepare('SELECT id,role,is_active FROM users WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$uid]);$u=$s->fetch();
            if(!$u||$u['role']!=='cashier')fail('Solo puedes asignar roles personalizados a usuarios no administradores de tu empresa.',422);
            $s=$db->prepare('SELECT role_id,business_id FROM access_user_roles WHERE user_id=? FOR UPDATE');$s->execute([$uid]);$old=$s->fetch();
            if($old&&(int)$old['business_id']!==$biz)fail('Asignación inconsistente.',409);
            if($rid===null){$s=$db->prepare('DELETE FROM access_user_roles WHERE user_id=? AND business_id=?');$s->execute([$uid,$biz]);}
            else{$s=$db->prepare('INSERT INTO access_user_roles(user_id,business_id,role_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id)');$s->execute([$uid,$biz,$rid]);}
            $s=$db->prepare('DELETE FROM sessions WHERE user_id=?');$s->execute([$uid]);
            accessEvent($db,$a,'user_role_changed',$rid,$uid,['previous_role_id'=>$old['role_id']??null,'new_role_id'=>$rid]);
            $db->commit();return respond($response,['ok'=>true]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
