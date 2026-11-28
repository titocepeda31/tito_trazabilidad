<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

// Read-only trace. Historical sale entitlements and physical units are not interchangeable.
function traceUnitSql():string {
    return "SELECT x.id,x.serial_number,x.status,x.product_id,x.receipt_id,x.opening_id,x.supplier_id,x.warehouse_id,x.received_at,x.unit_cost_clp,x.created_at,p.name AS product_name,p.sku,w.name AS warehouse_name,rs.supplier_name AS receipt_supplier,rs.tax_id AS supplier_tax_id,rw.warehouse_name AS receipt_warehouse,o.warehouse_name AS opening_warehouse,o.counted_on AS opening_date,o.evidence_reference AS opening_evidence,m.name AS catalog_manufacturer,b.name AS catalog_brand FROM serial_units x JOIN products p ON p.id=x.product_id AND p.business_id=x.business_id JOIN warehouses w ON w.id=x.warehouse_id AND w.business_id=x.business_id LEFT JOIN receipt_supplier_snapshots rs ON rs.receipt_id=x.receipt_id AND rs.business_id=x.business_id AND rs.supplier_id=x.supplier_id LEFT JOIN receipt_warehouse_snapshots rw ON rw.receipt_id=x.receipt_id AND rw.business_id=x.business_id LEFT JOIN serial_opening_batches o ON o.id=x.opening_id AND o.business_id=x.business_id LEFT JOIN product_origins po ON po.product_id=x.product_id AND po.business_id=x.business_id LEFT JOIN manufacturers m ON m.id=po.manufacturer_id AND m.business_id=x.business_id LEFT JOIN brands b ON b.id=po.brand_id AND b.business_id=x.business_id";
}
function traceSaleScope(string $alias,string $unitColumn):string {
    return "($alias.$unitColumn=? OR EXISTS(SELECT 1 FROM warranty_free_exchanges f WHERE f.business_id=$alias.business_id AND f.sale_id=$alias.sale_id AND f.root_unit_id=$alias.$unitColumn AND (f.returned_unit_id=? OR f.replacement_unit_id=?)))";
}
function traceRows(PDO $db,string $sql,array $params):array {
    $s=$db->prepare($sql);$s->execute($params);$r=$s->fetchAll();$s->closeCursor();return $r;
}
function traceTimeline(PDO $db,int $biz,int $unit,int $offset):array {
    // All source timestamps are registration times. Effective dates are separate.
    $w=traceSaleScope('c','unit_id');
    $sql="SELECT t.*,u.email AS actor_email FROM (
        SELECT e.id,e.created_at,e.actor_id,'serial' AS source,e.unit_id AS reference_id,e.unit_id AS physical_unit_id,
        CONVERT(e.event_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS event_type,
        CONVERT(e.details USING utf8mb4) COLLATE utf8mb4_unicode_ci AS note,NULL AS evidence_reference,NULL AS event_on
        FROM serial_unit_events e WHERE e.business_id=? AND e.unit_id=?
        UNION ALL
        SELECT e.id,e.created_at,e.actor_id,'service',c.id,COALESCE(c.physical_unit_id,c.unit_id),
        CONVERT(e.to_status USING utf8mb4) COLLATE utf8mb4_unicode_ci,
        CONVERT(e.note USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(e.evidence_reference USING utf8mb4) COLLATE utf8mb4_unicode_ci,NULL
        FROM service_case_events e JOIN service_cases c ON c.id=e.case_id AND c.business_id=e.business_id WHERE e.business_id=? AND COALESCE(c.physical_unit_id,c.unit_id)=?
        UNION ALL
        SELECT e.id,e.created_at,e.actor_id,'coverage',c.id,c.unit_id,
        CONVERT(CONCAT(c.kind,':',e.kind) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
        CONVERT(e.note USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(e.evidence_reference USING utf8mb4) COLLATE utf8mb4_unicode_ci,e.event_on
        FROM warranty_coverage_events e JOIN sale_warranty_coverages c ON c.id=e.coverage_id AND c.business_id=e.business_id WHERE e.business_id=? AND $w
        UNION ALL
        SELECT r.id,r.created_at,r.actor_id,'replacement_coverage',r.exchange_id,f.replacement_unit_id,
        CONVERT(CONCAT(r.kind,':',r.action) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
        CONVERT(r.note USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(r.evidence_reference USING utf8mb4) COLLATE utf8mb4_unicode_ci,r.event_on
        FROM replacement_coverage_events r JOIN warranty_free_exchanges f ON f.id=r.exchange_id AND f.business_id=r.business_id WHERE r.business_id=? AND f.replacement_unit_id=?
        UNION ALL
        SELECT r.id,r.created_at,r.created_by,'repair',r.case_id,COALESCE(c.physical_unit_id,c.unit_id),
        'delivered',CONVERT(r.work_description USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(r.evidence_reference USING utf8mb4) COLLATE utf8mb4_unicode_ci,r.delivered_on
        FROM service_repair_warranties r JOIN service_cases c ON c.id=r.case_id AND c.business_id=r.business_id WHERE r.business_id=? AND COALESCE(c.physical_unit_id,c.unit_id)=?
    ) t LEFT JOIN users u ON u.id=t.actor_id AND u.business_id=? ORDER BY t.created_at DESC,t.source,t.id DESC LIMIT 26 OFFSET ".$offset;
    return traceRows($db,$sql,[$biz,$unit,$biz,$unit,$biz,$unit,$unit,$unit,$biz,$unit,$biz,$unit,$biz]);
}
function registerTraceabilityRoutes(RouteCollectorProxy $group):void {
    $group->get('/traceability/units',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$q=$p['q']??'';$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if($page===false||!is_string($q)||trim($q)===''||mb_strlen($q)>120)fail('Ingresa una serie, producto o SKU.',422);
        $term=trim($q);$serial=mb_strtoupper($term,'UTF-8');
        $sql=traceUnitSql()." WHERE x.business_id=? AND (LOCATE(?,x.serial_number)>0 OR LOCATE(?,p.name)>0 OR LOCATE(?,COALESCE(p.sku,''))>0) ORDER BY (x.serial_number=?) DESC,x.serial_number,x.id LIMIT 26 OFFSET ".(($page-1)*25);
        $rows=traceRows(db(),$sql,[$biz,$serial,$term,$term,$serial]);return respond($response,['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25]);
    });
    $group->get('/traceability/units/{id:[0-9]+}',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$unit=(int)$args['id'];$db=db();
        $rows=traceRows($db,traceUnitSql().' WHERE x.business_id=? AND x.id=?',[$biz,$unit]);if(!$rows)fail('Unidad no encontrada.',404);
        return respond($response,['unit'=>$rows[0]]);
    });
    $group->get('/traceability/units/{id:[0-9]+}/history',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$unit=(int)$args['id'];$p=$request->getQueryParams();$tab=$p['tab']??'timeline';$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if($page===false||!in_array($tab,['timeline','sales','warranties','service','repairs','changes','linked_sales','replacement_warranties','held'],true))fail('Filtro inválido.',422);
        $db=db();if(!traceRows($db,'SELECT id FROM serial_units WHERE business_id=? AND id=?',[$biz,$unit]))fail('Unidad no encontrada.',404);
        $offset=($page-1)*25;$limit=' LIMIT 26 OFFSET '.$offset;$scope=traceSaleScope('su','unit_id');$params=[$biz,$unit];
        switch($tab){
        case 'timeline':
            $rows=traceTimeline($db,$biz,$unit,$offset);break;
        case 'sales':
            $sql="SELECT su.unit_id AS root_unit_id,x.serial_number AS root_serial,su.sale_id,su.sale_item_id,su.unit_price_clp,s.created_at,s.status AS sale_status,ss.client_id,ss.client_name,sp.point_name,sp.branch_name,w.name AS dispatch_warehouse,rv.reversal_kind,rv.return_id,rv.credit_adjustment_id,rv.created_at AS reversed_at,cr.resolution,cr.refund_status FROM serial_sale_units su JOIN serial_units x ON x.id=su.unit_id AND x.business_id=su.business_id JOIN sales s ON s.id=su.sale_id AND s.business_id=su.business_id JOIN serial_sale_snapshots ss ON ss.sale_id=su.sale_id AND ss.business_id=su.business_id JOIN warehouses w ON w.id=su.warehouse_id AND w.business_id=su.business_id LEFT JOIN sale_point_snapshots sp ON sp.sale_id=su.sale_id AND sp.business_id=su.business_id LEFT JOIN serial_sale_reversals rv ON rv.unit_id=su.unit_id AND rv.sale_id=su.sale_id AND rv.business_id=su.business_id LEFT JOIN customer_returns cr ON cr.id=rv.return_id AND cr.business_id=su.business_id WHERE su.business_id=? AND $scope ORDER BY su.sale_id DESC,su.unit_id DESC";
            $rows=traceRows($db,$sql.$limit,[$biz,$unit,$unit,$unit]);break;
        case 'warranties':
            $rows=traceRows($db,warrantyViewSql().' WHERE c.business_id=? AND '.traceSaleScope('c','unit_id').' ORDER BY c.id DESC'.$limit,[$biz,$unit,$unit,$unit]);
            foreach($rows as &$r){$r=warrantyEnrich($db,$biz,$r);}unset($r);
            break;
        case 'service':
            $rows=traceRows($db,"SELECT c.id,c.sale_id,c.unit_id,c.physical_unit_id,c.client_id,h.name AS client_name,c.status,c.custody,c.problem,c.closure_outcome,c.prior_repair_id,c.created_at,c.closed_at FROM service_cases c JOIN habitual_clients h ON h.id=c.client_id AND h.business_id=c.business_id WHERE c.business_id=? AND COALESCE(c.physical_unit_id,c.unit_id)=? ORDER BY c.id DESC".$limit,$params);break;
        case 'repairs':
            $rows=traceRows($db,repairWarrantySql().' WHERE r.business_id=? AND COALESCE(c.physical_unit_id,c.unit_id)=? ORDER BY r.id DESC'.$limit,$params);foreach($rows as &$r)unset($r['created_by']);unset($r);break;
        case 'changes':
            // Show the entire free exchange chain of every sale entitlement related to this unit.
            $rows=traceRows($db,freeExchangeSql().' WHERE e.business_id=? AND '.traceSaleScope('e','root_unit_id').' ORDER BY e.id DESC'.$limit,[$biz,$unit,$unit,$unit]);break;
        case 'linked_sales':
            $rows=traceRows($db,replacementSql().' WHERE r.business_id=? AND (r.original_unit_id=? OR r.replacement_unit_id=?) ORDER BY r.id DESC'.$limit,[$biz,$unit,$unit]);break;
        case 'replacement_warranties':
            $rows=traceRows($db,freeExchangeSql().' WHERE e.business_id=? AND e.replacement_unit_id=? ORDER BY e.id DESC'.$limit,$params);
            foreach($rows as &$r)$r['coverages']=replacementCoverageSummary($db,$biz,(int)$r['id']);unset($r);break;
        case 'held':
            $rows=traceRows($db,'SELECT e.id,e.hold_event_id,e.action,e.from_status,e.to_status,e.recipient,e.note,e.evidence_reference,e.created_at,w.name AS warehouse_name,u.email AS actor_email FROM warranty_hold_events e JOIN warehouses w ON w.id=e.warehouse_id AND w.business_id=e.business_id LEFT JOIN users u ON u.id=e.actor_id AND u.business_id=e.business_id WHERE e.business_id=? AND e.unit_id=? ORDER BY e.id DESC'.$limit,$params);break;
        }
        return respond($response,['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25]);
    });
}
