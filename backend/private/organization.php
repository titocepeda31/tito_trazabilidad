<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function orgText(array $b,string $key,int $max): string {
    $v=$b[$key]??'';
    if(!is_string($v)||mb_strlen(trim($v))>$max) fail('Campo inválido: '.$key,422);
    return trim($v);
}
function orgReady(PDO $db): void {
    $s=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('business_profiles','branches','branch_warehouses')");
    if((int)$s->fetchColumn()!==3) fail('Falta instalar la migración de empresas y sucursales.',503);
}
function orgRut(string $raw): string {
    if($raw==='') return '';
    $v=strtoupper(str_replace(['.','-',' '],'',$raw));
    if(!preg_match('/^[1-9][0-9]{0,7}[0-9K]$/D',$v)) fail('RUT inválido.',422);
    $n=substr($v,0,-1);$sum=0;$m=2;
    for($i=strlen($n)-1;$i>=0;$i--){$sum+=(int)$n[$i]*$m;$m=$m===7?2:$m+1;}
    $d=11-($sum%11);$dv=$d===11?'0':($d===10?'K':(string)$d);
    if(substr($v,-1)!==$dv) fail('RUT inválido.',422);
    return $n.'-'.$dv;
}
// Serialize RUT writes across the commercial profile and platform provisioning.
function orgLockRut(PDO $db): void {
    if((int)$db->query("SELECT GET_LOCK('tito_trazabilidad_provision_v1',5)")->fetchColumn()!==1)fail('Hay otra modificación de empresa en curso. Reintenta.',409);
}
function orgUnlockRut(PDO $db): void {
    $db->query("SELECT RELEASE_LOCK('tito_trazabilidad_provision_v1')");
}
function orgCheckRutUnique(PDO $db,string $rut,int $except=0): void {
    if($rut==='')return;
    $s=$db->prepare("SELECT business_id FROM business_profiles WHERE business_id<>? AND UPPER(REPLACE(REPLACE(REPLACE(tax_id,'.',''),'-',''),' ',''))=? LIMIT 1");
    $s->execute([$except,str_replace('-','',$rut)]);
    if($s->fetchColumn())throw new HttpError('Ya existe una empresa con ese RUT.',409,'tax_id');
}
function registerOrganizationRoutes(RouteCollectorProxy $group): void {
    $group->get('/organization',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$db=db();orgReady($db);
        $s=$db->prepare('SELECT b.id,b.name,p.legal_name,p.tax_id,p.address,p.city,p.email,p.phone FROM businesses b LEFT JOIN business_profiles p ON p.business_id=b.id WHERE b.id=?');
        $s->execute([$biz]);$business=$s->fetch();
        if(!$business) fail('Empresa no encontrada.',404);
        $s=$db->prepare('SELECT id,code,name,address,city,phone,is_active FROM branches WHERE business_id=? ORDER BY is_active DESC,name,id');$s->execute([$biz]);$branches=$s->fetchAll();
        $s=$db->prepare('SELECT w.id,w.code,w.name,w.is_active,w.is_sales_location,m.branch_id FROM warehouses w LEFT JOIN branch_warehouses m ON m.warehouse_id=w.id AND m.business_id=w.business_id WHERE w.business_id=? ORDER BY w.name,w.id');$s->execute([$biz]);$warehouses=$s->fetchAll();
        $s=$db->prepare("SELECT u.id,u.email,u.role,u.is_active,u.sales_warehouse_id, w.name AS warehouse_name,m.branch_id FROM users u LEFT JOIN warehouses w ON w.business_id=u.business_id AND w.id=u.sales_warehouse_id LEFT JOIN branch_warehouses m ON m.warehouse_id=w.id AND m.business_id=w.business_id WHERE u.business_id=? ORDER BY u.email");$s->execute([$biz]);$users=$s->fetchAll();
        return respond($response,['business'=>$business,'branches'=>$branches,'warehouses'=>$warehouses,'users'=>$users]);
    });
    $group->post('/organization/profile',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$db=db();orgReady($db);$b=requestBody($request);
        $legal=requireString($b,'legal_name',160);$rut=orgRut(orgText($b,'tax_id',16));$email=orgText($b,'email',160);
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Correo inválido.',422);
        orgLockRut($db);
        try{
            orgCheckRutUnique($db,$rut,$biz);
            $s=$db->prepare('INSERT INTO business_profiles(business_id,legal_name,tax_id,address,city,email,phone) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE legal_name=VALUES(legal_name),tax_id=VALUES(tax_id),address=VALUES(address),city=VALUES(city),email=VALUES(email),phone=VALUES(phone)');
            $s->execute([$biz,$legal,$rut,orgText($b,'address',200),orgText($b,'city',100),$email,orgText($b,'phone',40)]);
        }finally{orgUnlockRut($db);}
        return respond($response,['ok'=>true]);
    });
    $group->post('/organization/branches',static function(Request $request,Response $response): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$db=db();orgReady($db);$b=requestBody($request);
        $code=strtoupper(requireString($b,'code',30));if(!preg_match('/^[A-Z0-9_-]+$/D',$code))fail('Código: solo letras, números, guion o guion bajo.',422);
        try{
            $s=$db->prepare('INSERT INTO branches(business_id,code,name,address,city,phone) VALUES(?,?,?,?,?,?)');
            $s->execute([$biz,$code,requireString($b,'name',120),orgText($b,'address',200),orgText($b,'city',100),orgText($b,'phone',40)]);
        }catch(PDOException $e){if((int)($e->errorInfo[1]??0)===1062)fail('Código de sucursal ya existente.',409);throw $e;}
        return respond($response,['ok'=>true,'id'=>(int)$db->lastInsertId()],201);
    });
    $group->post('/organization/branches/{id:[0-9]+}/update',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$db=db();orgReady($db);$b=requestBody($request);$id=(int)$args['id'];
        if(!is_bool($b['is_active']??null))fail('Estado inválido.',422);
        $db->beginTransaction();
        try{
            $s=$db->prepare('SELECT id FROM branches WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$id]);if(!$s->fetch())fail('Sucursal no encontrada.',404);
            if(!$b['is_active']){
                $s=$db->prepare('SELECT warehouse_id FROM branch_warehouses WHERE business_id=? AND branch_id=? LIMIT 1');$s->execute([$biz,$id]);if($s->fetch())fail('Desvincula las bodegas antes de desactivar la sucursal.',409);
            }
            $s=$db->prepare('UPDATE branches SET name=?,address=?,city=?,phone=?,is_active=? WHERE business_id=? AND id=?');
            $s->execute([requireString($b,'name',120),orgText($b,'address',200),orgText($b,'city',100),orgText($b,'phone',40),(int)$b['is_active'],$biz,$id]);
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        return respond($response,['ok'=>true]);
    });
    $group->post('/organization/warehouses/{id:[0-9]+}/branch',static function(Request $request,Response $response,array $args): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];$db=db();orgReady($db);$b=requestBody($request);$wid=(int)$args['id'];
        if(!array_key_exists('branch_id',$b))fail('Sucursal requerida.',422);
        $bid=$b['branch_id'];if($bid!==null&&(!is_int($bid)||$bid<1))fail('Sucursal inválida.',422);
        $db->beginTransaction();
        try{
            if($bid!==null){$s=$db->prepare('SELECT id FROM branches WHERE id=? AND business_id=? AND is_active=1 FOR UPDATE');$s->execute([$bid,$biz]);if(!$s->fetch())fail('Sucursal activa no encontrada.',404);}
            $s=$db->prepare('SELECT id FROM warehouses WHERE id=? AND business_id=? FOR UPDATE');$s->execute([$wid,$biz]);if(!$s->fetch())fail('Bodega no encontrada.',404);
            $s=$db->prepare('SELECT business_id FROM branch_warehouses WHERE warehouse_id=? FOR UPDATE');$s->execute([$wid]);$owner=$s->fetchColumn();
            if($owner!==false&&(int)$owner!==$biz)fail('Asignación inconsistente; revisar base de datos.',409);
            if($bid===null){$s=$db->prepare('DELETE FROM branch_warehouses WHERE warehouse_id=? AND business_id=?');$s->execute([$wid,$biz]);}
            else{$s=$db->prepare('INSERT INTO branch_warehouses(warehouse_id,business_id,branch_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE branch_id=VALUES(branch_id)');$s->execute([$wid,$biz,$bid]);}
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        return respond($response,['ok'=>true]);
    });
}
