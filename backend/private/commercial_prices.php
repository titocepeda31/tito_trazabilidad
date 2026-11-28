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
function registerCommercialPriceRoutes(RouteCollectorProxy $group):void{
    $group->get('/products/{id:[0-9]+}/commercial-price-history',static function(Request $request,Response $response,array $args):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$pid=(int)$args['id'];
        $s=db()->prepare('SELECT id,price_unit,source_amount,source_basis,tax_treatment,final_amount,reference_clp,uf_rate_id,reference_date,actor_id,created_at FROM product_commercial_prices WHERE business_id=? AND product_id=? ORDER BY id DESC LIMIT 100');
        $s->execute([$biz,$pid]);return respond($response,['versions'=>$s->fetchAll()]);
    });
}
