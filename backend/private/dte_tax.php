<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function dteTaxDay(mixed $v):string{
    $day=titoCreditDate($v);
    if(!$day||$day<'2000-01-01'||$day>quoteToday())fail('Fecha de vigencia inválida.',422);
    return $day;
}
function dteTaxPolicy(PDO $db,int $biz,int $pid,string $day):?array{
    return dteOne($db,'SELECT id,product_id,treatment,effective_on,evidence_reference FROM product_tax_versions WHERE business_id=? AND product_id=? AND effective_on<=? ORDER BY effective_on DESC,id DESC LIMIT 1',[$biz,$pid,$day]);
}
// Positive integer arithmetic, CLP, half-up rounding. No per-line fiscal unit prices yet.
function dteTaxRound(int $num,int $den):int{
    if($num<0||$den<1)throw new RuntimeException('Monto tributario inválido.');
    return intdiv($num+intdiv($den,2),$den);
}
function dteTaxCalculate(array $lines,string $basis,int $type,int $commercial):array{
    $issues=[];$taxable=0;$exempt=0;$unknown=0;$taxableLines=0;$freeLines=0;$unknownLines=0;
    if($commercial<0||$commercial>1000000000000)fail('Total fuera del rango tributario del piloto.',422);
    foreach($lines as $l){
        $amount=(int)$l['commercial_clp'];if($amount<0||$amount>1000000000000)fail('Monto de línea fuera de rango.',422);
        if($l['treatment']==='vat19'){$taxable+=$amount;$taxableLines++;}
        elseif(in_array($l['treatment'],['exempt','non_taxable'],true)){$exempt+=$amount;$freeLines++;}
        else{$unknown+=$amount;$unknownLines++;}
    }
    if($taxable+$exempt+$unknown!==$commercial)fail('Detalle comercial no concilia.',409);
    if($unknownLines)$issues[]='Hay productos sin clasificación tributaria vigente para la fecha de venta.';
    if(!in_array($basis,['gross','net'],true))$issues[]='La base de precios del borrador no está confirmada.';
    if(!$unknownLines){
        if(in_array($type,[34,41],true)&&$taxableLines)$issues[]='Documento exento no admite líneas afectas; revisa el tipo.';
        if(in_array($type,[33,39],true)&&!$taxableLines)$issues[]='Operación totalmente exenta/no afecta: revisa el tipo 34 o 41.';
    }
    $net=$vat=$total=$difference=null;
    if(!$unknownLines&&in_array($basis,['gross','net'],true)){
        $net=$basis==='gross'?dteTaxRound($taxable*100,119):$taxable;
        $vat=$basis==='gross'?$taxable-$net:dteTaxRound($net*19,100);
        $total=$net+$vat+$exempt;$difference=$total-$commercial;
        if($vat!==dteTaxRound($net*19,100))$issues[]='El redondeo del precio bruto no reproduce exactamente el IVA sobre el neto. Conciliar detalle antes de emitir.';
        if($difference!==0)$issues[]='El total tributario difiere de la venta registrada. No se modifica el cobro ni se agrega IVA automáticamente.';
        if($taxableLines&&$net<=0)$issues[]='Monto afecto sin base neta positiva; requiere revisión.';
        if($total<=0)$issues[]='Total no positivo; requiere tratamiento específico.';
    }
    return ['rate_percent'=>19,'basis'=>$basis,'commercial_total_clp'=>$commercial,'taxable_commercial_clp'=>$taxable,'exempt_clp'=>$exempt,'unknown_clp'=>$unknown,'net_clp'=>$net,'vat_clp'=>$vat,'total_clp'=>$total,'difference_clp'=>$difference,'issues'=>$issues,'status'=>$issues?'observed':'reconciled','method'=>'aggregate_clp_half_up_v1'];
}
function dteTaxReviewData(PDO $db,int $biz,int $doc):array{
    $r=dteOne($db,'SELECT id,sale_id,config_id,document_type,status,snapshot_json FROM dte_documents WHERE business_id=? AND id=? FOR UPDATE',[$biz,$doc]);if(!$r)fail('Borrador no encontrado.',404);
    if($r['status']!=='draft')fail('El borrador no está activo.',409);
    $snapshot=json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
    $config=dteConfig($db,$biz);if(!$config||(int)$config['id']!==(int)$r['config_id'])fail('La configuración tributaria cambió. Descarta y prepara un nuevo borrador.',409);
    $profile=dteProfile($db,$biz);if(titoCommercialRut($profile['tax_id']??null)!==$config['issuer']['rut']||($profile['legal_name']??null)!==$config['issuer']['legal_name']||($profile['address']??null)!==$config['issuer']['address']||($profile['city']??null)!==$config['issuer']['city'])fail('La ficha del emisor cambió. Actualiza la configuración y el borrador.',409);
    $current=dteSaleSnapshot($db,$biz,(int)$r['sale_id'],(int)$r['document_type'],$config);
    $fingerprint=static function(array $s):array{
        return [$s['sale_id'],$s['sale_date'],$s['issuer'],$s['recipient'],$s['payment'],$s['commercial_total_clp'],$s['price_basis'],array_map(static fn($l)=>[(int)$l['sale_item_id'],(int)$l['product_id'],(string)$l['quantity'],(int)$l['unit_price_clp'],(int)$l['subtotal_clp']],$s['lines'])];
    };
    if($fingerprint($current)!==$fingerprint($snapshot))fail('Los datos de la venta o receptor cambiaron. Prepara un nuevo borrador.',409);
    $day=(new DateTimeImmutable($snapshot['sale_date'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Santiago'))->format('Y-m-d');
    $lines=[];foreach($snapshot['lines'] as $l){
        $policy=dteTaxPolicy($db,$biz,(int)$l['product_id'],$day);
        $lines[]=['sale_item_id'=>(int)$l['sale_item_id'],'product_id'=>(int)$l['product_id'],'product_name'=>$l['product_name'],'commercial_clp'=>(int)$l['subtotal_clp'],'treatment'=>$policy['treatment']??'unknown','policy_id'=>$policy?(int)$policy['id']:null,'effective_on'=>$policy['effective_on']??null,'evidence_reference'=>$policy['evidence_reference']??null];
    }
    $calc=dteTaxCalculate($lines,$snapshot['price_basis'],(int)$r['document_type'],(int)$snapshot['commercial_total_clp']);
    $last=dteOne($db,'SELECT id FROM dte_tax_reviews WHERE business_id=? AND document_id=? ORDER BY id DESC LIMIT 1',[$biz,$doc]);
    $data=['document_id'=>$doc,'document_type'=>(int)$r['document_type'],'sale_id'=>(int)$r['sale_id'],'sale_on'=>$day,'config_id'=>(int)$r['config_id'],'lines'=>$lines,'calculation'=>$calc,'expected_review_id'=>$last?(int)$last['id']:null];
    $data['preview_hash']=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));return $data;
}
function registerDteTaxRoutes(RouteCollectorProxy $group):void{
    $group->get('/dte/tax/products',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$p=$request->getQueryParams();$q=$p['q']??'';$page=filter_var($p['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if($page===false||!is_string($q)||mb_strlen($q)>120)fail('Filtro inválido.',422);
        $db=db();$s=$db->prepare("SELECT id,name,sku,price_clp FROM products WHERE business_id=? AND is_active=1 AND (LOCATE(?,name)>0 OR LOCATE(?,COALESCE(sku,''))>0 OR ?='') ORDER BY name,id LIMIT 26 OFFSET ".(($page-1)*25));$s->execute([$biz,trim($q),trim($q),trim($q)]);$rows=$s->fetchAll();$s->closeCursor();$more=count($rows)>25;$rows=array_slice($rows,0,25);$day=quoteToday();
        foreach($rows as &$r){$r['policy']=dteTaxPolicy($db,$biz,(int)$r['id'],$day);$latest=dteOne($db,'SELECT id FROM product_tax_versions WHERE business_id=? AND product_id=? ORDER BY id DESC LIMIT 1',[$biz,(int)$r['id']]);$r['latest_version_id']=$latest?(int)$latest['id']:null;}unset($r);
        return respond($response,['rows'=>$rows,'more'=>$more,'today'=>$day]);
    });
    $group->post('/dte/tax/products',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','products','treatment','effective_on','evidence_reference']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);$items=$b['products']??null;$t=$b['treatment']??null;$day=dteTaxDay($b['effective_on']??null);$ref=dteText($b,'evidence_reference',250);
        if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>100||!in_array($t,['unknown','vat19','exempt','non_taxable'],true))fail('Selección o clasificación inválida.',422);
        $map=[];foreach($items as $i){
            if(!is_array($i)||array_diff(array_keys($i),['product_id','expected_version_id']))fail('Producto inválido.',422);
            $pid=originId($i['product_id']??null,'product_id');$expected=originId($i['expected_version_id']??null,'expected_version_id');if(!$pid||array_key_exists($pid,$map))fail('Producto repetido o inválido.',422);$map[$pid]=$expected;
        }ksort($map,SORT_NUMERIC);
        $hash=hash('sha256',json_encode([$uid,$map,$t,$day,$ref],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $s=$db->prepare('SELECT product_id,payload_hash FROM product_tax_versions WHERE business_id=? AND request_id=?');$s->execute([$biz,$rid]);$old=$s->fetchAll();$s->closeCursor();
            if($old){if(count($old)!==count($map))fail('Solicitud reutilizada.',409);foreach($old as $o)if(!array_key_exists((int)$o['product_id'],$map)||!hash_equals($o['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'count'=>count($old),'already_recorded'=>true]);}
            $s=$db->prepare('INSERT INTO product_tax_versions(business_id,product_id,treatment,effective_on,evidence_reference,actor_id,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?)');
            foreach($map as $pid=>$expected){
                if(!dteOne($db,'SELECT id FROM products WHERE business_id=? AND id=? AND is_active=1 FOR UPDATE',[$biz,$pid]))fail('Producto no disponible.',404);
                $latest=dteOne($db,'SELECT id FROM product_tax_versions WHERE business_id=? AND product_id=? ORDER BY id DESC LIMIT 1',[$biz,$pid]);if(($latest?(int)$latest['id']:null)!==$expected)fail('Clasificación modificada. Actualiza la lista.',409);
                $s->execute([$biz,$pid,$t,$day,$ref,$uid,$rid,$hash]);
            }$db->commit();return respond($response,['ok'=>true,'count'=>count($map)],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->get('/dte/documents/{id:[0-9]+}/tax',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$id=(int)$args['id'];$db=db();$r=dteOne($db,'SELECT id FROM dte_documents WHERE business_id=? AND id=?',[$biz,$id]);if(!$r)fail('Borrador no encontrado.',404);
        $s=$db->prepare('SELECT id,result_status,result_json,evidence_reference,created_at FROM dte_tax_reviews WHERE business_id=? AND document_id=? ORDER BY id DESC LIMIT 20');$s->execute([$biz,$id]);$rows=$s->fetchAll();$s->closeCursor();foreach($rows as &$r){$r['result']=json_decode($r['result_json'],true,512,JSON_THROW_ON_ERROR);unset($r['result_json']);}unset($r);
        return respond($response,['reviews'=>$rows]);
    });
    $group->post('/dte/documents/{id:[0-9]+}/tax/preview',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$db=db();$db->beginTransaction();try{originLockCompany($db,$biz);$r=dteTaxReviewData($db,$biz,(int)$args['id']);$db->commit();return respond($response,$r);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/dte/documents/{id:[0-9]+}/tax/review',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$id=(int)$args['id'];$b=requestBody($request);
        if(array_diff(array_keys($b),['request_id','preview_hash','evidence_reference','confirmed']))fail('Campos no permitidos.',422);
        $rid=titoCreditUuid($b['request_id']??null);$preview=$b['preview_hash']??null;$ref=dteText($b,'evidence_reference',250);
        if(!is_string($preview)||!preg_match('/^[a-f0-9]{64}$/D',$preview)||($b['confirmed']??false)!==true)fail('Revisión y confirmación obligatorias.',422);
        $hash=hash('sha256',json_encode([$uid,$id,$preview,$ref],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$db=db();$db->beginTransaction();try{originLockCompany($db,$biz);
            $old=dteOne($db,'SELECT id,payload_hash FROM dte_tax_reviews WHERE business_id=? AND request_id=?',[$biz,$rid]);if($old){if(!hash_equals($old['payload_hash'],$hash))fail('Solicitud reutilizada.',409);$db->commit();return respond($response,['ok'=>true,'review_id'=>(int)$old['id'],'already_recorded'=>true]);}
            $data=dteTaxReviewData($db,$biz,$id);if(!hash_equals($data['preview_hash'],$preview))fail('Cambió la revisión; calcula nuevamente.',409);
            $s=$db->prepare('INSERT INTO dte_tax_reviews(business_id,document_id,actor_id,result_status,result_json,evidence_reference,request_id,payload_hash) VALUES(?,?,?,?,?,?,?,?)');$s->execute([$biz,$id,$uid,$data['calculation']['status'],json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$ref,$rid,$hash]);$review=(int)$db->lastInsertId();$db->commit();return respond($response,['ok'=>true,'review_id'=>$review],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
