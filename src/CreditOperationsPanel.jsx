import React,{useEffect,useState} from 'react';
import {newId,pesos} from './api.js';

const methodNames={efectivo:'Efectivo',transferencia:'Transferencia',tarjeta:'Tarjeta',otro:'Otro'};
const pendingKey='tito_pending_credit_adjustment_v1';
function renderWhen(s){
  if(!s)return '';
  const d=new Date(String(s).replace(' ','T')+'Z');
  return Number.isFinite(d.getTime())?d.toLocaleString('es-CL',{timeZone:'America/Santiago',dateStyle:'short',timeStyle:'short'}):String(s);
}
export default function CreditOperationsPanel({account,request,notify,onChange}){
  const accountId=Number(account.id);
  const [info,setInfo]=useState(null);
  const [loading,setLoading]=useState(false);
  const [busy,setBusy]=useState(false);
  const [kind,setKind]=useState('commercial');
  const [qty,setQty]=useState({});
  const [returnSeries,setReturnSeries]=useState({}),[voidSeries,setVoidSeries]=useState({});
  const availableSeries=i=>(i.serial_units||[]).filter(u=>u.reversal_kind===null&&u.status==='sold');
  function toggleSeries(setter,id,n){setter(old=>({...old,[id]:(old[id]||[]).includes(n)?old[id].filter(v=>v!==n):[...(old[id]||[]),n]}));}
  const [reason,setReason]=useState('');
  const [received,setReceived]=useState(false);
  const [voidReason,setVoidReason]=useState('');
  const [voidConfirmed,setVoidConfirmed]=useState(false);
  const [refundAmount,setRefundAmount]=useState('');
  const [refundMethod,setRefundMethod]=useState('transferencia');
  const [refundReference,setRefundReference]=useState('');
  const [pending,setPending]=useState(()=>{try{return JSON.parse(sessionStorage.getItem(pendingKey)||'null');}catch{return null;}});
  async function refresh(){
    setLoading(true);
    try{const data=await request('/credit/accounts/'+accountId+'/adjustments');setInfo(data);}
    catch(e){notify('No se pudieron consultar los ajustes de crédito: '+e.message,'error');}
    finally{setLoading(false);}
  }
  useEffect(()=>{void refresh();},[accountId]);
  const active=account.status==='active';
  const lines=info?.items||[];
  const anyReturns=(info?.adjustments||[]).length>0;
  const selected=lines.map(i=>({...i,returnQty:i.serial_units?.length?(returnSeries[i.sale_item_id]||[]).length:Number(qty[i.sale_item_id]||0)})).filter(i=>i.returnQty>0);
  const selectedTotal=selected.reduce((sum,i)=>sum+Math.round(i.returnQty*Number(i.unit_price_clp)),0);
  function canSubmit(){return !busy&&!pending;}
  async function submitOperation(body){
    setBusy(true);
    try{
      const {type,id,...payload}=body;
      const path=type==='return'?'/returns':type==='void'?'/void':'/refunds';
      const result=await request('/credit/accounts/'+id+path,{method:'POST',body:payload});
      sessionStorage.removeItem(pendingKey);setPending(null);
      setQty({});setReturnSeries({});setVoidSeries({});setReason('');setReceived(false);setVoidConfirmed(false);setVoidReason('');setRefundAmount('');setRefundReference('');
      if(type==='return')notify('Devolución de crédito registrada: '+pesos(result.amount_clp)+'. Pendiente por cobrar: '+pesos(result.balance_clp)+'. A favor del cliente: '+pesos(result.refund_due_clp)+'.','success');
      if(type==='void')notify('Venta a crédito anulada. Mercadería repuesta y deuda ajustada; verifica posibles reembolsos pendientes.','success');
      if(type==='refund')notify('Reembolso externo registrado. Saldo a devolver: '+pesos(result.refund_due_clp)+'.','success');
      await onChange();await refresh();
    }catch(e){
      if(e.status>=400&&e.status<500&&e.status!==408&&e.status!==429){
        sessionStorage.removeItem(pendingKey);setPending(null);
        notify('Operación rechazada: '+e.message,'error');
      }else{
        notify('Resultado incierto: '+e.message+'. Revisa historial y reintenta el MISMO registro; no generes otro movimiento.','error');
      }
    }finally{setBusy(false);}
  }
  function schedule(body){
    sessionStorage.setItem(pendingKey,JSON.stringify(body));setPending(body);void submitOperation(body);
  }
  function submitReturn(e){
    e.preventDefault();if(!canSubmit()||!active||!info)return;
    if(!received||reason.trim().length<5||!selected.length){notify('Confirma inspección, motivo y cantidades para devolver.','error');return;}
    for(const i of selected){
      const available=Number(i.quantity)-Number(i.returned_qty);
      if(i.returnQty>available+0.000001||i.returnQty<=0||
        (i.sale_unit==='unidad'&&!Number.isInteger(i.returnQty))||
        Math.abs(i.returnQty*1000-Math.round(i.returnQty*1000))>0.000001){
        notify('Cantidad no válida en '+i.product_name_snapshot,'error');return;
      }
    }
    const note=kind==='commercial'?'Se repondrá el stock vendible en el local.':'Los productos defectuosos NO volverán al inventario vendible.';
    if(!window.confirm('¿Registrar devolución a crédito por aproximadamente '+pesos(selectedTotal)+'? '+note+' La deuda se ajustará y podría quedar un reembolso pendiente al cliente.'))return;
    schedule({
      type:'return',id:accountId,request_id:newId(),
      return_kind:kind,reason:reason.trim(),inspection_confirmed:true,
      items:selected.map(i=>({sale_item_id:Number(i.sale_item_id),quantity:i.returnQty})),serial_items:selected.filter(i=>i.serial_units?.length).map(i=>({sale_item_id:Number(i.sale_item_id),serial_numbers:returnSeries[i.sale_item_id]||[]}))
    });
  }
  function submitVoid(e){
    e.preventDefault();if(!canSubmit()||!active)return;
    if(voidReason.trim().length<5||!voidConfirmed){notify('Confirma recuperación física de toda la mercadería y motivo.','error');return;}
    if(lines.some(i=>i.serial_units?.length&&(voidSeries[i.sale_item_id]||[]).length!==Number(i.quantity))){notify('Identifica todas las series recuperadas.','error');return;}
    if(!window.confirm('ANULAR venta #'+account.sale_id+' COMPLETA: reponer todos sus productos al local, ajustar toda la deuda y registrar los abonos previos como dinero que corresponde devolver. ¿Continuar?'))return;
    schedule({type:'void',id:accountId,request_id:newId(),reason:voidReason.trim(),inspection_confirmed:true,serial_items:lines.filter(i=>i.serial_units?.length).map(i=>({sale_item_id:Number(i.sale_item_id),serial_numbers:voidSeries[i.sale_item_id]||[]}))});
  }
  function submitRefund(e){
    e.preventDefault();if(!canSubmit())return;
    const amount=Number(refundAmount);
    if(!Number.isSafeInteger(amount)||amount<1||amount>Number(account.refund_due_clp)){notify('Importe del reembolso superior al saldo a favor del cliente.','error');return;}
    if(!window.confirm('¿Confirmas que YA DEVOLVISTE '+pesos(amount)+' al cliente mediante '+methodNames[refundMethod]+'? Tito POS no mueve dinero automáticamente.'))return;
    schedule({type:'refund',id:accountId,request_id:newId(),amount_clp:amount,method:refundMethod,reference:refundReference.trim()||null});
  }
  return <section className="credit-operations">
    <h4>Ajustes de crédito y mercadería</h4>
    {loading&&<p className="muted">Cargando historial de devoluciones y reembolsos…</p>}
    {pending&&<div className="warning-box" role="alert">
      <strong>Operación con resultado pendiente de confirmar</strong>
      <p>Verifica el historial antes de otra operación. Reintentar este identificador evita duplicados.</p>
      <button className="primary" type="button" disabled={busy||pending.id!==accountId} onClick={()=>void submitOperation(pending)}>Reintentar la misma operación</button>
      <button type="button" className="ghost" disabled={busy} onClick={()=>{
        if(window.confirm('Solo descarta si verificaste que el movimiento NO quedó registrado.')){
          sessionStorage.removeItem(pendingKey);setPending(null);
        }
      }}>Descartar tras verificar</button>
    </div>}
    {info&&active&&<form className="formstack credit-operation-form" onSubmit={submitReturn}>
      <h4>Registrar devolución parcial</h4>
      <p className="helper">La devolución aceptada reduce la venta neta. Si ya existen abonos, podría generar un saldo a favor del cliente.</p>
      <label>Condición de la mercadería<select value={kind} disabled={busy||!!pending} onChange={e=>setKind(e.target.value)}>
        <option value="commercial">Comercial · apta para reventa (reingresa al local)</option>
        <option value="warranty">Garantía · defectuosa (no reingresa a stock vendible)</option>
      </select></label>
      <div className="credit-returns-lines">
        {lines.map(i=>{
          const available=Math.max(0,Number(i.quantity)-Number(i.returned_qty));
          if(i.serial_units?.length)return <div key={i.sale_item_id}><strong>{i.product_name_snapshot} · Selecciona las series recuperadas</strong>{availableSeries(i).map(u=><label key={u.unit_id} className="credit-toggle"><input type="checkbox" disabled={busy||!!pending} checked={(returnSeries[i.sale_item_id]||[]).includes(u.serial_number)} onChange={()=>toggleSeries(setReturnSeries,i.sale_item_id,u.serial_number)}/>{u.serial_number}</label>)}{!availableSeries(i).length&&<p>Sin series disponibles.</p>}</div>;
          return <label key={i.sale_item_id}>{i.product_name_snapshot} · Disponible para devolución {available} {i.sale_unit} · {pesos(i.unit_price_clp)}/unidad
            <input type="number" step={i.sale_unit==='unidad'?'1':'0.001'} min="0" max={available} value={qty[i.sale_item_id]||''} disabled={busy||!!pending||available===0} onChange={e=>setQty(p=>({...p,[i.sale_item_id]:e.target.value}))} placeholder="Cantidad"/>
          </label>;
        })}
      </div>
      <label>Motivo de devolución<textarea value={reason} disabled={busy||!!pending} minLength={5} maxLength={250} rows={2} onChange={e=>setReason(e.target.value)} placeholder="Explica el motivo y la inspección"/></label>
      <label className="credit-toggle"><input type="checkbox" checked={received} disabled={busy||!!pending} onChange={e=>setReceived(e.target.checked)}/> Confirmo recepción e inspección física de estos productos</label>
      <button type="submit" className="primary wide" disabled={!canSubmit()||!received||!selected.length}>{busy?'Procesando…':'Registrar devolución y ajustar deuda'}</button>
    </form>}
    {info&&active&&<form className="formstack credit-operation-form" onSubmit={submitVoid}>
      <h4>Anular toda la venta a crédito</h4>
      <p className="warning-box">Solo disponible si no tiene devoluciones anteriores. Es necesario recuperar TODOS los productos en condiciones aptas para reventa. Si el cliente ya pagó, se generará un saldo a favor que deberá reembolsarse por separado.</p>
      {!anyReturns&&lines.filter(i=>i.serial_units?.length).map(i=><div key={i.sale_item_id}><strong>{i.product_name_snapshot} · Confirma cada serie recuperada</strong>{availableSeries(i).map(u=><label key={u.unit_id} className="credit-toggle"><input type="checkbox" checked={(voidSeries[i.sale_item_id]||[]).includes(u.serial_number)} disabled={busy||!!pending} onChange={()=>toggleSeries(setVoidSeries,i.sale_item_id,u.serial_number)}/>{u.serial_number}</label>)}</div>)}
      <label>Motivo<input maxLength={250} minLength={5} value={voidReason} disabled={busy||!!pending||anyReturns} onChange={e=>setVoidReason(e.target.value)} placeholder="Ej. Venta registrada por error"/></label>
      <label className="credit-toggle"><input type="checkbox" checked={voidConfirmed} disabled={busy||!!pending||anyReturns} onChange={e=>setVoidConfirmed(e.target.checked)}/> Confirmo que recuperé toda la mercadería apta para reventa</label>
      <button className="ghost wide danger-button" disabled={!canSubmit()||anyReturns||!voidConfirmed}>Anular venta completa y regularizar deuda</button>
    </form>}
    {Number(account.refund_due_clp)>0&&<form className="formstack credit-operation-form" onSubmit={submitRefund}>
      <h4>Reembolsar saldo a favor: {pesos(account.refund_due_clp)}</h4>
      <p className="warning-box">Primero devuelve el dinero por un medio externo. Después confirma aquí el pago realizado. Esta acción NO envía dinero.</p>
      <label>Monto efectivamente reembolsado<input type="number" min="1" step="1" max={account.refund_due_clp} value={refundAmount} disabled={busy||!!pending} onChange={e=>setRefundAmount(e.target.value)}/></label>
      <label>Medio de reembolso<select value={refundMethod} disabled={busy||!!pending} onChange={e=>setRefundMethod(e.target.value)}>
        {Object.entries(methodNames).map(([key,label])=><option key={key} value={key}>{label}</option>)}
      </select></label>
      <label>Referencia del comprobante<input maxLength={120} value={refundReference} disabled={busy||!!pending} onChange={e=>setRefundReference(e.target.value)}/></label>
      <button className="primary wide" disabled={!canSubmit()||!refundAmount}>Confirmar reembolso YA realizado</button>
    </form>}
    {!!info?.adjustments?.length&&<div className="credit-operation-history">
      <h4>Devoluciones y anulaciones</h4>
      {info.adjustments.map(a=><p key={a.id}><strong>{a.kind==='void'?'Anulación':'Devolución'} #{a.id} · {pesos(a.amount_clp)}</strong><span>{a.return_kind==='warranty'?'Garantía defectuosa':'Mercadería apta'} · {renderWhen(a.created_at)}</span><small>{a.reason}</small></p>)}
    </div>}
    {!!info?.refunds?.length&&<div className="credit-operation-history">
      <h4>Reembolsos entregados</h4>
      {info.refunds.map(r=><p key={r.id}><strong>{pesos(r.amount_clp)} · {methodNames[r.method]}</strong><span>{renderWhen(r.created_at)}</span><small>{r.reference||'Sin referencia'}</small></p>)}
    </div>}
  </section>;
}
