<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

function titoCommercialLatest(PDO $db,int $biz,int $pid):?array{
    return dteOne($db,'SELECT id,price_unit,source_amount,source_basis,tax_treatment,final_amount,reference_clp,uf_rate_id,reference_date FROM product_commercial_prices WHERE business_id=? AND product_id=? ORDER BY id DESC LIMIT 1',[$biz,$pid]);
}
function titoCommercialAllowed(PDO $db,int $biz,string $unit):void{
    $s=titoCurrencySettings($db,$biz);
    if(!in_array($unit,['CLP','UF'],true)||(!(int)$s['allow_both']&&$s['default_unit']!==$unit))fail('Unidad comercial no habilitada para la empresa.',422);
}
function titoCommercialPrepare(PDO $db,int $biz,mixed $raw,?int $pid=null):array{
    if(!is_array($raw)||array_is_list($raw))fail('Precio comercial inválido.',422);
    $p=titoDraftPrice($raw);titoCommercialAllowed($db,$biz,$p['price_unit']);
    if($pid!==null){
        $policy=dteTaxPolicy($db,$biz,$pid,quoteToday());
        if($policy&&$policy['treatment']!==$p['tax_treatment'])fail('El impuesto no coincide con la clasificación tributaria vigente del producto.',409);
    }
    $day=null;$rid=null;$ref=(int)$p['final_amount'];
    if($p['price_unit']==='UF'){
        $day=quoteToday();$rate=titoCurrencyRate($db,$biz,$day);
        if(!$rate)fail('Sin UF registrada para hoy. Sincroniza los valores antes de guardar.',409);
        $rid=(int)$rate['id'];$ref=titoDraftUFReference($p['final_amount'],(string)$rate['clp_per_uf']);
    }
    return $p+['reference_clp'=>$ref,'uf_rate_id'=>$rid,'reference_date'=>$day];
}
function titoCommercialRecord(PDO $db,int $biz,int $pid,int $uid,array $p):int{
    $st=$db->prepare('INSERT INTO product_commercial_prices(business_id,product_id,price_unit,source_amount,source_basis,tax_treatment,final_amount,reference_clp,uf_rate_id,reference_date,actor_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$biz,$pid,$p['price_unit'],$p['source_amount'],$p['source_basis'],$p['tax_treatment'],$p['final_amount'],$p['reference_clp'],$p['uf_rate_id'],$p['reference_date'],$uid]);
    return (int)$db->lastInsertId();
}
function titoCommercialAttach(PDO $db,int $biz,array $products):array{
    if(!$products)return [];
    $st=$db->prepare('SELECT p.id,p.product_id,p.price_unit,p.source_amount,p.source_basis,p.tax_treatment,p.final_amount,p.reference_clp,p.uf_rate_id,p.reference_date FROM product_commercial_prices p JOIN (SELECT product_id,MAX(id) id FROM product_commercial_prices WHERE business_id=? GROUP BY product_id) latest ON latest.id=p.id WHERE p.business_id=?');
    $st->execute([$biz,$biz]);$by=[];foreach($st->fetchAll() as $p)$by[(int)$p['product_id']]=$p;$st->closeCursor();
    foreach($products as &$p){
        $v=$by[(int)$p['id']]??null;
        // Legacy CLP edits/imports supersede the old CLP price metadata, never UF.
        if($v&&$v['price_unit']==='CLP'&&(int)$v['reference_clp']!==(int)$p['price_clp'])$v=null;
        $p['commercial_price']=$v;$p['price_unit']=$v['price_unit']??'CLP';$p['uf_sale_blocked']=$p['price_unit']==='UF';
    }unset($p);return $products;
}
// Cálculo referencial entero: unidad CLP al peso, UF a seis decimales.
function titoCommercialRound(int $n,int $den):int{
    return intdiv($n+intdiv($den,2),$den);
}
function titoCommercialAmount(string $unit,string $final,?string $rate):array{
    if($unit==='CLP'){
        $clp=integerPrice((int)$final);
        if($rate===null)return ['clp'=>$clp,'uf'=>null];
        $r=titoCurrencyScaled($rate,2,6);if($r<=0)fail('UF inválida.',422);
        // CLP <= 1e9: product <= 1e17, fits signed 64-bit.
        $uf=titoCommercialRound($clp*100000000,$r);
        return ['clp'=>$clp,'uf'=>titoDraftDecimal($uf,6)];
    }
    if($rate===null)fail('No hay UF registrada para la fecha de operación.',409);
    return ['clp'=>titoDraftUFReference($final,$rate),'uf'=>$final];
}
function titoCommercialSubtotal(int $amount,int $quantityMilli,int $limit):int{
    $whole=intdiv($quantityMilli,1000);$fraction=$quantityMilli%1000;
    if($amount<0||$quantityMilli<0||($whole>0&&$amount>intdiv($limit,$whole)))fail('Subtotal demasiado grande.',422);
    $base=$amount*$whole;
    $rest=intdiv($amount,1000)*$fraction+titoCommercialRound(($amount%1000)*$fraction,1000);
    if($base>$limit-$rest)fail('Subtotal demasiado grande.',422);
    return $base+$rest;
}
function registerCommercialPriceRoutes(RouteCollectorProxy $group):void{
    $group->post('/currency/operation-preview',static function(Request $request,Response $response):Response{
        $a=$request->getAttribute('currentUser');$biz=(int)$a['business_id'];$b=requestBody($request);
        $db=db();$unit=$b['operation_unit']??null;
        if(!is_string($unit))fail('Unidad de operación inválida.',422);
        $day=titoUfRangeDay($b['date']??quoteToday());$items=$b['items']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>50)fail('Ingresa de 1 a 50 productos.',422);
        $db->beginTransaction();try{
            originLockCompany($db,$biz);titoCommercialAllowed($db,$biz,$unit);
            $rate=titoCurrencyRate($db,$biz,$day);$r=$rate?(string)$rate['clp_per_uf']:null;
            $st=$db->prepare('SELECT id,name,price_clp,sale_unit FROM products WHERE id=? AND business_id=? AND is_active=1');
            $lines=[];$seen=[];$totalClp=0;$totalUf=0;$allUf=true;
            foreach($items as $item){
                if(!is_array($item)||filter_var($item['product_id']??null,FILTER_VALIDATE_INT)===false)fail('Producto inválido.',422);
                $pid=(int)$item['product_id'];if($pid<1||isset($seen[$pid]))fail('Producto duplicado o inválido.',422);$seen[$pid]=true;
                $st->execute([$pid,$biz]);$p=$st->fetch();$st->closeCursor();if(!$p)fail('Producto no disponible.',404);
                $q=decimalQuantity($item['quantity']??null,false,$p['sale_unit']!=='unidad');
                if((float)$q<=0)fail('Cantidad inválida.',422);
                $v=titoCommercialLatest($db,$biz,$pid);
                if($v&&$v['price_unit']==='CLP'&&(int)$v['reference_clp']!==(int)$p['price_clp'])$v=null;
                $originalUnit=$v['price_unit']??'CLP';$final=$originalUnit==='CLP'?(string)(int)$p['price_clp']:(string)$v['final_amount'];
                if($originalUnit==='UF'||$unit==='UF'){if(!$r)fail('No existe UF registrada para la fecha elegida.',409);}
                $converted=titoCommercialAmount($originalUnit,$final,$r);
                // Preserve original list price and rate; no arbitrary discounts in this preview.
                $qm=titoCurrencyScaled($q,3,10);
                $subClp=titoCommercialSubtotal($converted['clp'],$qm,1000000000000);
                $subUf=$converted['uf']===null?null:titoCommercialSubtotal((int)str_replace('.','',$converted['uf']),$qm,PHP_INT_MAX);
                if($subClp<0||$subClp>1000000000000||$totalClp+$subClp>1000000000000)fail('Total demasiado grande.',422);
                if($subUf===null)$allUf=false;else{if($subUf<0||$totalUf>PHP_INT_MAX-$subUf)fail('Total UF demasiado grande.',422);$totalUf+=$subUf;}
                $totalClp+=$subClp;
                $lines[]=['product_id'=>$pid,'name'=>$p['name'],'quantity'=>$q,'original_unit'=>$originalUnit,'original_final_amount'=>$final,'price_version_id'=>$v?(int)$v['id']:null,'unit_clp'=>$converted['clp'],'unit_uf'=>$converted['uf'],'subtotal_clp'=>$subClp,'subtotal_uf'=>$subUf===null?null:titoDraftDecimal($subUf,6)];
            }
            $db->rollBack();return respond($response,['operation_unit'=>$unit,'date'=>$day,'rate'=>$rate,'lines'=>$lines,'total_clp'=>$totalClp,'total_uf'=>$allUf?titoDraftDecimal($totalUf,6):null,'preview_only'=>true,'operational_enabled'=>false]);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->get('/products/{id:[0-9]+}/commercial-price-history',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$pid=(int)$args['id'];
        $s=db()->prepare('SELECT id,price_unit,source_amount,source_basis,tax_treatment,final_amount,reference_clp,uf_rate_id,reference_date,actor_id,created_at FROM product_commercial_prices WHERE business_id=? AND product_id=? ORDER BY id DESC LIMIT 100');
        $s->execute([$biz,$pid]);return respond($response,['versions'=>$s->fetchAll()]);
    });
}
