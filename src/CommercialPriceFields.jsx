import React,{useState} from 'react';
export function commercialPayload(form){
 const price_unit=String(form.get('price_unit'));
 return {commercial_price:{price_unit,source_amount:String(form.get('source_amount')||'').trim().replace(',','.'),source_basis:String(form.get('source_basis')),tax_treatment:String(form.get('tax_treatment'))}};
}
export default function CommercialPriceFields({product,settings}){
 const existing=product?.commercial_price;
 const [unit,setUnit]=useState(existing?.price_unit||product?.price_unit||settings?.default_unit||'CLP');
 const [amount,setAmount]=useState(existing?String(Number(existing.source_amount)):product?String(product.price_clp):'');
 const [basis,setBasis]=useState(existing?.source_basis||'gross');
 const [tax,setTax]=useState(existing?.tax_treatment||'');
 const allowed=settings?.allow_both===true||Number(settings?.allow_both)===1?['CLP','UF']:[settings?.default_unit||'CLP'];
 const units=[...new Set([...allowed,unit])];
 const enabled=allowed.includes(unit);
 return <>
 <label>Unidad comercial<select name="price_unit" value={unit} onChange={e=>{setUnit(e.target.value);setAmount('');}}>{units.map(u=><option key={u} value={u}>{u==='CLP'?'Pesos chilenos (CLP)':'Unidad de Fomento (UF)'}</option>)}</select></label>
 {!enabled&&<p className="warning-box">Esta unidad no está habilitada en la empresa; selecciona una habilitada.</p>}
 <label>Precio original ({unit})<input name="source_amount" required value={amount} inputMode={unit==='CLP'?'numeric':'decimal'} pattern={unit==='CLP'?'[0-9]{1,10}':'[0-9]{1,5}([.,][0-9]{1,4})?'} onChange={e=>setAmount(e.target.value)} placeholder="Sin separadores de miles"/></label>
 <label>Tipo de precio<select name="source_basis" value={basis} onChange={e=>setBasis(e.target.value)}><option value="gross">Final</option><option value="net">Neto</option></select></label>
 <label>Tratamiento tributario<select name="tax_treatment" required value={tax} onChange={e=>setTax(e.target.value)}><option value="">Selecciona</option><option value="vat19">Afecto IVA 19%</option><option value="exempt">Exento</option><option value="non_taxable">No afecto</option></select></label>
 {unit==='UF'&&<p className="warning-box">Se conservará el precio en UF y su equivalente referencial en pesos. En esta rama de desarrollo el producto no podrá cotizarse ni venderse hasta integrar los flujos comerciales.</p>}
 {existing&&<p className="helper">Versión de precio #{existing.id}. Las operaciones históricas no se modifican.</p>}
 </>;
}
