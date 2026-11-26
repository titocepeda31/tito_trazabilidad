<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Vistas de auditoría y exportación de catálogo, aisladas por negocio. */
function registerInventoryReportRoutes(RouteCollectorProxy $group): void {
    $group->get('/inventory/movements', static function(Request $request, Response $response): Response {
        $admin=adminOnly($request);
        $biz=(int)$admin['business_id'];
        $q=$request->getQueryParams();
        $limit=min(200,max(1,(int)($q['limit']??100)));
        $before=isset($q['before_id'])?(int)$q['before_id']:0;
        $product=isset($q['product_id'])?(int)$q['product_id']:0;
        if($before<0||$product<0)fail('Filtro inválido.',422);
        $sql='SELECT m.id,m.product_id,p.name AS product_name,p.sku,p.sale_unit,m.movement_type,m.delta_qty,m.balance_after,m.reason,m.created_at,m.sale_id,u.email AS actor_email FROM stock_movements m JOIN products p ON p.id=m.product_id AND p.business_id=m.business_id JOIN users u ON u.id=m.changed_by WHERE m.business_id=?';
        $params=[$biz];
        if($before>0){$sql.=' AND m.id<?';$params[]=$before;}
        if($product>0){$sql.=' AND m.product_id=?';$params[]=$product;}
        $sql.=' ORDER BY m.id DESC LIMIT '.($limit+1);
        $st=db()->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
        $hasMore=count($rows)>$limit;
        if($hasMore)array_pop($rows);
        return respond($response,['movements'=>$rows,'next_before_id'=>$hasMore?(int)end($rows)['id']:null]);
    });

    $group->get('/inventory/changes', static function(Request $request, Response $response): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];
        $q=$request->getQueryParams();
        $limit=min(200,max(1,(int)($q['limit']??100)));
        $before=isset($q['before_id'])?(int)$q['before_id']:0;
        if($before<0)fail('Filtro inválido.',422);
        $sql='SELECT c.id,c.product_id,p.name AS product_name,c.old_values_json,c.new_values_json,c.created_at,u.email AS actor_email FROM product_changes c JOIN products p ON p.id=c.product_id AND p.business_id=c.business_id JOIN users u ON u.id=c.changed_by WHERE c.business_id=?';
        $params=[$biz];if($before>0){$sql.=' AND c.id<?';$params[]=$before;}
        $st=db()->prepare($sql.' ORDER BY c.id DESC LIMIT '.($limit+1));
        $st->execute($params);$rows=$st->fetchAll();
        $hasMore=count($rows)>$limit;
        if($hasMore)array_pop($rows);
        foreach($rows as &$r){
            $r['before']=json_decode((string)$r['old_values_json'],true)??[];
            $r['after']=json_decode((string)$r['new_values_json'],true)??[];
            unset($r['old_values_json'],$r['new_values_json']);
        }
        unset($r);
        return respond($response,['changes'=>$rows,'next_before_id'=>$hasMore?(int)end($rows)['id']:null]);
    });

    // Exportación paginada, para no truncar silenciosamente el inventario.
    $group->get('/inventory/export', static function(Request $request, Response $response): Response {
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];
        $q=$request->getQueryParams();
        $after=isset($q['after_id'])?(int)$q['after_id']:0;
        if($after<0)fail('Cursor inválido.',422);
        $st=db()->prepare('SELECT id,name,sku,barcode,price_clp,sale_unit,stock_qty,is_active,created_at,updated_at FROM products WHERE business_id=? AND id>? ORDER BY id LIMIT 501');
        $st->execute([$biz,$after]);$rows=$st->fetchAll();
        $hasMore=count($rows)>500;
        if($hasMore)array_pop($rows);
        return respond($response,['products'=>$rows,'next_after_id'=>$hasMore?(int)end($rows)['id']:null]);
    });
}

