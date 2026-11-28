<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function dashboardRows(PDO $db,string $sql,array $params):array {
    $s=$db->prepare($sql);$s->execute($params);$rows=$s->fetchAll();$s->closeCursor();return $rows;
}
function dashboardOne(PDO $db,string $sql,array $params):array {
    return dashboardRows($db,$sql,$params)[0]??[];
}
function dashboardCount(PDO $db,string $sql,array $params):int {
    return (int)(dashboardOne($db,$sql,$params)['n']??0);
}
function dashboardDateRange(string $period,DateTimeImmutable $now):array {
    if($period==='all_time')return [null,null];
    $start=match($period){'today'=>$now->setTime(0,0),'week'=>$now->modify('monday this week')->setTime(0,0),'month'=>$now->modify('first day of this month')->setTime(0,0)};
    $end=$start->modify(match($period){'today'=>'+1 day','week'=>'+7 days','month'=>'+1 month'});
    $utc=new DateTimeZone('UTC');return [$start->setTimezone($utc)->format('Y-m-d H:i:s'),$end->setTimezone($utc)->format('Y-m-d H:i:s')];
}
function dashboardStock(PDO $db,int $biz,string $today):array {
    // Sale-eligible locations only. Reserve by product and warehouse, never subtract globally.
    $balance="SELECT p.id,p.name,p.sku,p.sale_unit,w.id AS warehouse_id,w.name AS warehouse_name,COALESCE(ws.qty,0) AS stock,COALESCE(r.reserved,0) AS reserved
      FROM products p CROSS JOIN warehouses w
      LEFT JOIN warehouse_stocks ws ON ws.business_id=p.business_id AND ws.product_id=p.id AND ws.warehouse_id=w.id
      LEFT JOIN (SELECT i.product_id,s.warehouse_id,SUM(i.quantity-i.dispatched_quantity) AS reserved
                 FROM serial_reservation_items i JOIN serial_reservations s ON s.id=i.reservation_id AND s.business_id=i.business_id
                 WHERE i.business_id=? AND s.status='pending' AND (s.expires_on IS NULL OR s.expires_on>=?)
                 GROUP BY i.product_id,s.warehouse_id) r ON r.product_id=p.id AND r.warehouse_id=w.id
      WHERE p.business_id=? AND p.is_active=1 AND w.business_id=p.business_id AND w.is_active=1 AND w.is_sales_location=1";
    $args=[$biz,$today,$biz];
    $byUnit=dashboardRows($db,"SELECT b.sale_unit,SUM(b.stock) AS stock,SUM(b.reserved) AS reserved,SUM(GREATEST(b.stock-b.reserved,0)) AS available,SUM(CASE WHEN b.reserved>b.stock OR b.stock<0 THEN 1 ELSE 0 END) AS inconsistent_locations FROM ($balance) b GROUP BY b.sale_unit ORDER BY b.sale_unit",$args);
    $low="SELECT b.id,b.name,b.sku,b.sale_unit,SUM(b.stock) AS stock,SUM(b.reserved) AS reserved,SUM(GREATEST(b.stock-b.reserved,0)) AS available FROM ($balance) b GROUP BY b.id,b.name,b.sku,b.sale_unit HAVING SUM(GREATEST(b.stock-b.reserved,0))<=5";
    $lowCount=dashboardCount($db,"SELECT COUNT(*) AS n FROM ($low) t",$args);
    $lowRows=dashboardRows($db,$low.' ORDER BY available ASC,b.name,b.id LIMIT 8',$args);
    $blocked=dashboardCount($db,"SELECT COUNT(*) AS n FROM serial_reservation_items i JOIN serial_reservations r ON r.id=i.reservation_id AND r.business_id=i.business_id LEFT JOIN products p ON p.id=i.product_id AND p.business_id=i.business_id LEFT JOIN warehouses w ON w.id=r.warehouse_id AND w.business_id=r.business_id WHERE i.business_id=? AND r.status='pending' AND (r.expires_on IS NULL OR r.expires_on>=?) AND (i.quantity>i.dispatched_quantity) AND (p.id IS NULL OR p.is_active<>1 OR w.id IS NULL OR w.is_active<>1 OR w.is_sales_location<>1)",[$biz,$today]);
    $serial=dashboardRows($db,"SELECT x.status,COUNT(*) AS n FROM serial_units x WHERE x.business_id=? GROUP BY x.status ORDER BY x.status",[$biz]);
    $reservations=dashboardOne($db,"SELECT COUNT(CASE WHEN expires_on IS NULL OR expires_on>=? THEN 1 END) AS active,COUNT(CASE WHEN expires_on<? THEN 1 END) AS expired FROM serial_reservations WHERE business_id=? AND status='pending'",[$today,$today,$biz]);
    return ['by_unit'=>$byUnit,'low_stock_count'=>$lowCount,'low_stock'=>$lowRows,'blocked_reservation_lines'=>$blocked,'serial_states'=>$serial,'reservations'=>$reservations,'active_products'=>dashboardCount($db,'SELECT COUNT(*) AS n FROM products WHERE business_id=? AND is_active=1',[$biz]),'sales_locations'=>dashboardCount($db,'SELECT COUNT(*) AS n FROM warehouses WHERE business_id=? AND is_active=1 AND is_sales_location=1',[$biz])];
}
function dashboardPostSale(PDO $db,int $biz):array {
    $cases=dashboardRows($db,'SELECT status,COUNT(*) AS n FROM service_cases WHERE business_id=? GROUP BY status',[$biz]);
    // Original coverages: serial sale still physically assigned, no reversal or free replacement.
    // Non-serialized coverage is a line, not a unit; partial returns still require review.
    $original=dashboardRows($db,"SELECT c.activation_status,COUNT(*) AS n FROM sale_warranty_coverages c JOIN sales s ON s.id=c.sale_id AND s.business_id=c.business_id LEFT JOIN serial_units x ON x.id=c.unit_id AND x.business_id=c.business_id WHERE c.business_id=? AND c.kind='manufacturer' AND s.status='completed' AND (c.offered IS NULL OR c.offered=1) AND (c.unit_id IS NULL OR (x.status='sold' AND NOT EXISTS(SELECT 1 FROM serial_sale_reversals rv WHERE rv.business_id=c.business_id AND rv.unit_id=c.unit_id AND rv.sale_id=c.sale_id) AND NOT EXISTS(SELECT 1 FROM serial_sale_units later WHERE later.business_id=c.business_id AND later.unit_id=c.unit_id AND later.sale_id>c.sale_id) AND NOT EXISTS(SELECT 1 FROM warranty_free_exchanges f WHERE f.business_id=c.business_id AND f.sale_id=c.sale_id AND f.root_unit_id=c.unit_id))) GROUP BY c.activation_status",[$biz]);
    $active="FROM warranty_free_exchanges e JOIN serial_units x ON x.id=e.replacement_unit_id AND x.business_id=e.business_id JOIN sales s ON s.id=e.sale_id AND s.business_id=e.business_id WHERE e.business_id=? AND x.status='replacement_delivered' AND s.status='completed' AND NOT EXISTS(SELECT 1 FROM warranty_free_exchanges n WHERE n.business_id=e.business_id AND n.sale_id=e.sale_id AND n.root_unit_id=e.root_unit_id AND n.returned_unit_id=e.replacement_unit_id)";
    $replacements=dashboardRows($db,"SELECT COALESCE((SELECT r.activation_status FROM replacement_coverage_events r WHERE r.business_id=e.business_id AND r.exchange_id=e.id AND r.kind='manufacturer' ORDER BY r.id DESC LIMIT 1),'unknown') AS activation_status,COUNT(*) AS n $active GROUP BY activation_status",[$biz]);
    $review=dashboardCount($db,"SELECT COUNT(*) AS n $active AND (SELECT COUNT(*) FROM replacement_coverage_events r WHERE r.business_id=e.business_id AND r.exchange_id=e.id AND r.decision<>'pending' AND NOT EXISTS(SELECT 1 FROM replacement_coverage_events later WHERE later.business_id=r.business_id AND later.exchange_id=r.exchange_id AND later.kind=r.kind AND later.id>r.id))<3",[$biz]);
    return ['case_states'=>$cases,'original_activation'=>$original,'replacement_activation'=>$replacements,'replacement_review_pending'=>$review];
}
function registerDashboardRoutes(RouteCollectorProxy $group):void {
    $group->get('/reports/management-dashboard',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$period=$p['period']??'month';
        if(array_diff(array_keys($p),['period'])||!in_array($period,['today','week','month','all_time'],true))fail('Período inválido.',422);
        $db=db();$now=new DateTimeImmutable('now',new DateTimeZone('America/Santiago'));$today=$now->format('Y-m-d');[$start,$end]=dashboardDateRange($period,$now);
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();
        try{
            $financial=$period==='all_time'?titoCreditFinancialSummary($db,$biz):titoCreditFinancialPeriod($db,$biz,$period);
            // Align all filters with the exact window returned by the financial helper (including midnight).
            if($period!=='all_time'){$utc=new DateTimeZone('UTC');$start=(new DateTimeImmutable($financial['window_start_local']))->setTimezone($utc)->format('Y-m-d H:i:s');$end=(new DateTimeImmutable($financial['window_end_local']))->setTimezone($utc)->format('Y-m-d H:i:s');}
            $where=$start===null?'':' AND created_at>=? AND created_at<?';$args=$start===null?[$biz]:[$biz,$start,$end];
            $sales=dashboardRows($db,'SELECT status,COUNT(*) AS n FROM sales WHERE business_id=?'.$where.' GROUP BY status',$args);
            $quotesCreated=dashboardCount($db,'SELECT COUNT(*) AS n FROM quotes WHERE business_id=?'.$where,$args);
            // Pipeline is a current snapshot; amount is original quote total, not balance to dispatch.
            $pipeline=dashboardRows($db,"SELECT CASE WHEN q.status IN ('sent','accepted') AND q.valid_until<? THEN 'expired' WHEN q.status='reserved' AND EXISTS(SELECT 1 FROM serial_reservations r WHERE r.business_id=q.business_id AND r.quote_id=q.id AND r.status='pending' AND r.expires_on<?) THEN 'reservation_expired' ELSE q.status END AS effective_status,COUNT(*) AS n,COALESCE(SUM(q.total_clp),0) AS original_total_clp FROM quotes q WHERE q.business_id=? GROUP BY effective_status ORDER BY effective_status",[$today,$today,$biz]);
            $overdueTasks=dashboardCount($db,"SELECT COUNT(*) AS n FROM quote_followups WHERE business_id=? AND status='pending' AND kind='task' AND due_on<?",[$biz,$today]);
            $due=dashboardOne($db,"SELECT COUNT(CASE WHEN z.balance>0 THEN 1 END) AS accounts_open,COUNT(CASE WHEN z.balance>0 AND z.due_date<? THEN 1 END) AS accounts_overdue,COALESCE(SUM(CASE WHEN z.due_date<? THEN z.balance ELSE 0 END),0) AS overdue_clp FROM (SELECT a.due_date,GREATEST(0,a.original_total_clp-COALESCE(adj.adjusted,0)-COALESCE(p.paid,0)) AS balance FROM credit_accounts a LEFT JOIN (SELECT account_id,business_id,SUM(amount_clp) AS adjusted FROM credit_adjustments WHERE business_id=? GROUP BY account_id,business_id) adj ON adj.account_id=a.id AND adj.business_id=a.business_id LEFT JOIN (SELECT account_id,business_id,SUM(amount_clp) AS paid FROM credit_payments WHERE business_id=? GROUP BY account_id,business_id) p ON p.account_id=a.id AND p.business_id=a.business_id WHERE a.business_id=?) z",[$today,$today,$biz,$biz,$biz]);
            $trend=[];$utc=new DateTimeZone('UTC');
            for($i=6;$i>=0;$i--){$day=$now->setTime(0,0)->modify('-'.$i.' days');$next=$day->modify('+1 day');$r=dashboardOne($db,"SELECT COUNT(*) AS n,COALESCE(SUM(total_clp),0) AS gross_clp FROM sales WHERE business_id=? AND status='completed' AND created_at>=? AND created_at<?",[$biz,$day->setTimezone($utc)->format('Y-m-d H:i:s'),$next->setTimezone($utc)->format('Y-m-d H:i:s')]);$trend[]=['day'=>$day->format('Y-m-d'),'n'=>(int)$r['n'],'gross_clp'=>(int)$r['gross_clp']];}
            $stock=dashboardStock($db,$biz,$today);$pos=dashboardPostSale($db,$biz);
            $cashOpen=titoCashReady($db)?dashboardCount($db,"SELECT COUNT(*) AS n FROM cash_sessions WHERE business_id=? AND status='open'",[$biz]):null;
            $db->commit();
            return respond($response,['scope'=>$period,'generated_at'=>$now->format(DateTimeInterface::ATOM),'today'=>$today,'financial'=>$financial,'sales_states'=>$sales,'quotes_created'=>$quotesCreated,'quote_pipeline'=>$pipeline,'overdue_followups'=>$overdueTasks,'receivables'=>$due,'trend'=>$trend,'stock'=>$stock,'post_sale'=>$pos,'cash_open'=>$cashOpen]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
