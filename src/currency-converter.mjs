// Referencial, no modifica precios, ventas ni DTE. Solo usa tasa de fecha seleccionada.
const integers=new Intl.NumberFormat('es-CL',{maximumFractionDigits:0});
function scaled(raw,places,maxWhole){
 const s=String(raw??'').trim();
 const pattern=places===0?new RegExp('^[0-9]{1,'+maxWhole+'}$'):new RegExp('^[0-9]{1,'+maxWhole+'}(?:[.,][0-9]{1,'+places+'})?$');
 if(!pattern.test(s))throw new Error(places===0?'Ingresa pesos enteros sin separadores de miles (hasta 12 dígitos).':'Ingresa UF sin separadores de miles, con hasta 4 decimales (hasta 12 dígitos enteros).');
 const [whole,frac='']=s.replace(',','.').split('.');
 return BigInt(whole+frac.padEnd(places,'0'));
}
function decimal(n,places){
 const div=10n**BigInt(places);const whole=integers.format(n/div);const fraction=(n%div).toString().padStart(places,'0');
 return places?whole+','+fraction:whole;
}
function round(n,d){return (n+d/2n)/d;}
export function convertCurrencyReference(amount,unit,rate){
 // El API devuelve DECIMAL(12,2) como cadena. No aceptar tasas vacías o ambiguas.
 const s=String(rate??'');
 if(!/^[0-9]{1,6}(?:\.[0-9]{1,2})?$/.test(s))throw new Error('No hay una tasa UF válida para la fecha seleccionada.');
 const [whole,frac='']=s.split('.');const cents=BigInt(whole+frac.padEnd(2,'0'));
 if(cents<=0n)throw new Error('No hay una tasa UF válida para la fecha seleccionada.');
 if(unit==='CLP'){
   const clp=scaled(amount,0,12);const uf=round(clp*1000000n,cents);
   return {input: '$'+integers.format(clp),output:decimal(uf,4)+' UF',unit:'UF'};
 }
 if(unit==='UF'){
   const uf=scaled(amount,4,12);const clp=round(uf*cents,1000000n);
   return {input:decimal(uf,4)+' UF',output:'$'+integers.format(clp),unit:'CLP'};
 }
 throw new Error('Unidad comercial inválida.');
}
