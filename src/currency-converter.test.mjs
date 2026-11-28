import {test} from 'node:test';
import assert from 'node:assert/strict';
import {convertCurrencyReference as convert} from './currency-converter.mjs';
test('pesos a UF, tasa exacta',()=>{
 assert.equal(convert('25000','CLP','41426.11').output,'0,6035 UF');
});
test('UF a pesos sobre el antiguo límite de catálogo',()=>{
 const r=convert('25000','UF','41426.11');
 assert.equal(r.output.replace(/[^0-9]/g,''),'1035652750');
});
test('decimales UF y redondeo al peso',()=>{
 assert.equal(convert('10,5','UF','41426.11').output.replace(/[^0-9]/g,''),'434974');
 assert.equal(convert('0,0001','UF','45000.00').output,'$5');
});
test('entradas inválidas no producen una conversión',()=>{
 for(const [amount,unit,rate] of [['25.000','CLP','41426.11'],['-1','UF','41426.11'],['1,00001','UF','41426.11'],['1','UF','0'],['1','UF','']]){
  assert.throws(()=>convert(amount,unit,rate));
 }
});
