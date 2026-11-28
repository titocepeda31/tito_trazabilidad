<?php
declare(strict_types=1);
require __DIR__.'/../private/bootstrap.php';
require __DIR__.'/../private/currency_units.php';
function checkCurrency(bool $ok):void{if(!$ok)throw new RuntimeException('Fallo de conversión UF');}
checkCurrency(titoCurrencyConvert('11.9000','40000.00')===476000);
checkCurrency(titoCurrencyConvert('0.0001','45000.00')===5);
checkCurrency(titoCurrencyConvert('0.0001','44999.99')===4);
checkCurrency(titoCurrencyConvert('0','40000.00')===0);
foreach([['1.00001','40000.00'],['1','0'],['-1','40000'],['1e2','40000'],['99999','999999']] as [$a,$r]){
    $rejected=false;try{titoCurrencyConvert($a,$r);}catch(HttpError $e){$rejected=$e->status===422;}checkCurrency($rejected);
}
echo "currency units OK\n";
