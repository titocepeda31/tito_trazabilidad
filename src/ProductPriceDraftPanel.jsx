import React,{useEffect,useState} from 'react';
import {readSheet} from 'read-excel-file/browser';
import {csvParse,guessSeparator} from './import-utils';
import {newId,pesos} from './api';

const initial={product_name:'',sku:'',barcode:'',price_unit:'CLP',source_amount:'',source_basis:'',tax_treatment:''};
const keys=['product_name','sku','barcode','price_unit','source_amount','source_basis','tax_treatment'];
function normalizeRow(r){
 const unit=String(r.price_unit??'').trim().toUpperCase();
 const raw=String(r.source_amount??'').trim();
 const basis=String(r.source_basis??'').trim().toLowerCase();
 const tax=String(r.tax_treatment??'').trim().toLowerCase();
 return {product_name:String(r.product_name??'').trim(),sku:String(r.sku??'').trim()||null,barcode:String(r.barcode??'').trim()||null,price_unit:unit,source_amount:unit==='UF'?raw.replace(',','.'):raw,source_basis:({neto:'net',final:'gross',bruto:'gross'})[basis]||basis,tax_treatment:({afecto:'vat19',exento:'exempt','no afecto':'non_taxable'})[tax]||tax};
}
export default function ProductPriceDraftPanel({request,today,defaultUnit='CLP'}){
 const [form,setForm]=useState({...initial,price_unit:defaultUnit}),[mode,setMode]=useState('manual'),[rows,setRows]=useState([]),[date,setDate]=useState(''),[review,setReview]=useState(null),[history,setHistory]=useState([]),[busy,setBusy]=useState(false),[error,setError]=useState(''),[message,setMessage]=useState('');
 async function loadHistory(){const r=await request('/currency/product-drafts');setHistory(r.drafts||[]);}
 useEffect(()=>{loadHistory().catch(e=>setError(e.message));},[]);
 const change=(key,val)=>{setForm(f=>({...f,[key]:val}));setReview(null);};
 async function run(action){setBusy(true);setError('');setMessage('');try{await action();}catch(e){setError(e.message);}finally{setBusy(false);}}
 async function load(e){const file=e.target.files?.[0];e.target.value='';if(!file)return;setRows([]);setReview(null);await run(async()=>{
  if(file.size>2*1024*1024)throw new Error('Máximo 2 MB.');
  let sheet;if(/\.xlsx$/i.test(file.name))sheet=await readSheet(file);else if(/\.csv$/i.test(file.name)){const txt=(await file.text()).replace(/^\uFEFF/,'');sheet=csvParse(txt,guessSeparator(txt));}else throw new Error('Usa Excel .xlsx o CSV.');
  const headers=(sheet[0]||[]).map(v=>String(v??'').trim().toLowerCase());
  if(keys.some(k=>headers.filter(h=>h===k).length!==1))throw new Error('Usa la plantilla: faltan columnas requeridas o están duplicadas.');
  const data=sheet.slice(1).filter(r=>r.some(v=>String(v??'').trim()!=='')).map(r=>normalizeRow(Object.fromEntries(keys.map(k=>[k,r[headers.indexOf(k)]]))));
  if(!data.length||data.length>100)throw new Error('Se admiten de 1 a 100 filas por archivo. Divide archivos mayores.');
  setRows(data);setMessage(data.length+' filas cargadas; revisa antes de guardar.');
 });}
 function template(){const url=URL.createObjectURL(new Blob(['\uFEFF'+keys.join(';')+'\nCocina industrial;COC-001;;UF;10;net;vat19\nAccesorio;ACC-001;;CLP;11900;gross;vat19\n'],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download='precios_clp_uf.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}
 function preview(e){e.preventDefault();setReview(null);run(async()=>{
  const body={rows:mode==='manual'?[normalizeRow(form)]:rows,...(date?{date}:{})};
  const result=await request('/currency/product-drafts/preview',{method:'POST',body});
  setReview({...body,expected:result.rows,request_id:newId()});
 });}
 function save(){if(!review||busy)return;run(async()=>{
  const result=await request('/currency/product-drafts',{method:'POST',body:review});
  setReview(null);setMessage(result.saved+' borradores guardados. No se modificó el inventario ni el precio vigente.');await loadHistory();
 });}
 const table=(data)=><div className="table-wrap"><table><thead><tr><th>Producto / código</th><th>Original</th><th>Base / impuesto</th><th>Final</th><th>CLP referencial</th><th>Catálogo</th></tr></thead><tbody>{data.map((r,i)=><tr key={r.id??i}><td>{r.product_name}<small className="sub">{r.sku||r.barcode}</small></td><td>{r.source_amount} {r.price_unit}</td><td>{r.source_basis==='net'?'Neto':'Final'} · {({vat19:'IVA 19%',exempt:'Exento',non_taxable:'No afecto'})[r.tax_treatment]}</td><td>{r.final_amount} {r.price_unit}</td><td>{r.reference_clp==null?'Sin conversión':pesos(Number(r.reference_clp))}{r.reference_date&&<small className="sub">{r.reference_date} · UF #{r.uf_rate_id}</small>}</td><td>{r.product_id?'Existente #'+r.product_id:'Nuevo (borrador)'}</td></tr>)}</tbody></table></div>;
 return <><section className="panel pad"><h3>Preparación de precios de productos</h3><p className="warning-box">Borradores únicamente. No se crean productos vendibles ni se actualizan sus precios o existencias. UF operativa queda bloqueada hasta integrar ventas y cotizaciones.</p>
 {error&&<p role="alert">{error}</p>}{message&&<p role="status">{message}</p>}
 <div className="inline-actions"><button type="button" className="ghost" disabled={busy} onClick={()=>{setMode('manual');setReview(null);}}>Ingreso manual</button><button type="button" className="ghost" disabled={busy} onClick={()=>{setMode('file');setReview(null);}}>Excel / CSV</button></div>
 <form className="formstack" onSubmit={preview}>
 {mode==='manual'?<><label>Producto<input required maxLength={120} value={form.product_name} disabled={busy} onChange={e=>change('product_name',e.target.value)}/></label>
 <div className="field-row"><label>SKU<input maxLength={50} value={form.sku} disabled={busy} onChange={e=>change('sku',e.target.value)}/></label><label>Código de barras<input maxLength={50} value={form.barcode} disabled={busy} onChange={e=>change('barcode',e.target.value)}/></label></div>
 <label>Unidad comercial<select value={form.price_unit} disabled={busy} onChange={e=>change('price_unit',e.target.value)}><option value="CLP">CLP</option><option value="UF">UF</option></select></label>
 <label>Precio original<input required inputMode="decimal" value={form.source_amount} disabled={busy} onChange={e=>change('source_amount',e.target.value)} placeholder="Sin separadores de miles"/></label>
 <label>Base del precio<select required value={form.source_basis} disabled={busy} onChange={e=>change('source_basis',e.target.value)}><option value="">Selecciona</option><option value="net">Neto, sin IVA</option><option value="gross">Final, incluye IVA cuando corresponde</option></select></label>
 <label>Tratamiento tributario<select required value={form.tax_treatment} disabled={busy} onChange={e=>change('tax_treatment',e.target.value)}><option value="">Selecciona</option><option value="vat19">Afecto IVA 19%</option><option value="exempt">Exento</option><option value="non_taxable">No afecto</option></select></label></>:<><button type="button" className="ghost" disabled={busy} onClick={template}>Descargar plantilla</button><label>Archivo (máximo 100 filas)<input type="file" accept=".xlsx,.csv" disabled={busy} onChange={load}/></label><small>Una unidad por fila: CLP o UF. Precios sin separadores de miles; UF con hasta 4 decimales. {rows.length} filas cargadas.</small></>}
 <label>Fecha UF referencial (opcional)<input type="date" max={today||undefined} value={date} disabled={busy} onChange={e=>{setDate(e.target.value);setReview(null);}}/></label>
 <button className="primary" disabled={busy||(mode==='file'&&!rows.length)}>Revisar precios</button></form>
 {review&&<><h4>Vista previa del servidor</h4>{table(review.expected)}<button type="button" className="primary" disabled={busy} onClick={save}>Confirmar / guardar borradores</button><p className="helper">Si falla la comunicación, reintenta el mismo guardado antes de editar el lote.</p></>}
 </section><section className="panel pad"><h3>Últimos borradores</h3>{history.length?table(history):<p className="muted">Sin borradores registrados.</p>}</section></>;
}
