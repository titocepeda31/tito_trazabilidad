import React,{useEffect,useRef,useState} from 'react';
import {pesos,qtyFormat,newId} from './api.js';

const payments=[['efectivo','Efectivo'],['transferencia','Transferencia'],['tarjeta','Tarjeta'],['otro','Otro']];
function SeriesPicker({item,warehouse,request,value,onChange,disabled}){
  const required=Number(item.quantity);
  const [query,setQuery]=useState(''),[rows,setRows]=useState([]),[loading,setLoading]=useState(false),[error,setError]=useState(''),[more,setMore]=useState(false);
  const generation=useRef(0);
  async function load(){
    const n=++generation.current;setLoading(true);setError('');
    try{
      const r=await request('/sales/serial-available?product_id='+Number(item.product_id)+'&warehouse_id='+warehouse+'&q='+encodeURIComponent(query.trim()));
      if(n!==generation.current)return;setRows(r.units||[]);setMore(Boolean(r.more));
    }catch(e){if(n===generation.current){setRows([]);setMore(false);setError(e.message);}}
    finally{if(n===generation.current)setLoading(false);}
  }
  useEffect(()=>{void load();return()=>{generation.current++;};},[]);
  function toggle(number){
    if(disabled)return;
    if(value.includes(number))onChange(value.filter(n=>n!==number));
    else if(value.length<required)onChange([...value,number]);
  }
  const options=[...new Set([...value,...rows.map(r=>r.serial_number)])];
  return <section className="panel pad formstack">
    <div className="panel-heading"><div><h3>{item.product_name_snapshot}</h3><p>{qtyFormat(required)} unidades cotizadas · {pesos(item.unit_price_clp)} c/u</p></div><span className={'pill '+(value.length===required?'green':'amber')}>{value.length} / {required} series</span></div>
    {required>500||!Number.isInteger(required)?<p className="warning-box">Esta cantidad no se puede despachar por series en una sola operación (máximo 500 unidades enteras por línea).</p>:<>
      <label>Buscar número de serie<input type="search" maxLength={120} value={query} disabled={disabled} onChange={e=>setQuery(e.target.value)} onKeyDown={e=>{if(e.key==='Enter'){e.preventDefault();void load();}}}/></label>
      <button type="button" className="ghost" disabled={disabled||loading} onClick={load}>{loading?'Consultando…':'Buscar / actualizar series'}</button>
      {error&&<p className="warning-box" role="alert">{error}</p>}
      {more&&<p className="helper">Hay más de 500 coincidencias. Acota la búsqueda; las selecciones se mantienen.</p>}
      {value.length>0&&<p className="helper">Seleccionadas: {value.join(', ')}</p>}
      <div style={{maxHeight:260,overflowY:'auto'}}>{options.map(number=><label key={number} style={{display:'flex',alignItems:'center',gap:10,padding:'6px 0'}}><input type="checkbox" checked={value.includes(number)} disabled={disabled||(!value.includes(number)&&value.length>=required)} onChange={()=>toggle(number)}/><span>{number}</span></label>)}</div>
      {!loading&&!error&&!options.length&&<p className="helper">No hay series en stock que coincidan.</p>}
      <p className="helper">La lista muestra unidades físicas en stock. Al confirmar se verifica además la disponibilidad libre de reservas y la ubicación real.</p>
    </>}
  </section>;
}
export default function QuoteConversionPanel({data,request,notify,locations,initialLocation,onBack,onDone,onCheck,mode='sale'}){
  const isReserve=mode==='reserve',isDispatch=mode==='dispatch';
  const [warehouse,setWarehouse]=useState(Number(isDispatch?data.reservation?.warehouse_id:initialLocation)||0),[payment,setPayment]=useState('efectivo'),[series,setSeries]=useState({});
  const [busy,setBusy]=useState(false),[error,setError]=useState(''),[uncertain,setUncertain]=useState(false),[confirmedSale,setConfirmedSale]=useState(null);
  const saving=useRef(false),reserveRequest=useRef(null);
  const [note,setNote]=useState('');
  const serialLines=data.items.filter(i=>Number(i.serial_control)===1);
  const eligible=serialLines.length===data.items.length&&serialLines.length>0&&serialLines.every(i=>Number.isInteger(Number(i.quantity))&&Number(i.quantity)>0&&Number(i.quantity)<=500);
  const complete=isReserve?eligible:serialLines.every(i=>Number.isInteger(Number(i.quantity))&&Number(i.quantity)<=500&&Number(i.quantity)>0&&(series[i.product_id]||[]).length===Number(i.quantity));
  function changeWarehouse(v){
    if(Object.values(series).some(a=>a.length)&&!window.confirm('Cambiar de bodega eliminará las series seleccionadas. ¿Continuar?'))return;
    setWarehouse(Number(v));setSeries({});setError('');
  }
  async function submit(){
    if(saving.current||!complete||!warehouse||confirmedSale||!data.quote.client_id)return;
    if(!uncertain&&!window.confirm(isReserve?'¿Reservar todas las unidades de la cotización #'+data.quote.id+'? No se cobrará ni descontará stock físico.':'¿Confirmar la venta completa de la cotización #'+data.quote.id+' por '+pesos(data.quote.total_clp)+'? Verifica que las series correspondan a los equipos que entregarás.'))return;
    saving.current=true;setBusy(true);setError('');
    try{
      let body;
      if(isReserve){reserveRequest.current??=newId();body={request_id:reserveRequest.current,revision:Number(data.quote.revision),warehouse_id:warehouse,note:note.trim()};}
      else body={warehouse_id:warehouse,payment_method:payment,...(isDispatch?{reservation_id:Number(data.reservation.id)}:{}),serial_items:serialLines.map(i=>({product_id:Number(i.product_id),serial_numbers:series[i.product_id]||[]}))};
      const result=await request('/quotes/'+data.quote.id+(isReserve?'/reserve':'/convert'),{method:'POST',body});
      const id=isReserve?result.reservation_id:result.sale_id;
      if(!result.ok||!id)throw new Error('Respuesta no confirmada.');
      setConfirmedSale(Number(id));setUncertain(false);
      notify((isReserve?'Reserva #':'Venta #')+id+(result.already_recorded?' ya registrada.':' registrada desde cotización.'),'success');
      await onDone();
    }catch(e){
      const unknown=!(e.status>=400&&e.status<500&&![408,429].includes(e.status));
      if(!unknown)reserveRequest.current=null;
      setUncertain(unknown);setError(unknown?'Resultado incierto. Reintenta con la misma selección o consulta la cotización antes de continuar.':e.message);
    }finally{saving.current=false;setBusy(false);}
  }
  const locked=busy||uncertain||Boolean(confirmedSale);
  return <div className="quotes-page">
    <div className="section-intro"><div><button type="button" className="text-button" disabled={busy||uncertain||Boolean(confirmedSale)} onClick={onBack}>← Volver a cotización</button><p className="eyebrow">VENTAS / COTIZACIONES</p><h2>{isReserve?'Preparar reserva':isDispatch?'Despachar reserva #'+data.reservation.id:'Preparar venta'} · Cotización #{data.quote.id}</h2><p className="muted">{data.quote.customer_name}{data.quote.customer_rut?' · '+data.quote.customer_rut:''}</p></div></div>
    <section className="panel pad formstack">
      <h3>{isReserve?'Condiciones de reserva':'Condiciones de venta'}</h3>
      <label>Bodega / sucursal de venta<select value={warehouse||''} disabled={locked||isDispatch} onChange={e=>changeWarehouse(e.target.value)}><option value="">Selecciona bodega</option>{isDispatch?<option value={warehouse}>{data.reservation.warehouse_name||'Bodega #'+warehouse}</option>:locations.map(w=><option key={w.id} value={w.id}>{w.name}</option>)}</select></label>
      {isReserve?<label>Observación (opcional)<input value={note} maxLength={250} disabled={locked} onChange={e=>setNote(e.target.value)}/></label>:<label>Medio de pago<select value={payment} disabled={locked} onChange={e=>setPayment(e.target.value)}>{payments.map(([v,l])=><option value={v} key={v}>{l}</option>)}</select></label>}
      <div className="table-wrap"><table><thead><tr><th>Producto</th><th>Cantidad</th><th>Precio pactado</th><th>Subtotal</th></tr></thead><tbody>{data.items.map(i=><tr key={i.id}><td>{i.product_name_snapshot}{Number(i.serial_control)===1&&<small className="sub">Requiere series</small>}</td><td>{qtyFormat(i.quantity)} {i.sale_unit}</td><td>{pesos(i.unit_price_clp)}</td><td>{pesos(i.subtotal_clp)}</td></tr>)}</tbody></table></div>
      <div className="receipt-total"><span>Total acordado</span><strong>{pesos(data.quote.total_clp)}</strong></div><p className="helper">Precios y cantidades de la cotización no editables. {isReserve?'Se compromete stock libre por cantidad, sin cobro ni salida física.':'La venta completa requiere caja habilitada.'}</p>
    </section>
    {!isReserve&&warehouse>0&&serialLines.map(i=><SeriesPicker key={warehouse+'-'+i.product_id} item={i} warehouse={warehouse} request={request} value={series[i.product_id]||[]} onChange={v=>setSeries(old=>({...old,[i.product_id]:v}))} disabled={locked}/>)}
    <section className="panel pad formstack">
      {error&&<p role="alert" className="warning-box">{error}</p>}
      {confirmedSale?<><strong>{isReserve?'Reserva':'Venta'} #{confirmedSale} registrada.</strong><button type="button" className="primary" disabled={busy} onClick={onCheck}>Consultar cotización</button></>:<>
      {!complete&&<p className="helper">{isReserve?'Solo cotizaciones íntegramente serializadas de hasta 500 unidades por línea.':'Selecciona exactamente todas las series requeridas.'}</p>}
      <button type="button" className="primary wide" disabled={busy||!complete||!warehouse||!data.quote.client_id} onClick={submit}>{busy?'Confirmando…':uncertain?'Reintentar la misma operación':isReserve?'Confirmar reserva':'Confirmar venta y descontar stock'}</button>
      {(uncertain||error)&&<button type="button" className="ghost" disabled={busy} onClick={onCheck}>Consultar estado de cotización</button>}
      <p className="helper">{isReserve?'La reserva conserva los precios acordados hasta despacho o cancelación. No genera venta, cobro ni DTE.':'La confirmación registra venta y caja, series y movimientos en una sola transacción. No emite DTE.'}</p></>}
    </section>
  </div>;
}
