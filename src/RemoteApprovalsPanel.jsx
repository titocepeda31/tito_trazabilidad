import React,{Fragment,useEffect,useRef,useState} from 'react';
import {pesos,qtyFormat} from './api.js';
import {approvalLocalDateTime,paginateLegacyApprovals} from './remoteApprovalPagination.js';
import PushNotificationsPanel from './PushNotificationsPanel.jsx';

const statusLabel={pending:'Pendiente',approved:'Aprobada',rejected:'Rechazada',expired:'Vencida',completed:'Venta registrada',cancelled:'Cancelada'};
const PAGE_SIZE=10;
const emptyResult={requests:[],total:0,page:1,pages:1,per_page:PAGE_SIZE,legacy:false};

export default function RemoteApprovalsPanel({request,notify,onRefresh,loading,canManagePush=true}){
  const [working,setWorking]=useState(null);
  const [reasons,setReasons]=useState({});
  const [page,setPage]=useState(1);
  const [status,setStatus]=useState('all');
  const [from,setFrom]=useState('');
  const [to,setTo]=useState('');
  const [expanded,setExpanded]=useState(null);
  const [result,setResult]=useState(emptyResult);
  const [fetching,setFetching]=useState(true);
  const [error,setError]=useState('');
  const [revision,setRevision]=useState(0);
  const requestRef=useRef(request);
  requestRef.current=request;
  const invalidRange=!!(from&&to&&from>to);

  useEffect(()=>{
    let active=true,checking=false;
    if(invalidRange){
      setError('La fecha inicial no puede ser posterior a la fecha final.');
      setFetching(false);
      setResult(emptyResult);
      return ()=>{active=false;};
    }
    async function load(){
      if(!active||checking)return;
      checking=true;
      try{
        const params=new URLSearchParams({page:String(page),per_page:String(PAGE_SIZE),status});
        if(from)params.set('from',from);
        if(to)params.set('to',to);
        const data=await requestRef.current('/sales/discount-requests?'+params.toString());
        if(!active)return;
        const paged=Number.isInteger(Number(data.total))&&Number.isInteger(Number(data.pages));
        const next=paged?{...data,legacy:false}:paginateLegacyApprovals(data.requests||[],{page,perPage:PAGE_SIZE,status,from,to});
        setResult(next);
        setError('');
        if(next.page!==page)setPage(next.page);
      }catch(e){
        if(active)setError('No se pudieron consultar las autorizaciones: '+e.message);
      }finally{
        checking=false;
        if(active)setFetching(false);
      }
    }
    setFetching(true);
    void load();
    const timer=setInterval(load,12000);
    return ()=>{active=false;clearInterval(timer);};
  },[page,status,from,to,revision,invalidRange]);

  async function decide(id,decision){
    if(working!==null)return;
    setWorking(id);
    try{
      const data=await request('/sales/discount-requests/'+id+'/decision',{
        method:'POST',body:{decision,reason:reasons[id]||''}
      });
      notify('Solicitud #'+id+' '+(data.status==='approved'
        ?'aprobada. La sesión del cajero intentará registrar esa venta.'
        :'rechazada. El cajero verá el resultado.'),'success');
      setRevision(x=>x+1);
      await onRefresh();
    }catch(e){
      notify('No se pudo procesar la solicitud: '+e.message,'error');
      setRevision(x=>x+1);
    }finally{setWorking(null);}
  }

  const refresh=()=>{
    setFetching(true);
    setRevision(x=>x+1);
    void onRefresh();
  };
  const reset=()=>{
    setFrom('');setTo('');setStatus('all');setPage(1);
  };
  const details=r=><div className="approval-details">
    <div className="remote-request-lines">{(r.summary?.items||[]).map(p=><div key={p.product_id}>
      <strong>{p.name}</strong>
      <small>{qtyFormat(p.quantity)} {p.unit} · {pesos(p.list_price_clp)} → {pesos(p.unit_price_clp)} por unidad{p.discount_reason?' · '+p.discount_reason:''}</small>
    </div>)}</div>
    <div className="remote-request-totals">
      <span>Antes: {pesos(r.summary?.list_total_clp||0)}</span>
      <span>Rebaja: {pesos(r.summary?.discount_clp||0)}</span>
      <strong>Total: {pesos(r.summary?.total_clp||0)}</strong>
    </div>
    {r.status==='pending'&&<div className="remote-request-actions">
      <label>Motivo de rechazo (opcional)
        <input type="text" maxLength={250} value={reasons[r.id]||''}
          onChange={e=>setReasons(old=>({...old,[r.id]:e.target.value}))}
          placeholder="Ej. Descuento no autorizado"/>
      </label>
      <div className="inline-actions">
        <button type="button" className="ghost" disabled={working!==null} onClick={()=>decide(r.id,'reject')}>Rechazar</button>
        <button type="button" className="primary" disabled={working!==null} onClick={()=>decide(r.id,'approve')}>
          {working===r.id?'Procesando…':'Autorizar descuento'}
        </button>
      </div>
    </div>}
  </div>;

  const rows=result.requests||[];
  const total=Number(result.total)||0;
  const pages=Math.max(1,Number(result.pages)||1);
  const current=Math.min(page,pages);
  return <div className="remote-approvals-page">
    <div className="section-intro approval-page-heading"><div><p className="eyebrow">SUPERVISIÓN</p><h2>Autorizaciones</h2><p className="muted">Solicitudes de descuento y su historial.</p></div></div>
    <section className="panel pad approval-filter-panel" aria-label="Buscar y filtrar autorizaciones">
      <div className="approval-quick-filters">
        <label>Mostrar solicitudes<select value={status} onChange={e=>{setStatus(e.target.value);setPage(1);setExpanded(null);}}>
          <option value="all">Todas (pendientes primero)</option>
          <option value="pending">Solo pendientes</option>
          <option value="approved">Aprobadas</option>
          <option value="completed">Ventas registradas</option>
          <option value="rejected">Rechazadas</option>
          <option value="expired">Vencidas</option>
          <option value="cancelled">Canceladas</option>
        </select></label>
        <button type="button" className="ghost approval-refresh" disabled={loading||fetching} onClick={refresh} aria-label="Actualizar solicitudes">{loading||fetching?'Actualizando…':'↻ Actualizar'}</button>
      </div>
      <details className="approval-date-disclosure">
        <summary>Filtrar por fecha{from||to?' · Filtro activo':''}</summary>
        <div className="approval-date-filters">
          <label>Desde<input type="date" value={from} max={to||undefined} onChange={e=>{setFrom(e.target.value);setPage(1);setExpanded(null);}}/></label>
          <label>Hasta<input type="date" value={to} min={from||undefined} onChange={e=>{setTo(e.target.value);setPage(1);setExpanded(null);}}/></label>
          <button type="button" className="ghost" onClick={reset} disabled={!from&&!to&&status==='all'}>Limpiar filtros</button>
        </div>
      </details>
      <div className="approval-results-meta" role="status" aria-live="polite">
        <span>{fetching?'Consultando solicitudes…':total+' solicitud'+(total===1?'':'es')+' encontrada'+(total===1?'':'s')}</span>
        {!result.legacy&&typeof result.pending_count==='number'&&<span className={result.pending_count>0?'approval-pending-count':''}>Pendientes: {result.pending_count}</span>}
      </div>
      {error&&<p className="error-inline" role="alert">{error}</p>}
      {result.legacy&&<p className="warning-box" role="status">El servidor aún muestra como máximo 50 solicitudes; el filtro por fecha solo incluye esos registros.</p>}
    </section>

    <div className="approvals-desktop table-wrap panel">
      <table className="approvals-table">
        <thead><tr><th>Solicitud</th><th>Fecha (Chile)</th><th>Cajero</th><th>Total</th><th>Estado</th><th>Detalle</th></tr></thead>
        <tbody>{rows.map(r=><Fragment key={r.id}>
          <tr><td><strong>#{r.id}</strong></td><td>{approvalLocalDateTime(r.created_at)}</td>
            <td>{r.cashier_email}</td><td><strong>{pesos(r.summary?.total_clp||0)}</strong></td>
            <td><span className={'remote-status remote-status-'+r.status}>{statusLabel[r.status]||r.status}</span></td>
            <td><button type="button" className="ghost mini" aria-expanded={expanded===r.id}
              aria-controls={'approval-detail-'+r.id} onClick={()=>setExpanded(old=>old===r.id?null:r.id)}>
              {expanded===r.id?'Ocultar':'Ver detalle'}
            </button></td></tr>
          {expanded===r.id&&<tr className="approval-expanded"><td colSpan={6}><div id={'approval-detail-'+r.id}>{details(r)}</div></td></tr>}
        </Fragment>)}</tbody>
      </table>
      {!fetching&&rows.length===0&&<p className="empty">No hay solicitudes para los filtros seleccionados.</p>}
    </div>
    <div className="approvals-mobile remote-request-list">
      {rows.map(r=><article key={r.id} className="remote-request-card">
        <div className="remote-request-heading"><strong>Solicitud #{r.id}</strong>
          <span className={'remote-status remote-status-'+r.status}>{statusLabel[r.status]||r.status}</span></div>
        <p className="muted">Cajero: <strong>{r.cashier_email}</strong> · {approvalLocalDateTime(r.created_at)}</p>
        {details(r)}
      </article>)}
      {!fetching&&rows.length===0&&<section className="panel pad"><p className="muted">No hay solicitudes para los filtros seleccionados.</p></section>}
    </div>
    <nav className="approval-pager" aria-label="Páginas de autorizaciones">
      <button type="button" className="ghost" disabled={current<=1||fetching} onClick={()=>{setPage(p=>p-1);setExpanded(null);}}>Anterior</button>
      <span>Página {current} de {pages}</span>
      <button type="button" className="ghost" disabled={current>=pages||fetching} onClick={()=>{setPage(p=>p+1);setExpanded(null);}}>Siguiente</button>
    </nav>
    {canManagePush&&<details className="approval-push-disclosure">
      <summary><span>Configurar avisos push</span><small>Activar, probar o desactivar las notificaciones de este dispositivo</small></summary>
      <PushNotificationsPanel request={request} notify={notify}/>
    </details>}
  </div>;
}
