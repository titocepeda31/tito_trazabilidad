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
echo "UF official parser OK\n";
