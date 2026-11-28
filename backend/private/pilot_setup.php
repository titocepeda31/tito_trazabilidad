<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function registerPilotSetupRoutes(RouteCollectorProxy $group):void {
    $group->get('/pilot/setup',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$db=db();
        // Evidence of configuration, not acceptance of production readiness.
        $count=static function(string $sql,array $params=[])use($db,$biz):int{
            $s=$db->prepare($sql);$s->execute($params?:[$biz]);$n=$s->fetchColumn();$s->closeCursor();return (int)$n;
        };
        $s=$db->prepare('SELECT b.name,p.legal_name,p.tax_id,p.address,p.city,p.email FROM businesses b LEFT JOIN business_profiles p ON p.business_id=b.id WHERE b.id=?');$s->execute([$biz]);$profile=$s->fetch();$s->closeCursor();
        if(!$profile)fail('Empresa no encontrada.',404);
        $missing=[];foreach(['legal_name'=>'Razón social','tax_id'=>'RUT','address'=>'Dirección','city'=>'Ciudad','email'=>'Correo'] as $field=>$label){if(trim((string)($profile[$field]??''))==='')$missing[]=$label;}
        $c=[];
        $c['branches']=$count('SELECT COUNT(*) FROM branches WHERE business_id=? AND is_active=1');
        $c['warehouses']=$count('SELECT COUNT(*) FROM warehouses WHERE business_id=? AND is_active=1');
        $c['sales_warehouses']=$count('SELECT COUNT(*) FROM warehouses WHERE business_id=? AND is_active=1 AND is_sales_location=1');
        $c['unassigned_warehouses']=$count("SELECT COUNT(*) FROM warehouses w LEFT JOIN branch_warehouses bw ON bw.business_id=w.business_id AND bw.warehouse_id=w.id LEFT JOIN branches b ON b.business_id=bw.business_id AND b.id=bw.branch_id AND b.is_active=1 WHERE w.business_id=? AND w.is_active=1 AND b.id IS NULL");
        $c['sales_points']=$count("SELECT COUNT(*) FROM sales_points p JOIN branches b ON b.id=p.branch_id AND b.business_id=p.business_id AND b.is_active=1 WHERE p.business_id=? AND p.is_active=1");
        $c['terminals']=$count("SELECT COUNT(*) FROM pos_terminals t JOIN warehouses w ON w.id=t.warehouse_id AND w.business_id=t.business_id AND w.is_active=1 AND w.is_sales_location=1 WHERE t.business_id=? AND t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP()");
        $c['linked_terminals']=$count("SELECT COUNT(*) FROM pos_terminals t JOIN warehouses w ON w.id=t.warehouse_id AND w.business_id=t.business_id AND w.is_active=1 AND w.is_sales_location=1 JOIN sales_point_terminals m ON m.terminal_id=t.id AND m.business_id=t.business_id JOIN sales_points p ON p.id=m.sales_point_id AND p.business_id=m.business_id AND p.is_active=1 JOIN branches b ON b.id=p.branch_id AND b.business_id=p.business_id AND b.is_active=1 WHERE t.business_id=? AND t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP()");
        $c['users']=$count('SELECT COUNT(*) FROM users WHERE business_id=? AND is_active=1');
        $c['admins']=$count("SELECT COUNT(*) FROM users WHERE business_id=? AND is_active=1 AND role='admin'");
        $c['roles']=$count('SELECT COUNT(*) FROM access_roles WHERE business_id=? AND is_active=1');
        $c['manufacturers']=$count('SELECT COUNT(*) FROM manufacturers WHERE business_id=? AND is_active=1');
        $c['brands']=$count('SELECT COUNT(*) FROM brands WHERE business_id=? AND is_active=1');
        $c['suppliers']=$count('SELECT COUNT(*) FROM suppliers WHERE business_id=? AND is_active=1');
        $c['products']=$count('SELECT COUNT(*) FROM products WHERE business_id=? AND is_active=1');
        $c['products_without_sku']=$count("SELECT COUNT(*) FROM products WHERE business_id=? AND is_active=1 AND (sku IS NULL OR TRIM(sku)='')");
        $c['serial_products']=$count('SELECT COUNT(*) FROM serial_product_controls c JOIN products p ON p.id=c.product_id AND p.business_id=c.business_id AND p.is_active=1 WHERE c.business_id=?');
        $c['serial_without_manufacturer']=$count("SELECT COUNT(*) FROM serial_product_controls c JOIN products p ON p.id=c.product_id AND p.business_id=c.business_id AND p.is_active=1 LEFT JOIN product_origins o ON o.product_id=p.id AND o.business_id=p.business_id LEFT JOIN manufacturers m ON m.id=o.manufacturer_id AND m.business_id=o.business_id AND m.is_active=1 WHERE c.business_id=? AND m.id IS NULL");
        $c['clients']=$count('SELECT COUNT(*) FROM habitual_clients WHERE business_id=? AND is_active=1');
        $c['opening_batches']=$count('SELECT COUNT(*) FROM serial_opening_batches WHERE business_id=?');
        $c['stock_serial_units']=$count("SELECT COUNT(*) FROM serial_units WHERE business_id=? AND status='in_stock'");
        $c['products_with_stock']=$count('SELECT COUNT(*) FROM products WHERE business_id=? AND is_active=1 AND stock_qty>0');
        $c['global_stock_mismatches']=$count("SELECT COUNT(*) FROM products p LEFT JOIN (SELECT business_id,product_id,SUM(qty) AS total FROM warehouse_stocks WHERE business_id=? GROUP BY business_id,product_id) ws ON ws.business_id=p.business_id AND ws.product_id=p.id WHERE p.business_id=? AND (ws.total IS NULL OR ABS(p.stock_qty-ws.total)>0.0005)",[$biz,$biz]);
        $c['serial_global_mismatches']=$count("SELECT COUNT(*) FROM serial_product_controls c JOIN products p ON p.id=c.product_id AND p.business_id=c.business_id LEFT JOIN (SELECT business_id,product_id,COUNT(*) AS n FROM serial_units WHERE business_id=? AND status='in_stock' GROUP BY business_id,product_id) x ON x.business_id=c.business_id AND x.product_id=c.product_id WHERE c.business_id=? AND ABS(p.stock_qty-COALESCE(x.n,0))>0.0005",[$biz,$biz]);
        $c['serial_location_mismatches']=$count("SELECT COUNT(*) FROM warehouse_stocks ws JOIN serial_product_controls c ON c.product_id=ws.product_id AND c.business_id=ws.business_id LEFT JOIN (SELECT business_id,product_id,warehouse_id,COUNT(*) AS n FROM serial_units WHERE business_id=? AND status='in_stock' GROUP BY business_id,product_id,warehouse_id) x ON x.business_id=ws.business_id AND x.product_id=ws.product_id AND x.warehouse_id=ws.warehouse_id WHERE ws.business_id=? AND ABS(ws.qty-COALESCE(x.n,0))>0.0005",[$biz,$biz]);
        $c['serial_missing_location']=$count("SELECT COUNT(*) FROM serial_units x LEFT JOIN warehouse_stocks ws ON ws.business_id=x.business_id AND ws.product_id=x.product_id AND ws.warehouse_id=x.warehouse_id WHERE x.business_id=? AND x.status='in_stock' AND ws.product_id IS NULL");
        $c['warranty_policies']=$count('SELECT COUNT(*) FROM warranty_policies WHERE business_id=?');
        return respond($response,['company'=>$profile['name'],'profile_missing'=>$missing,'counts'=>$c,'checked_on'=>quoteToday(),'dte_state'=>'not_integrated','acceptance_state'=>'pending']);
    });
}
