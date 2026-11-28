<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * Ajustes del libro de crédito. Toda devolución/reversa física se registra
 * junto con el ajuste monetario bajo un único COMMIT InnoDB.
 */
function titoCreditTotals(PDO $db,int $biz,int $accountId,int $original): array {
    $st=$db->prepare('SELECT COALESCE(SUM(amount_clp),0) FROM credit_adjustments WHERE business_id=? AND account_id=?');
    $st->execute([$biz,$accountId]);$adjusted=(int)$st->fetchColumn();$st->closeCursor();
    $st=$db->prepare('SELECT COALESCE(SUM(amount_clp),0) FROM credit_payments WHERE business_id=? AND account_id=?');
    $st->execute([$biz,$accountId]);$paid=(int)$st->fetchColumn();$st->closeCursor();
    $st=$db->prepare('SELECT COALESCE(SUM(amount_clp),0) FROM credit_refunds WHERE business_id=? AND account_id=?');
    $st->execute([$biz,$accountId]);$refunded=(int)$st->fetchColumn();$st->closeCursor();
    $effective=$original-$adjusted;
    if($effective<0||$refunded>max(0,$paid-$effective))throw new RuntimeException('Libro de crédito inconsistente. No se modificó la operación.');
    return [
        'adjusted_clp'=>$adjusted,'net_total_clp'=>$effective,
        'paid_clp'=>$paid,'refunded_clp'=>$refunded,
        'balance_clp'=>max(0,$effective-$paid),
        'refund_due_clp'=>max(0,$paid-$effective-$refunded)
    ];
}
function titoCreditAdjustmentItems(array $body): array {
    $rows=$body['items']??null;
    if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>80)fail('Selecciona entre 1 y 80 productos.',422);
    $wanted=[];
    foreach($rows as $row){
        if(!is_array($row)||array_diff(array_keys($row),['sale_item_id','quantity']))fail('Campos de devolución inválidos.',422);
        $id=filter_var($row['sale_item_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $q=$row['quantity']??null;
        if($id===false||isset($wanted[$id])||(!is_int($q)&&!is_float($q)))fail('Producto o cantidad inválida.',422);
        $qty=(float)$q;
        if(!is_finite($qty)||$qty<=0||$qty>1000000000||abs($qty*1000-round($qty*1000))>0.000001)fail('Cantidad fuera de rango.',422);
        $wanted[(int)$id]=number_format($qty,3,'.','');
    }
    ksort($wanted,SORT_NUMERIC);
    return $wanted;
}
function titoCreditSerialSpecs(mixed $rows): array {
    if(!is_array($rows)||!array_is_list($rows)||count($rows)>80)fail('Selección de series inválida.',422);
    $out=[];
    foreach($rows as $row){
        if(!is_array($row)||array_diff(array_keys($row),['sale_item_id','serial_numbers']))fail('Campos de series inválidos.',422);
        $id=originId($row['sale_item_id']??null,'sale_item_id');
        if(!$id||isset($out[$id]))fail('Línea repetida o inválida.',422);
        $out[$id]=serialNormalizeList($row['serial_numbers']??null);
        if(!$out[$id])fail('Selecciona series.',422);
    }
    ksort($out,SORT_NUMERIC);return $out;
}
function titoCreditAccountLock(PDO $db,int $biz,int $id): array {
    $st=$db->prepare('SELECT a.id,a.business_id,a.sale_id,a.client_id,a.status,a.original_total_clp,s.status AS sale_status FROM credit_accounts a JOIN sales s ON s.id=a.sale_id AND s.business_id=a.business_id WHERE a.business_id=? AND a.id=? FOR UPDATE');
    $st->execute([$biz,$id]);$a=$st->fetch();$st->closeCursor();
    if(!$a)fail('Cuenta a crédito no encontrada.',404);
    return $a;
}
function titoCreditAdjustmentLines(PDO $db,int $biz,int $saleId,array $wanted,string $kind,array $series=[]): array {
    $st=$db->prepare('SELECT si.id,si.product_id,si.quantity,si.unit_price_clp,si.subtotal_clp,p.name,p.sale_unit,p.is_active,p.stock_qty FROM sale_items si JOIN products p ON p.id=si.product_id AND p.business_id=? WHERE si.sale_id=? ORDER BY si.id');
    $st->execute([$biz,$saleId]);$lines=$st->fetchAll();$st->closeCursor();
    $byId=[];foreach($lines as $line)$byId[(int)$line['id']]=$line;
    $st=$db->prepare('SELECT ai.sale_item_id,COALESCE(SUM(ai.quantity),0) qty,COALESCE(SUM(ai.amount_clp),0) amount_clp FROM credit_adjustment_items ai JOIN credit_adjustments a ON a.id=ai.adjustment_id AND a.business_id=? JOIN credit_accounts ca ON ca.id=a.account_id WHERE ca.sale_id=? GROUP BY ai.sale_item_id');
    $st->execute([$biz,$saleId]);$prior=[];
    foreach($st->fetchAll() as $row)$prior[(int)$row['sale_item_id']]=$row;
    $st->closeCursor();
    if(array_diff_key($series,$wanted))fail('Series de líneas no seleccionadas.',422);
    $out=[];$sum=0;
    foreach($wanted as $id=>$rawQty){
        if(!isset($byId[$id]))fail('El producto no pertenece a la venta.',422);
        $line=$byId[$id];$qty=(float)$rawQty;
        $old=(float)($prior[$id]['qty']??0);$left=(float)$line['quantity']-$old;
        if($left<=0||$qty>$left+0.000001)fail('Cantidad superior a lo pendiente de devolver.',409);
        if($line['sale_unit']==='unidad'&&floor($qty)!==$qty)fail('Esta unidad no admite fracciones.',422);
        $remainingAmount=(int)$line['subtotal_clp']-(int)($prior[$id]['amount_clp']??0);
        if($remainingAmount<0)throw new RuntimeException('Precio histórico inconsistente.');
        $value=$qty>=$left-0.000001
          ? $remainingAmount
          : min($remainingAmount,(int)round($qty*(int)$line['unit_price_clp'],0,PHP_ROUND_HALF_UP));
        $pid=(int)$line['product_id'];$numbers=$series[$id]??[];
        $units=serialSaleLineUnits($db,$biz,$saleId,(int)$id);
        $controlled=serialControlled($db,$biz,$pid);
        if($controlled||$units){
            if(!$controlled||count($units)!==(int)$line['quantity']||floor($qty)!==$qty||count($numbers)!==(int)$qty)fail('Selecciona exactamente las series vendidas que se recuperan.',422);
            $available=[];foreach($units as $unit)if($unit['reversal_kind']===null&&$unit['status']==='sold')$available[]=$unit['serial_number'];
            if(array_diff($numbers,$available))fail('Serie no disponible para devolver en esta venta.',409);
        }elseif($numbers)fail('Series de producto no serializado.',422);
        $out[]=['line'=>$line,'qty'=>$qty,'rawQty'=>$rawQty,'amount'=>$value,'serial_numbers'=>$numbers];
        $sum+=$value;
        if($sum>1000000000000)fail('Total fuera de rango.',422);
    }
    if(!$out)fail('Sin productos para devolver.',422);
    return [$out,$sum,$lines,$prior];
}
function titoCreditRestore(PDO $db,int $biz,int $uid,int $saleId,int $adjustmentId,array $validated,bool $restock,string $kind,string $reason): void {
    $saleLocation=titoSaleHistoricalLocation($db,$biz,$saleId);
    $items=$db->prepare('INSERT INTO credit_adjustment_items(adjustment_id,sale_item_id,product_id,quantity,amount_clp,restocked) VALUES(?,?,?,?,?,?)');
    $stock=$db->prepare('SELECT id,is_active,name,sale_unit,stock_qty FROM products WHERE id=? AND business_id=? FOR UPDATE');
    foreach($validated as $v){
        $line=$v['line'];$pid=(int)$line['product_id'];
        if($v['serial_numbers']){
            $stock->execute([$pid,$biz]);$product=$stock->fetch();$stock->closeCursor();
            if(!$product||(int)$product['is_active']!==1||$product['sale_unit']!==$line['sale_unit'])fail('Producto serializado inactivo o modificado.',409);
            serialReconcile($db,$biz,$pid,(float)$product['stock_qty']);
            serialRestoreUnits($db,['business_id'=>$biz,'id'=>$uid],$saleId,(int)$line['id'],$pid,$saleLocation,$v['serial_numbers'],$kind,null,$reason,$adjustmentId);
            $balance=$restock?titoRestoreSerialLocationStock($db,$biz,$pid,$saleLocation,$v['qty']):$product['stock_qty'];
            if($restock)move($db,$biz,$pid,null,$uid,'adjustment',number_format($v['qty'],3,'.',''),$balance,'Reversa serializada crédito #'.$adjustmentId.' · Venta #'.$saleId);
            serialReconcile($db,$biz,$pid,(float)$balance);
        }elseif($restock){
            $stock->execute([$pid,$biz]);$product=$stock->fetch();$stock->closeCursor();
            if(!$product||(int)$product['is_active']!==1||$product['sale_unit']!==$line['sale_unit']){
                fail('No se puede restituir automáticamente el producto inactivo o modificado.',409);
            }
            $next=(float)$product['stock_qty']+$v['qty'];
            if($next>1000000000)fail('Stock resultante fuera de rango.',422);
            $formatted=number_format($next,3,'.','');
            $formatted=titoApplyLocationStock($db,$biz,$pid,$saleLocation,$v['qty']);
            // Se restituye el stock a la sucursal donde ocurrió la venta.
            move($db,$biz,$pid,null,$uid,'adjustment',number_format($v['qty'],3,'.',''),$formatted,'Ajuste crédito #'.$adjustmentId.' · Venta #'.$saleId.' · recibido para reventa');
        }
        $items->execute([$adjustmentId,(int)$line['id'],$pid,$v['rawQty'],$v['amount'],$restock?1:0]);
    }
}
function titoCreditFinancialSummary(PDO $db,int $biz): array {
    $one=static function(string $sql,array $params)use($db):int{
        $st=$db->prepare($sql);$st->execute($params);$v=(int)$st->fetchColumn();$st->closeCursor();return $v;
    };
    $gross=$one("SELECT COALESCE(SUM(total_clp),0) FROM sales WHERE business_id=? AND status='completed'",[$biz]);
    $void=$one("SELECT COALESCE(SUM(total_clp),0) FROM sales WHERE business_id=? AND status='voided'",[$biz]);
    $returnsImmediate=$one("SELECT COALESCE(SUM(r.amount_clp),0) FROM customer_returns r JOIN sales s ON s.id=r.sale_id AND s.business_id=r.business_id WHERE r.business_id=? AND s.status='completed' AND r.resolution='refund'",[$biz]);
    $returnsCredit=$one("SELECT COALESCE(SUM(a.amount_clp),0) FROM credit_adjustments a JOIN credit_accounts c ON c.id=a.account_id AND c.business_id=a.business_id JOIN sales s ON s.id=c.sale_id AND s.business_id=c.business_id WHERE a.business_id=? AND a.kind='return' AND s.status='completed'",[$biz]);
    // Cobros declarados, no conciliados con bancos. Al anular una venta pagada
    // el cobro histórico sigue hasta que se registre un reembolso confirmado.
    $immediate=$one("SELECT COALESCE(SUM(s.total_clp),0) FROM sales s LEFT JOIN credit_accounts c ON c.sale_id=s.id WHERE s.business_id=? AND c.id IS NULL",[$biz]);
    $creditPaid=$one('SELECT COALESCE(SUM(amount_clp),0) FROM credit_payments WHERE business_id=? AND advance_quote_id IS NULL',[$biz]);
    $advanceReceived=$one("SELECT COALESCE(SUM(amount_clp),0) FROM quote_advance_movements WHERE business_id=? AND kind='receipt'",[$biz]);
    $advanceRefund=$one("SELECT COALESCE(SUM(amount_clp),0) FROM quote_advance_movements WHERE business_id=? AND kind='refund'",[$biz]);
    $advanceApplied=$one('SELECT COALESCE(SUM(amount_clp),0) FROM credit_payments WHERE business_id=? AND advance_quote_id IS NOT NULL',[$biz]);
    $refundsImmediate=$one("SELECT COALESCE(SUM(amount_clp),0) FROM customer_returns WHERE business_id=? AND refund_status='confirmed' AND resolution='refund'",[$biz]);
    $refundsCredit=$one('SELECT COALESCE(SUM(amount_clp),0) FROM credit_refunds WHERE business_id=?',[$biz]);
    $st=$db->prepare(
        'SELECT COALESCE(SUM(GREATEST(0,a.original_total_clp-COALESCE(adj.adjusted,0)-COALESCE(p.paid,0))),0) AS outstanding,
        COALESCE(SUM(GREATEST(0,COALESCE(p.paid,0)-(a.original_total_clp-COALESCE(adj.adjusted,0))-COALESCE(r.refunded,0))),0) AS refund_due
        FROM credit_accounts a
        LEFT JOIN (SELECT account_id,SUM(amount_clp) adjusted FROM credit_adjustments GROUP BY account_id) adj ON adj.account_id=a.id
        LEFT JOIN (SELECT account_id,SUM(amount_clp) paid FROM credit_payments GROUP BY account_id) p ON p.account_id=a.id
        LEFT JOIN (SELECT account_id,SUM(amount_clp) refunded FROM credit_refunds GROUP BY account_id) r ON r.account_id=a.id
        WHERE a.business_id=?');
    $st->execute([$biz]);$totals=$st->fetch();$st->closeCursor();
    return [
        'scope'=>'all_time',
        'gross_active_sales_clp'=>$gross,
        'voided_sales_clp'=>$void,
        'accepted_returns_clp'=>$returnsImmediate+$returnsCredit,
        'net_sales_clp'=>max(0,$gross-$returnsImmediate-$returnsCredit),
        'immediate_declared_clp'=>$immediate,
        'credit_payments_clp'=>$creditPaid,
        'advances_received_clp'=>$advanceReceived,'advances_refunded_clp'=>$advanceRefund,'advances_available_clp'=>$advanceReceived-$advanceRefund-$advanceApplied,
        'refunds_confirmed_clp'=>$refundsImmediate+$refundsCredit+$advanceRefund,
        'net_collections_declared_clp'=>$immediate+$creditPaid+$advanceReceived-$refundsImmediate-$refundsCredit-$advanceRefund,
        'receivables_clp'=>(int)$totals['outstanding'],
        'credit_refund_due_clp'=>(int)$totals['refund_due']
    ];
}

/**
 * Filtra ventas por FECHA DE VENTA (cohorte, con estado y devoluciones
 * conocido hoy); los cobros y reembolsos por la fecha real de cada movimiento.
 * Los saldos de deuda y créditos a favor son siempre los ACTUALES.
 * Para TIMESTAMP se utiliza UNIX_TIMESTAMP, independiente de la zona de
 * sesión MySQL; refunded_at es DATETIME UTC guardado con UTC_TIMESTAMP().
 */
function titoCreditFinancialPeriod(PDO $db,int $biz,string $period,?DateTimeImmutable $referenceNow=null):array{
    if(!in_array($period,['today','week','month'],true))fail('Período financiero inválido.',422);
    $tz=new DateTimeZone('America/Santiago');
    $now=$referenceNow!==null?$referenceNow->setTimezone($tz):new DateTimeImmutable('now',$tz);
    if($period==='today'){
        $start=$now->setTime(0,0);
        $end=$start->modify('+1 day');
    }elseif($period==='week'){
        $start=$now->modify('monday this week')->setTime(0,0);
        $end=$start->modify('+7 days');
    }else{
        $start=$now->modify('first day of this month')->setTime(0,0);
        $end=$start->modify('+1 month');
    }
    $from=$start->getTimestamp();$to=$end->getTimestamp();
    $filter=static fn(string $column):string=>"UNIX_TIMESTAMP($column)>=? AND UNIX_TIMESTAMP($column)<?";
    $sum=static function(string $sql,array $params)use($db):int{
        $st=$db->prepare($sql);
        $st->execute($params);
        $v=(int)$st->fetchColumn();$st->closeCursor();return $v;
    };
    $saleWindow=$filter('s.created_at');
    $gross=$sum("SELECT COALESCE(SUM(s.total_clp),0) FROM sales s WHERE s.business_id=? AND s.status='completed' AND $saleWindow",[$biz,$from,$to]);
    $void=$sum("SELECT COALESCE(SUM(s.total_clp),0) FROM sales s WHERE s.business_id=? AND s.status='voided' AND $saleWindow",[$biz,$from,$to]);
    $immediateReturns=$sum("SELECT COALESCE(SUM(r.amount_clp),0) FROM customer_returns r JOIN sales s ON s.id=r.sale_id AND s.business_id=r.business_id WHERE r.business_id=? AND s.status='completed' AND r.resolution='refund' AND $saleWindow",[$biz,$from,$to]);
    $creditReturns=$sum("SELECT COALESCE(SUM(a.amount_clp),0) FROM credit_adjustments a JOIN credit_accounts c ON c.id=a.account_id AND c.business_id=a.business_id JOIN sales s ON s.id=c.sale_id AND s.business_id=c.business_id WHERE a.business_id=? AND a.kind='return' AND s.status='completed' AND $saleWindow",[$biz,$from,$to]);
    $immediate=$sum("SELECT COALESCE(SUM(s.total_clp),0) FROM sales s LEFT JOIN credit_accounts c ON c.sale_id=s.id WHERE s.business_id=? AND c.id IS NULL AND $saleWindow",[$biz,$from,$to]);
    $payWindow=$filter('p.created_at');
    $creditPaid=$sum("SELECT COALESCE(SUM(p.amount_clp),0) FROM credit_payments p WHERE p.business_id=? AND p.advance_quote_id IS NULL AND $payWindow",[$biz,$from,$to]);
    $advanceWindow=$filter('m.created_at');
    $advanceReceived=$sum("SELECT COALESCE(SUM(m.amount_clp),0) FROM quote_advance_movements m WHERE m.business_id=? AND m.kind='receipt' AND $advanceWindow",[$biz,$from,$to]);
    $advanceRefund=$sum("SELECT COALESCE(SUM(m.amount_clp),0) FROM quote_advance_movements m WHERE m.business_id=? AND m.kind='refund' AND $advanceWindow",[$biz,$from,$to]);
    $creditRefundWindow=$filter('r.created_at');
    $creditRefund=$sum("SELECT COALESCE(SUM(r.amount_clp),0) FROM credit_refunds r WHERE r.business_id=? AND $creditRefundWindow",[$biz,$from,$to]);
    // DATETIME UTC, no TIMESTAMP: se compara con límites UTC textuales.
    $utc=new DateTimeZone('UTC');
    $startUTC=$start->setTimezone($utc)->format('Y-m-d H:i:s');
    $endUTC=$end->setTimezone($utc)->format('Y-m-d H:i:s');
    $immediateRefund=$sum("SELECT COALESCE(SUM(r.amount_clp),0) FROM customer_returns r WHERE r.business_id=? AND r.refund_status='confirmed' AND r.resolution='refund' AND r.refunded_at>=? AND r.refunded_at<?",[$biz,$startUTC,$endUTC]);
    // El saldo por cobrar y el excedente de crédito son fotografías de hoy.
    // Se conservan las mismas fórmulas previamente probadas.
    $all=titoCreditFinancialSummary($db,$biz);
    return [
        'scope'=>$period,
        'window_start_local'=>$start->format(DateTimeInterface::ATOM),
        'window_end_local'=>$end->format(DateTimeInterface::ATOM),
        'gross_active_sales_clp'=>$gross,
        'voided_sales_clp'=>$void,
        'accepted_returns_clp'=>$immediateReturns+$creditReturns,
        'net_sales_clp'=>max(0,$gross-$immediateReturns-$creditReturns),
        'immediate_declared_clp'=>$immediate,
        'credit_payments_clp'=>$creditPaid,
        'advances_received_clp'=>$advanceReceived,'advances_refunded_clp'=>$advanceRefund,'advances_available_clp'=>$all['advances_available_clp'],
        'refunds_confirmed_clp'=>$immediateRefund+$creditRefund+$advanceRefund,
        'net_collections_declared_clp'=>$immediate+$creditPaid+$advanceReceived-$immediateRefund-$creditRefund-$advanceRefund,
        'receivables_clp'=>$all['receivables_clp'],
        'credit_refund_due_clp'=>$all['credit_refund_due_clp']
    ];
}

function registerCreditOperationsRoutes(RouteCollectorProxy $group): void {
    $group->get('/credit/adjustments',static function(Request $request,Response $response):Response{
        $u=adminOnly($request);$biz=(int)$u['business_id'];
        $st=db()->prepare('SELECT a.id,a.account_id,a.kind,a.return_kind,a.amount_clp,a.reason,a.created_at,c.sale_id,h.name AS client_name FROM credit_adjustments a JOIN credit_accounts c ON c.id=a.account_id AND c.business_id=a.business_id JOIN habitual_clients h ON h.id=c.client_id AND h.business_id=c.business_id WHERE a.business_id=? ORDER BY a.id DESC LIMIT 100');
        $st->execute([$biz]);
        return respond($response,['adjustments'=>$st->fetchAll()]);
    });
    $group->get('/reports/financial-summary',static function(Request $request,Response $response):Response{
        $u=adminOnly($request);
        $query=$request->getQueryParams();
        if(array_diff(array_keys($query),['period']))fail('Filtro financiero inválido.',422);
        $period=$query['period']??'all_time';
        if(!is_string($period)||!in_array($period,['today','week','month','all_time'],true))fail('Período financiero inválido.',422);
        $db=db();$biz=(int)$u['business_id'];
        $summary=$period==='all_time'
            ? titoCreditFinancialSummary($db,$biz)
            : titoCreditFinancialPeriod($db,$biz,$period);
        return respond($response,['summary'=>$summary]);
    });
    $group->get('/credit/accounts/{id:[0-9]+}/adjustments',static function(Request $request,Response $response,array $args):Response{
        $u=adminOnly($request);$biz=(int)$u['business_id'];$id=(int)$args['id'];$db=db();
        $st=$db->prepare('SELECT id,status,original_total_clp,sale_id FROM credit_accounts WHERE id=? AND business_id=?');$st->execute([$id,$biz]);$a=$st->fetch();$st->closeCursor();
        if(!$a)fail('Cuenta no encontrada.',404);
        $st=$db->prepare('SELECT a.id,a.kind,a.return_kind,a.amount_clp,a.reason,a.created_at,u.email AS created_by_email FROM credit_adjustments a JOIN users u ON u.id=a.created_by WHERE a.business_id=? AND a.account_id=? ORDER BY a.id DESC');
        $st->execute([$biz,$id]);$adj=$st->fetchAll();$st->closeCursor();
        $st=$db->prepare('SELECT id,amount_clp,method,reference,created_at FROM credit_refunds WHERE business_id=? AND account_id=? ORDER BY id DESC');
        $st->execute([$biz,$id]);$refunds=$st->fetchAll();$st->closeCursor();
        $st=$db->prepare('SELECT si.id AS sale_item_id,si.product_name_snapshot,si.quantity,si.unit_price_clp,si.subtotal_clp,p.sale_unit,COALESCE(ret.returned_qty,0) AS returned_qty FROM sale_items si JOIN credit_accounts ca ON ca.sale_id=si.sale_id AND ca.business_id=? JOIN products p ON p.id=si.product_id LEFT JOIN (SELECT ai.sale_item_id,SUM(ai.quantity) returned_qty FROM credit_adjustment_items ai GROUP BY ai.sale_item_id) ret ON ret.sale_item_id=si.id WHERE ca.id=? ORDER BY si.id');
        $st->execute([$biz,$id]);$lines=$st->fetchAll();$st->closeCursor();
        foreach($lines as &$line){$line['serial_units']=serialSaleLineUnits($db,$biz,(int)$a['sale_id'],(int)$line['sale_item_id']);}unset($line);
        return respond($response,['adjustments'=>$adj,'refunds'=>$refunds,'items'=>$lines,'account_status'=>$a['status']]);
    });
    $group->post('/credit/accounts/{id:[0-9]+}/returns',static function(Request $request,Response $response,array $args):Response{
        $u=adminOnly($request);$biz=(int)$u['business_id'];$uid=(int)$u['id'];$id=(int)$args['id'];
        $b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','return_kind','reason','inspection_confirmed','items','serial_items']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);
        $kind=$b['return_kind']??null;
        if(!in_array($kind,['commercial','warranty'],true))fail('Tipo inválido.',422);
        if(($b['inspection_confirmed']??null)!==true)fail('Confirma recepción e inspección de la mercadería.',422);
        $reason=titoCreditString($b,'reason',250,true);
        if(mb_strlen($reason)<5)fail('Describe el motivo (al menos cinco caracteres).',422);
        $wanted=titoCreditAdjustmentItems($b);
        $series=titoCreditSerialSpecs($b['serial_items']??[]);$canonical=[$id,$rid,'return',$kind,$reason,$wanted];if($series)$canonical[]=$series;
        $hash=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();
        try{
            $db->beginTransaction();originLockCompany($db,$biz);
            $a=titoCreditAccountLock($db,$biz,$id);
            $st=$db->prepare('SELECT id,kind,payload_hash,amount_clp FROM credit_adjustments WHERE business_id=? AND request_id=?');
            $st->execute([$biz,$rid]);$prior=$st->fetch();$st->closeCursor();
            if($prior){
                if(!hash_equals((string)$prior['payload_hash'],$hash))fail('Identificador reutilizado con distinta devolución.',409);
                $tot=titoCreditTotals($db,$biz,$id,(int)$a['original_total_clp']);
                $db->commit();
                return respond($response,['ok'=>true,'adjustment_id'=>(int)$prior['id'],'amount_clp'=>(int)$prior['amount_clp'],'already_recorded'=>true]+$tot);
            }
            if($a['status']!=='active'||$a['sale_status']!=='completed')fail('Esta cuenta ya no permite devoluciones.',409);
            [$lines,$amount]=titoCreditAdjustmentLines($db,$biz,(int)$a['sale_id'],$wanted,$kind,$series);
            if($amount<=0)fail('Una devolución a crédito debe tener importe positivo. Solicita revisión manual de líneas gratuitas.',422);
            $tot=titoCreditTotals($db,$biz,$id,(int)$a['original_total_clp']);
            if($amount>$tot['net_total_clp'])fail('La devolución excede el valor neto de la venta.',409);
            $st=$db->prepare("INSERT INTO credit_adjustments(business_id,account_id,created_by,request_id,payload_hash,kind,return_kind,reason,amount_clp) VALUES(?,?,?,?,?,'return',?,?,?)");
            $st->execute([$biz,$id,$uid,$rid,$hash,$kind,$reason,$amount]);
            $adjustmentId=(int)$db->lastInsertId();
            titoCreditRestore($db,$biz,$uid,(int)$a['sale_id'],$adjustmentId,$lines,$kind==='commercial',$kind,$reason);
            $tot=titoCreditTotals($db,$biz,$id,(int)$a['original_total_clp']);
            $db->commit();
            return respond($response,['ok'=>true,'adjustment_id'=>$adjustmentId,'amount_clp'=>$amount,'restocked'=>$kind==='commercial']+$tot,201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/credit/accounts/{id:[0-9]+}/void',static function(Request $request,Response $response,array $args):Response{
        $u=adminOnly($request);$biz=(int)$u['business_id'];$uid=(int)$u['id'];$id=(int)$args['id'];
        $b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','reason','inspection_confirmed','serial_items']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);
        if(($b['inspection_confirmed']??null)!==true)fail('Confirma que toda la mercadería fue recuperada y está apta para reventa.',422);
        $reason=titoCreditString($b,'reason',250,true);
        if(mb_strlen($reason)<5)fail('Indica un motivo de al menos cinco caracteres.',422);
        $series=titoCreditSerialSpecs($b['serial_items']??[]);$canonical=[$id,$rid,'void',$reason];if($series)$canonical[]=$series;
        $hash=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();
        try{
            $db->beginTransaction();originLockCompany($db,$biz);
            $a=titoCreditAccountLock($db,$biz,$id);
            $st=$db->prepare('SELECT id,payload_hash FROM credit_adjustments WHERE business_id=? AND request_id=?');$st->execute([$biz,$rid]);$prior=$st->fetch();$st->closeCursor();
            if($prior){
                if(!hash_equals((string)$prior['payload_hash'],$hash))fail('Identificador reutilizado con contenido distinto.',409);
                $tot=titoCreditTotals($db,$biz,$id,(int)$a['original_total_clp']);
                $db->commit();return respond($response,['ok'=>true,'adjustment_id'=>(int)$prior['id'],'already_recorded'=>true]+$tot);
            }
            if($a['status']!=='active'||$a['sale_status']!=='completed')fail('La venta ya fue anulada.',409);
            $st=$db->prepare('SELECT COUNT(*) FROM credit_adjustments WHERE business_id=? AND account_id=?');
            $st->execute([$biz,$id]);
            if((int)$st->fetchColumn()>0)fail('La venta tiene devoluciones parciales; regularízalas antes de anularla completa.',409);
            $st=$db->prepare('SELECT COUNT(*) FROM customer_returns WHERE business_id=? AND sale_id=?');
            $st->execute([$biz,(int)$a['sale_id']]);
            if((int)$st->fetchColumn()>0)fail('La venta ya tiene devoluciones ordinarias; no se permite anulación total.',409);
            $st=$db->prepare('SELECT id,quantity FROM sale_items WHERE sale_id=? ORDER BY id');
            $st->execute([(int)$a['sale_id']]);$rows=$st->fetchAll();
            $wanted=[];foreach($rows as $row)$wanted[(int)$row['id']]=number_format((float)$row['quantity'],3,'.','');
            [$lines,$amount]=titoCreditAdjustmentLines($db,$biz,(int)$a['sale_id'],$wanted,'commercial',$series);
            if($amount!==(int)$a['original_total_clp'])fail('Diferencia entre el total vendido y los productos a reponer.',409);
            $st=$db->prepare("INSERT INTO credit_adjustments(business_id,account_id,created_by,request_id,payload_hash,kind,return_kind,reason,amount_clp) VALUES(?,?,?,?,?,'void','commercial',?,?)");
            $st->execute([$biz,$id,$uid,$rid,$hash,$reason,$amount]);$adjustmentId=(int)$db->lastInsertId();
            titoCreditRestore($db,$biz,$uid,(int)$a['sale_id'],$adjustmentId,$lines,true,'void',$reason);
            $st=$db->prepare("UPDATE credit_accounts SET status='voided' WHERE id=? AND business_id=? AND status='active'");
            $st->execute([$id,$biz]);if($st->rowCount()!==1)throw new RuntimeException('No se actualizó el estado de la cuenta.');
            // El trigger solo permite anular crédito si la cuenta ya fue
            // regularizada dentro de ESTA MISMA transacción.
            $st=$db->prepare("UPDATE sales SET status='voided' WHERE id=? AND business_id=? AND status='completed'");
            $st->execute([(int)$a['sale_id'],$biz]);if($st->rowCount()!==1)throw new RuntimeException('No se anuló la venta.');
            $tot=titoCreditTotals($db,$biz,$id,(int)$a['original_total_clp']);
            $db->commit();
            return respond($response,['ok'=>true,'adjustment_id'=>$adjustmentId,'sale_id'=>(int)$a['sale_id'],'voided'=>true]+$tot,201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/credit/accounts/{id:[0-9]+}/refunds',static function(Request $request,Response $response,array $args):Response{
        $u=adminOnly($request);$biz=(int)$u['business_id'];$uid=(int)$u['id'];$id=(int)$args['id'];
        $b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','amount_clp','method','reference']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);
        $amount=titoCreditUnsigned($b['amount_clp']??null,'reembolso',1);
        $method=$b['method']??null;
        if(!in_array($method,['efectivo','transferencia','tarjeta','otro'],true))fail('Medio inválido.',422);
        $ref=titoCreditString($b,'reference',120);
        $hash=hash('sha256',json_encode([$id,$amount,$method,$ref],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();
        try{
            $db->beginTransaction();originLockCompany($db,$biz);
            $a=titoCreditAccountLock($db,$biz,$id);
            $st=$db->prepare('SELECT id,payload_hash FROM credit_refunds WHERE business_id=? AND request_id=?');
            $st->execute([$biz,$rid]);$prior=$st->fetch();$st->closeCursor();
            if($prior){
                if(!hash_equals((string)$prior['payload_hash'],$hash))fail('Identificador de reembolso reutilizado con importe diferente.',409);
                $tot=titoCreditTotals($db,$biz,$id,(int)$a['original_total_clp']);
                $db->commit();return respond($response,['ok'=>true,'refund_id'=>(int)$prior['id'],'already_recorded'=>true]+$tot);
            }
            $tot=titoCreditTotals($db,$biz,$id,(int)$a['original_total_clp']);
            if($amount>$tot['refund_due_clp'])fail('El reembolso excede el saldo a favor del cliente.',409);
            $cashSessionId=titoCashSessionForReceipt($db,$u,$request);
            $st=$db->prepare('INSERT INTO credit_refunds(business_id,account_id,created_by,request_id,payload_hash,amount_clp,method,reference,cash_session_id) VALUES(?,?,?,?,?,?,?,?,?)');
            $st->execute([$biz,$id,$uid,$rid,$hash,$amount,$method,$ref,$cashSessionId]);
            $refundId=(int)$db->lastInsertId();
            if($method==='efectivo')
                titoCashRegisterExternal($db,$biz,$uid,$cashSessionId,'credit_refund',$refundId,$amount);
            $tot=titoCreditTotals($db,$biz,$id,(int)$a['original_total_clp']);
            $db->commit();
            return respond($response,['ok'=>true,'refund_id'=>$refundId,'amount_clp'=>$amount]+$tot,201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}

