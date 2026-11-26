import React,{useEffect,useMemo,useState} from 'react';
import {newId,pesos} from './api.js';
import CreditOperationsPanel from './CreditOperationsPanel.jsx';

const pendingKey='tito_pending_credit_payment';
const methodNames={efectivo:'Efectivo',transferencia:'Transferencia',tarjeta:'Tarjeta',otro:'Otro'};
const emptyClient={name:'',phone:'',notes:'',credit_enabled:false,credit_limit_clp:''};
const dateChile=s=>{
  if(!s)return 'Sin fecha';
  const d=new Date(String(s).replace(' ','T')+'Z');
  return Number.isFinite(d.valueOf())?d.toLocaleDateString('es-CL',{timeZone:'America/Santiago'}):String(s);
};
export default function ClientsPanel({request,notify,onChange}){
  const [clients,setClients]=useState([]);
  const [accounts,setAccounts]=useState([]);
  const [loading,setLoading]=useState(true);
  const [busy,setBusy]=useState(false);
  const [form,setForm]=useState(null);
  const [editingId,setEditingId]=useState(null);
  const [activeAccount,setActiveAccount]=useState(null);
  const [accountDetail,setAccountDetail]=useState(null);
  const [showOnlyOpen,setShowOnlyOpen]=useState(true);
  const [amount,setAmount]=useState('');
  const [method,setMethod]=useState('transferencia');
  const [reference,setReference]=useState('');
  const [pending,setPending]=useState(()=>{
    try{return JSON.parse(sessionStorage.getItem(pendingKey)||'null');}catch{return null;}
  });
  const outstanding=accounts.reduce((sum,a)=>sum+Number(a.balance_clp),0);
  const refundDue=accounts.reduce((sum,a)=>sum+Number(a.refund_due_clp||0),0);
  const openCount=accounts.filter(a=>Number(a.balance_clp)>0).length;
  const shown=useMemo(()=>accounts.filter(a=>!showOnlyOpen||Number(a.balance_clp)>0||Number(a.refund_due_clp||0)>0),[accounts,showOnlyOpen]);
  const locked=busy||Boolean(pending);
  async function refresh(){
    setLoading(true);
    try{
      const [c,a]=await Promise.all([request('/clients'),request('/credit/accounts')]);
      setClients(c.clients||[]);setAccounts(a.accounts||[]);
    }catch(e){notify('No se pudieron cargar clientes y cuentas por cobrar: '+e.message,'error');}
    finally{setLoading(false);}
  }
  useEffect(()=>{void refresh();},[]);
  async function inspect(accountId){
    try{
      const result=await request('/credit/accounts/'+accountId);
      setAccountDetail(result);
      setActiveAccount(accountId);
      setAmount(String(result.account.balance_clp));
    }catch(e){notify('No se pudo abrir la cuenta: '+e.message,'error');}
  }
  function startCreate(){setForm({...emptyClient});setEditingId(null);}
  function startEdit(c){
    setForm({name:c.name,phone:c.phone||'',notes:c.notes||'',credit_enabled:Number(c.credit_enabled)===1,credit_limit_clp:String(c.credit_limit_clp||''),is_active:Number(c.is_active)===1});
    setEditingId(Number(c.id));
  }
  async function saveClient(e){
    e.preventDefault();if(!form||locked)return;
    const body={name:form.name.trim(),phone:form.phone.trim()||null,notes:form.notes.trim()||null,credit_enabled:Boolean(form.credit_enabled),credit_limit_clp:Number(form.credit_limit_clp||0)};
    if(editingId!==null)body.is_active=Boolean(form.is_active);
    if(!body.name){notify('Indica el nombre del cliente.','error');return;}
    if(!Number.isSafeInteger(body.credit_limit_clp)||body.credit_limit_clp<0||(body.credit_enabled&&body.credit_limit_clp<=0)){notify('El límite de crédito debe ser un monto positivo para clientes habilitados.','error');return;}
    setBusy(true);
    try{
      await request(editingId===null?'/clients':'/clients/'+editingId+'/update',{method:'POST',body});
      notify(editingId===null?'Cliente registrado.':'Cliente actualizado.','success');
      setForm(null);setEditingId(null);await refresh();
    }catch(err){notify('No se pudo guardar el cliente: '+err.message,'error');}
    finally{setBusy(false);}
  }
  function beginPayment(a){
    if(pending){notify('Hay un abono sin confirmar: verifica su resultado antes de registrar otro.','error');return;}
    void inspect(Number(a.id));
  }
  async function savePayment(body){
    setBusy(true);
    try{
      const result=await request('/credit/accounts/'+body.account_id+'/payments',{
        method:'POST',
        body:{request_id:body.request_id,amount_clp:body.amount_clp,method:body.method,reference:body.reference}
      });
      sessionStorage.removeItem(pendingKey);setPending(null);
      notify(result.already_recorded?'El abono ya había sido registrado; no se duplicó.':'Abono registrado. Saldo restante: '+pesos(result.balance_clp),'success');
      await refresh();await inspect(body.account_id);await onChange();
      setReference('');
    }catch(e){notify('No se pudo confirmar el abono: '+e.message+'. Verifica el historial y reintenta con el mismo identificador; no registres otro abono.','error');}
    finally{setBusy(false);}
  }
  function submitPayment(e){
    e.preventDefault();
    if(!activeAccount||locked||!accountDetail)return;
    const value=Number(amount),balance=Number(accountDetail.account.balance_clp);
    if(!Number.isSafeInteger(value)||value<=0||value>balance){notify('El abono debe ser mayor a cero y no superar el saldo pendiente.','error');return;}
    if(!window.confirm('¿Confirmas que RECIBISTE '+pesos(value)+' mediante '+methodNames[method]+'? Tito POS solo registra el cobro, no procesa pagos.'))return;
    const body={request_id:newId(),account_id:activeAccount,amount_clp:value,method,reference:reference.trim()||null};
    sessionStorage.setItem(pendingKey,JSON.stringify(body));
    setPending(body);void savePayment(body);
  }
  return <div className="clients-page">
    <div className="section-intro">
      <div><p className="eyebrow">CLIENTES HABITUALES</p><h2>Clientes y cuentas por cobrar</h2>
        <p className="muted">Autoriza crédito para clientes de confianza y registra los abonos cuando recibas el dinero.</p></div>
      <button className="primary" onClick={startCreate} disabled={locked}>+ Nuevo cliente</button>
    </div>
    <div className="stats-grid">
      <div className="stat-card"><div className="stat-head"><span>Por cobrar (cuentas listadas)</span><span className="stat-icon">⌁</span></div><strong>{pesos(outstanding)}</strong><small>Deudas pendientes después de devoluciones.</small></div>
      <div className="stat-card"><div className="stat-head"><span>Cuentas pendientes</span><span className="stat-icon">◷</span></div><strong>{openCount}</strong><small>Se muestran hasta 200 cuentas recientes.</small></div>
      <div className="stat-card"><div className="stat-head"><span>Por reembolsar (cuentas listadas)</span><span className="stat-icon">↩</span></div><strong>{pesos(refundDue)}</strong><small>Abonos de clientes que exceden el importe neto de la compra.</small></div>
    </div>
    <section className="panel credit-client-panel">
      <div className="panel-heading pad"><div><h3>Clientes habituales</h3><p>Solo el administrador habilita crédito y establece su límite.</p></div>
        <button className="ghost mini" onClick={()=>void refresh()} disabled={loading}>↻ Actualizar</button>
      </div>
      <div className="table-wrap">
        <table><thead><tr><th>Cliente</th><th>Contacto</th><th>Crédito</th><th>Límite</th><th>Pendiente</th><th></th></tr></thead>
          <tbody>{clients.map(c=><tr key={c.id}>
            <td><strong>{c.name}</strong>{Number(c.is_active)!==1&&<small className="sub">Inactivo</small>}</td>
            <td>{c.phone||'—'}</td>
            <td><span className={'pill '+(Number(c.credit_enabled)===1?'green':'amber')}>{Number(c.credit_enabled)===1?'Autorizado':'No autorizado'}</span></td>
            <td>{pesos(c.credit_limit_clp)}</td>
            <td><strong>{pesos(c.outstanding_clp)}</strong></td>
            <td><button className="ghost mini" disabled={locked} onClick={()=>startEdit(c)}>Editar</button></td>
          </tr>)}</tbody></table>
        {!clients.length&&!loading&&<div className="receipt-empty">No hay clientes habituales. Crea uno y autoriza el crédito solo cuando corresponda.</div>}
      </div>
    </section>
    <section className="panel credit-account-panel">
      <div className="panel-heading pad"><div><h3>Cuentas por cobrar</h3><p>Ventas fiadas y pagos recibidos, con historial por cliente.</p></div>
        <label className="credit-open-switch"><input type="checkbox" checked={showOnlyOpen} onChange={e=>setShowOnlyOpen(e.target.checked)}/> Solo deudas o reembolsos pendientes</label>
      </div>
      <div className="table-wrap">
        <table><thead><tr><th>Venta</th><th>Cliente</th><th>Estado</th><th>Compromiso</th><th>Original</th><th>Ajustes</th><th>Pagado</th><th>Saldo</th><th>A favor</th><th>Acción</th></tr></thead>
          <tbody>{shown.map(a=><tr key={a.id}>
            <td><strong>#{a.sale_id}</strong><small className="sub">{dateChile(a.created_at)}</small></td>
            <td>{a.client_name}</td>
            <td>{a.status==='voided'?'Anulada':'Vigente'}</td>
            <td>{a.due_date||'Sin fecha acordada'}</td>
            <td>{pesos(a.original_total_clp)}</td>
            <td>{pesos(a.adjusted_clp??0)}</td>
            <td>{pesos(a.paid_clp)}</td>
            <td><strong>{pesos(a.balance_clp)}</strong></td>
            <td><strong>{pesos(a.refund_due_clp??0)}</strong></td>
            <td><button className="ghost mini" disabled={busy} onClick={()=>beginPayment(a)}>Ver / gestionar</button></td>
          </tr>)}</tbody>
        </table>
        {!shown.length&&!loading&&<div className="receipt-empty">No se encontraron cuentas para mostrar.</div>}
      </div>
      <p className="helper pad">Las cuentas conservan el valor original; los ajustes de devoluciones y anulaciones se registran por separado. Los totales superiores corresponden solo a las 200 cuentas listadas. El sistema no mueve dinero ni emite documentos tributarios.</p>
    </section>

    {form&&<div className="modal-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget&&!busy)setForm(null);}}>
      <div className="modal" role="dialog" aria-modal="true" aria-label="Ficha de cliente habitual">
        <div className="modal-head"><h3>{editingId===null?'Nuevo cliente habitual':'Editar cliente habitual'}</h3>
          <button className="ghost close" type="button" disabled={busy} onClick={()=>setForm(null)} aria-label="Cerrar">×</button></div>
        <form className="formstack" onSubmit={saveClient}>
          <label>Nombre del cliente o empresa<input required maxLength={150} value={form.name} disabled={busy} onChange={e=>setForm(c=>({...c,name:e.target.value}))}/></label>
          <label>Teléfono o contacto<input maxLength={80} value={form.phone} disabled={busy} onChange={e=>setForm(c=>({...c,phone:e.target.value}))}/></label>
          <label>Observaciones<input maxLength={300} value={form.notes} disabled={busy} onChange={e=>setForm(c=>({...c,notes:e.target.value}))}/></label>
          <label className="credit-toggle"><input type="checkbox" checked={form.credit_enabled} disabled={busy} onChange={e=>setForm(c=>({...c,credit_enabled:e.target.checked}))}/> Autorizar compras a crédito</label>
          <label>Límite máximo de deuda ($ CLP)<input type="number" min="0" step="1" required={form.credit_enabled} value={form.credit_limit_clp} disabled={busy} onChange={e=>setForm(c=>({...c,credit_limit_clp:e.target.value}))} placeholder="Ej. 300000"/></label>
          {editingId!==null&&<label className="credit-toggle"><input type="checkbox" checked={form.is_active} disabled={busy} onChange={e=>setForm(c=>({...c,is_active:e.target.checked}))}/> Cliente activo</label>}
          <p className="helper">El límite se aplica a la suma de todas sus cuentas pendientes. Habilitar crédito no implica entregar dinero automáticamente.</p>
          <button className="primary wide" disabled={busy}>{busy?'Guardando…':'Guardar cliente'}</button>
        </form>
      </div>
    </div>}
    {activeAccount!==null&&accountDetail&&<div className="modal-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget&&!busy)setActiveAccount(null);}}>
      <div className="modal" role="dialog" aria-modal="true" aria-label="Detalle de cuenta por cobrar">
        <div className="modal-head"><h3>Cuenta por cobrar · Venta #{accountDetail.account.sale_id}</h3>
          <button className="ghost close" type="button" disabled={busy} onClick={()=>setActiveAccount(null)} aria-label="Cerrar">×</button></div>
        <p><strong>{accountDetail.account.client_name}</strong></p>
        <div className="credit-detail-summary">
          <span>Venta original: <strong>{pesos(accountDetail.account.original_total_clp)}</strong></span>
          <span>Ajustes aceptados: <strong>{pesos(accountDetail.account.adjusted_clp??0)}</strong></span>
          <span>Venta neta: <strong>{pesos(accountDetail.account.net_total_clp??accountDetail.account.original_total_clp)}</strong></span>
          <span>Abonos recibidos: <strong>{pesos(accountDetail.account.paid_clp)}</strong></span>
          <span>Pendiente: <strong>{pesos(accountDetail.account.balance_clp)}</strong></span>
          <span>A favor del cliente: <strong>{pesos(accountDetail.account.refund_due_clp??0)}</strong></span>
        </div>
        {accountDetail.account.status==='voided'&&<p className="warning-box">Esta venta a crédito fue anulada. Su saldo por cobrar es cero. Los abonos previos pueden requerir reembolso.</p>}
        {accountDetail.account.status==='active'&&Number(accountDetail.account.balance_clp)>0&&<form className="formstack" onSubmit={submitPayment}>
          <h4>Registrar dinero efectivamente recibido</h4>
          <label>Abono ($ CLP)<input type="number" min="1" max={accountDetail.account.balance_clp} step="1" required disabled={locked} value={amount} onChange={e=>setAmount(e.target.value)}/></label>
          <label>Medio recibido<select value={method} disabled={locked} onChange={e=>setMethod(e.target.value)}>
            {Object.entries(methodNames).map(([key,label])=><option value={key} key={key}>{label}</option>)}
          </select></label>
          <label>Referencia o comprobante (opcional)<input maxLength={120} disabled={locked} value={reference} onChange={e=>setReference(e.target.value)}/></label>
          <button className="primary wide" disabled={locked}>{busy?'Guardando…':'Confirmar abono recibido'}</button>
        </form>}
        {pending&&<div className="warning-box" role="alert">
          <strong>Abono con resultado sin confirmar</strong>
          <p>No registres otro pago hasta revisar el historial. Si es el mismo intento, puedes reintentarlo sin duplicarlo.</p>
          <button type="button" className="primary" disabled={busy} onClick={()=>void savePayment(pending)}>Reintentar el mismo abono</button>
          <button type="button" className="ghost" disabled={busy} onClick={()=>{
            if(window.confirm('Descarta este intento SOLO si comprobaste que NO fue registrado en el historial.')){
              sessionStorage.removeItem(pendingKey);setPending(null);
            }
          }}>Descartar tras verificar</button>
        </div>}
        {accountDetail.account.adjusted_clp===undefined?
          <p className="warning-box">Para activar devoluciones y anulaciones de crédito, primero instala la actualización de la API en cPanel. Los abonos existentes siguen funcionando.</p>:
          <CreditOperationsPanel key={activeAccount} account={accountDetail.account} request={request} notify={notify} onChange={async()=>{await refresh();await inspect(activeAccount);await onChange();}}/>}
        <h4>Historial de abonos</h4>
        {accountDetail.payments.length?
          <div className="credit-payments">{accountDetail.payments.map(p=><div key={p.id}>
            <strong>{pesos(p.amount_clp)}</strong><span>{methodNames[p.method]||p.method} · {dateChile(p.created_at)}</span>
            <small>{p.reference||'Sin referencia'} · {p.received_by_email}</small>
          </div>)}</div>:
          <p className="muted">Todavía no se han registrado pagos.</p>}
      </div>
    </div>}
  </div>;
}
