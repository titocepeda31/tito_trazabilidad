<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

// All fiscal arithmetic is integer-based. Preserve UF to 6 places after IVA.
function titoDraftDecimal(int $n,int $places):string{
    $s=str_pad((string)$n,$places+1,'0',STR_PAD_LEFT);
    return substr($s,0,-$places).'.'.substr($s,-$places);
}
function titoDraftPrice(array $r):array{
    $unit=$r['price_unit']??null;$basis=$r['source_basis']??null;$tax=$r['tax_treatment']??null;
    if(!in_array($unit,['CLP','UF'],true)||!in_array($basis,['net','gross'],true)||!in_array($tax,['vat19','exempt','non_taxable'],true))fail('Confirma unidad, base de precio e impuesto.',422);
    $raw=$r['source_amount']??null;
    $add=$basis==='net'&&$tax==='vat19';
    if($unit==='CLP'){
        if(!is_string($raw)||!preg_match('/^[0-9]{1,10}$/D',$raw))fail('Precio CLP debe ser entero.',422);
        $a=integerPrice((int)$raw);$final=integerPrice($a+($add?intdiv($a*19+50,100):0));
        return ['price_unit'=>$unit,'source_amount'=>(string)$a,'source_basis'=>$basis,'tax_treatment'=>$tax,'final_amount'=>(string)$final];
    }
    $a=titoCurrencyScaled($raw,4,5);
    $final=$a*($add?119:100);
    return ['price_unit'=>$unit,'source_amount'=>titoDraftDecimal($a,4),'source_basis'=>$basis,'tax_treatment'=>$tax,'final_amount'=>titoDraftDecimal($final,6)];
}
function titoDraftUFReference(string $final,string $rate):int{
    $a=titoCurrencyScaled($final,6,6);$b=titoCurrencyScaled($rate,2,6);
    if($b<=0)fail('Valor UF inválido.',422);
    // Decompose the multiplication to avoid overflow for large, valid inputs.
    $den=100000000;$whole=intdiv($a,$den);$rest=$a%$den;
    return integerPrice($whole*$b+intdiv($rest*$b+intdiv($den,2),$den));
}
function titoDraftCode(mixed $v):?string{
    if($v===null||$v==='')return null;
    if(!is_string($v)||mb_strlen(trim($v))>50)fail('Código inválido.',422);
    return trim($v)?:null;
}
function titoDraftInput(array $r):array{
    $name=requireString($r,'product_name',120);$sku=titoDraftCode($r['sku']??null);$barcode=titoDraftCode($r['barcode']??null);
    if(!$sku&&!$barcode)fail('Identifica cada producto mediante SKU o código de barras.',422);
    return ['product_name'=>$name,'sku'=>$sku,'barcode'=>$barcode]+titoDraftPrice($r);
}
function titoDraftMatch(PDO $db,int $biz,array $r):?int{
    $s=$db->prepare('SELECT id,sku,barcode FROM products WHERE business_id=? AND (sku=? OR barcode=?) FOR UPDATE');
    $s->execute([$biz,$r['sku'],$r['barcode']]);$matches=$s->fetchAll();$s->closeCursor();
    if(count($matches)>1)fail('SKU y código de barras corresponden a productos diferentes.',409);
    if(!$matches)return null;
    $p=$matches[0];
    if($r['sku']!==null&&$p['sku']!==null&&strcasecmp($r['sku'],$p['sku'])!==0)fail('SKU no coincide con el producto existente.',409);
    if($r['barcode']!==null&&$p['barcode']!==null&&strcasecmp($r['barcode'],$p['barcode'])!==0)fail('Código de barras no coincide con el producto existente.',409);
    return (int)$p['id'];
}
function titoDraftRows(PDO $db,int $biz,array $rows,?string $day):array{
    $settings=titoCurrencySettings($db,$biz);$rate=null;$out=[];$skus=[];$bars=[];$ids=[];
    foreach($rows as $i=>$raw){
        if(!is_array($raw)||array_is_list($raw))fail('Fila inválida.',422);
        $r=titoDraftInput($raw);
        if(!(int)$settings['allow_both']&&$r['price_unit']!==$settings['default_unit'])fail('Unidad no habilitada para esta empresa.',422);
        foreach(['sku','barcode'] as $key){
            if($r[$key]===null)continue;
            $k=mb_strtoupper($r[$key],'UTF-8');
            if($key==='sku'){if(isset($skus[$k]))fail('SKU duplicado en el lote.',422);$skus[$k]=true;}
            else{if(isset($bars[$k]))fail('Código de barras duplicado en el lote.',422);$bars[$k]=true;}
        }
        $pid=titoDraftMatch($db,$biz,$r);
        if($pid!==null){if(isset($ids[$pid]))fail('Dos filas corresponden al mismo producto.',422);$ids[$pid]=true;}
        $ref=null;$rid=null;$refDay=null;
        if($r['price_unit']==='CLP')$ref=(int)$r['final_amount'];
        elseif($day!==null){
            $rate??=titoCurrencyRate($db,$biz,$day);
            if(!$rate)fail('No existe UF registrada para la fecha seleccionada.',409);
            $rid=(int)$rate['id'];$refDay=$day;$ref=titoDraftUFReference($r['final_amount'],(string)$rate['clp_per_uf']);
        }
        $out[]=$r+['product_id'=>$pid,'row_index'=>$i,'reference_date'=>$refDay,'uf_rate_id'=>$rid,'reference_clp'=>$ref];
    }
    return $out;
}
function registerProductPriceDraftRoutes(RouteCollectorProxy $group):void{
    $group->get('/currency/product-drafts',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$s=db()->prepare('SELECT id,product_id,sku,barcode,product_name,price_unit,source_amount,source_basis,tax_treatment,final_amount,uf_rate_id,reference_date,reference_clp,created_at FROM product_price_drafts WHERE business_id=? ORDER BY id DESC LIMIT 100');
        $s->execute([(int)$a['business_id']]);return respond($response,['drafts'=>$s->fetchAll(),'operational_enabled'=>false]);
    });
    $group->post('/currency/product-drafts/preview',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$b=requestBody($request);$rows=$b['rows']??null;$day=isset($b['date'])?titoCurrencyDay($b['date']):null;
        if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>100)fail('Se admiten entre 1 y 100 filas por lote.',422);
        $db=db();$db->beginTransaction();try{originLockCompany($db,(int)$a['business_id']);$out=titoDraftRows($db,(int)$a['business_id'],$rows,$day);$db->rollBack();return respond($response,['rows'=>$out,'operational_enabled'=>false]);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/currency/product-drafts',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$biz=(int)$a['business_id'];$uid=(int)$a['id'];$b=requestBody($request);
        $rid=$b['request_id']??null;$rows=$b['rows']??null;$expected=$b['expected']??null;$day=$b['date']??null;
        if(!is_string($rid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$rid)||!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>100||!is_array($expected)||!array_is_list($expected))fail('Lote inválido.',422);
        $hash=hash('sha256',json_encode([$rows,$day,$expected],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $db=db();$db->beginTransaction();try{
            originLockCompany($db,$biz);
            $old=$db->prepare('SELECT payload_hash,COUNT(*) AS n FROM product_price_drafts WHERE business_id=? AND request_id=? GROUP BY payload_hash');$old->execute([$biz,$rid]);$previous=$old->fetchAll();$old->closeCursor();
            if($previous){if(count($previous)!==1||!hash_equals($previous[0]['payload_hash'],$hash)||(int)$previous[0]['n']!==count($rows))fail('Identificador reutilizado con contenido distinto.',409);$db->commit();return respond($response,['saved'=>count($rows),'already_recorded'=>true]);}
            $day=$day===null?null:titoCurrencyDay($day);
            $out=titoDraftRows($db,$biz,$rows,$day);
            if($out!==$expected)fail('La vista previa cambió (producto, valor UF o configuración). Vuelve a revisar antes de guardar.',409);
            $s=$db->prepare('INSERT INTO product_price_drafts(business_id,product_id,sku,barcode,product_name,price_unit,source_amount,source_basis,tax_treatment,final_amount,uf_rate_id,reference_date,reference_clp,request_id,row_index,payload_hash,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach($out as $r)$s->execute([$biz,$r['product_id'],$r['sku'],$r['barcode'],$r['product_name'],$r['price_unit'],$r['source_amount'],$r['source_basis'],$r['tax_treatment'],$r['final_amount'],$r['uf_rate_id'],$r['reference_date'],$r['reference_clp'],$rid,$r['row_index'],$hash,$uid]);
            $db->commit();return respond($response,['saved'=>count($out),'already_recorded'=>false],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
}
