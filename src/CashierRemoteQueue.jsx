import React from 'react';
import {pesos} from './api.js';
import {remoteActive} from './remoteSaleQueue.js';

const labels={
  pending:'Esperando autorización',
  approved:'Autorizada',
  completed:'Venta realizada',
  rejected:'Rechazada',
  expired:'Vencida',
  cancelled:'Cancelada'
};

export default function CashierRemoteQueue({queue,onRetry,onCancel,onDismiss,onShowSales}){
  if(!queue.length)return null;
  const waiting=queue.filter(remoteActive).length;
  return <section className="panel pad remote-sales-queue" aria-label="Ventas con autorización remota">
    <h3>Solicitudes de venta ({waiting} activas)</h3>
    <p className="helper">Puedes seguir atendiendo otros clientes. Cada solicitud y su venta se procesan por separado; aprobar una no modifica el carrito actual.</p>
    <div className="remote-request-list">
      {queue.map(entry=><article className="remote-request-card" key={entry.id}>
        <div className="remote-request-heading">
          <strong>Solicitud #{entry.id}</strong>
          <span className={'remote-status remote-status-'+entry.status}>{labels[entry.status]||entry.status}</span>
        </div>
        <p className="muted">
          {entry.summary?.total_clp!=null?'Total: '+pesos(entry.summary.total_clp)+' · ':''}
          {entry.sale.items.length} producto(s) · {entry.sale.payment_method}
        </p>
        {entry.processing&&<p role="status">Registrando o actualizando esta solicitud…</p>}
        {entry.status==='pending'&&<p>Esperando decisión del administrador. Puedes continuar con otra venta.</p>}
        {entry.status==='approved'&&!entry.processing&&<p>{entry.attempted?'Descuento autorizado; el registro de venta necesita revisión o reintento.':'Descuento autorizado. Se registrará automáticamente en esta sesión.'}</p>}
        {entry.status==='rejected'&&<p>Descuento rechazado. Esta venta no se registró.</p>}
        {entry.status==='expired'&&<p>La autorización venció. Esta venta no se registró.</p>}
        {entry.status==='cancelled'&&<p>Solicitud cancelada. Esta venta no se registró.</p>}
        {entry.reason&&<p>Motivo: {entry.reason}</p>}
        {entry.error&&<p className="error-inline" role="alert">{entry.error}</p>}
        {entry.status==='completed'&&entry.receipt&&<div className="sale-success-details" role="status">
          <div><small>¡Venta realizada!</small><strong>#{entry.receipt.id}</strong></div>
          <div><small>Total</small><strong>{pesos(entry.receipt.total)}</strong></div>
          <div><small>Medio de pago</small><strong>{entry.receipt.payment}</strong></div>
        </div>}
        <div className="inline-actions">
          {entry.status==='approved'&&entry.attempted&&!entry.processing&&
            <button type="button" className="primary" onClick={()=>onRetry(entry.id)}>Reintentar esta venta</button>}
          {remoteActive(entry)&&!entry.processing&&
            <button type="button" className="ghost" onClick={()=>onCancel(entry.id)}>Cancelar solicitud</button>}
          {entry.status==='completed'&&entry.receipt&&<button type="button" className="ghost" onClick={onShowSales}>Ver en historial</button>}
          {!remoteActive(entry)&&!entry.processing&&
            <button type="button" className="ghost" onClick={()=>onDismiss(entry.id)}>Ocultar de esta lista</button>}
        </div>
      </article>)}
    </div>
  </section>;
}
