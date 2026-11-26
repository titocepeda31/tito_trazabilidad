<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * Establecer el responsable ANTES de una creación de producto.
 * La auditoría la escribe un trigger SQL en la misma transacción que INSERT.
 * Solo se invoca después de adminOnly y valida el negocio en el trigger.
 */
function titoProductCreationActor(PDO $db,int $biz,int $uid,string $origin): void{
    if($biz<1||$uid<1||!in_array($origin,['manual','excel','mercaderia'],true)){
        throw new InvalidArgumentException('Contexto de auditoría inválido.');
    }
    $st=$db->prepare('SET @tito_product_creator_id = ?, @tito_product_creator_business = ?, @tito_product_creator_origin = ?');
    $st->execute([$uid,$biz,$origin]);
    $st->closeCursor();
}

function registerProductCreationAuditRoutes(RouteCollectorProxy $group): void{
    $group->get('/inventory/creations', static function(Request $request,Response $response):Response{
        $admin=adminOnly($request);$biz=(int)$admin['business_id'];
        $query=$request->getQueryParams();
        $limit=min(200,max(1,(int)($query['limit']??100)));
        $before=isset($query['before_id'])?(int)$query['before_id']:0;
        if($before<0)fail('Filtro de auditoría inválido.',422);
        $sql='SELECT c.id,c.product_id,c.name_snapshot,c.sku_snapshot,c.barcode_snapshot,
            c.price_clp,c.sale_unit,c.stock_initial_qty,c.origin,c.created_at,
            p.name AS current_name,u.email AS actor_email
            FROM product_creation_audit c
            JOIN products p ON p.id=c.product_id AND p.business_id=c.business_id
            LEFT JOIN users u ON u.id=c.created_by AND u.business_id=c.business_id
            WHERE c.business_id=?';
        $params=[$biz];
        if($before>0){$sql.=' AND c.id<?';$params[]=$before;}
        $st=db()->prepare($sql.' ORDER BY c.id DESC LIMIT '.($limit+1));
        $st->execute($params);$rows=$st->fetchAll();$st->closeCursor();
        $more=count($rows)>$limit;
        if($more)array_pop($rows);
        return respond($response,[
            'creations'=>$rows,
            'next_before_id'=>$more?(int)end($rows)['id']:null,
            'note'=>'Auditoría de productos creados desde la instalación del registro; las altas anteriores no tienen autor reconstruible.'
        ]);
    });
}

