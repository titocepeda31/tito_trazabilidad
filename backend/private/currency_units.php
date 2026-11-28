<?php
declare(strict_types=1);
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

// Decimal string inputs, integer arithmetic. Never accept float UF amounts.
function titoCurrencyScaled(mixed $v,int $places,int $wholeDigits):int {
    if(!is_string($v)||!preg_match('/^[0-9]{1,'.$wholeDigits.'}(?:\.[0-9]{1,'.$places.'})?$/D',$v))fail('Monto decimal inválido.',422);
    $parts=explode('.',$v,2);
    return (int)($parts[0].str_pad($parts[1]??'',$places,'0'));
}
function titoCurrencyDay(mixed $v):string {
    if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v))fail('Fecha UF inválida.',422);
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('America/Santiago'));
    if(!$d||$d->format('Y-m-d')!==$v||$v<'2000-01-01'||$v>quoteToday())fail('Fecha UF inválida o futura.',422);
    return $v;
}
function titoCurrencyConvert(string $amount,string $rate):int {
    // 4 decimales de UF × 2 decimales de CLP/UF; redondeo half-up al peso.
    $a=titoCurrencyScaled($amount,4,5);$r=titoCurrencyScaled($rate,2,6);
    if($r<=0)fail('Valor UF inválido.',422);
    $clp=intdiv($a*$r+500000,1000000);
    return integerPrice($clp);
}
function titoCurrencySettings(PDO $db,int $biz):array {
    return dteOne($db,'SELECT default_unit,allow_both,updated_at FROM business_currency_settings WHERE business_id=?',[$biz])??['default_unit'=>'CLP','allow_both'=>1,'updated_at'=>null];
}
function titoCurrencyRate(PDO $db,int $biz,string $day):?array {
    return dteOne($db,'SELECT id,rate_on,clp_per_uf,source_reference,recorded_by,created_at FROM business_uf_rates WHERE business_id=? AND rate_on=? ORDER BY id DESC LIMIT 1',[$biz,$day]);
}
function registerCurrencyUnitRoutes(RouteCollectorProxy $group):void {
    $group->get('/currency/settings',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);return respond($response,['settings'=>titoCurrencySettings(db(),(int)$a['business_id']),'today'=>quoteToday(),'operational_enabled'=>false]);
    });
    $group->post('/currency/settings',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$b=requestBody($request);$u=$b['default_unit']??null;$both=$b['allow_both']??null;
        if(!in_array($u,['CLP','UF'],true)||!is_bool($both))fail('Configuración comercial inválida.',422);
        $db=db();$s=$db->prepare('INSERT INTO business_currency_settings(business_id,default_unit,allow_both,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE default_unit=VALUES(default_unit),allow_both=VALUES(allow_both),updated_by=VALUES(updated_by)');
        $s->execute([(int)$a['business_id'],$u,(int)$both,(int)$a['id']]);
        return respond($response,['settings'=>titoCurrencySettings($db,(int)$a['business_id']),'operational_enabled'=>false]);
    });
    $group->get('/currency/uf',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$day=titoCurrencyDay($request->getQueryParams()['date']??quoteToday());$r=titoCurrencyRate(db(),(int)$a['business_id'],$day);
        return respond($response,['date'=>$day,'rate'=>$r]);
    });
    $group->post('/currency/uf/sync',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$b=requestBody($request);$day=titoCurrencyDay($b['date']??null);$refresh=$b['refresh']??false;
        if(!is_bool($refresh))fail('Opción de actualización inválida.',422);
        $result=titoUfEnsure(db(),(int)$a['business_id'],(int)$a['id'],$day,$refresh);
        return respond($response,$result);
    });
    $group->post('/currency/uf',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$b=requestBody($request);$day=titoCurrencyDay($b['date']??null);
        $value=$b['clp_per_uf']??null;$scaled=titoCurrencyScaled($value,2,6);
        if($scaled<=0)fail('Valor UF inválido.',422);
        $source=requireString($b,'source_reference',250);$db=db();$biz=(int)$a['business_id'];
        $source='Manual: '.mb_substr($source,0,242);
        $db->beginTransaction();try{
            originLockCompany($db,$biz);
            $s=$db->prepare('INSERT INTO business_uf_rates(business_id,rate_on,clp_per_uf,source_reference,recorded_by) VALUES(?,?,?,?,?)');
            $s->execute([$biz,$day,$value,$source,(int)$a['id']]);
            $id=(int)$db->lastInsertId();
            $rate=dteOne($db,'SELECT id,rate_on,clp_per_uf,source_reference,recorded_by,created_at FROM business_uf_rates WHERE business_id=? AND id=?',[$biz,$id]);
            $db->commit();return respond($response,['rate'=>$rate],201);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    });
    $group->post('/currency/preview',static function(Request $request,Response $response):Response{
        $a=adminOnly($request);$b=requestBody($request);$day=titoCurrencyDay($b['date']??null);$amount=$b['amount']??null;
        if(!is_string($amount))fail('Monto UF inválido.',422);
        $rate=titoCurrencyRate(db(),(int)$a['business_id'],$day);
        if(!$rate)fail('No existe UF registrada para esa fecha. No se usará un valor de otro día.',409);
        return respond($response,['unit'=>'UF','amount'=>$amount,'total_clp'=>titoCurrencyConvert($amount,(string)$rate['clp_per_uf']),'rate'=>$rate,'preview_only'=>true]);
    });
}
