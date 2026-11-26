import React,{useEffect,useState} from 'react';
import {api} from './api.js';
import PasswordField from './PasswordField.jsx';

export default function PlatformPanel(){
  // La sesión de plataforma vive solo en memoria y nunca se usa para operaciones comerciales.
  const [token,setToken]=useState(''),[rows,setRows]=useState([]),[busy,setBusy]=useState(false),[notice,setNotice]=useState(''),[showNew,setShowNew]=useState(false);
  async function load(t=token){
    try{const d=await api('/platform/businesses',{token:t});setRows(d.businesses||[]);}
    catch(e){if(e.status===401){setToken('');setRows([]);}setNotice(e.message);}
  }
  useEffect(()=>{if(token)void load(token);},[token]);
  async function login(e){
    e.preventDefault();if(busy)return;setBusy(true);setNotice('');
    const form=e.currentTarget;const key=new FormData(form).get('key');
    try{const d=await api('/platform/login',{method:'POST',body:{key}});form.reset();setToken(d.token);}
    catch(err){setNotice(err.message);}finally{setBusy(false);}
  }
  async function create(e){
    e.preventDefault();if(busy)return;const form=e.currentTarget;const f=new FormData(form);
    if(f.get('admin_password')!==f.get('confirm')){setNotice('Las contraseñas no coinciden.');return;}
    setBusy(true);setNotice('');
    try{
      const d=await api('/platform/businesses',{token,method:'POST',body:Object.fromEntries(['name','legal_name','tax_id','admin_email','admin_password'].map(k=>[k,f.get(k)]))});
      form.reset();setShowNew(false);setNotice('Empresa #'+d.business_id+' creada. El administrador puede ingresar desde el acceso comercial.');await load();
    }catch(err){if(err.status===401){setToken('');setRows([]);}setNotice(err.message);}finally{setBusy(false);}
  }
  async function logout(){
    try{await api('/platform/logout',{token,method:'POST'});}catch{}finally{setToken('');setRows([]);setShowNew(false);setNotice('');}
  }
  if(!token)return <div className="auth-layout"><section className="auth-card"><div className="mark">T</div><p className="eyebrow">TITO LABS · PLATAFORMA</p><h1>Administración general</h1><p className="muted">Acceso independiente para gestionar empresas.</p><form className="formstack" onSubmit={login}><PasswordField label="Clave privada de plataforma" name="key" required autoComplete="current-password"/><button className="primary wide" disabled={busy}>{busy?'Ingresando…':'Ingresar a plataforma'}</button></form>{notice&&<p role="alert">{notice}</p>}<a href="/">Volver al acceso comercial</a></section></div>;
  return <div className="content-shell"><header className="topbar"><div><p className="eyebrow">TITO TRAZABILIDAD</p><strong>Administración general</strong></div><button className="ghost" onClick={logout}>Cerrar sesión de plataforma</button></header><main className="main-content"><div className="section-intro"><div><p className="eyebrow">MULTIEMPRESA</p><h2>Empresas</h2><p className="muted">Cada empresa tiene sus propios usuarios, sucursales e inventario.</p></div><button className="primary" disabled={busy} onClick={()=>setShowNew(v=>!v)}>{showNew?'Cancelar':'+ Nueva empresa'}</button></div>
    {notice&&<section className="panel pad" role="status">{notice}</section>}
    {showNew&&<section className="panel pad"><h3>Registrar empresa y administrador inicial</h3><form className="formstack" onSubmit={create}>
      <label>Nombre comercial<input name="name" required maxLength={120}/></label>
      <label>Razón social<input name="legal_name" required maxLength={160}/></label>
      <label>RUT<input name="tax_id" required maxLength={16} placeholder="12.345.678-5"/></label>
      <label>Correo del administrador<input name="admin_email" type="email" required maxLength={190} autoComplete="off"/></label>
      <PasswordField label="Contraseña inicial (mínimo 12 caracteres)" name="admin_password" required minLength={12} maxLength={72} autoComplete="new-password"/>
      <PasswordField label="Repetir contraseña" name="confirm" required minLength={12} maxLength={72} autoComplete="new-password"/>
      <p className="helper">Se crearán dos ubicaciones de inventario vacías por compatibilidad; no se crearán sucursales ni cajas. Entrega la contraseña en privado y solicita cambiarla.</p>
      <button className="primary" disabled={busy}>{busy?'Guardando…':'Crear empresa'}</button>
    </form></section>}
    <section className="panel pad"><div className="section-intro"><h3>Empresas registradas</h3><button className="ghost mini" disabled={busy} onClick={()=>load()}>Actualizar</button></div><div className="table-wrap"><table><thead><tr><th>ID</th><th>Empresa</th><th>RUT</th><th>Sucursales</th><th>Usuarios activos</th></tr></thead><tbody>{rows.map(b=><tr key={b.id}><td>{b.id}</td><td><strong>{b.name}</strong><small className="sub">{b.legal_name}</small></td><td>{b.tax_id||'—'}</td><td>{b.branch_count}</td><td>{b.active_users}</td></tr>)}</tbody></table></div>{rows.length===0&&<p className="muted">Sin empresas cargadas.</p>}</section>
    <p className="helper">Para operar una empresa usa su usuario propio en el <a href="/">acceso comercial</a>. El selector de empresa y permisos por sucursal se habilitarán después de auditar las rutas existentes.</p>
  </main></div>;
}
