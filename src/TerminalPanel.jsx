import React,{useEffect,useState} from 'react';
import {TERMINAL_KEY} from './api.js';

const QR_KEY='tito_pending_terminal_activation';
const isCode=value=>/^[a-f0-9]{32}$/.test(String(value||'').toLowerCase().trim());
const cleanCode=value=>String(value||'').replace(/[^0-9a-f]/gi,'').toLowerCase().slice(0,32);

export default function TerminalPanel({request,notify,isAdmin,onActivated}){
  const [status,setStatus]=useState(null);
  const [locations,setLocations]=useState([]);
  const [registered,setRegistered]=useState([]);
  const [selected,setSelected]=useState('');
  const [kind,setKind]=useState('shift');
  const [label,setLabel]=useState('');
  const [pair,setPair]=useState(null);
  const [qr,setQr]=useState('');
  const [code,setCode]=useState(()=>sessionStorage.getItem(QR_KEY)||'');
  const [busy,setBusy]=useState(false);
  const [loading,setLoading]=useState(true);
  const [error,setError]=useState('');

  async function load(){
    setLoading(true);setError('');
    try{
      const active=await request('/account/terminal-status');
      setStatus(active);
      if(isAdmin){
        const [warehouses,terminals]=await Promise.all([request('/warehouses'),request('/terminals')]);
        const options=(warehouses.warehouses||[]).filter(w=>Number(w.is_sales_location)===1&&Number(w.is_active)===1);
        setLocations(options);setRegistered(terminals.terminals||[]);
        setSelected(old=>options.some(w=>String(w.id)===old)?old:String(options[0]?.id||''));
      }
    }catch(e){setError('No se pudo consultar la identificación de caja: '+e.message);}
    finally{setLoading(false);}
  }
  useEffect(()=>{void load();},[isAdmin]);

  async function activate(raw){
    const value=cleanCode(raw);
    if(busy||!isCode(value)){notify('Código inválido. Revisa los 32 caracteres o vuelve a escanear el QR.','error');return;}
    setBusy(true);
    try{
      const result=await request('/account/terminal-activate',{method:'POST',body:{code:value}});
      localStorage.setItem(TERMINAL_KEY,result.terminal_token);
      sessionStorage.removeItem(QR_KEY);setCode('');
      notify('Caja identificada automáticamente: '+result.terminal.warehouse_name+'.','success');
      await load();onActivated(true);
    }catch(e){sessionStorage.removeItem(QR_KEY);setCode('');notify('No se pudo activar esta caja: '+e.message,'error');}
    finally{setBusy(false);}
  }
  // Al escanear el QR desde un celular con sesión abierta, la activación se ejecuta
  // una sola vez. Si aún no había sesión, App trae aquí al cajero tras login.
  useEffect(()=>{
    const pending=sessionStorage.getItem(QR_KEY);
    if(pending&&isCode(pending)&&!busy)void activate(pending);
  },[]);

  async function issue(e){
    e.preventDefault();if(busy)return;
    setBusy(true);
    try{
      const data=await request('/terminals/pair',{method:'POST',body:{warehouse_id:Number(selected),kind,label:label.trim()}});
      const link=window.location.origin+window.location.pathname+'#tito_activate='+data.code;
      setPair({...data,link});
      const lib=await import('qrcode');
      setQr(await lib.default.toDataURL(link,{width:256,margin:2,errorCorrectionLevel:'M'}));
      notify('Código temporal emitido. Solo podrá usarse una vez durante cinco minutos.','info');
    }catch(e){notify('No se pudo emitir código: '+e.message,'error');}
    finally{setBusy(false);}
  }
  async function revokeSelf(){
    if(busy||!window.confirm('¿Desactivar esta caja? Para vender habrá que volver a autorizarla.'))return;
    setBusy(true);
    try{
      await request('/account/terminal-revoke-self',{method:'POST',body:{}});
      localStorage.removeItem(TERMINAL_KEY);await load();onActivated(false);
      notify('Caja desvinculada. Ya no puede registrar ventas con este permiso.','success');
    }catch(e){notify('No se pudo desactivar la caja: '+e.message,'error');}
    finally{setBusy(false);}
  }
  async function revokeTerminal(id){
    if(busy||!window.confirm('¿Revocar esta caja de todos los dispositivos donde se use su autorización?'))return;
    setBusy(true);
    try{
      await request('/terminals/'+id+'/revoke',{method:'POST',body:{}});
      notify('Autorización revocada.','success');await load();onActivated(false);
    }catch(e){notify('No se pudo revocar: '+e.message,'error');}
    finally{setBusy(false);}
  }
  async function changeEnforcement(enabled){
    if(busy||!window.confirm(enabled
      ?'Al activar esta protección, NINGÚN cajero ni administrador podrá vender desde una caja sin autorización. ¿Continuar?'
      :'¿Desactivar la exigencia de cajas registradas? Volverán a usarse las asignaciones anteriores de usuario.'))return;
    setBusy(true);
    try{
      await request('/terminals/enforcement',{method:'POST',body:{enabled}});
      notify(enabled?'Ahora todas las ventas exigen una caja registrada.':'Se restauró temporalmente el funcionamiento con asignación anterior.','success');
      await load();onActivated(false);
    }catch(e){notify('No se pudo cambiar la política: '+e.message,'error');}
    finally{setBusy(false);}
  }

  return <div className="terminal-management">
    <div className="section-intro"><div><p className="eyebrow">SUCURSAL AUTOMÁTICA</p><h2>Identificación de caja</h2><p className="muted">La sucursal se reconoce en el servidor mediante una autorización de este navegador. No depende de IP fija ni GPS.</p></div></div>
    {loading?<p className="helper">Comprobando identificación…</p>:error?<div className="warning-box">{error}</div>:null}
    {status?.feature_ready&&<section className="panel pad">
      <h3>Este dispositivo</h3>
      {status.invalid_terminal&&<div className="warning-box">
        La autorización anterior de este navegador venció o fue revocada.
        <button type="button" className="ghost mini" onClick={()=>{localStorage.removeItem(TERMINAL_KEY);void load();onActivated(false);}}>Olvidar autorización anterior</button>
      </div>}
      {status.terminal?<div className="terminal-bound">
        <strong>✓ {status.terminal.warehouse_name}</strong>
        <p>{status.terminal.label} · {status.terminal.kind==='fixed'?'Caja fija':'Turno de celular'}</p>
        <p className="helper">Vigencia hasta: {status.terminal.expires_at} UTC. Todas las ventas desde este navegador se asignarán a esta sucursal.</p>
        <button type="button" className="ghost" disabled={busy} onClick={revokeSelf}>Desactivar esta caja</button>
      </div>:<div className="warning-box"><strong>Dispositivo sin sucursal reconocida</strong>
        <p>{status.enforced?'Ventas bloqueadas hasta activar una caja.':'Aún se utiliza la asignación de sucursal del usuario. Registra esta caja antes de activar el modo estricto.'}</p>
      </div>}
      <p className="helper">La autorización pertenece a este navegador. Si cambias de navegador, borras sus datos o usas incógnito, será necesario activarlo otra vez. No es una prueba de ubicación física.</p>
    </section>}
    {!loading&&!status?.feature_ready&&<div className="warning-box">La identificación automática requiere instalar primero la migración segura de cajas en cPanel. Las ventas actuales no se han cambiado.</div>}
    <section className="panel pad">
      <h3>Activar este celular o computador</h3>
      <p className="helper">Escanea el QR temporal autorizado por el administrador o escribe el código recibido. Sin una sucursal identificada, Vender y Rendición permanecen bloqueados. Al activar el equipo podrás comenzar a operar.</p>
      <form className="formstack" onSubmit={e=>{e.preventDefault();void activate(code);}}>
        <label>Código de activación (32 caracteres)<input aria-label="Código de activación de caja" autoComplete="off" maxLength={32} placeholder="Código temporal de una sola vez" value={code} onChange={e=>setCode(cleanCode(e.target.value))}/></label>
        <button type="submit" className="primary" disabled={busy||!isCode(code)||!status?.feature_ready}>{busy?'Activando…':'Identificar este dispositivo'}</button>
      </form>
    </section>
    {isAdmin&&status?.feature_ready&&<>
      <section className="panel pad">
        <h3>Autorizar nueva caja</h3>
        <p className="helper">Un administrador crea un QR o código de un solo uso, válido durante cinco minutos. Debe entregarse únicamente a un empleado autorizado del local correspondiente.</p>
        <form className="formstack" onSubmit={issue}>
          <label>Sucursal<select value={selected} onChange={e=>setSelected(e.target.value)} disabled={busy}>{locations.map(w=><option key={w.id} value={w.id}>{w.name}</option>)}</select></label>
          <label>Modalidad<select value={kind} onChange={e=>setKind(e.target.value)} disabled={busy}><option value="fixed">Caja fija · válida hasta 90 días</option><option value="shift">Celular · turno de hasta 12 horas</option></select></label>
          <label>Nombre de la caja<input maxLength={80} required minLength={2} value={label} disabled={busy} onChange={e=>setLabel(e.target.value)} placeholder="Ej. Mostrador 1 o Turno Norte"/></label>
          <button className="primary" disabled={busy||!selected||label.trim().length<2}>Crear QR temporal</button>
        </form>
        {pair&&<div className="terminal-pair-result">
          <strong>Activa en los siguientes cinco minutos</strong>
          <p>Destino: {locations.find(w=>Number(w.id)===pair.warehouse_id)?.name||'Sucursal'}</p>
          {qr&&<img alt="QR de activación temporal de sucursal" width="220" height="220" src={qr}/>}
          <p className="mono">{pair.code}</p>
          <div className="inline-actions">
            <button type="button" className="ghost mini" onClick={()=>navigator.clipboard?.writeText(pair.link)}>Copiar enlace</button>
            <button type="button" className="ghost mini" onClick={()=>navigator.clipboard?.writeText(pair.code)}>Copiar código</button>
          </div>
          <p className="helper">El código se invalida al activarse. No lo publiques ni lo dejes visible al público.</p>
        </div>}
      </section>
      <section className="panel pad">
        <h3>Política de ventas</h3>
        <p>{status.enforced?'🔒 El servidor exige una caja identificada para vender.':'La interfaz exige identificar el equipo, pero la API todavía conserva compatibilidad para accesos anteriores. Activa la protección del servidor después de registrar los dispositivos necesarios.'}</p>
        <button type="button" className="ghost" disabled={busy} onClick={()=>changeEnforcement(!status.enforced)}>{status.enforced?'Desactivar exigencia temporalmente':'Exigir caja identificada en todas las ventas'}</button>
      </section>
      <section className="panel pad">
        <h3>Cajas autorizadas</h3>
        {registered.length===0?<p className="helper">Todavía no se han registrado cajas.</p>:registered.map(t=><div key={t.id} className="terminal-row">
          <div><strong>{t.label}</strong><small>{t.warehouse_name} · {t.kind==='fixed'?'Caja fija':'Celular'} · {Number(t.is_active)===1?'Activa':'Vencida o revocada'}</small></div>
          {Number(t.is_active)===1&&<button type="button" className="ghost mini" disabled={busy} onClick={()=>revokeTerminal(t.id)}>Revocar</button>}
        </div>)}
      </section>
    </>}
  </div>;
}
