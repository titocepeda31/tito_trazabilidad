<?php
declare(strict_types=1);

const TITO_UF_SERIES='F073.UFF.PRE.Z.D';
const TITO_UF_OFFICIAL_SOURCE='Banco Central de Chile — BDE F073.UFF.PRE.Z.D';
function titoUfToken():?string{
    $v=getenv('TITO_BCCH_API_TOKEN');
    if($v===false||trim($v)==='')$v=config()['bcch_api_token']??null;
    return is_string($v)&&trim($v)!==''?trim($v):null;
}
function titoUfOfficialParse(string $raw,string $day):string{
    try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){fail('Respuesta UF oficial inválida.',502);}
    if(!is_array($data)||!in_array($data['Codigo']??null,[0,'0'],true)||($data['Series']['seriesId']??null)!==TITO_UF_SERIES||!is_array($data['Series']['Obs']??null))fail('Banco Central no entregó la serie UF esperada.',502);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day);if(!$date||$date->format('Y-m-d')!==$day)fail('Fecha inválida.',422);
    $match=[];
    foreach($data['Series']['Obs'] as $o)if(is_array($o)&&($o['indexDateString']??null)===$date->format('d-m-Y'))$match[]=$o;
    if(count($match)!==1||($match[0]['statusCode']??null)!=='OK')fail('No hay valor UF oficial confirmado para la fecha exacta.',409);
    $v=$match[0]['value']??null;
    if(!is_string($v)||!preg_match('/^[0-9]{1,6}(?:\.[0-9]{1,2})?$/D',$v))fail('Formato de valor UF oficial inesperado.',502);
    $n=titoCurrencyScaled($v,2,6);if($n<=0)fail('Valor UF oficial inválido.',502);
    return intdiv($n,100).'.'.str_pad((string)($n%100),2,'0',STR_PAD_LEFT);
}
function titoUfOfficialFetch(string $day):string{
    $token=titoUfToken();if(!$token)fail('Falta configurar el token API del Banco Central en el servidor. Puedes registrar el valor oficial manualmente.',503);
    if(!function_exists('curl_init'))fail('La extensión cURL no está disponible en el servidor.',503);
    $query=http_build_query(['token'=>$token,'function'=>'GetSeries','timeseries'=>TITO_UF_SERIES,'firstdate'=>$day,'lastdate'=>$day],'','&',PHP_QUERY_RFC3986);
    $ch=curl_init('https://si3.bcentral.cl/SieteRestWS/SieteRestWS.ashx?'.$query);
    if($ch===false)fail('No se pudo iniciar la consulta UF.',503);
    $raw='';curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>131072)return 0;$raw.=$chunk;return strlen($chunk);}]);
    $ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($ok===false||$http!==200)fail('No se pudo consultar la UF oficial. Reintenta o registra el valor verificado manualmente.',503);
    return titoUfOfficialParse($raw,$day);
}
// Network outside transaction; serialize inserts with manual entry, recheck after fetch.
function titoUfEnsure(PDO $db,int $biz,int $uid,string $day,bool $refresh=false):array{
    $current=titoCurrencyRate($db,$biz,$day);
    if($current&&!$refresh)return ['rate'=>$current,'cached'=>true];
    $value=titoUfOfficialFetch($day);
    $db->beginTransaction();try{
        originLockCompany($db,$biz);
        $latest=titoCurrencyRate($db,$biz,$day);
        if($latest&&(!$refresh||((string)$latest['clp_per_uf']===$value&&$latest['source_reference']===TITO_UF_OFFICIAL_SOURCE))){
            $db->commit();return ['rate'=>$latest,'cached'=>true];
        }
        $s=$db->prepare('INSERT INTO business_uf_rates(business_id,rate_on,clp_per_uf,source_reference,recorded_by) VALUES(?,?,?,?,?)');
        $s->execute([$biz,$day,$value,TITO_UF_OFFICIAL_SOURCE,$uid]);
        $rate=titoCurrencyRate($db,$biz,$day);$db->commit();return ['rate'=>$rate,'cached'=>false];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
