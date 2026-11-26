import React,{useEffect,useState} from 'react';

const emptyBranch={code:'',name:'',address:'',city:'',phone:'',is_active:true};
const profileFields=[['legal_name','Razón social',160],['tax_id','RUT',16],['address','Dirección',200],['city','Comuna / ciudad',100],['email','Correo',160],['phone','Teléfono',40]];
const branchFields=[['code','Código',30],['name','Nombre',120],['address','Dirección',200],['city','Comuna / ciudad',100],['phone','Teléfono',40]];
export default function OrganizationPanel({request,notify}){
  const [data,setData]=useState(null),[profile,setProfile]=useState({}),[branch,setBranch]=useState(null),[busy,setBusy]=useState(false),[error,setError]=useState('');
  async function load(){
    setError('');
    try{const d=await request('/organization');setData(d);setProfile(d.business);}
    catch(e){setError(e.message);}
  }
  useEffect(()=>{void load();},[]);
  async function save(path,body,after){
    if(busy)return;setBusy(true);
    try{await request(path,{method:'POST',body});notify('Cambios guardados.','success');after?.();await load();}
    catch(e){notify(e.message,'error');}finally{setBusy(false);}
  }
  const field=(item,obj,set,locked=false)=>{
    const [key,label,max]=item;return <label key={key}>{label}<input value={obj[key]??''} maxLength={max} required={key==='legal_name'||key==='name'||key==='code'} type={key==='email'?'email':'text'} readOnly={locked} disabled={busy} onChange={e=>set(v=>({...v,[key]:e.target.value}))}/></label>;
  };
  return <>
    <div className="section-intro"><div><p className="eyebrow">ADMINISTRACIÓN</p><h2>Empresas y sucursales</h2><p className="muted">Organiza la empresa, sus establecimientos y sus bodegas sin alterar el inventario.</p></div><button className="ghost" onClick={load} disabled={busy}>Actualizar</button></div>
    {error&&<section className="panel pad"><p role="alert">{error}</p><button className="ghost" onClick={load}>Reintentar</button></section>}
    {!data&&!error&&<p>Cargando organización…</p>}
    {data&&<>
      <section className="panel pad"><h3>Empresa actual · {data.business.name}</h3><p className="muted">Ficha tributaria y de contacto de la empresa de tu sesión.</p>
        <form className="formstack" onSubmit={e=>{e.preventDefault();save('/organization/profile',Object.fromEntries(profileFields.map(([k])=>[k,k==='legal_name'?(profile[k]??profile.name):profile[k]??''])));}}>
          {profileFields.map(f=>field(f,{...profile,legal_name:profile.legal_name??profile.name},setProfile))}
          <button className="primary" disabled={busy}>Guardar ficha</button>
        </form>
      </section>
      <section className="panel pad">
        <div className="section-intro"><div><h3>Sucursales</h3><p className="muted">Una sucursal puede tener varias bodegas.</p></div><button className="primary" disabled={busy} onClick={()=>setBranch({...emptyBranch})}>+ Nueva sucursal</button></div>
        {branch&&<form className="formstack" onSubmit={e=>{e.preventDefault();save(branch.id?'/organization/branches/'+branch.id+'/update':'/organization/branches',{...branch,is_active:Boolean(Number(branch.is_active))},()=>setBranch(null));}}>
          {branchFields.map(f=>field(f,branch,setBranch,Boolean(branch.id&&f[0]==='code')))}
          {branch.id&&<label><input type="checkbox" checked={Boolean(Number(branch.is_active))} disabled={busy} onChange={e=>setBranch(v=>({...v,is_active:e.target.checked}))}/> Sucursal activa</label>}
          <div className="inline-actions"><button className="primary" disabled={busy}>Guardar sucursal</button><button className="ghost" type="button" onClick={()=>setBranch(null)}>Cancelar</button></div>
        </form>}
        <div className="table-wrap"><table><thead><tr><th>Código</th><th>Sucursal</th><th>Comuna</th><th>Bodegas</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>{data.branches.map(b=><tr key={b.id}><td>{b.code}</td><td>{b.name}<small className="sub">{b.address}</small></td><td>{b.city||'—'}</td><td>{data.warehouses.filter(w=>Number(w.branch_id)===Number(b.id)).length}</td><td><span className={'pill '+(Number(b.is_active)?'green':'amber')}>{Number(b.is_active)?'Activa':'Inactiva'}</span></td><td><button className="ghost mini" disabled={busy} onClick={()=>setBranch({...b})}>Editar</button></td></tr>)}</tbody></table></div>
        {!data.branches.length&&<p className="muted">Aún no hay sucursales. Crea la primera según la ubicación real.</p>}
      </section>
      <section className="panel pad"><h3>Asignación de bodegas</h3><p className="muted">Asociar una bodega no mueve mercadería ni cambia su configuración de caja. No se modifica el historial de ventas.</p><div className="table-wrap"><table><thead><tr><th>Bodega</th><th>Tipo</th><th>Sucursal</th></tr></thead><tbody>{data.warehouses.map(w=><tr key={w.id}><td>{w.name}</td><td>{Number(w.is_sales_location)?'Inventario vinculado a caja (heredado)':'Ubicación de inventario'}</td><td><select aria-label={'Sucursal de '+w.name} value={w.branch_id??''} disabled={busy} onChange={e=>save('/organization/warehouses/'+w.id+'/branch',{branch_id:e.target.value?Number(e.target.value):null})}><option value="">Sin asignar</option>{data.branches.filter(b=>Number(b.is_active)).map(b=><option key={b.id} value={b.id}>{b.name}</option>)}</select></td></tr>)}</tbody></table></div></section>
      <section className="panel pad"><h3>Usuarios y ubicación de caja</h3><p className="muted">Vista de las asignaciones explícitas vigentes. Los permisos de venta y caja siguen administrándose en Usuarios; esta ficha no restringe el acceso por sucursal.</p><div className="table-wrap"><table><thead><tr><th>Usuario</th><th>Rol</th><th>Bodega de venta</th><th>Sucursal asociada</th></tr></thead><tbody>{data.users.map(u=><tr key={u.id}><td>{u.email}</td><td>{u.role==='admin'?'Administrador':'Cajero'}</td><td>{u.warehouse_name||'Sin asignación explícita'}</td><td>{data.branches.find(b=>Number(b.id)===Number(u.branch_id))?.name||'—'}</td></tr>)}</tbody></table></div></section>
    </>}
  </>;
}
