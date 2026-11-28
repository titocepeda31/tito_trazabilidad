<?php
declare(strict_types=1);
require __DIR__.'/../private/bootstrap.php';
require __DIR__.'/../private/currency_units.php';
require __DIR__.'/../private/product_price_drafts.php';
require __DIR__.'/../private/commercial_prices.php';
function commercialCheck(bool $ok):void{if(!$ok)throw new RuntimeException('Fallo precio comercial');}
$p=titoDraftPrice(['price_unit'=>'UF','source_amount'=>'15','source_basis'=>'net','tax_treatment'=>'vat19']);
commercialCheck($p['final_amount']==='17.850000');
commercialCheck(titoCommercialAmount('UF','15.000000','41426.11')['clp']===621392);
commercialCheck(titoCommercialAmount('CLP','35000','41426.11')['uf']==='0.844878');
commercialCheck(titoCommercialSubtotal(35000,1500,1000000000000)===52500);
commercialCheck(titoCommercialSubtotal(1,500,1000000000000)===1);
commercialCheck(titoCommercialSubtotal(12345678901234567,1,PHP_INT_MAX)===12345678901235);
foreach([fn()=>titoCommercialAmount('UF','1.000000',null),fn()=>titoCommercialSubtotal(1000000000,1000000000000,1000000000000)] as $f){
 $bad=false;try{$f();}catch(HttpError $e){$bad=true;}commercialCheck($bad);
}
echo "Commercial price tests OK\n";
