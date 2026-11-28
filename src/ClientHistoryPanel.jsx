import React,{useEffect,useRef,useState} from 'react';
import {pesos,qtyFormat} from './api.js';
import ClientReceivablePanel from './ClientReceivablePanel.jsx';
const quoteStates={draft:'Borrador',sent:'Enviada',accepted:'Aceptada',rejected:'Rechazada',expired:'Vencida',reserved:'Reservada',reservation_expired:'Reserva vencida',partial_closed:'Cerrada parcialmente',converted:'Convertida'};
const resStates={pending:'Pendiente',expired:'Vencida',cancelled:'Cancelada',dispatched:'Despachada'};
const saleStates={completed:'Registrada',voided:'Anulada'};
const date=v=>String(v||'').slice(0,16)||'—';
const label=(map,v)=>map[v]||v||'—';
const payment={efectivo:'Efectivo',transferencia:'Transferencia',tarjeta:'Tarjeta',otro:'Otro'};
const tabs=[
 ['service','Servicio técnico',[['id','Caso',v=>'#'+v],['sale_id','Venta'],['product_name','Equipo'],['serial_number','Serie'],['status','Estado',v=>label({received:'Ingresado',diagnosis:'Diagnóstico',repair:'Reparación',ready:'Listo',closed:'Cerrado'},v)],['custody','Custodia',v=>label({customer:'Cliente',service:'Servicio técnico',manufacturer:'Fabricante'},v)],['problem','Motivo'],['created_at','Fecha',date]]],
 ['receivables','Cuentas por cobrar',[['sale_id','Venta',v=>'#'+v],['created_at','Fecha',date],['due_date','Vencimiento'],['original_total_clp','Original',pesos],['adjusted_clp','Ajustes',pesos],['paid_clp','Abonado',pesos],['balance_clp','Pendiente',pesos],['refund_due_clp','Por devolver',pesos],['status','Estado',(v,r)=>v==='voided'?'Anulada':Number(r.balance_clp)>0?'Pendiente':'Sin saldo']]],
 ['followups','Seguimiento',[['quote_id','Cotización'],['kind','Tipo',v=>label({contact:'Contacto',task:'Acción'},v)],['channel','Canal',v=>label({phone:'Teléfono',email:'Correo',whatsapp:'WhatsApp',meeting:'Reunión',other:'Otro'},v)],['note','Detalle'],['assigned_email','Responsable'],['due_on','Fecha límite'],['status','Estado',v=>label({recorded:'Registrado',pending:'Pendiente',done:'Realizada',cancelled:'Cancelada'},v)],['created_at','Registro',date],['close_note','Resultado']]],
 ['quotes','Cotizaciones',[['id','N.º',v=>'#'+v],['created_at','Fecha',date],['effective_status','Estado',v=>label(quoteStates,v)],['valid_until','Vigencia'],['total_clp','Total',pesos]]],
 ['reservations','Reservas',[['id','N.º',v=>'#'+v],['quote_id','Cotización'],['warehouse_name','Bodega'],['created_at','Fecha',date],['expires_on','Fecha límite'],['effective_status','Estado',v=>label(resStates,v)],['remaining_lines','Líneas con saldo', (v,r)=>r.effective_status==='pending'?v:'—']]],
 ['sales','Ventas',[['id','Venta',v=>'#'+v],['created_at','Fecha',date],['status','Estado',v=>label(saleStates,v)],['quote_id','Cotización'],['warehouse_name','Bodega'],['payment_method','Medio',v=>label(payment,v)],['total_clp','Total',pesos]]],
 ['products','Productos adquiridos',[['sale_id','Venta',v=>'#'+v],['created_at','Fecha',date],['product_name','Producto'],['sku','SKU'],['quantity','Vendido',qtyFormat],['returned_quantity','Devuelto',qtyFormat],['unit_price_clp','Precio',pesos],['subtotal_clp','Subtotal',pesos],['sale_status','Estado venta',v=>label(saleStates,v)]]],
 ['series','Números de serie',[['sale_id','Venta',v=>'#'+v],['created_at','Fecha',date],['product_name','Equipo'],['serial_number','Serie'],['sale_status','Estado venta',v=>label(saleStates,v)],['reversal_kind','Reversa',v=>label({commercial:'Devolución comercial',warranty:'Garantía',void:'Anulación'},v)||'—'],['return_id','Devolución'],['credit_adjustment_id','Ajuste de crédito']]],
 ['returns','Devoluciones',[['id','N.º',v=>'#'+v],['sale_id','Venta'],['created_at','Fecha',date],['reason','Motivo'],['resolution','Resolución'],['amount_clp','Importe',pesos],['refund_status','Reembolso']]],
 ['assessments','Evaluaciones de posventa',[['id','N.º',v=>'#'+v],['sale_id','Venta'],['created_at','Fecha',date],['outcome','Resultado'],['observations','Observaciones'],['custody','Custodia']]]
];
export default function ClientHistoryPanel({client,request,onBack}){
 const [tab,setTab]=useState('quotes'),[page,setPage]=useState(1),[rows,setRows]=useState([]),[more,setMore]=useState(false),[busy,setBusy]=useState(true),[error,setError]=useState('');
 const [account,setAccount]=useState(null);
 const gen=useRef(0);
 async function load(t,p){const n=++gen.current;setTab(t);setPage(p);setBusy(true);setError('');setRows([]);setMore(false);try{const d=await request('/clients/commercial/'+client.id+'/history?tab='+encodeURIComponent(t)+'&page='+p);if(gen.current!==n)return;setRows(d.rows||[]);setMore(Boolean(d.more));}catch(e){if(gen.current===n)setError(e.message);}finally{if(gen.current===n)setBusy(false);}}
 useEffect(()=>{void load('quotes',1);return()=>{gen.current++;};},[]);
 const cols=tabs.find(t=>t[0]===tab)[2];
 if(account)return <ClientReceivablePanel key={account.id} accountId={account.id} client={client} request={request} onBack={()=>{setAccount(null);void load('receivables',page);}}/>;
 return <div className="clients-page"><div className="section-intro"><div><button type="button" className="text-button" onClick={onBack}>← Volver a clientes</button><p className="eyebrow">GESTIÓN COMERCIAL / CLIENTES</p><h2>Historial comercial</h2><p>{client.name}{client.rut?' · '+client.rut:''}</p></div></div>
 <section className="panel pad formstack"><div className="inline-actions" style={{flexWrap:'wrap'}}>{tabs.map(([v,t])=><button type="button" key={v} className={tab===v?'primary':'ghost'} aria-pressed={tab===v} onClick={()=>void load(v,1)}>{t}</button>)}</div><p className="helper">Registros asociados a la identidad del cliente. No incluye documentos históricos sin vínculo; las anulaciones y devoluciones se muestran como historial, no como propiedad actual del equipo.</p>
 {error&&<p role="alert" className="warning-box">{error}</p>}{busy&&<p>Cargando historial…</p>}
 {!busy&&!error&&<><div className="table-wrap"><table><thead><tr>{cols.map(([k,l])=><th key={k}>{l}</th>)}{tab==='receivables'&&<th>Detalle</th>}</tr></thead><tbody>{rows.map((r,i)=><tr key={r.id??(r.sale_id+'-'+r.unit_id+'-'+i)}>{cols.map(([k,l,f])=><td key={k}>{f?f(r[k],r):r[k]??'—'}</td>)}{tab==='receivables'&&<td><button type="button" className="ghost mini" onClick={()=>setAccount(r)}>Ver / abonar</button></td>}</tr>)}</tbody></table>{!rows.length&&<p className="muted">No hay registros vinculados en esta página.</p>}</div><div className="list-pagination"><span>Página {page} · hasta 25 registros</span><div className="inline-actions"><button type="button" className="ghost mini" disabled={page<=1} onClick={()=>void load(tab,page-1)}>Anterior</button><button type="button" className="ghost mini" disabled={!more} onClick={()=>void load(tab,page+1)}>Siguiente</button><button type="button" className="ghost mini" onClick={()=>void load(tab,page)}>Actualizar</button></div></div></>}
 {error&&<button type="button" className="ghost" onClick={()=>void load(tab,page)}>Reintentar</button>}</section></div>;
}
