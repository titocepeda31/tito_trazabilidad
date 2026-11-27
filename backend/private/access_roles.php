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
        'quotes.create'=>['module'=>'Cotizaciones','action'=>'Crear','description'=>'Crear a precio de lista; requiere Ver cotizaciones y Ver inventario. Solo alcance general de empresa.'],
    ];
}
function accessIdentity(PDO $db,array $user): array {
    $user['custom_role']=null;$user['permissions']=[];$user['branch_scope']='all';$user['branches']=[];
    $s=$db->prepare('SELECT a.business_id,r.id,r.name,r.is_active FROM access_user_roles a LEFT JOIN access_roles r ON r.id=a.role_id AND r.business_id=a.business_id WHERE a.user_id=?');
    $s->execute([(int)$user['id']]);$row=$s->fetch();
    if(!$row)return $user;
    if((int)$row['business_id']!==(int)$user['business_id']||!$row['id']||(int)$row['is_active']!==1||$user['role']!=='cashier')fail('Asignación de rol inválida. Contacta al administrador.',403);
    $s=$db->prepare('SELECT permission_code FROM access_role_permissions WHERE business_id=? AND role_id=? ORDER BY permission_code');
    $s->execute([(int)$user['business_id'],(int)$row['id']]);
    $user['custom_role']=['id'=>(int)$row['id'],'name'=>$row['name']];
    $user['permissions']=array_values(array_intersect($s->fetchAll(PDO::FETCH_COLUMN),array_keys(accessCatalog())));
    $s=$db->prepare('SELECT business_id FROM access_user_branch_scopes WHERE user_id=?');$s->execute([(int)$user['id']]);$scope=$s->fetchColumn();
    if($scope!==false){
        if((int)$scope!==(int)$user['business_id'])fail('Alcance de sucursal inválido.',403);
        $user['branch_scope']='selected';
        $s=$db->prepare('SELECT b.id,b.name FROM access_user_branches a JOIN branches b ON b.id=a.branch_id AND b.business_id=a.business_id WHERE a.user_id=? AND a.business_id=? AND b.is_active=1 ORDER BY b.name');
        $s->execute([(int)$user['id'],(int)$user['business_id']]);$user['branches']=$s->fetchAll();
        // Sin atribución histórica de sucursal, no exponer ventas o cotizaciones globales.
        $user['permissions']=array_values(array_intersect($user['permissions'],['inventory.view']));
    }
    return $user;
}
function accessRoutePermission(string $method,string $pattern): ?string {
    return match($method.' '.$pattern){
        'GET /me','POST /auth/logout','POST /account/password'=>'account.self',
        'GET /products','GET /warehouses/locations','GET /warehouses/stock','GET /access/branch-inventory'=>'inventory.view',
        'POST /quotes','GET /quotes/clients'=>'quotes.create',
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
    if($user['branch_scope']==='selected'&&$route?->getPattern()!=='/access/branch-inventory')fail('La operación no está habilitada para tu alcance de sucursales.',403);
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
    if(in_array('quotes.create',$permissions,true)&&(!in_array('quotes.view',$permissions,true)||!in_array('inventory.view',$permissions,true)))throw new HttpError('Crear cotizaciones requiere Ver inventario y Ver cotizaciones.',422,'permissions');
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
        $users=$s->fetchAll();
        $s=$db->prepare('SELECT id,name,code,is_active FROM branches WHERE business_id=? ORDER BY name');$s->execute([$biz]);$branches=$s->fetchAll();
        $s=$db->prepare('SELECT sc.user_id,a.branch_id FROM access_user_branch_scopes sc LEFT JOIN access_user_branches a ON a.user_id=sc.user_id AND a.business_id=sc.business_id WHERE sc.business_id=?');$s->execute([$biz]);$scopes=[];
        foreach($s->fetchAll() as $r){$scopes[$r['user_id']]??=[];if($r['branch_id']!==null)$scopes[$r['user_id']][]=(int)$r['branch_id'];}
        foreach($users as &$u){$u['branch_scope']=array_key_exists($u['id'],$scopes)?'selected':'all';$u['branch_ids']=$scopes[$u['id']]??[];}unset($u);
        return respond($response,['catalog'=>accessCatalog(),'roles'=>$roles,'users'=>$users,'branches'=>$branches]);
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
    $group->get('/access/branch-inventory',static function(Request $request,Response $response): Response {
        $u=accessRequire($request,'inventory.view');$biz=(int)$u['business_id'];$uid=(int)$u['id'];
        if(($u['branch_scope']??'all')!=='selected')fail('Selecciona un alcance de sucursales.',403);
        // Solo stock activo en bodegas actualmente asociadas a sucursales autorizadas.
        $s=db()->prepare("SELECT p.id,p.name,p.sku,p.price_clp,p.sale_unit,b.id AS branch_id,b.name AS branch_name,w.id AS warehouse_id,w.name AS warehouse_name,ws.qty AS stock_qty
          FROM access_user_branches a JOIN access_user_branch_scopes sc ON sc.user_id=a.user_id AND sc.business_id=a.business_id
          JOIN branches b ON b.id=a.branch_id AND b.business_id=a.business_id AND b.is_active=1
          JOIN branch_warehouses bw ON bw.branch_id=b.id AND bw.business_id=b.business_id
          JOIN warehouses w ON w.id=bw.warehouse_id AND w.business_id=bw.business_id AND w.is_active=1
          JOIN warehouse_stocks ws ON ws.warehouse_id=w.id AND ws.business_id=w.business_id
          JOIN products p ON p.id=ws.product_id AND p.business_id=ws.business_id AND p.is_active=1
          WHERE a.business_id=? AND a.user_id=? ORDER BY b.name,w.name,p.name,p.id LIMIT 3000");
        $s->execute([$biz,$uid]);return respond($response,['products'=>$s->fetchAll()]);
    });
    $group->post('/access/users/{id:[0-9]+}/branches',static function(Request $request,Response $response,array $args): Response {
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$args['id'];$db=db();$b=requestBody($request);
        if(array_diff(array_keys($b),['scope','branch_ids'])||!in_array($b['scope']??null,['all','selected'],true)||!is_array($b['branch_ids']??null)||!array_is_list($b['branch_ids'])||count($b['branch_ids'])>100)fail('Alcance inválido.',422);
        $ids=$b['branch_ids'];foreach($ids as $id)if(!is_int($id)||$id<1)fail('Sucursal inválida.',422);
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);
        if($b['scope']==='all'&&$ids)fail('No envíes sucursales con alcance general.',422);
        $db->beginTransaction();
        try{
            $s=$db->prepare('SELECT id,role FROM users WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$uid]);$u=$s->fetch();
            if(!$u||$u['role']!=='cashier')fail('Usuario no encontrado o no admite restricciones.',404);
            $s=$db->prepare('SELECT role_id FROM access_user_roles WHERE business_id=? AND user_id=? FOR UPDATE');$s->execute([$biz,$uid]);$rid=$s->fetchColumn();
            if(!$rid)fail('Asigna primero un rol personalizado.',409);
            foreach($ids as $id){$s=$db->prepare('SELECT id FROM branches WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE');$s->execute([$biz,$id]);if(!$s->fetchColumn())fail('Sucursal activa no encontrada.',404);}
            $s=$db->prepare('DELETE FROM access_user_branch_scopes WHERE user_id=? AND business_id=?');$s->execute([$uid,$biz]);
            if($b['scope']==='selected'){
                $s=$db->prepare('INSERT INTO access_user_branch_scopes(user_id,business_id) VALUES(?,?)');$s->execute([$uid,$biz]);
                $s=$db->prepare('INSERT INTO access_user_branches(user_id,business_id,branch_id) VALUES(?,?,?)');foreach($ids as $id)$s->execute([$uid,$biz,$id]);
            }
            $s=$db->prepare('DELETE FROM sessions WHERE user_id=?');$s->execute([$uid]);
            accessEvent($db,$a,'user_branches_changed',(int)$rid,$uid,['scope'=>$b['scope'],'branch_ids'=>$ids]);
            $db->commit();return respond($response,['ok'=>true]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
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
