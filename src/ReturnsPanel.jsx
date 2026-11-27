import React,{useEffect,useMemo,useRef,useState} from 'react';
import {newId,pesos,qtyFormat} from './api.js';
import {inChilePeriod,pageSlice} from './historyFilters.js';

const pendingKey='tito_pending_customer_return';
const kindLabels={commercial:'Devolución comercial',warranty:'Garantía por falla de origen'};
const assessmentLabels={pending_review:'Pendiente de evaluación',rejected:'Rechazado con justificación',technical_service:'Derivado a servicio técnico'};
const resolutionLabels={refund:'Reembolso',exchange:'Cambio de producto',repair:'Reparación'};
const dateChile=value=>{
  if(!value)return '—';
  const date=new Date(String(value).replace(' ','T')+'Z');
  return Number.isFinite(date.valueOf())?date.toLocaleString('es-CL',{timeZone:'America/Santiago',dateStyle:'short',timeStyle:'short'}):String(value);
};

// Calendar days in Chile, informational only; sale date may differ from receipt date.
const saleAge=value=>{
  if(!value)return null;
  const d=new Date(String(value).replace(' ','T')+(String(value).endsWith('Z')?'':'Z'));
  if(!Number.isFinite(d.valueOf()))return null;
  const day=t=>{const parts=new Intl.DateTimeFormat('en-CA',{timeZone:'America/Santiago',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(t);const p=Object.fromEntries(parts.map(x=>[x.type,x.value]));return Date.UTC(+p.year,+p.month-1,+p.day);};
  return Math.max(0,Math.round((day(new Date())-day(d))/86400000));
};

export default function ReturnsPanel({sales,request,notify,onChange,initialSaleId}){
  const [saleId,setSaleId]=useState(initialSaleId?String(initialSaleId):'');
  const [detail,setDetail]=useState(null);
  const [view,setView]=useState('new');
  const [query,setQuery]=useState(''),[searchResults,setSearchResults]=useState(null),[searchBusy,setSearchBusy]=useState(false),[searchError,setSearchError]=useState('');
  const autoPick=useRef(null),loadVersion=useRef(0);
  const [history,setHistory]=useState([]);
  const [assessments,setAssessments]=useState([]),[assessmentError,setAssessmentError]=useState('');
  const [outcome,setOutcome]=useState('pending_review'),[custody,setCustody]=useState('customer'),[evidence,setEvidence]=useState('');
  const [creditHistory,setCreditHistory]=useState([]);
  const [historyQuery,setHistoryQuery]=useState('');
  const [historyPeriod,setHistoryPeriod]=useState('all');
  const [historyPage,setHistoryPage]=useState(1);
  const [creditPage,setCreditPage]=useState(1);
  const [quantities,setQuantities]=useState({}),[chosenSeries,setChosenSeries]=useState({});
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
    const serials=i.serial_units?.length?(chosenSeries[i.sale_item_id]||[]):null;
    const qty=serials?serials.length:Number(quantities[i.sale_item_id]||0);
    return {...i,available,selected_qty:qty,serials,amount:Math.round(qty*Number(i.unit_price_clp))};
  }).filter(i=>i.selected_qty>0),[items,quantities,chosenSeries]);
  const total=selected.reduce((sum,i)=>sum+i.amount,0);
  const denied=returnKind==='customer_damage';
  const age=saleAge(detail?.sale_created_at);
  const remaining=items.some(i=>Number(i.quantity)>Number(i.returned_qty)+0.000001);

  async function loadAssessments(){
    try{const data=await request('/returns/assessments');setAssessments(data.assessments||[]);setAssessmentError('');}
    catch(e){setAssessmentError('No se pudo cargar el historial de evaluaciones. Comprueba el despliegue y la migración 014. '+e.message);}
  }
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
    const version=++loadVersion.current;
    const pick=autoPick.current;autoPick.current=null;
    if(!id){setDetail(null);return;}
    setLoading(true);
    try{
      const data=await request('/sales/'+id+'/returns');
      if(version!==loadVersion.current)return;
      setDetail(data);setQuantities({});setChosenSeries({});setBackendMissing(false);
      if(pick&&String(pick.sale_id)===String(id)){
        const line=(data.items||[]).find(i=>String(i.sale_item_id)===String(pick.sale_item_id));
        if(line&&Number(line.quantity)>Number(line.returned_qty)&&line.serial_units?.some(u=>u.serial_number===pick.serial_number&&u.status==='sold'&&!u.reversal_kind)){
          setChosenSeries({[line.sale_item_id]:[pick.serial_number]});
        }
      }
    }catch(e){
      if(version!==loadVersion.current)return;
      setDetail(null);
      if(e.status===404)setBackendMissing(true);
      else notify('No se pudo revisar la venta: '+e.message,'error');
    }finally{if(version===loadVersion.current)setLoading(false);}
  }
  useEffect(()=>{void loadHistory();void loadAssessments();},[]);
  useEffect(()=>{
    if(initialSaleId){setView('new');setSaleId(String(initialSaleId));}
  },[initialSaleId]);
  useEffect(()=>{void loadSale(saleId);},[saleId]);

  async function searchSale(e){
    e.preventDefault();if(locked||searchBusy||!query.trim())return;
    setSearchBusy(true);setSearchError('');setSearchResults(null);
    try{setSearchResults(await request('/returns/search?q='+encodeURIComponent(query.trim())));}
    catch(e){setSearchError('No se pudo buscar: '+e.message);}
    finally{setSearchBusy(false);}
  }
  function chooseSale(id,pick=null){
    if(locked)return;
    const next=String(id||'');
    ++loadVersion.current;setDetail(null);setQuantities({});setChosenSeries({});setReason('');setInspectionConfirmed(false);setReturnKind('commercial');setResolution('refund');setOutcome('pending_review');setCustody('customer');setEvidence('');
    autoPick.current=pick;setView('new');setSearchResults(null);
    if(next===saleId)void loadSale(next);else setSaleId(next);
  }

  async function saveReturn(body){
    if(body.assessment)return saveAssessment(body);
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
      setPending(null);setQuantities({});setChosenSeries({});setReason('');setInspectionConfirmed(false);
      notify(result.already_recorded?'Devolución ya registrada; no se duplicó stock.':'Devolución #'+result.return_id+' registrada. '+(result.restocked?'Unidades devueltas al stock del local.':'Producto defectuoso registrado sin reingreso a stock vendible.'),'success');
      await Promise.all([loadHistory(),loadSale(body.sale_id),onChange()]);
    }catch(e){
      if(e.status>=400&&e.status<500&&![408,429].includes(e.status)){sessionStorage.removeItem(pendingKey);setPending(null);notify('Devolución rechazada: '+e.message,'error');await loadSale(body.sale_id);}else notify('No se pudo confirmar la devolución: '+e.message+'. Revisa el historial y reintenta con el mismo identificador; no generes otra devolución.', 'error');
    }finally{setBusy(false);}
  }
  async function saveAssessment(body){
    setBusy(true);
    try{
      const {assessment,sale_id,...payload}=body;
      const result=await request('/sales/'+sale_id+'/return-assessments',{method:'POST',body:payload});
      sessionStorage.removeItem(pendingKey);setPending(null);setReason('');setEvidence('');setInspectionConfirmed(false);setQuantities({});setChosenSeries({});
      notify('Evaluación #'+result.assessment_id+' registrada. No se modificó stock ni dinero.','success');
      await loadAssessments();setView('assessments');
    }catch(e){
      if(e.status>=400&&e.status<500&&![408,429].includes(e.status)){sessionStorage.removeItem(pendingKey);setPending(null);notify('No se registró la evaluación: '+e.message,'error');}
      else notify('Resultado no confirmado. Reintenta el mismo registro, sin crear otro. '+e.message,'error');
    }finally{setBusy(false);}
  }
  function submit(e){
    e.preventDefault();
    if(locked||!detail||String(detail.sale_id)!==String(saleId))return;
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
    if(denied){
      if(reason.trim().length<10){notify('Describe la evaluación con al menos 10 caracteres.','error');return;}
      if(outcome==='rejected'&&(!evidence.trim()||custody!=='customer')){notify('El rechazo requiere evidencia y confirmar que el producto queda con el cliente.','error');return;}
      if(!window.confirm('¿Registrar la evaluación? No se devolverá dinero ni se modificará el inventario.'))return;
      const body={assessment:true,request_id:newId(),sale_id:Number(saleId),outcome,custody,observations:reason.trim(),evidence_reference:evidence.trim(),inspection_confirmed:true,items:selected.map(i=>({sale_item_id:Number(i.sale_item_id),quantity:i.selected_qty,...(i.serials?{serial_numbers:i.serials}:{})}))};
      sessionStorage.setItem(pendingKey,JSON.stringify(body));setPending(body);void saveAssessment(body);return;
    }
    const kind=returnKind;
    const notice=kind==='commercial'?'Las unidades aptas para reventa volverán al stock del local.':'Las unidades por garantía quedarán registradas fuera del stock vendible.';
    if(!window.confirm('¿Registrar devolución de la venta #'+saleId+' por un valor de referencia de '+pesos(total)+'? '+notice+' No se efectúa ningún pago ni documento SII automáticamente.'))return;
    const body={
      request_id:newId(),sale_id:Number(saleId),return_kind:kind,
      item_condition:kind==='commercial'?'resellable':'defective',
      resolution,reason:reason.trim(),inspection_confirmed:true,
      items:selected.map(i=>({sale_item_id:Number(i.sale_item_id),quantity:i.selected_qty,...(i.serials?{serial_numbers:i.serials}:{})}))
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
    <div className="role-nav" aria-label="Vistas de postventa">
      <button type="button" className={view==='new'?'primary':'ghost'} onClick={()=>setView('new')}>Nueva devolución</button>
      <button type="button" className={view==='history'?'primary':'ghost'} onClick={()=>setView('history')}>Historial</button>
      <button type="button" className={view==='assessments'?'primary':'ghost'} onClick={()=>{setView('assessments');void loadAssessments();}}>Evaluaciones</button>
    </div>
    {view!=='new'&&pending&&<p className="warning-box">Existe un registro pendiente de confirmar. Revisa el historial y vuelve a Nueva devolución para reintentar el mismo registro.</p>}
    {view==='new'&&<section className="panel pad returns-form-panel">
      <div className="panel-heading"><div><h3>1. Localizar venta</h3><p>Escanea un código o busca la venta original.</p></div></div>
      <form className="origin-search" onSubmit={searchSale}>
        <input type="search" aria-label="Serie, código, cliente o número de venta" placeholder="Serie, SKU, código de barras, cliente o N.º venta" value={query} maxLength={120} disabled={locked||searchBusy} onChange={e=>{setQuery(e.target.value);setSearchResults(null);setSearchError('');}}/>
        <button className="primary" disabled={locked||searchBusy||!query.trim()}>{searchBusy?'Buscando…':'Buscar'}</button>
      </form>
      {searchError&&<p role="alert" className="warning-box">{searchError}</p>}
      {searchResults&&<div className="returns-line-list" aria-live="polite">
        {searchResults.series?.length>0&&<h4>Coincidencias por serie vigente</h4>}
        {(searchResults.series||[]).map(r=><div className="returns-line" key={r.sale_id+'-'+r.sale_item_id+'-'+r.serial_number}><div><strong>{r.product_name} · {r.serial_number}</strong><small>Venta #{r.sale_id} · {r.client_name||'Cliente no registrado'} · {dateChile(r.created_at)}</small></div><button type="button" className="ghost" disabled={locked} onClick={()=>chooseSale(r.sale_id,r)}>Seleccionar serie</button></div>)}
        {searchResults.sales?.length>0&&<h4>Ventas encontradas</h4>}
        {(searchResults.sales||[]).map(r=><div className="returns-line" key={r.sale_id}><div><strong>Venta #{r.sale_id} · {pesos(r.total_clp)}</strong><small>{r.client_name||'Cliente no registrado'} · {dateChile(r.created_at)}</small></div><button type="button" className="ghost" disabled={locked} onClick={()=>chooseSale(r.sale_id)}>Abrir venta</button></div>)}
        {!searchResults.series?.length&&!searchResults.sales?.length&&<p className="muted">No se encontraron ventas directas vigentes. Comprueba el código o consulta el historial de trazabilidad.</p>}
        {searchResults.more&&<p className="helper">Hasta 50 resultados por tipo. Acota la búsqueda.</p>}
      </div>}
      <details><summary>Seleccionar una venta manualmente</summary><label>Venta registrada
        <select value={saleId} disabled={locked} onChange={e=>chooseSale(e.target.value)}>
          <option value="">Selecciona una venta</option>
          {sales.filter(s=>(s.status||'completed')==='completed').map(s=><option key={s.id} value={s.id}>
            Venta #{s.id} · {pesos(s.total_clp)} · {dateChile(s.created_at)}
          </option>)}
          {saleId&&!sales.some(s=>String(s.id)===saleId)&&<option value={saleId}>Venta #{saleId}</option>}
        </select>
      </label></details>
      {detail&&String(detail.sale_id)===String(saleId)&&<form className="formstack" onSubmit={submit}>
        <div className="panel-heading"><div><h3>2. Revisar venta #{detail.sale_id}</h3><p>{detail.client_name||'Cliente no registrado'} · {dateChile(detail.sale_created_at)}{age!==null?' · '+age+' día(s) desde la venta':''}</p></div><button type="button" className="ghost" disabled={locked} onClick={()=>chooseSale('')}>Cambiar venta</button></div>
        <p className="helper">La devolución comercial depende de la política de la empresa. Los días son informativos y no bloquean garantías ni otros derechos legales. Si la entrega fue posterior a la venta, verifica su fecha real.</p>
        <h3>3. Seleccionar motivo y productos</h3>
        <div className="returns-choice-grid">
          <label>Situación del producto
            <select value={returnKind} disabled={locked} onChange={e=>{setReturnKind(e.target.value);setInspectionConfirmed(false);}}>
              <option value="commercial">Devolución comercial · está en buen estado</option>
              <option value="warranty">Garantía · presenta falla de origen</option>
              <option value="customer_damage">Posible daño por mal uso · requiere evaluación</option>
            </select>
          </label>
          {denied?<label>Resultado de la evaluación
            <select value={outcome} disabled={locked} onChange={e=>{setOutcome(e.target.value);setInspectionConfirmed(false);if(e.target.value==='rejected')setCustody('customer');}}>
              <option value="pending_review">Pendiente de evaluación técnica</option>
              <option value="rejected">Rechazado con justificación</option>
              <option value="technical_service">Derivar a servicio técnico</option>
            </select>
          </label>:<label>Solución solicitada
            <select value={resolution} disabled={locked} onChange={e=>setResolution(e.target.value)}>
              <option value="refund">Devolución del dinero</option>
              <option value="exchange">Cambio del producto</option>
              <option value="repair">Reparación</option>
            </select>
          </label>}
        </div>
        {denied?<p className="warning-box" role="alert">
          Registra una evaluación, no una devolución. No se modifica el inventario ni se genera reembolso. Un posible mal uso no acredita por sí solo el rechazo de una garantía; documenta la evidencia. Si hay defecto de origen, usa Garantía.
        </p>:returnKind==='warranty'?<p className="discount-approval-notice">
          <strong>Garantía por defecto de origen</strong> La recepción se registra, pero las unidades no se suman al stock vendible. En Chile, la garantía legal contempla devolución, cambio o reparación cuando corresponde; el caso se debe evaluar.
        </p>:<p className="helper">
          Solo acepta productos completos y en condiciones aptas para reventa. Estas unidades se reingresarán al local de venta.
        </p>}
        {denied&&<div className="returns-choice-grid">
          <label>¿Quién conserva el producto?
            <select value={custody} disabled={locked||outcome==='rejected'} onChange={e=>{setCustody(e.target.value);setInspectionConfirmed(false);}}>
              <option value="customer">Cliente (no queda retenido)</option>
              <option value="evaluation">Recibido para evaluación (no stock vendible)</option>
            </select>
          </label>
          <label>Referencia de evidencia o informe técnico {outcome==='rejected'?'(obligatoria)':'(opcional)'}
            <input value={evidence} maxLength={250} disabled={locked} required={outcome==='rejected'} onChange={e=>setEvidence(e.target.value)} placeholder="Identificador de informe o fotografías archivadas"/>
          </label>
        </div>}
        <label className="returns-inspection-confirm">
          <input type="checkbox" checked={inspectionConfirmed} disabled={locked} onChange={e=>setInspectionConfirmed(e.target.checked)}/>
          <span>{denied?'Confirmo la inspección y la situación de custodia indicada. Si rechazo, cuento con evidencia que sustenta la decisión y el producto queda con el cliente.':returnKind==='commercial'
            ?'Confirmo que recibí y revisé físicamente el producto, está completo y en condiciones aptas para reventa.'
            :'Confirmo que recibí físicamente el producto para tramitar una garantía, y que NO volverá al stock vendible.'}</span>
        </label>
        <div className="returns-line-list">
          {items.map(line=>{
            const available=Math.max(0,Number(line.quantity)-Number(line.returned_qty));
            return <div className="returns-line" key={line.sale_item_id}>
              <div><strong>{line.product_name_snapshot}</strong><small>{qtyFormat(available)} de {qtyFormat(line.quantity)} {line.sale_unit} disponibles para devolución · Vendido a {pesos(line.unit_price_clp)}</small></div>
              {line.serial_units?.length?<div><strong>{denied?'Selecciona las series evaluadas':'Selecciona las series recibidas'}</strong><p className="helper">{(chosenSeries[line.sale_item_id]||[]).length} seleccionadas</p><div style={{maxHeight:230,overflowY:'auto'}}>{line.serial_units.map(u=>{const picked=(chosenSeries[line.sale_item_id]||[]).includes(u.serial_number);const blocked=!!u.reversal_kind||u.status!=='sold';return <label key={u.unit_id}><input type="checkbox" checked={picked} disabled={locked||blocked||available<=0} onChange={e=>{const checked=e.target.checked;setChosenSeries(a=>({...a,[line.sale_item_id]:checked?[...new Set([...(a[line.sale_item_id]||[]),u.serial_number]) ]:(a[line.sale_item_id]||[]).filter(n=>n!==u.serial_number)}));}}/>{u.serial_number}{blocked?' · Ya retornada o no disponible':''}</label>;})}</div></div>:<label>{denied?'Cantidad evaluada':'Cantidad a devolver'}
                <input type="number" min="0" max={available} step={line.sale_unit==='unidad'?'1':'0.001'}
                  value={quantities[line.sale_item_id]??''} placeholder="0"
                  disabled={locked||available<=0}
                  onChange={e=>setQuantities(q=>({...q,[line.sale_item_id]:e.target.value}))}/>
              </label>}
            </div>;
          })}
          {!remaining&&<p className="muted">Todos los productos de esta venta ya fueron devueltos.</p>}
        </div>
        <label>{denied?'Observaciones y justificación de la evaluación':'Motivo y observaciones'}
          <textarea rows="3" required maxLength={denied?1000:250} minLength={denied?10:5} value={reason} disabled={locked}
            placeholder={denied?'Describe daños observados, comprobaciones y motivo de la decisión':'Ej. El cliente solicitó cambio; producto apto para reventa'}
            onChange={e=>setReason(e.target.value)}/>
        </label>
        {!denied&&<div className="returns-total"><span>Importe estimado de los productos seleccionados</span><strong>{pesos(total)}</strong></div>}
        {resolution==='refund'&&!denied&&<p className="helper">El importe se calcula sobre el precio efectivamente cobrado, no el precio actual. El reembolso quedará pendiente hasta que confirmes que se pagó fuera del sistema.</p>}
        {resolution!=='refund'&&!denied&&<p className="helper">El cambio o la reparación se registrará como solución solicitada; Tito POS no despacha productos de reemplazo ni gestiona reparaciones automáticamente.</p>}
        <button className="primary wide" disabled={locked||!inspectionConfirmed||!selected.length||!remaining||backendMissing||detail.sale_status!=='completed'||(denied&&outcome==='rejected'&&!evidence.trim())}>
          {busy?'Registrando…':denied?'Registrar evaluación':'Registrar devolución'}
        </button>
      </form>}
      {pending&&<div className="warning-box" role="alert">
        <strong>Operación con resultado no confirmado</strong>
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
        {detail.returns.map(r=><p key={r.id}>#{r.id} · {kindLabels[r.return_kind]} · {pesos(r.amount_clp)} · {resolutionLabels[r.resolution]}{r.serial_numbers?.length>0&&<small className="sub">Series: {r.serial_numbers.join(', ')}</small>}</p>)}
      </div>}
    </section>}

    {view==='assessments'&&<section className="panel pad">
      <div className="panel-heading"><div><h3>Evaluaciones de postventa</h3><p>Solicitudes y rechazos registrados sin movimientos de stock o dinero.</p></div><button className="ghost" onClick={()=>void loadAssessments()}>Actualizar</button></div>
      {assessmentError&&<p role="alert" className="warning-box">{assessmentError}</p>}
      {!assessmentError&&!assessments.length&&<p className="muted">No hay evaluaciones registradas.</p>}
      {assessments.map(a=><details key={a.id} className="returns-sale-history"><summary><strong>Evaluación #{a.id} · Venta #{a.sale_id} · {assessmentLabels[a.outcome]}</strong> · {dateChile(a.created_at)}</summary>
        <p>{a.observations}</p><p className="helper">Responsable: {a.actor_email} · Custodia: {a.custody==='customer'?'Cliente':'Recibido para evaluación'}</p>
        {a.evidence_reference&&<p>Referencia: {a.evidence_reference}</p>}
        {(a.items||[]).map(i=><p key={i.sale_item_id}>{i.product_name} · {qtyFormat(i.quantity)}{i.serial_numbers?.length?' · Series: '+i.serial_numbers.join(', '):''}</p>)}
        <button type="button" className="ghost mini" disabled={locked} onClick={()=>chooseSale(a.sale_id)}>Consultar venta</button>
      </details>)}
      <p className="helper">Últimas 100 evaluaciones. El seguimiento de servicio técnico, cambios de estado y adjuntos se incorporarán en una etapa posterior.</p>
    </section>}
    {view==='history'&&<section className="panel returns-history">
      <div className="panel-heading pad"><div><h3>Últimas devoluciones</h3><p>Historial auditable del negocio</p></div>
        <button className="ghost" type="button" disabled={loading} onClick={()=>void loadHistory()}>↻ Actualizar</button>
      </div>
      <div className="list-filters"><label>Buscar devolución<input type="search" value={historyQuery} onChange={e=>setHistoryQuery(e.target.value)} placeholder="N.º de venta, registro, tipo o cliente…" /></label><label>Período<select value={historyPeriod} onChange={e=>setHistoryPeriod(e.target.value)}><option value="all">Todos los cargados</option><option value="today">Hoy</option><option value="week">Esta semana</option><option value="month">Este mes</option></select></label></div>
      <p className="list-filter-note">La búsqueda y las páginas filtran solamente los registros cargados. No consultan todas las devoluciones históricas del servidor.</p>
      <div className="table-wrap">
        <table><thead><tr><th>Registro</th><th>Venta</th><th>Tipo</th><th>Solución</th><th>Monto</th><th>Reembolso</th></tr></thead>
          <tbody>{historyPageData.rows.map(r=><tr key={r.id}>
            <td><strong>#{r.id}</strong><small className="sub">{dateChile(r.created_at)}</small></td>
            <td><button type="button" className="ghost mini" disabled={locked} onClick={()=>chooseSale(r.sale_id)}>#{r.sale_id}</button></td>
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
    </section>}

    {view==='history'&&creditHistory.length>0&&<section className="panel returns-history">
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
