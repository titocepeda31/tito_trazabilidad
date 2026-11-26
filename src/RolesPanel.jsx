import React,{useEffect,useState} from 'react';
import './roles.css';
const empty=()=>({id:null,name:'',description:'',is_active:true,permissions:[]});
export default function RolesPanel({request}){
  const [data,setData]=useState({catalog:{},roles:[],users:[]}),[editing,setEditing]=useState(null),[busy,setBusy]=useState(false),[error,setError]=useState(''),[field,setField]=useState(''),[notice,setNotice]=useState('');
  async function load(){try{setData(await request('/access/roles'));}catch(e){setError(e.message);}}
  useEffect(()=>{void load();},[]);
  function open(r){setEditing(r?{...r,is_active:Number(r.is_active)===1,permissions:[...r.permissions]}:empty());setError('');setField('');setNotice('');}
  function change(key,value){setEditing(x=>({...x,[key]:value}));if(field===key){setField('');setError('');}}
  async function save(e){
    e.preventDefault();if(busy)return;setError('');setField('');setNotice('');
    if(!editing.name.trim()){setError('Ingresa un nombre para el rol.');setField('name');return;}
    setBusy(true);try{await request(editing.id?'/access/roles/'+editing.id+'/update':'/access/roles',{method:'POST',body:{name:editing.name,description:editing.description,is_active:editing.is_active,permissions:editing.permissions}});setEditing(null);setNotice('Rol guardado. Los usuarios asignados deberán volver a ingresar.');await load();}
    catch(e){setError(e.message);setField(e.field||'');}finally{setBusy(false);}
  }
  async function assign(u,value){
    const rid=value?Number(value):null;
    const message=rid?'Asignar este rol a '+u.email+'? Se aplicarán únicamente sus permisos y se cerrará la sesión.':'¿Retirar el rol de '+u.email+'? Recuperará los permisos heredados de cajero, incluidas las delegaciones existentes.';
    if(!window.confirm(message))return;
    setBusy(true);setError('');setNotice('');
    try{await request('/access/users/'+u.id+'/role',{method:'POST',body:{role_id:rid}});setNotice('Asignación actualizada. El usuario debe volver a ingresar.');await load();}
    catch(e){setError(e.message);}finally{setBusy(false);}
  }
  return <><div className="section-intro"><div><p className="eyebrow">SEGURIDAD · ACCESOS</p><h2>Roles y permisos</h2><p className="muted">Configura perfiles de acceso para los trabajadores de esta empresa.</p></div><button className="primary" disabled={busy} onClick={()=>open()}>+ Nuevo rol</button></div>
    {notice&&<div className="panel pad" role="status">{notice}</div>}
    {error&&<div className="role-error" role="alert">{error}</div>}
    <section className="panel pad"><h3>Roles personalizados</h3><p className="muted">Primera etapa: permisos de consulta efectivos en la API. Los administradores y cajeros sin rol personalizado conservan su acceso anterior.</p><div className="table-wrap"><table><thead><tr><th>Rol</th><th>Permisos</th><th>Usuarios</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>{data.roles.map(r=><tr key={r.id}><td><strong>{r.name}</strong><small className="sub">{r.description}</small></td><td>{r.permissions.length}</td><td>{r.assigned_count}</td><td><span className={'pill '+(Number(r.is_active)?'green':'amber')}>{Number(r.is_active)?'Activo':'Inactivo'}</span></td><td><button className="ghost mini" disabled={busy} onClick={()=>open(r)}>Editar</button></td></tr>)}</tbody></table>{!data.roles.length&&<p className="muted">Todavía no hay roles personalizados.</p>}</div></section>
    {editing&&<section className="panel pad"><h3>{editing.id?'Editar rol':'Nuevo rol'}</h3><form className="formstack role-form" noValidate onSubmit={save}><label>Nombre<input value={editing.name} maxLength={100} aria-invalid={field==='name'} onChange={e=>change('name',e.target.value)}/>{field==='name'&&<small className="role-field-error">{error}</small>}</label><label>Descripción<input value={editing.description} maxLength={250} aria-invalid={field==='description'} onChange={e=>change('description',e.target.value)}/>{field==='description'&&<small className="role-field-error">{error}</small>}</label><div className="role-matrix"><table><thead><tr><th>Módulo</th><th>Acción habilitada</th><th>Permitir</th></tr></thead><tbody>{Object.entries(data.catalog).map(([code,p])=><tr key={code}><td><strong>{p.module}</strong><small className="sub">{p.description}</small></td><td>{p.action}</td><td><input type="checkbox" aria-label={p.module+' '+p.action} checked={editing.permissions.includes(code)} onChange={e=>change('permissions',e.target.checked?[...editing.permissions,code]:editing.permissions.filter(v=>v!==code))}/></td></tr>)}</tbody></table></div><label className="role-check"><input type="checkbox" checked={editing.is_active} onChange={e=>change('is_active',e.target.checked)}/>Rol activo</label><p className="helper">Sin permisos seleccionados, el usuario solo podrá acceder a su cuenta. Crear, editar, anular, aprobar y exportar se incorporarán tras auditar cada módulo.</p><div className="inline-actions"><button type="button" className="ghost" disabled={busy} onClick={()=>{setEditing(null);setError('');}}>Cancelar</button><button className="primary" disabled={busy}>{busy?'Guardando…':'Guardar rol'}</button></div></form></section>}
    <section className="panel pad"><h3>Asignar roles a usuarios</h3><p className="muted">Los administradores conservan el control completo de su empresa. Los roles personalizados sustituyen, no amplían, los accesos heredados del usuario no administrador.</p><div className="table-wrap"><table><thead><tr><th>Usuario</th><th>Estado</th><th>Rol</th></tr></thead><tbody>{data.users.map(u=><tr key={u.id}><td>{u.email}</td><td>{Number(u.is_active)?'Activo':'Inactivo'}</td><td>{u.role==='admin'?<strong>Administrador</strong>:<select aria-label={'Rol de '+u.email} value={u.role_id||''} disabled={busy} onChange={e=>assign(u,e.target.value)}><option value="">Cajero heredado</option>{data.roles.filter(r=>Number(r.is_active)===1||Number(r.id)===Number(u.role_id)).map(r=><option key={r.id} value={r.id}>{r.name}</option>)}</select>}</td></tr>)}</tbody></table></div></section>
  </>;
}
