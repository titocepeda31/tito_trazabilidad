import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { readSheet } from 'read-excel-file/browser';
import { API_BASE, ApiError, api, newId, pesos, qtyFormat } from './api';
import { csvParse, guessSeparator, headers, suggestMap, validateRows } from './import-utils';
import { inChilePeriod, pageSlice } from './historyFilters.js';
import './styles.css';
import ReceiptsPanel from './receipts.jsx';
import InventoryHistory from './InventoryHistory.jsx';
import QuotesPanel from './QuotesPanel.jsx';
import ReturnsPanel from './ReturnsPanel.jsx';
import ServiceCasesPanel from './ServiceCasesPanel.jsx';
import WarrantiesPanel from './WarrantiesPanel.jsx';
import ClientsPanel from './ClientsPanel.jsx';
import FinancialSummary from './FinancialSummary.jsx';
import ManagementDashboard from './ManagementDashboard.jsx';
import WarehousesPanel from './WarehousesPanel.jsx';
import AccountPanel from './AccountPanel.jsx';
import OrganizationPanel from './OrganizationPanel.jsx';
import SalesPointsPanel from './SalesPointsPanel.jsx';
import OriginCatalogPanel from './OriginCatalogPanel.jsx';
import SerialsPanel from './SerialsPanel.jsx';
import TraceabilityPanel from './TraceabilityPanel.jsx';
import ReservationsPanel from './ReservationsPanel.jsx';
import PlatformPanel from './PlatformPanel.jsx';
import RolesPanel from './RolesPanel.jsx';
import RestrictedWorkspace from './RestrictedWorkspace.jsx';
import RemoteApprovalsPanel from './RemoteApprovalsPanel.jsx';
import {installOneSignalDevVerification} from './onesignalVerify.js';
import useCashierApprovalQueue from './useCashierApprovalQueue.js';
import CashierRemoteQueue from './CashierRemoteQueue.jsx';
import useApprovalPushNavigation from './useApprovalPushNavigation.js';
import PasswordField from './PasswordField.jsx';
import TerminalPanel from './TerminalPanel.jsx';
import {terminalVerified,cashSessionVerified,mustVerifyTerminalBeforeMutation} from './terminalSalesGate.js';
import CashSessionsPanel from './CashSessionsPanel.jsx';
import {openSaleVoucher} from './saleVoucher.js';

// onesignal:managed v1 - verificación de SDK solo en desarrollo.
if (import.meta.env.DEV) installOneSignalDevVerification();

// onesignal:managed v1 - PWA raíz; OneSignal usa exclusivamente /push/onesignal/.
if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
  window.addEventListener('load',()=>navigator.serviceWorker.register('/alerts-sw.js').catch(()=>{}));
}

const menu = [
  { key: 'inicio', label: 'Inicio', ico: '▦' },
  { key: 'inventario', label: 'Inventario', ico: '▤' },
  { key: 'importar', label: 'Importar', ico: '⇧' },
  { key: 'recepciones', label: 'Mercadería', ico: '▣' },
  { key: 'bodegas', label: 'Bodegas', ico: '▤' },
  { key: 'terminal', label: 'Identificación de caja', ico: '⌁' },
  { key: 'caja', label: 'Rendición de caja', ico: '▣' },
  { key: 'vender', label: 'Vender', ico: '▣' },
  { key: 'ventas', label: 'Ventas', ico: '◷' },
  { key: 'devoluciones', label: 'Devoluciones', ico: '↩' },
  { key: 'garantias', label: 'Garantías', ico: '◇' },
  { key: 'servicio', label: 'Servicio técnico', ico: '⚒' },
  { key: 'clientes', label: 'Clientes', ico: '♧' },
  { key: 'cotizaciones', label: 'Cotizar', ico: '▤' },
  { key: 'aprobaciones', label: 'Autorizaciones', ico: '✓' },
  { key: 'organizacion', label: 'Empresas y sucursales', ico: '▦' },
  { key: 'puntosventa', label: 'Puntos de venta', ico: '▣' },
  { key: 'origen', label: 'Fabricantes y proveedores', ico: '▧' },
  { key: 'series', label: 'Números de serie', ico: '#' },
  { key: 'trazabilidad', label: 'Trazabilidad', ico: '◎' },
  { key: 'usuarios', label: 'Usuarios', ico: '♙' },
  { key: 'roles', label: 'Roles y permisos', ico: '⚿' },
  { key: 'cuenta', label: 'Mi cuenta', ico: '♙' }
];

function App() {
  const [token, setToken] = useState(() => sessionStorage.getItem('tito_token') || '');
  const [user, setUser] = useState(() => { try { return JSON.parse(sessionStorage.getItem('tito_user') || 'null'); } catch { return null; } });
  const [staffPermissions,setStaffPermissions] = useState({can_approve_discounts:false,can_sell_credit:false});
  const [page, setPage] = useState('inicio');
  const [traceUnitId,setTraceUnitId]=useState(null);
  const [creatingSaleClient,setCreatingSaleClient]=useState(false),[saleClientResult,setSaleClientResult]=useState(null);
  const [newSaleRequest,setNewSaleRequest] = useState(0);
  const [terminalVersion,setTerminalVersion]=useState(0);
  const [terminalStatus,setTerminalStatus]=useState(null);
  const [terminalChecking,setTerminalChecking]=useState(true);
  const [cashChecking,setCashChecking]=useState(true);
  const [cashStatus,setCashStatus]=useState(null);
  const [cashStatusError,setCashStatusError]=useState('');
  // QR de un solo uso en fragmento local: no se envía en solicitudes HTTP.
  useEffect(()=>{
    const match=window.location.hash.match(/^#tito_activate=([0-9a-fA-F]{32})$/);
    if(match){
      sessionStorage.setItem('tito_pending_terminal_activation',match[1].toLowerCase());
      window.history.replaceState(null,'',window.location.pathname+window.location.search);
    }
  },[]);
  const [products, setProducts] = useState([]);
  const [sales, setSales] = useState([]);
  const [returnSaleId,setReturnSaleId]=useState(null);
  const [loaded, setLoaded] = useState(false);
  const [loading, setLoading] = useState(false);
  const [serverOk, setServerOk] = useState(false);
  const [approvalRequests,setApprovalRequests]=useState([]);
  const [approvalLoading,setApprovalLoading]=useState(false);
  const [notice, setNotice] = useState(null);
  const [loginBusy, setLoginBusy] = useState(false);
  const noticeTimer = useRef(null);

  function notify(message, type = 'info') {
    setNotice({ message, type });
    if (noticeTimer.current) clearTimeout(noticeTimer.current);
    noticeTimer.current = setTimeout(() => setNotice(null), 6500);
  }
  const endSession = () => { setTerminalStatus(null);setCashStatus(null);setToken(''); setUser(null); sessionStorage.removeItem('tito_token'); sessionStorage.removeItem('tito_user'); sessionStorage.removeItem('tito_pending_sale'); sessionStorage.removeItem('tito_last_sale'); sessionStorage.removeItem('tito_pending_remote_sale'); sessionStorage.removeItem('tito_remote_auto_attempted'); sessionStorage.removeItem('tito_remote_sale_queue_v2'); setProducts([]); setSales([]); setLoaded(false); setServerOk(false); setStaffPermissions({can_approve_discounts:false,can_sell_credit:false}); };
  async function request(path, options = {}) {
    try {
      // Antes de cada operación que podría generar una venta validamos el
      // terminal de nuevo en la API. Un local heredado nunca es autorización.
      if(mustVerifyTerminalBeforeMutation(path,options.method)){
        const status=await api('/account/terminal-status',{token});
        setTerminalStatus(status);
        if(!terminalVerified(status)){
          setCashStatus(null);setPage('terminal');
          throw new ApiError('Identifica este computador o celular con el QR/código de su sucursal antes de vender.',403);
        }
        const cash=await api('/cash/current',{token});
        setCashStatus(cash);
        if(!cashSessionVerified(cash,status)){
          setPage('caja');
          throw new ApiError('No hay gaveta abierta de esta sucursal. Abre tu turno de caja antes de vender o cobrar.',409);
        }
      }
      return await api(path,{...options,token});
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) { endSession(); }
      throw e;
    }
  }
  async function refresh(show = false) {
    if (!token) return;
    if (show) setLoading(true);
    try {
      const [p, s] = await Promise.all([request('/products'), request('/sales')]);
      setProducts(p.products || []);
      setSales(s.sales || []);
      setLoaded(true); setServerOk(true);
    } catch (e) { setServerOk(false); notify(e.message, 'error'); }
    finally { setLoading(false); }
  }
  useEffect(() => { if (token&&!user?.custom_role) refresh(false); }, [token,user?.custom_role?.id]);
  // Se comprueba otra vez al entrar a Vender o Rendición para detectar
  // revocaciones, vencimientos y cambios de navegador sin mostrar Local 1.
  useEffect(()=>{
    if(!token||user?.custom_role){setTerminalStatus(null);setTerminalChecking(false);return;}
    // Mantener el panel de venta montado en segundo plano para no perder
    // colas de autorizaciones y reintentos cuando se navega a otras secciones.
    if(page!=='vender' && page!=='caja' && terminalVerified(terminalStatus))return;
    let alive=true;
    setTerminalChecking(true);
    api('/account/terminal-status',{token}).then(status=>{
      if(!alive)return;
      setTerminalStatus(status);setTerminalChecking(false);
      if(user?.role==='cashier' && status?.feature_ready===true && !status.terminal){
        setPage('terminal');
      }
    }).catch(()=>{if(alive){setTerminalStatus(null);setTerminalChecking(false);}});
    if(sessionStorage.getItem('tito_pending_terminal_activation'))setPage('terminal');
    return ()=>{alive=false;};
  },[token,terminalVersion,page==='vender',page==='caja']);
  // Revalidar gaveta del cajero al entrar a Vender/Rendición, sin dar
  // por confirmado ningún fondo de efectivo antes de consultar al servidor.
  useEffect(()=>{
    if(!token||user?.custom_role||!terminalVerified(terminalStatus)){
      setCashStatus(null);setCashStatusError('');setCashChecking(false);return;
    }
    let alive=true;
    setCashChecking(true);setCashStatusError('');
    api('/cash/current',{token}).then(data=>{
      if(alive){setCashStatus(data);setCashChecking(false);}
    }).catch(e=>{
      if(alive){setCashStatus(null);setCashStatusError(e.message||'No se pudo comprobar la caja.');setCashChecking(false);}
    });
    return ()=>{alive=false;};
  },[token,terminalVersion,page==='vender',page==='caja',terminalStatus?.terminal?.id]);

  useEffect(()=>{
    if(!token||user?.custom_role){setStaffPermissions({can_approve_discounts:false,can_sell_credit:false});return;}
    let alive=true;
    api('/account/staff-permissions',{token}).then(data=>{
      if(alive)setStaffPermissions({can_approve_discounts:data.can_approve_discounts===true,can_sell_credit:data.can_sell_credit===true});
    }).catch(()=>{if(alive)setStaffPermissions({can_approve_discounts:false,can_sell_credit:false});});
    return ()=>{alive=false;};
  },[token,page]);
  async function refreshApprovals(){
    if(!token||(user?.role!=='admin'&&!staffPermissions.can_approve_discounts))return;
    setApprovalLoading(true);
    try{
      const data=await api('/sales/discount-requests',{token});
      setApprovalRequests(data.requests||[]);
    }catch(error){if(error.status!==404)notify('No se pudo revisar la bandeja de descuentos: '+error.message,'error');}
    finally{setApprovalLoading(false);}
  }
  useEffect(()=>{
    if(!token||(user?.role!=='admin'&&!staffPermissions.can_approve_discounts)){setApprovalRequests([]);return;}
    let active=true,started=false;let known=new Set();
    async function poll(){
      try{
        const data=await api('/sales/discount-requests',{token});
        if(!active)return;
        const rows=data.requests||[];
        setApprovalRequests(rows);
        const current=new Set(rows.filter(r=>r.status==='pending').map(r=>Number(r.id)));
        if(started){
          const incoming=[...current].filter(id=>!known.has(id));
          if(incoming.length){
            notify('Hay '+incoming.length+' nueva(s) solicitud(es) de venta con descuento. Revisa Más → Autorizaciones.','info');
            // Los avisos del sistema son enviados por OneSignal; no duplicar la notificación local.
          }
        }
        known=current;started=true;
      }catch(e){/* La bandeja podría no estar instalada todavía: no bloquear el POS. */}
    }
    poll();
    const timer=setInterval(poll,12000);
    return ()=>{active=false;clearInterval(timer);};
  },[token,user?.role,staffPermissions.can_approve_discounts]);


  async function onLogin(e) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    setLoginBusy(true);
    try {
      const data = await api('/auth/login', { method: 'POST', body: { email: String(form.get('email')).trim(), password: String(form.get('password')) } });
      sessionStorage.setItem('tito_token', data.token);
      sessionStorage.setItem('tito_user', JSON.stringify(data.user));
      setToken(data.token); setUser(data.user); setPage('inicio'); setServerOk(true);
    } catch (e) { notify(e.message, 'error'); }
    finally { setLoginBusy(false); }
  }
  async function logout() {
    // onesignal:managed v1: al cerrar sesión, desasociar el dispositivo
    // para no seguir avisando en un teléfono compartido.
    if(user?.role==='admin'&&window.titoOneSignalReady){
      try{
        const os=await Promise.race([
          window.titoOneSignalReady,
          new Promise((_,reject)=>setTimeout(()=>reject(new Error('timeout')),1500))
        ]);
        const subId=os.User.PushSubscription.id;
        if(subId){
          try{await request('/account/push-subscriptions/unregister',{method:'POST',body:{subscription_id:subId}});}
          catch{/* Se desactiva localmente de todos modos. */}
        }
        try{await os.User.PushSubscription.optOut();}catch{/* cierre de sesión prevalece */}
      }catch{/* permiso o SDK no disponible; no bloquear salida */}
    }
    try { await request('/auth/logout', { method: 'POST', body: {} }); } catch { /* cierre local siempre */ }
    endSession();
  }
  const isAdmin = user?.role === 'admin';
  const terminalCanSell=terminalVerified(terminalStatus);
  const cashCanSell=cashSessionVerified(cashStatus,terminalStatus);
  const salesScreenReady=terminalCanSell&&cashCanSell&&!terminalChecking&&!cashChecking;
  const {handledElsewhere,loginInNewTab}=useApprovalPushNavigation({token,user,onOpen:()=>{setPage('aprobaciones');void refreshApprovals();}});
  // Un enlace push abre la bandeja solo después de autenticar al administrador.
  useEffect(()=>{
    if(!token||user?.role!=='admin')return;
    const url=new URL(window.location.href);
    const requested=url.searchParams.get('approval_request');
    if(requested&&/^[1-9][0-9]*$/.test(requested)){
      setPage('aprobaciones');
      url.searchParams.delete('approval_request');
      window.history.replaceState(null,'',url.pathname+url.search+url.hash);
    }
  },[token,user?.role]);
  const pendingApprovals=approvalRequests.filter(r=>r.status==='pending').length;
  const canApproveDiscounts=isAdmin||staffPermissions.can_approve_discounts;
  const canSellCredit=isAdmin||staffPermissions.can_sell_credit;
  const visibleMenu = isAdmin ? menu : menu.filter(m => !['importar','usuarios','recepciones','cotizaciones','bodegas','devoluciones','servicio','garantias','clientes','organizacion','roles','puntosventa','origen','series','trazabilidad'].includes(m.key) && (m.key!=='aprobaciones'||canApproveDiscounts));
  const critical = products.filter(p => Number(p.stock_qty) <= 5);
  const stockValue = products.reduce((total,p) => total + Number(p.stock_qty) * Number(p.price_clp), 0);
  const dateCL = d => d.toLocaleDateString('sv-SE', {timeZone:'America/Santiago'});
  const today = dateCL(new Date());
  const salesToday = sales.filter(s => {
    const utc = String(s.created_at).trim().replace(' ', 'T')+'Z';
    const date = new Date(utc);
    return Number.isFinite(date.valueOf()) && dateCL(date) === today;
  });
  if(token&&user?.custom_role)return <RestrictedWorkspace user={user} request={request} onLogout={logout}/>;
  return <>
    {!token ? <div className="auth-layout"><section className="auth-card">
      <div className="mark">T</div><div className="eyebrow">TITO LABS · SISTEMA COMERCIAL</div>
      <h1>Tito <span>POS</span></h1><p className="muted">Inventario, importación de productos y ventas desde un solo lugar.</p>
      {handledElsewhere?<div className="formstack" role="status"><p><strong>La solicitud se abrió en tu otra sesión de administrador.</strong></p><p>Vuelve a la ventana de Tito POS que ya tenías abierta. En Android puede ser necesario cambiar manualmente de pestaña o volver a la aplicación instalada.</p><button className="primary wide" type="button" onClick={loginInNewTab}>Iniciar sesión aquí de todos modos</button></div>:<form onSubmit={onLogin} className="formstack">
        <label>Correo electrónico<input name="email" type="email" autoComplete="username" required placeholder="correo@negocio.cl" /></label>
        <PasswordField label="Contraseña" name="password" autoComplete="current-password" required placeholder="Tu contraseña" />
        <button className="primary wide" disabled={loginBusy}>{loginBusy ? 'Ingresando…' : 'Entrar al sistema'} <span>→</span></button>
      </form>}<div className="auth-foot">Conectado a tu propia API PHP + MySQL</div>
    </section></div> : <div className="app-shell">
      <aside className="sidebar">
        <div className="brand"><span className="brand-box">T</span><span>Tito <strong>POS</strong><small>Panel comercial</small></span></div>
        <div className="sidebar-tag">ESPACIO DE TRABAJO</div>
        <nav>{visibleMenu.map(item => <button key={item.key} className={'navitem ' + (page === item.key ? 'selected' : '')} onClick={() => {if(item.key==='trazabilidad')setTraceUnitId(null);setPage(item.key);}}><span>{item.ico}</span>{item.label}{item.key==='aprobaciones'&&pendingApprovals>0&&<span className="approval-count">{pendingApprovals}</span>}</button>)}</nav>
        <div className="sidebar-bottom"><div className="shop-card"><span className="shop-dot"/ ><div><strong>{user?.business_name || 'Mi negocio'}</strong><small>{user?.email || ''}</small></div></div><button className="small-link" onClick={logout}>Cerrar sesión ↗</button></div>
      </aside>
      <div className="content-shell"><header className="topbar"><div><div className="topbar-kicker">TITO POS / {menu.find(m=>m.key===page)?.label.toUpperCase()||'MÁS'}</div><strong>{user?.business_name || 'Mi negocio'}</strong></div><div className="top-right">{canApproveDiscounts&&pendingApprovals>0&&<button className="approval-top-action" type="button" onClick={()=>setPage('aprobaciones')}>🔔 {pendingApprovals} pendiente{pendingApprovals!==1?'s':''}</button>}<span className="connection"><i className={serverOk?'':'off'}/> {serverOk?'API conectada':'API por comprobar'}</span><button className="ghost mini" onClick={()=>refresh(true)} disabled={loading}>{loading?'Actualizando…':'↻ Actualizar'}</button></div></header>
      <main className="main-content">
        {page==='inicio' && isAdmin && <ManagementDashboard request={request} onNavigate={p=>{if(p==='trazabilidad')setTraceUnitId(null);setPage(p);}} onNewSale={()=>{setNewSaleRequest(n=>n+1);setPage('vender');}}/>}
        {page==='inicio' && !isAdmin && <><div className="section-intro"><div><p className="eyebrow">RESUMEN GENERAL</p><h2>Tu negocio, en orden.</h2><p className="muted">Productos y movimientos registrados en Tito POS.</p></div><button className="primary" onClick={()=>{setNewSaleRequest(n=>n+1);setPage('vender');}}>+ Nueva venta</button></div>
          <div className="stats-grid"><Stat label="Productos listados" value={products.length} detail="API entrega máximo 1000" ico="▤"/><Stat label="Stock bajo (≤5)" value={critical.length} detail="Productos para revisar" ico="!" warning={critical.length>0}/><Stat label="Valor de inventario" value={pesos(stockValue)} detail="Estimado a precio de venta" ico="$"/><Stat label="Ventas de hoy" value={salesToday.length} detail="Hora local de Chile (últimas 100 ventas)" ico="✓"/></div>
          {isAdmin&&<FinancialSummary request={request} notify={notify}/>}
          <section className="panel"><div className="panel-heading"><div><h3>Atención al inventario</h3><p>Productos con cinco unidades o menos</p></div><button className="text-button" onClick={()=>setPage('inventario')}>Ver inventario →</button></div>{!loaded ? <Empty text="Cargando productos…"/> : critical.length===0 ? <Empty text="No se detecta stock bajo en el listado actual."/> : <div className="table-wrap"><table><thead><tr><th>Producto</th><th>Código</th><th>Stock</th></tr></thead><tbody>{critical.slice(0,8).map(p=><tr key={p.id}><td><strong>{p.name}</strong></td><td className="mono">{p.sku||p.barcode}</td><td><span className="pill amber">{qtyFormat(p.stock_qty)} {p.sale_unit}</span></td></tr>)}</tbody></table></div>}</section>
        </>}
        {page==='inventario' && <Inventory products={products} onChange={()=>refresh(false)} request={request} notify={notify} canManage={isAdmin} onOpenHistory={()=>setPage('auditoria')} />}
        {page==='auditoria' && isAdmin && <InventoryHistory request={request} notify={notify} products={products} onBack={()=>setPage('inventario')} />}
        {page==='importar' && <ImportPanel request={request} refresh={()=>refresh(false)} notify={notify} />}
        {page==='recepciones' && isAdmin && <ReceiptsPanel products={products} request={request} onChange={()=>refresh(false)} notify={notify} />}
        {page==='bodegas' && isAdmin && <WarehousesPanel request={request} notify={notify} onChange={()=>refresh(false)} />}
        {page==='terminal' && <TerminalPanel request={request} notify={notify} isAdmin={isAdmin} onActivated={activated=>{setTerminalStatus(null);setTerminalVersion(n=>n+1);if(activated)setPage('vender');}} />}
        {(page==='caja'||page==='vender')&&(!terminalCanSell||terminalChecking)&&<section className="panel pad" role="status">
          <p className="eyebrow">SUCURSAL SIN IDENTIFICAR</p>
          <h2>{terminalStatus?.feature_ready===true?'Activa este dispositivo antes de operar':'Verificando autorización de sucursal…'}</h2>
          <p className="muted">El sistema no asignará Local 1 automáticamente. Para vender o abrir una gaveta es necesario un código QR de la sucursal emitido por el administrador.</p>
          {terminalStatus?.invalid_terminal&&<p className="warning-box">La autorización anterior venció o fue revocada. Solicita un código nuevo.</p>}
          <button type="button" className="primary" onClick={()=>setPage('terminal')}>{isAdmin?'Identificar equipo o crear QR':'Ingresar código de identificación'}</button>
          <p className="helper">Si todavía no tienes el código, pídeselo al administrador. Los productos y el historial permanecen disponibles.</p>
        </section>}
        {page==='caja'&&terminalCanSell&&!terminalChecking&&<CashSessionsPanel request={request} notify={notify} isAdmin={isAdmin} userId={Number(user?.id)} />}
        {page==='vender'&&terminalCanSell&&!terminalChecking&&!salesScreenReady&&<section className="panel pad" role="status">
          <p className="eyebrow">TURNO DE CAJA</p>
          <h2>{cashChecking&&!cashStatusError?'Comprobando tu gaveta…':'Abre tu gaveta antes de vender'}</h2>
          <p className="muted">{cashStatusError?'No fue posible validar la caja: '+cashStatusError:
            cashStatus?.session?.status==='open'?'Tu turno pertenece a otra sucursal. Cierra o regulariza la gaveta antes de operar desde este terminal.':
            'La identificación del dispositivo no abre una gaveta ni registra dinero. Cada cajero necesita su propio turno y fondo inicial.'}</p>
          <button type="button" className="primary" onClick={()=>setPage('caja')}>Ir a Rendición de caja · Abrir turno</button>
          <p className="helper">No se enviarán ventas ni abonos hasta confirmar una sesión abierta de esta sucursal.</p>
        </section>}
        <div style={{display:page==='vender'&&salesScreenReady?'contents':'none'}}>{terminalCanSell&&cashCanSell&&<SellPanel key={'terminal-'+terminalVersion+'-'+terminalStatus.terminal.id+'-'+cashStatus.session.id} products={products} request={request} refresh={()=>refresh(false)} notify={notify} canDiscount={isAdmin} canApproveDiscount={canApproveDiscounts} canRegisterCredit={canSellCredit} isAdmin={isAdmin} terminalStatus={terminalStatus} cashStatus={cashStatus} onCashChanged={async()=>{try{setCashStatus(await api('/cash/current',{token}));}catch{setCashStatus(null);}}} onOpenCash={()=>setPage('caja')} onShowSales={()=>setPage('ventas')} businessName={user?.business_name||'Tito POS'} active={page==='vender'} newSaleRequest={newSaleRequest} onCreateClient={isAdmin?()=>{setCreatingSaleClient(true);setPage('clientes');}:undefined} createdClient={saleClientResult} onClientConsumed={()=>setSaleClientResult(null)} />}</div>
        {page==='organizacion' && isAdmin && <OrganizationPanel request={request} notify={notify} />}
        {page==='puntosventa' && isAdmin && <SalesPointsPanel request={request}/>}
        {page==='origen' && isAdmin && <OriginCatalogPanel request={request}/>}
        {page==='trazabilidad' && isAdmin && <TraceabilityPanel request={request} initialUnitId={traceUnitId} onBack={traceUnitId?()=>{setTraceUnitId(null);setPage('series');}:undefined}/>}
        {page==='series' && isAdmin && <SerialsPanel request={request} onChange={refresh} onOpenTrace={id=>{setTraceUnitId(id);setPage('trazabilidad');}}/>}
        {page==='usuarios' && isAdmin && <UsersPanel request={request} notify={notify} ownId={Number(user?.id)} />}
        {page==='roles' && isAdmin && <RolesPanel request={request} />}
        {page==='cuenta' && <AccountPanel user={user} request={request} notify={notify} onPasswordChanged={()=>{endSession();setPage('inicio');notify('Contraseña actualizada. Inicia sesión nuevamente.','success');}} />}
        {page==='ventas' && <SalesPanel sales={sales} onRefresh={()=>refresh(true)} request={request} notify={notify} canVoid={isAdmin} onStartReturn={id=>{setReturnSaleId(id);setPage('devoluciones');}} businessName={user?.business_name||'Tito POS'} />}
        {page==='garantias' && isAdmin && <WarrantiesPanel request={request} notify={notify}/>}
        {page==='servicio' && isAdmin && <ServiceCasesPanel request={request} notify={notify}/>}
        {page==='devoluciones' && isAdmin && <ReturnsPanel sales={sales} request={request} notify={notify} initialSaleId={returnSaleId} onChange={()=>refresh(false)} />}
        {page==='clientes' && isAdmin && <ClientsPanel request={request} notify={notify} onChange={()=>refresh(false)} createForSale={creatingSaleClient} onSaleClientDone={c=>{setCreatingSaleClient(false);if(c)setSaleClientResult(c);setPage('vender');}} />}
        {page==='cotizaciones' && isAdmin && <QuotesPanel products={products} request={request} notify={notify} onSale={()=>refresh(true)} businessName={user?.business_name||'Tito POS'} />}
        {page==='aprobaciones' && canApproveDiscounts && <RemoteApprovalsPanel requests={approvalRequests} request={request} notify={notify} onRefresh={refreshApprovals} loading={approvalLoading} canManagePush={isAdmin} />}
        {page==='mas' && <div className="more-page"><div className="section-intro"><div><p className="eyebrow">OPERACIONES</p><h2>Más herramientas</h2><p className="muted">Módulos de administración y control de inventario.</p></div></div><div className="more-menu">{visibleMenu.filter(m=>!['inicio','inventario','vender','ventas'].includes(m.key)).map(m=><button type="button" key={m.key} className="panel pad more-option" onClick={()=>{if(m.key==='trazabilidad')setTraceUnitId(null);setPage(m.key);}}><span>{m.ico}</span><strong>{m.label}{m.key==='aprobaciones'&&pendingApprovals>0?' ('+pendingApprovals+')':''}</strong><span>→</span></button>)}</div></div>}
      </main></div>
      <nav className="bottom-nav">{visibleMenu.filter(m=>['inicio','inventario','vender','ventas'].includes(m.key)).map(item=><button key={item.key} className={page===item.key?'active':''} onClick={()=>setPage(item.key)}><span>{item.ico}</span>{item.label}</button>)}<button className={page==='mas'||!['inicio','inventario','vender','ventas'].includes(page)?'active':''} onClick={()=>setPage('mas')}><span>⋯</span>Más</button></nav>
    </div>}
    {notice && <div role="status" className={'toast '+notice.type}>{notice.message}<button onClick={()=>setNotice(null)} aria-label="Cerrar">×</button></div>}
  </>;
}
function Stat({ label, value, detail, ico, warning }) { return <div className="stat-card"><div className="stat-head"><span>{label}</span><span className={'stat-icon '+(warning?'warn':'')}>{ico}</span></div><strong>{value}</strong><small>{detail}</small></div>; }
function Empty({text}) { return <div className="empty">{text}</div>; }
function Inventory({products,request,onChange,notify,canManage,onOpenHistory}) {
  const [query,setQuery]=useState(''); const [showNew,setShowNew]=useState(false); const [adjust,setAdjust]=useState(null); const [edit,setEdit]=useState(null); const [saving,setSaving]=useState(false);
  const [stockView,setStockView]=useState('all'); const [stockOrder,setStockOrder]=useState('name'); const [inventoryPage,setInventoryPage]=useState(1);
  const [localById,setLocalById]=useState(null);
  const [locationById,setLocationById]=useState({});
  const [locations,setLocations]=useState([]);
  const [stockLocation,setStockLocation]=useState('total');
  const [locationLoaded,setLocationLoaded]=useState(false);
  const [stockDetailLoaded,setStockDetailLoaded]=useState(false);
  const [adjustCode,setAdjustCode]=useState('local');
  const [pendingAdjustment,setPendingAdjustment]=useState(()=>{try{return JSON.parse(sessionStorage.getItem('tito_pending_adjust')||'null');}catch{return null;}});
  useEffect(()=>{
    let active=true;
    setLocationLoaded(false);setStockDetailLoaded(false);
    // El stock y el catálogo de ubicaciones se cargan por separado:
    // una ruta nueva pendiente de publicar no debe romper los ajustes
    // históricos de inventario del administrador.
    request('/warehouses/stock').then(d=>{if(active){
      setLocalById(Object.fromEntries((d.stocks||[]).map(p=>[Number(p.product_id),Number(p.local_qty)])));
      setLocationById(Object.fromEntries((d.stocks||[]).map(p=>[Number(p.product_id),{
        local:Number(p.local_qty),bodega:Number(p.bodega_qty),locations:p.locations||{}
      }])));
      setStockDetailLoaded(true);
    }}).catch(e=>{if(active){
      setLocalById(null);setLocationById({});setStockDetailLoaded(false);
      notify('No se pudo cargar el stock detallado: '+e.message,'error');
    }});
    request('/warehouses/locations').then(w=>{if(active){
      setLocations(w.locations||[]);
      setLocationLoaded(true);
    }}).catch(e=>{if(active){
      setLocations([]);setStockLocation('total');setLocationLoaded(false);
      notify('Filtro por local/bodega pendiente de habilitar en API: '+e.message,'error');
    }});
    return ()=>{active=false;};
  },[products]);
  const stockFor=(p)=>stockLocation==='total'?Number(p.stock_qty):
    !stockDetailLoaded?NaN:Number(locationById[Number(p.id)]?.locations?.[stockLocation]??0);
  const stocksReady=stockLocation==='total'||(locationLoaded&&stockDetailLoaded);
  const chosenLocation=locations.find(w=>String(w.id)===stockLocation);
  const visible=useMemo(()=>(stocksReady?products:[])
    .filter(p=>[p.name,p.sku,p.barcode].some(v=>String(v??'').toLocaleLowerCase('es-CL').includes(query.trim().toLocaleLowerCase('es-CL'))))
    .filter(p=>stockView==='all'||(stockView==='none'?stockFor(p)<=0:stockView==='positive'?stockFor(p)>0:stockFor(p)>0&&stockFor(p)<=5))
    .sort((a,b)=>stockOrder==='stock_desc'?stockFor(b)-stockFor(a)||a.name.localeCompare(b.name,'es'):stockOrder==='stock_asc'?stockFor(a)-stockFor(b)||a.name.localeCompare(b.name,'es'):a.name.localeCompare(b.name,'es')),
    [products,query,stockView,stockOrder,stockLocation,locationById,stocksReady]);
  const inventoryPageData=pageSlice(visible,inventoryPage,30);
  const zeroStockCount=products.filter(p=>stockFor(p)<=0).length;
  useEffect(()=>setInventoryPage(1),[query,stockView,stockOrder,stockLocation]);
  async function add(e) {
    e.preventDefault(); const f=new FormData(e.currentTarget); setSaving(true);
    try { await request('/products',{method:'POST',body:{name:String(f.get('name')).trim(),sku:String(f.get('sku')).trim()||null,barcode:String(f.get('barcode')).trim()||null,price_clp:Number(f.get('price')),stock_qty:Number(f.get('stock')),sale_unit:String(f.get('unit'))}}); notify('Producto creado correctamente.','success');setShowNew(false);onChange(); }
    catch(err){notify(err.message,'error');} finally{setSaving(false);}
  }
  async function editProduct(e) {
    e.preventDefault();
    if(!edit)return;
    const f=new FormData(e.currentTarget);
    const body={
      name:String(f.get('name')||'').trim(),
      sku:String(f.get('sku')||'').trim()||null,
      barcode:String(f.get('barcode')||'').trim()||null,
      price_clp:Number(f.get('price'))
    };
    if(!body.sku&&!body.barcode){notify('Debes ingresar un SKU o código de barras.','error');return;}
    setSaving(true);
    try{
      await request('/products/'+edit.id+'/update',{method:'POST',body});
      notify('Producto actualizado; cambio registrado en auditoría.','success');
      setEdit(null);onChange();
    }catch(error){notify(error.message,'error');}
    finally{setSaving(false);}
  }
  async function sendStockAdjustment(body){
    setSaving(true);
    try{
      try{
        await request('/stock/adjust-by-location',{method:'POST',body});
      }catch(err){
        // Compatibilidad temporal con API anterior: solo ajustes del Local.
        if(err.status===404&&body.warehouse_code==='local'){
          await request('/stock/adjust',{method:'POST',body:{product_id:body.product_id,delta_qty:body.delta_qty,reason:body.reason}});
        }else throw err;
      }
      sessionStorage.removeItem('tito_pending_adjust');setPendingAdjustment(null);setAdjust(null);
      notify('Stock de '+(body.warehouse_code==='local'?'Local de venta':'Bodega de respaldo')+' actualizado. Ajuste registrado.','success');
      await onChange();
    }catch(err){
      if(err.status===404&&body.warehouse_code==='bodega'){
        sessionStorage.removeItem('tito_pending_adjust');setPendingAdjustment(null);
        notify('El ajuste directo de bodega todavía necesita instalarse en la API de cPanel.','error');
      }else if(!err.status||err.status>=500||err.status===408||err.status===429){
        notify('No se pudo confirmar el resultado. NO crees otro ajuste: revisa Historial y, si corresponde, reintenta el mismo registro.','error');
        await onChange();
      }else{
        sessionStorage.removeItem('tito_pending_adjust');setPendingAdjustment(null);
        notify('Ajuste rechazado: '+err.message,'error');
      }
    }finally{setSaving(false);}
  }
  function adjustStock(e){
    e.preventDefault();
    if(pendingAdjustment||saving)return;
    const f=new FormData(e.currentTarget);
    const delta=Number(f.get('delta'));
    if(!Number.isFinite(delta)||delta===0){notify('La variación debe ser distinta de cero.','error');return;}
    const available=locationById[Number(adjust.id)]?.[adjustCode];
    if(delta<0&&available!==undefined&&Math.abs(delta)>available+0.000001){
      notify('No puedes descontar '+qtyFormat(Math.abs(delta))+' porque en '+(adjustCode==='local'?'Local de venta':'Bodega de respaldo')+' hay '+qtyFormat(available)+'.','error');
      return;
    }
    const body={request_id:newId(),product_id:Number(adjust.id),warehouse_code:adjustCode,delta_qty:delta,reason:String(f.get('reason')).trim()};
    sessionStorage.setItem('tito_pending_adjust',JSON.stringify(body));setPendingAdjustment(body);sendStockAdjustment(body);
  }
  return <><div className="section-intro"><div><p className="eyebrow">GESTIÓN DE PRODUCTOS</p><h2>Inventario</h2><p className="muted">Busca por nombre, SKU o código de barras.</p></div>{canManage&&<div className="inline-actions"><button className="ghost" onClick={onOpenHistory}>◷ Historial y exportación</button><button className="primary" onClick={()=>setShowNew(true)}>+ Agregar producto</button></div>}</div>
    <section className="panel">
      <div className="inventory-status-summary"><strong>{products.length} productos cargados</strong><span>{stocksReady?zeroStockCount:'—'} sin stock · {stocksReady?products.length-zeroStockCount:'—'} con existencias</span><small>{chosenLocation?'Disponibilidad en '+chosenLocation.name:'Stock global de la empresa'}; unidades, kilos o litros según producto.</small></div>
      <div className="list-filters inventory-filters">
        <label>Ubicación del stock<select aria-label="Filtrar stock por ubicación" value={stockLocation} disabled={!locationLoaded||!stockDetailLoaded} onChange={e=>setStockLocation(e.target.value)}>
          <option value="total">Todas las ubicaciones · Total empresa</option>
          {locations.map(w=><option key={w.id} value={String(w.id)}>{w.name} · {Number(w.is_sales_location)===1?'Local':'Bodega'}</option>)}
        </select></label>
        <label>Buscar producto<input type="search" placeholder="Nombre, SKU o código de barras…" value={query} onChange={e=>setQuery(e.target.value)}/></label>
        <label>Existencias<select value={stockView} onChange={e=>setStockView(e.target.value)}><option value="all">Todos los productos</option><option value="none">Sin stock (0)</option><option value="positive">Con stock</option><option value="low">Stock bajo (1–5)</option></select></label>
        <label>Ordenar<select value={stockOrder} onChange={e=>setStockOrder(e.target.value)}><option value="name">Nombre A–Z</option><option value="stock_desc">Mayor stock primero</option><option value="stock_asc">Menor stock primero</option></select></label>
      </div>
      {stockLocation!=='total'&&!stocksReady&&<p className="warning-box">Cargando saldos de la ubicación… No se mostrarán existencias cero hasta verificar el servidor.</p>}
      <div className="table-wrap"><table><thead><tr><th>Producto</th><th>SKU / Código</th><th>Precio</th><th>{chosenLocation?'Stock en '+chosenLocation.name:'Stock total'}</th><th></th></tr></thead><tbody>{inventoryPageData.rows.map(p=><tr key={p.id}><td><strong>{p.name}</strong><small className="sub">{p.sale_unit}</small></td><td className="mono">{p.sku||'—'}<small className="sub">{p.barcode||'Sin código de barras'}</small></td><td>{pesos(p.price_clp)}</td><td><span className={'pill '+(stockFor(p)===0?'red':stockFor(p)<=5?'amber':'green')}>{qtyFormat(stockFor(p))} {p.sale_unit}</span>{stockLocation==='total'&&locationById[Number(p.id)]&&<small className="sub">Local original: {qtyFormat(locationById[Number(p.id)].local)} · Bodega original: {qtyFormat(locationById[Number(p.id)].bodega)}</small>}</td><td>{canManage&&<div className="inline-actions"><button className="ghost mini" onClick={()=>setEdit(p)}>Editar</button><button className="ghost mini" onClick={()=>{setAdjustCode('local');setAdjust(p);}}>Ajustar stock</button></div>}</td></tr>)}</tbody></table>{stocksReady&&visible.length===0&&<Empty text="No hay productos que coincidan con estos filtros."/>}</div>
      <div className="list-pagination"><span>Mostrando {inventoryPageData.rows.length} de {inventoryPageData.total} productos filtrados · Página {inventoryPageData.page} de {inventoryPageData.pages}</span><div className="inline-actions"><button type="button" className="ghost mini" disabled={inventoryPageData.page<=1} onClick={()=>setInventoryPage(p=>p-1)}>← Anterior</button><button type="button" className="ghost mini" disabled={inventoryPageData.page>=inventoryPageData.pages} onClick={()=>setInventoryPage(p=>p+1)}>Siguiente →</button></div></div>
    </section>
    {showNew && <Modal title="Nuevo producto" onClose={()=>setShowNew(false)}><form className="formstack" onSubmit={add}><label>Nombre<input required name="name" maxLength="120" placeholder="Ej. Tornillo 2 pulgadas"/></label><div className="field-row"><label>SKU<input name="sku" maxLength="50" placeholder="FER-001"/></label><label>Código de barras<input name="barcode" maxLength="50" placeholder="780…"/></label></div><div className="field-row"><label>Precio (CLP)<input name="price" type="number" min="0" max="1000000000" step="1" required /></label><label>Stock inicial<input name="stock" type="number" min="0" max="1000000000" step="0.001" defaultValue="0" required /></label></div><label>Unidad<select name="unit"><option value="unidad">Unidad</option><option value="kg">Kg</option><option value="litro">Litro</option></select></label><p className="helper">Debes ingresar al menos un SKU o código de barras.</p><button className="primary wide" disabled={saving}>{saving?'Guardando…':'Guardar producto'}</button></form></Modal>}
    {edit&&<Modal title={'Editar producto · '+edit.name} onClose={()=>setEdit(null)}>
      <form className="formstack" onSubmit={editProduct}>
        <label>Nombre<input name="name" required maxLength="120" defaultValue={edit.name}/></label>
        <div className="field-row"><label>SKU<input name="sku" maxLength="50" defaultValue={edit.sku||''}/></label><label>Código de barras<input name="barcode" maxLength="50" defaultValue={edit.barcode||''}/></label></div>
        <label>Precio de lista (CLP)<input name="price" type="number" min="0" max="1000000000" step="1" defaultValue={edit.price_clp} required/></label>
        <p className="helper">Stock actual: {qtyFormat(edit.stock_qty)} {edit.sale_unit}. Para cambiar existencias, usa «Ajustar stock» y selecciona la ubicación. Las ventas anteriores conservan sus precios registrados.</p>
        <button className="primary wide" disabled={saving}>{saving?'Guardando…':'Guardar cambios'}</button>
      </form>
    </Modal>}
    {adjust && <Modal title={'Ajustar stock · '+adjust.name} onClose={()=>{if(!saving)setAdjust(null);}}>
      <form className="formstack" onSubmit={adjustStock}>
        <p className="muted">Stock total empresa: <strong>{qtyFormat(adjust.stock_qty)} {adjust.sale_unit}</strong>. El ajuste modifica la ubicación seleccionada y cambia también el total.</p>
        <label>Ubicación<select value={adjustCode} disabled={saving||!!pendingAdjustment} onChange={e=>setAdjustCode(e.target.value)}><option value="local">Local de venta</option><option value="bodega">Bodega de respaldo</option></select></label>
        <p className="muted">Disponible en {adjustCode==='local'?'Local de venta':'Bodega de respaldo'}: <strong>{locationById[Number(adjust.id)]?.[adjustCode]===undefined?'Sin verificar':qtyFormat(locationById[Number(adjust.id)][adjustCode])+' '+adjust.sale_unit}</strong></p>
        <label>Variación (positivo para sumar, negativo para descontar)<input name="delta" type="number" required step={adjust.sale_unit==='unidad'?'1':'0.001'} placeholder="Ej. +5 o -2" disabled={saving||!!pendingAdjustment}/></label>
        <label>Motivo<input name="reason" required maxLength="250" placeholder="Ej. Diferencia en conteo físico" disabled={saving||!!pendingAdjustment}/></label>
        <button className="primary wide" disabled={saving||!!pendingAdjustment}>{saving?'Guardando…':'Registrar ajuste'}</button>
        {pendingAdjustment&&<div className="warning-box">Resultado pendiente: comprueba el historial antes de reenviar. <button type="button" className="ghost mini" disabled={saving} onClick={()=>sendStockAdjustment(pendingAdjustment)}>Reintentar la misma operación</button><button type="button" className="ghost mini" disabled={saving} onClick={()=>{if(window.confirm('¿Verificaste que esta operación no se registró?')){sessionStorage.removeItem('tito_pending_adjust');setPendingAdjustment(null);}}}>Descartar solo tras verificar</button></div>}
      </form>
    </Modal>}
  </>;
}
function ImportPanel({request,refresh,notify}) {
  const [fileName,setFileName]=useState('');const [matrix,setMatrix]=useState([]);const [mapping,setMapping]=useState(null);const [mode,setMode]=useState('preserve_existing');const [busy,setBusy]=useState(false);const [result,setResult]=useState(null);const [progress,setProgress]=useState('');
  const [warehouses,setWarehouses]=useState([]);
  const [warehouseId,setWarehouseId]=useState('');
  const [warehouseLoading,setWarehouseLoading]=useState(true);
  const [pendingImport,setPendingImport]=useState(()=>{try{return JSON.parse(sessionStorage.getItem('tito_pending_inventory_import')||'null');}catch{return null;}});
  useEffect(()=>{
    let alive=true;
    request('/warehouses').then(d=>{
      if(!alive)return;
      const options=(d.warehouses||[]).filter(w=>Number(w.is_active)===1);
      setWarehouses(options);
      const initial=options.find(w=>w.code==='local')||options[0];
      const resume=options.find(w=>Number(w.id)===Number(pendingImport?.warehouse_id));
      setWarehouseId(resume?String(resume.id):(initial?String(initial.id):''));
      if(!initial)notify('No hay locales o bodegas activos para importar.','error');
    }).catch(e=>{if(alive)notify('No se pudieron cargar las ubicaciones: '+e.message,'error');})
    .finally(()=>{if(alive)setWarehouseLoading(false);});
    return ()=>{alive=false;};
  },[]);
  const columns=matrix[0]?.map((v,i)=>String(v??`Columna ${i+1}`))??[];
  const preview=useMemo(()=>matrix.length>1&&mapping ? validateRows(matrix.slice(1),mapping) : {rows:[],errors:[]},[matrix,mapping]);
  const mustMap=['name','price_clp'];const missing=mustMap.some(k=>mapping?.[k]<0);
  const needsStock=mode!=='preserve_existing' && preview.rows.some(r=>r.stock_qty===null);
  const selectedWarehouse=warehouses.find(w=>String(w.id)===warehouseId);
  const tooMany=preview.rows.length>1000;
  const valid=Boolean(selectedWarehouse) && preview.rows.length>0 && !missing && !needsStock && !tooMany && preview.errors.length===0;
  async function loadFile(e) {
    const file=e.target.files?.[0];if(!file||pendingImport)return;
    e.target.value='';setFileName(file.name);setResult(null);setProgress('');setBusy(true);
    try {
      if(file.size>10*1024*1024)throw new Error('Archivo demasiado grande (máximo 10 MB).');
      let rows;
      if(/\.xlsx$/i.test(file.name))rows=await readSheet(file);
      else if(/\.csv$/i.test(file.name)){
        const text=(await file.text()).replace(/^\uFEFF/,'');
        rows=csvParse(text,guessSeparator(text));
      } else throw new Error('Formato no admitido. Utiliza .xlsx o .csv.');
      if(!rows?.length)throw new Error('El archivo está vacío.');
      setMatrix(rows);setMapping(suggestMap(rows[0]));
    } catch(err){setMatrix([]);setMapping(null);notify(err.message,'error');}
    finally{setBusy(false);}
  }
  async function sendImport(payload){
    if(busy)return;
    setBusy(true);setResult(null);
    const summary={created:0,updated:0,unchanged:0,stock_adjusted:0};
    const all=payload.rows;
    try{
      for(let i=0;i<all.length;i+=30){
        const chunk_index=Math.floor(i/30);
        setProgress(`Importando lote ${chunk_index+1} de ${Math.ceil(all.length/30)}…`);
        const data=await request('/products/import',{method:'POST',body:{
          import_id:payload.import_id,chunk_index,warehouse_id:payload.warehouse_id,
          stock_mode:payload.stock_mode,rows:all.slice(i,i+30)
        }});
        for(const key of Object.keys(summary))summary[key]+=Number(data.summary?.[key]||0);
      }
      sessionStorage.removeItem('tito_pending_inventory_import');
      setPendingImport(null);setResult(summary);
      notify('Importación completada en '+payload.warehouse_name+'.','success');
      refresh();setProgress('');
    }catch(err){
      setProgress('Importación incompleta. Usa «Reintentar la misma importación» para evitar duplicar stock.');
      notify('Importación interrumpida: '+err.message+'. Algunos lotes pueden estar guardados. Reintenta la misma operación, no crees una nueva.','error');
    }finally{setBusy(false);}
  }
  function submit(){
    if(!valid||busy||pendingImport||!selectedWarehouse)return;
    const action=mode==='set_to_excel'
      ?'REEMPLAZAR las existencias SOLO en '+selectedWarehouse.name
      :mode==='add_excel'
      ?'SUMAR las cantidades del archivo SOLO en '+selectedWarehouse.name
      :'CONSERVAR las existencias de productos existentes; los nuevos entrarán en '+selectedWarehouse.name;
    if(!window.confirm('Destino: '+selectedWarehouse.name+'\nAcción: '+action+'.\nProductos: '+preview.rows.length+'. ¿Confirmas la importación?'))return;
    const payload={
      import_id:newId(),warehouse_id:Number(selectedWarehouse.id),
      warehouse_name:selectedWarehouse.name,stock_mode:mode,
      rows:preview.rows.map(({rowNumber,problems,...r})=>r)
    };
    sessionStorage.setItem('tito_pending_inventory_import',JSON.stringify(payload));
    setPendingImport(payload);
    void sendImport(payload);
  }
  return <><div className="section-intro"><div><p className="eyebrow">CARGA MASIVA</p><h2>Importar inventario</h2><p className="muted">Sube un Excel o CSV, revisa los campos y decide cómo tratar el stock.</p></div></div>
    <section className="panel pad">
    <div className="stock-options">
      <label>Destino del inventario
        <select aria-label="Sucursal o bodega de destino" value={warehouseId} disabled={busy||warehouseLoading||Boolean(pendingImport)} onChange={e=>setWarehouseId(e.target.value)}>
          <option value="">— Selecciona una ubicación —</option>
          {warehouses.map(w=><option key={w.id} value={String(w.id)}>{w.name}{Number(w.is_sales_location)===1?' · Local':' · Bodega'}</option>)}
        </select>
      </label>
      <small>{warehouseLoading?'Cargando ubicaciones…':selectedWarehouse?'Las existencias se cargarán únicamente en '+selectedWarehouse.name+'. Las demás sucursales conservarán sus cantidades.':'No hay una ubicación válida. Comprueba las bodegas antes de importar.'}</small>
    </div>
    <label className="file-drop"><span className="file-glyph">⇧</span><strong>{fileName || 'Selecciona un archivo Excel o CSV'}</strong><span>Formatos .xlsx y .csv · Máximo 10 MB · Primera hoja del Excel</span><input type="file" accept=".xlsx,.csv" onChange={loadFile} disabled={busy||Boolean(pendingImport)||warehouseLoading}/></label>
    {matrix.length>0&&<><div className="panel-heading extra-top"><div><h3>Asignación de columnas</h3><p>{preview.rows.length} productos detectados · Revisa antes de continuar</p></div></div><div className="mapping-grid">{headers.map(h=><label key={h}><span>{({sku:'SKU',barcode:'Código de barras',name:'Nombre *',price_clp:'Precio CLP *',stock_qty:'Stock',sale_unit:'Unidad'})[h]}</span><select value={mapping?.[h]??-1} onChange={e=>setMapping(m=>({...m,[h]:Number(e.target.value)}))}><option value="-1">— No incluir —</option>{columns.map((c,i)=><option key={i} value={i}>{c}</option>)}</select></label>)}</div>
    <div className="stock-options"><strong>Stock de productos existentes en el destino</strong>
      <label><input type="radio" name="stockmode" value="preserve_existing" checked={mode==='preserve_existing'} disabled={busy||Boolean(pendingImport)} onChange={()=>setMode('preserve_existing')}/> Conservar las existencias actuales <span>(recomendado)</span></label>
      <label><input type="radio" name="stockmode" value="add_excel" checked={mode==='add_excel'} disabled={busy||Boolean(pendingImport)} onChange={()=>setMode('add_excel')}/> Sumar cantidades del Excel al destino</label>
      <label><input type="radio" name="stockmode" value="set_to_excel" checked={mode==='set_to_excel'} disabled={busy||Boolean(pendingImport)} onChange={()=>setMode('set_to_excel')}/> Reemplazar existencias en el destino <span>(requiere confirmación)</span></label>
      <small>Los productos nuevos entran en la ubicación elegida con la cantidad del archivo. Los existentes solo cambian su cantidad en esa ubicación cuando eliges sumar o reemplazar. El catálogo y los precios son compartidos entre sucursales.</small>
    </div>
    <div className="panel-heading extra-top"><div><h3>Vista previa</h3><p>Primeras 12 filas · Los errores se deben corregir antes de importar</p></div><span className={'pill '+(preview.errors.length?'amber':'green')}>{preview.errors.length} errores</span></div>
    <div className="table-wrap"><table><thead><tr><th>#</th><th>Producto</th><th>SKU / Barras</th><th>Precio</th><th>Stock</th></tr></thead><tbody>{preview.rows.slice(0,12).map(r=><tr key={r.rowNumber}><td>{r.rowNumber}</td><td>{r.name||'—'}</td><td className="mono">{r.sku||r.barcode||'—'}</td><td>{Number.isFinite(r.price_clp)?pesos(r.price_clp):'Error'}</td><td>{r.stock_qty===null?'—':qtyFormat(r.stock_qty)}</td></tr>)}</tbody></table></div>
    {preview.errors.length>0&&<div className="error-list"><strong>Problemas detectados:</strong>{preview.errors.slice(0,12).map(x=><p key={x.row}>Fila {x.row}: {x.text}</p>)}{preview.errors.length>12&&<p>Y {preview.errors.length-12} errores más…</p>}</div>}
    {missing&&<p className="error-inline">Asigna las columnas Nombre y Precio.</p>}{needsStock&&<p className="error-inline">Para sumar o reemplazar stock, todas las filas necesitan cantidades.</p>}{tooMany&&<p className="error-inline">Límite de este MVP: 1000 productos por archivo. Divide el archivo y revisa el total cargado.</p>}
    <div className="action-bar"><span className="muted">{progress||'La importación cambia datos reales de MySQL.'}</span><button className="primary" disabled={!valid||busy||Boolean(pendingImport)||warehouseLoading} onClick={submit}>{busy?'Procesando…':`Importar ${preview.rows.length} productos`}</button></div>
    </>}
    {pendingImport&&<div className="warning-box"><strong>Importación pendiente: {pendingImport.warehouse_name}</strong>
      <p>El navegador conserva el identificador original para no duplicar productos o existencias si se interrumpió un lote.</p>
      <div className="inline-actions">
        <button type="button" className="primary" disabled={busy} onClick={()=>sendImport(pendingImport)}>Reintentar la misma importación</button>
        <button type="button" className="ghost mini" disabled={busy} onClick={()=>{
          if(window.confirm('Descarta esta importación solo si ya verificaste sus resultados y NO vas a repetir el archivo. ¿Descartar registro pendiente?')){
            sessionStorage.removeItem('tito_pending_inventory_import');setPendingImport(null);setProgress('');
          }
        }}>Descartar tras verificar</button>
      </div>
    </div>}
    {result&&<div className="success-summary"><strong>Importación finalizada</strong><p>Creados: {result.created} · Actualizados: {result.updated} · Sin cambios: {result.unchanged} · Stock ajustado: {result.stock_adjusted}</p></div>}</section>
  </>;
}
function SellPanel({ products, request, refresh, notify, canDiscount, canApproveDiscount, canRegisterCredit, isAdmin, terminalStatus, cashStatus, onCashChanged, onOpenCash, onShowSales, businessName, active, newSaleRequest, onCreateClient, createdClient, onClientConsumed }) {
  const [localById,setLocalById]=useState(null);
  const [reservedById,setReservedById]=useState({}),[dispatchReservation,setDispatchReservation]=useState(null);
  const [saleLocationId,setSaleLocationId]=useState(null);
  const [saleLocations,setSaleLocations]=useState([]);
  const [legacyStock,setLegacyStock]=useState(false);
  useEffect(()=>{
    let alive=true;
    request('/account/sales-location').then(d=>{
      if(!alive)return;
      setSaleLocationId(Number(d.warehouse_id));setSaleLocations(d.locations||[]);
    }).catch(e=>{if(alive){setSaleLocationId(null);notify('No se pudo confirmar la sucursal de esta caja: '+e.message,'error');}});
    return ()=>{alive=false;};
  },[]);
  const stockRequestSequence=useRef(0);
  async function loadStoreStock(){
    const sequence=++stockRequestSequence.current;
    if(!saleLocationId){setLocalById(null);return;}
    try{
      const data=await request('/warehouses/stock');
      if(sequence!==stockRequestSequence.current)return;
      setLegacyStock(false);
      setReservedById(Object.fromEntries((data.stocks||[]).map(p=>[Number(p.product_id),Number(p.reserved_locations?.[String(saleLocationId)]??0)])));
      setLocalById(Object.fromEntries((data.stocks||[]).map(p=>[Number(p.product_id),Number(p.locations?.[String(saleLocationId)]??(saleLocations.find(x=>Number(x.id)===saleLocationId)?.code==='local'?p.local_qty:0))])));
    }catch(e){
      if(sequence!==stockRequestSequence.current)return;
      if(e.status===404){setLegacyStock(true);setLocalById(null);return;}
      setLegacyStock(false);setLocalById(null);notify('No se pudo verificar stock del local: '+e.message,'error');
    }
  }
  useEffect(()=>{if(active)void loadStoreStock();},[active,products,saleLocationId]);
  useEffect(()=>{
    if(!canRegisterCredit)return;
    let open=true;
    request('/clients').then(d=>{if(open)setCreditClients(d.clients||[]);}).catch(()=>{if(open)setCreditClients([]);});
    return ()=>{open=false;};
  },[canRegisterCredit,active]);
  const saleProducts=useMemo(()=>legacyStock?products:localById===null?[]:products.map(p=>({...p,stock_qty:localById[Number(p.id)]??0})),[products,localById,legacyStock]);
  const reservableProducts=useMemo(()=>saleProducts.map(p=>({...p,free_qty:Math.max(0,Number(p.stock_qty)-(reservedById[Number(p.id)]||0))})),[saleProducts,reservedById]);
  const [search,setSearch]=useState('');const [cart,setCart]=useState([]);const [payment,setPayment]=useState('efectivo');const [busy,setBusy]=useState(false);const [pending,setPending]=useState(()=>{try{const p=JSON.parse(sessionStorage.getItem('tito_pending_sale')||'null');return p?.discount_request_id?null:p;}catch{return null;}});
  const [lastSale,setLastSale]=useState(()=>{try{return JSON.parse(sessionStorage.getItem('tito_last_sale')||'null');}catch{return null;}});
  const [remoteBusy,setRemoteBusy]=useState(false);
  const [serialClients,setSerialClients]=useState([]),[serialClient,setSerialClient]=useState(''),[serialClientQuery,setSerialClientQuery]=useState(''),[serialClientError,setSerialClientError]=useState('');
  const [customerMode,setCustomerMode]=useState('consumer_final');
  const [creditClients,setCreditClients]=useState([]);
  const [creditClientId,setCreditClientId]=useState('');
  const [creditDueDate,setCreditDueDate]=useState('');
  const [creditNotes,setCreditNotes]=useState('');
  const {queue:remoteQueue,addRequest:addRemoteRequest,registerApproved:retryRemote,cancelRequest:cancelRemote,dismissRequest:dismissRemote}=
    useCashierApprovalQueue({request,refresh,notify});
  const [approvalPlan,setApprovalPlan]=useState(null);
  const [creditPending,setCreditPending]=useState(()=>{try{return JSON.parse(sessionStorage.getItem('tito_pending_credit_sale')||'null');}catch{return null;}});
  const [approvalEmail,setApprovalEmail]=useState('');
  const [approvalPassword,setApprovalPassword]=useState('');
  const inputRef=useRef(null);
  async function printLastSale(){
    if(!lastSale?.id||busy)return;
    // Reservar pestaña durante el clic: el usuario puede imprimir desde el visor PDF.
    const tab=window.open('','_blank');
    try{
      const sale=await request('/sales/'+Number(lastSale.id));
      const result=await openSaleVoucher(sale,businessName,{isCredit:!!lastSale.is_credit,tab,autoPrint:true});
      if(result==='downloaded')notify('Voucher PDF descargado. Ábrelo para imprimir o compartir.','info');
    }catch(error){if(tab&&!tab.closed)tab.close();notify('No se pudo generar el voucher: '+error.message,'error');}
  }
  function startNextSale(){
    if(pending||creditPending||approvalPlan)return;
    sessionStorage.removeItem('tito_last_sale');setLastSale(null);
    setCart([]);resetCustomer();setSearch('');setPayment('efectivo');setCreditClientId('');setCreditDueDate('');setCreditNotes('');
    inputRef.current?.focus();
  }
  useEffect(()=>{if(newSaleRequest>0)startNextSale();},[newSaleRequest]);
  useEffect(()=>{if(active&&!lastSale)inputRef.current?.focus();},[active,lastSale]);
  const matches=useMemo(()=>{const q=search.trim().toLocaleLowerCase('es-CL');return !q?[]:saleProducts.filter(p=>[p.name,p.sku,p.barcode].some(v=>String(v??'').toLocaleLowerCase('es-CL').includes(q))).slice(0,12);},[saleProducts,search]);
  const total=cart.reduce((acc,c)=>acc+Math.round(Number(c.unitPrice)*c.quantity),0);
  const listTotal=cart.reduce((acc,c)=>acc+Math.round(Number(c.product.price_clp)*c.quantity),0);
  const discountTotal=Math.max(0,listTotal-total);
  const lock=!!pending||!!creditPending||!!approvalPlan||!!lastSale;
  const hasSerialCart=cart.some(c=>Number(c.product.serial_control)===1);
  const customerSearchVersion=useRef(0);
  async function searchSerialClients(){const version=++customerSearchVersion.current;setSerialClientError('');try{const d=await request('/sales/serial-clients?q='+encodeURIComponent(serialClientQuery));if(version!==customerSearchVersion.current)return;setSerialClients(old=>{const found=d.clients||[];const chosen=old.find(c=>String(c.id)===serialClient);return chosen&&!found.some(c=>String(c.id)===serialClient)?[chosen,...found]:found;});}catch(e){if(version===customerSearchVersion.current)setSerialClientError(e.message);}}
  useEffect(()=>{if(hasSerialCart)setCustomerMode('registered');},[hasSerialCart]);
  useEffect(()=>{if(active&&payment!=='credito'&&(hasSerialCart||customerMode==='registered')&&!lock&&!dispatchReservation)void searchSerialClients();},[active,hasSerialCart,customerMode,dispatchReservation,payment]);
  useEffect(()=>{if(!createdClient)return;customerSearchVersion.current++;setSerialClients(old=>[createdClient,...old.filter(c=>String(c.id)!==String(createdClient.id))]);setSerialClient(String(createdClient.id));setCustomerMode('registered');setSerialClientQuery('');onClientConsumed?.();},[createdClient]);
  function resetCustomer(){customerSearchVersion.current++;setCustomerMode('consumer_final');setSerialClient('');setSerialClientQuery('');setSerialClients([]);setSerialClientError('');}
  async function findSaleSeries(id,loc,q=''){setCart(c=>c.map(x=>x.product.id===id?{...x,serialBusy:true,serialError:''}:x));try{const d=await request('/sales/serial-available?product_id='+id+'&warehouse_id='+loc+'&q='+encodeURIComponent(q));setCart(c=>c.map(x=>x.product.id===id&&x.serialWarehouse===loc?{...x,available:d.units||[],serialMore:d.more,serialBusy:false}:x));}catch(e){setCart(c=>c.map(x=>x.product.id===id&&x.serialWarehouse===loc?{...x,serialError:e.message,serialBusy:false}:x));}}
  function add(product) {if(lock||dispatchReservation)return;const controlled=Number(product.serial_control)===1;if(controlled&&cart.some(x=>x.product.id===product.id)){setSearch('');return;}
    setCart(c=>{const existing=c.find(x=>x.product.id===product.id);return existing ? c.map(x=>x.product.id===product.id?{...x,quantity:String((Number(x.quantity)||0)+1)}:x):[...c,{product,quantity:controlled?'0':'1',unitPrice:Number(product.price_clp),discountReason:'Precio negociado',serials:[],available:[],serialSearch:'',serialWarehouse:saleLocationId,serialBusy:controlled}];});setSearch('');if(controlled)void findSaleSeries(product.id,saleLocationId);}
  function prepareDispatch(r){if(lock||busy||cart.length){notify('Vacía el carrito antes de preparar una reserva.','error');return;}const rows=r.items.map(i=>({item:i,product:saleProducts.find(p=>Number(p.id)===Number(i.product_id))}));if(rows.some(x=>!x.product)){notify('No se pudo cargar uno de los productos reservados.','error');return;}setDispatchReservation(r);setCustomerMode('registered');setSerialClients([{id:r.client_id,name:r.client_name}]);setSerialClient(String(r.client_id));setPayment('efectivo');setCart(rows.map(({product})=>({product,quantity:'0',unitPrice:Number(product.price_clp),discountReason:'Precio negociado',serials:[],available:[],serialSearch:'',serialWarehouse:saleLocationId,serialBusy:true})));for(const {product} of rows)void findSaleSeries(product.id,saleLocationId);}
  function toggleSaleSeries(id,n,checked){setCart(c=>c.map(x=>{if(x.product.id!==id)return x;const serials=checked?[...new Set([...x.serials,n])]:x.serials.filter(s=>s!==n);return {...x,serials,quantity:String(serials.length)};}));}

  function scan(e) {if(e.key!=='Enter')return;e.preventDefault();const code=search.trim();if(!code)return;const found=saleProducts.find(p=>String(p.barcode??'')===code||String(p.sku??'')===code);if(found)add(found);else notify('No existe un producto con ese código.','error');}
  function changeQty(id,raw){if(lock)return;setCart(c=>c.map(x=>x.product.id===id?{...x,quantity:raw}:x));}
  function stepQty(id,delta){if(lock)return;setCart(c=>c.map(x=>x.product.id===id?{...x,quantity:String(Math.max(1,Math.round((Number(x.quantity)||0)+delta)))}:x));}
  function removeFromCart(id){if(lock||dispatchReservation)return;setCart(c=>c.filter(x=>x.product.id!==id));}
  function changePrice(id,raw){if(lock)return;setCart(c=>c.map(x=>x.product.id===id?{...x,unitPrice:raw===''?'':Number(raw)}:x));}
  function changeReason(id,value){if(lock)return;setCart(c=>c.map(x=>x.product.id===id?{...x,discountReason:value}:x));}
  async function registerCredit(salePayload){
    setBusy(true);
    try{
      const result=await request('/credit/sales',{method:'POST',body:salePayload});
      const receipt={id:Number(result.sale_id),total:Number(result.total_clp),payment:'credito',is_credit:true,items:salePayload.items.length,when:new Date().toISOString()};
      sessionStorage.setItem('tito_last_sale',JSON.stringify(receipt));setLastSale(receipt);
      sessionStorage.removeItem('tito_pending_credit_sale');setCreditPending(null);
      setCart([]);resetCustomer();setSearch('');setPayment('efectivo');setCreditClientId('');setCreditDueDate('');setCreditNotes('');
      notify(result.already_recorded?'Esta venta a crédito ya estaba registrada.':'Venta a crédito #'+receipt.id+' registrada; el pago quedó PENDIENTE.','success');
      await refresh();await loadStoreStock();
    }catch(err){
      if(err.status>=400&&err.status<500&&err.status!==408&&err.status!==429){
        sessionStorage.removeItem('tito_pending_credit_sale');setCreditPending(null);
        notify('Venta a crédito rechazada: '+err.message,'error');
      }else{
        notify('Resultado incierto al registrar la venta a crédito: '+err.message+'. Comprueba Clientes → Crédito heredado y reintenta EL MISMO registro; no generes otra venta.','error');
      }
    }finally{setBusy(false);}
  }
  async function registerDelegatedDiscount(salePayload){
    setBusy(true);
    try{
      const result=await request('/sales/delegated-discount-approve',{method:'POST',body:{sale:salePayload}});
      const authorized={...salePayload,discount_approval_token:result.discount_approval_token};
      sessionStorage.setItem('tito_pending_sale',JSON.stringify(authorized));setPending(authorized);
      await register(authorized);
    }catch(err){
      notify('No se pudo autorizar este descuento delegado: '+err.message,'error');
    }finally{setBusy(false);}
  }
  async function register(salePayload) {
    setBusy(true);
    try {
      const data=await request('/sales',{method:'POST',body:salePayload});
      const receipt={id:Number(data.sale_id),total:Number(data.total_clp),payment:salePayload.payment_method,items:salePayload.items.length,when:new Date().toISOString()};
      sessionStorage.setItem('tito_last_sale',JSON.stringify(receipt));
      setLastSale(receipt);
      notify('Venta #'+receipt.id+' registrada correctamente.','success');
      await onCashChanged();
      sessionStorage.removeItem('tito_pending_sale');setPending(null);
      setDispatchReservation(null);setCart([]);resetCustomer();setSearch('');refresh();loadStoreStock();
    } catch(e) {
      if (e.status>=400 && e.status<500 && e.status!==408 && e.status!==429) {
        sessionStorage.removeItem('tito_pending_sale');setPending(null);
        notify(`Venta no registrada: ${e.message}`, 'error');
      } else notify('Resultado incierto por error de conexión. Reintenta exactamente la misma venta; así evitamos duplicarla.', 'error');
    } finally {setBusy(false);}
  }
  function checkout() {
    if(lock||busy||!saleLocationId||(!legacyStock&&localById===null)||!cart.length){if(!legacyStock&&localById===null)notify('No fue posible cargar el stock del local.','error');return;}
    for(const c of cart){const q=Number(c.quantity);const freshProduct=saleProducts.find(p=>Number(p.id)===Number(c.product.id));const stock=Number(freshProduct?.stock_qty??0);if(!Number.isFinite(q)||q<=0||(c.product.sale_unit==='unidad'&&!Number.isInteger(q))||Math.round(q*1000)!==q*1000||q>stock){notify(`Cantidad o stock insuficiente para ${c.product.name}.`,'error');return;}}
    for(const c of cart){
      const agreed=Number(c.unitPrice),list=Number(c.product.price_clp);
      if(!Number.isInteger(agreed)||agreed<0||agreed>list||(list>0&&agreed===0)){
        notify(`Revisa el precio acordado de ${c.product.name}. Debe ser un monto válido en pesos y no superar el precio de lista.`,'error');return;
      }
    }
    if(dispatchReservation&&dispatchReservation.items.some(i=>cart.find(c=>Number(c.product.id)===Number(i.product_id))?.serials.length!==Number(i.quantity))){notify('Selecciona exactamente las unidades reservadas.','error');return;}
    if(hasSerialCart){if(!serialClient){notify('Selecciona el cliente de los equipos.','error');return;}if(payment==='credito'){notify('Venta a crédito por serie pendiente de integración.','error');return;}if(!canDiscount&&discountTotal>0){notify('Descuento autorizado por terceros pendiente para ventas por serie.','error');return;}if(cart.some(c=>Number(c.product.serial_control)===1&&(c.serials.length!==Number(c.quantity)||c.serials.length>500))){notify('Selecciona una serie por unidad.','error');return;}}
    if(payment!=='credito'&&(hasSerialCart||customerMode==='registered')&&!serialClient){notify('Selecciona un cliente registrado.','error');return;}
    if(payment==='credito'){
      if(!canRegisterCredit){notify('No estás habilitado para registrar ventas a crédito.','error');return;}
      if(!canApproveDiscount&&cart.some(c=>Number(c.unitPrice)<Number(c.product.price_clp))){notify('Para fiar con descuento también necesitas permiso para autorizar descuentos.','error');return;}
      const customer=creditClients.find(c=>String(c.id)===String(creditClientId));
      if(!customer||Number(customer.credit_enabled)!==1||Number(customer.is_active)!==1){notify('Selecciona un cliente habitual habilitado para crédito.','error');return;}
      const body={request_id:newId(),warehouse_id:saleLocationId,client_id:Number(creditClientId),due_date:creditDueDate||null,notes:creditNotes.trim()||null,items:cart.map(c=>({
        product_id:Number(c.product.id),quantity:Number(c.quantity),unit_price_clp:Number(c.unitPrice),
        discount_reason:Number(c.unitPrice)<Number(c.product.price_clp)?c.discountReason||'Precio negociado':null
      }))};
      sessionStorage.setItem('tito_pending_credit_sale',JSON.stringify(body));setCreditPending(body);void registerCredit(body);
      return;
    }
    const body={request_id:newId(),warehouse_id:saleLocationId,payment_method:payment,customer_mode:hasSerialCart?'registered':customerMode,...(hasSerialCart||customerMode==='registered'?{client_id:Number(serialClient)}:{}),...(dispatchReservation?{reservation_id:Number(dispatchReservation.id)}:{}),items:cart.map(c=>({
      product_id:Number(c.product.id),quantity:Number(c.quantity),...(Number(c.product.serial_control)===1?{serial_numbers:c.serials}:{}),
      ...(Number(c.unitPrice)<Number(c.product.price_clp)?{unit_price_clp:Number(c.unitPrice),discount_reason:c.discountReason||'Precio negociado'}:{})
    }))};
    if(!canDiscount&&cart.some(c=>Number(c.unitPrice)<Number(c.product.price_clp))){
      if(canApproveDiscount){void registerDelegatedDiscount(body);return;}
      setApprovalPlan(body);
      return;
    }
    sessionStorage.setItem('tito_pending_sale',JSON.stringify(body));setPending(body);register(body);
  }
  async function approveDiscount(e){
    e.preventDefault();if(!approvalPlan||busy)return;
    const email=approvalEmail.trim().toLowerCase();
    if(!email||!approvalPassword){notify('El administrador debe ingresar su correo y contraseña.','error');return;}
    setBusy(true);
    try{
      const approved=await request('/sales/discount-approve',{method:'POST',body:{
        sale:approvalPlan,supervisor_email:email,supervisor_password:approvalPassword
      }});
      if(!approved.ok||!approved.discount_approval_token)throw new Error('No se recibió autorización válida.');
      const authorizedSale={...approvalPlan,discount_approval_token:approved.discount_approval_token};
      sessionStorage.setItem('tito_pending_sale',JSON.stringify(authorizedSale));
      setPending(authorizedSale);
      setApprovalPlan(null);setApprovalPassword('');setApprovalEmail('');
      notify('Descuento autorizado por '+approved.authorized_by+'. Registrando venta…','info');
      await register(authorizedSale);
    }catch(error){
      setApprovalPassword('');
      if(error.status===404)notify('La autorización de descuentos aún no está instalada en la API.','error');
      else notify('No se pudo autorizar: '+error.message,'error');
    }finally{setBusy(false);}
  }
  async function requestRemoteApproval(){
    if(!approvalPlan||remoteBusy)return;
    setRemoteBusy(true);
    try{
      const id=await addRemoteRequest(approvalPlan,{total_clp:total,list_total_clp:listTotal,discount_clp:discountTotal});
      // Esta venta queda aparcada en su propia solicitud. El siguiente cliente
      // recibe un carrito completamente nuevo, sin mezclar productos ni pagos.
      setApprovalPlan(null);setApprovalPassword('');setApprovalEmail('');
      setCart([]);resetCustomer();setSearch('');setPayment('efectivo');
      notify('Solicitud #'+id+' enviada. Puedes atender al siguiente cliente mientras se autoriza.','success');
      void loadStoreStock();
    }catch(error){
      notify('No se pudo confirmar la solicitud: '+error.message+'. Puedes reintentar sin cambiar los datos.','error');
    }finally{setRemoteBusy(false);}
  }
  return <><div className="section-intro"><div><p className="eyebrow">PUNTO DE VENTA</p><h2>Nueva venta</h2><p className="muted">Busca un producto o usa un lector USB. {legacyStock?'Modo anterior: las bodegas aún no están activadas; se usa stock global.':'Solo se venden existencias de la sucursal seleccionada.'}</p></div></div>
    <section className="panel pad">
      <div className="cash-sale-indicator">
        <div><p className="eyebrow">GAVETA Y TURNO ACTIVO</p>
        <strong>{cashStatus.session.drawer_name} · {cashStatus.session.drawer_code}</strong>
        <p className="helper">Fondo inicial: {pesos(cashStatus.session.opening_clp)} · Efectivo esperado al consultar: {pesos(cashStatus.session.current_expected_clp)}</p></div>
        <button type="button" className="ghost mini" onClick={onOpenCash}>Ver rendición y movimientos</button>
      </div>
    </section>
    <section className="panel pad">
      <label>Sucursal de la caja<select aria-label="Sucursal de la caja" value={saleLocationId||''} disabled={!isAdmin||!!terminalStatus?.terminal||lock||busy||!saleLocationId} onChange={e=>{if(cart.length&&!window.confirm('Cambiar de sucursal vaciará el carrito actual. ¿Continuar?'))return;setDispatchReservation(null);setCart([]);setLocalById(null);setSaleLocationId(Number(e.target.value));}}>
        {saleLocations.map(w=><option key={w.id} value={w.id}>{w.name}</option>)}
      </select></label>
      {terminalStatus?.terminal?<p className="helper">✓ Caja identificada automáticamente: {terminalStatus.terminal.label}. Las ventas solo usarán esta sucursal.</p>:
      terminalStatus?.enforced?<p className="warning-box">Dispositivo no identificado. Las ventas están bloqueadas. Entra a Más → Identificación de caja para activar tu turno o caja fija.</p>:
      !isAdmin?<p className="helper">Mientras la identificación automática está desactivada, se utiliza tu sucursal asignada.</p>:null}
    </section>
    {lastSale&&<section className="sale-success-receipt" role="status" aria-live="polite">
      <div className="sale-success-heading"><span className="sale-success-check">✓</span><div><h3>{lastSale.is_credit?'¡Venta a crédito registrada!':'¡Venta realizada!'}</h3><p>{lastSale.is_credit?'Pago pendiente; todavía NO se ha cobrado dinero.':'Registrada y confirmada por Tito POS'}</p></div></div>
      <div className="sale-success-details"><div><small>Número de venta</small><strong>#{lastSale.id}</strong></div><div><small>{lastSale.is_credit?'Saldo por cobrar':'Total cobrado'}</small><strong>{pesos(lastSale.total)}</strong></div><div><small>Medio de pago</small><strong className="capitalize">{lastSale.payment}</strong></div></div>
      <p className="sale-success-next">La venta anterior está confirmada. Para atender al siguiente cliente, pulsa el botón verde:</p>
      <div className="sale-success-actions"><button type="button" className="primary sale-next-button" onClick={startNextSale}>+ Nueva venta · siguiente cliente</button><button type="button" className="ghost" onClick={printLastSale} disabled={busy}>Imprimir voucher</button><button type="button" className="ghost" onClick={onShowSales}>Ver venta en historial</button></div>
    </section>}
    {saleLocationId&&<ReservationsPanel key={saleLocationId} request={request} notify={notify} warehouseId={saleLocationId} products={reservableProducts} onChanged={loadStoreStock} onDispatch={prepareDispatch} disabled={lock||busy||!!dispatchReservation||cart.length>0}/>}
    {dispatchReservation&&<section className="panel pad"><strong>Preparando reserva #{dispatchReservation.id} · {dispatchReservation.client_name}</strong><p>{dispatchReservation.items.map(i=>i.name+' × '+i.quantity).join(' · ')}</p><button type="button" className="ghost" disabled={lock||busy} onClick={()=>{setDispatchReservation(null);setCart([]);setSerialClient('');}}>Salir de preparación sin cancelar reserva</button></section>}
    <CashierRemoteQueue queue={remoteQueue} onRetry={id=>retryRemote(id,{manual:true})} onCancel={cancelRemote} onDismiss={dismissRemote} onShowSales={onShowSales}/>
    <div className="sell-grid"><section className="panel pad"><div className="sale-product-heading"><h3>Agregar productos</h3><button className="ghost mini" type="button" disabled={busy} onClick={()=>{void refresh();void loadStoreStock();}}>↻ Actualizar existencias</button></div>{lastSale&&<p className="sale-locked-hint">La venta anterior está cerrada. Usa «Nueva venta · siguiente cliente» arriba para seguir atendiendo.</p>}<input ref={inputRef} className="search-sale" type="search" placeholder="⌕  Buscar o escanear código…" value={search} onKeyDown={scan} onChange={e=>setSearch(e.target.value)} disabled={lock}/><p className="helper">Un lector USB tipo teclado puede ingresar el código y pulsar Enter.</p>
      <div className="product-tiles">{matches.map(p=><button key={p.id} disabled={lock||!!dispatchReservation||Number(p.stock_qty)-(reservedById[Number(p.id)]||0)<=0} onClick={()=>add(p)} className="product-tile"><span><strong>{p.name}</strong><small>{p.sku||p.barcode||'Sin código'} · Libre local {qtyFormat(Math.max(0,Number(p.stock_qty)-(reservedById[Number(p.id)]||0)))}</small></span><b>{pesos(p.price_clp)}</b><span className="addcircle">+</span></button>)}{matches.length===0&&<Empty text={localById===null&&!legacyStock?'Cargando stock del local…':search.trim()?'No hay productos que coincidan.':'Escribe un nombre, SKU o código para buscar productos.'}/>}</div>{search.trim()&&matches.length===12&&<p className="helper">Se muestran hasta 12 coincidencias. Escribe más letras para precisar.</p>}</section>
      <section className="panel pad cart-panel"><div className="panel-heading"><div><h3>Detalle de venta</h3><p>{cart.length} productos distintos</p></div>{cart.length>0&&!lock&&!dispatchReservation&&<button className="text-button" onClick={()=>setCart([])}>Vaciar</button>}</div>{pending&&!busy&&<div className="warning-box">Hay una venta con resultado pendiente. Comprueba el historial antes de reintentar para evitar duplicados.</div>}
      {cart.length===0?<Empty text="Agrega productos para comenzar una venta."/>:<div className="cart-items">{cart.map(c=>{
        const hasDiscount=Number(c.unitPrice)<Number(c.product.price_clp);
        return <div key={c.product.id} className="cart-item cart-item-negotiable">
          <div className="cart-product-name"><strong>{c.product.name}</strong><small>Precio de lista: {pesos(c.product.price_clp)} / {c.product.sale_unit}</small><button type="button" className="ghost mini remove-cart" disabled={lock||!!dispatchReservation} onClick={()=>removeFromCart(c.product.id)}>Quitar producto</button></div>
          <div className="cart-controls">
            {Number(c.product.serial_control)===1?<div><strong>Series: {c.serials.length}</strong><p className="helper">Selecciona equipos de entrega inmediata.</p><div className="field-row"><input value={c.serialSearch} maxLength={120} disabled={lock||c.serialBusy} onChange={e=>setCart(a=>a.map(x=>x.product.id===c.product.id?{...x,serialSearch:e.target.value}:x))} placeholder="Buscar serie"/><button type="button" className="ghost mini" disabled={lock||c.serialBusy} onClick={()=>findSaleSeries(c.product.id,saleLocationId,c.serialSearch)}>Buscar</button></div>{c.serialError&&<p role="alert">{c.serialError}</p>}<div style={{maxHeight:180,overflowY:'auto'}}>{c.available.map(s=><label key={s.id}><input type="checkbox" checked={c.serials.includes(s.serial_number)} disabled={lock||(!c.serials.includes(s.serial_number)&&c.serials.length>=500)} onChange={e=>toggleSaleSeries(c.product.id,s.serial_number,e.target.checked)}/>{s.serial_number}</label>)}{c.serialBusy&&<p>Cargando…</p>}{!c.serialBusy&&!c.available.length&&<p>Sin series disponibles.</p>}</div>{c.serialMore&&<p>Filtra para ver más series.</p>}{c.serials.filter(n=>!c.available.some(s=>s.serial_number===n)).map(n=><button type="button" key={n} className="ghost mini" disabled={lock} onClick={()=>toggleSaleSeries(c.product.id,n,false)}>{n} ×</button>)}</div>:<label>Cantidad <span className="sale-quantity-control"><button type="button" aria-label={'Restar uno a '+c.product.name} disabled={lock||Number(c.quantity)<=1||c.product.sale_unit!=='unidad'} onClick={()=>stepQty(c.product.id,-1)}>−</button><input aria-label={'Cantidad '+c.product.name} type="text" inputMode={c.product.sale_unit==='unidad'?'numeric':'decimal'} autoComplete="off" value={c.quantity} disabled={lock} onFocus={e=>e.currentTarget.select()} onChange={e=>changeQty(c.product.id,e.target.value)}/><button type="button" aria-label={'Sumar uno a '+c.product.name} disabled={lock||c.product.sale_unit!=='unidad'} onClick={()=>stepQty(c.product.id,1)}>+</button></span></label>}
            <label>Precio unitario acordado ($)<input aria-label={'Precio unitario acordado de '+c.product.name} type="number" min={Number(c.product.price_clp)>0?1:0} max={Number(c.product.price_clp)} step="1" value={c.unitPrice} disabled={lock} onChange={e=>changePrice(c.product.id,e.target.value)}/></label>
          </div>
          {hasDiscount&&<div className="discount-note"><span>Rebaja: {pesos((Number(c.product.price_clp)-Number(c.unitPrice))*Number(c.quantity))}</span><label>Motivo <select value={c.discountReason} disabled={lock} onChange={e=>changeReason(c.product.id,e.target.value)}><option>Precio negociado</option><option>Compra por volumen</option><option>Cliente habitual</option><option>Promoción puntual</option></select></label></div>}
          <div className="item-subtotal">Subtotal: <strong>{pesos(Number(c.unitPrice)*c.quantity)}</strong></div>
        </div>;
      })}</div>}
      <div className="cart-bottom">{payment!=='credito'&&<div className="formstack">
        <label>Identificación del cliente<select value={hasSerialCart?'registered':customerMode} disabled={lock||busy||hasSerialCart||!!dispatchReservation} onChange={e=>{setCustomerMode(e.target.value);if(e.target.value==='consumer_final'){customerSearchVersion.current++;setSerialClient('');setSerialClientQuery('');}}}><option value="consumer_final" disabled={hasSerialCart}>Consumidor final</option><option value="registered">Cliente registrado</option></select></label>
        {hasSerialCart&&<p className="helper">La venta contiene equipos con número de serie: requiere un cliente registrado.</p>}
        {(hasSerialCart||customerMode==='registered')&&<><label>Buscar por nombre o RUT<input value={serialClientQuery} disabled={lock||busy||!!dispatchReservation} maxLength={120} onChange={e=>setSerialClientQuery(e.target.value)} onKeyDown={e=>{if(e.key==='Enter'){e.preventDefault();void searchSerialClients();}}}/></label><button type="button" className="ghost mini" disabled={lock||busy||!!dispatchReservation} onClick={searchSerialClients}>Buscar cliente</button><label>Cliente<select value={serialClient} disabled={lock||busy||!!dispatchReservation} onChange={e=>setSerialClient(e.target.value)}><option value="">Selecciona cliente</option>{serialClients.map(c=><option key={c.id} value={c.id}>{c.name}{c.rut?' · '+c.rut:''}</option>)}</select></label>{serialClientError&&<p role="alert">{serialClientError}</p>}{onCreateClient&&!dispatchReservation&&<button type="button" className="ghost" disabled={lock||busy} onClick={onCreateClient}>+ Nuevo cliente</button>}{!onCreateClient&&<p className="helper">Solicita al administrador el registro de un nuevo cliente.</p>}</>}
      </div>}{!canApproveDiscount&&discountTotal>0&&<p className="discount-approval-notice"><strong>Se requiere autorización del administrador.</strong> El cajero no puede completar esta venta con descuento sin su aprobación.</p>}<label>Medio de pago<select value={payment} onChange={e=>setPayment(e.target.value)} disabled={lock}><option value="efectivo">Efectivo</option><option value="transferencia">Transferencia</option><option value="tarjeta">Tarjeta</option><option value="otro">Otro</option>{canRegisterCredit&&<option value="credito">Crédito · pagar después</option>}</select></label>{payment==='credito'&&canRegisterCredit&&<div className="credit-checkout-fields">
        <p className="warning-box">Esta operación entregará mercadería ahora y dejará todo el monto como deuda pendiente. No se contabiliza como dinero recibido.</p>
        <label>Cliente habitual autorizado<select value={creditClientId} disabled={lock} onChange={e=>setCreditClientId(e.target.value)}>
          <option value="">Selecciona cliente</option>{creditClients.filter(c=>Number(c.is_active)===1&&Number(c.credit_enabled)===1).map(c=><option value={c.id} key={c.id}>{c.name} · Límite {pesos(c.credit_limit_clp)} · Debe {pesos(c.outstanding_clp||0)}</option>)}
        </select></label>
        <label>Fecha comprometida de pago (opcional)<input type="date" value={creditDueDate} disabled={lock} onChange={e=>setCreditDueDate(e.target.value)}/></label>
        <label>Observación (opcional)<input maxLength={250} value={creditNotes} disabled={lock} onChange={e=>setCreditNotes(e.target.value)} placeholder="Ej. Don Cristóbal autorizó pagar el viernes"/></label>
      </div>}{discountTotal>0&&<div className="discount-total"><span>Total antes de rebajas</span><span>{pesos(listTotal)}</span><strong>Descuentos aplicados</strong><strong>-{pesos(discountTotal)}</strong></div>}<div className="grand-total"><span>Total a cobrar</span><strong>{pesos(total)}</strong></div>
      {creditPending?<div className="warning-box"><strong>Venta a crédito pendiente de confirmación</strong><p>Reintenta el MISMO registro. No crees una segunda venta mientras verificas el resultado.</p><button className="primary wide" disabled={busy} onClick={()=>registerCredit(creditPending)}>{busy?'Verificando…':'Reintentar el mismo crédito'}</button></div>:pending?<><button className="primary wide" disabled={busy} onClick={()=>register(pending)}>{busy?'Verificando…':'Reintentar exactamente la venta pendiente'}</button><button className="ghost wide" disabled={busy} onClick={()=>{if(window.confirm('Solo descarta este intento si verificaste que no existe una venta pendiente en el sistema. ¿Deseas descartarlo?')){sessionStorage.removeItem('tito_pending_sale');setPending(null);}}}>Descartar intento (revisar antes)</button></>:<button className="primary wide" disabled={!cart.length||busy} onClick={checkout}>{busy?'Guardando…':'Confirmar venta y descontar stock'}</button>}
      <p className="helper">Registro interno de venta. No constituye boleta ni factura del SII.</p></div></section></div>
    {approvalPlan&&<Modal title="Autorizar descuento de esta venta" onClose={()=>{if(!busy){setApprovalPlan(null);setApprovalPassword('');setApprovalEmail('');}}}>
      <div className="discount-approval-summary">
        <p>El cajero solicita un descuento puntual. El administrador debe revisar los montos y aprobar esta venta desde este dispositivo.</p>
        <div className="discount-approval-products">{cart.filter(c=>Number(c.unitPrice)<Number(c.product.price_clp)).map(c=><div key={c.product.id} className="discount-approval-product"><strong>{c.product.name}</strong><small>{qtyFormat(c.quantity)} {c.product.sale_unit} · {pesos(c.product.price_clp)} → {pesos(c.unitPrice)} por unidad · {c.discountReason}</small></div>)}</div>
        <div className="sale-detail-total"><span>Antes de rebaja</span><strong>{pesos(listTotal)}</strong></div>
        <div className="sale-detail-total"><span>Descuento solicitado</span><strong>-{pesos(discountTotal)}</strong></div>
        <div className="sale-detail-total"><span>Total a cobrar</span><strong>{pesos(total)}</strong></div>
        <p className="helper">La autorización se aplica solo a esta venta y vence en 5 minutos. No concede permisos generales al cajero.</p>
      </div>
      <form className="formstack" onSubmit={approveDiscount}>
        <label>Correo del administrador<input type="email" autoComplete="off" inputMode="email" required value={approvalEmail} disabled={busy} onChange={e=>setApprovalEmail(e.target.value)} placeholder="administrador@negocio.cl"/></label>
        <PasswordField label="Contraseña del administrador" autoComplete="off" required value={approvalPassword} disabled={busy} onChange={e=>setApprovalPassword(e.target.value)}/>
        <button className="primary wide" type="submit" disabled={busy||remoteBusy}>{busy?'Verificando autorización…':'Autorizar presencialmente y registrar'}</button>
        <button className="ghost wide" type="button" disabled={busy||remoteBusy} onClick={requestRemoteApproval}>{remoteBusy?'Enviando solicitud…':'Solicitar autorización a distancia'}</button>
        <button className="ghost wide" type="button" disabled={busy} onClick={()=>{setApprovalPlan(null);setApprovalPassword('');setApprovalEmail('');}}>Cancelar sin vender</button>
      </form>
    </Modal>}
  </>;
}

function timeChile(raw) {
  if (!raw) return '—';
  const stamp = new Date(String(raw).replace(' ','T')+'Z');
  return Number.isFinite(stamp.valueOf())
    ? stamp.toLocaleString('es-CL',{timeZone:'America/Santiago',dateStyle:'medium',timeStyle:'short'})
    : String(raw);
}

function SalesPanel({sales,onRefresh,request,notify,canVoid,onStartReturn,businessName}) {
  const [detail,setDetail]=useState(null);
  const [discountAuthorization,setDiscountAuthorization]=useState(null);
  const [voidTarget,setVoidTarget]=useState(null);
  const [returnCount,setReturnCount]=useState(null);
  const [creditSales,setCreditSales]=useState({});
  const [salesQuery,setSalesQuery]=useState(''); const [salesPeriod,setSalesPeriod]=useState('all'); const [salesStatus,setSalesStatus]=useState('all'); const [salesPage,setSalesPage]=useState(1);
  useEffect(()=>{
    if(!canVoid)return;
    let active=true;
    request('/credit/accounts').then(d=>{if(active)setCreditSales(Object.fromEntries((d.accounts||[]).map(a=>[Number(a.sale_id),a])));}).catch(()=>{});
    return ()=>{active=false;};
  },[sales,canVoid]);
  const [busy,setBusy]=useState(false);
  const completed=sales.filter(s=>(s.status||'completed')==='completed');
  const total=completed.reduce((sum,s)=>sum+Number(s.total_clp),0);
  const filteredSales=useMemo(()=>sales.filter(s=>{
    const q=salesQuery.trim().toLocaleLowerCase('es-CL');
    const status=s.status||'completed';
    return (!q||[s.id,s.seller_email,s.warehouse_name,s.payment_method,creditSales[s.id]?'Crédito':''].some(x=>String(x??'').toLocaleLowerCase('es-CL').includes(q)))
      &&(salesStatus==='all'||status===salesStatus)
      &&inChilePeriod(s.created_at,salesPeriod);
  }),[sales,salesQuery,salesStatus,salesPeriod,creditSales]);
  const salesPageData=pageSlice(filteredSales,salesPage,20);
  useEffect(()=>setSalesPage(1),[salesQuery,salesPeriod,salesStatus]);
  async function printExistingSale(){
    if(!detail?.sale?.id||busy)return;
    const tab=window.open('','_blank');
    try{
      const result=await openSaleVoucher(detail,businessName,{isCredit:Boolean(creditSales[detail.sale.id]),tab,autoPrint:true});
      if(result==='downloaded')notify('Voucher PDF descargado. Ábrelo para imprimir o compartir.','info');
    }catch(error){if(tab&&!tab.closed)tab.close();notify('No se pudo generar el voucher: '+error.message,'error');}
  }
  async function openSale(id) {
    setBusy(true);
    try {
      const sale=await request('/sales/'+id);
      setDetail(sale);
      setDiscountAuthorization(null);
      setReturnCount(null);
      if(canVoid){
        try{const info=await request('/sales/'+id+'/returns');setReturnCount((info.returns||[]).length);}
        catch(e){if(e.status!==404)notify('No se pudo revisar el historial de devoluciones: '+e.message,'error');}
      }
      try{
        const result=await request('/sales/'+id+'/discount-approval');
        if(result.approval)setDiscountAuthorization(result.approval);
        else{
          try{
            const remote=await request('/sales/'+id+'/remote-discount-approval');
            if(remote.approval)setDiscountAuthorization(remote.approval);
          }catch(e){if(e.status!==404)notify('No se pudo verificar aprobación remota: '+e.message,'error');}
        }
      }catch(e){if(e.status!==404)notify('No se pudo verificar la autorización: '+e.message,'error');}
    }
    catch(error){notify(error.message,'error');}
    finally {setBusy(false);}
  }
  async function voidSale(e) {
    e.preventDefault();
    if(!voidTarget)return;
    if(creditSales[voidTarget.id]){notify('Las ventas a crédito no pueden anularse directamente mientras exista una cuenta por cobrar.','error');return;}
    if(returnCount===null||returnCount>0){notify('Esta venta tiene devoluciones o no se pudo verificar su estado. No se puede anular por completo.','error');return;}
    const f=new FormData(e.currentTarget);
    const reason=String(f.get('reason')||'').trim();
    if(reason.length<5){notify('El motivo debe contener al menos 5 caracteres.','error');return;}
    if(voidTarget.serial_client_name&&f.get('serial_inspection_confirmed')!=='on'){notify('Confirma la recepción física y estado de todas las series vendidas.','error');return;}
    if(!window.confirm('¿Anular la venta #'+voidTarget.id+' y reponer todo su stock? No se reembolsará dinero ni se anularán documentos tributarios.'))return;
    setBusy(true);
    try{
      const result=await request('/sales/'+voidTarget.id+'/void',{method:'POST',body:{reason,...(voidTarget.serial_client_name?{serial_inspection_confirmed:f.get('serial_inspection_confirmed')==='on'}:{})}});
      setVoidTarget(null);
      notify('Venta #'+result.sale_id+' anulada. Stock repuesto; comprueba cualquier devolución de dinero por separado.','success');
      await onRefresh();
      await openSale(result.sale_id);
    }catch(error){notify(error.message,'error');}
    finally{setBusy(false);}
  }
  const lineDiscount=(item)=>Math.max(0,Math.round(Number(item.quantity)*(Number(item.list_price_clp)-Number(item.unit_price_clp))));
  return <>
    <div className="section-intro"><div><p className="eyebrow">HISTORIAL</p><h2>Ventas registradas</h2><p className="muted">Selecciona una venta para revisar sus productos y descuentos.</p></div><button className="ghost" onClick={onRefresh}>↻ Actualizar</button></div>
    <div className="stats-grid two"><Stat label="Ventas listadas" value={sales.length} detail="Máximo 100 (incluye anuladas)" ico="◷"/><Stat label="Ventas vigentes (bruto)" value={pesos(total)} detail="Sin descontar devoluciones; máximo 100 ventas. NO representa cobros." ico="$"/></div>
    {canVoid&&<FinancialSummary request={request} notify={notify} variant="compact"/>}
    <section className="panel">
      <div className="list-filters">
        <label>Buscar venta<input type="search" placeholder="N.º, vendedor o medio de pago…" value={salesQuery} onChange={e=>setSalesQuery(e.target.value)}/></label>
        <label>Período<select value={salesPeriod} onChange={e=>setSalesPeriod(e.target.value)}><option value="all">Últimas cargadas</option><option value="today">Hoy</option><option value="week">Esta semana</option><option value="month">Este mes</option></select></label>
        <label>Estado<select value={salesStatus} onChange={e=>setSalesStatus(e.target.value)}><option value="all">Todas</option><option value="completed">Vigentes</option><option value="voided">Anuladas</option></select></label>
      </div><p className="list-filter-note">Los filtros y páginas se aplican solo a las últimas {sales.length} ventas descargadas (máximo 100); no consultan el historial completo del servidor.</p>
      <div className="table-wrap"><table><thead><tr><th>Venta</th><th>Cliente</th><th>Fecha Chile</th><th>Vendedor</th><th>Sucursal</th><th>Medio</th><th>Estado</th><th>Total</th><th>Detalle</th></tr></thead><tbody>{salesPageData.rows.map(s=><tr key={s.id}><td><strong>#{s.id}</strong></td><td>{s.client_name||(s.customer_mode==='consumer_final'?'Consumidor final':'—')}</td><td>{timeChile(s.created_at)}</td><td>{s.seller_email||'—'}</td><td>{s.warehouse_name||'Local original'}</td><td className="capitalize">{creditSales[s.id]?"Crédito":s.payment_method}</td><td><span className={'pill '+((s.status||'completed')==='voided'?'amber':'green')}>{(s.status||'completed')==='voided'?'Anulada':'Vigente'}</span></td><td><strong className={(s.status||'completed')==='voided'?'muted':''}>{pesos(s.total_clp)}</strong></td><td><button className="ghost mini" disabled={busy} onClick={()=>openSale(s.id)}>Ver</button></td></tr>)}</tbody></table>{filteredSales.length===0&&<Empty text={sales.length===0?"Todavía no hay ventas registradas.":"No hay ventas que coincidan con estos filtros en los registros cargados."}/>}</div>
      <div className="list-pagination"><span>{salesPageData.total} ventas encontradas · Página {salesPageData.page} de {salesPageData.pages}</span><div className="inline-actions"><button type="button" className="ghost mini" disabled={salesPageData.page<=1} onClick={()=>setSalesPage(p=>p-1)}>← Anterior</button><button type="button" className="ghost mini" disabled={salesPageData.page>=salesPageData.pages} onClick={()=>setSalesPage(p=>p+1)}>Siguiente →</button></div></div></section>
    {detail&&<Modal title={'Venta #'+detail.sale.id+' · '+(detail.sale.warehouse_name||'Local original')} onClose={()=>setDetail(null)}>
      {detail.sale.status==='voided'&&<div className="warning-box"><strong>Venta anulada</strong><p>Motivo: {detail.sale.void_reason||'No informado'}</p><p>Por: {detail.sale.voided_by_email||'Administrador'} · {timeChile(detail.sale.voided_at)}</p></div>}
      {discountAuthorization&&<div className="discount-authorization-audit"><strong>Descuento autorizado</strong><span>Administrador: {discountAuthorization.supervisor_email} · {timeChile(discountAuthorization.approved_at)}</span></div>}
      <div className="sale-meta"><div><small>Fecha</small><strong>{timeChile(detail.sale.created_at)}</strong></div><div><small>Vendedor</small><strong>{detail.sale.seller_email||'—'}</strong></div><div><small>Medio registrado</small><strong className="capitalize">{creditSales[detail.sale.id]?'Crédito · pago diferido':detail.sale.payment_method}</strong></div></div>
      <div className="table-wrap"><table className="sale-detail-table"><thead><tr><th>Producto</th><th>Cantidad</th><th>Precio</th><th>Subtotal</th></tr></thead><tbody>{detail.items.map(item=><tr key={item.id}><td><strong>{item.product_name||'Producto #'+item.product_id}</strong><small className="sub">{item.sku||''}</small>{item.serial_numbers?.length>0&&<small className="sub">Series: {item.serial_numbers.join(', ')}</small>}{lineDiscount(item)>0&&<small className="discount-line">Rebaja: {pesos(lineDiscount(item))} · {item.discount_reason||'Sin motivo registrado'}</small>}</td><td>{qtyFormat(item.quantity)}</td><td>{pesos(item.unit_price_clp)}</td><td><strong>{pesos(item.subtotal_clp)}</strong></td></tr>)}</tbody></table></div>
      <div className="sale-detail-total"><span>Total registrado</span><strong>{pesos(detail.sale.total_clp)}</strong></div>
      <div className="inline-actions sale-voucher-actions">
        <button type="button" className="primary" disabled={busy} onClick={printExistingSale}>Imprimir voucher · 80 mm</button>
        <p className="helper">Se abrirá un PDF con el detalle de esta venta; podrás imprimirlo o guardarlo desde el navegador.</p>
      </div>
      {canVoid&&detail.sale.status!=='voided'&&creditSales[detail.sale.id]&&<p className="warning-box">Esta venta es a crédito. Para registrar una devolución, una anulación completa o un reembolso, usa Más → Clientes → Crédito heredado → Cuentas por cobrar → Ver / gestionar. Allí se ajustan conjuntamente deuda e inventario.</p>}
      {(detail.sale.client_name||detail.sale.serial_client_name||detail.sale.customer_mode==='consumer_final')&&<p><strong>Cliente:</strong> {detail.sale.client_name||detail.sale.serial_client_name||'Consumidor final'}{detail.sale.client_rut?' · '+detail.sale.client_rut:''}</p>}
      {canVoid&&detail.sale.status!=='voided'&&!creditSales[detail.sale.id]&&<div className="sale-return-actions"><button className="primary wide" type="button" disabled={busy} onClick={()=>{setDetail(null);onStartReturn(detail.sale.id);}}>↩ Registrar devolución de productos</button>
        {returnCount===0?<button className="ghost wide danger-button" disabled={busy} onClick={()=>setVoidTarget(detail.sale)}>Anular esta venta y reponer todo el stock</button>:<p className="helper">{returnCount>0?'Esta venta ya tiene devoluciones: no corresponde una anulación total.': 'La anulación total requiere verificar el historial de devoluciones en la API.'}</p>}
      </div>}
      <p className="helper">Registro interno de venta. No reemplaza boleta o factura del SII.</p>
    </Modal>}
    {voidTarget&&<Modal title={'Anular venta #'+voidTarget.id} onClose={()=>setVoidTarget(null)}>
      <div className="warning-box"><strong>Operación irreversible desde Tito POS</strong><p>Se mantendrá el registro y se devolverán las cantidades al inventario una sola vez. No hay reembolso bancario ni anulación de boletas SII.</p></div>
      <form className="formstack" onSubmit={voidSale}>
        {voidTarget.serial_client_name&&<label><input type="checkbox" name="serial_inspection_confirmed" required/> Confirmo que recibí físicamente todas las series de la venta, están completas y aptas para reventa.</label>}
        <label>Motivo de anulación<textarea name="reason" required minLength="5" maxLength="250" rows="3" placeholder="Ej. Venta registrada por error"/></label>
        <button className="primary wide" disabled={busy}>{busy?'Procesando…':'Confirmar anulación'}</button>
      </form>
    </Modal>}
  </>;
}

function UsersPanel({request,notify,ownId}) {
  const [users,setUsers]=useState([]);
  const [salesLocations,setSalesLocations]=useState([]);
  const [staff,setStaff]=useState([]);
  const [staffEvents,setStaffEvents]=useState([]);
  const [delegationAvailable,setDelegationAvailable]=useState(false);
  const [loading,setLoading]=useState(true);
  const [busy,setBusy]=useState(false);
  const [showNew,setShowNew]=useState(false);
  const [resetUser,setResetUser]=useState(null);
  async function load() {
    try { const [data,w]=await Promise.all([request('/users'),request('/warehouses')]);setUsers(data.users||[]);setSalesLocations((w.warehouses||[]).filter(x=>Number(x.is_sales_location)===1&&Number(x.is_active)===1)); }
    catch(e){notify(e.message,'error');}
    finally{setLoading(false);}
  }
  async function loadStaff(){
    try{
      const [p,e]=await Promise.all([request('/staff/permissions'),request('/staff/events')]);
      setStaff(p.staff||[]);setStaffEvents(e.events||[]);setDelegationAvailable(true);
    }catch(error){
      setDelegationAvailable(false);
      if(error.status!==404)notify('No se pudieron consultar las delegaciones: '+error.message,'error');
    }
  }
  useEffect(()=>{load();void loadStaff();},[]);
  async function changeSalesLocation(user,warehouseId){
    const location=salesLocations.find(w=>Number(w.id)===warehouseId);
    if(!location||!window.confirm('Asignar '+location.name+' a '+user.email+'? Se cerrarán sus sesiones para aplicar el cambio.'))return;
    setBusy(true);
    try{await request('/users/'+user.id+'/sales-location',{method:'POST',body:{warehouse_id:warehouseId}});notify('Sucursal asignada; el cajero debe iniciar sesión nuevamente.','success');await load();}
    catch(e){notify('No se pudo asignar la sucursal: '+e.message,'error');}
    finally{setBusy(false);}
  }
  async function changeDelegation(person,key,enabled){
    const field=key==='can_approve_discounts'?'autorizar descuentos':'registrar fiados';
    if(!window.confirm((enabled?'¿Habilitar':'¿Revocar')+' '+field+' para '+person.email+'?'))return;
    setBusy(true);
    try{
      const next={
        can_approve_discounts:key==='can_approve_discounts'?enabled:Number(person.can_approve_discounts)===1,
        can_sell_credit:key==='can_sell_credit'?enabled:Number(person.can_sell_credit)===1
      };
      await request('/staff/permissions/'+person.id,{method:'POST',body:next});
      notify('Permisos actualizados para '+person.email+'.','success');
      await loadStaff();
    }catch(error){notify('No se actualizaron los permisos: '+error.message,'error');}
    finally{setBusy(false);}
  }
  async function create(e) {
    e.preventDefault();
    const f=new FormData(e.currentTarget);
    const password=String(f.get('password')||'');
    if(password!==String(f.get('confirm')||'')){notify('Las contraseñas no coinciden.','error');return;}
    setBusy(true);
    try {
      await request('/users',{method:'POST',body:{
        email:String(f.get('email')||'').trim(),role:String(f.get('role')||'cashier'),password
      }});
      notify('Usuario creado correctamente.','success');setShowNew(false);await load();await loadStaff();
    }catch(e){notify(e.message,'error');}
    finally{setBusy(false);}
  }
  async function update(id,body,label) {
    if(!window.confirm(label))return;
    setBusy(true);
    try {await request('/users/'+id+'/update',{method:'POST',body});notify('Usuario actualizado.','success');await load();await loadStaff();}
    catch(e){notify(e.message,'error');}
    finally{setBusy(false);}
  }
  async function reset(e) {
    e.preventDefault();
    const f=new FormData(e.currentTarget),password=String(f.get('password')||'');
    if(password!==String(f.get('confirm')||'')){notify('Las contraseñas no coinciden.','error');return;}
    setBusy(true);
    try {await request('/users/'+resetUser.id+'/update',{method:'POST',body:{password}});
      notify('Contraseña actualizada. El usuario deberá iniciar sesión nuevamente.','success');setResetUser(null);
    }catch(e){notify(e.message,'error');}
    finally{setBusy(false);}
  }
  return <>
    <div className="section-intro"><div><p className="eyebrow">ACCESOS</p><h2>Usuarios</h2><p className="muted">Asigna administradores y cajeros; los permisos se aplican en la API.</p></div><button className="primary" onClick={()=>setShowNew(true)}>+ Nuevo usuario</button></div>
    <section className="panel"><div className="table-wrap"><table><thead><tr><th>Correo</th><th>Rol</th><th>Sucursal de caja</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>{users.map(u=><tr key={u.id}><td><strong>{u.email}</strong>{Number(u.id)===ownId&&<small className="sub">Tu cuenta</small>}</td><td><select aria-label={'Rol de '+u.email} value={u.role} disabled={busy||Number(u.id)===ownId} onChange={e=>update(u.id,{role:e.target.value},'¿Cambiar el rol de '+u.email+'?') }><option value="admin">Administrador</option><option value="cashier">Cajero</option></select></td><td>{u.role==='cashier'?<select aria-label={'Sucursal asignada a '+u.email} value={u.sales_warehouse_id||salesLocations.find(w=>w.code==='local')?.id||''} disabled={busy||Number(u.is_active)!==1||!salesLocations.length} onChange={e=>changeSalesLocation(u,Number(e.target.value))}>{salesLocations.map(w=><option value={w.id} key={w.id}>{w.name}</option>)}</select>:<span className="muted">Todas</span>}</td><td><span className={'pill '+(Number(u.is_active)?'green':'amber')}>{Number(u.is_active)?'Activo':'Desactivado'}</span></td><td><div className="inline-actions"><button className="ghost mini" disabled={busy||Number(u.id)===ownId} onClick={()=>update(u.id,{is_active:!Number(u.is_active)},'¿Cambiar el estado de '+u.email+'?')}>{Number(u.is_active)?'Desactivar':'Activar'}</button><button className="ghost mini" disabled={busy} onClick={()=>setResetUser(u)}>Contraseña</button></div></td></tr>)}</tbody></table>{loading&&<Empty text="Cargando usuarios…"/>}{!loading&&!users.length&&<Empty text="No hay usuarios registrados."/>}</div></section>
    <section className="panel pad staff-delegation-panel">
      <h3>Permisos especiales para cajeros</h3>
      <p className="muted">El administrador decide quién puede aprobar precios especiales y quién puede registrar ventas a crédito. No entrega acceso para cambiar precios del catálogo, autorizar clientes nuevos, modificar límites de crédito ni gestionar usuarios.</p>
      {!delegationAvailable?<p className="warning-box">Los permisos delegados aún no están habilitados en la API de cPanel. Hasta instalarlos, ningún cajero obtiene nuevas autorizaciones.</p>:<div className="table-wrap"><table><thead><tr><th>Cajero</th><th>Autorizar descuentos</th><th>Registrar fiados</th></tr></thead><tbody>{staff.map(person=><tr key={person.id}><td><strong>{person.email}</strong><small className="sub">{Number(person.is_active)===1?'Activo':'Desactivado'}</small></td>
        <td><label className="staff-switch"><input type="checkbox" disabled={busy||Number(person.is_active)!==1} checked={Number(person.can_approve_discounts)===1} onChange={e=>changeDelegation(person,'can_approve_discounts',e.target.checked)}/><span>{Number(person.can_approve_discounts)===1?'Habilitado':'No autorizado'}</span></label></td>
        <td><label className="staff-switch"><input type="checkbox" disabled={busy||Number(person.is_active)!==1} checked={Number(person.can_sell_credit)===1} onChange={e=>changeDelegation(person,'can_sell_credit',e.target.checked)}/><span>{Number(person.can_sell_credit)===1?'Habilitado':'No autorizado'}</span></label></td></tr>)}</tbody></table>{staff.length===0&&<Empty text="No hay cajeros registrados todavía."/>}</div>}
    </section>
    {delegationAvailable&&<section className="panel pad staff-delegation-panel"><div className="panel-heading"><div><h3>Actividad de autorizaciones delegadas</h3><p>Permite revisar qué hizo cada trabajador, aunque el aviso push no se haya recibido.</p></div><button type="button" className="ghost mini" disabled={busy} onClick={loadStaff}>↻ Actualizar</button></div>
      <div className="table-wrap"><table><thead><tr><th>Fecha</th><th>Cajero</th><th>Acción</th><th>Monto</th><th>Referencia</th></tr></thead><tbody>{staffEvents.map(event=><tr key={event.id}><td>{timeChile(event.created_at)}</td><td>{event.actor_email}</td><td>{({discount_authorized:'Descuento propio autorizado',discount_approved:'Solicitud aprobada',discount_rejected:'Solicitud rechazada',credit_sale:'Venta a crédito'})[event.event_type]||event.event_type}</td><td>{event.amount_clp===null?'—':pesos(event.amount_clp)}</td><td>{event.sale_id?'Venta #'+event.sale_id:event.description}</td></tr>)}</tbody></table>{staffEvents.length===0&&<Empty text="Aún no hay autorizaciones delegadas registradas."/>}</div>
      <p className="helper">«Descuento propio autorizado» no garantiza que la venta haya terminado. Confirma su cierre en Ventas. Los avisos push requieren que el administrador tenga su dispositivo registrado.</p>
    </section>}
    {showNew&&<Modal title="Crear usuario" onClose={()=>setShowNew(false)}><form className="formstack" onSubmit={create}><label>Correo<input type="email" name="email" required maxLength="190" autoComplete="off"/></label><label>Rol<select name="role" defaultValue="cashier"><option value="cashier">Cajero</option><option value="admin">Administrador</option></select></label><PasswordField label="Contraseña (mínimo 12 caracteres)" name="password" minLength={12} maxLength={72} required autoComplete="new-password"/><PasswordField label="Repetir contraseña" name="confirm" minLength={12} required autoComplete="new-password"/><p className="helper">Entrega la contraseña de forma privada al trabajador. No se envían correos automáticamente.</p><button className="primary wide" disabled={busy}>{busy?'Guardando…':'Crear usuario'}</button></form></Modal>}
    {resetUser&&<Modal title={'Cambiar contraseña · '+resetUser.email} onClose={()=>setResetUser(null)}><form className="formstack" onSubmit={reset}><PasswordField label="Nueva contraseña" minLength={12} maxLength={72} name="password" required autoComplete="new-password"/><PasswordField label="Repetir nueva contraseña" minLength={12} name="confirm" required autoComplete="new-password"/><button className="primary wide" disabled={busy}>{busy?'Guardando…':'Actualizar contraseña'}</button></form></Modal>}
  </>;
}
function Modal({title,onClose,children}) { useEffect(()=>{function esc(e){if(e.key==='Escape')onClose();}document.addEventListener('keydown',esc);return()=>document.removeEventListener('keydown',esc);},[onClose]);return <div className="modal-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget)onClose();}}><div className="modal" role="dialog" aria-modal="true" aria-label={title}><div className="modal-head"><h3>{title}</h3><button className="ghost close" onClick={onClose} aria-label="Cerrar">×</button></div>{children}</div></div>}

createRoot(document.getElementById('root')).render(new URLSearchParams(window.location.search).get('platform')==='1'?<PlatformPanel/>:<App/>);
