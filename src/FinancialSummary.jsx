import React,{useEffect,useRef,useState} from 'react';
import {pesos} from './api.js';

const periods=[
  {key:'today',label:'Hoy'},
  {key:'week',label:'Esta semana'},
  {key:'month',label:'Este mes'},
  {key:'all_time',label:'Histórico'}
];
const periodName=scope=>periods.find(p=>p.key===scope)?.label||'Histórico';

export default function FinancialSummary({request,notify,variant='full'}){
  const [period,setPeriod]=useState('all_time');
  const [summary,setSummary]=useState(null);
  const [loading,setLoading]=useState(true);
  const [error,setError]=useState('');
  const serial=useRef(0);

  async function refresh(selected=period){
    const current=++serial.current;
    setLoading(true);
    setError('');
    try{
      const url='/reports/financial-summary'+(selected==='all_time'?'':'?period='+encodeURIComponent(selected));
      const result=await request(url);
      if(current!==serial.current)return;
      if(!result?.summary)throw new Error('La API no entregó un resumen financiero.');
      if(selected!=='all_time'&&result.summary.scope!==selected){
        // La API antigua ignora el parámetro period y devuelve el acumulado.
        // Nunca presentar esas cifras como si correspondieran a "Hoy".
        setPeriod('all_time');
        setError('La API todavía no admite filtros por fecha. Se mantiene el histórico hasta actualizar cPanel.');
        setSummary(result.summary);
        return;
      }
      setSummary(result.summary);
    }catch(e){
      if(current!==serial.current)return;
      setError('No se pudo consultar este período. Se conservan los últimos valores cargados.');
      if(e?.status!==404)notify('Error en resumen financiero: '+e.message,'error');
    }finally{
      if(current===serial.current)setLoading(false);
    }
  }
  useEffect(()=>{void refresh('all_time');return()=>{serial.current++;};},[]);

  const actualScope=summary?.scope||'all_time';
  const isHistoric=actualScope==='all_time';
  const owed=Number(summary?.receivables_clp||0);
  const refundDue=Number(summary?.credit_refund_due_clp||0);
  const selectedLabel=periodName(actualScope).toLowerCase();
  return <section className={'panel finance-panel finance-simple '+(variant==='compact'?'finance-compact':'')} aria-label="Resumen financiero">
    <div className="finance-heading pad">
      <div>
        <h3>Resumen del negocio</h3>
        <p className="muted">Ventas y pagos registrados en Tito POS, no saldo de caja ni dinero en el banco.</p>
      </div>
      <button type="button" className="ghost mini" onClick={()=>void refresh(period)} disabled={loading}>{loading?'Actualizando…':'↻ Actualizar'}</button>
    </div>
    <div className="finance-periods" role="group" aria-label="Período del resumen financiero">
      {periods.map(p=><button key={p.key} type="button"
        className={'finance-period '+(period===p.key?'is-active':'')}
        aria-pressed={period===p.key}
        disabled={loading}
        onClick={()=>{setPeriod(p.key);void refresh(p.key);}}>{p.label}</button>)}
    </div>
    {error&&<p role="alert" className="finance-error">{error}</p>}
    {!summary?<p className="helper pad">{loading?'Cargando resumen…':'No hay datos disponibles. Comprueba la conexión con la API.'}</p>:
    <div aria-busy={loading?'true':'false'} className={loading?'finance-busy':''}>
      <div className="finance-main-card">
        <span className="finance-caption">Ventas netas · {selectedLabel}</span>
        <strong className="finance-main-amount">{pesos(summary.net_sales_clp)}</strong>
        <p>Ventas registradas en el período, vigentes hoy, menos devoluciones asociadas.</p>
      </div>
      <div className="finance-secondary-grid">
        <article className="finance-secondary-card">
          <span>Cobros registrados · {selectedLabel}</span>
          <strong>{pesos(summary.net_collections_declared_clp)}</strong>
          <small>Pagos declarados, descontando reembolsos confirmados. No es saldo de caja.</small>
        </article>
        <article className="finance-secondary-card">
          <span>Clientes que deben · ahora</span>
          <strong>{pesos(owed)}</strong>
          <small>Deuda a crédito pendiente de todos los períodos.</small>
        </article>
      </div>
      {refundDue>0&&<div className="finance-refund-alert" role="note">
        <strong>Hay {pesos(refundDue)} a favor de clientes a crédito.</strong>
        <span>Abonos que exceden lo adeudado, pendientes de reembolso registrado.</span>
      </div>}
      <details className="finance-breakdown">
        <summary>Ver detalle de ventas, devoluciones y cobros</summary>
        <div className="finance-breakdown-body">
          <h4>Ventas del período</h4>
          <div className="finance-breakdown-row"><span>Ventas vigentes antes de devoluciones</span><strong>{pesos(summary.gross_active_sales_clp)}</strong></div>
          <div className="finance-breakdown-row"><span>Devoluciones asociadas a esas ventas</span><strong>−{pesos(summary.accepted_returns_clp)}</strong></div>
          <div className="finance-breakdown-row finance-breakdown-total"><span>Ventas netas</span><strong>{pesos(summary.net_sales_clp)}</strong></div>
          <div className="finance-breakdown-row"><span>Ventas del período anuladas (excluidas)</span><strong>{pesos(summary.voided_sales_clp)}</strong></div>
          <h4>Dinero registrado en el período</h4>
          <div className="finance-breakdown-row"><span>Cobros inmediatos declarados</span><strong>{pesos(summary.immediate_declared_clp)}</strong></div>
          <div className="finance-breakdown-row"><span>Abonos de clientes a crédito</span><strong>{pesos(summary.credit_payments_clp)}</strong></div><div className="finance-breakdown-row"><span>Anticipos recibidos</span><strong>{pesos(summary.advances_received_clp||0)}</strong></div><div className="finance-breakdown-row"><span>Anticipos aún no aplicados (saldo actual)</span><strong>{pesos(summary.advances_available_clp||0)}</strong></div>
          <div className="finance-breakdown-row"><span>Reembolsos confirmados</span><strong>−{pesos(summary.refunds_confirmed_clp)}</strong></div>
          <div className="finance-breakdown-row finance-breakdown-total"><span>Cobros netos registrados</span><strong>{pesos(summary.net_collections_declared_clp)}</strong></div>
          <div className="finance-breakdown-row"><span>Saldo a favor de clientes a crédito · ahora</span><strong>{pesos(refundDue)}</strong></div>
        </div>
      </details>
    </div>}
    <p className="finance-footnote">
      {isHistoric
        ? 'Histórico: incluye todas las operaciones registradas desde el inicio.'
        : 'Ventas según la fecha de la venta; devoluciones y anulaciones reflejan su situación actual. Los cobros se agrupan por fecha de recepción y los reembolsos por fecha de confirmación.'}
      {' '}Las cuentas por cobrar y los saldos a favor son siempre saldos actuales, no históricos. Los cobros pueden superar las ventas netas por anulaciones o devoluciones pendientes de reembolsar.
    </p>
  </section>;
}
