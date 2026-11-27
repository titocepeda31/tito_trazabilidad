import React,{useEffect,useState,useRef} from 'react';
import {pesos,newId} from './api';
import QuoteClientPicker from './QuoteClientPicker.jsx';
export default function RoleQuoteForm({request,onSaved}){
 const [products,setProducts]=useState([]),[lines,setLines]=useState([{id:'',qty:'1'}]),[error,setError]=useState(''),[busy,setBusy]=useState(false),[ready,setReady]=useState(false);
 const rid=useRef(null);const [customer,setCustomer]=useState(null);
 useEffect(()=>{let alive=true;request('/products').then(d=>{if(alive){setProducts(d.products||[]);setReady(true);}}).catch(e=>{if(alive)setError(e.message);});return()=>{alive=false;};},[]);
 function update(i,key,value){setLines(old=>old.map((r,j)=>j===i?{...r,[key]:value}:r));rid.current=null;}
 const total=lines.reduce((t,l)=>t+Math.round(Number(products.find(p=>String(p.id)===l.id)?.price_clp||0)*Number(l.qty||0)),0);
 async function save(e){e.preventDefault();if(busy)return;setError('');
 const f=new FormData(e.currentTarget),items=lines.map(l=>({product_id:Number(l.id),quantity:Number(l.qty)}));
 if(items.some(i=>!i.product_id||!Number.isFinite(i.quantity)||i.quantity<=0)||new Set(items.map(i=>i.product_id)).size!==items.length){setError('Selecciona productos distintos y cantidades positivas.');return;}
 if(!customer?.id||!f.get('valid_until')){setError('Ingresa el cliente y la fecha de validez.');return;}
 setBusy(true);try{rid.current??=newId();await request('/quotes',{method:'POST',body:{request_id:rid.current,client_id:Number(customer.id),customer_contact:String(f.get('customer_contact')||''),valid_until:f.get('valid_until'),notes:String(f.get('notes')||''),items}});onSaved();}
 catch(e){setError(e.message);}finally{setBusy(false);}}
 return <form className="formstack panel pad role-form" onSubmit={save} onChange={()=>{rid.current=null;}} noValidate>
 <h3>Nueva cotización · precio de lista</h3>{error&&<p className="role-error" role="alert">{error}</p>}
 <QuoteClientPicker request={request} value={customer} onChange={c=>{setCustomer(c);rid.current=null;}} disabled={busy}/><label>Contacto<input name="customer_contact" maxLength={120} disabled={busy}/></label><label>Válida hasta<input type="date" name="valid_until" required disabled={busy}/></label><label>Observaciones<input name="notes" maxLength={500} disabled={busy}/></label>
 {lines.map((l,i)=><div className="role-quote-line" key={i}><label>Producto<select value={l.id} onChange={e=>update(i,'id',e.target.value)} disabled={busy||!ready}><option value="">Seleccionar</option>{products.map(p=><option value={p.id} key={p.id}>{p.name} · {pesos(p.price_clp)}</option>)}</select></label><label>Cantidad<input type="number" min="0.001" step="0.001" value={l.qty} onChange={e=>update(i,'qty',e.target.value)} disabled={busy}/></label><button type="button" className="ghost mini" disabled={busy||lines.length===1} onClick={()=>{setLines(s=>s.filter((_,j)=>i!==j));rid.current=null;}}>Quitar</button></div>)}
 <button type="button" className="ghost" disabled={busy||lines.length>=50} onClick={()=>{setLines(s=>[...s,{id:'',qty:'1'}]);rid.current=null;}}>+ Agregar producto</button><strong>Total estimado: {pesos(total)}</strong><p className="helper">La API calcula el precio vigente y valida las cantidades. No se permiten descuentos ni conversión a venta.</p><button className="primary" disabled={busy||!ready}>{busy?'Guardando…':'Guardar cotización'}</button></form>;
}
