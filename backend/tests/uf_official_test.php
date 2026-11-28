<?php
declare(strict_types=1);
require __DIR__.'/../private/bootstrap.php';
require __DIR__.'/../private/currency_units.php';
require __DIR__.'/../private/uf_official.php';
function ufCheck(bool $ok):void{if(!$ok)throw new RuntimeException('Fallo UF oficial');}
function ufFixture(array $obs):string{return json_encode(['Codigo'=>0,'Series'=>['seriesId'=>TITO_UF_SERIES,'Obs'=>$obs]],JSON_THROW_ON_ERROR);}
$o=['indexDateString'=>'28-11-2026','value'=>'40000.5','statusCode'=>'OK'];
ufCheck(titoUfOfficialParse(ufFixture([$o]),'2026-11-28')==='40000.50');
foreach([ufFixture([]),ufFixture([$o,$o]),ufFixture([array_replace($o,['indexDateString'=>'27-11-2026'])]),ufFixture([array_replace($o,['statusCode'=>'NA'])]),ufFixture([array_replace($o,['value'=>'NaN'])]),ufFixture([array_replace($o,['value'=>'0'])]),'{}','not-json'] as $raw){
 $bad=false;try{titoUfOfficialParse($raw,'2026-11-28');}catch(HttpError $e){$bad=true;}ufCheck($bad);
}
// Published period and month/year rollover, future published dates, gaps and duplicate protection.
ufCheck(titoUfPublishedThrough('2026-11-28')==='2026-12-09');
ufCheck(titoUfPublishedThrough('2026-12-08')==='2026-12-09');
ufCheck(titoUfPublishedThrough('2026-12-09')==='2027-01-09');
ufCheck(titoUfPeriod('2026-12-09')===['2026-11-10','2026-12-09']);
ufCheck(titoUfPeriod('2027-01-01')===['2026-12-10','2027-01-09']);
ufCheck(count(titoUfDays('2026-11-10','2026-12-09'))===30);
$p=array_replace($o,['indexDateString'=>'09-12-2026','value'=>'41501.94']);
$q=array_replace($o,['indexDateString'=>'29-11-2026','statusCode'=>'NA','value'=>'']);
$range=titoUfOfficialParseRange(ufFixture([$p,$q,$o]),'2026-11-28','2026-12-09');
ufCheck($range===['2026-11-28'=>'40000.50','2026-12-09'=>'41501.94']);
ufCheck(titoUfOfficialParseRange(ufFixture([]),'2026-11-28','2026-12-09')===[]);
foreach([ufFixture([$p,$p]),ufFixture([array_replace($p,['value'=>'0'])]),ufFixture([array_replace($p,['indexDateString'=>'31-11-2026'])])] as $raw){
 $bad=false;try{titoUfOfficialParseRange($raw,'2026-11-28','2026-12-09');}catch(HttpError $e){$bad=true;}ufCheck($bad);
}
foreach([['2026-12-09','2026-11-28'],['2024-01-01','2025-01-02']] as [$a,$b]){
 $bad=false;try{titoUfDays($a,$b);}catch(HttpError $e){$bad=true;}ufCheck($bad);
}
echo "UF official parser OK\n";
