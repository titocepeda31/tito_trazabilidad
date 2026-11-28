<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

// Internal SKU, never a manufacturer's serial number or a GS1/GTIN.
function productAutoSku(PDO $db,int $biz):string {
    $s=$db->prepare('SELECT id FROM products WHERE business_id=? AND (sku=? OR barcode=?) LIMIT 1');
    for($i=0;$i<10;$i++){
        $code='TT-'.strtoupper(bin2hex(random_bytes(6)));
        $s->execute([$biz,$code,$code]);$used=$s->fetchColumn();$s->closeCursor();
        if(!$used)return $code;
    }
    fail('No fue posible asignar un código interno único.',409);
}
function registerLabelRoutes(RouteCollectorProxy $group):void {
    $group->get('/labels/search',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$kind=$p['kind']??'product';$q=$p['q']??'';$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if($page===false||!in_array($kind,['product','serial'],true)||!is_string($q)||mb_strlen(trim($q))>120)fail('Filtro inválido.',422);
        $term=trim($q);$serial=mb_strtoupper($term,'UTF-8');$args=[$biz];
        if($kind==='product'){
            $sql="SELECT p.id,p.name AS product_name,p.sku,p.barcode,NULL AS serial_number,NULL AS warehouse_name,NULL AS status FROM products p WHERE p.business_id=? AND p.is_active=1";
            if($term!==''){$sql.=" AND (LOCATE(?,p.name)>0 OR LOCATE(?,COALESCE(p.sku,''))>0 OR LOCATE(?,COALESCE(p.barcode,''))>0)";$args=array_merge($args,[$term,$term,$term]);}
            $sql.=' ORDER BY p.name,p.id';
        }else{
            $sql="SELECT x.id,p.name AS product_name,p.sku,p.barcode,x.serial_number,w.name AS warehouse_name,x.status FROM serial_units x JOIN products p ON p.id=x.product_id AND p.business_id=x.business_id JOIN warehouses w ON w.id=x.warehouse_id AND w.business_id=x.business_id WHERE x.business_id=?";
            if($term!==''){$sql.=" AND (LOCATE(?,x.serial_number)>0 OR LOCATE(?,p.name)>0 OR LOCATE(?,COALESCE(p.sku,''))>0)";$args=array_merge($args,[$serial,$term,$term]);}
            $sql.=' ORDER BY x.id DESC';
        }
        $s=db()->prepare($sql.' LIMIT 26 OFFSET '.(($page-1)*25));$s->execute($args);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25]);
    });
}
