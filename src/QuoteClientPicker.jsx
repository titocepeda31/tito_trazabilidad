import React,{useEffect,useRef,useState} from 'react';
export default function QuoteClientPicker({request,value,onChange,disabled=false}){
  const [query,setQuery]=useState(''),[rows,setRows]=useState([]),[busy,setBusy]=useState(false),[error,setError]=useState(''),[more,setMore]=useState(false);
  const sequence=useRef(0);
  async function search(){
    const n=++sequence.current;setBusy(true);setError('');
    try{const r=await request('/quotes/clients?q='+encodeURIComponent(query.trim()));if(n!==sequence.current)return;setRows(r.clients||[]);setMore(Boolean(r.more));}
    catch(e){if(n===sequence.current){setRows([]);setMore(false);setError(e.message);}}
    finally{if(n===sequence.current)setBusy(false);}
  }
  useEffect(()=>{void search();return()=>{sequence.current++;};},[]);
  const options=value&&!rows.some(c=>String(c.id)===String(value.id))?[value,...rows]:rows;
  return <div className="formstack quote-client-picker">
    <label>Buscar cliente por nombre o RUT<input type="search" value={query} disabled={disabled} maxLength={150} placeholder="Nombre o RUT" onChange={e=>setQuery(e.target.value)} onKeyDown={e=>{if(e.key==='Enter'){e.preventDefault();void search();}}}/></label>
    <button type="button" className="ghost" disabled={disabled||busy} onClick={search}>{busy?'Buscando…':'Buscar cliente'}</button>
    <label>Cliente registrado<select required value={value?String(value.id):''} disabled={disabled} onChange={e=>onChange(options.find(c=>String(c.id)===e.target.value)||null)}><option value="">Selecciona un cliente</option>{options.map(c=><option key={c.id} value={c.id}>{c.name}{c.rut?' · '+c.rut:''}</option>)}</select></label>
    {error&&<p role="alert" className="warning-box">{error}</p>}
    {more&&<p className="helper">Se muestran hasta 50 coincidencias. Acota la búsqueda.</p>}
    {!busy&&!error&&!options.length&&<p className="helper">No se encontraron clientes activos. Si no existe, regístralo desde el módulo Clientes.</p>}
  </div>;
}
