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
  const [dueDate,setDueDate]=useState(''),[initialAmount,setInitialAmount]=useState('0'),[initialMethod,setInitialMethod]=useState('transferencia'),[initialRef,setInitialRef]=useState('');
  const today=new Date().toLocaleDateString('sv-SE',{timeZone:'America/Santiago'});
  const [expiresOn,setExpiresOn]=useState(data.quote.valid_until||today);
  const pending=Object.fromEntries((data.reservation?.items||[]).map(i=>[Number(i.product_id),Number(i.remaining_quantity)]));
  const delivered=Object.fromEntries((data.reservation?.items||[]).map(i=>[Number(i.product_id),Number(i.dispatched_quantity)]));
  const [quantities,setQuantities]=useState(()=>Object.fromEntries((data.reservation?.items||[]).map(i=>[Number(i.product_id),String(i.remaining_quantity)])));
  const dispatchRequest=useRef(null);
  const activeItems=isDispatch?data.items.filter(i=>Number(quantities[i.product_id]||0)>0).map(i=>({...i,quantity:Number(quantities[i.product_id])})):data.items;
  const serialLines=activeItems.filter(i=>Number(i.serial_control)===1);
  const validDispatch=!isDispatch||(activeItems.length>0&&data.items.every(i=>{const n=Number(quantities[i.product_id]||0);return Number.isFinite(n)&&n>=0&&n<=Number(pending[i.product_id]||0)&&Math.abs(n*1000-Math.round(n*1000))<=0.00001&&(i.sale_unit!=='unidad'||Number.isInteger(n));}));
  function dispatchSubtotal(i){
    const before=Number(delivered[i.product_id]||0),qty=Number(quantities[i.product_id]||0),price=Number(i.unit_price_clp);
    const after=Math.round((before+qty)*1000)/1000;
    return (Math.abs(after-Number(i.quantity))<0.00001?Number(i.subtotal_clp):Math.round(after*price))-Math.round(before*price);
  }
  const dispatchTotal=isDispatch?data.items.reduce((sum,i)=>sum+dispatchSubtotal(i),0):Number(data.quote.total_clp);
  function changeQty(pid,v){setQuantities(old=>({...old,[pid]:v}));setSeries(old=>({...old,[pid]:[]}));setError('');}
  const advanceAvailable=Number(data.advances?.available_clp||0);
  const advanceApply=Math.min(Math.max(0,dispatchTotal),advanceAvailable);
  const creditAllowed=!isReserve&&activeItems.length>0&&dispatchTotal>0;
  const isCredit=payment==='credito';
  const creditValid=!isCredit||(creditAllowed&&dueDate>=today&&Number.isSafeInteger(Number(initialAmount))&&Number(initialAmount)>=0&&Number(initialAmount)+advanceApply<=dispatchTotal&&(advanceApply>0||Number(initialAmount)<dispatchTotal));
  const eligible=data.items.length>0&&data.items.every(i=>{const n=Number(i.quantity),controlled=Number(i.serial_control)===1;return Number.isFinite(n)&&n>0&&n<=1000000000&&Math.abs(n*1000-Math.round(n*1000))<=0.00001&&(i.sale_unit!=='unidad'||Number.isInteger(n))&&(!controlled||(i.sale_unit==='unidad'&&n<=500));});
  const complete=isReserve?eligible&&Boolean(expiresOn&&expiresOn>=today):validDispatch&&creditValid&&(!advanceApply||isCredit)&&serialLines.every(i=>Number.isInteger(Number(i.quantity))&&Number(i.quantity)<=500&&Number(i.quantity)>0&&(series[i.product_id]||[]).length===Number(i.quantity));
  function changeWarehouse(v){
    if(Object.values(series).some(a=>a.length)&&!window.confirm('Cambiar de bodega eliminará las series seleccionadas. ¿Continuar?'))return;
    setWarehouse(Number(v));setSeries({});setError('');
  }
  async function submit(){
    if(saving.current||!complete||!warehouse||confirmedSale||!data.quote.client_id)return;
    if(!uncertain&&!window.confirm(isReserve?'¿Reservar todas las unidades de la cotización #'+data.quote.id+'? No se cobrará ni descontará stock físico.':'¿Confirmar '+(isDispatch?'este despacho':'la venta completa')+' de la cotización #'+data.quote.id+' por '+pesos(dispatchTotal)+'? '+(isCredit?'Se registrará una cuenta por cobrar, con abono recibido de '+pesos(initialAmount)+'.':'Verifica cantidades y, si corresponde, las series de los equipos que entregarás.')))return;
    saving.current=true;setBusy(true);setError('');
    try{
      let body;
      if(isReserve){reserveRequest.current??=newId();body={request_id:reserveRequest.current,revision:Number(data.quote.revision),warehouse_id:warehouse,note:note.trim(),expires_on:expiresOn};}
      else {
        if(isDispatch)dispatchRequest.current??=newId();
        body={warehouse_id:warehouse,payment_method:payment,...(isCredit?{due_date:dueDate,advance_apply_clp:advanceApply,initial_payment_clp:Number(initialAmount),...(Number(initialAmount)>0?{initial_payment_method:initialMethod,initial_payment_reference:initialRef.trim()||null}:{})}:{}),...(isDispatch?{reservation_id:Number(data.reservation.id),request_id:dispatchRequest.current,revision:Number(data.quote.revision),dispatch_items:activeItems.map(i=>({product_id:Number(i.product_id),quantity:Number(i.quantity)}))}:{}),serial_items:serialLines.map(i=>({product_id:Number(i.product_id),serial_numbers:series[i.product_id]||[]}))};
      }
      const result=await request('/quotes/'+data.quote.id+(isReserve?'/reserve':'/convert'),{method:'POST',body});
      const id=isReserve?result.reservation_id:result.sale_id;
      if(!result.ok||!id)throw new Error('Respuesta no confirmada.');
      setConfirmedSale(Number(id));setUncertain(false);
      notify((isReserve?'Reserva #':'Venta #')+id+(result.already_recorded?' ya registrada.':' registrada desde cotización.'),'success');
      await onDone();
    }catch(e){
      const unknown=!(e.status>=400&&e.status<500&&![408,429].includes(e.status));
      if(!unknown){reserveRequest.current=null;dispatchRequest.current=null;}
      setUncertain(unknown);setError(unknown?'Resultado incierto. Reintenta con la misma selección o consulta la cotización antes de continuar.':e.message);
    }finally{saving.current=false;setBusy(false);}
  }
  const locked=busy||uncertain||Boolean(confirmedSale);
  return <div className="quotes-page">
    <div className="section-intro"><div><button type="button" className="text-button" disabled={busy||uncertain||Boolean(confirmedSale)} onClick={onBack}>← Volver a cotización</button><p className="eyebrow">VENTAS / COTIZACIONES</p><h2>{isReserve?'Preparar reserva':isDispatch?'Despachar reserva #'+data.reservation.id:'Preparar venta'} · Cotización #{data.quote.id}</h2><p className="muted">{data.quote.customer_name}{data.quote.customer_rut?' · '+data.quote.customer_rut:''}</p></div></div>
    <section className="panel pad formstack">
      <h3>{isReserve?'Condiciones de reserva':'Condiciones de venta'}</h3>
      <label>Bodega / sucursal de venta<select value={warehouse||''} disabled={locked||isDispatch} onChange={e=>changeWarehouse(e.target.value)}><option value="">Selecciona bodega</option>{isDispatch?<option value={warehouse}>{data.reservation.warehouse_name||'Bodega #'+warehouse}</option>:locations.map(w=><option key={w.id} value={w.id}>{w.name}</option>)}</select></label>
      {isReserve?<><label>Reservar hasta (inclusive)<input type="date" required min={today} value={expiresOn} disabled={locked} onChange={e=>setExpiresOn(e.target.value)}/></label><p className="helper">El saldo no entregado queda libre desde el día siguiente, según hora de Chile.</p><label>Observación (opcional)<input value={note} maxLength={250} disabled={locked} onChange={e=>setNote(e.target.value)}/></label></>:<><label>Condición de pago<select value={payment} disabled={locked} onChange={e=>setPayment(e.target.value)}>{payments.map(([v,l])=><option value={v} key={v}>{l} · contado</option>)}<option value="credito" disabled={!creditAllowed}>A plazo / liquidar anticipo</option></select></label>{!creditAllowed&&<p className="helper">Pago a plazo requiere una entrega con importe positivo.</p>}{advanceApply>0&&<p className="warning-box">Anticipo disponible {pesos(advanceAvailable)}. Se aplicará {pesos(advanceApply)} a esta entrega. Selecciona A plazo / liquidar anticipo, aunque el saldo quede en cero.</p>}{isCredit&&<><label>Fecha de compromiso de pago<input type="date" required min={today} value={dueDate} disabled={locked} onChange={e=>setDueDate(e.target.value)}/></label><label>Abono efectivamente recibido al entregar (CLP)<input type="number" min="0" max={Math.max(0,dispatchTotal-advanceApply-(advanceApply?0:1))} step="1" value={initialAmount} disabled={locked} onChange={e=>setInitialAmount(e.target.value)}/></label>{Number(initialAmount)>0&&<><label>Medio del abono<select value={initialMethod} disabled={locked} onChange={e=>setInitialMethod(e.target.value)}>{payments.map(([v,l])=><option key={v} value={v}>{l}</option>)}</select></label><label>Referencia del abono<input value={initialRef} maxLength={120} disabled={locked} onChange={e=>setInitialRef(e.target.value)}/></label></>}<div className="receipt-total"><span>Saldo por cobrar</span><strong>{pesos(dispatchTotal-advanceApply-Number(initialAmount||0))}</strong></div><p className="helper">Solo registra el dinero recibido en esta entrega; no es un anticipo anterior ni una factura tributaria. La cuenta queda disponible en Clientes → Historial → Cuentas por cobrar.</p></>}</>}
      <div className="table-wrap"><table><thead><tr><th>Producto</th><th>Cantidad cotizada</th>{isDispatch&&<><th>Entregado</th><th>Pendiente</th><th>Entregar ahora</th></>}<th>Precio pactado</th><th>Subtotal {isDispatch?'entrega':''}</th></tr></thead><tbody>{data.items.map(i=><tr key={i.id}><td>{i.product_name_snapshot}{Number(i.serial_control)===1&&<small className="sub">Requiere series</small>}</td><td>{qtyFormat(i.quantity)} {i.sale_unit}</td>{isDispatch&&<><td>{qtyFormat(delivered[i.product_id]||0)}</td><td>{qtyFormat(pending[i.product_id]||0)}</td><td><input aria-label={'Cantidad a entregar de '+i.product_name_snapshot} type="number" min="0" max={pending[i.product_id]||0} step={i.sale_unit==='unidad'?'1':'0.001'} disabled={locked||!pending[i.product_id]} value={quantities[i.product_id]??'0'} onChange={e=>changeQty(i.product_id,e.target.value)}/></td></>}<td>{pesos(i.unit_price_clp)}</td><td>{pesos(isDispatch?dispatchSubtotal(i):i.subtotal_clp)}</td></tr>)}</tbody></table></div>
      <div className="receipt-total"><span>{isDispatch?'Total de esta entrega':'Total acordado'}</span><strong>{pesos(dispatchTotal)}</strong></div><p className="helper">{isDispatch?'Puedes entregar una parte o todo el saldo. Solo se registra la venta de esta entrega, a precio pactado; el resto continúa reservado.':isReserve?'Precios y cantidades fijos. Se compromete stock libre sin cobro ni salida física.':'Precios y cantidades fijos; la venta completa requiere caja habilitada.'}</p>
    </section>
    {!isReserve&&warehouse>0&&serialLines.map(i=><SeriesPicker key={warehouse+'-'+i.product_id} item={i} warehouse={warehouse} request={request} value={series[i.product_id]||[]} onChange={v=>setSeries(old=>({...old,[i.product_id]:v}))} disabled={locked}/>)}
    <section className="panel pad formstack">
      {error&&<p role="alert" className="warning-box">{error}</p>}
      {confirmedSale?<><strong>{isReserve?'Reserva':'Venta'} #{confirmedSale} registrada.</strong><button type="button" className="primary" disabled={busy} onClick={onCheck}>Consultar cotización</button></>:<>
      {!complete&&<p className="helper">{isReserve?'Revisa las cantidades: unidades enteras, hasta tres decimales para peso/volumen y máximo 500 equipos por línea serializada.':'Revisa las cantidades a entregar y selecciona exactamente las series requeridas.'}</p>}
      <button type="button" className="primary wide" disabled={busy||!complete||!warehouse||!data.quote.client_id} onClick={submit}>{busy?'Confirmando…':uncertain?'Reintentar la misma operación':isReserve?'Confirmar reserva':'Confirmar venta y descontar stock'}</button>
      {(uncertain||error)&&<button type="button" className="ghost" disabled={busy} onClick={onCheck}>Consultar estado de cotización</button>}
      <p className="helper">{isReserve?'La reserva conserva los precios acordados hasta despacho, cancelación o vencimiento. No genera venta, cobro ni DTE.':isCredit?'Registra venta, cuenta por cobrar, abono recibido (si corresponde) e inventario en una sola transacción. No emite DTE.':'La confirmación registra venta y caja, series y movimientos en una sola transacción. No emite DTE.'}</p></>}
    </section>
  </div>;
}
