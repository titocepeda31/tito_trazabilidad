<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function salesPointReady(PDO $db): void {
    $s=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('sales_points','sales_point_terminals','sale_point_snapshots','sales_point_events')");
    if((int)$s->fetchColumn()!==4)fail('Falta instalar la migración 006 de puntos de venta.',503);
}
function salesPointEvent(PDO $db,array $u,string $event,?int $point,?int $terminal,array $details): void {
    $s=$db->prepare('INSERT INTO sales_point_events(business_id,actor_id,event_type,sales_point_id,terminal_id,details) VALUES(?,?,?,?,?,?)');
    $s->execute([(int)$u['business_id'],(int)$u['id'],$event,$point,$terminal,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
}
function salesPointLock(PDO $db,int $biz,int $id): array {
    $s=$db->prepare('SELECT id,branch_id,code,name,is_active FROM sales_points WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$id]);$r=$s->fetch();
    if(!$r)fail('Punto de venta no encontrado.',404);
    return $r;
}
// Dentro de la misma transacción que inserta la venta. Nunca deduce sucursal desde la bodega.
function salesPointRecord(PDO $db,array $u,Request $request,int $sale,int $warehouse): void {
    if(!$db->inTransaction())throw new RuntimeException('Trazabilidad de punto de venta requiere transacción.');
    salesPointReady($db);
    $terminal=titoTerminalFromRequest($db,$u,$request);
    if(!$terminal)return; // Ventas heredadas quedan explícitamente sin atribución.
    $biz=(int)$u['business_id'];$tid=(int)$terminal['id'];
    if((int)$terminal['warehouse_id']!==$warehouse)fail('La ubicación de despacho no coincide con la caja registrada.',403);
    $s=$db->prepare('SELECT m.sales_point_id FROM sales_point_terminals m WHERE m.business_id=? AND m.terminal_id=? FOR UPDATE');$s->execute([$biz,$tid]);$pid=$s->fetchColumn();
    if($pid===false)return;
    $p=salesPointLock($db,$biz,(int)$pid);
    $s=$db->prepare('SELECT name,is_active FROM branches WHERE business_id=? AND id=?');$s->execute([$biz,(int)$p['branch_id']]);$b=$s->fetch();
    if((int)$p['is_active']!==1||!$b||(int)$b['is_active']!==1)fail('Punto de venta o sucursal inactiva.',409);
    $s=$db->prepare('INSERT INTO sale_point_snapshots(sale_id,business_id,sales_point_id,branch_id,terminal_id,point_code,point_name,branch_name,dispatch_warehouse_id) VALUES(?,?,?,?,?,?,?,?,?)');
    $s->execute([$sale,$biz,(int)$pid,(int)$p['branch_id'],$tid,$p['code'],$p['name'],$b['name'],$warehouse]);
}
function registerSalesPointRoutes(RouteCollectorProxy $group): void {
    $group->get('/sales-points',static function(Request $request,Response $response): Response {
        $u=adminOnly($request);$db=db();$biz=(int)$u['business_id'];salesPointReady($db);
        $s=$db->prepare('SELECT p.id,p.branch_id,p.code,p.name,p.is_active,b.name AS branch_name,(SELECT COUNT(*) FROM sales_point_terminals m WHERE m.business_id=p.business_id AND m.sales_point_id=p.id) AS terminal_count,(SELECT COUNT(*) FROM sale_point_snapshots h WHERE h.business_id=p.business_id AND h.sales_point_id=p.id) AS sale_count FROM sales_points p JOIN branches b ON b.id=p.branch_id AND b.business_id=p.business_id WHERE p.business_id=? ORDER BY p.is_active DESC,b.name,p.name');$s->execute([$biz]);$points=$s->fetchAll();
        $s=$db->prepare('SELECT id,name,code,is_active FROM branches WHERE business_id=? ORDER BY name');$s->execute([$biz]);$branches=$s->fetchAll();
        $s=$db->prepare("SELECT t.id,t.label,t.kind,w.name AS dispatch_name,m.sales_point_id,(t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND w.is_active=1 AND w.is_sales_location=1) AS is_active FROM pos_terminals t JOIN warehouses w ON w.id=t.warehouse_id AND w.business_id=t.business_id LEFT JOIN sales_point_terminals m ON m.terminal_id=t.id AND m.business_id=t.business_id WHERE t.business_id=? AND ((t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP()) OR m.terminal_id IS NOT NULL) ORDER BY t.id DESC");$s->execute([$biz]);
        return respond($response,['points'=>$points,'branches'=>$branches,'terminals'=>$s->fetchAll()]);
    });
    $save=static function(Request $request,Response $response,array $args=[]): Response {
        $u=adminOnly($request);$db=db();$biz=(int)$u['business_id'];salesPointReady($db);$b=requestBody($request);$id=isset($args['id'])?(int)$args['id']:null;
        if(array_diff(array_keys($b),['branch_id','code','name','is_active']))fail('Campos no permitidos.',422);
        $bid=$b['branch_id']??null;$active=$b['is_active']??null;
        if(!is_int($bid)||$bid<1)throw new HttpError('Selecciona una sucursal.',422,'branch_id');
        if(!is_bool($active))fail('Estado inválido.',422);
        $code=$b['code']??null;$name=$b['name']??null;
        if(!is_string($code)||!preg_match('/^[A-Z0-9_-]{1,30}$/D',strtoupper(trim($code))))throw new HttpError('Código: letras, números, guion o guion bajo (máximo 30).',422,'code');
        if(!is_string($name)||trim($name)===''||mb_strlen(trim($name))>120)throw new HttpError('Ingresa un nombre válido.',422,'name');
        $code=strtoupper(trim($code));$name=trim($name);
        $db->beginTransaction();
        try{
            $s=$db->prepare('SELECT id,is_active FROM branches WHERE business_id=? AND id=? FOR UPDATE');$s->execute([$biz,$bid]);$branch=$s->fetch();
            if(!$branch||($active&&(int)$branch['is_active']!==1))throw new HttpError('Selecciona una sucursal activa de tu empresa.',422,'branch_id');
            if($id===null){$s=$db->prepare('INSERT INTO sales_points(business_id,branch_id,code,name,is_active) VALUES(?,?,?,?,?)');$s->execute([$biz,$bid,$code,$name,(int)$active]);$id=(int)$db->lastInsertId();$event='point_created';}
            else{
                $old=salesPointLock($db,$biz,$id);
                if((int)$old['branch_id']!==$bid||$old['code']!==$code)fail('La sucursal y el código son permanentes. Crea otro punto de venta si corresponde.',409);
                if(!$active){$s=$db->prepare('SELECT terminal_id FROM sales_point_terminals WHERE business_id=? AND sales_point_id=? LIMIT 1');$s->execute([$biz,$id]);if($s->fetchColumn())fail('Desvincula los terminales antes de desactivar el punto.',409);}
                $s=$db->prepare('UPDATE sales_points SET name=?,is_active=? WHERE business_id=? AND id=?');$s->execute([$name,(int)$active,$biz,$id]);$event='point_updated';
            }
            salesPointEvent($db,$u,$event,$id,null,['branch_id'=>$bid,'code'=>$code,'name'=>$name,'is_active'=>$active]);
            $db->commit();return respond($response,['ok'=>true,'id'=>$id]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062)throw new HttpError('Ya existe un punto con ese código.',409,'code');throw $e;}
    };
    $group->post('/sales-points',$save);
    $group->post('/sales-points/{id:[0-9]+}/update',$save);
    $group->post('/sales-points/terminals/{id:[0-9]+}',static function(Request $request,Response $response,array $args): Response {
        $u=adminOnly($request);$db=db();$biz=(int)$u['business_id'];$tid=(int)$args['id'];salesPointReady($db);$b=requestBody($request);
        if(array_keys($b)!==['sales_point_id']||($b['sales_point_id']!==null&&(!is_int($b['sales_point_id'])||$b['sales_point_id']<1)))fail('Punto de venta inválido.',422);
        $pid=$b['sales_point_id'];$db->beginTransaction();
        try{
            // Mismo orden terminal -> vínculo -> punto que en el registro de venta.
            $s=$db->prepare("SELECT t.id,(t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND w.is_active=1 AND w.is_sales_location=1) AS active FROM pos_terminals t JOIN warehouses w ON w.id=t.warehouse_id AND w.business_id=t.business_id WHERE t.business_id=? AND t.id=? FOR UPDATE");
            $s->execute([$biz,$tid]);$t=$s->fetch();if(!$t)fail('Terminal no encontrado.',404);
            $s=$db->prepare('SELECT business_id,sales_point_id FROM sales_point_terminals WHERE terminal_id=? FOR UPDATE');$s->execute([$tid]);$old=$s->fetch();
            if($old&&(int)$old['business_id']!==$biz)fail('Vínculo inconsistente.',409);
            if($pid!==null){
                if((int)$t['active']!==1)fail('Solo puedes vincular terminales activos.',409);
                $p=salesPointLock($db,$biz,$pid);if((int)$p['is_active']!==1)fail('Punto de venta desactivado.',409);
                $s=$db->prepare('SELECT id FROM branches WHERE business_id=? AND id=? AND is_active=1');$s->execute([$biz,(int)$p['branch_id']]);if(!$s->fetchColumn())fail('Sucursal desactivada.',409);
                $s=$db->prepare('INSERT INTO sales_point_terminals(terminal_id,business_id,sales_point_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE sales_point_id=VALUES(sales_point_id)');$s->execute([$tid,$biz,$pid]);
            }else{$s=$db->prepare('DELETE FROM sales_point_terminals WHERE terminal_id=? AND business_id=?');$s->execute([$tid,$biz]);}
            salesPointEvent($db,$u,'terminal_mapping_changed',$pid,$tid,['previous_point_id'=>$old['sales_point_id']??null,'new_point_id'=>$pid]);
            $db->commit();return respond($response,['ok'=>true]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
