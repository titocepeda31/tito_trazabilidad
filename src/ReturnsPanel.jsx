import React,{useEffect,useMemo,useState} from 'react';
import {newId,pesos,qtyFormat} from './api.js';
import {inChilePeriod,pageSlice} from './historyFilters.js';

const pendingKey='tito_pending_customer_return';
const kindLabels={commercial:'Devolución comercial',warranty:'Garantía por falla de origen'};
const resolutionLabels={refund:'Reembolso',exchange:'Cambio de producto',repair:'Reparación'};
const dateChile=value=>{
  if(!value)return '—';
  const date=new Date(String(value).replace(' ','T')+'Z');
  return Number.isFinite(date.valueOf())?date.toLocaleString('es-CL',{timeZone:'America/Santiago',dateStyle:'short',timeStyle:'short'}):String(value);
};

export default function ReturnsPanel({sales,request,notify,onChange,initialSaleId}){
  const [saleId,setSaleId]=useState(initialSaleId?String(initialSaleId):'');
  const [detail,setDetail]=useState(null);
  const [history,setHistory]=useState([]);
  const [creditHistory,setCreditHistory]=useState([]);
  const [historyQuery,setHistoryQuery]=useState('');
  const [historyPeriod,setHistoryPeriod]=useState('all');
  const [historyPage,setHistoryPage]=useState(1);
  const [creditPage,setCreditPage]=useState(1);
  const [quantities,setQuantities]=useState({});
  const [returnKind,setReturnKind]=useState('commercial');
  const [resolution,setResolution]=useState('refund');
  const [reason,setReason]=useState('');
  const [inspectionConfirmed,setInspectionConfirmed]=useState(false);
  const [busy,setBusy]=useState(false);
  const [loading,setLoading]=useState(true);
  const [backendMissing,setBackendMissing]=useState(false);
  const [refundTarget,setRefundTarget]=useState(null);
  const [refundMethod,setRefundMethod]=useState('efectivo');
  const [refundReference,setRefundReference]=useState('');
  const [pending,setPending]=useState(()=>{
    try{return JSON.parse(sessionStorage.getItem(pendingKey)||'null');}catch{return null;}
  });
  const locked=busy||Boolean(pending);
  const needle=historyQuery.trim().toLocaleLowerCase('es-CL');
  const filteredHistory=useMemo(()=>history.filter(r=>
    inChilePeriod(r.created_at,historyPeriod)&&
    (!needle||[r.id,r.sale_id,kindLabels[r.return_kind],resolutionLabels[r.resolution],r.refund_status].some(x=>String(x??'').toLocaleLowerCase('es-CL').includes(needle)))
  ),[history,historyPeriod,needle]);
  const filteredCreditHistory=useMemo(()=>creditHistory.filter(a=>
    inChilePeriod(a.created_at,historyPeriod)&&
    (!needle||[a.id,a.sale_id,a.client_name,a.reason,a.kind].some(x=>String(x??'').toLocaleLowerCase('es-CL').includes(needle)))
  ),[creditHistory,historyPeriod,needle]);
  const historyPageData=pageSlice(filteredHistory,historyPage,20);
  const creditPageData=pageSlice(filteredCreditHistory,creditPage,20);
  useEffect(()=>{setHistoryPage(1);setCreditPage(1);},[historyQuery,historyPeriod]);
  const items=detail?.items||[];
  const selected=useMemo(()=>items.map(i=>{
    const available=Math.max(0,Number(i.quantity)-Number(i.returned_qty));
    const qty=Number(quantities[i.sale_item_id]||0);
    return {...i,available,selected_qty:qty,amount:Math.round(qty*Number(i.unit_price_clp))};
  }).filter(i=>i.selected_qty>0),[items,quantities]);
  const total=selected.reduce((sum,i)=>sum+i.amount,0);
  const denied=returnKind==='customer_damage';
  const remaining=items.some(i=>Number(i.quantity)>Number(i.returned_qty)+0.000001);

  async function loadHistory(){
    try{
      const response=await request('/returns');
      setHistory(response.returns||[]);setBackendMissing(false);
    }catch(e){
      if(e.status===404)setBackendMissing(true);
      else notify('No se pudieron cargar las devoluciones: '+e.message,'error');
    }finally{setLoading(false);}
    try{
      const data=await request('/credit/adjustments');
      setCreditHistory(data.adjustments||[]);
    }catch(e){
      if(e.status!==404)notify('No se pudo cargar el historial de devoluciones de crédito: '+e.message,'error');
    }
  }
  async function loadSale(id){
    if(!id){setDetail(null);return;}
    setLoading(true);
    try{
      const data=await request('/sales/'+id+'/returns');
      setDetail(data);setQuantities({});setBackendMissing(false);
    }catch(e){
      setDetail(null);
      if(e.status===404)setBackendMissing(true);
      else notify('No se pudo revisar la venta: '+e.message,'error');
    }finally{setLoading(false);}
  }
  useEffect(()=>{void loadHistory();},[]);
  useEffect(()=>{
    if(initialSaleId)setSaleId(String(initialSaleId));
  },[initialSaleId]);
  useEffect(()=>{void loadSale(saleId);},[saleId]);

  async function saveReturn(body){
    setBusy(true);
    try{
      const result=await request('/sales/'+body.sale_id+'/returns',{
        method:'POST',
        body:{
          request_id:body.request_id,return_kind:body.return_kind,
          item_condition:body.item_condition,resolution:body.resolution,
          reason:body.reason,inspection_confirmed:body.inspection_confirmed,items:body.items
        }
      });
      sessionStorage.removeItem(pendingKey);
      setPending(null);setQuantities({});setReason('');setInspectionConfirmed(false);
      notify(result.already_recorded?'Devolución ya registrada; no se duplicó stock.':'Devolución #'+result.return_id+' registrada. '+(result.restocked?'Unidades devueltas al stock del local.':'Producto defectuoso registrado sin reingreso a stock vendible.'),'success');
      await Promise.all([loadHistory(),loadSale(body.sale_id),onChange()]);
    }catch(e){
      notify('No se pudo confirmar la devolución: '+e.message+'. Revisa el historial y reintenta con el mismo identificador; no generes otra devolución.', 'error');
    }finally{setBusy(false);}
  }
  function submit(e){
    e.preventDefault();
    if(locked||!detail||denied)return;
    if(detail.sale_status!=='completed'){notify('La venta debe estar vigente para devolver productos.','error');return;}
    if(reason.trim().length<5){notify('Describe el motivo de devolución (mínimo cinco caracteres).','error');return;}
    if(!inspectionConfirmed){notify('Debes confirmar la inspección y recepción física del producto.','error');return;}
    if(!selected.length){notify('Selecciona una cantidad para devolver.','error');return;}
    for(const i of selected){
      if(!Number.isFinite(i.selected_qty)||i.selected_qty>i.available+0.000001||
        (i.sale_unit==='unidad'&&!Number.isInteger(i.selected_qty))||
        Math.abs(i.selected_qty*1000-Math.round(i.selected_qty*1000))>0.000001){
        notify('La cantidad excede lo pendiente de devolver: '+i.product_name_snapshot,'error');return;
      }
    }
    const kind=returnKind;
    const notice=kind==='commercial'?'Las unidades aptas para reventa volverán al stock del local.':'Las unidades por garantía quedarán registradas fuera del stock vendible.';
    if(!window.confirm('¿Registrar devolución de la venta #'+saleId+' por un valor de referencia de '+pesos(total)+'? '+notice+' No se efectúa ningún pago ni documento SII automáticamente.'))return;
    const body={
      request_id:newId(),sale_id:Number(saleId),return_kind:kind,
      item_condition:kind==='commercial'?'resellable':'defective',
      resolution,reason:reason.trim(),inspection_confirmed:true,
      items:selected.map(i=>({sale_item_id:Number(i.sale_item_id),quantity:i.selected_qty}))
    };
    sessionStorage.setItem(pendingKey,JSON.stringify(body));
    setPending(body);void saveReturn(body);
  }
  async function confirmRefund(e){
    e.preventDefault();
    if(!refundTarget||busy)return;
    if(!window.confirm('¿Confirmas que el dinero de esta devolución YA fue reembolsado fuera de Tito POS? Esta acción solo registra el hecho.'))return;
    setBusy(true);
    try{
      await request('/returns/'+refundTarget.id+'/confirm-refund',{
        method:'POST',body:{method:refundMethod,reference:refundReference.trim()}
      });
      notify('Reembolso marcado como efectuado. No se ha enviado dinero desde Tito POS.','success');
      setRefundTarget(null);setRefundReference('');await loadHistory();
      if(saleId)await loadSale(saleId);
    }catch(e){notify('No se pudo confirmar el reembolso: '+e.message,'error');}
    finally{setBusy(false);}
  }

  return <div className="customer-returns-page">
    <div className="section-intro">
      <div><p className="eyebrow">POSTVENTA</p><h2>Devoluciones de clientes</h2>
      <p className="muted">Registra devoluciones parciales vinculadas a la venta original y controla el reingreso al stock.</p></div>
    </div>
    {backendMissing&&<section className="warning-box" role="status">
      El módulo todavía requiere instalar la API de devoluciones en cPanel. No registres ajustes de stock para simular una devolución.
    </section>}
    <section className="panel pad returns-form-panel">
      <div className="panel-heading"><div><h3>Nueva devolución</h3><p>Busca primero la venta original.</p></div></div>
      <label>Venta registrada
        <select value={saleId} disabled={locked} onChange={e=>setSaleId(e.target.value)}>
          <option value="">Selecciona una venta</option>
          {sales.filter(s=>(s.status||'completed')==='completed').map(s=><option key={s.id} value={s.id}>
            Venta #{s.id} · {pesos(s.total_clp)} · {dateChile(s.created_at)}
          </option>)}
          {saleId&&!sales.some(s=>String(s.id)===saleId)&&<option value={saleId}>Venta #{saleId}</option>}
        </select>
      </label>
      {detail&&<form className="formstack" onSubmit={submit}>
        <div className="returns-choice-grid">
          <label>Situación del producto
            <select value={returnKind} disabled={locked} onChange={e=>{setReturnKind(e.target.value);setInspectionConfirmed(false);}}>
              <option value="commercial">Devolución comercial · está en buen estado</option>
              <option value="warranty">Garantía · presenta falla de origen</option>
              <option value="customer_damage">Dañado por mal uso del cliente · requiere revisión</option>
            </select>
          </label>
          <label>Solución solicitada
            <select value={resolution} disabled={locked||denied} onChange={e=>setResolution(e.target.value)}>
              <option value="refund">Devolución del dinero</option>
              <option value="exchange">Cambio del producto</option>
              <option value="repair">Reparación</option>
            </select>
          </label>
        </div>
        {denied?<p className="warning-box" role="alert">
          El daño atribuido al uso del cliente no puede registrarse automáticamente como devolución comercial.
          Debe revisarse el caso y su evidencia. Si el daño era un defecto de origen, selecciona «Garantía».
        </p>:returnKind==='warranty'?<p className="discount-approval-notice">
          <strong>Garantía por defecto de origen</strong> La recepción se registra, pero las unidades no se suman al stock vendible. En Chile, la garantía legal contempla devolución, cambio o reparación cuando corresponde; el caso se debe evaluar.
        </p>:<p className="helper">
          Solo acepta productos completos y en condiciones aptas para reventa. Estas unidades se reingresarán al local de venta.
        </p>}
        {!denied&&<label className="returns-inspection-confirm">
          <input type="checkbox" checked={inspectionConfirmed} disabled={locked} onChange={e=>setInspectionConfirmed(e.target.checked)}/>
          <span>{returnKind==='commercial'
            ?'Confirmo que recibí y revisé físicamente el producto, está completo y en condiciones aptas para reventa.'
            :'Confirmo que recibí físicamente el producto para tramitar una garantía, y que NO volverá al stock vendible.'}</span>
        </label>}
        <div className="returns-line-list">
          {items.map(line=>{
            const available=Math.max(0,Number(line.quantity)-Number(line.returned_qty));
            return <div className="returns-line" key={line.sale_item_id}>
              <div><strong>{line.product_name_snapshot}</strong><small>{qtyFormat(available)} de {qtyFormat(line.quantity)} {line.sale_unit} disponibles para devolución · Vendido a {pesos(line.unit_price_clp)}</small></div>
              <label>Cantidad a devolver
                <input type="number" min="0" max={available} step={line.sale_unit==='unidad'?'1':'0.001'}
                  value={quantities[line.sale_item_id]??''} placeholder="0"
                  disabled={locked||denied||available<=0}
                  onChange={e=>setQuantities(q=>({...q,[line.sale_item_id]:e.target.value}))}/>
              </label>
            </div>;
          })}
          {!remaining&&<p className="muted">Todos los productos de esta venta ya fueron devueltos.</p>}
        </div>
        <label>Motivo y observaciones
          <textarea rows="3" required maxLength={250} minLength={5} value={reason} disabled={locked||denied}
            placeholder="Ej. El cliente solicitó cambio; se verificó producto sin uso y con embalaje completo"
            onChange={e=>setReason(e.target.value)}/>
        </label>
        <div className="returns-total"><span>Importe estimado de los productos seleccionados</span><strong>{pesos(total)}</strong></div>
        {resolution==='refund'&&!denied&&<p className="helper">El importe se calcula sobre el precio efectivamente cobrado, no el precio actual. El reembolso quedará pendiente hasta que confirmes que se pagó fuera del sistema.</p>}
        {resolution!=='refund'&&!denied&&<p className="helper">El cambio o la reparación se registrará como solución solicitada; Tito POS no despacha productos de reemplazo ni gestiona reparaciones automáticamente.</p>}
        <button className="primary wide" disabled={locked||denied||!inspectionConfirmed||!selected.length||!remaining||backendMissing||detail.sale_status!=='completed'}>
          {busy?'Registrando…':'Registrar devolución'}
        </button>
      </form>}
      {pending&&<div className="warning-box" role="alert">
        <strong>Devolución con resultado no confirmado</strong>
        <p>No generes otra devolución para estas unidades antes de verificar el historial.</p>
        <button type="button" className="primary" disabled={busy} onClick={()=>void saveReturn(pending)}>Reintentar el mismo registro</button>
        <button type="button" className="ghost" disabled={busy} onClick={()=>{
          if(window.confirm('Descarta el intento SOLO si comprobaste que la devolución no fue registrada.')){
            sessionStorage.removeItem(pendingKey);setPending(null);
          }
        }}>Descartar tras comprobar</button>
      </div>}
      {detail?.returns?.length>0&&<div className="returns-sale-history">
        <h3>Devoluciones de esta venta</h3>
        {detail.returns.map(r=><p key={r.id}>#{r.id} · {kindLabels[r.return_kind]} · {pesos(r.amount_clp)} · {resolutionLabels[r.resolution]}</p>)}
      </div>}
    </section>

    <section className="panel returns-history">
      <div className="panel-heading pad"><div><h3>Últimas devoluciones</h3><p>Historial auditable del negocio</p></div>
        <button className="ghost" type="button" disabled={loading} onClick={()=>void loadHistory()}>↻ Actualizar</button>
      </div>
      <div className="list-filters"><label>Buscar devolución<input type="search" value={historyQuery} onChange={e=>setHistoryQuery(e.target.value)} placeholder="N.º de venta, registro, tipo o cliente…" /></label><label>Período<select value={historyPeriod} onChange={e=>setHistoryPeriod(e.target.value)}><option value="all">Todos los cargados</option><option value="today">Hoy</option><option value="week">Esta semana</option><option value="month">Este mes</option></select></label></div>
      <p className="list-filter-note">La búsqueda y las páginas filtran solamente los registros cargados. No consultan todas las devoluciones históricas del servidor.</p>
      <div className="table-wrap">
        <table><thead><tr><th>Registro</th><th>Venta</th><th>Tipo</th><th>Solución</th><th>Monto</th><th>Reembolso</th></tr></thead>
          <tbody>{historyPageData.rows.map(r=><tr key={r.id}>
            <td><strong>#{r.id}</strong><small className="sub">{dateChile(r.created_at)}</small></td>
            <td><button type="button" className="ghost mini" onClick={()=>setSaleId(String(r.sale_id))}>#{r.sale_id}</button></td>
            <td>{kindLabels[r.return_kind]}</td>
            <td>{resolutionLabels[r.resolution]}</td>
            <td><strong>{pesos(r.amount_clp)}</strong></td>
            <td>{r.refund_status==='pending'?
              <button className="ghost mini" type="button" disabled={busy} onClick={()=>{setRefundMethod('efectivo');setRefundReference('');setRefundTarget(r);}}>Confirmar reembolso</button>
              :r.refund_status==='confirmed'?'Confirmado':'No corresponde'}</td>
          </tr>)}</tbody>
        </table>
        {!filteredHistory.length&&!loading&&<p className="receipt-empty">No hay devoluciones que coincidan con el filtro.</p>}
      </div>
      <div className="list-pagination"><span>{historyPageData.total} devoluciones filtradas · Página {historyPageData.page} de {historyPageData.pages}</span><div className="inline-actions"><button type="button" className="ghost mini" disabled={historyPageData.page<=1} onClick={()=>setHistoryPage(p=>p-1)}>← Anterior</button><button type="button" className="ghost mini" disabled={historyPageData.page>=historyPageData.pages} onClick={()=>setHistoryPage(p=>p+1)}>Siguiente →</button></div></div>
      <p className="helper pad">Los productos por garantía se registran fuera del inventario vendible. Un reembolso confirmado aquí no mueve dinero ni emite notas de crédito del SII.</p>
    </section>

    {creditHistory.length>0&&<section className="panel returns-history">
      <div className="panel-heading pad"><div><h3>Devoluciones y anulaciones a crédito</h3><p>Historial separado del libro de cuentas por cobrar</p></div></div>
      <div className="table-wrap"><table><thead><tr><th>Operación</th><th>Venta</th><th>Cliente</th><th>Tipo</th><th>Importe ajustado</th><th>Motivo</th></tr></thead>
        <tbody>{creditPageData.rows.map(a=><tr key={a.id}>
          <td><strong>#{a.id}</strong><small className="sub">{dateChile(a.created_at)}</small></td>
          <td>#{a.sale_id}</td><td>{a.client_name}</td>
          <td>{a.kind==='void'?'Anulación total':a.return_kind==='warranty'?'Garantía defectuosa':'Devolución comercial'}</td>
          <td><strong>{pesos(a.amount_clp)}</strong></td><td>{a.reason}</td>
        </tr>)}</tbody></table>{filteredCreditHistory.length===0&&<p className="receipt-empty">No hay movimientos de crédito para estos filtros.</p>}</div>
      <div className="list-pagination"><span>{creditPageData.total} movimientos · Página {creditPageData.page} de {creditPageData.pages}</span><div className="inline-actions"><button type="button" className="ghost mini" disabled={creditPageData.page<=1} onClick={()=>setCreditPage(p=>p-1)}>← Anterior</button><button type="button" className="ghost mini" disabled={creditPageData.page>=creditPageData.pages} onClick={()=>setCreditPage(p=>p+1)}>Siguiente →</button></div></div>
      <p className="helper pad">Para ver el saldo actualizado, abonos y posibles reembolsos pendientes, abre Más → Clientes y crédito → Cuentas por cobrar.</p>
    </section>}

    {refundTarget&&<div className="modal-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget)setRefundTarget(null);}}>
      <div className="modal" role="dialog" aria-modal="true" aria-label={'Confirmar reembolso de devolución '+refundTarget.id}>
        <div className="modal-head"><h3>Confirmar reembolso #{refundTarget.id}</h3><button type="button" className="ghost close" onClick={()=>setRefundTarget(null)} aria-label="Cerrar">×</button></div>
        <p className="warning-box">Usa esta opción únicamente después de devolver efectivamente {pesos(refundTarget.amount_clp)} al cliente por un medio externo.</p>
        <form className="formstack" onSubmit={confirmRefund}>
          <label>Medio de devolución del dinero
            <select value={refundMethod} onChange={e=>setRefundMethod(e.target.value)} disabled={busy}>
              <option value="efectivo">Efectivo</option><option value="transferencia">Transferencia</option>
              <option value="tarjeta">Tarjeta</option><option value="otro">Otro</option>
            </select>
          </label>
          <label>Comprobante o referencia (opcional)
            <input value={refundReference} maxLength={120} disabled={busy} onChange={e=>setRefundReference(e.target.value)} placeholder="Número de transferencia o comprobante"/>
          </label>
          <button type="submit" className="primary wide" disabled={busy}>{busy?'Confirmando…':'Confirmar que ya se reembolsó'}</button>
        </form>
      </div>
    </div>}
  </div>;
}
