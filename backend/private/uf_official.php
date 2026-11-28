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
function titoUfOfficialRequest(string $from,string $to):string{
    $token=titoUfToken();if(!$token)fail('Falta configurar el token API del Banco Central en el servidor. Puedes registrar el valor oficial manualmente.',503);
    if(!function_exists('curl_init'))fail('La extensión cURL no está disponible en el servidor.',503);
    $query=http_build_query(['token'=>$token,'function'=>'GetSeries','timeseries'=>TITO_UF_SERIES,'firstdate'=>$from,'lastdate'=>$to],'','&',PHP_QUERY_RFC3986);
    $ch=curl_init('https://si3.bcentral.cl/SieteRestWS/SieteRestWS.ashx?'.$query);
    if($ch===false)fail('No se pudo iniciar la consulta UF.',503);
    $raw='';curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>131072)return 0;$raw.=$chunk;return strlen($chunk);}]);
    $ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($ok===false||$http!==200)fail('No se pudo consultar la UF oficial. Reintenta o registra el valor verificado manualmente.',503);
    return $raw;
}
function titoUfOfficialFetch(string $day):string{
    return titoUfOfficialParse(titoUfOfficialRequest($day,$day),$day);
}
function titoUfPublishedThrough(string $today):string{
    $d=new DateTimeImmutable($today,new DateTimeZone('America/Santiago'));
    if((int)$d->format('d')>=9)$d=$d->modify('first day of next month');
    return $d->format('Y-m-09');
}
function titoUfPeriod(string $day):array{
    $d=new DateTimeImmutable($day,new DateTimeZone('America/Santiago'));
    if((int)$d->format('d')<10)$d=$d->modify('first day of previous month');
    return [$d->format('Y-m-10'),$d->modify('first day of next month')->format('Y-m-09')];
}
function titoUfRangeDay(mixed $v):string{
    if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v))fail('Fecha UF inválida.',422);
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('America/Santiago'));
    if(!$d||$d->format('Y-m-d')!==$v||$v<'2000-01-01'||$v>titoUfPublishedThrough(quoteToday()))fail('Fecha fuera del horizonte de publicación UF.',422);
    return $v;
}
function titoUfDays(string $from,string $to):array{
    if($from>$to)fail('La fecha inicial debe ser anterior o igual a la final.',422);
    $d=new DateTimeImmutable($from);$end=new DateTimeImmutable($to);
    if($d->diff($end)->days>365)fail('El período máximo es de 366 días.',422);
    $out=[];while($d<=$end){$out[]=$d->format('Y-m-d');$d=$d->modify('+1 day');}
    return $out;
}
function titoUfOfficialParseRange(string $raw,string $from,string $to):array{
    try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){fail('Respuesta UF oficial inválida.',502);}
    if(!is_array($data)||!in_array($data['Codigo']??null,[0,'0'],true)||($data['Series']['seriesId']??null)!==TITO_UF_SERIES||!is_array($data['Series']['Obs']??null))fail('Banco Central no entregó la serie UF esperada.',502);
    $allowed=array_fill_keys(titoUfDays($from,$to),true);$seen=[];$out=[];
    foreach($data['Series']['Obs'] as $o){
        if(!is_array($o)||!is_string($o['indexDateString']??null))fail('Observación UF inválida.',502);
        $rawDay=$o['indexDateString'];$d=DateTimeImmutable::createFromFormat('!d-m-Y',$rawDay);
        if(!$d||$d->format('d-m-Y')!==$rawDay)fail('Fecha UF oficial inválida.',502);
        $day=$d->format('Y-m-d');
        if(!isset($allowed[$day]))continue;
        if(isset($seen[$day]))fail('Banco Central devolvió fechas duplicadas.',502);$seen[$day]=true;
        if(($o['statusCode']??null)!=='OK')continue;
        $v=$o['value']??null;
        if(!is_string($v)||!preg_match('/^[0-9]{1,6}(?:\.[0-9]{1,2})?$/D',$v))fail('Formato de valor UF oficial inesperado.',502);
        $n=titoCurrencyScaled($v,2,6);if($n<=0)fail('Valor UF oficial inválido.',502);
        $out[$day]=intdiv($n,100).'.'.str_pad((string)($n%100),2,'0',STR_PAD_LEFT);
    }
    ksort($out);return $out;
}
function titoUfCoverage(PDO $db,int $biz,string $from,string $to):array{
    $days=titoUfDays($from,$to);
    // Una fila por fecha: la última versión registrada, sin perder el historial.
    // Ambas partes de la consulta están limitadas a la empresa autenticada.
    $s=$db->prepare('SELECT r.id,r.rate_on,r.clp_per_uf,r.source_reference,r.created_at
        FROM business_uf_rates r
        JOIN (SELECT rate_on,MAX(id) AS latest_id FROM business_uf_rates WHERE business_id=? AND rate_on BETWEEN ? AND ? GROUP BY rate_on) latest
        ON latest.latest_id=r.id AND latest.rate_on=r.rate_on
        WHERE r.business_id=? ORDER BY r.rate_on ASC');
    $s->execute([$biz,$from,$to,$biz]);$rates=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();
    $stored=array_column($rates,'rate_on');
    $missing=array_values(array_diff($days,$stored));
    return ['from'=>$from,'to'=>$to,'days'=>count($days),'stored'=>count($rates),'missing'=>$missing,'rates'=>$rates];
}
function titoUfSyncRange(PDO $db,int $biz,int $uid,string $from,string $to,bool $refresh=false):array{
    $days=titoUfDays($from,$to);$before=titoUfCoverage($db,$biz,$from,$to);
    if(!$refresh&&!$before['missing'])return $before+['inserted'=>0,'unchanged'=>count($days),'published'=>null,'cached'=>true];
    $rates=titoUfOfficialParseRange(titoUfOfficialRequest($from,$to),$from,$to);
    $db->beginTransaction();try{
        originLockCompany($db,$biz);
        $s=$db->prepare('INSERT INTO business_uf_rates(business_id,rate_on,clp_per_uf,source_reference,recorded_by) VALUES(?,?,?,?,?)');
        $inserted=0;$unchanged=0;
        foreach($rates as $day=>$value){
            $latest=titoCurrencyRate($db,$biz,$day);
            if($latest&&(!$refresh||((string)$latest['clp_per_uf']===$value&&$latest['source_reference']===TITO_UF_OFFICIAL_SOURCE))){$unchanged++;continue;}
            $s->execute([$biz,$day,$value,TITO_UF_OFFICIAL_SOURCE,$uid]);$inserted++;
        }
        $coverage=titoUfCoverage($db,$biz,$from,$to);$db->commit();
        return $coverage+['inserted'=>$inserted,'unchanged'=>$unchanged,'published'=>count($rates),'cached'=>false];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
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
