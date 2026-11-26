<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

function platformKey(): string {
    $key=config()['platform_admin_key']??null;
    if(!is_string($key)||strlen($key)<40||strlen($key)>128)fail('Administración de plataforma no habilitada.',503);
    return $key;
}
function platformReady(PDO $db): void {
    if($db->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_ttrazabilidad')fail('Base de plataforma incorrecta.',503);
    orgReady($db);
    $count=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('platform_sessions','platform_business_creations')");
    if((int)$count->fetchColumn()!==2)fail('Falta instalar la migración multiempresa.',503);
}
function platformAuth(Request $request,PDO $db): string {
    $key=platformKey();platformReady($db);
    if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$request->getHeaderLine('Authorization'),$m))fail('Acceso de plataforma requerido.',401);
    $hash=hash('sha256',$m[1]);
    $st=$db->prepare('SELECT 1 FROM platform_sessions WHERE token_hash=? AND key_fingerprint=? AND expires_at>UTC_TIMESTAMP()');
    $st->execute([$hash,hash('sha256',$key)]);
    if(!$st->fetchColumn())fail('Sesión de plataforma inválida o expirada.',401);
    return $hash;
}
function platformCheckSchema(PDO $db): void {
    // El login comercial actual usa email sin empresa: exigir unicidad global.
    $st=$db->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND NON_UNIQUE=0 GROUP BY INDEX_NAME HAVING COUNT(*)=1 AND MAX(COLUMN_NAME)='email' AND MAX(COALESCE(SUB_PART,0))=0");
    if(!$st->fetchColumn())fail('Se requiere un índice único global de correo en usuarios antes de habilitar nuevas empresas.',409);
    $st=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('businesses','business_profiles','users','warehouses','platform_business_creations') AND ENGINE='InnoDB'");
    if((int)$st->fetchColumn()!==5)fail('No se pudo verificar el soporte transaccional de la creación de empresas.',503);
}
function platformRequired(array $body,string $field,string $label,int $max): string {
    $v=$body[$field]??null;
    if(!is_string($v)||trim($v)===''||mb_strlen(trim($v))>$max)throw new HttpError($label.': ingresa un valor válido.',422,$field);
    return trim($v);
}
function registerPlatformRoutes(App $app): void {
    $app->post('/platform/login',static function(Request $request,Response $response): Response {
        $key=platformKey();$db=db();platformReady($db);$body=requestBody($request);
        $candidate=$body['key']??null;
        throttleLogin('platform-admin',false);
        if(!is_string($candidate)||strlen($candidate)>128||!hash_equals($key,$candidate)){
            throttleLogin('platform-admin',true);fail('Clave de plataforma incorrecta.',401);
        }
        throttleLogin('platform-admin',null);
        $db->exec('DELETE FROM platform_sessions WHERE expires_at<=UTC_TIMESTAMP()');
        $token=bin2hex(random_bytes(32));
        $st=$db->prepare('INSERT INTO platform_sessions(token_hash,key_fingerprint,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))');
        $st->execute([hash('sha256',$token),hash('sha256',$key)]);
        return respond($response,['token'=>$token]);
    });
    $app->post('/platform/logout',static function(Request $request,Response $response): Response {
        $db=db();$hash=platformAuth($request,$db);
        $s=$db->prepare('DELETE FROM platform_sessions WHERE token_hash=?');$s->execute([$hash]);
        return respond($response,['ok'=>true]);
    });
    $app->get('/platform/businesses',static function(Request $request,Response $response): Response {
        $db=db();platformAuth($request,$db);
        $rows=$db->query("SELECT b.id,b.name,p.legal_name,p.tax_id,
            (SELECT COUNT(*) FROM branches s WHERE s.business_id=b.id) AS branch_count,
            (SELECT COUNT(*) FROM users u WHERE u.business_id=b.id AND u.is_active=1) AS active_users
            FROM businesses b LEFT JOIN business_profiles p ON p.business_id=b.id ORDER BY b.name,b.id")->fetchAll();
        return respond($response,['businesses'=>$rows]);
    });
    $app->post('/platform/businesses',static function(Request $request,Response $response): Response {
        $db=db();platformAuth($request,$db);platformCheckSchema($db);$b=requestBody($request);
        if(array_diff(array_keys($b),['name','legal_name','tax_id','admin_email','admin_password']))fail('Campos no permitidos.',422);
        $name=platformRequired($b,'name','Nombre comercial',120);$legal=platformRequired($b,'legal_name','Razón social',160);
        try{$rut=orgRut(platformRequired($b,'tax_id','RUT',16));}
        catch(HttpError $e){throw new HttpError($e->getMessage(),$e->status,'tax_id');}
        $email=strtolower(platformRequired($b,'admin_email','Correo del administrador',190));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new HttpError('Correo del administrador inválido.',422,'admin_email');
        $password=$b['admin_password']??null;
        if(is_string($password)&&hash_equals(platformKey(),$password))throw new HttpError('Usa una contraseña distinta de la clave de plataforma.',422,'admin_password');
        try{$hash=newPasswordHash($password);}catch(HttpError $e){throw new HttpError($e->getMessage(),$e->status,'admin_password');}
        $lock=$db->query("SELECT GET_LOCK('tito_trazabilidad_provision_v1',5)")->fetchColumn();
        if((int)$lock!==1)fail('Hay otra creación en curso. Reintenta.',409);
        try {
            $db->beginTransaction();
            $s=$db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');$s->execute([$email]);if($s->fetchColumn())throw new HttpError('El correo ya está registrado en el sistema. Utiliza otro correo.',409,'admin_email');
            $s=$db->prepare("SELECT business_id FROM business_profiles WHERE UPPER(REPLACE(REPLACE(REPLACE(tax_id,'.',''),'-',''),' ',''))=? LIMIT 1");
            $s->execute([str_replace('-','',$rut)]);if($s->fetchColumn())throw new HttpError('Ya existe una empresa con ese RUT.',409,'tax_id');
            $s=$db->prepare('INSERT INTO businesses(name) VALUES(?)');$s->execute([$name]);$id=(int)$db->lastInsertId();
            $s=$db->prepare('INSERT INTO business_profiles(business_id,legal_name,tax_id) VALUES(?,?,?)');$s->execute([$id,$legal,$rut]);
            try{
                $s=$db->prepare("INSERT INTO users(business_id,email,password_hash,role,is_active) VALUES(?,?,?,'admin',1)");$s->execute([$id,$email,$hash]);
            }catch(PDOException $e){
                if((int)($e->errorInfo[1]??0)===1062)throw new HttpError('El correo ya está registrado en el sistema. Utiliza otro correo.',409,'admin_email');
                throw $e;
            }
            $uid=(int)$db->lastInsertId();
            // Ubicaciones de compatibilidad requeridas por el inventario y la caja heredados.
            // Son ubicaciones vacías, no sucursales comerciales ni terminales habilitados.
            $s=$db->prepare("INSERT INTO warehouses(business_id,code,name,is_sales_location,is_active) VALUES(?,'local','Inventario inicial',1,1),(?,'bodega','Bodega de respaldo',0,1)");$s->execute([$id,$id]);
            $s=$db->prepare('INSERT INTO platform_business_creations(business_id,admin_user_id) VALUES(?,?)');$s->execute([$id,$uid]);
            $db->commit();
            return respond($response,['ok'=>true,'business_id'=>$id,'admin_user_id'=>$uid],201);
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062)fail('Ya existe un registro con esos datos.',409);
            throw $e;
        }finally{$db->query("SELECT RELEASE_LOCK('tito_trazabilidad_provision_v1')");}
    });
}
