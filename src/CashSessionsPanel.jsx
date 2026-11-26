import React,{useEffect,useState} from 'react';
import {newId,pesos} from './api.js';
import {printCashReport} from './cashSessionReport.js';

const intClp=value=>{
  const text=String(value??'').trim();
  return /^\d{1,12}$/.test(text)?Number(text):NaN;
};
const clp=n=>pesos(Number(n)||0);

/**
 * La sesión sigue al cajero; los equipos identificados siguen determinando la
 * sucursal y NO trasladan físicamente los billetes.
 */
export default function CashSessionsPanel({request,notify,isAdmin,userId}){
  const [current,setCurrent]=useState(null);
  const [locations,setLocations]=useState([]);
  const [cashiers,setCashiers]=useState([]);
  const [adminDrawers,setAdminDrawers]=useState([]);
  const [assignedCashier,setAssignedCashier]=useState('');
  const [history,setHistory]=useState([]);
  const [detail,setDetail]=useState(null);
  const [busy,setBusy]=useState(false);
  const [loading,setLoading]=useState(true);
  const [opening,setOpening]=useState('0');
  const [drawerId,setDrawerId]=useState('');
  const [drawerLocation,setDrawerLocation]=useState('');
  const [drawerName,setDrawerName]=useState('');
  const [drawerCode,setDrawerCode]=useState('');
  const [counted,setCounted]=useState('');
  const [closingReason,setClosingReason]=useState('');
  const [withdrawal,setWithdrawal]=useState('');
  const [receiver,setReceiver]=useState('');
  const [withdrawalReason,setWithdrawalReason]=useState('');
  const [pendingAction,setPendingAction]=useState(()=>{try{return JSON.parse(sessionStorage.getItem('tito_pending_cash_action')||'null');}catch{return null;}});
  const [printing,setPrinting]=useState(false);

  async function refresh(){
    setLoading(true);
    try{
      const jobs=[request('/cash/current'),request('/cash/history')];
      if(isAdmin)jobs.push(request('/warehouses'),request('/users'),request('/cash/drawers'));

      const results=await Promise.all(jobs);
      setCurrent(results[0]);setHistory(results[1].sessions||[]);
      if(isAdmin){
        const warehouses=(results[2].warehouses||[]).filter(w=>Number(w.is_active)===1&&Number(w.is_sales_location)===1);
        setLocations(warehouses);setDrawerLocation(v=>warehouses.some(w=>String(w.id)===v)?v:String(warehouses[0]?.id||''));
        const users=(results[3].users||[]).filter(u=>Number(u.is_active)===1);
        setCashiers(users);setAssignedCashier(v=>users.some(u=>String(u.id)===v)?v:String(users[0]?.id||''));
        setAdminDrawers(results[4].drawers||[]);
      }
      setDrawerId(old=>results[0].drawers?.some(d=>String(d.id)===old)?old:String(results[0].drawers?.[0]?.id||''));
      if(detail?.session?.id){
        const d=await request('/cash/sessions/'+detail.session.id);
        setDetail(d);
      }
    }catch(e){notify('No se pudo consultar la caja: '+e.message,'error');}
    finally{setLoading(false);}
  }
  useEffect(()=>{void refresh();},[]);

  async function viewDetail(id){
    setBusy(true);
    try{setDetail(await request('/cash/sessions/'+id));}
    catch(e){notify('No se pudo abrir la rendición: '+e.message,'error');}
    finally{setBusy(false);}
  }

  async function execute(action,call){
    if(busy||pendingAction)return;
    const pending={action,body:call.body,url:call.url};
    setPendingAction(pending);sessionStorage.setItem('tito_pending_cash_action',JSON.stringify(pending));
    setBusy(true);
    try{
      await request(call.url,{method:'POST',body:call.body});
      setPendingAction(null);sessionStorage.removeItem('tito_pending_cash_action');
      notify(action+' registrada.','success');
      setCounted('');setClosingReason('');setWithdrawal('');setReceiver('');setWithdrawalReason('');
      await refresh();
    }catch(e){
      notify(action+' sin confirmar: '+e.message+'. Revisa el historial antes de volver a operar.','error');
    }finally{setBusy(false);}
  }
  async function resume(){
    if(!pendingAction||busy)return;
    setBusy(true);
    try{
      await request(pendingAction.url,{method:'POST',body:pendingAction.body});
      notify('Operación confirmada con su mismo identificador.','success');
      setPendingAction(null);sessionStorage.removeItem('tito_pending_cash_action');await refresh();
    }catch(e){notify('El resultado sigue sin confirmarse: '+e.message,'error');}
    finally{setBusy(false);}
  }
  async function printDetail(){
    if(!detail?.session||printing)return;
    const tab=window.open('','_blank');
    setPrinting(true);
    try{await printCashReport(detail,tab);}
    catch(e){if(tab&&!tab.closed)tab.close();notify('No se pudo imprimir la rendición: '+e.message,'error');}
    finally{setPrinting(false);}
  }
  async function createDrawer(e){
    e.preventDefault();if(busy)return;
    setBusy(true);
    try{
      await request('/cash/drawers',{method:'POST',body:{warehouse_id:Number(drawerLocation),code:drawerCode.trim(),name:drawerName.trim(),cashier_id:Number(assignedCashier)}});
      setDrawerCode('');setDrawerName('');notify('Gaveta registrada.','success');await refresh();
    }catch(e){notify('No se pudo crear la gaveta: '+e.message,'error');}
    finally{setBusy(false);}
  }
  async function assignDrawer(id,cashierId){
    if(busy)return;
    const cashier=cashiers.find(u=>Number(u.id)===cashierId);
    if(!cashier||!window.confirm('¿Asignar esta gaveta a '+cashier.email+'? Solo puedes hacerlo sin un turno abierto.'))return;
    setBusy(true);
    try{
      await request('/cash/drawers/'+id+'/assign',{method:'POST',body:{cashier_id:cashierId}});
      notify('Gaveta asignada al cajero.','success');await refresh();
    }catch(e){notify('No se pudo reasignar la gaveta: '+e.message,'error');}
    finally{setBusy(false);}
  }
  async function toggleRequired(){
    if(busy||!current)return;
    const required=!current.required;
    const warning=required
      ?'Al activar la exigencia, NINGÚN usuario podrá vender, cobrar fiados ni devolver efectivo sin su gaveta abierta. Verifica primero que todos los cajeros tengan una gaveta asignada y probada. ¿Activar?'
      :'¿Desactivar temporalmente la exigencia? Las operaciones sin un turno de caja ya no quedarán incluidas en la cuadratura.';
    if(!window.confirm(warning))return;
    setBusy(true);
    try{
      await request('/cash/policy',{method:'POST',body:{required}});
      notify(required?'Ahora se exige turno de caja abierto.':'Modo gradual reactivado.','success');
      await refresh();
    }catch(e){notify('No se pudo cambiar la política de caja: '+e.message,'error');}
    finally{setBusy(false);}
  }
  const session=current?.session;
  const selected=detail?.session;
  const detailIsOpen=selected?.status==='open';
  const owner=selected&&Number(selected.cashier_id)===Number(userId);
  const canClose=detailIsOpen&&(isAdmin||owner);
  const canWithdraw=detailIsOpen&&isAdmin;

  return <div className="cash-management">
    <div className="section-intro"><div>
      <p className="eyebrow">RENDICIÓN Y CUADRATURA</p><h2>Mi caja de dinero</h2>
      <p className="muted">La sesión acompaña al cajero entre terminales de la misma sucursal; la gaveta física y el dinero deben permanecer bajo su custodia.</p>
    </div><button type="button" className="ghost mini" disabled={loading||busy} onClick={refresh}>{loading?'Cargando…':'Actualizar'}</button></div>

    {current?.required?<p className="warning-box">Este negocio exige un turno abierto para registrar ventas. Si cambias de sucursal, cierra o entrega formalmente tu gaveta.</p>:<p className="helper">Modo gradual: las ventas realizadas sin un turno abierto todavía pueden registrarse, pero NO aparecerán en una rendición de caja. Antes de habilitarla, comprueba que cada empleado tenga su gaveta y que los cobros y devoluciones se registren correctamente.</p>}

    {isAdmin&&current&&<section className="panel pad">
      <h3>Política de rendición del negocio</h3>
      <p className="helper">{current.required?'Todas las ventas y cobros requieren un turno abierto.':'Actualmente las ventas sin caja abierta siguen permitidas para facilitar la transición.'}</p>
      <button className="ghost" type="button" disabled={busy} onClick={toggleRequired}>
        {current.required?'Volver temporalmente a modo gradual':'Exigir turno abierto para operar'}
      </button>
      <p className="helper">La activación no crea fondos de efectivo. Registra gavetas, asígnalas a cada usuario y abre sus turnos antes de exigir este control.</p>
    </section>}

    {pendingAction&&<div className="warning-box">
      <strong>Operación pendiente de confirmar: {pendingAction.action}</strong>
      <p>No registres otra operación equivalente. Reintenta únicamente el mismo identificador, después de revisar el historial.</p>
      <div className="inline-actions">
        <button className="ghost" type="button" disabled={busy} onClick={resume}>Reintentar la misma operación</button>
        <button className="ghost mini" type="button" disabled={busy} onClick={()=>{
          if(window.confirm('Descarta solo después de revisar los movimientos. El registro del servidor no se borra. ¿Continuar?')){
            sessionStorage.removeItem('tito_pending_cash_action');setPendingAction(null);
          }
        }}>Descartar aviso tras verificar</button>
      </div>
    </div>}

    {session?<section className="panel pad">
      <p className="eyebrow">TURNO ABIERTO · SUCURSAL {session.warehouse_name}</p>
      <h3>{session.drawer_name} · {session.drawer_code}</h3>
      <p className="helper">A cargo de {session.cashier_email}. Comenzó {session.opened_at} UTC. No está asociado de manera permanente a ningún computador o celular.</p>
      <div className="cash-figures">
        <div><span>Fondo inicial</span><strong>{clp(session.opening_clp)}</strong></div>
        <div><span>Ventas en efectivo</span><strong>{clp(session.cash_sales_clp)}</strong></div>
        <div><span>Abonos de fiados en efectivo</span><strong>+{clp(session.credit_received_cash_clp)}</strong></div>
        <div><span>Devoluciones de productos en efectivo</span><strong>−{clp(session.customer_refunds_cash_clp)}</strong></div>
        <div><span>Reembolsos de crédito en efectivo</span><strong>−{clp(session.credit_refunds_cash_clp)}</strong></div>
        <div><span>Retirado por recaudación</span><strong>−{clp(session.withdrawals_clp)}</strong></div>
        <div><span>Efectivo esperado</span><strong>{clp(session.current_expected_clp)}</strong></div>
      </div>
      <button className="ghost" type="button" disabled={busy} onClick={()=>viewDetail(session.id)}>Ver movimientos y cerrar turno</button>
      <p className="helper">Tarjetas, transferencias y fiados sin pagar no suman efectivo. Los abonos cobrados y reembolsos entregados sí ajustan el libro de dinero.</p>
    </section>:
    <section className="panel pad">
      <h3>Abrir una gaveta</h3>
      <p className="helper">El fondo inicial debe corresponder al efectivo que recibes físicamente. Solo puedes tener un turno abierto.</p>
      <form className="formstack" onSubmit={e=>{
        e.preventDefault();
        const amount=intClp(opening);
        if(!Number.isSafeInteger(amount)){notify('Ingresa un fondo inicial válido en pesos.','error');return;}
        if(!window.confirm('¿Confirmas haber recibido '+clp(amount)+' para abrir esta gaveta?'))return;
        void execute('Apertura de caja',{url:'/cash/sessions/open',body:{request_id:newId(),drawer_id:Number(drawerId),opening_clp:amount}});
      }}>
        <label>Gaveta de este local<select value={drawerId} disabled={busy} onChange={e=>setDrawerId(e.target.value)}>
          {(current?.drawers||[]).map(d=><option key={d.id} value={d.id}>{d.name} · {d.code}</option>)}
        </select></label>
        <label>Fondo inicial (CLP)<input type="number" min="0" max="100000000000" step="1" required value={opening} onChange={e=>setOpening(e.target.value)}/></label>
        <button className="primary" disabled={busy||!drawerId||Boolean(pendingAction)}>Abrir turno de caja</button>
      </form>
      {(current?.drawers||[]).length===0&&<p className="warning-box">No hay gavetas registradas en tu sucursal. Pide al administrador que cree una.</p>}
    </section>}

    {isAdmin&&<section className="panel pad">
      <h3>Registrar gaveta física</h3>
      <p className="helper">Cada gaveta tiene un código único por local. Crear una gaveta no abre un turno ni cambia el efectivo.</p>
      <form className="formstack" onSubmit={createDrawer}>
        <label>Sucursal<select value={drawerLocation} disabled={busy} onChange={e=>setDrawerLocation(e.target.value)}>{locations.map(w=><option key={w.id} value={w.id}>{w.name}</option>)}</select></label>
        <label>Cajero responsable<select value={assignedCashier} disabled={busy} onChange={e=>setAssignedCashier(e.target.value)}>{cashiers.map(u=><option key={u.id} value={u.id}>{u.email} · {u.role}</option>)}</select></label>
        <label>Nombre<input required maxLength={100} placeholder="Ej. Gaveta del mostrador 1" value={drawerName} onChange={e=>setDrawerName(e.target.value)}/></label>
        <label>Código<input required maxLength={32} pattern="[A-Za-z0-9][A-Za-z0-9_-]*" placeholder="CAJA-01" value={drawerCode} onChange={e=>setDrawerCode(e.target.value)}/></label>
        <button className="ghost" type="submit" disabled={busy||!drawerLocation||!assignedCashier}>Crear y asignar gaveta</button>
      </form>
      <h4>Gavetas existentes</h4>
      {adminDrawers.map(d=><div className="cash-history-row" key={d.id}>
        <div><strong>{d.name} · {d.code}</strong><small>{d.warehouse_name} · Responsable actual: {d.assigned_email||'Sin asignar'}</small></div>
        <select aria-label={'Asignar gaveta '+d.name} value={d.assigned_cashier_id||''} disabled={busy||Number(d.active_sessions)>0}
          onChange={e=>assignDrawer(Number(d.id),Number(e.target.value))}>
          <option value="">Seleccionar cajero</option>
          {cashiers.map(u=><option key={u.id} value={u.id}>{u.email}</option>)}
        </select>
      </div>)}
      <p className="helper">Una gaveta abierta no puede reasignarse hasta cerrar su turno y rendir el dinero.</p>
    </section>}

    <section className="panel pad">
      <h3>Turnos de caja recientes</h3>
      {!history.length?<p className="helper">Aún no hay turnos registrados.</p>:<div className="cash-history">
        {history.map(s=><div className="cash-history-row" key={s.id}>
          <div><strong>#{s.id} · {s.drawer_name}</strong><small>{s.warehouse_name} · {s.cashier_email} · {s.status==='open'?'Abierto':'Cerrado'}</small></div>
          <button className="ghost mini" disabled={busy} onClick={()=>viewDetail(s.id)}>Ver</button>
        </div>)}
      </div>}
    </section>

    {selected&&<section className="panel pad">
      <h3>Rendición del turno #{selected.id}</h3>
      <p className="helper">{selected.cashier_email} · {selected.drawer_name} · {selected.warehouse_name}</p>
      <div className="cash-figures">
        <div><span>Fondo inicial</span><strong>{clp(selected.opening_clp)}</strong></div>
        <div><span>Ventas en efectivo</span><strong>{clp(selected.cash_sales_clp)}</strong></div>
        <div><span>Abonos de fiados en efectivo</span><strong>+{clp(selected.credit_received_cash_clp)}</strong></div>
        <div><span>Devoluciones de productos en efectivo</span><strong>−{clp(selected.customer_refunds_cash_clp)}</strong></div>
        <div><span>Reembolsos de crédito en efectivo</span><strong>−{clp(selected.credit_refunds_cash_clp)}</strong></div>
        <div><span>Recaudaciones</span><strong>−{clp(selected.withdrawals_clp)}</strong></div>
        <div><span>Efectivo esperado</span><strong>{clp(selected.current_expected_clp)}</strong></div>
      </div>
      <h4>Ventas registradas por medio</h4>
      {(selected.sales_by_method||[]).map((p,i)=><div className="cash-breakdown" key={i}><span>{p.payment_method} ({p.n})</span><strong>{clp(p.total)}</strong></div>)}
      <h4>Abonos recibidos de fiados por medio</h4>
      {(selected.credit_payments_by_method||[]).map((p,i)=><div className="cash-breakdown" key={'abono'+i}><span>{p.method} ({p.n})</span><strong>{clp(p.total)}</strong></div>)}
      <h4>Devoluciones de productos por medio</h4>
      {(selected.customer_refunds_by_method||[]).map((p,i)=><div className="cash-breakdown" key={'devolucion'+i}><span>{p.method} ({p.n})</span><strong>−{clp(p.total)}</strong></div>)}
      <h4>Reembolsos de crédito por medio</h4>
      {(selected.credit_refunds_by_method||[]).map((p,i)=><div className="cash-breakdown" key={'credito'+i}><span>{p.method} ({p.n})</span><strong>−{clp(p.total)}</strong></div>)}
      <p className="helper">Tarjetas y transferencias se informan, pero no cuentan como efectivo físico ni como liquidaciones bancarias acreditadas.</p>
      {!detailIsOpen&&<div className="cash-close-result">
        <strong>Turno cerrado · Efectivo contado: {clp(selected.closing_counted_clp)}</strong>
        <p>Esperado al cierre: {clp(selected.closing_expected_clp)} · Diferencia: {clp(selected.closing_difference_clp)}</p>
        {selected.closing_reason&&<p>Justificación: {selected.closing_reason}</p>}
      </div>}
      <div className="cash-movements">
        <h4>Movimientos de efectivo (últimos 200)</h4>
        {(detail.movements||[]).map(m=><div className="cash-breakdown" key={m.id}>
          <span>{m.movement_type==='cash_sale'?'Venta #'+m.source_sale_id:
            m.movement_type==='credit_payment'?'Abono de fiado #'+m.source_credit_payment_id:
            m.movement_type==='customer_refund'?'Reembolso por devolución #'+m.source_customer_return_id:
            m.movement_type==='credit_refund'?'Reembolso de crédito #'+m.source_credit_refund_id:
            m.movement_type==='withdrawal'?'Retiro · '+(m.reference||''):'Ingreso'}
            <small>{m.reason} · {m.created_at} UTC</small></span>
          <strong>{Number(m.amount_delta_clp)>0?'+':''}{clp(m.amount_delta_clp)}</strong>
        </div>)}
      </div>
      {canWithdraw&&<form className="formstack" onSubmit={e=>{
        e.preventDefault();
        const amount=intClp(withdrawal);
        if(!Number.isSafeInteger(amount)||amount<=0){notify('Ingresa un retiro positivo en pesos.','error');return;}
        if(!window.confirm('¿Confirmas que '+receiver+' recibió físicamente '+clp(amount)+' desde esta gaveta?'))return;
        void execute('Recaudación parcial',{url:'/cash/sessions/'+selected.id+'/withdraw',body:{request_id:newId(),amount_clp:amount,receiver:receiver.trim(),reason:withdrawalReason.trim()}});
      }}>
        <h4>Registrar recaudación / retiro por el administrador</h4>
        <label>Monto entregado (CLP)<input type="number" min="1" required value={withdrawal} onChange={e=>setWithdrawal(e.target.value)}/></label>
        <label>Recibido por<input required maxLength={120} value={receiver} onChange={e=>setReceiver(e.target.value)}/></label>
        <label>Motivo<input required maxLength={250} value={withdrawalReason} onChange={e=>setWithdrawalReason(e.target.value)}/></label>
        <button className="ghost" disabled={busy||Boolean(pendingAction)}>Registrar entrega de dinero</button>
      </form>}
      {canClose&&<form className="formstack" onSubmit={e=>{
        e.preventDefault();
        const amount=intClp(counted);
        if(!Number.isSafeInteger(amount)){notify('Ingresa un monto contado válido.','error');return;}
        if(amount!==Number(selected.current_expected_clp)&&closingReason.trim().length<5){notify('Explica el faltante o sobrante antes de cerrar.','error');return;}
        if(!window.confirm('¿Cerrar definitivamente este turno con '+clp(amount)+' contados?'))return;
        void execute('Cuadratura y cierre',{url:'/cash/sessions/'+selected.id+'/close',body:{counted_clp:amount,reason:closingReason.trim()}});
      }}>
        <h4>Arqueo y cierre definitivo</h4>
        <label>Efectivo contado físicamente (CLP)<input type="number" min="0" required value={counted} onChange={e=>setCounted(e.target.value)}/></label>
        {counted!==''&&Number.isFinite(intClp(counted))&&<p className="helper">Diferencia prevista: {clp(intClp(counted)-Number(selected.current_expected_clp))}</p>}
        <label>Justificación (obligatoria ante diferencia)<textarea rows={2} maxLength={250} value={closingReason} onChange={e=>setClosingReason(e.target.value)}/></label>
        <button className="primary" disabled={busy||Boolean(pendingAction)}>Cerrar y cuadrar caja</button>
      </form>}
      <button type="button" className="ghost" disabled={printing} onClick={printDetail}>Imprimir rendición · PDF 80 mm</button>
      <button type="button" className="ghost mini" onClick={()=>setDetail(null)}>Cerrar detalle</button>
    </section>}
  </div>;
}
