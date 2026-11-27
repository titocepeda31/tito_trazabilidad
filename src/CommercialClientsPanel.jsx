import React,{useEffect,useRef,useState} from 'react';

const empty={name:'',client_type:'',rut:'',business_activity:'',address:'',commune:'',contact_name:'',phone:'',billing_email:'',notes:'',is_active:true};
const types={person:'Persona',business:'Empresa'};
const toForm=c=>Object.fromEntries(Object.keys(empty).map(k=>[k,k==='is_active'?Number(c[k])===1:String(c[k]??'')]));
export default function CommercialClientsPanel({request,notify}){
  const [rows,setRows]=useState([]),[total,setTotal]=useState(0),[page,setPage]=useState(1),[pages,setPages]=useState(1);
  const [q,setQ]=useState(''),[query,setQuery]=useState(''),[state,setState]=useState('all');
  const [form,setForm]=useState(null),[editing,setEditing]=useState(null),[loading,setLoading]=useState(true),[busy,setBusy]=useState(false),[error,setError]=useState('');
  const generation=useRef(0),saving=useRef(false);
  async function load(p=1,search=query,filter=state){
    const version=++generation.current;setLoading(true);setError('');
    try{
      const d=await request('/clients/commercial?q='+encodeURIComponent(search)+'&state='+encodeURIComponent(filter)+'&page='+p);
      if(version!==generation.current)return;
      setRows(d.clients||[]);setTotal(Number(d.total)||0);setPage(Number(d.page)||1);setPages(Number(d.pages)||1);
    }catch(e){if(version===generation.current){setRows([]);setError('No se pudieron cargar los clientes comerciales. Verifica migración 015 y backend: '+e.message);}}
    finally{if(version===generation.current)setLoading(false);}
  }
  useEffect(()=>{void load(1,'','all');return ()=>{generation.current++;};},[]);
  function search(e){e.preventDefault();const next=q.trim();setQuery(next);void load(1,next,state);}
  function filter(value){setState(value);void load(1,query,value);}
  function edit(c){setEditing(c?Number(c.id):null);setForm(c?toForm(c):{...empty});}
  function change(k,v){setForm(f=>({...f,[k]:v}));}
  async function save(e){
    e.preventDefault();if(!form||saving.current)return;
    if(!form.name.trim()){notify('Ingresa el nombre o razón social.','error');return;}
    const body=Object.fromEntries(Object.entries(form).map(([k,v])=>[k,typeof v==='string'?v.trim():v]));
    saving.current=true;setBusy(true);
    try{
      await request(editing===null?'/clients/commercial':'/clients/commercial/'+editing+'/update',{method:'POST',body});
      notify(editing===null?'Cliente registrado.':'Ficha comercial actualizada.','success');setForm(null);setEditing(null);await load(page,query,state);
    }catch(e){notify('No se pudo guardar: '+e.message,'error');}
    finally{saving.current=false;setBusy(false);}
  }
  const field=(k,label,max,type='text',required=false)=> <label key={k}>{label}<input type={type} maxLength={max} required={required} disabled={busy} value={form[k]} onChange={e=>change(k,e.target.value)}/></label>;
  return <div className="clients-page">
    <div className="section-intro"><div><p className="eyebrow">GESTIÓN COMERCIAL</p><h2>Clientes</h2><p className="muted">Registro de personas y empresas, independiente de su forma de pago.</p></div><button type="button" className="primary" disabled={busy||!!error} onClick={()=>edit(null)}>+ Nuevo cliente</button></div>
    <section className="panel pad">
      <form className="origin-search" onSubmit={search}><input type="search" aria-label="Buscar clientes" placeholder="Nombre, RUT, contacto o correo" value={q} maxLength={150} onChange={e=>setQ(e.target.value)}/><button type="submit" className="primary">Buscar</button></form>
      <div className="list-filters"><label>Estado<select value={state} onChange={e=>filter(e.target.value)}><option value="all">Todos</option><option value="active">Activos</option><option value="inactive">Inactivos</option></select></label><button type="button" className="ghost" onClick={()=>{setQ('');setQuery('');setState('all');void load(1,'','all');}}>Limpiar filtros</button></div>
      {error&&<p className="warning-box" role="alert">{error}</p>}
      {loading&&<p className="muted">Cargando clientes…</p>}
      {!loading&&!error&&<><div className="table-wrap"><table><thead><tr><th>Cliente / RUT</th><th>Contacto</th><th>Estado</th><th></th></tr></thead><tbody>{rows.map(c=><tr key={c.id}><td><strong>{c.name}</strong><small className="sub">{c.rut||'Sin RUT'} · {types[c.client_type]||'Sin clasificar'}</small></td><td>{c.contact_name||c.phone||'—'}{c.billing_email&&<small className="sub">{c.billing_email}</small>}</td><td><span className={'pill '+(Number(c.is_active)===1?'green':'amber')}>{Number(c.is_active)===1?'Activo':'Inactivo'}</span></td><td><button type="button" className="ghost mini" onClick={()=>edit(c)}>Ver / editar</button></td></tr>)}</tbody></table>{!rows.length&&<p className="muted">No se encontraron clientes.</p>}</div>
      <div className="list-pagination"><span>{total} clientes · Página {page} de {pages}</span><div className="inline-actions"><button type="button" className="ghost mini" disabled={page<=1} onClick={()=>void load(page-1)}>Anterior</button><button type="button" className="ghost mini" disabled={page>=pages} onClick={()=>void load(page+1)}>Siguiente</button></div></div></>}
    </section>
    {form&&<div className="modal-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget&&!busy)setForm(null);}}><div className="modal" role="dialog" aria-modal="true" aria-label="Ficha comercial de cliente">
      <div className="modal-head"><h3>{editing===null?'Nuevo cliente':'Ficha comercial'}</h3><button type="button" className="ghost close" disabled={busy} aria-label="Cerrar" onClick={()=>setForm(null)}>×</button></div>
      <form className="formstack" onSubmit={save}>
        <h4>Identificación</h4><label>Tipo de cliente<select value={form.client_type} disabled={busy} onChange={e=>change('client_type',e.target.value)}><option value="">Sin clasificar</option><option value="person">Persona</option><option value="business">Empresa</option></select></label>
        {field('name','Nombre o razón social',150,'text',true)}{field('rut','RUT (opcional)',25)}{field('business_activity','Giro comercial',150)}
        <h4>Dirección y contacto</h4>{field('address','Dirección',200)}{field('commune','Comuna',100)}{field('contact_name','Persona de contacto',150)}{field('phone','Teléfono',80,'tel')}{field('billing_email','Correo de facturación',254,'email')}
        <label>Observaciones<textarea rows="3" maxLength={300} disabled={busy} value={form.notes} onChange={e=>change('notes',e.target.value)}/></label>
        <label className="credit-toggle"><input type="checkbox" checked={form.is_active} disabled={busy} onChange={e=>change('is_active',e.target.checked)}/> Cliente activo</label>
        <p className="helper">El registro comercial no autoriza crédito ni modifica deudas existentes.</p><button type="submit" className="primary wide" disabled={busy}>{busy?'Guardando…':'Guardar cliente'}</button>
      </form></div></div>}
  </div>;
}
