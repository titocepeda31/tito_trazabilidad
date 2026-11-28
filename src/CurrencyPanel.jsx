import React,{useEffect,useState} from 'react';
import {pesos} from './api';
import ProductPriceDraftPanel from './ProductPriceDraftPanel.jsx';

export default function CurrencyPanel({request}){
 const [checking,setChecking]=useState(false);
 const [settings,setSettings]=useState(null),[date,setDate]=useState(''),[today,setToday]=useState(''),[rate,setRate]=useState(null),[value,setValue]=useState(''),[source,setSource]=useState(''),[amount,setAmount]=useState(''),[preview,setPreview]=useState(null),[busy,setBusy]=useState(false),[message,setMessage]=useState(''),[error,setError]=useState('');
 useEffect(()=>{let active=true;request('/currency/settings').then(r=>{if(active){setSettings({...r.settings,allow_both:Number(r.settings.allow_both)===1});setDate(r.today);setToday(r.today);}}).catch(e=>{if(active)setError(e.message);});return()=>{active=false;};},[request]);
 useEffect(()=>{if(!date)return;let active=true;setRate(null);setPreview(null);setError('');setMessage('');setChecking(true);
  request('/currency/uf?date='+encodeURIComponent(date)).then(async r=>{
   if(!active)return;
   if(r.rate){setRate(r.rate);return;}
   const data=await request('/currency/uf/sync',{method:'POST',body:{date,refresh:false}});
   if(active)setRate(data.rate);
  }).catch(e=>{if(active)setError(e.message);}).finally(()=>{if(active)setChecking(false);});
  return()=>{active=false;};},[date,request]);
 async function run(action){setBusy(true);setError('');setMessage('');try{await action();}catch(e){setError(e.message);}finally{setBusy(false);}}
 function saveSettings(e){e.preventDefault();run(async()=>{const r=await request('/currency/settings',{method:'POST',body:{default_unit:settings.default_unit,allow_both:settings.allow_both}});setSettings({...r.settings,allow_both:Number(r.settings.allow_both)===1});setMessage('Preferencia guardada.');});}
 function refreshOfficial(){run(async()=>{const r=await request('/currency/uf/sync',{method:'POST',body:{date,refresh:true}});setRate(r.rate);setPreview(null);setMessage(r.cached?'Valor oficial ya registrado.':'Valor oficial registrado como nueva versión.');});}
 function saveRate(e){e.preventDefault();run(async()=>{const r=await request('/currency/uf',{method:'POST',body:{date,clp_per_uf:value.trim().replace(',','.'),source_reference:source}});setRate(r.rate);setPreview(null);setValue('');setSource('');setMessage('Valor registrado como nueva versión.');});}
 function convert(e){e.preventDefault();run(async()=>{setPreview(null);const r=await request('/currency/preview',{method:'POST',body:{date,amount:amount.trim().replace(',','.')}});setPreview(r);});}
 return <><div className="section-intro"><div><p className="eyebrow">CONFIGURACIÓN COMERCIAL</p><h2>Pesos y Unidad de Fomento</h2><p className="muted">Preferencias y valores diarios por empresa.</p></div></div>
 <section className="panel pad"><p className="warning-box">Fase de preparación: la configuración no modifica el catálogo, cotizaciones, ventas ni DTE. La UF se consulta al seleccionar la fecha si no existe registro. El ingreso manual queda como respaldo.</p>
 {error&&<p role="alert">{error}</p>}{message&&<p role="status">{message}</p>}
 {settings&&<form className="formstack" onSubmit={saveSettings}><h3>Preferencias</h3><label>Unidad predeterminada<select value={settings.default_unit} disabled={busy} onChange={e=>setSettings({...settings,default_unit:e.target.value})}><option value="CLP">Pesos chilenos (CLP)</option><option value="UF">UF</option></select></label><label><input type="checkbox" checked={settings.allow_both} disabled={busy} onChange={e=>setSettings({...settings,allow_both:e.target.checked})}/> Permitir ambas unidades (cuando se habiliten operaciones)</label><button className="primary" disabled={busy}>Guardar preferencia</button></form>}
 </section><section className="panel pad"><h3>Valor diario de la UF</h3><label>Fecha de referencia<input type="date" value={date} max={today} disabled={busy} onChange={e=>{setDate(e.target.value);setRate(null);setPreview(null);}}/></label>
 {checking?<p role="status">Consultando valor UF…</p>:rate?<p>UF registrada: {rate.clp_per_uf} CLP · versión {rate.id} · {rate.source_reference}</p>:<p className="muted">Sin valor registrado para la fecha.</p>}
 <button type="button" className="ghost" disabled={busy||checking||!date} onClick={refreshOfficial}>Actualizar desde Banco Central</button>
 <form className="formstack" onSubmit={saveRate}><label>CLP por UF<input required inputMode="decimal" value={value} onChange={e=>setValue(e.target.value)} placeholder="40000,00" disabled={busy}/></label><label>Fuente oficial / referencia<input required maxLength={250} value={source} onChange={e=>setSource(e.target.value)} disabled={busy}/></label><button className="primary" disabled={busy||checking||!date}>Registrar respaldo manual</button></form></section>
 <section className="panel pad"><h3>Simular conversión</h3><form className="formstack" onSubmit={convert}><label>Monto UF<input required inputMode="decimal" value={amount} disabled={busy} onChange={e=>{setAmount(e.target.value);setPreview(null);}}/></label><button className="primary" disabled={busy||checking||!rate}>Calcular</button></form>{preview&&<p role="status"><strong>{preview.amount} UF = {pesos(preview.total_clp)}</strong> · {preview.rate.rate_on} · versión {preview.rate.id}. Solo referencia.</p>}</section>{settings&&<ProductPriceDraftPanel request={request} today={today} defaultUnit={settings.default_unit}/>}</>;
}
