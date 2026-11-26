import React,{useEffect,useState} from 'react';
import {api} from './api.js';
import PasswordField from './PasswordField.jsx';
import {validateCompany,validateCompanyEdit,companyErrorField} from './platformValidation.js';
import './platformForm.css';

export default function PlatformPanel(){
  // La sesión de plataforma vive solo en memoria y nunca se usa para operaciones comerciales.
  const [token,setToken]=useState(''),[rows,setRows]=useState([]),[busy,setBusy]=useState(false),[notice,setNotice]=useState(''),[showNew,setShowNew]=useState(false);
  const [errors,setErrors]=useState({}),[formError,setFormError]=useState('');
  const [editing,setEditing]=useState(null),[statusTarget,setStatusTarget]=useState(null);
  const [statusError,setStatusError]=useState('');
  function resetFormErrors(){setErrors({});setFormError('');}
  function clearField(e){const field=e.target.name;if(!field)return;setErrors(old=>{if(!old[field]&&!(field==='admin_password'&&old.confirm))return old;const next={...old};delete next[field];if(field==='admin_password')delete next.confirm;return next;});}
  function focusField(form,name){if(name)form.elements.namedItem(name)?.focus();}
  function inputField(name,label,props={}){return <label>{label}<input name={name} {...props} disabled={busy} aria-invalid={errors[name]?true:undefined} aria-describedby={errors[name]?'platform-'+name+'-error':undefined}/>{errors[name]&&<span className="platform-field-error" id={'platform-'+name+'-error'}>{errors[name]}</span>}</label>;}
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
    const values=Object.fromEntries(f.entries());resetFormErrors();setNotice('');
    const validation=validateCompany(values);
    if(Object.keys(validation).length){setErrors(validation);setFormError('Revisa los campos marcados antes de continuar.');focusField(form,Object.keys(validation)[0]);return;}
    setBusy(true);
    try{
      const d=await api('/platform/businesses',{token,method:'POST',body:Object.fromEntries(['name','legal_name','tax_id','admin_email','admin_password'].map(k=>[k,f.get(k)]))});
      form.reset();setShowNew(false);setNotice('Empresa #'+d.business_id+' creada. El administrador puede ingresar desde el acceso comercial.');await load();
    }catch(err){
      if(err.status===401){setToken('');setRows([]);setNotice('Tu sesión expiró. Vuelve a ingresar.');}
      else{
        const field=companyErrorField(err);
        if(field)setErrors({[field]:err.message});
        setFormError(err.message||'No fue posible crear la empresa. Intenta nuevamente.');
        if(field)requestAnimationFrame(()=>focusField(form,field));
      }
    }finally{setBusy(false);}
  }
  function openEdit(row){setShowNew(false);setStatusTarget(null);resetFormErrors();setNotice('');setEditing(row);}
  async function saveEdit(e){
    e.preventDefault();if(busy)return;
    const form=e.currentTarget,values=Object.fromEntries(new FormData(form).entries());
    resetFormErrors();const validation=validateCompanyEdit(values);
    if(Object.keys(validation).length){setErrors(validation);setFormError('Revisa los campos marcados.');focusField(form,Object.keys(validation)[0]);return;}
    setBusy(true);
    try{await api('/platform/businesses/'+editing.id+'/update',{token,method:'POST',body:values});setEditing(null);setNotice('Empresa actualizada correctamente.');await load();}
    catch(err){if(err.status===401){setToken('');setRows([]);setEditing(null);setNotice('Tu sesión expiró. Vuelve a ingresar.');}
      else{const field=companyErrorField(err);if(field)setErrors({[field]:err.message});setFormError(err.message);if(field)requestAnimationFrame(()=>focusField(form,field));}}
    finally{setBusy(false);}
  }
  function openStatus(row){setShowNew(false);setEditing(null);resetFormErrors();setStatusError('');setNotice('');setStatusTarget(row);}
  async function saveStatus(e){
    e.preventDefault();if(busy)return;
    const active=Number(statusTarget.is_active)!==1,reason=String(new FormData(e.currentTarget).get('reason')||'').trim();
    if(!active&&!reason){setStatusError('Indica el motivo de desactivación.');return;}
    setBusy(true);setStatusError('');
    try{await api('/platform/businesses/'+statusTarget.id+'/status',{token,method:'POST',body:{is_active:active,reason}});setStatusTarget(null);setNotice(active?'Empresa reactivada. Sus usuarios deben iniciar sesión nuevamente.':'Empresa desactivada. Se revocaron sus sesiones comerciales.');await load();}
    catch(err){if(err.status===401){setToken('');setRows([]);setStatusTarget(null);setNotice('Tu sesión expiró. Vuelve a ingresar.');}else setStatusError(err.message);}
    finally{setBusy(false);}
  }
  async function logout(){
    try{await api('/platform/logout',{token,method:'POST'});}catch{}finally{setToken('');setRows([]);setShowNew(false);setEditing(null);setStatusTarget(null);setNotice('');resetFormErrors();}
  }
  if(!token)return <div className="auth-layout"><section className="auth-card"><div className="mark">T</div><p className="eyebrow">TITO LABS · PLATAFORMA</p><h1>Administración general</h1><p className="muted">Acceso independiente para gestionar empresas.</p><form className="formstack" onSubmit={login}><PasswordField label="Clave privada de plataforma" name="key" required autoComplete="current-password"/><button className="primary wide" disabled={busy}>{busy?'Ingresando…':'Ingresar a plataforma'}</button></form>{notice&&<p role="alert">{notice}</p>}<a href="/">Volver al acceso comercial</a></section></div>;
  return <div className="content-shell"><header className="topbar"><div><p className="eyebrow">TITO TRAZABILIDAD</p><strong>Administración general</strong></div><button className="ghost" onClick={logout}>Cerrar sesión de plataforma</button></header><main className="main-content"><div className="section-intro"><div><p className="eyebrow">MULTIEMPRESA</p><h2>Empresas</h2><p className="muted">Cada empresa tiene sus propios usuarios, sucursales e inventario.</p></div><button className="primary" disabled={busy} onClick={()=>{resetFormErrors();setNotice('');setEditing(null);setStatusTarget(null);setShowNew(v=>!v);}}>{showNew?'Cancelar':'+ Nueva empresa'}</button></div>
    {notice&&<section className="panel pad" role="status">{notice}</section>}
    {showNew&&<section className="panel pad"><h3>Registrar empresa y administrador inicial</h3><form className="formstack platform-company-form" noValidate onSubmit={create} onChange={clearField}>
      {formError&&<div role="alert" className="platform-form-error"><strong>No se pudo guardar la empresa</strong><span>{formError}</span></div>}
      {inputField('name','Nombre comercial',{required:true,maxLength:120})}
      {inputField('legal_name','Razón social',{required:true,maxLength:160})}
      {inputField('tax_id','RUT',{required:true,maxLength:16,placeholder:'12.345.678-5'})}
      {inputField('admin_email','Correo del administrador',{type:'email',required:true,maxLength:190,autoComplete:'off'})}
      <PasswordField label="Contraseña inicial (mínimo 12 caracteres)" name="admin_password" required minLength={12} maxLength={72} autoComplete="new-password" disabled={busy} error={errors.admin_password}/>
      <PasswordField label="Repetir contraseña" name="confirm" required minLength={12} maxLength={72} autoComplete="new-password" disabled={busy} error={errors.confirm}/>
      <p className="helper">Se crearán dos ubicaciones de inventario vacías por compatibilidad; no se crearán sucursales ni cajas. Entrega la contraseña en privado y solicita cambiarla.</p>
      {formError&&<p role="status" className="platform-field-error">Corrige los datos indicados o revisa el mensaje de error antes de reintentar.</p>}
      <button className="primary" disabled={busy}>{busy?'Guardando…':'Crear empresa'}</button>
    </form></section>}
    {editing&&<section className="panel pad" key={'edit-'+editing.id}><h3>Editar empresa · {editing.name}</h3><form className="formstack platform-company-form" noValidate onSubmit={saveEdit} onChange={clearField}>
      {formError&&<div role="alert" className="platform-form-error"><strong>No se pudo guardar la empresa</strong><span>{formError}</span></div>}
      {inputField('name','Nombre comercial',{defaultValue:editing.name,required:true,maxLength:120})}
      {inputField('legal_name','Razón social',{defaultValue:editing.legal_name||'',required:true,maxLength:160})}
      {inputField('tax_id','RUT',{defaultValue:editing.tax_id||'',required:true,maxLength:16})}
      <p className="helper">Corrige el RUT únicamente si corresponde a la misma entidad legal. Para otra entidad, crea una empresa nueva.</p>
      {inputField('address','Dirección',{defaultValue:editing.address||'',maxLength:200})}
      {inputField('city','Ciudad',{defaultValue:editing.city||'',maxLength:100})}
      {inputField('email','Correo de contacto',{defaultValue:editing.email||'',maxLength:160,type:'email'})}
      {inputField('phone','Teléfono',{defaultValue:editing.phone||'',maxLength:40})}
      <div className="platform-actions"><button type="button" className="ghost" disabled={busy} onClick={()=>{setEditing(null);resetFormErrors();}}>Cancelar</button><button className="primary" disabled={busy}>{busy?'Guardando…':'Guardar cambios'}</button></div>
    </form></section>}
    {statusTarget&&<section className="panel pad" key={'status-'+statusTarget.id}><h3>{Number(statusTarget.is_active)===1?'Desactivar':'Reactivar'} empresa · {statusTarget.name}</h3><form className="formstack" onSubmit={saveStatus}>
      <p className="muted">{Number(statusTarget.is_active)===1?'Se cerrarán las sesiones de esta empresa y sus usuarios no podrán iniciar sesión ni seguir operando mediante la API. No se borran sus datos. Las operaciones que ya estaban en ejecución podrían finalizar.':'Sus usuarios habilitados podrán iniciar sesión nuevamente. No se reactivarán usuarios deshabilitados individualmente.'}</p>
      {statusError&&<div className="platform-form-error" role="alert">{statusError}</div>}
      <label>Motivo{Number(statusTarget.is_active)===1?' (obligatorio)':' (opcional)'}<input name="reason" maxLength={250} disabled={busy}/></label>
      <div className="platform-actions"><button className="ghost" type="button" disabled={busy} onClick={()=>setStatusTarget(null)}>Cancelar</button><button className={Number(statusTarget.is_active)===1?'platform-danger-button':'primary'} disabled={busy}>{busy?'Guardando…':Number(statusTarget.is_active)===1?'Confirmar desactivación':'Confirmar reactivación'}</button></div>
    </form></section>}
    <section className="panel pad"><div className="section-intro"><h3>Empresas registradas</h3><button className="ghost mini" disabled={busy} onClick={()=>load()}>Actualizar</button></div><div className="table-wrap"><table><thead><tr><th>ID</th><th>Empresa</th><th>RUT</th><th>Sucursales</th><th>Usuarios habilitados</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>{rows.map(b=><tr key={b.id}><td>{b.id}</td><td><strong>{b.name}</strong><small className="sub">{b.legal_name}</small></td><td>{b.tax_id||'—'}</td><td>{b.branch_count}</td><td>{b.active_users}</td><td><span className={'platform-status '+(Number(b.is_active)===1?'is-active':'is-inactive')}>{b.is_active==null?'API pendiente':Number(b.is_active)===1?'Activa':'Desactivada'}</span>{b.status_reason&&<small className="platform-reason">{b.status_reason}</small>}</td><td><div className="platform-actions"><button className="ghost mini" disabled={busy} onClick={()=>openEdit(b)}>Editar</button><button className="ghost mini" disabled={busy||b.is_active==null} onClick={()=>openStatus(b)}>{Number(b.is_active)===1?'Desactivar':'Reactivar'}</button></div></td></tr>)}</tbody></table></div>{rows.length===0&&<p className="muted">Sin empresas cargadas.</p>}</section>
    <p className="helper">Para operar una empresa usa su usuario propio en el <a href="/">acceso comercial</a>. El selector de empresa y permisos por sucursal se habilitarán después de auditar las rutas existentes.</p>
  </main></div>;
}
