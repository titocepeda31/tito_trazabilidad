<?php
declare(strict_types=1);
require __DIR__.'/../private/bootstrap.php';
require __DIR__.'/../private/currency_units.php';
require __DIR__.'/../private/product_price_drafts.php';
function checkDraft(bool $ok):void{if(!$ok)throw new RuntimeException('Fallo de precios CLP/UF');}
function priceDraft(string $unit,string $amount,string $basis,string $tax):array{return titoDraftPrice(['price_unit'=>$unit,'source_amount'=>$amount,'source_basis'=>$basis,'tax_treatment'=>$tax]);}
checkDraft(priceDraft('CLP','100000','net','vat19')['final_amount']==='119000');
checkDraft(priceDraft('CLP','119000','gross','vat19')['final_amount']==='119000');
checkDraft(priceDraft('UF','10','net','vat19')['final_amount']==='11.900000');
checkDraft(priceDraft('UF','0.0001','net','vat19')['final_amount']==='0.000119');
checkDraft(priceDraft('UF','10','net','exempt')['final_amount']==='10.000000');
checkDraft(priceDraft('UF','10','gross','vat19')['final_amount']==='10.000000');
checkDraft(titoDraftUFReference('11.900000','40000.00')===476000);
checkDraft(titoDraftUFReference('0.000119','40000.00')===5);
foreach([['CLP','1.2','net','vat19'],['UF','1.00001','net','vat19'],['UF','-1','gross','vat19'],['UF','1','unknown','vat19']] as $args){$bad=false;try{priceDraft(...$args);}catch(HttpError $e){$bad=$e->status===422;}checkDraft($bad);}
echo "product price drafts OK\n";
