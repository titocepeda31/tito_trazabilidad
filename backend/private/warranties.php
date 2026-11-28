<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function warrantyEnd(?string $start,?int $n,?string $unit): ?string {
    if(!$start||!$n||!$unit)return null;
    $d=new DateTimeImmutable($start,new DateTimeZone('America/Santiago'));
    if($unit==='days')return $d->modify('+'.$n.' days')->format('Y-m-d');
    $months=$n*($unit==='years'?12:1);$day=(int)$d->format('j');
    $target=$d->modify('first day of this month')->modify('+'.$months.' months');
    return $target->setDate((int)$target->format('Y'),(int)$target->format('n'),min($day,(int)$target->format('t')))->format('Y-m-d');
}
function warrantyText(array $b,string $key,int $max): string {
    $v=$b[$key]??null;if(!is_string($v)||trim($v)===''||mb_strlen(trim($v))>$max)fail('Campo inválido: '.$key,422);return trim($v);
}
function warrantyPolicy(PDO $db,int $biz,int $product,string $kind): ?array {
    $s=$db->prepare('SELECT * FROM warranty_policies WHERE business_id=? AND kind=? AND (product_id=? OR product_id IS NULL) ORDER BY (product_id IS NOT NULL) DESC,id DESC LIMIT 1');
    $s->execute([$biz,$kind,$product]);$p=$s->fetch();$s->closeCursor();return $p?:null;
}
// Snapshot at effective sale; no retroactive inference for older sales.
function warrantyRecordSale(PDO $db,int $biz,int $sale,?int $client,bool $historical=false): void {
    if(!$db->inTransaction())throw new RuntimeException('Garantías fuera de transacción.');
    $s=$db->prepare('SELECT si.id,si.product_id,u.unit_id,m.name AS manufacturer_name,s.created_at,w.id AS legacy_id,w.manufacturer_name AS legacy_manufacturer,w.starts_on AS legacy_start,w.ends_on AS legacy_end,w.terms AS legacy_terms FROM sale_items si JOIN sales s ON s.id=si.sale_id AND s.business_id=? LEFT JOIN serial_sale_units u ON u.sale_item_id=si.id AND u.sale_id=s.id AND u.business_id=s.business_id LEFT JOIN unit_warranties w ON w.unit_id=u.unit_id AND w.sale_id=s.id AND w.business_id=s.business_id LEFT JOIN product_origins po ON po.product_id=si.product_id AND po.business_id=s.business_id LEFT JOIN manufacturers m ON m.id=po.manufacturer_id AND m.business_id=s.business_id WHERE s.id=? ORDER BY si.id,u.unit_id');
    $s->execute([$biz,$sale]);$lines=$s->fetchAll();$s->closeCursor();
    $ins=$db->prepare('INSERT INTO sale_warranty_coverages(business_id,sale_id,sale_item_id,unit_id,unit_key,client_id,kind,policy_id,offered,duration_value,duration_unit,start_event,starts_on,ends_on,terms,manufacturer_name,activation_status,legacy_warranty_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $cache=[];
    foreach($lines as $l){$pid=(int)$l['product_id'];$unit=$l['unit_id']===null?null:(int)$l['unit_id'];
        foreach(['legal','satisfaction','manufacturer'] as $kind){
            $p=null;if($kind!=='legal'&&!$historical){if(!array_key_exists($kind.':'.$pid,$cache))$cache[$kind.':'.$pid]=warrantyPolicy($db,$biz,$pid,$kind);$p=$cache[$kind.':'.$pid];}
            $offered=$kind==='legal'?null:($p?(int)$p['offered']:null);
            $n=$kind==='legal'?6:($p&&$offered?(int)$p['duration_value']:null);
            $u=$kind==='legal'?'months':($p&&$offered?$p['duration_unit']:null);
            $base=$kind==='legal'?'receipt':($p?$p['start_event']:($kind==='manufacturer'?'sale':'receipt'));
            $start=$base==='sale'&&$offered===1?quoteToday():null;
            $terms=$kind==='legal'?'Chile: referencia general de seis meses desde recepción. Revisar aplicabilidad, plazo mayor, suspensión y derechos antes de decidir.':($p?$p['terms']:'Sin condiciones documentadas; revisar.');
            $activation=$kind==='manufacturer'?($p&&$offered?((int)$p['requires_notification']?'pending':'not_required'):'unknown'):'not_required';
            $legacy=null;$manufacturer=$l['manufacturer_name'];$end=warrantyEnd($start,$n,$u);
            if($historical){
                $manufacturer=null; // current catalog is not historical evidence
                if($kind==='manufacturer'&&$l['legacy_id']){
                    $legacy=(int)$l['legacy_id'];$offered=1;$manufacturer=$l['legacy_manufacturer'];
                    $start=$l['legacy_start'];$end=$l['legacy_end'];$terms=$l['legacy_terms'];$activation='unknown';
                }
            }
            $ins->execute([$biz,$sale,$l['id'],$unit,$unit??0,$client,$kind,$p['id']??null,$offered,$n,$u,$base,$start,$end,$terms,$manufacturer,$activation,$legacy]);
        }
    }
}
// Periods apply to legal/manufacturer coverages of the same historical line/unit.
function warrantySuspensions(PDO $db,int $biz,array $c): array {
    $s=$db->prepare("SELECT p.id,p.coverage_id,p.starts_on,p.resumed_on,p.created_by,p.resumed_by,p.created_at FROM warranty_suspensions p JOIN sale_warranty_coverages a ON a.id=p.coverage_id AND a.business_id=p.business_id WHERE p.business_id=? AND a.sale_item_id=? AND a.unit_key=? ORDER BY p.starts_on,p.id");
    $s->execute([$biz,$c['sale_item_id'],$c['unit_key']]);$rows=$s->fetchAll();$s->closeCursor();return $rows;
}
// Pure calendar calculation: half-open periods [start,resume), merge overlaps.
// Open periods are provisional through today. Periods outside the estimated
// coverage are shown in history but are not automatically counted.
function warrantyAdjusted(array $c,array $periods,string $today): array {
    $original=$c['ends_on']??null;$base=$original;
    if($c['kind']==='legal'&&$base&&($c['legal_review_until']??null)>$base)$base=$c['legal_review_until'];
    $result=['reviewed_end_on'=>$base,'adjusted_end_on'=>$base,'suspended_days'=>0,'suspension_open'=>false,'review_required'=>false];
    if(!in_array($c['kind'],['legal','manufacturer'],true))return $result;
    foreach($periods as $p)if($p['resumed_on']===null)$result['suspension_open']=true;
    if(!$base||!$c['starts_on']){$result['review_required']=true;return $result;}
    $intervals=[];
    foreach($periods as $p){
        $start=max($c['starts_on'],$p['starts_on']);$end=$p['resumed_on']??$today;
        if($end<=$start)continue;$intervals[]=[$start,$end];
    }
    usort($intervals,static fn($a,$b)=>strcmp($a[0],$b[0]));
    $merged=[];
    foreach($intervals as [$a,$b]){
        $last=count($merged)-1;
        if($last>=0&&$a<=$merged[$last][1])$merged[$last][1]=max($merged[$last][1],$b);
        else $merged[]=[$a,$b];
    }
    $tz=new DateTimeZone('UTC');$end=new DateTimeImmutable($base,$tz);
    foreach($merged as [$a,$b]){
        if($a>$end->format('Y-m-d')){$result['review_required']=true;continue;}
        $days=(int)(new DateTimeImmutable($a,$tz))->diff(new DateTimeImmutable($b,$tz))->days;
        $result['suspended_days']+=$days;$end=$end->modify('+'.$days.' days');
    }
    $result['adjusted_end_on']=$end->format('Y-m-d');return $result;
}
function warrantyEnrich(PDO $db,int $biz,array $c): array {
    $periods=warrantySuspensions($db,$biz,$c);
    return $c+warrantyAdjusted($c,$periods,quoteToday());
}
function warrantyViewSql(): string {
    return "SELECT c.*,s.status AS sale_status,s.created_at AS sold_at,si.product_id,si.product_name_snapshot AS product_name,si.quantity,x.serial_number,COALESCE(h.name,s.client_name_snapshot) AS client_name FROM sale_warranty_coverages c JOIN sales s ON s.id=c.sale_id AND s.business_id=c.business_id JOIN sale_items si ON si.id=c.sale_item_id AND si.sale_id=s.id LEFT JOIN serial_units x ON x.id=c.unit_id AND x.business_id=c.business_id LEFT JOIN habitual_clients h ON h.id=c.client_id AND h.business_id=c.business_id";
}
function warrantyForUnit(PDO $db,int $biz,int $sale,int $unit): array {
    $s=$db->prepare(warrantyViewSql().' WHERE c.business_id=? AND c.sale_id=? AND c.unit_id=? ORDER BY c.id');$s->execute([$biz,$sale,$unit]);$r=$s->fetchAll();$s->closeCursor();return array_map(static fn($c)=>warrantyEnrich($db,$biz,$c),$r);
}
function registerWarrantyRoutes(RouteCollectorProxy $group): void {
    $group->post('/warranties/historical-sales/{id:[0-9]+}',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$sale=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','note','evidence_reference']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);$note=warrantyText($b,'note',1000);$ref=warrantyText($b,'evidence_reference',250);
        $hash=hash('sha256',json_encode([$sale,(int)$a['id'],$note,$ref],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $s=$db->prepare('SELECT s.id,s.client_id,ss.client_id AS serial_client,ca.client_id AS credit_client FROM sales s LEFT JOIN serial_sale_snapshots ss ON ss.sale_id=s.id AND ss.business_id=s.business_id LEFT JOIN credit_accounts ca ON ca.sale_id=s.id AND ca.business_id=s.business_id WHERE s.business_id=? AND s.id=? FOR UPDATE');$s->execute([$biz,$sale]);$row=$s->fetch();$s->closeCursor();if(!$row)fail('Venta no encontrada.',404);
            $s=$db->prepare('SELECT e.payload_hash,c.sale_id FROM warranty_coverage_events e JOIN sale_warranty_coverages c ON c.id=e.coverage_id AND c.business_id=e.business_id WHERE e.business_id=? AND e.request_id=?');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();if($old){if((int)$old['sale_id']!==$sale||!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'already_recorded'=>true]);}
            $ids=array_values(array_unique(array_map('intval',array_filter([$row['client_id'],$row['serial_client'],$row['credit_client']],static fn($x)=>$x!==null))));
            if(count($ids)>1)fail('Identidad histórica de cliente inconsistente; revisar antes de importar.',409);
            $s=$db->prepare('SELECT id FROM sale_warranty_coverages WHERE business_id=? AND sale_id=? LIMIT 1');$s->execute([$biz,$sale]);$existing=$s->fetchColumn();$s->closeCursor();if($existing)fail('La venta ya tiene coberturas; no se duplicarán ni sobrescribirán.',409);
            warrantyRecordSale($db,$biz,$sale,$ids[0]??null,true);
            $s->execute([$biz,$sale]);$id=$s->fetchColumn();$s->closeCursor();if(!$id)fail('Venta sin líneas verificables.',409);
            $s=$db->prepare("INSERT INTO warranty_coverage_events(business_id,coverage_id,actor_id,kind,note,evidence_reference,event_on,request_id,payload_hash,details_json) VALUES(?,?,?,'note',?,?,?,?,?,?)");
            $s->execute([$biz,$id,(int)$a['id'],$note,$ref,quoteToday(),$rid,$hash,json_encode(['historical_sale_import'=>$sale],JSON_THROW_ON_ERROR)]);
            $db->commit();return respond($response,['ok'=>true,'sale_id'=>$sale],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });

    $group->get('/warranties/policies',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$db=db();
        $s=$db->prepare('SELECT w.*,p.name AS product_name FROM warranty_policies w LEFT JOIN products p ON p.id=w.product_id AND p.business_id=w.business_id WHERE w.business_id=? ORDER BY w.id DESC LIMIT 100');$s->execute([$biz]);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>$rows]);
    });
    $group->post('/warranties/policies',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','product_id','kind','offered','duration_value','duration_unit','start_event','requires_notification','terms','evidence_reference']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);$pid=originId($b['product_id']??null,'product_id');$kind=$b['kind']??null;$offered=$b['offered']??null;$base=$b['start_event']??null;$notify=$b['requires_notification']??null;
        if(!in_array($kind,['satisfaction','manufacturer'],true)||!is_bool($offered)||!is_bool($notify)||!in_array($base,['sale','receipt','installation'],true)||($kind==='manufacturer'&&!$pid)||($kind!=='manufacturer'&&$notify)||(!$offered&&$notify))fail('Política inválida.',422);
        $n=$offered?filter_var($b['duration_value']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>3650]]):null;$u=$offered?($b['duration_unit']??null):null;
        if($offered&&($n===false||!in_array($u,['days','months','years'],true)||($u==='years'&&$n>20)||($u==='months'&&$n>240)))fail('Duración inválida.',422);
        $terms=warrantyText($b,'terms',1000);$ref=warrantyText($b,'evidence_reference',250);$hash=hash('sha256',json_encode([$uid,$pid,$kind,$offered,$n,$u,$base,$notify,$terms,$ref],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $s=$db->prepare('SELECT id,payload_hash FROM warranty_policies WHERE business_id=? AND request_id=?');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();if($old){if(!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'policy_id'=>(int)$old['id']]);}
            if($pid){$s=$db->prepare('SELECT id FROM products WHERE business_id=? AND id=? AND is_active=1');$s->execute([$biz,$pid]);$ok=$s->fetchColumn();$s->closeCursor();if(!$ok)fail('Producto no disponible.',422);}
            $s=$db->prepare('INSERT INTO warranty_policies(business_id,product_id,kind,offered,duration_value,duration_unit,start_event,requires_notification,terms,evidence_reference,actor_id,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$biz,$pid,$kind,(int)$offered,$n,$u,$base,(int)$notify,$terms,$ref,$uid,$rid,$hash]);$id=(int)$db->lastInsertId();$db->commit();return respond($response,['ok'=>true,'policy_id'=>$id],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->get('/warranties/coverages',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);$filter=$p['filter']??'all';$q=$p['q']??'';
        if($page===false||!in_array($filter,['all','legal','satisfaction','manufacturer','pending'],true)||!is_string($q)||mb_strlen($q)>120)fail('Filtro inválido.',422);
        $where=' WHERE c.business_id=?';$params=[$biz];
        if($filter==='pending')$where.=" AND s.status='completed' AND c.kind='manufacturer' AND c.activation_status IN ('pending','reported','observed','unknown')";
        elseif($filter!=='all'){$where.=' AND c.kind=?';$params[]=$filter;}
        if(trim($q)!==''){$where.=' AND (LOCATE(?,COALESCE(x.serial_number,\'\'))>0 OR LOCATE(?,COALESCE(si.product_name_snapshot,\'\'))>0 OR CAST(c.sale_id AS CHAR)=?)';$params[]=mb_strtoupper(trim($q),'UTF-8');$params[]=trim($q);$params[]=trim($q);}
        $s=db()->prepare(warrantyViewSql().$where.' ORDER BY c.id DESC LIMIT 26 OFFSET '.(($page-1)*25));$s->execute($params);$rows=$s->fetchAll();$s->closeCursor();return respond($response,['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25]);
    });
    $group->get('/warranties/coverages/{id:[0-9]+}',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$id=(int)$args['id'];$db=db();$s=$db->prepare(warrantyViewSql().' WHERE c.business_id=? AND c.id=?');$s->execute([$biz,$id]);$c=$s->fetch();$s->closeCursor();if(!$c)fail('Cobertura no encontrada.',404);
        $s=$db->prepare('SELECT e.id,e.kind,e.note,e.evidence_reference,e.event_on,e.details_json,e.created_at,u.email AS actor_email FROM warranty_coverage_events e JOIN users u ON u.id=e.actor_id AND u.business_id=e.business_id WHERE e.business_id=? AND e.coverage_id=? ORDER BY e.id DESC');$s->execute([$biz,$id]);$events=$s->fetchAll();$s->closeCursor();return respond($response,['coverage'=>warrantyEnrich($db,$biz,$c),'events'=>$events,'periods'=>warrantySuspensions($db,$biz,$c),'today'=>quoteToday()]);
    });
    $group->post('/warranties/coverages/{id:[0-9]+}/events',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$id=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','revision','kind','event_on','note','evidence_reference','policy_id','review_until','suspension_id']))fail('Campos no permitidos.',422);
        [$rid,$rev,$hash]=quoteCommand($b);$hash=hash('sha256',$id.':'.$uid.':'.$hash);$kind=$b['kind']??null;$day=reservationDate($b['event_on']??null);$note=warrantyText($b,'note',1000);$ref=warrantyText($b,'evidence_reference',250);
        if(!in_array($kind,['base_date','reported','confirmed','observed','note','policy','legal_review','suspend','resume'],true)||$day>quoteToday())fail('Evento inválido.',422);
        $db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $s=$db->prepare('SELECT c.*,s.status AS sale_status,si.product_id FROM sale_warranty_coverages c JOIN sales s ON s.id=c.sale_id AND s.business_id=c.business_id JOIN sale_items si ON si.id=c.sale_item_id AND si.sale_id=s.id WHERE c.business_id=? AND c.id=? FOR UPDATE');$s->execute([$biz,$id]);$c=$s->fetch();$s->closeCursor();if(!$c)fail('Cobertura no encontrada.',404);
            $s=$db->prepare('SELECT coverage_id,payload_hash FROM warranty_coverage_events WHERE business_id=? AND request_id=?');$s->execute([$biz,$rid]);$old=$s->fetch();$s->closeCursor();if($old){if((int)$old['coverage_id']!==$id||!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true]);}
            if((int)$c['revision']!==$rev||$c['sale_status']!=='completed')fail('Cobertura modificada o venta anulada.',409);
            $details=null;
            if($kind==='legal_review'){
                $until=reservationDate($b['review_until']??null);
                if($c['kind']!=='legal'||!$c['ends_on']||$until<max($c['ends_on'],$c['legal_review_until']??$c['ends_on']))fail('La revisión no puede reducir el plazo original o revisado.',422);
                $s=$db->prepare('UPDATE sale_warranty_coverages SET legal_review_until=? WHERE id=? AND business_id=?');$s->execute([$until,$id,$biz]);
                $details=['previous'=>$c['legal_review_until'],'review_until'=>$until];
            }elseif($kind==='suspend'){
                if(!in_array($c['kind'],['legal','manufacturer'],true))fail('Registrar ejercicio en cobertura legal o fabricante.',422);
                // Explicit exercise may precede recorded receipt; retain evidence, not infer a new base date.
                $s=$db->prepare('INSERT INTO warranty_suspensions(business_id,coverage_id,starts_on,created_by) VALUES(?,?,?,?)');$s->execute([$biz,$id,$day,$uid]);$details=['suspension_id'=>(int)$db->lastInsertId(),'starts_on'=>$day];
            }elseif($kind==='resume'){
                if(!in_array($c['kind'],['legal','manufacturer'],true))fail('Cobertura inválida.',422);
                $pid=originId($b['suspension_id']??null,'suspension_id');
                $s=$db->prepare('SELECT p.id,p.starts_on,p.resumed_on FROM warranty_suspensions p JOIN sale_warranty_coverages a ON a.id=p.coverage_id AND a.business_id=p.business_id WHERE p.business_id=? AND p.id=? AND a.sale_item_id=? AND a.unit_key=? FOR UPDATE');$s->execute([$biz,$pid,$c['sale_item_id'],$c['unit_key']]);$p=$s->fetch();$s->closeCursor();if(!$p||$p['resumed_on']!==null||$day<$p['starts_on'])fail('Período inexistente, cerrado o fecha anterior al inicio.',409);
                $s=$db->prepare('UPDATE warranty_suspensions SET resumed_on=?,resumed_by=? WHERE business_id=? AND id=? AND resumed_on IS NULL');$s->execute([$day,$uid,$biz,$pid]);$details=['suspension_id'=>$pid,'resumed_on'=>$day];
            }elseif($kind==='policy'){
                if($c['kind']==='legal'||$c['policy_id']!==null||$c['legacy_warranty_id']!==null)fail('Solo se permite documentar condiciones antes desconocidas.',409);
                $pid=originId($b['policy_id']??null,'policy_id');$s=$db->prepare('SELECT * FROM warranty_policies WHERE business_id=? AND id=? AND kind=? AND (product_id=? OR product_id IS NULL)');$s->execute([$biz,$pid,$c['kind'],$c['product_id']]);$p=$s->fetch();$s->closeCursor();if(!$p)fail('Política no corresponde.',422);
                $start=(int)$p['offered']&&$p['start_event']==='sale'?$day:null;
                $act=$c['kind']==='manufacturer'?((int)$p['offered']?((int)$p['requires_notification']?'pending':'not_required'):'unknown'):'not_required';
                $s=$db->prepare('UPDATE sale_warranty_coverages SET policy_id=?,offered=?,duration_value=?,duration_unit=?,start_event=?,starts_on=?,ends_on=?,terms=?,activation_status=? WHERE id=? AND business_id=?');$s->execute([$p['id'],$p['offered'],$p['duration_value'],$p['duration_unit'],$p['start_event'],$start,warrantyEnd($start,$p['duration_value']===null?null:(int)$p['duration_value'],$p['duration_unit']),$p['terms'],$act,$id,$biz]);
            }elseif($kind==='base_date'){
                if($c['start_event']==='sale'||$c['starts_on']!==null||$c['duration_value']===null||($c['kind']!=='legal'&&(int)$c['offered']!==1))fail('No corresponde registrar otra fecha base.',409);
                $s=$db->prepare('UPDATE sale_warranty_coverages SET starts_on=?,ends_on=? WHERE business_id=? AND id=?');$s->execute([$day,warrantyEnd($day,(int)$c['duration_value'],$c['duration_unit']),$biz,$id]);
            }elseif($kind!=='note'){
                $allowed=['pending'=>['reported'],'reported'=>['confirmed','observed'],'observed'=>['reported']];
                if($c['kind']!=='manufacturer'||!in_array($kind,$allowed[$c['activation_status']]??[],true)||($c['starts_on']&&$day<$c['starts_on'])||($kind!=='reported'&&(!$c['notified_on']||$day<$c['notified_on'])))fail('Transición de activación inválida.',409);
                $s=$db->prepare("UPDATE sale_warranty_coverages SET activation_status=?,notified_on=CASE WHEN ?='reported' THEN ? ELSE notified_on END,confirmed_on=CASE WHEN ?='confirmed' THEN ? ELSE NULL END WHERE business_id=? AND id=?");$s->execute([$kind,$kind,$day,$kind,$day,$biz,$id]);
            }
            $s=$db->prepare('UPDATE sale_warranty_coverages SET revision=revision+1 WHERE business_id=? AND id=?');$s->execute([$biz,$id]);
            $s=$db->prepare('INSERT INTO warranty_coverage_events(business_id,coverage_id,actor_id,kind,note,evidence_reference,event_on,request_id,payload_hash,details_json) VALUES(?,?,?,?,?,?,?,?,?,?)');$s->execute([$biz,$id,$uid,$kind,$note,$ref,$day,$rid,$hash,$details===null?null:json_encode($details,JSON_THROW_ON_ERROR)]);$db->commit();return respond($response,['ok'=>true],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
